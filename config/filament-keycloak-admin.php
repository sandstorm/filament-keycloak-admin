<?php

declare(strict_types=1);

/**
 * Structure-only stub (plan §9). The plugin reads resolved `config('filament-keycloak-admin.*')` but
 * ships no deployment specifics and never calls env() — the consuming app owns the authoritative file
 * with real values (e.g. `admin/config/filament-keycloak-admin.php`). These null defaults document the
 * shape; a required key that is unset fails loudly at runtime.
 */
return [

    /**
     * Read by ConfigKeycloakSettingsProvider and handed to the client library's transport.
     *
     * The three URLs use Keycloak's own hostname nomenclature (https://www.keycloak.org/server/hostname),
     * because a Keycloak instance may answer on a different address per channel: `backchannel_url` is what
     * this application dials directly (Admin REST API, token endpoint) and is often an internal address,
     * while `frontend_url` / `administration_url` are what the admin's *browser* would be sent to. The
     * latter two are optional and fall back to the backchannel URL, which is what a single-hostname
     * deployment wants.
     */
    'connection' => [
        'backchannel_url' => null,     // required, e.g. http://keycloak.internal:8080/
        'frontend_url' => null,        // optional, e.g. https://login.example/
        'administration_url' => null,  // optional, e.g. https://keycloak-admin.example/
        'realm' => null,               // the Keycloak realm name
        'client_id' => null,           // service-account client (service_account mode)
        'client_secret' => null,       // the app decides how it sources this
    ],

    // 'service_account' | 'sso' — explicit, no fallback. Only service_account is wired today.
    'auth_mode' => 'service_account',

    // HTTP client timeouts (seconds) — surface "Keycloak unreachable" quickly rather than hanging.
    'http' => [
        'connect_timeout' => 5,
        'timeout' => 15,
    ],

    // Send-password-reset-email action (execute-actions-email UPDATE_PASSWORD) — the admin never sees
    // or sets the password. Requires realm SMTP.
    'pw_reset' => [
        'lifespan' => 43200,      // link validity, seconds (12h)
        'client_id' => null,      // optional: where the user lands afterwards
        'redirect_uri' => null,
    ],

];
