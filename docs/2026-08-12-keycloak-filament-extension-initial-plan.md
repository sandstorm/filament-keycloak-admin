# Keycloak Filament Admin — Plan

**Package:** `sandstorm/filament-keycloak-admin` · **Namespace:** `Sandstorm\FilamentKeycloakAdmin` · **Filament v4** plugin,
PHP ^8.3 · **Keycloak target:** 26.5.3+.

A Filament v4 admin panel to manage Keycloak users through the Keycloak Admin REST API. Keycloak is the source of truth —
no local user mirror. The plugin is **UI only**; every HTTP call goes through the standalone client library
`sandstorm/keycloak-admin-api` (namespace `Sandstorm\KeycloakAdminApi`).

This document is the current-state plan. It is kept consistent with the code.

---

## 0. Implementation status (2026-08-15)

The plugin was refactored to match this plan. **Done:**

- **Namespace/identifier** — `Sandstorm\FilamentKeycloakAdmin`, config/view/plugin id `filament-keycloak-admin`. PHP ^8.3.
- **Layout (§3)** — `Filament/Helpers/KeycloakRecord`, `Filament/Pages/KeycloakUsers` + `InspectKeycloakUser`, child
  components colocated under `Filament/Pages/InspectKeycloakUser/`. Spatie skeleton (Commands, Facade, migrations, stubs,
  JS build) removed — pure server-rendered UI, no assets.
- **List page (§7.1)** — records cross as `KeycloakRecord`; whole-row `recordUrl`; no `viewAction`.
- **Detail page (§7.2)** — tab orchestrator; Overview stacks Identity + Groups + Credentials; sessions/events/admin lazy;
  cross-tab refresh via `keycloak-user-changed` + `#[On]` → `resetTable()`.
- **Failure model (§8)** — every failure (incl. 401/403) propagates. The `@keycloakboundary` directive, the unavailable
  partial, and all `KeycloakAuthenticationException` catches are gone (the client lib has one type,
  `UnexpectedKeycloakResponseException`).
- **Config (§9)** — plugin ships a structure-only stub; real values live in the app at
  `admin/config/filament-keycloak-admin.php`. The plugin never calls `env()`.
- **Views** — six per-component blades collapsed to two shared (`keycloak-table`, `keycloak-infolist`).
- **Event tables (§7.2)** — self-contained (the earlier generic abstract base was dropped; see §7.2).
- **Tests (§11)** — plain PHPUnit; `phpunit.xml.dist` `unit`/`integration` suites; Testbench `TestCase`; the E2E
  Integration suite (docker-compose + realm-import + `IntegrationTestCase` + driving tests) is in place. PHPStan level 5
  passes. Rector removed (PHPStan + Pint only). Plugin-local `mise.toml` with `install`/`test`/`analyse`/`lint`/`e2e*`.

**Open:** run `composer update` with the sibling lib resolvable (path repo / published tag) to refresh the lock;
`auth_mode=sso` provider (§6); `.github/` CI (§12); client-lib `v1.0` tag (§13).

---

## 1. Two packages

| Package | Role | Depends on |
|---------|------|-----------|
| **`sandstorm/keycloak-admin-api`** | Framework-agnostic PHP client for the Keycloak Admin REST API — immutable typed DTOs, PSR-18/PSR-17 transport, token providers. No Laravel. Its own repo + test suite. | PSR HTTP only |
| **`sandstorm/filament-keycloak-admin`** (this) | Filament v4 UI over that client — pages, tables, actions, wiring. | the client + Filament + Guzzle |

The plugin **requires** the client as a normal composer dependency:

```jsonc
"require": {
    "php": "^8.3",
    "filament/filament": "^4.0",
    "guzzlehttp/guzzle": "^7.0",              // the plugin supplies the concrete PSR-18 client (the lib only suggests one)
    "sandstorm/keycloak-admin-api": "^1.0",
    "spatie/laravel-package-tools": "^1.15.0"
}
```

