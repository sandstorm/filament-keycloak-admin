# User write actions + impersonation — concept

**Date:** 2026-08-19 · Extends the initial plan (`2026-08-12-keycloak-filament-extension-initial-plan.md`)
and the client-lib concept (`keycloak-admin-api/docs/standalone-keycloak-api-package.md`).

Adds **write** capability to the today-read-only user detail page, and **impersonation**. every write must honour the
**individual admin's** Keycloak permissions (FGAP), which forces the **`sso` act-as-user** auth mode — sketched in both
prior docs, **but not built yet**.

---

## 1. Features in scope

Per user, on `InspectKeycloakUser` (the detail page):

| # | Feature                               | Keycloak endpoint                                                 | Kind             |
|---|---------------------------------------|-------------------------------------------------------------------|------------------|
| 1 | Activate / deactivate user            | `PUT /users/{id}` (`enabled`)                                     | user write       |
| 2 | Change first / last name              | `PUT /users/{id}` (`firstName`/`lastName`)                        | user write       |
| 3 | Toggle email-verified                 | `PUT /users/{id}` (`emailVerified`)                               | user write       |
| 4 | Edit custom attributes *(maybe — §5)* | `PUT /users/{id}` (`attributes`)                                  | user write       |
| 5 | Reset password                        | email link **(exists)** + direct `PUT /users/{id}/reset-password` | credential write |
| 6 | Impersonate into an application       | `POST /users/{id}/impersonation` **or** token-exchange (§6)       | session          |

Features 1–4 are the **same endpoint** — one read-modify-write `PUT /users/{id}` (§4.1). Group them into one
"Edit identity" surface, not four separate calls.

---

## 2. FGAP prerequisite — `sso` act-as-user (build FIRST)

**Why.** `service_account` mode is one shared, coarse identity: every write is attributed to the service account and
authorised by *its* roles. Honouring **per-admin** permissions (FGAP = Fine-Grained Admin Permissions) means the call
must carry the **logged-in admin's own token** so Keycloak evaluates *that person's* grants and attributes the
admin-event to *that person*. That is exactly `sso` mode.

The client lib already has the seam: `KeycloakTokenProvider::currentBearer()` + the transport injects whatever bearer it
is handed — **no lib change needed for auth**. What's missing is the plugin-side provider.

**Build `Auth\FilamentSsoTokenProvider implements KeycloakTokenProvider`** (plugin, Laravel-coupled — never the lib;
initial plan §6):

- Reads the logged-in admin's Keycloak access token; refreshes via stored `refresh_token` near expiry.
- Wire into the ServiceProvider `auth_mode` match: `'sso' => new FilamentSsoTokenProvider(...)`, replacing today's
  `throw` (`FilamentKeycloakAdminServiceProvider.php:81`).
- **Deployment requirement:** the panel's OIDC client must issue tokens carrying `realm-management` roles + audience. If
  absent → 403, **no fallback** to the service account.

### 2.1 Token-source seam — heloufir in prod, faked in tests (decided)

The admin's KC token lives where the panel's login lib put it. This deployment uses
**`heloufir/filament-keycloak-sso`** (installed, inspected): it stores `access_token` + `refresh_token`
(no expiry) in a `keycloak_sessions` row keyed by the Filament user's email, and refreshes via a
`grant_type=refresh_token` POST using the OIDC client creds in `filament-keycloak-sso.*` config.

**Constraint:** heloufir may be a **prod** dependency, but the **plugin's test suite must not depend on
it.** Resolved with a seam:

```php
// src/Auth/AdminKeycloakSession.php  — the seam (no heloufir, no Laravel-auth types)
interface AdminKeycloakSession {
    public function tokens(): ?AdminKeycloakTokens;   // current admin's stored tokens; null = no KC session
    public function refresh(): ?AdminKeycloakTokens;  // force a refresh via the issuing client, persist, return the new pair; null if none/failed
}
// AdminKeycloakTokens: final readonly { string $accessToken; string $refreshToken; }
```

**Refresh lives behind the seam so the prod adapter can *reuse heloufir's own refresh*, while the tested
provider stays heloufir- and HTTP-free.** The provider does no HTTP at all now.

- **`FilamentSsoTokenProvider`** depends on **only the seam**. Its whole logic: `tokens()` → if the access
  token's JWT `exp` is still valid (safety margin) return it; else `refresh()`; if that yields a valid token
  return it; **no session, or refresh fails/returns an invalid token → throw loudly** (never a fallback to
  another identity). Pure, fully unit-testable with a fake seam — no PSR client, no settings.
