<?php

declare(strict_types=1);

namespace Broodfonds\KeycloakFilamentAdmin\Livewire;

use Broodfonds\KeycloakFilamentAdmin\Filament\KeycloakRecord;
use Filament\Actions\Action;
use Filament\Tables\Columns\IconColumn;
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
use Sandstorm\KeycloakAdminApi\Features\KeycloakCredentialsApi;
use Sandstorm\KeycloakAdminApi\Features\KeycloakCredentialsApi\Dto\KeycloakCredential;
use Sandstorm\KeycloakAdminApi\SharedModel\KeycloakUserId;
use Sandstorm\KeycloakAdminApi\Connection\KeycloakAuthenticationException;

use function assert;
use function report;
use function view;

/**
 * Security / 2FA tab — the user's stored credentials (password + any OTP/WebAuthn factors) as a table.
 * An OTP/WebAuthn row present means 2FA is configured. The catchable auth failure (§6.3) is handled by
 * the `@keycloakboundary` in this component's view; every other failure propagates.
 *
 * Per-credential removal is **whitelisted** (§5.4.3): only second-factor credentials (OTP/WebAuthn) with
 * a real id are removable — `password` is never removable here (removing it is not a 2FA reset and could
 * lock the account), and recovery codes are left out pending an explicit decision. Removing the *last*
 * second factor leaves the account single-factor, so the confirm modal warns in that case. After a
 * removal the table re-reads (invariant #6).
 */
final class KeycloakUserCredentialsTable extends Component implements HasActions, HasSchemas, HasTable
{
    use InteractsWithActions;
    use InteractsWithSchemas;
    use InteractsWithTable;

    public string $userId;

    protected KeycloakCredentialsApi $credentialsApi;

    public function boot(KeycloakCredentialsApi $credentialsApi): void
    {
        $this->credentialsApi = $credentialsApi;
    }

    public function mount(string $userId): void
    {
        $this->userId = $userId;
    }

    public function table(Table $table): Table
    {
        return $table
            ->records(fn (): Collection => $this->loadCredentials())
            ->columns([
                TextColumn::make('type')->badge()->state(fn (KeycloakRecord $record): string => self::dto($record)->type),
                TextColumn::make('userLabel')->label('Label')->state(fn (KeycloakRecord $record): ?string => self::dto($record)->userLabel)->placeholder('—'),
                TextColumn::make('createdAt')->label('Created')->state(fn (KeycloakRecord $record): string => self::dto($record)->formattedCreatedAt()),
                IconColumn::make('secondFactor')->label('Second factor')->boolean()->state(fn (KeycloakRecord $record): bool => self::dto($record)->isSecondFactor()),
            ])
            ->recordActions([
                $this->removeCredentialAction(),
            ])
            ->emptyStateHeading('No credentials stored.');
    }

    /**
     * Remove a single 2FA credential by id. Whitelisted (§5.4.3): visible only for second-factor
     * credentials (OTP/WebAuthn) that carry a real id — never for `password` or id-less display rows.
     */
    private function removeCredentialAction(): Action
    {
        return Action::make('removeCredential')
            ->label('Remove')
            ->icon(Heroicon::OutlinedTrash)
            ->color('danger')
            ->visible(fn (KeycloakRecord $record): bool => self::dto($record)->isSecondFactor() && self::dto($record)->id !== null)
            ->requiresConfirmation()
            ->modalHeading('Remove this 2FA credential?')
            ->modalDescription(fn (KeycloakRecord $record): string => $this->removalWarning(self::dto($record)))
            ->modalSubmitActionLabel('Remove credential')
            ->action(function (KeycloakRecord $record): void {
                $credential = self::dto($record);
                // id non-null is guaranteed by ->visible(); assert for the type-checker and as a guard.
                assert($credential->id !== null);

                try {
                    $this->credentialsApi->delete(new KeycloakUserId($this->userId), $credential->id);
                } catch (KeycloakAuthenticationException $exception) {
                    report($exception);

                    Notification::make()
                        ->title('Not authorized in Keycloak')
                        ->body('You lack permission to remove this credential. See the application log for the full Keycloak error.')
                        ->danger()
                        ->send();

                    return;
                }

                // Re-read so the removed factor disappears from the list (invariant #6).
                $this->resetTable();

                Notification::make()
                    ->title('Credential removed')
                    ->success()
                    ->send();
            });
    }

    /**
     * Confirm-modal body — warns when this is the user's last second factor (removal leaves the account
     * single-factor). §5.4.3: surface the warning either way.
     */
    private function removalWarning(KeycloakCredential $credential): string
    {
        $label = $credential->userLabel ?? $credential->type;
        $base = sprintf('Remove the "%s" credential (%s)?', $label, $credential->type);

        if ($this->countSecondFactors() <= 1) {
            return $base . ' This is the user\'s LAST second factor — removing it leaves the account single-factor (password only).';
        }

        return $base;
    }

    /**
     * How many second-factor credentials the user currently has (drives the last-MFA warning).
     */
    private function countSecondFactors(): int
    {
        return $this->loadCredentials()
            ->filter(static fn (KeycloakRecord $record): bool => self::dto($record)->isSecondFactor())
            ->count();
    }

    /**
     * @return Collection<int, KeycloakRecord>
     */
    private function loadCredentials(): Collection
    {
        $credentials = $this->credentialsApi->get(new KeycloakUserId($this->userId));

        return (new Collection($credentials->all()))->map(static fn (KeycloakCredential $credential, int $index): KeycloakRecord => KeycloakRecord::for((string) $index, $credential));
    }

    private static function dto(KeycloakRecord $record): KeycloakCredential
    {
        $credential = $record->dto();
        assert($credential instanceof KeycloakCredential);

        return $credential;
    }

    public function render(): View
    {
        return view('keycloak-filament-admin::livewire.keycloak-user-credentials-table');
    }
}