- **Standalone (OSS):** the client resolves from Packagist.
- **(OPTIONAL) Local monorepo dev:** the consuming `admin/` app declares a `DistributionPackages/*` **path repository**, so the
  sibling resolves from `../keycloak-admin-api`. A path-repo override for working on both packages at once is a dev-only
  overlay — **not** committed to the published `composer.json` (else external `composer install` breaks on the missing
  sibling).

---

## 2. The client library (what the plugin consumes)

`Sandstorm\KeycloakAdminApi` exposes:

```
Connection/
  KeycloakSettings.php                 # value object: baseUrl, realm, clientId, clientSecret
  KeycloakSettingsProvider.php         # interface: get(): KeycloakSettings   (the plugin supplies an impl)
  KeycloakTransport.php                # PSR-18 transport: get/getJson/postJson/putJson/put/putList/delete
  UnexpectedKeycloakResponseException  # THE single failure type — carries ->statusCode (401/403 = denied)
  Auth/
    KeycloakTokenProvider.php          # interface: currentBearer(): string
    ServiceAccountTokenProvider.php    # client_credentials grant + in-memory token cache
Features/
  Keycloak{Users,Groups,Credentials,Sessions,Events}Api.php   # segregated interfaces + …/…Implementation
  …/Dto/*                              # KeycloakUser, KeycloakGroup, KeycloakCredential, KeycloakSession,
                                       #   KeycloakUserEvent, KeycloakAdminEvent, …
SharedModel/
  KeycloakUserId.php  KeycloakTimestamp.php  KeycloakCollection.php
```

**Failure model — one exception.** `KeycloakTransport` never throws provider-specific auth types. Any transport failure
or non-2xx becomes a single `UnexpectedKeycloakResponseException` whose `->statusCode` the caller may read (401/403 =
denied). A malformed body is `UnexpectedValueException`. There is no dedicated authentication exception.

---

## 3. Plugin layout

```
src/
  FilamentKeycloakAdminServiceProvider.php   # config, HTTP client, token provider, transport, API bindings, Livewire regs
  FilamentKeycloakAdminPlugin.php            # registers the two pages on a panel
  Keycloak/
    ConfigKeycloakSettingsProvider.php       # KeycloakSettingsProvider impl reading config('filament-keycloak-admin.*')
  Auth/
    FilamentSsoTokenProvider.php             # (near-term, §6) KeycloakTokenProvider impl reading the logged-in admin's KC token
  Filament/
    Helpers/
      KeycloakRecord.php                     # impl detail (pushed deep, §14.4): table-less Eloquent wrapper carrying a DTO across Filament's array|Model seam
    Pages/
      KeycloakUsers.php                      # list page (records() table, non-Eloquent)
      InspectKeycloakUser.php                # detail page: tab orchestrator + page header write actions
      InspectKeycloakUser/                   # colocated child Livewire components — one per list (§7.2)
        KeycloakUserIdentity.php             # infolist (key/value, not a list)
        KeycloakUserGroupsTable.php
        KeycloakUserCredentialsTable.php
        KeycloakUserSessionsTable.php
        KeycloakUserEventsTable.php          # self-contained (each event table owns its perPage+1 probe + typed dto())
        KeycloakAdminEventsTable.php
config/filament-keycloak-admin.php            # structure-only stub (keys + docs, no values, no env()); real config lives in the app (§9)
resources/
  views/livewire/keycloak-table.blade.php    # ONE shared blade for every table component
  views/livewire/keycloak-infolist.blade.php # Identity infolist
  views/filament/pages/{keycloak-users,inspect-keycloak-user}.blade.php
  lang/en/filament-keycloak-admin.php
tests/                                        # plain PHPUnit (§8)
```

The package is a pure UI plugin: pages, tables, actions, and the wiring that binds the client lib to a panel.

---

## 4. Data source — non-Eloquent Filament table

