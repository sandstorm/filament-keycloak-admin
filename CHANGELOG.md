# Changelog

All notable changes to `filament-keycloak-admin` will be documented in this file, following
[Keep a Changelog](https://keepachangelog.com/en/1.0.0/).

## Unreleased

### Added

- Audit logging (info level) for every write action: enabling/disabling a user, editing identity fields,
  group add/remove, sending a password-reset email, removing a 2FA credential, and logging out all
  sessions. A write denied by Keycloak (401/403) logs at warning level instead of only showing a UI
  notice. Every log line is a static message plus a context array (action name, acting admin id, target
  user id, and other non-PII identifiers) — never request bodies or interpolated PII.
- `Sandstorm\FilamentKeycloakAdmin\Logging\KeycloakAdminLogger` — an optional container binding that lets
  a host app hand this package its own PSR-3 logger instance for the audit log above, instead of only
  selecting a channel by name.
- `filament-keycloak-admin.logging.channel` config key — the log channel used when the host app hasn't
  bound `KeycloakAdminLogger` (defaults to the app's default channel).
- `Sandstorm\FilamentKeycloakAdmin\Http\KeycloakAdminHttpHandlerStack` — a container binding that exposes
  the Guzzle `HandlerStack` backing the Keycloak Admin API client, so a host app can push its own HTTP
  request/response logging middleware onto it. The client still carries no logging middleware by default
  and never will: token requests carry the client secret and admin responses carry user PII, so redaction
  stays the host's responsibility.
