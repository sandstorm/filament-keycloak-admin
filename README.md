# Filament Keycloak Admin

A Filament v4 admin panel to manage Keycloak users through the Keycloak Admin REST API. Keycloak is
the source of truth — there is no local user mirror. This package is **UI only**; every HTTP call goes
through the standalone client library [`sandstorm/keycloak-admin-api`](https://github.com/sandstorm/keycloak-admin-api).

- **Target:** Filament v4, PHP ^8.3, Keycloak 26.5.3+.
- **Features:** searchable user list; per-user detail with Identity, Groups (add/remove), Security/2FA
  (remove second factors), Active sessions (log out all), User events, and Admin history; a
  "Send password-reset email" action.

## Installation

```bash
composer require sandstorm/filament-keycloak-admin
```

Register the plugin on a panel:

```php
use Sandstorm\FilamentKeycloakAdmin\FilamentKeycloakAdminPlugin;

$panel->plugin(FilamentKeycloakAdminPlugin::make());
```

## Configuration

The plugin reads resolved `config('filament-keycloak-admin.*')` and never calls `env()` itself — the
consuming app owns the authoritative config. Provide `config/filament-keycloak-admin.php` in your app:

```php
return [
    'connection' => [
        'base_url'      => 'https://keycloak.example/',
        'realm'         => 'YourRealm',
        'client_id'     => 'admin-panel-serviceaccount',
        'client_secret' => '…',
    ],
    'auth_mode' => 'service_account', // 'service_account' | 'sso' (planned)
    'http' => [
        'connect_timeout' => 5,
        'timeout'         => 15,
    ],
    'pw_reset' => [
        'lifespan'     => 43200,
        'client_id'    => null,
        'redirect_uri' => null,
    ],
];
```

The package publishes only a structure-only stub (keys + docs, no values, no `env()`).

### Auth modes

- **`service_account`** (wired today) — a `client_credentials` grant on a confidential client with the
  required `realm-management` roles: `view-users`, `manage-users`, `query-users`, `query-groups` (plus
  `view-events` for the event tabs). One shared identity.
- **`sso`** (planned) — reuse the logged-in admin's Keycloak SSO token as the Admin-API bearer for real
  per-person attribution in admin events. Until it lands, `auth_mode=sso` throws.

There is **no fallback**: a misconfigured or underprivileged mode fails loudly. Every failure (including
401/403) propagates to the framework error page.

## Testing

```bash
composer test              # unit/feature suite (hermetic)
composer test:integration  # E2E against a real Keycloak (opt-in; requires KEYCLOAK_E2E_BASE_URL)
composer analyse           # PHPStan
composer test:lint         # Pint
```

## License

MIT. See [LICENSE.md](LICENSE.md).
