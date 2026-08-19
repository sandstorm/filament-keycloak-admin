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

### Auth modes

- **`service_account`** (wired today) — a `client_credentials` grant on a confidential client with the
  required `realm-management` roles: `view-users`, `manage-users`, `query-users`, `query-groups` (plus
  `view-events` for the event tabs). One shared identity.
- **`sso`** (act-as-user) — the Admin-API bearer is the **logged-in admin's own** Keycloak token, so
  Keycloak evaluates that person's fine-grained permissions and attributes admin events to them.
  `FilamentSsoTokenProvider` reads the token through an `AdminKeycloakSession` seam: bind your own, or
  install `heloufir/filament-keycloak-sso` for the bundled `HeloufirAdminKeycloakSession` adapter.
  Selecting `sso` with neither present fails loudly.

There is **no fallback**: a misconfigured or underprivileged mode fails loudly. Every read failure
(including 401/403) propagates to the framework error page.

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

`mise run e2e` boots a throwaway Keycloak 26.5.3 (`tests/Integration/docker-compose.yml`) importing two
realms and runs the opt-in `integration` suite against it. To iterate:

```bash
mise run e2e:up            # boot Keycloak (both realms) on http://localhost:9911
mise run test:integration  # run the integration suite (sets KEYCLOAK_E2E_BASE_URL for you)
mise run e2e:down          # tear down
```

**Log into the Keycloak admin console** at <http://localhost:9911> with **`admin` / `admin`** (the
`KC_BOOTSTRAP_ADMIN_*` creds in the compose file). Seeded users all have password `changeit`.

Two realms are imported so the `sso` act-as-user path is proven in both authorization modes:

| Realm | Admin Permissions (FGAP) | Notable seeded users |
|-------|--------------------------|----------------------|
| `test-realm` | **off** (classic roles) | `admin-user` (realm-management roles), `login-user` (none), `jane` in `/staff` |
| `test-realm-fgap` | **on** | `admin-user`, `login-user`, `sarah` + `jane` in `/staff`, `emma` in `/endusers` |

#### FGAP staff policy (already baked into the realm import)

`test-realm-fgap` demonstrates group-based fine-grained scoping: a **staff** member (group `/staff`) may
**read all users** and **change any enduser** (`/endusers`), but **may not change another staff member**.
It's expressed with two permissions and one policy — no negation, no precedence tricks:

- **Group policy** `match-staff` → matches members of `/staff`.
- **"staff can view all"** — resource type **Users**, scope **`view`**, All users → flat listing/search of
  everyone.
- **"staff can manage endusers"** — resource type **Groups**, enforce access to the **specific `/endusers`
  group**, scope **`manage-members`**. Staff get manage only on that group's members, so `/staff` members
  are protected simply by never being granted.

Two KC 26 subtleties this encodes (see the schema in the import): scopes have **no transitive dependency**
(`manage` ≠ `view`), and editing a member's *user account* is Groups **`manage-members`** — which the
`authorizationSchema` aliases to Users **`manage`** (`manage → [manage-members]`), **not** Groups `manage`
(the group object). One gotcha while authoring: the console **Evaluate** panel tallies *literal* scope
names, so a Users/`manage` probe shows "Denied scope: manage" even when `manage-members` permits it — the
real Admin API resolves the alias, so trust a real call (the E2E) over that panel line.

This is already committed in `realm-import-fgap.json`, so `mise run e2e` reproduces it with no manual step.
**If you re-author it in the console**, note the manage permission targets a *specific group by UUID*
(`resources: ["<endusers-group-id>"]`) and KC adds a matching entry under `authorizationSettings.resources`.
That UUID is realm-specific, so the import **pins** the `/endusers` group id
(`4b22752d-0719-48a2-942d-5f9057c57d81`) to keep the reference stable across re-imports. To refresh from a
new **Partial export** (Action menu → tick **Include clients** + groups): merge the `admin-permissions`
client's `authorizationSettings` and the groups-with-ids back in — but keep this repo's seeded users and
their `changeit` credentials, which a partial export strips.

**Proof:** `Sso\SsoActAsUserE2ETest::a_staff_member_may_edit_an_enduser_but_not_another_staff_member` acts
as `sarah` and edits `emma` (persists) but is denied `jane` with a 403 — exercised through the client
library's `KeycloakUsersApi::update()` (a lossless read-modify-write), never raw HTTP.

## License

MIT. See [LICENSE.md](LICENSE.md).
