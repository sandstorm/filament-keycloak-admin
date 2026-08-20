<?php

declare(strict_types=1);

namespace Sandstorm\FilamentKeycloakAdmin\Filament\Pages\InspectKeycloakUser;

use Filament\Forms\Components\CheckboxList;
use Filament\Forms\Components\DatePicker;
use Filament\Forms\Components\Field;
use Filament\Forms\Components\Radio;
use Filament\Forms\Components\Select;
use Filament\Forms\Components\TagsInput;
use Filament\Forms\Components\Textarea;
use Filament\Forms\Components\TextInput;
use Sandstorm\KeycloakAdminApi\Features\KeycloakRealmApi\Dto\KeycloakUserProfileAttribute;
use Sandstorm\KeycloakAdminApi\Features\KeycloakRealmApi\Dto\KeycloakUserProfileAttributes;
use Sandstorm\KeycloakAdminApi\Features\KeycloakUsersApi\Dto\KeycloakUser;

use function implode;
use function in_array;
use function preg_match;

/**
 * Maps a realm's declarative User-Profile schema (plan §5) onto the plugin's user form: which custom
 * attributes to show, which to make editable, and — crucially — *what widget* each renders as, driven by
 * Keycloak's own `inputType` annotation so the plugin mirrors the console (a `textarea` attribute becomes
 * a textarea, a `select` becomes a dropdown, a multivalued attribute becomes a tag/checkbox input, …).
 *
 * This is the pure schema→Filament translation extracted out of {@see KeycloakUserIdentity}: it holds no
 * Livewire state and issues no HTTP, so it is fully unit-testable. The owning component supplies the
 * fetched {@see KeycloakUserProfileAttributes} and the {@see KeycloakUser}, wires the returned fields
 * into its modal, and owns the write itself (attaching its Edit action to the read entries).
 *
 * The built-in identity attributes (username/email/firstName/lastName) are rendered by the component's
 * fixed fields, so they are filtered out here — this class is only the *extra* attributes.
 */
