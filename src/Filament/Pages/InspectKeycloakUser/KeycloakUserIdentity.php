<?php

declare(strict_types=1);

namespace Sandstorm\FilamentKeycloakAdmin\Filament\Pages\InspectKeycloakUser;

use Filament\Actions\Action;
use Filament\Actions\Concerns\InteractsWithActions;
use Filament\Actions\Contracts\HasActions;
use Filament\Forms\Components\TextInput;
use Filament\Forms\Components\Toggle;
use Filament\Infolists\Components\IconEntry;
use Filament\Infolists\Components\TextEntry;
use Filament\Notifications\Notification;
use Filament\Schemas\Concerns\InteractsWithSchemas;
use Filament\Schemas\Contracts\HasSchemas;
use Filament\Schemas\Schema;
use Filament\Support\Icons\Heroicon;
use Illuminate\Contracts\View\View;
use Livewire\Attributes\On;
use Livewire\Component;
use Sandstorm\FilamentKeycloakAdmin\Filament\Concerns\InteractsWithKeycloakWrites;
use Sandstorm\KeycloakAdminApi\Features\KeycloakUsersApi;
use Sandstorm\KeycloakAdminApi\Features\KeycloakUsersApi\Dto\KeycloakUser;
use Sandstorm\KeycloakAdminApi\SharedModel\KeycloakUserId;

use function in_array;
use function view;

/**
 * Identity section of the detail page — the key/value fields (username, email, name, email-verified)
 * plus a pending-TOTP note, and now the **write** surface: a live enable/disable toggle (like the stock
 * Keycloak user page) and an "Edit" action for the names + email-verified flag. Its own Livewire
 * component so the host page fetches nothing.
 *
 * Both write controls are gated on the caller-relative capability `user.access.manage` (plan §2.1): if
 * this admin may not manage the user, the toggle is disabled and Edit is hidden — the UI reflects
 * Keycloak's own answer up front. A write can still be denied by a mid-flight grant change, so every
 * write goes through {@see InteractsWithKeycloakWrites::runKeycloakWrite()}, which surfaces a 401/403 as
 * a friendly notice (the scoped exception to plan §8; other failures still propagate).
 */
final class KeycloakUserIdentity extends Component implements HasActions, HasSchemas
{
    use InteractsWithActions;
    use InteractsWithKeycloakWrites;
    use InteractsWithSchemas;

    public string $userId;

    /**
     * Live state of the enable/disable toggle form (statePath `enabledData`). Bound to the toggle switch
     * so flipping it fires {@see setEnabled()} immediately, mirroring the stock Keycloak console.
     *
     * @var array<string, mixed>
     */
    public array $enabledData = [];

    protected KeycloakUsersApi $usersApi;

    /**
     * Per-request cache of the fetched user (private → never serialized by Livewire; re-resolved each
     * request, cleared on write and on the cross-tab signal so the next read is fresh).
     */
    private ?KeycloakUser $user = null;

    public function boot(KeycloakUsersApi $usersApi): void
    {
        $this->usersApi = $usersApi;
    }

    public function mount(string $userId): void
    {
        $this->userId = $userId;
        $this->syncEnabledToggle();
    }

    /**
     * A write in a sibling table (e.g. a credential removal) — or one of this component's own writes —
     * can change identity state, so re-read on the shared cross-tab signal: drop the cache and resync the
     * toggle to the server's truth, and the infolist rebuilds from the fresh fetch (plan §7.2).
     */
    #[On('keycloak-user-changed')]
    public function refresh(): void
    {
        $this->user = null;
        $this->syncEnabledToggle();
    }

    public function identityInfolist(Schema $schema): Schema
    {
        $user = $this->loadUser();

        return $schema->components([
            TextEntry::make('username')->state($user->username),
            TextEntry::make('email')->state($user->email)->placeholder('—'),
            TextEntry::make('name')->label('Name')->state($user->fullName())->placeholder('—')
                ->hintAction($this->editIdentityAction()),
            IconEntry::make('emailVerified')->label('Email verified')->boolean()->state($user->emailVerified)
                ->hintAction($this->editIdentityAction()),
            TextEntry::make('totpPending')
                ->hiddenLabel()
                ->state('TOTP setup pending — the user must configure an authenticator at next login.')
                ->color('warning')
                ->columnSpanFull()
                ->visible(in_array('CONFIGURE_TOTP', $user->requiredActions, true)),
        ])->columns(2);
    }

