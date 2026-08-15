<?php

declare(strict_types=1);

namespace Broodfonds\KeycloakFilamentAdmin\Livewire;

use Broodfonds\KeycloakFilamentAdmin\Filament\KeycloakRecord;
use Filament\Actions\Action;
use Filament\Tables\Columns\TextColumn;
use Filament\Actions\Concerns\InteractsWithActions;
use Filament\Actions\Contracts\HasActions;
use Filament\Notifications\Notification;
use Filament\Schemas\Concerns\InteractsWithSchemas;
use Filament\Schemas\Contracts\HasSchemas;
use Filament\Support\Icons\Heroicon;
use Filament\Tables\Concerns\InteractsWithTable;
use Filament\Tables\Contracts\HasTable;
use Filament\Tables\Table;
use Illuminate\Contracts\View\View;
use Illuminate\Support\Collection;
use Livewire\Component;
use Sandstorm\KeycloakAdminApi\Features\KeycloakSessionsApi;
use Sandstorm\KeycloakAdminApi\Features\KeycloakSessionsApi\Dto\KeycloakSession;
use Sandstorm\KeycloakAdminApi\SharedModel\KeycloakUserId;
use Sandstorm\KeycloakAdminApi\Connection\KeycloakAuthenticationException;

use function assert;
use function report;
use function view;

/**
 * Active sessions tab — the user's currently-live sessions as a table (empty once logged out/expired,
 * which is normal, not an error — plan §2.3). The catchable auth failure (§6.3) is handled by the
 * `@keycloakboundary` in this component's view; every other failure propagates. A "Log out all sessions"
 * header action force-signs-out everywhere, then re-reads the table (invariant #6).
 */
final class KeycloakUserSessionsTable extends Component implements HasActions, HasSchemas, HasTable
{
    use InteractsWithActions;
    use InteractsWithSchemas;
    use InteractsWithTable;

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

    public function table(Table $table): Table
    {
        return $table
            ->records(fn (): Collection => $this->loadSessions())
            ->columns([
                TextColumn::make('ipAddress')->label('IP address')->state(fn (KeycloakRecord $record): ?string => self::dto($record)->ipAddress)->placeholder('—'),
                TextColumn::make('start')->label('Started')->state(fn (KeycloakRecord $record): string => self::dto($record)->formattedStart()),
                TextColumn::make('lastAccess')->label('Last access')->state(fn (KeycloakRecord $record): string => self::dto($record)->formattedLastAccess()),
                TextColumn::make('clients')->label('Clients')->state(fn (KeycloakRecord $record): string => self::dto($record)->clientsLabel()),
            ])
            ->headerActions([
                $this->logoutAllAction(),
            ])
            ->emptyStateHeading('No active sessions.');
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
                try {
                    $this->sessionsApi->logoutAll(new KeycloakUserId($this->userId));
                } catch (KeycloakAuthenticationException $exception) {
                    // The one catchable failure (§6.3): not permitted → friendly notice, still report()ed.
                    report($exception);

                    Notification::make()
                        ->title('Not authorized in Keycloak')
                        ->body('You lack permission to log this user out. See the application log for the full Keycloak error.')
                        ->danger()
                        ->send();

                    return;
                }

                // Re-read so the (now empty) session list reflects real server state (invariant #6).
                $this->resetTable();

                Notification::make()
                    ->title('All sessions logged out')
                    ->success()
                    ->send();
            });
    }

    /**
     * @return Collection<int, KeycloakRecord>
     */
    private function loadSessions(): Collection
    {
        $sessions = $this->sessionsApi->getSessions(new KeycloakUserId($this->userId));

        return (new Collection($sessions->all()))->map(static fn (KeycloakSession $session, int $index): KeycloakRecord => KeycloakRecord::for((string) $index, $session));
    }

    private static function dto(KeycloakRecord $record): KeycloakSession
    {
        $session = $record->dto();
        assert($session instanceof KeycloakSession);

        return $session;
    }

    public function render(): View
    {
        return view('keycloak-filament-admin::livewire.keycloak-user-sessions-table');
    }
}
