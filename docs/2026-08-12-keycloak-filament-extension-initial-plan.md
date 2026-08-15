# Keycloak Filament Admin UI — Plan (v2)

**Date:** 2026-08-12 · **Keycloak target:** 26.5.3 **Package:** `broodfonds/keycloak-filament-admin` — **Filament v4** plugin, PHP ^8.2,
spatie/laravel-package-tools. **Builds on:** the existing Keycloak adapter at
`admin/DistributionPackages/keycloak-admin-adapter-layer` (namespace `Domain\Adapters\KeycloakAdmin`). We develop this
UI **alongside** that adapter — extending it, not replacing it.

---

## 0. Decisions taken (this iteration)

| Topic                    | Decision                                                                                                                                                                                                                                                                                                                                                  |
|--------------------------|-----------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------|
| Filament version         | **v4** (not v5).                                                                                                                                                                                                                                                                                                                                          |
| Adapter home             | **Move** the adapter → `shared/KeycloakAdmin`(, namespace **`Shared\KeycloakAdmin`** (both `api/` and `admin/` already autoload `Shared\` → `../shared`). Later extract to its own composer package.                                                                                                                                                                |
| Auth model               | **Explicitly configured, no auto-fallback.** `auth_mode` picks one: `sso` (act-as-user — reuse the logged-in admin's Keycloak SSO token as the Admin-API bearer → real per-person attribution) or `service_account` (`client_credentials`, current adapter). The mode is a deployment decision; the transport never silently switches modes — a misconfigured/underprivileged token fails loudly. |
| Username edit            | Gated on realm `editUsernameAllowed` — fetch realm config, render field read-only when false.                                                                                                                                                                                                                                                             |
| Last login               | Show **multiple** recent logins from events + **current active sessions**.                                                                                                                                                                                                                                                                                |
| Admin history            | Show admin-events targeting the user (who changed what).                                                                                                                                                                                                                                                                                                  |
| Extra write ops in scope | Enable/disable user · set password directly · reset/remove 2FA credential · logout all sessions.                                                                                                                                                                                                                                                          |

---

## 1. Goal

A Filament v4 admin panel to manage Keycloak users via the Keycloak Admin REST API. Keycloak is the source of truth — no
local user mirror table. Filament resources read/write through the shared adapter.

### In-scope features

1. **List & find users** — searchable, server-side-paginated table; filter by enabled and by group.
2. **Assign groups** — add/remove user ↔ realm groups. *Only when the caller has the group-management permission* (see
   §6).
3. **Set username** — editable **only if** realm `editUsernameAllowed = true`; otherwise shown read-only.
4. **Trigger password reset** — `execute-actions-email` with `UPDATE_PASSWORD`.
5. **See last logins** — a **list** of recent LOGIN events + current active sessions.
6. **See configured 2FA** — credential types (OTP, WebAuthn, …) + pending `CONFIGURE_TOTP` required action.

### Extra write ops (this iteration)

7. Enable/disable user. 8. Set password directly (no email). 9. Reset/remove a 2FA credential. 10. Logout all sessions.

### Nice-to-have / read

11. **Admin history** — admin-events scoped to the user.

---

## 2. Keycloak API background

### 2.1 Authentication — explicitly configured (no auto-fallback)

One token provider, chosen by **explicit configuration** (`auth_mode`). The transport asks the configured
provider for a bearer per request. **There is no automatic fallback** between modes — if the configured mode
can't produce a usable token, the request fails loudly (surfaced as an error), it does not silently switch.

- **`sso` — act-as-user.** The admin logs into the Filament panel via Keycloak OIDC (`heloufir/keycloak-sso`). Its
  access token is stored in the session; we use it as the Admin-API bearer → real per-person attribution in
  admin-events. **Requirements:** the admin's token must carry `realm-management` roles (`view-users`, `manage-users`,
  `query-users`, `query-groups`) and an **audience** including `realm-management` — usually needs an audience/role
  **mapper** on the SSO client. Refresh via the stored `refresh_token` when near expiry. If the token lacks the roles,
  the call fails with a clear error (missing admin roles) — it does **not** fall back to the service account.
- **`service_account`.** The existing `client_credentials` grant on a confidential client with the same
  `realm-management` roles. Token cached in-memory (already implemented). Used for headless contexts (CLI/jobs) and
  for panels deliberately configured to act as a single shared identity.

Which mode is active is a deployment decision set in config — not resolved per request. Token endpoint unchanged:
`POST {baseUrl}/realms/{realm}/protocol/openid-connect/token`.

### 2.2 Endpoints used

Base admin path: `{baseUrl}/admin/realms/{realm}`

| Feature                      | Method & path                                                                    | Notes                                                                                                                              |
|------------------------------|----------------------------------------------------------------------------------|------------------------------------------------------------------------------------------------------------------------------------|
| Realm config (username-edit) | `GET /admin/realms/{realm}`                                                      | Read `editUsernameAllowed`, `eventsEnabled`, `adminEventsEnabled`. Cache. These are *hints*, not authorization (see §2.3, §6).     |
| User Profile metadata        | `GET /admin/realms/{realm}/users/profile`                                        | Managed-attribute config: which attributes exist, required, editable, validators. Drives proactive form rendering (§5.5).          |
| List/search users            | `GET /users?search=&first=&max=&enabled=&q=&briefRepresentation=true`            | `search` = infix over username/email/first/last. `q=key:value` for custom-attribute search.                                        |
| User count                   | `GET /users/count?search=&enabled=`                                              | Table total / pagination.                                                                                                          |
| Users **in a group**         | `GET /groups/{groupId}/members?first=&max=`                                      | The only way to filter by group. **No** free-text param here (can't combine text+group in one call — see §3.3).                    |
| Single user                  | `GET /users/{id}`                                                                | Full representation.                                                                                                               |
| Update user                  | `PUT /users/{id}`                                                                | Read-modify-write full `UserRepresentation`. Covers username (if allowed), names, email, `enabled`, `emailVerified`.               |
| List realm groups            | `GET /groups?search=&briefRepresentation=true`                                   | Group picker. Hierarchical.                                                                                                        |
| User's groups                | `GET /users/{id}/groups`                                                         | Current memberships.                                                                                                               |
| Add to group                 | `PUT /users/{id}/groups/{groupId}`                                               | Empty-body PUT. Idempotent.                                                                                                        |
| Remove from group            | `DELETE /users/{id}/groups/{groupId}`                                            | Needs a DELETE verb (adapter gap — §4).                                                                                            |
| Trigger PW reset (email)     | `PUT /users/{id}/execute-actions-email?lifespan=…` body `["UPDATE_PASSWORD"]`    | **JSON array body** (adapter gap — §4). Requires realm SMTP. Optional `client_id`+`redirect_uri`.                                  |
| Set password directly        | `PUT /users/{id}/reset-password` body `{type:"password",value:…,temporary:bool}` | Admin sets password, no email. (Existing `setUserCredentials` uses the full-rep path; this is the dedicated endpoint — prefer it.) |
| 2FA / credentials            | `GET /users/{id}/credentials`                                                    | `type` ∈ `password`,`otp`,`webauthn`,`webauthn-passwordless`,`recovery-authn-codes`. `otp`/`webauthn*` present = 2FA on.           |
| Remove a credential          | `DELETE /users/{id}/credentials/{credId}`                                        | Reset/remove 2FA. DELETE verb.                                                                                                     |
| Enable/disable               | `PUT /users/{id}` with `enabled` toggled                                         | Part of update.                                                                                                                    |
| Active sessions              | `GET /users/{id}/sessions`                                                       | `start`, `lastAccess` (epoch ms), `ipAddress`, per-client.                                                                         |
| Logout all sessions          | `POST /users/{id}/logout`                                                        | Force sign-out everywhere.                                                                                                         |
| Recent logins (multiple)     | `GET /events?type=LOGIN&user={id}&max=N`                                         | **List** — timestamp, ipAddress, clientId. Needs realm login-events enabled.                                                       |
| Admin history for user       | `GET /admin-events?resourcePath=users/{id}&max=N`                                | Admin actions ON this user. Needs realm admin-events enabled. `resourcePath` is prefix-matched.                                    |
| Required actions             | on `UserRepresentation.requiredActions`                                          | `CONFIGURE_TOTP` pending = 2FA required, not yet set.                                                                              |

### 2.3 Login activity — "Recent login events" + "Active sessions" (not "last login")

Keycloak stores no authoritative "last login" field. Presenting either source as *the* last login **misleads** — event
retention may not cover the window we care about, and sessions vanish on logout. So label the two panels honestly:
**"Recent login events"** and **"Active sessions"** — never a single "Last login" value.

- **Active sessions** (`/users/{id}/sessions`) — only *live* sessions; empty after logout/expiry. No realm config
  needed.
- **Recent login events** (`/events?type=LOGIN&user={id}&max=N`) — history even after logout; depends on login-event
  storage + **retention window** (Realm Settings → Events → Save login events).

**Three-state model — never conflate `[]` with "disabled".** For each source distinguish:

1. **Available + results** → render the list.
2. **Available + no results** → "no logins recorded in the retention window" (the user may simply not have logged in
   recently — *not* an error).
3. **Unavailable / forbidden** → the call 403'd, or the feature/listener is off → "login history unavailable
   (events not enabled or not permitted)".

`eventsEnabled`/`adminEventsEnabled` from realm config are only *hints* — availability can still fail because the
**caller lacks permission**, retention excludes results, or event listeners differ. So decide the state from the actual
call outcome (2xx-with-rows vs 2xx-empty vs 403/error), using the realm flags only to pick a clearer message. Same
three-state treatment for admin-event history (§5.2).

---

## 3. Architecture

### 3.1 Two components

**A. Shared adapter — `shared/KeycloakAdmin` (namespace `Shared\KeycloakAdmin`).**
Move the current adapter here and extend it. **Laravel-independent** — depends only on PSR interfaces (PSR-3
logger; Guzzle/PSR-18 client) and plain PHP. **No** Laravel session/config/container, **no** `heloufir/keycloak-sso`,
no framework globals. It defines the token-provider *interface* and the *service-account* implementation only — any
provider that needs a web session lives in the consuming app/plugin (component B). Consumed by `api/`, `admin/`, and
this plugin. Reusable outside Laravel by design.

**One port, segmented by capability (interface segregation).** In ports-and-adapters terms there is exactly **one
port** here — the outbound boundary to Keycloak, `KeycloakAdminApiClient` (the existing code already documents this
interface as "the port"). We are **not** introducing five ports. Instead we apply **interface segregation**: split the
one fat contract into small, cohesive **interfaces** (`KeycloakUsersApi`, `KeycloakGroupsApi`, …) so callers depend only
on the slice they use. The `KeycloakAdminApiClient` facade exposes them via accessors (`users()`, `groups()`, …). The
concrete implementations + `KeycloakTransport` are the **adapter** side. (These are *interfaces*, not traits — traits
are impl-reuse, not the contract that defines the seam; a trait may later share code *between* implementations, but the
boundary is always an interface.)

```
shared/KeycloakAdmin/
  KeycloakAdminApiClient.php              # THE port (facade): accessors users(), groups(), credentials(),
                                          #   sessions(), events(), realm() → each returns a segregated interface
  Contracts/                               # interface-segregation slices of the one port (NOT separate ports)
    KeycloakUsersApi.php                  # list, count, getById, update, findByUsername, create,
                                          #   updateAttributes, setEnabled
    KeycloakGroupsApi.php                 # listRealmGroups, getUserGroups, addUserToGroup,
                                          #   removeUserFromGroup, getGroupMembers
    KeycloakCredentialsApi.php            # get, delete, resetPassword, executeActionsEmail
    KeycloakSessionsApi.php               # getSessions, logoutAll
    KeycloakEventsApi.php                 # getLoginEvents, getAdminEventsForUser
    KeycloakRealmApi.php                  # getConfig (editUsernameAllowed, events flags), getUserProfile, ping
  Impl/                                    # adapter side — one implementation per contract, each using KeycloakTransport
  KeycloakTransport.php                   # + DELETE verb, + array-body PUT, + pluggable token provider
  Auth/
    KeycloakTokenProvider.php             # interface: currentBearer(): string  ← the only auth contract shared exposes
    ServiceAccountTokenProvider.php       # client_credentials, self-contained (moved out of transport).
                                          #   NO session-based provider here — that would couple shared to a framework.
  Dto/
    KeycloakUser.php                      # EXTEND: email, enabled, emailVerified, firstName,
                                          #   lastName, requiredActions, createdTimestamp
    KeycloakUserId.php  KeycloakSettings.php  KeycloakAccessToken.php  CreateKeycloakUserCommand.php
    KeycloakGroup.php  KeycloakCredential.php  KeycloakSession.php
    KeycloakLoginEvent.php  KeycloakAdminEvent.php  KeycloakRealmConfig.php  KeycloakUserProfileMetadata.php
```

- The **existing** methods (`ping`, `findUserByUsername`, `createUser`, `updateUserAttributes`, `setUserCredentials`)
  move onto the relevant interface — `ping`→`KeycloakRealmApi`, the user ones→`KeycloakUsersApi`, `setUserCredentials`→
  `KeycloakCredentialsApi` — keeping their current signatures so `api/`'s import path keeps working.
- Multi-call mutations (group add/remove diff) live in the plugin action, not a contract method — each interface stays
  single-op so partial failure is visible to the caller (§5.4.2).
- **Impl shape (open, minor):** one class implementing all slices (simple, fat) vs one focused collaborator per slice
  composed by the facade (cleaner SRP, more wiring). Lean to focused collaborators; not load-bearing for the plan.

**B. Filament v4 plugin — `broodfonds/keycloak-filament-admin` (this package).**
UI only. Depends on the shared adapter. No HTTP of its own.

```
src/
  Filament/
    Resources/
      KeycloakUsers/
        KeycloakUserResource.php
        Pages/
          ListKeycloakUsers.php
          ViewKeycloakUser.php            # infolist: identity, 2FA, sessions, login history, admin history
          EditKeycloakUser.php            # identity form (username gated), enable/disable
        Actions/
          TriggerPasswordResetAction.php  # execute-actions-email
          SetPasswordAction.php           # reset-password (direct)
          ManageGroupsAction.php          # add/remove diff (permission-gated)
          RemoveCredentialAction.php      # delete OTP/WebAuthn
          LogoutAllSessionsAction.php
  Auth/
    FilamentSsoTokenProvider.php          # implements Shared\KeycloakAdmin\Auth\KeycloakTokenProvider;
                                          #   reads/refreshes the logged-in admin's KC token from the Laravel
                                          #   session (heloufir/keycloak-sso). Laravel-coupled — lives HERE, not shared.
    TokenProviderBinding.php              # binds KeycloakTokenProvider from config auth_mode (sso|service_account)
  Data/
    UserRecords.php                       # records() callback: maps injected page/search/filters
                                          #   → adapter call → LengthAwarePaginator of KeycloakUser DTOs
  Policies/KeycloakUserPolicy.php
KeycloakFilamentAdminPlugin.php           # registers the resource on the panel
```

### 3.2 Data source — non-Eloquent Filament table (`records()`)

Filament v4 resources normally bind Eloquent. Keycloak has no local table. Filament v4 has a first-class hook for
this: **[custom-data tables](https://filamentphp.com/docs/4.x/tables/custom-data)** via the `records()` closure — use
it, don't invent our own resolver.

- **Mechanism.** The table's `records()` closure returns a `LengthAwarePaginator` of `KeycloakUser` DTOs. Filament
  injects the state we forward straight to the adapter:
  - `$page`, `$recordsPerPage` → adapter `first`/`max` (`first = ($page-1) * $recordsPerPage`); paginator total from
    `GET /users/count` (or `/groups/{id}/members` length).
  - `$search` → adapter `search` param (global search). Docs are explicit: **let the data source search** — never
    filter client-side.
  - `$sortColumn` / `$sortDirection` → note KC's `GET /users` has **no sort param**; only a fixed username order. So
    sorting is limited — expose sortable columns only where KC supports it, otherwise disable column sort (don't
    sort a single page and pretend it's global).
  - `$filters` → enabled toggle → `enabled=`; group filter → switch data source to `getGroupMembers()` (§3.3).
- **DTO records.** The "record" is a `KeycloakUser` DTO keyed by user id (`getKey()` = id). Row/bulk actions call the
  adapter directly; after a write, call `$this->resetTable()` so the row reflects the change.
- **Bulk selection across pages:** implement `resolveSelectedRecordsUsing($keys)` (re-fetch by id) if we add bulk
  actions — otherwise selection only covers the loaded page.
- **Guardrails are ours.** The docs state Filament provides the plumbing, not auth/validation/error-handling/rate-
  limiting — those live in the adapter + policy (§6), not the table.
- Rejected: mirroring users into a local read-model table (infra + staleness). Revisit only if list-side filtering/perf
  demands it.

### 3.3 Filtering / search — what KC allows

- Free-text: `search` (infix username/email/name), or field-specific `username/email/firstName/lastName`, `exact`,
  `emailVerified`, `enabled`.
- Custom attribute: `q=key:value`.
- **By group:** switch the data source to `GET /groups/{id}/members` (paginated). **Limitation:** that endpoint takes no
  text-search param, so "text search *within* a group" isn't a single call. v1: group filter and free-text search are
  **mutually exclusive** in the UI (pick group → list members; type text → global search). Note this cap in the UI;
  don't fake it.

### 3.4 Config — settings are injected into the adapter, not owned by it

The shared adapter must be **configurable by whoever uses it** — it does **not** own a source of settings, and must
**not** depend on the app-specific `LazySettings` (that couples `shared/` to the main app; see §4). Instead:

- The adapter depends on a tiny, framework-agnostic seam — `KeycloakSettingsProvider { get(): KeycloakSettings }` (or
  simply accept a `KeycloakSettings` value object). It knows nothing about *where* the values came from.
- **From `api/` (current usage):** wrap the existing `LazySettings<KeycloakSettings>` in a provider impl — no behaviour
  change for the import path.
- **From the Filament plugin:** provide an impl that reads **normal Laravel/Filament config** (`config(...)`/env),
  bound in the plugin's service provider. The plugin ships its own config file for connection + UI knobs.

`config/keycloak-filament-admin.php` (plugin):

```php
return [
    // Connection — read by the plugin's KeycloakSettingsProvider impl and handed to the shared adapter.
    'connection' => [
        'base_url'      => env('KEYCLOAK_BASE_URL'),
        'realm'         => env('KEYCLOAK_REALM'),
        'client_id'     => env('KEYCLOAK_ADMIN_CLIENT_ID'),      // service-account client (service_account mode)
        'client_secret' => env('KEYCLOAK_ADMIN_CLIENT_SECRET'),
    ],
    'auth_mode' => env('KEYCLOAK_ADMIN_AUTH_MODE', 'sso'), // 'sso' | 'service_account' — explicit, no fallback
    'events' => [
        'login_history_max' => 20,
        'admin_history_max' => 20,
    ],
    'pw_reset' => [
        'lifespan'     => env('KEYCLOAK_PW_RESET_LIFESPAN', 43200),
        'redirect_uri' => env('KEYCLOAK_PW_RESET_REDIRECT_URI', null),
        'client_id'    => env('KEYCLOAK_PW_RESET_CLIENT_ID', null),
    ],
    'set_password' => [
        'enabled'                  => env('KEYCLOAK_ADMIN_SET_PASSWORD_ENABLED', false),
        'allow_permanent_password' => false, // §5.4.1 — temporary=true enforced unless deliberately turned on
    ],
];
```

So the same shared adapter serves `api/` (settings from `LazySettings`) and the plugin (settings from Filament config)
without the adapter knowing the difference.

---

## 4. Required changes to the existing adapter

These are **blocking** — the current transport can't do them yet:

1. **DELETE verb** — `KeycloakTransport` has `get/getJson/postJson/putJson` only. Add `delete(path)` /
   `deleteJson(path)`. Needed for: remove-from-group, delete-credential.
2. **JSON-array body** — `putJson()`/`postJson()` cast body to `(object)`. `execute-actions-email` needs a **JSON
   array** (`["UPDATE_PASSWORD"]`), which `(object)` corrupts into `{"0":"…"}`. Add an array-preserving send (e.g.
   `putRaw(path, array $jsonList)` / accept list bodies without object-casting).
3. **Pluggable token provider** — extract `getToken()` from `KeycloakTransport` into a `KeycloakTokenProvider`
   (service-account impl = current logic). Transport takes the provider by constructor; which impl is bound is decided
   once from `auth_mode` — no runtime selection/fallback in the transport.
4. **Namespace move** — `Domain\Adapters\KeycloakAdmin` → `Shared\KeycloakAdmin`; update `api/` usages + PSR-4. `admin/`
   already autoloads `Shared\`.
5. **Decouple settings from `LazySettings`** — the transport currently takes `LazySettings<KeycloakSettings>` (an
   app type). Replace with a framework-agnostic `KeycloakSettingsProvider` (or plain `KeycloakSettings`) seam so the
   adapter has no app dependency. `api/` supplies a `LazySettings`-backed impl; the plugin supplies a Filament-config
   impl (§3.4).
6. **Decouple logging factory** — `KeycloakTransport` builds its Guzzle client via the app's
   `LoggingGuzzleClientFactory`/`BroodfondsApiLogging`. For `shared/` to be Laravel/app-independent, the client (or a
   PSR-18 client + PSR-3 logger) must be **injected**, not constructed from app classes. Keep the PII-safe "info-only"
   behaviour, but supplied by the consumer.

Everything else (token cache, read-modify-write PUT, PII-safe logging behaviour) is reused as-is.

### 4.1 Detailed adapter-change plan (step by step)

Ordered, each step independently compilable + testable. **Each step ships its own tests** (the Tier-1/Tier-3 ones from
§7 that apply) — not a test phase at the end. Keep every existing public signature working until the final sweep so
`api/`'s import path never breaks mid-refactor.

**Current state.** `admin/DistributionPackages/keycloak-admin-adapter-layer/`, namespace `Domain\Adapters\KeycloakAdmin`
(mapped via `api/` `Domain\` → `api/src`). Files: `KeycloakAdminApiClient` (interface, 5 methods),
`KeycloakAdminApiClientImplementation`, `KeycloakTransport` (Guzzle, token, `get/getJson/postJson/putJson`), DTOs
(`KeycloakUser`, `KeycloakUserId`, `KeycloakSettings`, `KeycloakAccessToken`, `CreateKeycloakUserCommand`). App
couplings: `LazySettings<KeycloakSettings>`, `LoggingGuzzleClientFactory`, `BroodfondsApiLogging`, PSR-3 logger.

**Step 1 — Move + namespace (mechanical, no behaviour change).**
- `git mv` the folder → `shared/KeycloakAdmin/`. Rename namespace `Domain\Adapters\KeycloakAdmin` → `Shared\KeycloakAdmin`
  in every file.
- Update `api/` call sites + any DI bindings/imports. `admin/` already autoloads `Shared\` → `../shared`.
- Run `api/` tests → green before continuing. Commit.

**Step 2 — Settings seam (kills `LazySettings` coupling).**
- Add `Shared\KeycloakAdmin\KeycloakSettingsProvider` interface: `public function get(): KeycloakSettings;`
- Change `KeycloakTransport` ctor: `LazySettings<KeycloakSettings>` → `KeycloakSettingsProvider`. Body already calls
  `->get()`, so change is type-only.
- In `api/`: add a `LazySettingsKeycloakSettingsProvider` adapter wrapping the existing `LazySettings`; bind it.
- In the plugin (later, Step 9): a config-backed provider.

**Step 3 — Inject HTTP client + logger (kills `LoggingGuzzleClientFactory`/`BroodfondsApiLogging` coupling).**
- `KeycloakTransport` ctor takes the ready Guzzle/PSR-18 `Client` (and PSR-3 `LoggerInterface`) as parameters instead
  of building via app factory.
- `api/` binding constructs the client with `LoggingGuzzleClientFactory::createLoggingHttpClientOnlyInfo(...)` and
  passes it in — PII-safe behaviour preserved, but the choice lives in the consumer, not `shared/`.
- After Steps 2–3, `shared/KeycloakAdmin` imports nothing from `Domain\`/Laravel. Add an arch test asserting that.

**Step 4 — Extract token provider.**
- Add `Shared\KeycloakAdmin\Auth\KeycloakTokenProvider` interface: `public function currentBearer(): string;`
- Move `KeycloakTransport::getToken()` + `$cachedToken` logic into `ServiceAccountTokenProvider` implementing it
  (constructor takes settings provider + HTTP client).
- `KeycloakTransport::send()` calls `$this->tokenProvider->currentBearer()`. Ctor now takes the provider.
- Tests: token cache hit/expiry/refresh move with the provider.

**Step 5 — Transport verbs.**
- Add `delete(string $path): ResponseInterface` (+ `deleteJson` if a decoded body is ever needed) — mirrors `get`.
- Add an **array-body** send that does *not* `(object)`-cast: e.g. `putList(string $path, array $jsonList)` /
  `postList(...)` using `RequestOptions::JSON => array_values($jsonList)`. Needed for `execute-actions-email`.
- Unit-test the emitted JSON is `["UPDATE_PASSWORD"]`, not `{"0":"UPDATE_PASSWORD"}`.

**Step 6 — Segregated interfaces + facade.**
- Add `Contracts/Keycloak{Users,Groups,Credentials,Sessions,Events,Realm}Api` interfaces (signatures in §3.1).
- Relocate the 5 existing methods onto the right interface, **unchanged signatures**: `findUserByUsername`,
  `createUser`, `updateUserAttributes` → `KeycloakUsersApi`; `setUserCredentials` → `KeycloakCredentialsApi`; `ping` →
  `KeycloakRealmApi`.
- `KeycloakAdminApiClient` facade exposes `users()/groups()/credentials()/sessions()/events()/realm()`.
- Impl: focused collaborator per interface (each holds `KeycloakTransport`), composed by the facade (§3.1 impl-shape
  note). Keep a thin back-compat shim if any `api/` code calls the old flat client directly.

**Step 7 — DTOs.**
- Extend `KeycloakUser`: add `email`, `enabled`, `emailVerified`, `firstName`, `lastName`, `requiredActions`,
  `createdTimestamp` (all nullable/defaulted; `fromRawResponse` stays tolerant). **Don't break** the existing
  `attributes`/`firstAttributeValue` API used by the import.
- Add `KeycloakGroup`, `KeycloakCredential` (id, type, userLabel, createdDate), `KeycloakSession` (start, lastAccess,
  ipAddress, clients), `KeycloakLoginEvent`, `KeycloakAdminEvent`, `KeycloakRealmConfig` (editUsernameAllowed,
  eventsEnabled, adminEventsEnabled), `KeycloakUserProfileMetadata`. Each with a `fromRawResponse()` and tolerant
  parsing like the existing DTO.

**Step 8 — New API methods** (per interface, each `@throws RuntimeException`, endpoint in §2.2):
- `KeycloakUsersApi`: `list(search, first, max, enabled, q): list<KeycloakUser>`, `count(search, enabled): int`,
  `getById(KeycloakUserId): KeycloakUser`, `update(KeycloakUser|rep): void` (read-modify-write), `setEnabled(id, bool)`.
- `KeycloakGroupsApi`: `listRealmGroups(search)`, `getUserGroups(id)`, `addUserToGroup(id, groupId)`,
  `removeUserFromGroup(id, groupId)`, `getGroupMembers(groupId, first, max)`.
- `KeycloakCredentialsApi`: `get(id)`, `delete(id, credentialId)`, `resetPassword(id, value, temporary)`,
  `executeActionsEmail(id, list<string> actions, ?lifespan, ?clientId, ?redirectUri)` (uses the array-body send).
- `KeycloakSessionsApi`: `getSessions(id)`, `logoutAll(id)`.
- `KeycloakEventsApi`: `getLoginEvents(id, max)`, `getAdminEventsForUser(id, max)`.
- `KeycloakRealmApi`: `getConfig()`, `getUserProfile()`, `ping()`.

**Step 9 — Consumers + tests.**
- `api/`: bindings updated (Steps 2–4). Import path exercised by its existing tests → green.
- Plugin: config-backed `KeycloakSettingsProvider`, HTTP client + logger, token-provider binding from `auth_mode`
  (Phase 3).
- Adapter tests: `Http::fake`/Guzzle-mock per new method with recorded 26.5.3 JSON fixtures (pagination params, DELETE,
  array-body payload, RMW update, three-state event parsing).

**Back-compat guarantee.** Steps 1–5 are pure refactor/extension — no existing signature changes. Steps 6–8 only *add*;
the 5 original methods keep identical signatures on their new interfaces. Only DI wiring in `api/` changes, covered by
its tests.

---

## 5. UI design

### 5.1 User list

Columns: username · email · name · enabled (badge) · email-verified (icon) · 2FA (icon: has otp/webauthn) · groups
(count — lazy/detail-only to avoid N+1).

- Global search → `search`. Group filter → group-members data source (§3.3, mutually exclusive with text).
- Enabled/disabled filter.
- Row actions: View, Edit, Trigger PW reset, Manage groups (if permitted).

**Sorting — constrained by the API.** Keycloak's `GET /users` has **no `orderBy`/direction param**; it returns a fixed
order (by username). So:
- **Username** may be marked sortable *ascending only* (matches KC's native order); wiring it just controls the label,
  results are already username-ordered.
- **Email / name / other columns are NOT server-sortable** — mark them explicitly non-sortable. Do **not** sort the
  current page locally and present it as a global sort (that lies across page boundaries).
- **Pagination determinism:** `first`/`max` over username order is stable enough for browsing, but concurrent
  create/delete can shift rows between pages — acceptable for an admin browse tool; note it, don't pretend snapshot
  isolation.
- Revisit if a future need justifies the local read-model table (§3.2), which *could* offer full sorting.

### 5.2 User detail (View page — infolist)

- **Identity** — username (read-only if `editUsernameAllowed=false`), email, names, enabled, email-verified. Field
  editability/requiredness driven by User Profile metadata (§5.5).
- **Groups** — current memberships; Manage-groups action (permission-gated).
- **Security / 2FA** — credential list (type + `userLabel` + createdDate), `CONFIGURE_TOTP` pending badge;
  Remove-credential (whitelisted types only) + Set-password-directly actions (§5.4).
- **Active sessions** — start, lastAccess, ip, client; Logout-all action.
- **Recent login events** — last N LOGIN events. **Three-state** (§2.3): results / "no logins in retention window" /
  "unavailable or forbidden". Never labelled "last login".
- **Admin history** — last N admin-events targeting this user. Same three-state treatment.

### 5.3 Edit page

Identity form (username gated), enable/disable toggle → `PUT /users/{id}` (read-modify-write). Group management via
action/modal (diff → PUT/DELETE per group).

### 5.4 Actions

- **Trigger PW reset (email)** — confirm modal → `executeActionsEmail(['UPDATE_PASSWORD'])`; surface
  SMTP-not-configured clearly. **Preferred** over setting a password directly.
- **Set password directly** — see §5.4.1. Off by default; behind config.
- **Manage groups** — multi-select modal, compute add/remove diff. See §5.4.2 (partial failure).
- **Remove credential** — see §5.4.3 (whitelist).
- **Logout all sessions** — confirm → `sessions()->logoutAll()`.

#### 5.4.1 Set password directly — security

Admin-set passwords are a sharp tool; the email-reset flow (#4) should be the default path. When this action *is*
enabled:

- **`temporary = true` is enforced** (user must change at next login). Permanent passwords are **not offered** unless a
  concrete, configured use case turns them on (`allow_permanent_password` config, default false).
- **Confirmation input** — value + confirm fields must match before submit.
- **Never echoed back:** on validation failure the field is **cleared, never re-rendered** with the value; the field is
  `revealable`/`password` type, `autocomplete=new-password`, excluded from any form-state dehydration that could land in
  a Livewire payload.
- **Never logged:** value excluded from logs, exception reports, and request/Livewire debug payloads (adapter already
  refuses to log credentials).
- **Step-up:** require a second confirmation; **re-authentication before submit is an open question** (§10) — recommend
  yes if the panel session is long-lived.
- Respect realm **password policy** — surface Keycloak's rejection (too short, history, etc.) rather than pre-guessing.

#### 5.4.2 Multi-call mutations — explicit partial failure

Group add/remove is N sequential calls (no bulk endpoint). Model **partial failure explicitly**: apply each diff item,
collect per-item success/failure, and report "3 of 4 applied, 1 failed (…)". Do **not** assume all-or-nothing or roll
back silently. After completion, re-read memberships so the UI reflects real server state (§5.4 / read-modify-write).

#### 5.4.3 Credential removal — whitelist, not "reset 2FA"

The credentials list mixes `password`, `otp`, `webauthn`, `webauthn-passwordless`, `recovery-authn-codes`. Removal is
per-credential and **whitelisted**:

- **`password` is never removable** here (removing it isn't a 2FA reset and can lock the account/change login flow).
- Removal targets a **single credential by id**, showing its `userLabel` (a user may have e.g. two WebAuthn keys —
  don't blind-remove "the OTP"). "Reset 2FA" as a bulk concept is avoided; the admin removes specific factors.
- **Recovery codes** removable only if we deliberately decide to (open question §10).
- **Removing the last second factor** may leave the account single-factor. If the realm mandates MFA, decide whether to
  also add a `CONFIGURE_TOTP` (or equivalent) required action so the user re-enrols on next login — **open question
  §10**; depends on realm MFA policy. Surface a warning when removing the last MFA credential either way.

### 5.5 User Profile — attribute editability is realm-defined, not assumed

`editUsernameAllowed` is only one constraint. Modern Keycloak's **User Profile** system governs each attribute:
whether it exists (managed attributes), is required, is editable by admins, and its validators. First/last name and
email are **not guaranteed editable** — a realm may make them read-only, required, or subject to validators; some users
are **LDAP/federated** with read-only attributes; email may be required and duplicate-email may or may not be allowed.

**v1 stance (documented explicitly):**

- **Rely on Keycloak server-side validation** as the source of truth — attempt the write, surface KC's field errors
  verbatim (mapped to the right form field where possible). We do **not** re-implement realm validators.
- **Read User Profile metadata** (`GET /admin/realms/{realm}/users/profile`) to render the form *proactively*: show
  configured managed attributes, mark required ones, and disable admin-non-editable / federated read-only fields — so
  the admin sees constraints before submitting rather than only on rejection.
- Custom user-profile attributes the realm defines are surfaced (read + edit where editable); the plan does not
  hard-code a fixed name/email/enabled shape.
- Federated (LDAP) users: treat their read-only attributes as disabled; don't offer edits KC will reject.

---

## 6. Security & authorization

### 6.1 Keycloak is the authorization authority — don't reimplement it client-side

**Do not gate operations on `token->hasRole('manage-users')`.** Keycloak's admin authorization is richer than a flat
set of `realm-management` roles, and with **Fine-Grained Admin Permissions (FGAP)** it is **resource-dependent** — an
admin may manage only *some* users/groups, so "can I edit this user?" varies per target. Replicating that in a Laravel
policy would be wrong and would drift from Keycloak.

**Target Keycloak version: 26.5.3** (confirmed). FGAP facts (from KC release notes): **26.2.0** — FGAP (V2)
*supported*; 26.6.0 added group scope `manage-membership-of-members`; 26.7.0 added organizations as an FGAP resource
type. So on **26.5.3**: FGAP V2 is **available/supported**, but the 26.6 group scope and 26.7 org resource type are
**not** — design to the 26.5 feature set. V2 models permissions as authorization resources/scopes (Users, Groups, …)
rather than broad realm-management roles, evaluated per resource.

**Confirmed: FGAP is OFF on the `Broodfonds` realm.** Realm Settings → **Admin Permissions = Off** is exactly the FGAP
V2 toggle; Off means authorization is **classic role-based** (realm-management roles), *not* per-resource. So **today**:
no per-admin user subsets unless admins are granted different realm-management roles; the caller either has a role or
gets 403. **The design does not change** — we still authorize by attempt + graceful 403 and keep the Laravel policy
coarse, because (a) role-based authz *also* 403s on missing roles, and (b) this stays correct if Admin Permissions is
switched on later. We just don't need to build FGAP-specific per-target UI now.

**Design that works under both role-based and FGAP authz:**

- **In `sso` mode, Keycloak enforces per-admin scope for free.** The admin's token is the caller, so `GET /users`
  returns only what they may see, and writes they may not perform return **403**. Different admins naturally see
  different subsets. We must **not** widen this by second-guessing it.
- **Authorize by attempt + graceful 403, not by pre-computed role checks.** Treat `403` as "not permitted for this
  target": disable/hide the action, show a clear "you don't have permission" notice — never a stack trace. Where the UI
  needs to pre-disable a control (not just fail on click), prefer a capability probe over role inspection.
- **The Laravel policy stays coarse:** `KeycloakUserPolicy` only decides *"may this panel user open the Keycloak
  resource at all"* (panel-level gate). It does **not** encode per-user/per-group edit rights — Keycloak owns those.
- **`service_account` mode is the coarse exception:** one shared identity with fixed roles, no per-admin scoping. If the
  deployment needs per-admin least-privilege or per-target visibility, it must run `sso` mode (and ideally FGAP). Call
  this out in config docs.
- **Group-management action** visibility follows the same rule: attempt-based (`sso`) or a config flag
  (`service_account`); feature #2 stays conditional.

**No usable SSO identity → disabled UI, never an exception.** In `sso` mode, if no logged-in Keycloak user / no usable
token is present when a view activates, the page must **render with its components disabled** (empty/greyed table,
disabled actions, a clear "not authenticated to Keycloak — sign in / your session expired" banner) — it must **not**
throw an unhandled exception into the UI. Resolve the token at view/mount time, catch the missing-identity case, and
degrade to a read-disabled state. (This is distinct from a *401 mid-request*, which triggers one refresh attempt within
the mode, then the same graceful disabled/notice state — still no crash, still no fallback.)

### 6.2 Other

- **Act-as-user auditing:** with SSO tokens, admin-events attribute changes to the real person — a reason to prefer
  `sso`.
- **Secrets:** service-account `client_secret` in env only; never logged (adapter uses PII-safe "info-only" logging).
- **Realm/User-Profile respect:** username read-only when `editUsernameAllowed=false`; other field editability/required
  rules come from User Profile metadata (§5.5). v1 also relies on Keycloak server-side validation and surfaces its
  errors.
- **Resilience:** HTTP timeouts; retry once on 401 by refreshing the token **within the configured mode** (`sso`:
  refresh via `refresh_token`; `service_account`: re-fetch via `client_credentials`). **No cross-mode fallback** — a
  persistent auth failure surfaces per the taxonomy below.

### 6.3 Failure taxonomy — friendly-vs-propagate × log-vs-no-log

Every Keycloak-side failure falls into exactly one row. The axis that matters: does the UI **degrade to a friendly notice**
(and continue) or does the exception **propagate** (crash → error page)? **Everything is logged** either way — the auth
case via an explicit `report()`, the rest by the framework's uncaught handler. Only **one** exception type is behaviourally
special (the caught one); the rest are plain SPL exceptions because a dedicated class buys nothing for an uncaught path.

| Case | UI | Logged | Exception (thrown where) |
|------|----|--------|--------------------------|
| Admin not signed in via Keycloak (`sso` mode, no usable identity) | **friendly notice**, empty page | yes (`report()`) | `KeycloakAuthenticationException` (SSO token provider — later slice) |
| Admin lacks the permission in Keycloak (HTTP **403**) | **friendly notice**, empty page | yes (`report()`) | `KeycloakAuthenticationException` (transport, on 401/403) |
| Keycloak unreachable / timeout / **5xx** | **propagates** (error page) | yes (framework) | `RuntimeException` (transport) |
| Not configured / unknown `auth_mode` / bad service-account secret | **propagates** | yes | `RuntimeException` (settings provider, token-provider binding; token POST propagates raw) |
| Malformed response (non-array list, non-numeric count, bad JSON) | **propagates** | yes | `UnexpectedValueException` / `JsonException` (transport, impl) |

- **`KeycloakAuthenticationException`** (`Shared\KeycloakAdmin\Exception`) is the *only* dedicated type — the single
  failure a UI catches. It extends `RuntimeException`, is thrown by the transport on **401/403**, and (later) by the SSO
  token provider when there is no usable identity. Its message carries **Keycloak's own upstream error body** (Guzzle
  truncates it) so an audience/role problem is debuggable from the log. Callers catch **only** this, `report()` it, then
  show the friendly notice — an expected per-caller outcome that must not crash the panel, but is still recorded.
- **401 vs 403** both map here (same UI): 401 = token missing/invalid/expired/wrong-audience (authentication); 403 = valid
  token but lacks the `realm-management` role (authorization). The HTTP code + body are in the message; the UI does not
  branch on them (yet).
- Everything else is a plain `RuntimeException` (environment/config) or `UnexpectedValueException` (broken response
  contract) and is **left to propagate**, so the framework logs it and it is visibly a bug/misconfig to fix. The transport
  resolves the bearer **before** its Guzzle try-block so a token-provision failure (bad secret, missing SSO session) is
  never mis-wrapped as a request-level auth/unavailable failure.
- The page (`KeycloakUsers::loadUsers`) implements exactly this: `catch (KeycloakAuthenticationException)` → `report()` +
  notice + empty paginator; no other catch (invariant #9).

---

## 7. Testing

**Principle: test where logic lives; don't test the passthrough.** The transport/HTTP layer is a thin proxy with no
business logic — exhaustively unit-testing "GET /users returns what Guzzle returned" buys little. Test the parts that
can actually be *wrong*, and prove the UI renders/behaves via a **fake adapter** rather than mocked HTTP. Add these
**along the way, per §4.1 step / phase** — not in a lump at the end.

**Tier 1 — real logic (unit, high value).** Only the bits with branching/transformation:
- DTO `fromRawResponse()` tolerance (missing/extra fields, attribute list normalization) — the import already depends
  on this being forgiving.
- Token cache: hit / near-expiry refresh / safety margin.
- **Array-body encoding** — `execute-actions-email` emits `["UPDATE_PASSWORD"]`, not `{"0":…}`.
- **Page → `first`/`max`** mapping and count wiring for `records()`.
- **Group diff + partial failure** (§5.4.2): correct add/remove set; one failed call → reported, others still applied.
- **Three-state** event/admin-history mapping (§2.3): rows / empty-in-retention / forbidden are distinct.
- **Auth-mode binding**: `auth_mode` selects the provider; underprivileged/absent token → **error, no fallback**
  (assert the switch never happens).
- **Password action rules** (§5.4.1): `temporary=true` enforced, permanent blocked unless config on, value never in
  dehydrated form state.
- **Credential-removal whitelist** (§5.4.3): `password` type never removable; remove-by-id targets the right credential.

**Tier 2 — thin transport (light).** A *few* Guzzle-mock tests: DELETE verb reaches the right URL; 401→single in-mode
refresh. Not every endpoint — no logic to cover.

**Tier 3 — Filament smoke tests via a FAKE adapter (the main UI safety net).** Provide in-memory fakes implementing the
capability interfaces (`FakeKeycloakUsersApi`, …) seeded with fixture DTOs; bind them in place of the real client. Then
Pest + Livewire assert *rendering and behaviour*, no HTTP:
- List page renders seeded rows; typing search calls the fake with the right `search`; group filter switches the fake
  data source; username-column sort constrained per §5.1.
- View page renders each section (identity, 2FA w/ `userLabel`, sessions, recent login events, admin history) including
  the **three-state** empty/forbidden variants (drive via fake return values).
- Edit: username field disabled when realm config says so / User Profile marks read-only; save calls fake `update`.
- Each action present/hidden by state + calls the right fake method (PW-reset, set-password, manage-groups,
  remove-credential, logout-all); after a write the table re-reads (`resetTable`).
- **Disabled-UI invariant:** `sso` mode + fake "no identity" → view renders **disabled components + banner, no
  exception** (§6.1, invariant #9).
- Coarse policy: unauthorized panel user → resource hidden.

**Cross-cutting.** Arch test: `shared/KeycloakAdmin` imports nothing from `Domain\`/Laravel (§4.1 Step 3).
pest-plugin-arch already scaffolded. **No live KC in CI** — fakes + fixtures only; optional opt-in integration group
against a dev realm (26.5.3) for the spike/manual runs.

---

## 8. Architectural invariants

Non-negotiable rules the implementation must uphold (verify in review):

1. **No identity fallback, ever** — the token provider is selected by config/caller context; a failure in the active
   mode surfaces as an error, never a silent switch to another identity.
2. **Token provider selected by caller/context** — bound once, injected; the transport never chooses.
3. **Shared adapter knows nothing about Laravel** — no session/config/container/`heloufir`; PSR-only deps. Session-based
   providers live in the plugin.
4. **Keycloak is the final authorization authority** — no client-side reimplementation of role/FGAP logic; authorize by
   attempt + graceful 403 (§6.1).
5. **Multi-call mutations support partial failure explicitly** — per-item outcome reported; no assumed atomicity (§5.4.2).
6. **Write operations fetch fresh state before read-modify-write** — never PUT a stale representation; re-read after
   write to refresh the UI.
7. **No assumptions about Filament v4 non-Eloquent tables until the spike proves them** (Phase 0).
8. **Credentials never touch logs / exception reports / Livewire debug payloads** (§5.4.1).
9. **Missing/unusable identity degrades gracefully** — no unhandled exception reaches the UI; components render disabled
   with a clear notice (§6.1).

## 9. Implementation phases

**Phase 0 — Technical spike (prove the risky assumptions before production code).** Timeboxed throwaway/PoC:

- Prove a Filament v4 **`records()`-backed table** with DTOs: pagination, global search, and that record identity powers
  **view/edit URLs + row/bulk actions** (don't assume — §3.2).
- Prove a **Keycloak SSO bearer** (from a heloufir session) actually authenticates against the **Admin REST API**
  (audience/roles present).
- **Verify real privileges for every endpoint** we call, in *both* auth modes — which of list/get/update/groups/
  credentials/sessions/events/admin-events/realm-config/user-profile actually succeed vs 403.
- **Verify admin-event output for every write action** — confirm each mutation is attributed and shaped as expected
  (feeds §5.2 admin history + auditing claims).
- ~~Confirm FGAP status + version~~ **done: 26.5.3, Admin Permissions Off (role-based)**. Spike only needs to confirm
  which realm-management roles the `sso`/`service_account` identity actually carries (which endpoints 403).

Exit criteria: each assumption confirmed or the plan adjusted. Only then (every phase below ships its §7 tests as it
lands — no end-of-project test phase):

1. **Adapter extraction/refactor** — move to `shared/KeycloakAdmin`; capability ports + facade; DELETE verb +
   array-body send; keep `api/` import path green. Tests green.
2. **Service-account provider** — extract `getToken()` → `ServiceAccountTokenProvider`; bind from `auth_mode`.
3. **SSO provider** — `FilamentSsoTokenProvider` (plugin); wire heloufir login; per-mode tests +
   underprivileged-token-errors-loudly test; **no fallback** asserted.
4. **Adapter read methods + DTOs** — users/groups/credentials/sessions/events/realm/user-profile ports.
5. **Read UI** — `records()` list (search/pagination, constrained sorting §5.1) + view infolist (identity, 2FA,
   sessions, recent login events, admin history) with three-state rendering (§2.3).
6. **Writes** — PW-reset email, set-password-direct (§5.4.1, off by default), manage-groups (partial-failure §5.4.2),
   remove-credential (whitelist §5.4.3), enable/disable, logout-all-sessions; each re-reads fresh state after.
7. **Polish** — coarse policy/panel gate, error/403 UX, User Profile-driven form rendering (§5.5), group-vs-text filter
   UX, README/config, later composer extraction of the adapter.

---

## 10. Open questions

**Keycloak environment (blocking — resolve in Phase 0):**

- ~~**FGAP enabled?**~~ **Resolved:** KC **26.5.3**, realm **Admin Permissions = Off** → FGAP disabled, classic
  role-based authz. No per-admin user subsets to build now; attempt+403 design stays (§6.1). Remaining sub-question: do
  admins currently get broad `realm-management`/`manage-users` or narrower roles? (Affects which actions 403.)
- **SSO client mapper:** does `heloufir/keycloak-sso` (client `admin-panel-dev`) issue tokens with the needed admin
  roles + audience? With `auth_mode=sso` and **no fallback** this is a hard prerequisite; else deploy
  `auth_mode=service_account` (client `admin-panel-serviceaccount`).
- **Events retention:** are login-events + admin-events enabled, and what retention window? Determines whether "recent
  login events"/"admin history" show data (§2.3 three-state).

**User Profile / editing:**

- Are first/last name/email guaranteed editable, or realm-restricted? Is email required? Duplicate email allowed? Any
  LDAP/federated users with read-only attributes? Custom user-profile attributes the admin must see? (v1: server-side
  validation + proactive metadata rendering — §5.5.)

**Password / credentials policy:**

- Should **set-password-directly** exist at all, or is email-reset (#4) sufficient? If it exists: permanent passwords
  ever allowed, or `temporary=true` always? Require **re-authentication** before setting a password?
- **Credential removal:** may recovery codes be removed? On removing the last MFA factor, should we auto-add a
  `CONFIGURE_TOTP` required action? Does the realm mandate MFA (changes post-removal behaviour)? (§5.4.3)

**Scope:**

- **Group-management permission source** in `service_account` mode — policy/config flag vs panel role? (Assumed flag.)
- **Cross-realm?** Single realm assumed (no switcher v1).
- **Delete/create user in the UI?** Out of scope this iteration (create exists for the import path).

---

## 11. Implementation progress

Built in **small, manually-verifiable slices**. `admin/` consumes the plugin via a `path` composer
repo (`DistributionPackages/*`, symlink), so plugin source is mounted into the admin container
(`docker-compose.yaml` mounts `admin/DistributionPackages/`). Verify each slice in the real admin
panel at `broodfonds-api-admin.docker/admin`.

### Done

- **Slice 1 — Plugin wiring (empty page).** ✅ Verified in admin panel.
  - Plugin requires Filament `^4.0` (was `^5.0`). Registered in `AdminPanelProvider` via
    `->plugin(KeycloakFilamentAdminPlugin::make())`.
  - `KeycloakUsers` Filament Page + placeholder view; plugin `register()` adds the page.
  - Infra: admin `composer.json` path repo `DistributionPackages/*` + require `@dev`;
    `docker-compose.yaml` mounts `admin/DistributionPackages/` into the admin container (was the
    missing mount that caused the "translator"/class-not-found boot failure).
- **Slice 2 — `records()` non-Eloquent table spike (invariant #7).** ✅ Verified in admin panel.
  - `KeycloakUsers` now `implements HasTable` + `use InteractsWithTable`; `table()` uses
    `->records()` returning a `LengthAwarePaginator` of **plain array rows** (`Data\DummyKeycloakUsers`,
    23 static rows). Proven: server-side pagination, global search (`$search` param), per-row identity
    via `__key`. Columns: username, email, enabled (boolean icon). No Keycloak calls.
  - Filament v4 facts confirmed for `records()`: injectable closure params `page`, `recordsPerPage`,
    `search`, `sortColumn`, `sortDirection`, `filters`; array records keyed by `__key`
    (`Filament\Support\ArrayRecord::getKeyName()`), columns read via `data_get`.
  - **DECISION — Page, not Resource (corrects §3.1/§3.2).** Filament's custom-data (`records()`) is a
    **Tables-only** feature; there is **no model-less Resource** in v4 (`Resource::getModel()` falls
    back to a guessed Eloquent class, and View/Edit/route-binding/authorization all assume a model).
    A dummy Eloquent model to fake it was tried and rejected as a fragile workaround. So the UI is a
    custom **Page hosting the table** (`extends Page implements HasTable`), which requires a `$view`
    blade rendering `{{ $this->table }}`. Ignore the `KeycloakUserResource`/`Pages/List…Edit…View…`
    file layout in §3.1 — View/Edit/actions will be additional Pages + table row/header actions on
    this Page, not Filament Resource pages.

- **Slice 3 (in progress) — adapter → `shared/`, Laravel-independent.** Invisible refactor (adapter
  is **orphaned**: confirmed no consumers repo-wide; `api/` import will come later). Verify = admin
  panel still boots + lint/tests green.
  - **Sub-step 1 ✅** — moved `admin/DistributionPackages/keycloak-admin-adapter-layer` →
    `shared/KeycloakAdmin`; namespace `Domain\Adapters\KeycloakAdmin` → `Shared\KeycloakAdmin` (admin
    already autoloads `Shared\ → ../shared`; `shared/` is mounted into the admin container).
  - **Sub-step 2 ✅** — decoupled the 3 app deps in `KeycloakTransport`: `LazySettings<KeycloakSettings>`
    → new `Shared\KeycloakAdmin\KeycloakSettingsProvider` seam (`get(): KeycloakSettings`); the
    Guzzle `Client` is now **injected** (built by the consumer, must be non-body-logging) instead of
    constructed via `LoggingGuzzleClientFactory`/`BroodfondsApiLogging`. Adapter now imports **only**
    Guzzle + PSR (`Psr\Log`, `Psr\Http\Message`) + own DTOs — no `Domain\`/`Illuminate\`/Filament
    (invariant #3). `php -l` clean.
  - **Sub-step 3 ✅** — extracted `KeycloakTransport::getToken()` → `Auth\ServiceAccountTokenProvider`
    (owns the client_credentials POST, in-memory token cache, expiry safety margin) implementing new
    `Auth\KeycloakTokenProvider { currentBearer(): string }`. `KeycloakTransport` ctor now
    `(KeycloakSettingsProvider, Client, KeycloakTokenProvider)` and `send()` calls
    `$this->tokenProvider->currentBearer()`. Consumer wiring becomes: settings provider + non-logging
    Guzzle client → `ServiceAccountTokenProvider` → `KeycloakTransport` → `…ApiClientImplementation`.
    Still PSR/Guzzle-only, lint clean.
  - **TODO** — arch test (admin pest) asserting `Shared\KeycloakAdmin` uses no `Domain`/`Illuminate`
    (grep-verified for now). `shared/` has no own composer/test harness — adapter is tested from the
    admin suite (where `Shared\` autoloads), Guzzle-mock based.

**Slice 3 complete** — adapter is now a self-contained, Laravel-independent `shared/KeycloakAdmin`
library (settings seam + injected client + pluggable token provider). Clean checkpoint / safe to
reset context here.

- **Slice 4 — real users behind `records()`.** ⏳ Code + tests green; **needs manual verify in the
  admin panel** (real Keycloak connection).
  - **Shared adapter:** extended `KeycloakUser` DTO — added `email`, `firstName`, `lastName`,
    `enabled`, `emailVerified` (all tolerant/defaulted; `enabled` defaults **true** so a brief row
    isn't falsely shown disabled) + `fullName()` helper. Kept `attributes`/`firstAttributeValue`
    (import path untouched). New segregated interface `Contracts/KeycloakUsersApi`
    (`list()`, `count()`) + `Impl/KeycloakUsersApiImplementation` (uses `KeycloakTransport`,
    `briefRepresentation=true`, shared `search`/`enabled` query for list+count so totals match rows,
    parses the bare-integer `/users/count` body). Facade §4.1 Step 6 still deferred — the plugin binds
    `KeycloakUsersApi` directly. Invariant #3 re-verified (shared imports only PSR/Guzzle/own).
  - **Plugin wiring:** `config/keycloak-filament-admin.php` filled (connection, `auth_mode`
    default `service_account`, http timeouts). `Keycloak\ConfigKeycloakSettingsProvider` reads that
    config (loud error on missing keys). `KeycloakFilamentAdminServiceProvider::registerKeycloakAdapter()`
    binds settings provider → non-body-logging Guzzle client (timeouts) → `KeycloakTokenProvider` chosen
    by `auth_mode` (`service_account` wired; `sso`/unknown **throw**, no fallback — invariants #1/#2) →
    `KeycloakTransport` → `KeycloakUsersApi`. Added `guzzlehttp/guzzle` to plugin `require`.
  - **Page:** `records()` now calls the adapter — page→`first`/`max`, `count()`→total, DTOs mapped to
    array rows keyed by user id; added a `name` column. `KeycloakUsersApi` is **injected via Livewire
    `boot()` method injection** (not `app()` service-location). `DummyKeycloakUsers` deleted.
  - **Failure taxonomy (§6.3)** implemented: single dedicated `KeycloakAuthenticationException` (thrown
    by the transport on 401/403) is the *only* caught exception → friendly notice + empty page, **not
    logged** (invariant #9). Unreachable/5xx → plain `RuntimeException`; not-configured/bad-`auth_mode`
    → `RuntimeException`; malformed response → `UnexpectedValueException` — **all propagate + get logged**.
    Transport resolves the bearer *before* its Guzzle try so token-provision failures propagate un-wrapped.
  - **Tests (16 green, 47 assertions):** DTO tolerance (full / brief-defaults / name-parts / no-id),
    `KeycloakUsersApi` via Guzzle `MockHandler` (page offset/limit + search/enabled query mapping,
    omitted params, `/users/count` integer parse + query, **403→`KeycloakAuthenticationException`,
    401→`KeycloakAuthenticationException`, 5xx→plain `RuntimeException` (not the auth type),
    non-numeric count→`UnexpectedValueException`**). Added `Shared\ → ../../../shared` to the plugin
    `autoload-dev` so the adapter is testable headless. NOTE: `pest` emits sandbox warnings reading
    `vendor/.../.env.testbench` (filesystem restriction) — cosmetic, all tests pass.

- **Slice 5a — user detail page (own route), identity section.** ⏳ Code + tests green; **needs
  manual verify in the admin panel**.
  - **Shared adapter:** `KeycloakUsersApi::getById(KeycloakUserId): KeycloakUser` + impl — full
    (non-brief) `GET /users/{id}` representation, id `rawurlencode`d, parsed via the tolerant
    `KeycloakUser::fromRawResponse`. Same failure taxonomy as list (401/403 →
    `KeycloakAuthenticationException`, else propagate).
  - **DECISION — detail is a deep-linkable route, not a modal.** Individual users must have stable,
    shareable URLs, so the detail view is a **dedicated `ViewKeycloakUser` Page** with route path
    `/keycloak-users/{userId}` (`getRoutePath` overridden; `mount(string $userId)`;
    `shouldRegisterNavigation = false`). Still a Page, not a Resource View page (Keycloak has no model
    — per the slice-2 decision); the user id is a plain route param and the full rep is fetched live.
    The list page's **View row action** is now a `->url(fn ($record) => ViewKeycloakUser::getUrl([...]))`
    link (not a modal).
  - **Detail page:** renders `{{ $this->userInfolist }}` — a `Schema` method resolved by Filament's
    `InteractsWithSchemas` magic. Fetches the full user via `getById` (memoized once per request) and
    renders an **Identity** `Section` (username, email, name, enabled, email-verified). Invariant #9:
    on `KeycloakAuthenticationException` it degrades to a single "unavailable" notice + `report()` +
    friendly `Notification`, never throws.
  - **Tests (20 green / 58 assertions):** `getById` full-rep parse (attributes preserved, no
    `briefRepresentation` in URL), `getById` 403 → `KeycloakAuthenticationException`, both pages
    registered on the panel, and the `{userId}` route path. Infolist render / three-state still
    **manual-verify only** — no workbench panel in the headless suite yet (see Reminders); page logic
    is thin over the unit-tested adapter.

- **Slice 5b — remaining detail-view sections (groups, 2FA, sessions, login events, admin history).**
  ⏳ Code + tests green; **needs manual verify in the admin panel**.
  - **Shared adapter:** 5 new DTOs (`KeycloakGroup`, `KeycloakCredential` with `isSecondFactor()`,
    `KeycloakSession` flattening the `clients` map → readable names, `KeycloakLoginEvent`,
    `KeycloakAdminEvent` flattening `authDetails.userId` → acting admin) + 4 new segregated interfaces
    (`KeycloakGroupsApi::getUserGroups`, `KeycloakCredentialsApi::get`, `KeycloakSessionsApi::getSessions`,
    `KeycloakEventsApi::getLoginEvents`/`getAdminEventsForUser`) with impls over the same `KeycloakTransport`.
    All read-only for now (writes land later). `KeycloakUser` DTO extended with tolerant `requiredActions`
    (drives the `CONFIGURE_TOTP` pending badge). Invariant #3 held — shared still imports only PSR/Guzzle/own.
  - **Plugin wiring:** the 4 new APIs bound as singletons in `registerKeycloakAdapter()`; `config` gained
    an `events` block (`login_history_max`/`admin_history_max`, default 20).
  - **Detail page:** `ViewKeycloakUser` now injects all 5 APIs via `boot()` and renders six sections —
    Identity, Groups, Security/2FA (second-factor badge + `CONFIGURE_TOTP` pending warning + credential
    list with `userLabel`/createdDate), Active sessions, Recent login events, Admin history. **Each list
    section is three-state** (§2.3) via a `fetchRows()` helper: a caught `KeycloakAuthenticationException`
    → "unavailable/forbidden" note (`report()`ed), `[]` → a per-section empty message (not an error), rows
    → bulleted list. Sections fetch **independently** (memoized per request), so one forbidden slice never
    blanks the others; a 403 fetching the user itself still degrades the whole page (invariant #9). History
    sections carry honest descriptions ("not an authoritative last login", retention-window caveat).
  - **Tests (28 total / 88 assertions, full suite green):** new `KeycloakDetailApisTest` (groups/credentials/
    sessions/events query + parse, `isSecondFactor`, clients-map flatten, admin-event `resourcePath` +
    acting-admin flatten, empty-≠-error, 403→`KeycloakAuthenticationException`) + `requiredActions` DTO
    tolerance. Infolist three-state **rendering** still manual-verify (no workbench panel headless).

- **Slice 5c — user events as a real paginated table (not a bulleted list).** ⏳ Code + tests green;
  **needs manual verify**.
  - Replaced the infolist "Recent login events" bulleted entry with a dedicated **Livewire table
    component** `Livewire\KeycloakUserEventsTable` (own `HasTable`/`HasActions`/`HasSchemas`), embedded in
    the detail page blade via `@livewire(...)` (only when the user resolved — invariant #9). Columns
    mirror Keycloak's own UI: **Time · Event · IP address · Client**, with the event `type` as a badge and
    the **error in brackets** (`LOGIN_ERROR (invalid_user_credentials)`, red badge). A row **"Details"**
    action opens a modal (the "expand row" equivalent) showing the full `details` map + raw fields.
  - **All event types** now (dropped the `type=LOGIN` filter); DTO `KeycloakLoginEvent` → `KeycloakUserEvent`
    (+ `type`, `error`, `details` map). Adapter `getUserEvents(userId, first, max)` is **paginated**:
    Keycloak's `/events` has **no count**, so pagination is *simple* (Prev/Next) — the table requests
    `perPage + 1` and Laravel's `Paginator` infers "has more" and slices the probe row off.
  - Same failure taxonomy: `KeycloakAuthenticationException` → friendly notice + empty page (needs
    `view-events`); everything else propagates. Config `events.user_history_max` removed (page size is a UI
    concern now); `admin_history_max` kept for the still-infolist admin-history section.
  - **Tests (full suite 29 / 96 green):** `getUserEvents` no-type-filter + `first`/`max` mapping + type/
    error/details parse; empty-≠-error and 403 updated to the new signature. Table rendering manual-verify.

- **Slice 5d — admin history table + adapter/DTO cleanup.** ⏳ Code + tests green; **needs manual verify**.
  - **Admin history is now a real paginated table** (`Livewire\KeycloakAdminEventsTable`, sibling of the
    user-events one), **collapsed by default** (`<x-filament::section collapsible collapsed>`). Columns:
    Time · Operation (badge, red on error) · Resource · By (acting admin) · IP; row "Details" modal shows
    auth client/ip, error and the JSON `representation`. `getAdminEventsForUser(userId, first, max)` now
    paginated (simple Prev/Next, `perPage + 1` probe). DTO `KeycloakAdminEvent` gained `authClient`,
    `authIpAddress`, `error`, `representation`. **`resourcePath` bug fixed earlier** (exact→wildcard
    `users/{id}*`) is why the panel now matches Keycloak's own admin-events list.
  - **Bug fix — `resourcePath` exact vs wildcard:** Keycloak's filter is exact unless it contains `*`; a
    bare `users/{id}` missed every sub-resource action. Trailing `*` = prefix match.
  - **Removed the 90-day login timeline** (visually noisy) and the throwaway `dateFrom` param it needed.
  - **Cleanup per review:** (a) dropped the UI-layer request memoization (`resolvedUser`/`sectionCache`) —
    caching, if ever needed, belongs in the adapter, not the page; the page now calls the adapter directly.
    (b) Timestamps are **real `DateTimeImmutable`** in the DTOs (new `Dto\KeycloakTimestamp` parses epoch-ms
    → UTC `DateTimeImmutable` and formats; plain PHP, keeps `shared/` framework-free) — `createdDate`→
    `createdAt`, event `time`→`at` (+ `formattedTime()`). (c) Display logic moved **onto the DTOs**
    (`KeycloakCredential::describe()`, `KeycloakSession::describe()`, `KeycloakUserEvent::label()`) instead of
    static `describe*`/`formatTimestamp` helpers on the page. (d) Removed the silly `userIdValue()` wrapper.
  - **Tests (29 / 97 green):** admin-events wildcard + `first`/`max` pagination; credential `createdAt` as
    `DateTimeImmutable`. Table rendering + collapse still manual-verify.

- **Slice 5e — detail page redesigned as tabs; every section an explicit table; typed record wrapper;
    Blade exception boundary.** ⏳ Code + tests green; **needs manual verify** (several
    framework-boundary assumptions below).
  - **Tabs (Filament schema).** `ViewKeycloakUser` is now a pure **tab orchestrator** — no adapter deps,
    no data fetch. `detailSchema()` builds `Schemas\Components\Tabs` with one tab per section, each
    embedding its Livewire component via `Schemas\Components\Livewire::make(Class, ['userId'=>…])`.
    All tabs except Identity are `->lazy()` → a hidden tab's data is only fetched when opened (admin
    history included). `->persistTabInQueryString()` makes the active tab deep-linkable.
  - **Everything is an explicit table now** (consistency): new Livewire components
    `KeycloakUserIdentity` (infolist), `KeycloakUserGroupsTable`, `KeycloakUserCredentialsTable`,
    `KeycloakUserSessionsTable` — alongside the two event tables. The old infolist sections +
    `fetchRows`/`listEntry` on the page are gone.
  - **Typed record wrapper (`Filament\KeycloakRecord`).** Filament custom-data tables only accept
    `array | Model` (verified in `HasRecords::mapWithKeys`), so a readonly DTO can't be a record. A
    table-less Eloquent `KeycloakRecord` carries the real DTO across the boundary; columns/actions read
    it back typed via `$record->dto()` narrowed with `assert($dto instanceof …)` (runtime check +
    PHPStan narrowing). Detail modals receive the DTO directly. **Chosen over scalar-array rows for
    type safety** (esp. upcoming write actions).
  - **`@keycloakboundary` Blade directive** (registered in the provider) replaces every per-component
    `try/catch`: it buffers the enclosed render and, on the one catchable `KeycloakAuthenticationException`
    (§6.3), discards partial output, `report()`s, and renders a fallback string (role-specific message).
    Lives **inside each component's view**, so it wraps initial *and* Livewire-update renders. Everything
    else propagates. (A Blade *component* can't do this — slot renders before the component; a *directive*
    compiles inline, so the try/catch really surrounds the render.)
  - **Manual-verify risks (new framework assumptions):** (a) table-less Eloquent `KeycloakRecord`
    behaves under Filament's record-key/resolve path; (b) `Livewire::make()->lazy()` inside schema Tabs
    defers hidden-tab fetches; (c) `@keycloakboundary` actually catches when Filament resolves
    `records()` during the table blade render (incl. lazy load + pagination Livewire updates). All green
    in the headless unit suite (29/97), but these are render-time behaviours only the real panel exercises.

- **Slice 6a — write foundation (transport verbs) + first write action: trigger password-reset email.**
    ⏳ Code + tests green; **needs manual verify in the admin panel** (real Keycloak + realm SMTP).
  - **Transport verbs (§4 items 1–2):** `KeycloakTransport::delete(path)` (DELETE verb, mirrors `get`)
    and `putList(path, list)` — an **array-body PUT** that does *not* `(object)`-cast, so the body
    serializes as a bare JSON array (`array_values()` guarantees sequential keys). `putJson` would
    corrupt a list into `{"0":…}`; `putList` is what `execute-actions-email` needs. `delete` unblocks
    remove-credential / remove-from-group (later slices).
  - **Adapter:** `KeycloakCredentialsApi::executeActionsEmail(userId, actions, ?lifespan, ?clientId,
    ?redirectUri)` + impl — `PUT /users/{id}/execute-actions-email?…` with the actions as the array
    body via `putList`. `array_filter` drops unset/zero optionals so no empty query params are sent.
    Same failure taxonomy (401/403 → `KeycloakAuthenticationException`, else propagate — SMTP-not-
    configured surfaces as a propagating 5xx).
  - **Config:** new `pw_reset` block (`lifespan` default 43200s, optional `client_id`/`redirect_uri`).
  - **UI:** `ViewKeycloakUser` gained a **header action** "Send password-reset email" (confirm modal,
    warning colour) → `executeActionsEmail(['UPDATE_PASSWORD'])`. This is the **preferred** reset path
    (§5.4) — the admin never sees/sets the password. The page now injects `KeycloakCredentialsApi` via
    `boot()` (previously dep-free tab orchestrator); catches only `KeycloakAuthenticationException`
    (report + friendly notice), else propagates. Success → success notification.
  - **Tests (33 / 108 green):** array-body encoding (`["UPDATE_PASSWORD"]`, NOT `{"0":…}`) + endpoint +
    query params; unset optionals omitted; 403 → `KeycloakAuthenticationException`; `delete` verb reaches
    the exact path with method DELETE. Modal/notification rendering still manual-verify (no workbench).
  - **Still TODO for the rest of slice 6:** set-password-direct (§5.4.1), manage-groups (partial failure
    §5.4.2), remove-credential (whitelist §5.4.3, uses the new `delete`), enable/disable (RMW PUT — needs
    `KeycloakUsersApi::update`/`setEnabled` + `KeycloakRealmApi` for the gated username field), logout-all
    (`POST /users/{id}/logout`). Each re-reads fresh state after (invariant #6).

- **Slice 6b — logout-all-sessions + remove-credential (whitelist).** ⏳ Code + tests green; **needs
    manual verify in the admin panel**.
  - **Adapter:** `KeycloakSessionsApi::logoutAll(userId)` → `POST /users/{id}/logout` (empty body,
    idempotent); `KeycloakCredentialsApi::delete(userId, credentialId)` → `DELETE
    /users/{id}/credentials/{credentialId}` (uses the slice-6a `delete` verb). Same failure taxonomy
    (401/403 → `KeycloakAuthenticationException`, else propagate). Adapter deletes exactly what it's told
    — the whitelist is a UI concern.
  - **UI — logout-all:** `KeycloakUserSessionsTable` gained a **header action** "Log out all sessions"
    (danger, confirm) → `logoutAll` → `resetTable()` so the now-empty list reflects server state
    (invariant #6). Catches only `KeycloakAuthenticationException` (actions run outside the render-time
    `@keycloakboundary`, so each action catches for itself).
  - **UI — remove-credential (whitelist §5.4.3):** `KeycloakUserCredentialsTable` gained a **row action**
    "Remove", `->visible()` only for **second-factor** credentials (OTP/WebAuthn via `isSecondFactor()`)
    that carry a **real id** — so `password` is never removable and id-less display rows are skipped.
    Recovery codes deliberately excluded (open question §10). Confirm modal shows the credential's
    `userLabel`/type and **warns when it's the user's last second factor** (removal → single-factor).
    → `delete` → `resetTable()`.
  - **Tests (36 / 113 green):** `logoutAll` POST endpoint + 403 → `KeycloakAuthenticationException`;
    credential `delete` DELETE endpoint. Modal/visibility/last-MFA-warning rendering still manual-verify
    (no workbench panel headless).
  - **Remaining slice-6 writes:** manage-groups (partial failure §5.4.2), enable/disable + gated username
    edit (needs `KeycloakUsersApi::update`/`setEnabled` + `KeycloakRealmApi` for `editUsernameAllowed`),
    set-password-direct (§5.4.1, off by default). Each re-reads fresh state after (invariant #6).

- **Slice 6c — manage-groups (direct add/remove, one group per call).**
    ⏳ Code + tests green; **needs manual verify in the admin panel**.
  - **Adapter (`KeycloakGroupsApi`):** three single-op methods — `listRealmGroups(?search)` →
    `GET /groups?briefRepresentation=true&search=` (picker source); `addUserToGroup(userId, groupId)` →
    **body-less** `PUT /users/{id}/groups/{groupId}` (idempotent); `removeUserFromGroup(...)` →
    `DELETE /users/{id}/groups/{groupId}`. Single-op — the UI adds/removes one group at a time.
    - **Live-verify fix (payload):** the first attempt used `putJson([])`, which emits a JSON `{}` body +
      `Content-Type: application/json`. Added a true no-body verb `KeycloakTransport::put(path)` (no body,
      no Content-Type) and pointed `addUserToGroup` at it. Keycloak takes the group purely from the URL.
    - **Live-verify note (the actual 500):** the join-group 500 (`unknown_error`) turned out to be a
      **realm-side event-listener** issue, not our payload — resolved by listing resource type
      `GROUP_MEMBERSHIP` in the realm's events config. Both payload shapes had 500'd identically, which is
      what pointed at the server side.
  - **UI:** `KeycloakUserGroupsTable` has a header **Add to group** action (searchable multi-select of
    **only groups the user isn't already in**, labelled by human-readable path/name) and a per-row
    **Remove** action (one click + confirm). Each calls the adapter directly, one group per call, then
    `resetTable()` (invariant #6). **No partial-failure bookkeeping** — a failed call is left to surface
    as an error and the admin retries. (Deliberately simplified after review; the earlier
    `Groups\GroupMembershipUpdater` + `GroupUpdateOutcome` diff-and-tally seam was removed as
    over-engineered for a one-group-at-a-time action.)
  - **UX follow-ups (from live use):**
    - Admin-history table gained a **Type** column (badge) showing `resourceType` (e.g. `GROUP_MEMBERSHIP`)
      — the DTO already parsed it; it just wasn't surfaced.
    - **Details** column shows a human-readable label via `KeycloakAdminEvent::resourceLabel()`, which
      reads the resource's own name from the event's logged `representation` — a group's `path`/`name`
      (`/Automatiseerders`), a user's `username`/full-name/`email` — falling back to the raw `resourcePath`
      when absent (deletes). (Rejected an earlier approach that fetched all realm groups and
      string-replaced UUID segments in the path — hacky; Keycloak already logs the name.)
  - **Tests (40 / 127 green):** adapter — `listRealmGroups` brief+search query, add via body-less PUT
    path, remove via DELETE path; `resourceLabel` group-path vs user-username vs raw-path fallback.
    Modal/multi-select rendering still manual-verify (no workbench panel headless).
  - **Remaining slice-6 writes:** enable/disable + gated username edit (needs `KeycloakUsersApi::update`/
    `setEnabled` + a `KeycloakRealmApi` for `editUsernameAllowed`), set-password-direct (§5.4.1, off by
    default). Each re-reads fresh state after (invariant #6).

### Continue here (slices 4 + 5a + 5b + 5c + 5d + 5e + 6a + 6b + 6c — MANUAL VERIFY, then rest of writes)

**First: verify slices 4 + 5a in the real admin panel** (`broodfonds-api-admin.docker/admin` → Keycloak
Users). Needs the service-account env set: `KEYCLOAK_BASE_URL`, `KEYCLOAK_REALM`,
`KEYCLOAK_ADMIN_CLIENT_ID`, `KEYCLOAK_ADMIN_CLIENT_SECRET`, `KEYCLOAK_ADMIN_AUTH_MODE=service_account`.
Expect: real users, working search + pagination; **View** action opens a modal with the Identity
section. Per §6.3: a **403** (service account lacks `view-users`) → friendly "not authorized" notice,
no crash; Keycloak **down**/misconfigured → error page + logged exception.

The detail-view **read** sections are complete (5b) and **slice 6 writes have started (6a done):** transport
`delete` + array-body `putList` verbs (§4 items 1–2) shipped, and PW-reset email
(`executeActionsEmail(['UPDATE_PASSWORD'])`) is wired as a `ViewKeycloakUser` header action. **Remaining
slice-6 writes:** set-password-direct (§5.4.1, off by default, `temporary=true` enforced), manage-groups
(add/remove diff, partial failure §5.4.2), remove-credential (whitelist §5.4.3, `password` never removable —
uses the new `delete`), enable/disable, logout-all — each re-reads fresh state after (invariant #6).
Enable/disable + gated username edit still need `KeycloakUsersApi::update`/`setEnabled` and a
`KeycloakRealmApi` (`editUsernameAllowed`).

### Reminders / TODO

- **Standalone testbench panel (nice-to-have, NOT done).** The package uses `orchestra/testbench`
  (`require-dev`, `TestCase extends Orchestra` + `WithWorkbench`) — Pest runs headless and passes.
  But there is **no workbench app** (no `workbench/`, no `testbench.yaml`), so the plugin is **not
  click-through-able standalone** yet; only the real admin panel is. To make it clickable in
  isolation, scaffold: `workbench/app/Providers/Filament/DemoPanelProvider.php` (panel, dummy
  guard/user, registers the plugin) + `testbench.yaml`, then `vendor/bin/testbench serve`. Deferred
  in favour of durchstich momentum.
- ~~**Delete `Data\DummyKeycloakUsers`**~~ done in slice 4.
- **No git repo visible from CLI** in this environment — cannot rely on the plan's `git mv` /
  tests-green safety net for the adapter move; verify the `api/` import path manually / via its tests.
