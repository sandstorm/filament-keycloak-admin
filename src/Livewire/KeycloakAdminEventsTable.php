<?php

declare(strict_types=1);

namespace Sandstorm\FilamentKeycloakAdmin\Livewire;

use Sandstorm\FilamentKeycloakAdmin\Filament\KeycloakRecord;
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
use Livewire\Component;
use Sandstorm\KeycloakAdminApi\Features\KeycloakEventsApi;
use Sandstorm\KeycloakAdminApi\Features\KeycloakEventsApi\Dto\KeycloakAdminEvent;
use Sandstorm\KeycloakAdminApi\SharedModel\KeycloakUserId;

use function assert;
use function view;

/**
 * Admin history tab — administrative actions performed ON this user (who changed what), as a
 * server-side-paginated table. Columns: Time · Operation · Resource · By (acting admin) · IP, with the
 * full detail (auth client/ip, error, JSON representation) behind a row "Details" modal (an infolist).
 *
 * Keycloak's `/admin-events` endpoint has no count, so pagination is "simple" (Prev/Next) via a
 * `perPage + 1` probe. The catchable auth failure (§6.3) is handled by the `@keycloakboundary` in this
 * component's view; every other failure propagates.
 */
final class KeycloakAdminEventsTable extends Component implements HasActions, HasSchemas, HasTable
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

    public function table(Table $table): Table
    {
        return $table
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
                    ->infolist(fn (KeycloakRecord $record): array => self::detailEntries(self::dto($record))),
            ]);
    }

    /**
     * Fetch one page, requesting one extra row so the simple paginator knows whether a next page exists
     * (Keycloak has no admin-events count). Auth failure propagates to the `@keycloakboundary` in the view.
     */
    private function loadEvents(int $page, int $recordsPerPage): Paginator
    {
        $first = ($page - 1) * $recordsPerPage;

        $events = $this->eventsApi->getAdminEventsForUser(new KeycloakUserId($this->userId), $first, $recordsPerPage + 1);

        $records = [];
        foreach ($events as $index => $event) {
            $records[] = KeycloakRecord::for((string) ($first + $index), $event);
        }

        return new Paginator($records, $recordsPerPage, $page);
    }

    /**
     * The "Details" modal infolist for one admin event.
     *
     * @return list<TextEntry>
     */
    private static function detailEntries(KeycloakAdminEvent $event): array
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

    public function render(): View
    {
        return view('keycloak-filament-admin::livewire.keycloak-admin-events-table');
    }
}
