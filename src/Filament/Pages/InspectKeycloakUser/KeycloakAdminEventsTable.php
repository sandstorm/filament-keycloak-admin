<?php

declare(strict_types=1);

namespace Sandstorm\FilamentKeycloakAdmin\Filament\Pages\InspectKeycloakUser;

use Filament\Actions\Action;
use Filament\Actions\Concerns\InteractsWithActions;
use Filament\Actions\Contracts\HasActions;
use Filament\Infolists\Components\TextEntry;
use Filament\Schemas\Concerns\InteractsWithSchemas;
use Filament\Schemas\Contracts\HasSchemas;
use Filament\Tables\Columns\TextColumn;
use Filament\Tables\Concerns\InteractsWithTable;
use Filament\Tables\Contracts\HasTable;
use Filament\Tables\Table;
use Illuminate\Contracts\View\View;
use Illuminate\Pagination\Paginator;
use Livewire\Attributes\On;
use Livewire\Component;
use Sandstorm\FilamentKeycloakAdmin\Filament\Concerns\HandlesKeycloakLoadErrors;
use Sandstorm\FilamentKeycloakAdmin\Filament\Helpers\KeycloakRecord;
use Sandstorm\KeycloakAdminApi\Features\KeycloakEventsApi;
use Sandstorm\KeycloakAdminApi\Features\KeycloakEventsApi\Dto\KeycloakAdminEvent;
use Sandstorm\KeycloakAdminApi\SharedModel\KeycloakUserId;

use function assert;
use function view;

/**
 * Admin history section — administrative actions performed ON this user (who changed what) as a table.
 * Full detail (auth client/ip, error, JSON representation) sits behind the row Details modal. A failed
 * initial load is caught in {@see self::render()} ({@see HandlesKeycloakLoadErrors}) and shown as the
 * table's empty state; other failures still propagate.
 *
 * Keycloak's `/admin-events` endpoint has no count, so pagination is "simple" (Prev/Next) via a
 * `perPage + 1` probe.
 */
final class KeycloakAdminEventsTable extends Component implements HasActions, HasSchemas, HasTable
{
    use HandlesKeycloakLoadErrors;
    use InteractsWithActions;
    use InteractsWithSchemas;
    use InteractsWithTable;

    public string $userId;

    protected KeycloakEventsApi $eventsApi;

    public function boot(KeycloakEventsApi $eventsApi): void
    {
        $this->eventsApi = $eventsApi;
    }

    public function mount(string $userId): void
    {
        $this->userId = $userId;
    }

    #[On('keycloak-user-changed')]
    public function refresh(): void
    {
        $this->resetTable();
    }

    public function table(Table $table): Table
    {
        return $this->keycloakLoadErrorEmptyState(
            $table
                ->heading('Admin history')
                ->records(fn (int $page, int $recordsPerPage): Paginator => $this->loadEvents($page, $recordsPerPage))
                ->paginationPageOptions([10, 25, 50])
                ->columns([
                    TextColumn::make('time')->label('Time')->state(fn (KeycloakRecord $record): string => self::dto($record)->formattedTime()),
                    TextColumn::make('operationType')->label('Operation')->badge()
                        ->color(fn (KeycloakRecord $record): string => self::dto($record)->error !== null ? 'danger' : 'gray')
                        ->state(fn (KeycloakRecord $record): ?string => self::dto($record)->operationType)->placeholder('—'),
                    TextColumn::make('resourceType')->label('Type')->badge()->color('gray')
                        ->state(fn (KeycloakRecord $record): ?string => self::dto($record)->resourceType)->placeholder('—'),
                    TextColumn::make('details')->label('Details')->state(fn (KeycloakRecord $record): ?string => self::dto($record)->resourceLabel())->placeholder('—')->wrap(),
                    TextColumn::make('authUser')->label('By')->state(fn (KeycloakRecord $record): ?string => self::dto($record)->authUser)->placeholder('—'),
                    TextColumn::make('authIpAddress')->label('IP')->state(fn (KeycloakRecord $record): ?string => self::dto($record)->authIpAddress)->placeholder('—'),
                ])
                ->recordActions([
                    Action::make('details')
                        ->label('Details')
                        ->modalHeading('Admin event details')
                        ->modalSubmitAction(false)
                        ->modalCancelActionLabel('Close')
                        ->infolist(fn (KeycloakRecord $record): array => $this->detailEntries(self::dto($record))),
                ]),
            'No admin events recorded.',
        );
    }

    /**
     * Fetch one page, requesting one extra row so the simple paginator knows whether a next page exists.
     * Called once from {@see self::render()}'s eager load; called again by Filament while compiling the
     * table's Blade output. That second call must not re-hit Keycloak once a failure is already known —
     * {@see HandlesKeycloakLoadErrors} caches the failure, not the result.
     */
    private function loadEvents(int $page, int $recordsPerPage): Paginator
    {
        if ($this->keycloakLoadError !== null) {
            return new Paginator([], $recordsPerPage, $page);
        }

        $first = ($page - 1) * $recordsPerPage;

        $records = [];
        foreach ($this->eventsApi->getAdminEventsForUser(new KeycloakUserId($this->userId), $first, $recordsPerPage + 1) as $index => $event) {
            $records[] = KeycloakRecord::for((string) ($first + $index), $event);
        }

        return new Paginator($records, $recordsPerPage, $page);
    }

    /**
     * @return list<TextEntry>
     */
    private function detailEntries(KeycloakAdminEvent $event): array
    {
        $entries = [
            TextEntry::make('time')->label('Time')->state($event->formattedTime()),
            TextEntry::make('operationType')->label('Operation')->state($event->operationType)->placeholder('—'),
            TextEntry::make('resourceType')->label('Resource type')->state($event->resourceType)->placeholder('—'),
            TextEntry::make('resourcePath')->label('Resource path')->state($event->resourcePath)->placeholder('—'),
            TextEntry::make('authUser')->label('By (admin)')->state($event->authUser)->placeholder('—'),
            TextEntry::make('authClient')->label('Via client')->state($event->authClient)->placeholder('—'),
            TextEntry::make('authIpAddress')->label('IP address')->state($event->authIpAddress)->placeholder('—'),
        ];

        if ($event->error !== null) {
            $entries[] = TextEntry::make('error')->label('Error')->state($event->error)->color('danger');
        }

        if ($event->representation !== null) {
            $entries[] = TextEntry::make('representation')->label('Representation')->state($event->representation)->columnSpanFull();
        }

        return $entries;
    }

    private static function dto(KeycloakRecord $record): KeycloakAdminEvent
    {
        $event = $record->dto();
        assert($event instanceof KeycloakAdminEvent);

        return $event;
    }

    /**
     * Triggers this request's one Keycloak read up front, before Filament's table machinery gets a
     * chance to invoke {@see self::loadEvents()} again mid-Blade-compile (see
     * {@see HandlesKeycloakLoadErrors}).
     */
    public function render(): View
    {
        $this->catchKeycloakLoadError(fn (): mixed => $this->getTable()->getRecords());

        return view('filament-keycloak-admin::livewire.keycloak-table');
    }
}
