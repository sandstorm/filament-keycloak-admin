<?php

declare(strict_types=1);

namespace Sandstorm\FilamentKeycloakAdmin\Tests\Integration;

use Livewire\Livewire;
use PHPUnit\Framework\Attributes\Group;
use PHPUnit\Framework\Attributes\Test;
use Sandstorm\FilamentKeycloakAdmin\Filament\Pages\InspectKeycloakUser\KeycloakUserIdentity;
use Sandstorm\KeycloakAdminApi\Features\KeycloakUsersApi;

use function app;

/**
 * E2E coverage for the custom User-Profile attributes folded into the Identity section (plan §5), driven
 * through the real Filament/Livewire layer against a live Keycloak. The `test-realm` import ships a
 * declarative User-Profile config with two custom attributes on top of the built-in identity fields:
 *
 *   - **department** — admin-viewable *and* admin-editable (`edit:["admin"]`)
 *   - **costCenter**  — admin-viewable but **not** admin-editable (`edit:[]`)
 *
 * so the same seed proves both the writable-field path and the read-only path. jane carries seeded values
 * (`department=Engineering`, `costCenter=CC-100`). Every mutation is restored so the read-focused E2E
 * tests keep seeing the same seed.
 */
#[Group('integration')]
final class KeycloakUserProfileAttributesE2ETest extends IntegrationTestCase
{
    /**
     * The Identity infolist renders the realm's custom attributes — label from the User-Profile schema,
     * value from the user — alongside the built-in fields.
     */
    #[Test]
    public function identity_section_shows_the_custom_profile_attributes(): void
    {
        $janeId = (string) $this->seededUserId()->value;

        Livewire::test(KeycloakUserIdentity::class, ['userId' => $janeId])
            ->assertSee('Department')
            ->assertSee('Engineering')
            ->assertSee('Cost center')
            ->assertSee('CC-100');
    }

    /**
     * The Edit modal edits an admin-editable custom attribute (department) via a lossless read-modify-
     * write, and the admin-view-only attribute (costCenter) survives untouched — proving both that a
     * schema-editable attribute is writable and that a non-editable one is preserved, never cleared.
     */
    #[Test]
    public function edit_modal_updates_an_editable_attribute_and_preserves_a_view_only_one(): void
    {
        $users = app(KeycloakUsersApi::class);
        $janeId = $this->seededUserId();
        $before = $users->getById($janeId);

        try {
            Livewire::test(KeycloakUserIdentity::class, ['userId' => (string) $janeId->value])
                ->callAction('editIdentity', [
                    'firstName' => $before->firstName,
                    'lastName' => $before->lastName,
                    'emailVerified' => $before->emailVerified,
                    'department' => 'Sales',
                ])
                ->assertHasNoActionErrors();

            $after = $users->getById($janeId);
            self::assertSame('Sales', $after->firstAttributeValue('department'), 'the editable attribute should persist');
            self::assertSame('CC-100', $after->firstAttributeValue('costCenter'), 'the admin-view-only attribute must survive the write untouched');
        } finally {
            $users->update($users->getById($janeId)->withAttribute('department', ['Engineering']));
        }
    }
}