- **`HeloufirAdminKeycloakSession implements AdminKeycloakSession`** — the *only* heloufir-coupled class, and
  now the home for refresh reuse. `tokens()` reads heloufir's public `KeycloakSession` Eloquent row
  (`email`, `access_token`, `refresh_token`) for `Filament::auth()->user()->email` — the one prod-reachable
  handle on the raw token (heloufir's own `roles()`/`keycloakUserRoles()` refresh then *discard* the token,
  returning only roles). `refresh()` **reuses heloufir**: it `use`s heloufir's public `HasKeycloakRoles` trait
  and calls `roles()`, whose documented side-effect is exactly a `refresh_token` grant against the issuing
  client that **rewrites the row** with the rotated pair; the adapter then re-reads the row and returns the
  fresh tokens. So the refresh grant is heloufir's code, not a copy. Prod-wired in the ServiceProvider;
  **never referenced by any test** (arch check).
- **Tests fake the seam:** unit tests inject an in-memory `AdminKeycloakSession` returning crafted JWTs and a
  preset refresh result; the E2E seeds `tokens()` with a **real** user token obtained via the `e2e-login`
  direct-grant client — proving the provider + transport + real KC honour a *user* identity, with zero
  heloufir dependency.

**Capability reflection — answers "show me what I can edit *before* I try."** Keycloak's `GET /users/{id}`
returns a caller-relative **`access`** map — `{manage, manageGroupMembership, mapRoles, impersonate, view}` —
that KC computes **for the calling identity**. Under `sso` it is *this admin's* effective FGAP grants. So:

- Add an `access` map to `KeycloakUser` (lib, tolerant parse; absent → empty/all-false).
- UI drives enable/disable + visibility of each write action off `user.access.*` (`manage` → edit/activate,
  `impersonate` → impersonate, …). **This is not client-side FGAP logic** — KC is still the authority; the UI
  merely *reflects* the authority's own answer. No permission is re-implemented or hard-coded.
- **Authorise-by-attempt stays only as the race backstop:** a write can still 403 (perms changed since load);
  writes catch `403/401` → friendly "not permitted" notice rather than the framework error page (a scoped
  exception to initial-plan §8; reads still propagate). Taxonomy in §7.

> Without `sso`, features 1–6 still *function* under `service_account`, but every action runs as the shared
> identity — no per-admin enforcement, no per-person audit. Acceptable only as an interim.

---

## 3. Library changes (`sandstorm/keycloak-admin-api`)

Fills the "user-write DTOs" gap tracked in the standalone concept §8. Feature-first: new DTOs sit beside their API.

### 3.1 `KeycloakUsersApi::update()`

```php
public function update(KeycloakUserId $id, UpdateKeycloakUserCommand $changes): void;  // PUT /users/{id}
```

Covers features 1–4. New DTO `Features/KeycloakUsersApi/Dto/UpdateKeycloakUserCommand.php` — a `final readonly`
command holding **only the mutable fields** as nullable "leave-unchanged unless set" values:
`enabled`, `firstName`, `lastName`, `emailVerified`, `attributes`. Named constructors keep call sites honest
(`::enable()`, `::disable()`, `::withNames(...)`, `::withEmailVerified(bool)`, `::withAttributes(...)`).

**Read-modify-write, expressed through the existing `KeycloakUser` DTO (decided).** Keycloak's
`PUT /users/{id}` replaces the representation — a partial body **wipes** omitted fields (attributes
especially). So the merge round-trips through the typed DTO, not raw-array poking:

1. `getById` → `KeycloakUser` (as today).
2. `KeycloakUser::applying(UpdateKeycloakUserCommand): KeycloakUser` — returns a **new** DTO with the
   command's set fields overridden (immutable; unset command fields leave the DTO field untouched).
3. `KeycloakUser::toRepresentation(): array` → the PUT body; impl calls `transport->putJson`.

