<?php

declare(strict_types=1);

namespace Sandstorm\FilamentKeycloakAdmin\Filament\Pages\InspectKeycloakUser;

use Sandstorm\FilamentKeycloakAdmin\Filament\Helpers\KeycloakRecord;
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
use Sandstorm\KeycloakAdminApi\Features\KeycloakEventsApi;
use Sandstorm\KeycloakAdminApi\Features\KeycloakEventsApi\Dto\KeycloakUserEvent;
use Sandstorm\KeycloakAdminApi\SharedModel\KeycloakUserId;

use function assert;
use function view;

/**
 * User events section — every event Keycloak recorded for this user (LOGIN, LOGIN_ERROR,
 * UPDATE_PASSWORD, …) as a table mirroring Keycloak's own list. Error is surfaced in the event badge;
 * full detail sits behind the row Details modal. Every failure propagates (plan §8).
 *
 * Keycloak's `/events` endpoint has no count, so pagination is "simple" (Prev/Next) via a `perPage + 1`
 * probe.
 */
final class KeycloakUserEventsTable extends Component implements HasActions, HasSchemas, HasTable
{
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
        return $table
            ->records(fn (int $page, int $recordsPerPage): Paginator => $this->loadEvents($page, $recordsPerPage))
            ->paginationPageOptions([10, 25, 50])
            ->columns([
                TextColumn::make('time')->label('Time')->state(fn (KeycloakRecord $record): string => self::dto($record)->formattedTime()),
                TextColumn::make('event')->label('Event')->badge()
                    ->color(fn (KeycloakRecord $record): string => self::dto($record)->error !== null ? 'danger' : 'gray')
                    ->state(fn (KeycloakRecord $record): string => self::dto($record)->label()),
                TextColumn::make('ipAddress')->label('IP address')->state(fn (KeycloakRecord $record): ?string => self::dto($record)->ipAddress)->placeholder('—'),
                TextColumn::make('clientId')->label('Client')->state(fn (KeycloakRecord $record): ?string => self::dto($record)->clientId)->placeholder('—'),
            ])
            ->recordActions([
                Action::make('details')
                    ->label('Details')
                    ->modalHeading('Event details')
                    ->modalSubmitAction(false)
                    ->modalCancelActionLabel('Close')
                    ->infolist(fn (KeycloakRecord $record): array => $this->detailEntries(self::dto($record))),
            ]);
    }

    /**
     * Fetch one page, requesting one extra row so the simple paginator knows whether a next page exists.
     */
    private function loadEvents(int $page, int $recordsPerPage): Paginator
    {
        $first = ($page - 1) * $recordsPerPage;

        $records = [];
        foreach ($this->eventsApi->getUserEvents(new KeycloakUserId($this->userId), $first, $recordsPerPage + 1) as $index => $event) {
            $records[] = KeycloakRecord::for((string) ($first + $index), $event);
        }

        return new Paginator($records, $recordsPerPage, $page);
    }

    /**
     * @return list<TextEntry>
     */
    private function detailEntries(KeycloakUserEvent $event): array
    {
        $entries = [
            TextEntry::make('time')->label('Time')->state($event->formattedTime()),
            TextEntry::make('type')->label('Type')->state($event->type ?? 'UNKNOWN'),
        ];

        if ($event->error !== null) {
            $entries[] = TextEntry::make('error')->label('Error')->state($event->error)->color('danger');
        }

        $entries[] = TextEntry::make('ipAddress')->label('IP address')->state($event->ipAddress)->placeholder('—');
        $entries[] = TextEntry::make('clientId')->label('Client')->state($event->clientId)->placeholder('—');

        // Each detail is its own entry (label = detail key), not a single bulleted blob.
        foreach ($event->details as $key => $value) {
            $entries[] = TextEntry::make('detail_' . $key)->label($key)->state($value);
        }

        return $entries;
    }

    private static function dto(KeycloakRecord $record): KeycloakUserEvent
    {
        $event = $record->dto();
        assert($event instanceof KeycloakUserEvent);

        return $event;
    }

    public function render(): View
    {
        return view('filament-keycloak-admin::livewire.keycloak-table');
    }
}