final readonly class KeycloakUserAttributes
{
    /**
     * The built-in identity attributes the User-Profile schema always declares — owned by the fixed
     * fields, never rendered as "custom".
     */
    private const BUILT_IN = ['username', 'email', 'firstName', 'lastName'];

    public function __construct(
        private KeycloakUserProfileAttributes $profile,
    ) {}

    /**
     * The custom attributes this admin may **view**, in schema order. Edit implies view (Keycloak grants
     * an editable attribute an implicit read), so an admin-editable attribute is always shown even if its
     * `view` list does not name `admin`; anything the admin can neither view nor edit is omitted.
     *
     * @return list<KeycloakUserProfileAttribute>
     */
    public function viewable(): array
    {
        $attributes = [];
        foreach ($this->profile as $attribute) {
            if (! $this->isCustom($attribute)) {
                continue;
            }

            if ($attribute->permissions->adminCanView() || $attribute->permissions->adminCanEdit()) {
                $attributes[] = $attribute;
            }
        }

        return $attributes;
    }

    /**
     * The custom attributes this admin may **edit** — the collection's own admin-editable projection with
     * the built-in identity attributes removed (they are edited via the fixed fields).
     *
     * @return list<KeycloakUserProfileAttribute>
     */
    public function editable(): array
    {
        $attributes = [];
        foreach ($this->profile->editableByAdmin() as $attribute) {
            if ($this->isCustom($attribute)) {
                $attributes[] = $attribute;
            }
        }

        return $attributes;
    }

    /**
     * A Filament field for one editable attribute, its widget chosen from the schema's `inputType`
     * annotation and its validators mapped from the schema's validators.
     */
    public function buildField(KeycloakUserProfileAttribute $attribute): Field
    {
        $field = $this->componentFor($attribute);

        $field->label($this->label($attribute))->required($attribute->required);

        if ($field instanceof TextInput || $field instanceof Textarea) {
            $this->applyTextValidators($field, $attribute);
        }

        return $field;
    }

    /**
     * The current values of the editable attributes, keyed by attribute name, for the modal's initial
     * fill — a multi-value field as a `list<string>`, a single-value field flattened to a scalar.
     *
     * @return array<string, mixed>
     */
    public function formState(KeycloakUser $user): array
    {
        $state = [];
        foreach ($this->editable() as $attribute) {
            $values = $user->attributes[$attribute->name] ?? [];
            $state[$attribute->name] = $this->isMultiValue($attribute) ? $values : ($values[0] ?? null);
        }

        return $state;
    }

    /**
     * One attribute's submitted form state as Keycloak's `list<string>` shape: a multi-value field is
     * already a list; a single-value field is wrapped, dropping an empty string so a cleared optional
     * field becomes `[]` (which Keycloak removes) rather than `['']`.
     *
     * @return list<string>
     */
    public function values(KeycloakUserProfileAttribute $attribute, mixed $state): array
    {
        if ($this->isMultiValue($attribute)) {
            $values = [];
            foreach ((array) $state as $value) {
                if ($value !== null && $value !== '') {
                    $values[] = (string) $value;
                }
            }

            return $values;
        }

        return ($state === null || $state === '') ? [] : [(string) $state];
    }

    /**
     * The read display of an attribute's value(s): each value mapped through the schema's option labels
     * (so a select shows "Engineering", not its stored code) and joined. Null when the user has no value,
     * so the caller can show a placeholder.
     */
    public function displayValue(KeycloakUserProfileAttribute $attribute, KeycloakUser $user): ?string
    {
        $values = $user->attributes[$attribute->name] ?? [];
        if ($values === []) {
            return null;
        }

        $labels = $attribute->optionLabels();

        $display = [];
        foreach ($values as $value) {
            $label = $labels[$value] ?? $value;
            $display[] = $this->stripLocalizationKey($label) ?? $value;
        }

        return implode(', ', $display);
    }

    /**
     * The attribute's human label — the schema `displayName`, unless it is an unresolved localization key
     * (`${department}`), in which case fall back to the raw attribute name.
     */
    public function label(KeycloakUserProfileAttribute $attribute): string
    {
        return $this->stripLocalizationKey($attribute->displayName) ?? $attribute->name;
    }

    /**
     * Pick the Filament component from Keycloak's `inputType` annotation, mirroring the console's widget
     * choice. A fixed-choice attribute (one with `options`) becomes a select/radio/checkbox-list; the
     * rest map by type, defaulting to a plain text input (or a tag input when multivalued).
     */
    private function componentFor(KeycloakUserProfileAttribute $attribute): Field
    {
        $options = $this->options($attribute);
        if ($options !== []) {
            if ($this->isMultiValue($attribute)) {
                return $attribute->inputType() === 'multiselect-checkboxes'
                    ? CheckboxList::make($attribute->name)->options($options)
                    : Select::make($attribute->name)->multiple()->options($options);
            }

            return $attribute->inputType() === 'select-radiobuttons'
                ? Radio::make($attribute->name)->options($options)
                : Select::make($attribute->name)->options($options);
        }

        return match ($attribute->inputType()) {
            'textarea' => Textarea::make($attribute->name),
            'html5-date' => DatePicker::make($attribute->name),
            default => $this->isMultiValue($attribute)
                ? TagsInput::make($attribute->name)
                : $this->textInputFor($attribute),
        };
    }

    private function textInputFor(KeycloakUserProfileAttribute $attribute): TextInput
    {
        $field = TextInput::make($attribute->name);

        match ($attribute->inputType()) {
            'html5-email' => $field->email(),
            'html5-number' => $field->numeric(),
            'html5-tel' => $field->tel(),
            'html5-url' => $field->url(),
            default => null,
        };

        return $field;
    }

    private function applyTextValidators(TextInput | Textarea $field, KeycloakUserProfileAttribute $attribute): void
    {
        if ($field instanceof TextInput && $attribute->requiresEmailFormat()) {
            $field->email();
        }

        $maxLength = $attribute->maxLength();
        if ($maxLength !== null) {
            $field->maxLength($maxLength);
        }

        $minLength = $attribute->minLength();
        if ($minLength !== null) {
            $field->minLength($minLength);
        }

        if ($field instanceof TextInput) {
            $pattern = $attribute->pattern();
            if ($pattern !== null) {
                $field->regex('/' . $pattern . '/');
            }
        }
    }

    /**
     * The select/radio options as `value => label`, labels resolved from the schema's option labels and
     * falling back to the raw value when a label is absent or an unresolved localization key.
     *
     * @return array<string, string>
     */
    private function options(KeycloakUserProfileAttribute $attribute): array
    {
        $labels = $attribute->optionLabels();

        $options = [];
        foreach ($attribute->options() as $value) {
            $options[$value] = $this->stripLocalizationKey($labels[$value] ?? null) ?? $value;
        }

        return $options;
    }

    /**
     * Whether the field holds multiple values — a multivalued attribute, or a multi-choice input type.
     */
    private function isMultiValue(KeycloakUserProfileAttribute $attribute): bool
    {
        return $attribute->multivalued
            || in_array($attribute->inputType(), ['multiselect', 'multiselect-checkboxes'], true);
    }

    private function isCustom(KeycloakUserProfileAttribute $attribute): bool
    {
        return ! in_array($attribute->name, self::BUILT_IN, true);
    }

    /**
     * Return the string unless it is null or an unresolved localization key (`${foo}`), in which case
     * return null so the caller can fall back.
     */
    private function stripLocalizationKey(?string $value): ?string
    {
        if ($value === null || $value === '' || preg_match('/^\$\{.*\}$/', $value) === 1) {
            return null;
        }

        return $value;
    }
}
