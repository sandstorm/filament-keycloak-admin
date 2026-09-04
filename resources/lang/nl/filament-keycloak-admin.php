<?php

declare(strict_types=1);

// Translations for sandstorm/filament-keycloak-admin.
return [
    'users' => [
        'empty' => [
            'heading' => 'Geen gebruikers gevonden',
        ],
    ],
    // Shared across every section that degrades gracefully on a failed Keycloak read — see
    // Filament\Concerns\InteractsWithKeycloakReads.
    'load_error' => [
        'heading' => 'Gegevens konden niet van Keycloak worden geladen',
        'unreachable' => 'Keycloak kon niet worden bereikt. Controleer de verbinding en probeer het opnieuw.',
        'forbidden' => 'De verbinding met Keycloak werd geweigerd omdat deze niet is toegestaan. Controleer de geconfigureerde inloggegevens en de bijbehorende realm-management-rollen.',
        'server_error' => 'Keycloak heeft een serverfout geretourneerd (HTTP :status). Probeer het straks opnieuw of controleer de Keycloak-logs.',
        'unexpected' => 'Keycloak heeft een onverwachte reactie geretourneerd (HTTP :status).',
    ],
    // Shared across every section that reads Keycloak in `sso` (act-as-user) mode — see
    // Auth\FilamentSsoTokenProvider.
    'sso_auth_error' => [
        'heading' => 'Ongeldige Keycloak-SSO-sessie',
        'message' => 'Uw Keycloak-SSO-sessie kon niet worden gevonden of vernieuwd, of is niet meer geldig.',
    ],
];