**Losslessness is mandatory and is the real logic worth testing.** The modelled DTO is a *subset* of
Keycloak's `UserRepresentation`; a naive `toRepresentation()` built only from modelled fields would drop
everything unmodelled (`createdTimestamp` is read-only, but future/unknown fields would silently vanish). So
`fromRawResponse()` **retains the original raw representation**, and `toRepresentation()` overlays only the
modelled fields onto that retained raw — modelled fields authoritative, everything else passed through
verbatim. Unit tests assert: (a) unknown/unmodelled fields survive the round-trip, (b) each command field
maps correctly, (c) an all-null command is a no-op body.

### 3.2 Reset password — email-link only (decided)

**No direct set-password.** Keep initial-plan §10: the only password path is the existing
`executeActionsEmail(['UPDATE_PASSWORD'])` — Keycloak mails the user a time-limited link; the admin never sees
or sets a password. No `resetPassword()` lib method, no password field in the UI. (Reverses the earlier
break-glass idea.)

### 3.3 Impersonation — a browser flow, **not** a server API call

**Goal:** admin clicks a button → admin's *browser* is logged into the **target application as that user**, no
password. This is fundamentally a browser-redirect flow; a server-side Admin-API call cannot deliver it (§6),
so there is **no `impersonate()` returning data to the server**. Instead the plugin ships a **redirect
endpoint** that drives the admin's browser through Keycloak's impersonation and on to the target app's OIDC
login. Lib involvement is minimal (possibly none). Full mechanism + requirements in §6; **build last, pending
the §7.1 target-app decision.**

### 3.4 Tests

Unit: `UpdateKeycloakUserCommand` / `KeycloakUser::applying()` merge + `toRepresentation()` losslessness (the
real logic). E2E: see the two-realm matrix in §3a.

---

## 3a. Testing matrix — FGAP **off** vs **on** (two realms, decided)

Every write + capability behaviour must be proven under **both** Keycloak authorization modes, because they
take different code paths at the server and we ship both. So the E2E suites (both the lib's and the plugin's)
run against **two seeded realms**, same KC instance (Keycloak `--import-realm` imports every JSON in the dir):

| Realm | Admin Permissions (FGAP) | Authorises by | Proves |
|-------|--------------------------|---------------|--------|
| **`test-realm`** (exists) | **Off** | classic `realm-management` roles | writes work; a caller with roles gets `user.access.manage = true` for everyone; a caller lacking a role gets a blanket 403 |
| **`test-realm-fgap`** (new) | **On** | scoped policies per user/group | a **scoped** admin gets `user.access.manage = true` for *permitted* users and **false** for others; a write to a permitted user 2xx's, to a forbidden user **403**s — same code, per-user outcome |

Seed in `test-realm-fgap`: FGAP enabled; a `scoped-admin` user granted `manage`/`view` on group **/staff**
only; `jane` in `/staff` (editable), `bob` outside it (read-only-to-scoped-admin); the OIDC/direct-grant client
so a **user** bearer can be obtained.

**How each layer injects the identity under test (no heloufir in either):**
- **Lib E2E** — inject a tiny `KeycloakTokenProvider` that direct-grants the `scoped-admin` (or a role-holder)
  and hands the transport that bearer. Proves the wire contract honours FGAP purely at the API layer.
- **Plugin E2E** — seed the faked `AdminKeycloakSession` (§2.1) with that same user token, `auth_mode=sso`.
  Proves the provider → transport → capability-gated UI path end-to-end.

Parameterise the E2E base class by realm (`KEYCLOAK_E2E_REALM`) so each behaviour test runs twice (off/on) via
a data provider or paired subclasses. The **off** realm is the regression net that FGAP support didn't break
classic role auth; the **on** realm proves per-admin enforcement + the `user.access` reflection.

---

## 4. Plugin UI changes (`InspectKeycloakUser`)

- **Identity tab → editable.** `KeycloakUserIdentity` gains an **Edit** action (Filament form action): first name, last
  name, email-verified toggle, enabled toggle → one `usersApi->update()` call. Attributes editing (§5) is a repeater in
  the same form if kept.
- **Activate / deactivate** — a header action (or the enabled toggle in Edit); `requiresConfirmation` for deactivate.
  Dispatches `keycloak-user-changed` so sibling tabs refresh (initial-plan §7.2 cross-tab signal).
- **Reset password** — a header action, modal with two choices: *(a)* "Send reset email" (existing
  `executeActionsEmail`, default/recommended) and *(b)* "Set password directly" (§3.2), password field + temporary
  toggle, `requiresConfirmation`, explicit warning.
- **Impersonate** — header action; behaviour per §6.

