<?php

declare(strict_types=1);

// Translations for sandstorm/filament-keycloak-admin.
return [
    'users' => [
        'empty' => [
            'heading' => 'No users found',
        ],
    ],
    // Shared across every section that degrades gracefully on a failed Keycloak read — see
    // Filament\Concerns\InteractsWithKeycloakReads.
    'load_error' => [
        'heading' => 'Could not load data from Keycloak',
        'unreachable' => 'Keycloak could not be reached. Check the connection and try again.',
        'forbidden' => 'The connection to Keycloak was rejected as not permitted. Check the configured credentials and their realm-management roles.',
        'server_error' => 'Keycloak returned a server error (HTTP :status). Try again shortly, or check the Keycloak logs.',
        'unexpected' => 'Keycloak returned an unexpected response (HTTP :status).',
    ],
];
