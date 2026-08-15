<?php

// config for Broodfonds/KeycloakFilamentAdmin
return [

    // Connection — read by the plugin's KeycloakSettingsProvider impl and handed to the shared adapter.
    'connection' => [
        'base_url' => env('KEYCLOAK_SERVICE_BASE_URL'),
        'realm' => env('KEYCLOAK_REALM'),
        'client_id' => env('KEYCLOAK_SERVICE_CLIENT_ID'),         // service-account client (service_account mode)
        'client_secret' => env('KEYCLOAK_SERVICE_CLIENT_SECRET'),
    ],

    // 'sso' (act-as-user) | 'service_account' — explicit, no fallback. Only service_account is wired today.
    'auth_mode' => env('KEYCLOAK_ADMIN_AUTH_MODE', 'service_account'),

    // HTTP client timeouts (seconds) — surface "Keycloak unreachable" quickly rather than hanging the panel.
    'http' => [
        'connect_timeout' => env('KEYCLOAK_ADMIN_CONNECT_TIMEOUT', 5),
        'timeout' => env('KEYCLOAK_ADMIN_TIMEOUT', 15),
    ],

    // Trigger-password-reset-email action (execute-actions-email UPDATE_PASSWORD) — the preferred,
    // admin-never-sees-the-password reset path (§5.4). Requires realm SMTP.
    'pw_reset' => [
        'lifespan' => env('KEYCLOAK_PW_RESET_LIFESPAN', 43200), // link validity, seconds (12h default)
        'client_id' => env('KEYCLOAK_PW_RESET_CLIENT_ID'),      // optional: where the user lands afterwards
        'redirect_uri' => env('KEYCLOAK_PW_RESET_REDIRECT_URI'),
    ],

];