All write actions catch `UnexpectedKeycloakResponseException` with `statusCode` 401/403 → friendly "You do not have
permission" notice (§2); other failures still propagate.

---

## 5. Custom attributes — driven by Keycloak User Profile (decided)

**No free-form key/value editing.** This realm doesn't allow arbitrary attributes, and neither should the UI —
it mirrors Keycloak. Keycloak's **User Profile** feature is the declarative schema: which attributes exist,
their type, validators, required-ness, and per-attribute **view/edit permissions** (`admin` vs `user`). The KC
admin console renders its user form straight from it; the plugin does the same.

- **New lib feature `KeycloakRealmApi::getUserProfile(): KeycloakUserProfile`** (`GET /realms/{realm}/users/profile`)
  — the missing feature tracked in standalone §8.5. Parses the attribute list with each attribute's
  `validations`, `required`, and `permissions`.
- **UI renders attribute fields from that config**, not a repeater: only attributes whose permissions grant the
  caller `edit` are editable; validators map to Filament field rules; unlisted/managed attributes are never
  touched (the §3.1 lossless round-trip preserves them). "Like in Keycloak."
- Combined with the `user.access` capability map (§2.1), the form shows exactly what this admin may change.
- **Slice ordering:** ships *after* the identity-field writes (features 1–3), since it needs the new
  `KeycloakRealmApi`. Read-only display of profile-defined attributes can land earlier.

---

## 6. Impersonation — how it actually works

Goal restated: **click → the admin's browser is logged into the target application as the user, no password.**
The impersonated identity must live in the **admin's browser's** Keycloak SSO session — which is why no
server-side Admin-API call alone achieves it.

### 6.1 How Keycloak's own Impersonate button works (observed)

**Observed behaviour:** clicking Impersonate opens a **new window** at the **Account console**, logged in as the
*target* user, while the original **admin console stays logged in as the admin**. Two sessions coexist.

**Mechanism (to confirm empirically against 26.5.3 in the E2E KC — earlier draft overstated this):** the admin
console is served from Keycloak's own origin (`/admin/`) and does
`POST /admin/realms/{realm}/users/{id}/impersonate` with the admin's bearer. Same-origin, so the response's
`Set-Cookie` (`KEYCLOAK_IDENTITY`/`KEYCLOAK_SESSION` for the **target** user) lands in the browser — a
**cookie-plant**. Crucially it is *non-destructive to the admin console*: that SPA authenticates with its own
in-memory bearer tokens (`security-admin-console` client), not the SSO cookie, so overwriting the cookie doesn't
log it out. The new window then does a normal OIDC handshake, reads the new cookie, and resolves to the target
user. Hence: token-based admin session (untouched) **and** cookie-based target session (new window) side by side.

**What this means for us — promising.** Our Filament panel session is a *Laravel* cookie, independent of the KC
SSO cookie, so it survives an impersonation cookie-plant exactly like the admin console does. So "Impersonate"
could be: plant the target SSO cookie in the admin's browser, then open the **target app** in a new window →
its OIDC login resolves to the target user, admin panel untouched. The one hard part is **getting the
`Set-Cookie` into the browser**: our panel is a *different origin* from Keycloak, so a server-side call plants
the cookie on the server (useless) and a cross-origin browser call hits third-party-cookie/CORS limits. Solving
that (a reverse-proxy path that serves Keycloak under the panel's domain, so the impersonate step is
first-party) is the crux — a deployment concern, not lib code.

### 6.2 Do it exactly like the console — the recommended path

We *can* reproduce 6.1. The only thing that made it look un-portable is the **origin boundary**, and that is a
deployment choice, not a protocol limit: the third-party-cookie block applies to cross-*site* embedded/XHR
contexts, **not** when Keycloak is served **same-site with the panel**. Front both under one parent domain —
reverse-proxy KC at `panel.example.com/auth`, or share `*.example.com` — and a browser-side call to KC's
impersonate endpoint is **first-party**; its `Set-Cookie` plants in the admin's browser just like the console's.

And we hold an advantage the console has: **we already have the admin's bearer** — that is exactly what
`FilamentSsoTokenProvider` (§2) produces. So the feature is:

1. **Impersonate** action → a small front-end (Alpine/JS) `fetch` to the same-site impersonate endpoint with the
   admin's bearer + `credentials: 'include'` (mirrors what the console does).