    /**
     * The live enable/disable switch — a one-field form so flipping it writes immediately (no "save"),
     * like the stock Keycloak user page. Disabled (not hidden) when the caller may not manage this user,
     * so the current state stays visible.
     */
    public function enabledForm(Schema $schema): Schema
    {
        return $schema->statePath('enabledData')->components([
            Toggle::make('enabled')
                ->label('Enabled')
                ->live()
                ->disabled(fn (): bool => ! $this->loadUser()->access->manage)
                ->afterStateUpdated(function (bool $state): void {
                    $this->setEnabled($state);
                }),
        ]);
    }

    /**
     * Persist the enable/disable flip via a read-modify-write update, then resync the toggle to the
     * server's actual state — so a denied (403) or failed flip visibly reverts rather than lying.
     */
    public function setEnabled(bool $enabled): void
    {
        $succeeded = $this->runKeycloakWrite(function () use ($enabled): void {
            $user = $this->usersApi->getById(new KeycloakUserId($this->userId));
            $this->usersApi->update($user->withEnabled($enabled));
        });

        $this->user = null;

        if ($succeeded) {
            $this->dispatch('keycloak-user-changed');
            Notification::make()->title($enabled ? 'User activated' : 'User deactivated')->success()->send();
        }

        // Reflect the server's truth (revert on denial/failure, confirm on success).
        $this->syncEnabledToggle();
    }

    /**
     * Point the toggle's state container at the server's current `enabled` value. Writing the public
     * state array directly (rather than the schema's magic accessor) keeps this the single source the
     * `enabledData`-statePath form renders from.
     */
    private function syncEnabledToggle(): void
    {
        $this->enabledData['enabled'] = $this->loadUser()->enabled;
    }

    /**
     * Edit the free-text identity fields + email-verified flag in a modal. Rendered as a small pencil
     * hint-action beside each field it changes (name, email-verified). When the caller may not manage
     * this user the pencil is *disabled* (not hidden) with a tooltip explaining why — so the affordance
     * stays discoverable and the missing permission is explicit. The write is a lossless
     * read-modify-write through the DTO.
     */
    public function editIdentityAction(): Action
    {
        return Action::make('editIdentity')
            ->icon(Heroicon::OutlinedPencilSquare)
            ->iconButton()
            ->color('gray')
            ->disabled(fn (): bool => ! $this->loadUser()->access->manage)
            ->tooltip(fn (): string => $this->loadUser()->access->manage
                ? 'Edit'
                : 'You do not have permission to edit this user.')
            ->modalHeading('Edit identity')
            ->fillForm(fn (): array => [
                'firstName' => $this->loadUser()->firstName,
                'lastName' => $this->loadUser()->lastName,
                'emailVerified' => $this->loadUser()->emailVerified,
            ])
            ->schema([
                TextInput::make('firstName')->label('First name'),
                TextInput::make('lastName')->label('Last name'),
                Toggle::make('emailVerified')->label('Email verified'),
            ])
            ->action(function (array $data): void {
                $succeeded = $this->runKeycloakWrite(function () use ($data): void {
                    $user = $this->usersApi->getById(new KeycloakUserId($this->userId));
                    $this->usersApi->update(
                        $user->withFirstName($data['firstName'])
                            ->withLastName($data['lastName'])
                            ->withEmailVerified((bool) $data['emailVerified']),
                    );
                });

                $this->user = null;

                if ($succeeded) {
                    $this->dispatch('keycloak-user-changed');
                    Notification::make()->title('Identity updated')->success()->send();
                }
            });
    }

    private function loadUser(): KeycloakUser
    {
        return $this->user ??= $this->usersApi->getById(new KeycloakUserId($this->userId));
    }

    public function render(): View
    {
        return view('filament-keycloak-admin::livewire.keycloak-identity');
    }
}
