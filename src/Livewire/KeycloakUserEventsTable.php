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
use Sandstorm\KeycloakAdminApi\Features\KeycloakEventsApi\Dto\KeycloakUserEvent;
use Sandstorm\KeycloakAdminApi\SharedModel\KeycloakUserId;

use function assert;
use function view;

/**
 * User events tab — every event Keycloak recorded for this user (LOGIN, LOGIN_ERROR, UPDATE_PASSWORD, …)
 * as a server-side-paginated table, mirroring Keycloak's own list. Columns: Time · Event · IP · Client,
 * error surfaced in the event label, full details behind a row "Details" modal (an infolist).
 *
 * Keycloak's `/events` endpoint has no count, so pagination is "simple" (Prev/Next) via a `perPage + 1`
 * probe. The catchable auth failure (§6.3) is handled by the `@keycloakboundary` in this component's
 * view; every other failure propagates.
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
                    ->infolist(fn (KeycloakRecord $record): array => self::detailEntries(self::dto($record))),
            ]);
    }

    /**
     * Fetch one page, requesting one extra row so the simple paginator knows whether a next page exists
     * (Keycloak has no events count). Auth failure propagates to the `@keycloakboundary` in the view.
     */
    private function loadEvents(int $page, int $recordsPerPage): Paginator
    {
        $first = ($page - 1) * $recordsPerPage;

        $events = $this->eventsApi->getUserEvents(new KeycloakUserId($this->userId), $first, $recordsPerPage + 1);

        $records = [];
        foreach ($events as $index => $event) {
            $records[] = KeycloakRecord::for((string) ($first + $index), $event);
        }

        return new Paginator($records, $recordsPerPage, $page);
    }

    /**
     * The "Details" modal infolist for one event.
     *
     * @return list<TextEntry>
     */
    private static function detailEntries(KeycloakUserEvent $event): array
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
        return view('keycloak-filament-admin::livewire.keycloak-user-events-table');
    }
}
