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
use Filament\Tables\Columns\IconColumn;
use Filament\Tables\Columns\TextColumn;
use Filament\Tables\Concerns\InteractsWithTable;
use Filament\Tables\Contracts\HasTable;
use Filament\Tables\Table;
use Illuminate\Contracts\View\View;
use Illuminate\Support\Collection;
use Livewire\Attributes\On;
use Livewire\Component;
use Sandstorm\FilamentKeycloakAdmin\Filament\Helpers\KeycloakRecord;
use Sandstorm\KeycloakAdminApi\Features\KeycloakCredentialsApi;
use Sandstorm\KeycloakAdminApi\Features\KeycloakCredentialsApi\Dto\KeycloakCredential;
use Sandstorm\KeycloakAdminApi\SharedModel\KeycloakUserId;

use function assert;
use function config;
use function sprintf;
use function view;

/**
 * Security / 2FA section — the user's stored credentials (password + any OTP/WebAuthn factors) as a
 * table. An OTP/WebAuthn row present means 2FA is configured. Every failure propagates (plan §8).
 *
 * Per-credential removal is **whitelisted**: only second-factor credentials (OTP/WebAuthn) with a real
 * id are removable — `password` is never removable here (removing it is not a 2FA reset and could lock
 * the account). Removing the *last* second factor leaves the account single-factor, so the confirm
 * modal warns in that case. After a removal the table re-reads and broadcasts the cross-tab signal.
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

    #[On('keycloak-user-changed')]
    public function refresh(): void
    {
        $this->resetTable();
    }

    public function table(Table $table): Table
    {
        return $table
            ->heading('Credentials & 2FA')
            ->records(fn (): Collection => $this->loadCredentials())
            ->columns([
                TextColumn::make('type')->badge()->state(fn (KeycloakRecord $record): string => self::dto($record)->type),
                TextColumn::make('userLabel')->label('Label')->state(fn (KeycloakRecord $record): ?string => self::dto($record)->userLabel)->placeholder('—'),
                TextColumn::make('createdAt')->label('Created')->state(fn (KeycloakRecord $record): string => self::dto($record)->formattedCreatedAt()),
                IconColumn::make('secondFactor')->label('Second factor')->boolean()->state(fn (KeycloakRecord $record): bool => self::dto($record)->isSecondFactor()),
            ])
            ->headerActions([
                $this->triggerPasswordResetAction(),
            ])
            ->recordActions([
                $this->removeCredentialAction(),
            ])
            ->emptyStateHeading('No credentials stored.');
    }

    /**
     * Send a password-reset email — Keycloak mails the user a time-limited UPDATE_PASSWORD link (the only
     * password path; the admin never sees or sets it, plan §3.2/§7.2). Lives in the credentials section
     * since it is a credential operation. Requires realm SMTP. Neutral styling — it is not destructive.
     */
    private function triggerPasswordResetAction(): Action
    {
        return Action::make('triggerPasswordReset')
            ->label('Send password-reset email')
            ->icon(Heroicon::OutlinedEnvelope)
            ->color('gray')
            ->requiresConfirmation()
            ->modalHeading('Send a password-reset email?')
            ->modalDescription('Keycloak will email this user a time-limited link to set a new password. You will not see or set the password yourself. Requires realm SMTP to be configured.')
            ->modalSubmitActionLabel('Send email')
            ->action(function (): void {
                $this->credentialsApi->executeActionsEmail(
                    new KeycloakUserId($this->userId),
                    ['UPDATE_PASSWORD'],
                    config('filament-keycloak-admin.pw_reset.lifespan'),
                    config('filament-keycloak-admin.pw_reset.client_id'),
                    config('filament-keycloak-admin.pw_reset.redirect_uri'),
                );

                Notification::make()
                    ->title('Password-reset email sent')
                    ->body('Keycloak has emailed the user a link to set a new password.')
                    ->success()
                    ->send();
            });
    }

    /**
     * Remove a single 2FA credential by id. Whitelisted: visible only for second-factor credentials
     * (OTP/WebAuthn) that carry a real id — never for `password` or id-less display rows.
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

                $this->credentialsApi->delete(new KeycloakUserId($this->userId), $credential->id);

                $this->resetTable();
                $this->dispatch('keycloak-user-changed');

                Notification::make()->title('Credential removed')->success()->send();
            });
    }

    /**
     * Confirm-modal body — warns when this is the user's last second factor (removal leaves the account
     * single-factor).
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
        return view('filament-keycloak-admin::livewire.keycloak-table');
    }
}
