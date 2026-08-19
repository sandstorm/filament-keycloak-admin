<?php

declare(strict_types=1);

/**
 * Structure-only stub (plan §9). The plugin reads resolved `config('filament-keycloak-admin.*')` but
 * ships no deployment specifics and never calls env() — the consuming app owns the authoritative file
 * with real values (e.g. `admin/config/filament-keycloak-admin.php`). These null defaults document the
 * shape; every connection key is required at runtime and fails loudly when unset.
 */
return [

    // Read by ConfigKeycloakSettingsProvider and handed to the client library's transport.
    'connection' => [
        'base_url' => null,       // e.g. https://keycloak.example/
        'realm' => null,          // the Keycloak realm name
        'client_id' => null,      // service-account client (service_account mode)
        'client_secret' => null,  // the app decides how it sources this
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
