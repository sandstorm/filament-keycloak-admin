<?php

declare(strict_types=1);

namespace Sandstorm\FilamentKeycloakAdmin\Filament\Pages\InspectKeycloakUser;

use Filament\Actions\Action;
use Filament\Actions\Concerns\InteractsWithActions;
use Filament\Actions\Contracts\HasActions;
use Filament\Forms\Components\Field;
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
use Sandstorm\KeycloakAdminApi\Features\KeycloakRealmApi;
use Sandstorm\KeycloakAdminApi\Features\KeycloakRealmApi\Dto\KeycloakUserProfileAttributes;
use Sandstorm\KeycloakAdminApi\Features\KeycloakUsersApi;
use Sandstorm\KeycloakAdminApi\Features\KeycloakUsersApi\Dto\KeycloakUser;
use Sandstorm\KeycloakAdminApi\SharedModel\KeycloakUserId;

use function array_keys;
use function in_array;

/**
 * Identity section of the detail page — the key/value fields (username, email, name, email-verified,
 * plus the realm's custom User-Profile attributes) and a pending-TOTP note, and the **write** surface: a
 * live enable/disable toggle (like the stock Keycloak user page) and an "Edit" action for the names,
 * email-verified flag, and every admin-editable custom attribute. Its own Livewire component so the host
 * page fetches nothing. A failed read is not caught here: it propagates to {@see
 * \Sandstorm\FilamentKeycloakAdmin\Exceptions\KeycloakLoadErrorRenderer}.
 *
 * The custom attributes are **not** free-form key/value pairs: which attributes exist, their labels,
 * widgets, validators, required-ness, and who may view/edit them all come from Keycloak's declarative
 * User-Profile schema (`GET /users/profile`, plan §5), so the Identity form mirrors the Keycloak
 * console's own user form — one "Details" surface, not a separate attributes card. The schema→field
 * translation lives in {@see KeycloakUserAttributes}; this component owns only the identity chrome, the
 * Edit action, and the write.
 *
 * Two authorities compose per control. The enable toggle and the Edit modal are gated on the
 * caller-relative `user.access.manage` (plan §2.1): if this admin may not manage the user, the toggle is
 * disabled and the pencil is disabled (not hidden — stays discoverable). Within the Edit modal, each
 * custom attribute additionally honours its schema permission (`adminCanEdit`): a view-only attribute is
 * shown read-only in the infolist and never appears as an editable field. A write can still be denied by
 * a mid-flight grant change, so every write goes through
 * {@see InteractsWithKeycloakWrites::runKeycloakWrite()}, which surfaces a 401/403 as a friendly notice.
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

    protected KeycloakRealmApi $realmApi;

    /**
     * Per-request cache of the fetched user (private → never serialized by Livewire; re-resolved each
     * request, cleared on write and on the cross-tab signal so the next read is fresh).
     */
    private ?KeycloakUser $user = null;

    /**
     * The realm's User-Profile attribute collection, fetched once per request (realm-wide schema,
     * unchanged between the two reads a write performs).
     */
    private ?KeycloakUserProfileAttributes $profileAttributes = null;

    /**
     * Per-request cache of the schema→field mapper built from {@see $profileAttributes}.
     */
    private ?KeycloakUserAttributes $attributeMapper = null;

    public function boot(KeycloakUsersApi $usersApi, KeycloakRealmApi $realmApi): void
    {
        $this->usersApi = $usersApi;
        $this->realmApi = $realmApi;
    }

    public function mount(string $userId): void
    {
        $this->userId = $userId;
        $this->syncEnabledToggle();
    }

    /**
     * A write in a sibling table (e.g. a credential removal) — or one of this component's own writes —
     * can change identity state, so re-read on the shared cross-tab signal: drop the caches and resync the
     * toggle to the server's truth, and the infolist rebuilds from the fresh fetch (plan §7.2).
     */
    #[On('keycloak-user-changed')]
    public function refresh(): void
    {
        $this->user = null;
        $this->profileAttributes = null;
        $this->attributeMapper = null;
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
            ...$this->attributeEntries($user),
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
        $succeeded = $this->runKeycloakWrite(
            'user.set_enabled',
            ['target_user_id' => $this->userId, 'enabled' => $enabled],
            function () use ($enabled): void {
                $user = $this->usersApi->getById(new KeycloakUserId($this->userId));
                $this->usersApi->update($user->withEnabled($enabled));
            },
        );

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
     * Edit the built-in identity fields (names + email-verified) **and** the admin-editable custom
     * attributes in one modal, mirroring the Keycloak console's single user form. Rendered as a small
     * pencil hint-action beside each field it changes. When the caller may not manage this user the pencil
     * is *disabled* (not hidden) with a tooltip explaining why — so the affordance stays discoverable and
     * the missing permission is explicit. The write is a lossless read-modify-write through the DTO:
     * unlisted/managed attributes are never touched.
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
                ...$this->attributeMapper()->formState($this->loadUser()),
            ])
            ->schema([
                TextInput::make('firstName')->label('First name'),
                TextInput::make('lastName')->label('Last name'),
                Toggle::make('emailVerified')->label('Email verified'),
                ...$this->attributeFields(),
            ])
            ->action(function (array $data): void {
                $succeeded = $this->runKeycloakWrite(
                    'user.update_identity',
                    ['target_user_id' => $this->userId, 'updated_fields' => array_keys($data)],
                    function () use ($data): void {
                        $user = $this->usersApi->getById(new KeycloakUserId($this->userId))
                            ->withFirstName($data['firstName'])
                            ->withLastName($data['lastName'])
                            ->withEmailVerified((bool) $data['emailVerified']);

                        foreach ($this->attributeMapper()->editable() as $attribute) {
                            $user = $user->withAttribute($attribute->name, $this->attributeMapper()->values($attribute, $data[$attribute->name] ?? null));
                        }

                        $this->usersApi->update($user);
                    },
                );

                $this->user = null;

                if ($succeeded) {
                    $this->dispatch('keycloak-user-changed');
                    Notification::make()->title('Identity updated')->success()->send();
                }
            });
    }

    /**
     * Read entries for the realm's admin-viewable custom attributes, in schema order. An admin-editable
     * attribute carries the same pencil hint-action as the built-in fields (it is edited in the same
     * modal); a view-only attribute is shown without one.
     *
     * @return list<TextEntry>
     */
    private function attributeEntries(KeycloakUser $user): array
    {
        $entries = [];
        foreach ($this->attributeMapper()->viewable() as $attribute) {
            $entry = TextEntry::make('attr_' . $attribute->name)
                ->label($this->attributeMapper()->label($attribute))
                ->state($this->attributeMapper()->displayValue($attribute, $user))
                ->placeholder('—');

            if ($attribute->permissions->adminCanEdit()) {
                $entry->hintAction($this->editIdentityAction());
            }

            $entries[] = $entry;
        }

        return $entries;
    }

    /**
     * The editable custom attributes as Filament form fields for the Edit modal.
     *
     * @return list<Field>
     */
    private function attributeFields(): array
    {
        $fields = [];
        foreach ($this->attributeMapper()->editable() as $attribute) {
            $fields[] = $this->attributeMapper()->buildField($attribute);
        }

        return $fields;
    }

    private function attributeMapper(): KeycloakUserAttributes
    {
        return $this->attributeMapper ??= new KeycloakUserAttributes($this->loadProfileAttributes());
    }

    private function loadUser(): KeycloakUser
    {
        return $this->user ??= $this->usersApi->getById(new KeycloakUserId($this->userId));
    }

    private function loadProfileAttributes(): KeycloakUserProfileAttributes
    {
        return $this->profileAttributes ??= $this->realmApi->getUserProfile()->attributes;
    }

    public function render(): View
    {
        return view('filament-keycloak-admin::livewire.keycloak-identity');
    }
}
