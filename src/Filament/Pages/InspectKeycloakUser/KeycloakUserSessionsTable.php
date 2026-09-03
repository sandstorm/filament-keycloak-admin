<?php

declare(strict_types=1);

namespace Sandstorm\FilamentKeycloakAdmin\Filament\Pages\InspectKeycloakUser;

use Filament\Actions\Action;
use Filament\Actions\Concerns\InteractsWithActions;
use Filament\Actions\Contracts\HasActions;
use Filament\Notifications\Notification;
use Filament\Schemas\Concerns\InteractsWithSchemas;
use Filament\Schemas\Contracts\HasSchemas;
use Filament\Support\Icons\Heroicon;
use Filament\Tables\Columns\TextColumn;
use Filament\Tables\Concerns\InteractsWithTable;
use Filament\Tables\Contracts\HasTable;
use Filament\Tables\Table;
use Illuminate\Contracts\View\View;
use Illuminate\Support\Collection;
use Livewire\Attributes\On;
use Livewire\Component;
use Sandstorm\FilamentKeycloakAdmin\Filament\Concerns\InteractsWithKeycloakReads;
use Sandstorm\FilamentKeycloakAdmin\Filament\Helpers\KeycloakRecord;
use Sandstorm\FilamentKeycloakAdmin\Logging\LogsKeycloakAdminWrites;
use Sandstorm\KeycloakAdminApi\Features\KeycloakSessionsApi;
use Sandstorm\KeycloakAdminApi\Features\KeycloakSessionsApi\Dto\KeycloakSession;
use Sandstorm\KeycloakAdminApi\SharedModel\KeycloakUserId;

use function assert;
use function view;

/**
 * Active sessions section — the user's currently-live sessions as a table (empty once logged
 * out/expired, which is normal). A "Log out all sessions" header action force-signs-out everywhere,
 * then re-reads the table and broadcasts the cross-tab signal (a logout shows up in user events too —
 * plan §7.2). A failed initial load is caught and shown as the table's empty state
 * ({@see InteractsWithKeycloakReads}); other failures still propagate.
 */
final class KeycloakUserSessionsTable extends Component implements HasActions, HasSchemas, HasTable
{
    use InteractsWithActions;
    use InteractsWithKeycloakReads;
    use InteractsWithSchemas;
    use InteractsWithTable;
    use LogsKeycloakAdminWrites;

    public string $userId;

    protected KeycloakSessionsApi $sessionsApi;

    public function boot(KeycloakSessionsApi $sessionsApi): void
    {
        $this->sessionsApi = $sessionsApi;
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
                ->heading('Active sessions')
                ->records(fn (): Collection => $this->loadSessions())
                ->columns([
                    TextColumn::make('ipAddress')->label('IP address')->state(fn (KeycloakRecord $record): ?string => self::dto($record)->ipAddress)->placeholder('—'),
                    TextColumn::make('start')->label('Started')->state(fn (KeycloakRecord $record): string => self::dto($record)->formattedStart()),
                    TextColumn::make('lastAccess')->label('Last access')->state(fn (KeycloakRecord $record): string => self::dto($record)->formattedLastAccess()),
                    TextColumn::make('clients')->label('Clients')->state(fn (KeycloakRecord $record): string => self::dto($record)->clientsLabel()),
                ])
                ->headerActions([
                    $this->logoutAllAction(),
                ]),
            'No active sessions.',
        );
    }

    private function logoutAllAction(): Action
    {
        return Action::make('logoutAll')
            ->label('Log out all sessions')
            ->icon(Heroicon::OutlinedArrowRightStartOnRectangle)
            ->color('danger')
            ->requiresConfirmation()
            ->modalHeading('Log out all sessions?')
            ->modalDescription('This immediately signs the user out of every device and application. They will need to log in again.')
            ->modalSubmitActionLabel('Log out everywhere')
            ->action(function (): void {
                $this->sessionsApi->logoutAll(new KeycloakUserId($this->userId));

                $this->logKeycloakWrite([
                    self::LOG_CONTEXT_ACTION => 'session.logout_all',
                    'target_user_id' => $this->userId,
                ]);

                $this->resetTable();
                $this->dispatch('keycloak-user-changed');

                Notification::make()->title('All sessions logged out')->success()->send();
            });
    }

    /**
     * @return Collection<int, KeycloakRecord>
     */
    private function loadSessions(): Collection
    {
        return $this->loadFromKeycloak(
            function (): Collection {
                $sessions = $this->sessionsApi->getSessions(new KeycloakUserId($this->userId));

                return (new Collection($sessions->all()))->map(static fn (KeycloakSession $session, int $index): KeycloakRecord => KeycloakRecord::for((string) $index, $session));
            },
            new Collection,
        );
    }

    private static function dto(KeycloakRecord $record): KeycloakSession
    {
        $session = $record->dto();
        assert($session instanceof KeycloakSession);

        return $session;
    }

    public function render(): View
    {
        return view('filament-keycloak-admin::livewire.keycloak-table');
    }
}
