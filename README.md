# Filament Keycloak Admin

A Filament v4 admin panel to manage Keycloak users through the Keycloak Admin REST API. Keycloak is the source of
truth — there is no local user mirror. This package is **UI only**; every HTTP call goes through the standalone client
library [`sandstorm/keycloak-admin-api`](https://github.com/sandstorm/keycloak-admin-api).

- **Target:** Filament v4, PHP ^8.3, Keycloak 26.5.3+.
- **Features:** searchable user list; per-user detail with Identity (edit + enable toggle + User-Profile attributes),
  Groups (add/remove), Security/2FA (remove second factors), Active sessions (log out all), User events, and Admin
  history; and a "Send password-reset email" action.

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

## Panel integration

Where the module shows up, and who sees it, is configured on the plugin instance:

```php
use Filament\Support\Icons\Heroicon;
use Sandstorm\FilamentKeycloakAdmin\FilamentKeycloakAdminPlugin;

$panel->plugin(
    FilamentKeycloakAdminPlugin::make()
        ->authorize(fn (): bool => auth()->user()?->can('manage-keycloak-users') ?? false)
        ->navigationLabel('Login accounts')
        ->navigationGroup('User management')
        ->navigationIcon(Heroicon::OutlinedKey)
        ->navigationSort(30),
);
```

| Method                                              | Default                   | Effect                                                                                                                                                         |
|-----------------------------------------------------|---------------------------|----------------------------------------------------------------------------------------------------------------------------------------------------------------|
| `authorize(Closure\|bool)`                          | `true` (everyone)         | Who may use the module. Gates `canAccess()` on **both** the list and the detail page, so an unauthorized admin gets a 403 on the URL as well as no menu entry. |
| `navigationLabel(Closure\|string\|null)`            | `'Keycloak Users'`        | Name in the main menu; also the list page's own title.                                                                                                         |
| `navigationGroup(Closure\|string\|UnitEnum\|null)`  | `null` (top level)        | Parent menu category to list the module under.                                                                                                                 |
| `navigationParentItem(Closure\|string\|null)`       | `null`                    | Nests the module under another navigation *item* (by its label) instead of under a group.                                                                      |
| `navigationIcon(Closure\|string\|BackedEnum\|null)` | `Heroicon::OutlinedUsers` | Menu icon. Pass `null` for no icon. **Ignored while a navigation group is set** — see below.                                                                   |
| `navigationSort(Closure\|int\|null)`                | `null`                    | Position within its menu group.                                                                                                                                |
| `registerNavigation(Closure\|bool)`                 | `true`                    | Whether the module appears in the menu at all. `false` keeps it reachable by URL (still subject to `authorize()`) — for panels that link to it themselves.     |

Every setter also accepts a closure, evaluated on each access, so the value may depend on the authenticated admin or on
runtime configuration:

## Configuration

The plugin reads resolved `config('filament-keycloak-admin.*')`, the consuming app owns
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

The three URLs follow [Keycloak's hostname nomenclature](https://www.keycloak.org/server/hostname), because a Keycloak
instance can be reachable under a different address per channel:

| Key                  | Keycloak option                  | Used for                                                                                                                           |
|----------------------|----------------------------------|------------------------------------------------------------------------------------------------------------------------------------|
| `backchannel_url`    | `--hostname-backchannel-dynamic` | This application's *own* calls: the Admin REST API and the service-account token endpoint. Frequently an internal/cluster address. |
| `frontend_url`       | `--hostname`                     | Anything the admin's **browser** is sent to.                                                                                       |
| `administration_url` | `--hostname-admin`               | The administration console's own base URL.                                                                                         |

Only `backchannel_url` is required; `administration_url` falls back to `frontend_url`, which falls back to
`backchannel_url`.

Only the backchannel URL is used today; the other two exist so a browser is never handed an internal address.

### Auth modes

- **`service_account`** — a `client_credentials` grant on a confidential client with the required
  `realm-management` roles: `view-users`, `manage-users`, `query-users`, `query-groups` (plus
  `view-events` for the event tabs). This is one shared identity for all actions.
- **`sso`** (act-as-user) — the Admin-API bearer is the **logged-in admin's own** Keycloak token, so Keycloak evaluates
  that person's fine-grained permissions and attributes admin events to them.
  `FilamentSsoTokenProvider` reads the token through `AdminKeycloakSession`. We recommend you to install
  `heloufir/filament-keycloak-sso` for the bundled `HeloufirAdminKeycloakSession` adapter.

## Logging

The plugin does not log anywhere by default — no channel, no fallback. It offers two independent,
opt-in extension points instead: bind a PSR-3 logger to get an audit trail of write actions, and/or bind
a Guzzle handler-stack customizer to get HTTP tracing. Neither is required; the plugin works exactly as
before if you bind nothing.

### Info audit logging

Every write action (enable/disable a user, edit identity fields, group add/remove, send a password-reset
email, remove a 2FA credential, log out all sessions) can log an audit line — `info` on success, `warning`
when Keycloak denies the write (401/403). To receive it, bind a PSR-3 logger instance under
`KeycloakAdminLogger` in your own service provider:

```php
use Psr\Log\LoggerInterface;
use Sandstorm\FilamentKeycloakAdmin\Logging\KeycloakAdminLogger;

$this->app->singleton(KeycloakAdminLogger::class, fn () => app(LoggerInterface::class));
// or a dedicated channel: fn () => Log::channel('keycloak-admin')
```

The bound instance does not need to implement `KeycloakAdminLogger` itself — any real PSR-3 logger works,
since the interface exists only as a collision-free container key (so binding it can't accidentally hijack
some other package's generic `Psr\Log\LoggerInterface` binding). Without a binding, write actions still
work, they just aren't logged.

Every call is a static message plus a context array — never a string-interpolated message, never PII
beyond ids:

```
[info] Keycloak admin write succeeded {"admin_id":42,"action":"group.add","target_user_id":"…","group_ids":["…"]}
[warning] Keycloak admin write denied {"admin_id":42,"action":"user.set_enabled","target_user_id":"…","enabled":false}
```

### HTTP outbound logging

The Guzzle client used for every Keycloak Admin API call (token requests included) carries no logging
middleware of its own and never will: token requests carry the client secret, and admin responses carry
user PII, so redaction is a decision only your app can make correctly for its own environment. To add
your own request/response logging, bind an implementation of `KeycloakAdminHttpHandlerStackCustomizer`:

```php
use GuzzleHttp\HandlerStack;
use Sandstorm\FilamentKeycloakAdmin\Http\KeycloakAdminHttpHandlerStackCustomizer;

class MyHandlerStackCustomizer implements KeycloakAdminHttpHandlerStackCustomizer
{
    public function customizeHandlerStack(HandlerStack $handlerStack): HandlerStack
    {
        $handlerStack->push($myLoggingMiddleware, 'http-logging');

        return $handlerStack;
    }
}

$this->app->bind(KeycloakAdminHttpHandlerStackCustomizer::class, MyHandlerStackCustomizer::class);
```

When bound, the plugin resolves it and passes the client's real handler stack through
`customizeHandlerStack()` once, while building the client — your middleware then runs on every request the
plugin makes. Keep your middleware from logging the `Authorization` header or any response/request body.

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