2. On success, `window.open()` the **target application** (or the account console) in a new tab. Its OIDC login
   reads the freshly-planted cookie → target user.
3. The admin's **panel** session is a separate Laravel cookie and is untouched — same "stay admin here, be the
   user there" split the console shows.

No lib `impersonate()` data call (§3.3 removed it); the bearer + a route are all that's needed.

**`impersonate` is public API.** `POST /admin/realms/{realm}/users/{id}/impersonate` is a documented, stable
Admin REST endpoint (`UserResource`) — requires the `impersonation` permission, returns `{sameRealm, redirect}`
plus the target-user `Set-Cookie`. Not console-internal; the console is just its best-known caller. (Confirm the
exact 26.5.3 response/cookie shape empirically against the docker KC.)

**Origin nuance — "same parent domain" is not enough on its own:**
- **Subdomain** (`panel.example.com` → `auth.example.com`): same-*site* (cookies fine with `SameSite=Lax/None`)
  but still cross-*origin*, so the browser `fetch` needs **CORS** — add the panel origin to the OIDC client's
  **Web Origins** in Keycloak.
- **Same origin via path-proxy** (`panel.example.com/auth` → KC): no CORS, cookie fully first-party. Cleanest;
  prefer this if the reverse proxy allows.

### 6.3 Token exchange — fallback only

If sharing a site with Keycloak is genuinely impossible, `grant_type=token-exchange` with
`requested_subject=<targetUserId>` + `audience=<target-client>` mints tokens acting as the target user, scoped
to an app — but it yields *tokens, not a browser cookie*, so it only helps for an app **we control** that has a
token-accepting login seam. Heavier and narrower; avoid unless 6.2 is ruled out.

### 6.4 Recommendation

**Go 6.2 — replicate the console.** The decision reduces to one deployment question (now §7.1): **is Keycloak
same-site with the panel, or can we reverse-proxy it so?** Almost always yes. Prototype the browser-side
impersonate `fetch` + `window.open` against the dockerized KC (proxied same-site) and confirm the 26.5.3
cookie/redirect behaviour empirically before building the action.

---

## 7. Open questions

**Resolved:** set-password → **email-link only** (§3.2); attributes → **User Profile-driven, no free-form**
(§5); "see what I can edit" → **`user.access` capability map** (§2.1); heloufir → **prod adapter behind a seam,
faked in tests** (§2.1); impersonation lib call → **dropped** (§3.3/§6).

**Still open:**

1. **Impersonation origin topology (§6.4)** — is Keycloak same-site with the panel, or can we reverse-proxy it
   under the panel's domain? If yes → replicate the console (6.2), the recommended path. Also: which target
   app(s) does the new window open into? Blocks all impersonation work. **Top question.**
2. **Write failure taxonomy** — confirm: writes catch 401/403 → friendly notice; everything else propagates;
   reads unchanged (scoped exception to initial-plan §8).
3. **`sso` deployment prereq** — confirm heloufir's OIDC client issues tokens carrying `realm-management`
   roles + audience, else FGAP writes 403 with no fallback (initial-plan §6/§15).
4. **Interim under `service_account`** — ship writes before `sso` lands (coarse identity, no per-admin audit),
   or hard-block writes until `sso`?

## 8. Sequencing — every slice TDD (E2E-focused, against real KC in the API layer)

1. **`FilamentSsoTokenProvider` + `AdminKeycloakSession` seam** (§2/§2.1) — unit: JWT-validity + refresh +
   throw-loud (fake seam, **no heloufir**); E2E: seam seeded with a real `e2e-login` user token drives a live
   read as that identity. *Foundation.* ← **current slice.**
2. **Lib: `KeycloakUser.access` + `.applying()` + `.toRepresentation()` + `UsersApi::update`** (§3.1) — unit:
   lossless round-trip + command merge; E2E: enable/disable, rename, emailVerified, **unmodelled fields
   survive**.
3. **Plugin: editable Identity + activate/deactivate**, capability-gated off `user.access` (§4/§2.1).
4. **Lib: `KeycloakRealmApi::getUserProfile`** (§5) — E2E parse of real profile config.
5. **Plugin: attribute fields rendered from User Profile** (§5).
6. **Impersonation** (§6) — blocked on §7.1; last.

`HeloufirAdminKeycloakSession` (the prod adapter) is wired in the ServiceProvider and covered by manual/prod
verification only — never by the test suite (arch check).