Keycloak has no local table. Filament v4's Tables-only **custom-data (`records()`)** hook backs the list:

- The list page is a **Page hosting a table** (`extends Page implements HasTable`), not a Resource — Filament has no
  model-less Resource. The `$view` blade renders `{{ $this->table }}`.
- `records(fn (int $page, int $recordsPerPage, ?string $search) => …)` maps Filament state straight to the client:
  `first = ($page-1) * $recordsPerPage`, `$search` → the client's `search` param, total from `count()`. Never filter
  client-side.
- **Records cross the boundary as `KeycloakRecord`** — a table-less Eloquent model wrapping the real DTO (Filament
  records must be `array|Model`). Columns/actions read it back typed via `$record->dto()` + `assert(... instanceof ...)`.
- **Sorting is constrained:** Keycloak's `GET /users` has no order param (fixed username order). Mark only username
  sortable; do not fake global sort by sorting one page.

---

## 5. Auth model — explicit, no fallback

One token provider, chosen by config `auth_mode`. The transport asks the bound provider for a bearer per request. **No
automatic fallback** — a misconfigured/underprivileged mode fails loudly.

- **`service_account`** (wired today) — `client_credentials` grant on a confidential client with the required
  `realm-management` roles. One shared identity; no per-admin scoping. `ServiceAccountTokenProvider` (from the client lib).
- **`sso` — act-as-user (§6, near-term).** Reuse the logged-in admin's Keycloak SSO token as the Admin-API bearer → real
  per-person attribution in admin-events. Fails loudly if the token lacks the admin roles/audience.

The provider is selected once in the ServiceProvider's `auth_mode` match and injected; the transport never chooses.
Unknown mode → `RuntimeException`.

---

## 6. SSO mode (near-term — needed soon)

Add `Auth\FilamentSsoTokenProvider implements Sandstorm\KeycloakAdminApi\Connection\Auth\KeycloakTokenProvider`:

- Reads/refreshes the logged-in admin's Keycloak access token from the Laravel session (via the panel's OIDC login,
  e.g. `heloufir/keycloak-sso`). Laravel-coupled → lives in the **plugin**, never in the client lib.
- Refreshes via the stored `refresh_token` near expiry; on 401 mid-request, one in-mode refresh, then propagate.
- **Requirements** the deployment must satisfy: the SSO client issues tokens carrying `realm-management` roles
  (`view-users`, `manage-users`, `query-users`, `query-groups`) and an **audience** including `realm-management` —
  usually an audience/role mapper on the SSO client. If absent, calls 403; **no** fallback to the service account.
- Wire into the ServiceProvider `auth_mode` match: `'sso' => new FilamentSsoTokenProvider(...)` (replacing today's
  `throw`). Config gains the SSO client knobs.
- **Auditing** is the reason to prefer `sso`: admin-events attribute changes to the real person.

Until this lands, `auth_mode=sso` throws (not implemented). `service_account` is the only working mode.

---

## 7. Features & UI

### 7.1 List page (`KeycloakUsers`)

- Columns: username · email · name · enabled (icon). Server-side search + pagination through the client.
- **The whole row is clickable** → `recordUrl(fn ($record) => InspectKeycloakUser::getUrl(['userId' => $record->getKey()]))`,
  giving each user a stable, shareable, deep-linkable address. Row-level navigation reads better than a lone "View"
  action and drops the shallow single-call `viewAction()` (§14.1).

### 7.2 Detail page (`InspectKeycloakUser`)

Route `/keycloak-users/{userId}` (stable, shareable). Named **Inspect** because it carries write actions alongside the
reads. The page is a **tab orchestrator**: `detailSchema()` builds Filament schema `Tabs`, embedding one or more Livewire
child components per tab via `Livewire::make(Class, ['userId' => …])`, active tab persisted in the query string.

