# Filament Keycloak Admin

A Filament v4 admin panel to manage Keycloak users through the Keycloak Admin REST API. Keycloak is the source of
truth — there is no local user mirror. This package is **UI only**; every HTTP call goes through the standalone client
library [`sandstorm/keycloak-admin-api`](https://github.com/sandstorm/keycloak-admin-api).

- **Target:** Filament v4, PHP ^8.3, Keycloak 26.5.3+.
- **Features:** searchable user list; per-user detail with Identity (edit + enable toggle + User-Profile
  attributes), Groups (add/remove), Security/2FA (remove second factors), Active sessions (log out all),
  User events, and Admin history; and a "Send password-reset email" action.

The whole package is work in progress, and is extended as needed.

> Thanks to [BroodfondsMakers](https://www.broodfonds.nl/) for sponsoring the development of this package,
> and for agreeing to Open Source it!

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

The plugin reads resolved `config('filament-keycloak-admin.*')` and never calls `env()` itself — the consuming app owns
the authoritative config. Provide `config/filament-keycloak-admin.php` in your app:

```php
return [
    'connection' => [
        'backchannel_url'    => 'http://keycloak.internal:8080/', // required
        'frontend_url'       => 'https://login.example/',         // optional, defaults to backchannel_url
        'administration_url' => null,                             // optional, defaults to frontend_url
        'realm'              => 'YourRealm',
        'client_id'          => 'admin-panel-serviceaccount',
        'client_secret'      => '…',
    ],
    'auth_mode' => 'service_account', // 'service_account' | 'sso' (act-as-user; see Auth modes below)
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

### Connection URLs

The three URLs follow [Keycloak's hostname nomenclature](https://www.keycloak.org/server/hostname), because a
Keycloak instance can be reachable under a different address per channel:

| Key | Keycloak option | Used for |
|---|---|---|
| `backchannel_url` | `--hostname-backchannel-dynamic` | This application's *own* calls: the Admin REST API and the service-account token endpoint. Frequently an internal/cluster address. |
| `frontend_url` | `--hostname` | Anything the admin's **browser** is sent to. |
| `administration_url` | `--hostname-admin` | The administration console's own base URL. |

Only `backchannel_url` is required; `administration_url` falls back to `frontend_url`, which falls back to
`backchannel_url` — the right behaviour for a single-hostname deployment.

Only the backchannel URL is used today; the other two exist so a browser is never handed an internal address.

### Auth modes

- **`service_account`** (wired today) — a `client_credentials` grant on a confidential client with the required
  `realm-management` roles: `view-users`, `manage-users`, `query-users`, `query-groups` (plus
  `view-events` for the event tabs). One shared identity.
- **`sso`** (act-as-user) — the Admin-API bearer is the **logged-in admin's own** Keycloak token, so Keycloak evaluates
  that person's fine-grained permissions and attributes admin events to them.
  `FilamentSsoTokenProvider` reads the token through an `AdminKeycloakSession` seam: bind your own, or install
  `heloufir/filament-keycloak-sso` for the bundled `HeloufirAdminKeycloakSession` adapter. Selecting `sso` with neither
  present fails loudly.

There is **no fallback**: a misconfigured or underprivileged mode fails loudly. Every read failure (including 401/403)
propagates to the framework error page.

## Testing

Use **mise** (see `mise.toml`):

```bash
composer install
mise run test              # unit/feature suite (hermetic, no Keycloak)
mise run analyse           # PHPStan
mise run lint              # Pint
mise run e2e               # full E2E cycle: boot Keycloak → integration suite → tear down
```

### End-to-end against a real Keycloak

`mise run e2e` boots a throwaway Keycloak 26.5.3 (`tests/Integration/docker-compose.yml`) importing two realms and runs
the opt-in `integration` suite against it. To iterate:

```bash
mise run e2e:up            # boot Keycloak (both realms) on http://localhost:9911
mise run test:integration  # run the integration suite (sets KEYCLOAK_E2E_BASE_URL for you)
mise run e2e:down          # tear down
```

**Log into the Keycloak admin console** at <http://localhost:9911> with **`admin` / `admin`** (the
`KC_BOOTSTRAP_ADMIN_*` creds in the compose file). Seeded users all have password `changeit`.

Two realms are imported so the `sso` act-as-user path is proven in both authorization modes:

| Realm             | Admin Permissions (FGAP) | Notable seeded users                                                            |
|-------------------|--------------------------|---------------------------------------------------------------------------------|
| `test-realm`      | **off** (classic roles)  | `admin-user` (realm-management roles), `login-user` (none), `jane` in `/staff`  |
| `test-realm-fgap` | **on**                   | `admin-user`, `login-user`, `sarah` + `jane` in `/staff`, `emma` in `/endusers` |

#### FGAP staff policy (baked into `realm-import-fgap.json`)

Staff read everyone, edit endusers, can't touch other staff:

```
match-staff  = Group policy → members of /staff
                │
   ┌────────────┴─────────────────────────────┐
   ▼                                           ▼
"staff can view all"                  "staff can manage endusers"
 Users · view · All users              Groups · manage-members · group /endusers
   │                                           │
   ▼                                           ▼
 sarah ──view──▶ everyone            sarah ──manage──▶ emma (∈/endusers)  ✔
                                     sarah ──manage──▶ jane (∈/staff)     ✘ 403
```

## License

MIT. See [LICENSE.md](LICENSE.md).