**Every list is a real table.** Groups, credentials, sessions, and both event logs render as **tables**, not flattened
into infolist entries — a table reads far better for a list (columns, per-row actions, alignment). Filament binds **one
table per Livewire component** (`InteractsWithTable` = a single `table()`), so each list is its own colocated Livewire
component. This is exactly how base Filament renders multi-table screens (a page plus nested **RelationManager**
components); the higher component count is the price of readable lists, and worth it. Only Identity — key/value fields,
not a list — is an infolist.

Tabs:

1. **Overview** (default) — three child components stacked:
   - **Identity** (`KeycloakUserIdentity`, infolist) — username, email, name, enabled, email-verified, pending-`CONFIGURE_TOTP` note.
   - **Groups** (`KeycloakUserGroupsTable`) — memberships; **Add to group** (picker of groups the user isn't in) + per-row **Remove**. One group per call.
   - **Security / 2FA** (`KeycloakUserCredentialsTable`) — credential list (type, label, created, second-factor icon). **Remove** row action whitelisted to second-factor credentials with a real id (`password` never removable); warns when removing the last second factor.
2. **Active sessions** (`KeycloakUserSessionsTable`) — live sessions; **Log out all sessions** header action. `->lazy()`.
3. **User events** (`KeycloakUserEventsTable`) — every event (LOGIN, LOGIN_ERROR, …) as a simple-paginated table (Keycloak has no events count → `perPage+1` probe). Row **Details** modal. `->lazy()`.
4. **Admin history** (`KeycloakAdminEventsTable`) — admin actions targeting this user, same pagination + Details modal. `->lazy()`.

Page header action: **Send password-reset email** — `executeActionsEmail(['UPDATE_PASSWORD'])`, the preferred reset path
(admin never sees/sets the password). Requires realm SMTP.

**Cross-tab refresh.** A write in one list can invalidate another (logout-all shows up in user events; a credential
change shows up in admin history). Each write dispatches one Livewire event `keycloak-user-changed`; every table listens
`#[On('keycloak-user-changed')]` → `resetTable()`. Only *mounted* tabs react; unopened `->lazy()` tabs fetch fresh on
first open regardless, so the signal refreshes the whole screen without a reload. (Coarsest fallback if ever needed:
redirect to `InspectKeycloakUser::getUrl([...])` — a full reload, active tab preserved by the query-string persist.)

The two event tables are **self-contained** — each `KeycloakUserEventsTable` / `KeycloakAdminEventsTable` owns its own
`perPage+1` probe, event→`KeycloakRecord` mapping, `table()` skeleton, Details-modal action, and a typed `dto()` narrower
(`assert($event instanceof KeycloakUserEvent|KeycloakAdminEvent)`). An earlier shared abstract base was dropped: its seam
was **wide** (four abstract methods) and it coupled the two subclasses through inheritance to save only a few lines —
a shallow base with a wide interface, the exact smell §14.1 warns against. Standalone also matches the other four table
components (groups/credentials/sessions), which are already self-contained. The small duplication between the two event
tables is the deliberate, cheaper trade. Every table component renders the **single shared**
`filament-keycloak-admin::livewire.keycloak-table` blade; Identity renders its own infolist blade.


---

## 8. Failure handling — propagate, no catching

The plugin does **not** catch Keycloak failures. Every `UnexpectedKeycloakResponseException` (including 401/403) and every
other error **propagates** → framework error page + log. No `@keycloakboundary` directive, no per-section "unavailable"
notice, no degrade-to-empty state. (Graceful per-section degradation keyed on `->statusCode` is a possible later
addition; deliberately out of scope now.)

The **no-fallback** guarantee still holds and lives in the ServiceProvider: the token provider is selected once from
`auth_mode`; a wrong/underprivileged mode surfaces as an error, never a silent identity switch.

---

## 9. Configuration — owned by the consuming app, not the plugin

The plugin reads config through the `filament-keycloak-admin.*` key but does **not** ship env wiring. The **authoritative
config file lives in the admin distribution** (the parent app: `admin/config/filament-keycloak-admin.php`), with literal
values. The plugin never calls `env()` — env handling, if any, is the deploying app's choice; the plugin only reads
resolved config via `ConfigKeycloakSettingsProvider`.

App-owned config file (`admin/config/filament-keycloak-admin.php`) — literal values, no `env()` inside the plugin:

```php
return [
    'connection' => [
        'base_url'      => 'https://keycloak.example/…',
        'realm'         => 'Broodfonds',
        'client_id'     => 'admin-panel-serviceaccount',
        'client_secret' => '…',                 // app decides how it sources this (its own env, secrets store, …)
    ],
    'auth_mode' => 'service_account',            // 'service_account' | 'sso' (near-term)
    'http' => [
        'connect_timeout' => 5,
        'timeout'         => 15,
    ],
    'pw_reset' => [
        'lifespan'     => 43200,
        'client_id'    => null,
        'redirect_uri' => null,
    ],
    // 'sso' block added with §6.
];
```

- The plugin ships **no** `env()`-populated config. Any config file it publishes is a **structure-only stub** (keys +
  docs, no values, no `env()`), so the OSS package carries no deployment specifics. Real values live in the app config
  above.
- `ConfigKeycloakSettingsProvider` reads `config('filament-keycloak-admin.connection.*')`; a missing key fails loudly.
- The ServiceProvider builds a **non-body-logging** Guzzle client (token requests carry the secret; admin responses
  carry PII) with the configured timeouts, wraps it in the token provider chosen by `auth_mode`, and binds the transport
  + the five `Keycloak*Api` implementations as singletons.

---

## 10. Security & authorization

- **Keycloak is the authorization authority.** Do not reimplement role/FGAP logic client-side. Authorize by attempt: a
  403 means "not permitted" and (per §8) propagates. Target realm today has **Admin Permissions = Off** → classic
  role-based authz; the design also holds if FGAP is switched on later.
- **`sso` enforces per-admin scope for free** — the admin's own token is the caller. `service_account` is one shared,
  coarse identity; deployments needing per-admin least-privilege must run `sso`.
- **Secrets:** service-account `client_secret` in env only; the client lib never body-logs; the plugin's Guzzle client
  has no logging middleware.
- **Set-password-directly is out of scope** for v1 — the email-reset flow is the only password path shipped.

---

## 11. Testing — plain PHPUnit

Same harness style as `sandstorm/keycloak-admin-api` (PHPUnit + PHPStan), plus `orchestra/testbench` because a Filament
plugin must boot Laravel. **No Pest.**

- `tests/TestCase.php` — Testbench `Orchestra` + `WithWorkbench`, registers the Filament + plugin providers.
- **Unit / feature suite** (default, hermetic): pages register on a panel; the detail route is `/keycloak-users/{userId}`;
  nav label. Structure/wiring only — **no fakes or bound test doubles**. Write actions are deliberately **not** unit-tested
  against a mock: a mock only re-asserts the call we wrote, so it proves nothing about Keycloak. Their real coverage is the
  E2E suite (§11.1), which exercises the whole path against a live Keycloak and verifies the effect server-side.
- `phpunit.xml.dist` two testsuites: `unit` (`tests/`, excluding Integration) and `integration` (`tests/Integration`,
  `@group integration`).

### 11.1 E2E against a real Keycloak (self-contained, opt-in)

Mirrors the client lib's Integration suite, driving the Filament/Livewire layer:

- Copy `tests/Integration/docker-compose.yml` (Keycloak 26.5.3 + MailPit) and `realm-import.json` (client `admin-api`
  with realm-management roles; public `e2e-login` direct-grant client; user `jane` in `/staff`) into the plugin.
- `tests/Integration/IntegrationTestCase.php` extends the Testbench `TestCase`, sets
  `config('filament-keycloak-admin.*')` from `KEYCLOAK_E2E_*` env (`auth_mode=service_account`), and **skips** unless
  `KEYCLOAK_E2E_BASE_URL` is set. Real ServiceProvider wiring is exercised — no fakes.
- Read cases (`#[Group('integration')]`, `Livewire::test`): list renders/searches `jane`; Identity shows jane; Groups
  shows `/staff`; sessions/credentials/events render (log in first via `e2e-login` so a real session + LOGIN event
  exist); a denied path propagates `UnexpectedKeycloakResponseException` (no swallow — §8).
- Write cases (`KeycloakUserWriteActionsE2ETest`): every UI mutation driven through the real Filament action lifecycle
  (mount → fill → confirm → call) then verified server-side via the bound API — `triggerPasswordReset`
  (`executeActionsEmail` accepted, MailPit SMTP), `logoutAll` (session gone after log-in), `addGroups`/`removeGroup`
  (membership toggled, net-zero against the seed), `removeCredential` (OTP deleted). The seeded second factor lives on a
  dedicated `mfa-user` so jane is never disturbed. The pure-API counterparts are the client lib's Integration suite.
- Plugin-local `mise.toml`: `install`, `test` (`phpunit --testsuite unit`), `analyse`, `e2e:up`, `e2e:down`,
  `test:integration` (env `KEYCLOAK_E2E_BASE_URL=http://localhost:9911`), `e2e` (up → integration → down).

---

## 12. Open-source release

Ships as its own repo, sibling to `sandstorm/keycloak-admin-api`.

- **`composer.json`:** name `sandstorm/filament-keycloak-admin`; homepage/support → the sandstorm repo; MIT; no
  Broodfonds strings; `minimum-stability: stable` once the client lib is tagged (require `^1.0`).
- **README:** install, register `FilamentKeycloakAdminPlugin` on a panel, provide the `filament-keycloak-admin` config in
  the app (the plugin ships only a structure-only stub — §9), required realm-management roles, Keycloak-version target,
  service-account-vs-sso note, how to run tests + E2E. Broodfonds-specific deployment notes stay out of the OSS README.
- **`.github/` CI:** one PHP (8.3 or 8.4, no matrix) → `composer install`, `phpunit --testsuite unit`, `phpstan analyse`,
  `pint --test`; E2E as an optional/manual job (boots docker-compose, sets `KEYCLOAK_E2E_BASE_URL`).
- **Housekeeping:** `.gitignore` covers `vendor/`, `build/`, `.phpunit.cache/`; no committed coverage artifacts;
  `CHANGELOG.md`; `grep -ri broodfonds .` (excluding `vendor/`, `.git/`) clean before tagging `v1.0.0`.

---

## 13. Open items

- **Refresh `composer.lock`** — run `composer update` with the sibling `sandstorm/keycloak-admin-api` resolvable (the
  admin app's `DistributionPackages/*` path repository, or a published tag) so the lock drops Pest and picks up the
  current requires.
- **Client lib `v1.0` tag** — the plugin requires `^1.0`; tag `sandstorm/keycloak-admin-api` before the plugin can go
  `minimum-stability: stable`.
- **SSO client mapper** (§6) — confirm the panel's OIDC client issues `realm-management` roles + audience; hard
  prerequisite for `auth_mode=sso` (no fallback).
- **Events retention** — login/admin events enabled + retention window determines whether the events/history tabs show
  data.
- **Single realm** assumed (no switcher). **Create/delete user** out of scope for v1.

---

## 14. Design principles (review contract)

Every change to this package is reviewed against *A Philosophy of Software Design* (Ousterhout). The plugin is a thin,
deep UI over the deep `sandstorm/keycloak-admin-api` lib; keep it that way. Concretely, uphold:

### 14.1 Deep modules, no shallow pass-throughs

- **Delete methods that are only a single method call** (the normal case). A method whose body is one delegation
  (`return $this->x->y()`) adds interface width and hides nothing — inline it at the call site. Keep such a wrapper
  **only** when its *name* absorbs a real decision the caller would otherwise have to make (a genuinely deeper interface),
  and say so in a why-comment. Apply this when rewriting the pages/components: today several private one-liners
  (`viewAction()`, per-action factory methods, `dto()` narrowers) exist — keep one only if it earns its name; otherwise
  inline.
- Prefer **few deep classes** over many shallow ones. No `Manager`/`Helper`/`Util` classes that only delegate.
- `KeycloakRecord` **stays** — it looks shallow but is not classitis: it bridges Filament's `array|Model` record contract
  to a typed DTO. Its class-level comment must state that *why* so it is not mistaken for a pass-through.

### 14.2 Complexity smells

- Branching on the same data → missing value object / polymorphism. Push the decision into the type.
- Nullable leaking to callers → absorb it (empty collection, default) where the domain allows. Deliberate `A|B` unions
  and enums are fine — they model a closed set; a stray `?T` "absent" case is the smell.

### 14.3 Comments — *why*, not *what*

- Each class carries a **class-level why-comment** (the problem it solves, the invariant it guards, what it hides) **or** a
  `@see` to the class that holds that rationale. Inline *what*-comments that restate the code are removed — the current
  code, carried over from the build log, is **over-commented**; the rewrite trims toward *why*.

### 14.4 Naming & proximity

- Precise names over `data`/`process`/`manager`. Interconnected classes sit together: the detail page's subcomponents
  live under `Filament/Pages/InspectKeycloakUser/` (§3), not a type-first `Livewire/` bucket.
- A class reads top-to-bottom: public entry points (constructor/named constructors, `table()`/`mount()`/`boot()`) first,
  private plumbing below.
- **Tree depth signals importance.** A file's folder level advertises how central it is: the primary, most-general class
  sits at the top of its package; impl details are pushed **deeper** so the layout tells the truth. A support type at the
  top level over-promises. E.g. `KeycloakRecord` — a Filament-boundary wrapper, not a domain concept — lives in
  `Filament/Helpers/`, not top-level `Filament/`; DTOs live in `Dto/`; the page's subcomponents nest under the page.

### 14.5 Value objects & immutability

- No bare strings/ints for domain concepts — use the lib's value objects (`KeycloakUserId`, `KeycloakTimestamp`) and typed
  collections, never raw `array`. Livewire props are the one forced exception: a `public string $userId` is required for
  serialization, wrapped into `KeycloakUserId` at use — acceptable, noted so it is not read as primitive obsession.
- Behavior lives on the DTO that owns the data (e.g. label/format helpers on the DTOs), not scattered across the UI.

### 14.6 Modern PHP & clean evolution

- Union types, enums, `readonly`, `match`, constructor promotion. No `mixed`/untyped params, no `@param`-only typing.
- **Refactor; never keep the old path.** No `*_v2`, no `if ($legacy)`, no compatibility shims — change behavior and delete
  the old code, updating all callers in one pass. (This whole plan is that discipline applied.)

### 14.7 Tests: TDD, end-to-end

- Tests are written **first** (red → green → refactor) and land **with** the change, not bolted on after.
- They exercise real behavior through the **public entry point** — `Livewire::test(...)`, the E2E suite against a real
  Keycloak (§11). A deep module gives a small, stable test surface; use it.

### 14.8 Say what something IS, not what it is not

- Prose, comments, docblocks, commit messages, and names state the **affirmative**: what a thing *is* and *does*, not
  what it lacks. `Kept consistent with the code.` beats `Kept consistent — no build log, no history.` A reader learns from
  a positive fact; a list of absences leaves them guessing what remains.
- Applies to code too: a name/comment says the property that holds (`readonly`, `AlwaysValidEmail`), not the one it
  avoids. Reserve negatives for genuine constraints/invariants that carry weight (`password is never removable here`,
  `no identity fallback`) — there the absence *is* the point.
