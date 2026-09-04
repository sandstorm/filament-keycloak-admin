<?php

declare(strict_types=1);

// Translations for sandstorm/filament-keycloak-admin.
return [
    'users' => [
        'empty' => [
            'heading' => 'Keine Benutzer gefunden',
        ],
    ],
    // Shared across every section that degrades gracefully on a failed Keycloak read — see
    // Filament\Concerns\InteractsWithKeycloakReads.
    'load_error' => [
        'heading' => 'Daten konnten nicht von Keycloak geladen werden',
        'unreachable' => 'Keycloak konnte nicht erreicht werden. Bitte die Verbindung prüfen und es erneut versuchen.',
        'forbidden' => 'Die Verbindung zu Keycloak wurde als nicht zulässig abgelehnt. Bitte die konfigurierten Zugangsdaten und deren Realm-Management-Rollen prüfen.',
        'server_error' => 'Keycloak hat einen Serverfehler zurückgegeben (HTTP :status). Bitte in Kürze erneut versuchen oder die Keycloak-Logs prüfen.',
        'unexpected' => 'Keycloak hat eine unerwartete Antwort zurückgegeben (HTTP :status).',
    ],
    // Shared across every section that reads Keycloak in `sso` (act-as-user) mode — see
    // Auth\FilamentSsoTokenProvider.
    'sso_auth_error' => [
        'heading' => 'Ungültige Keycloak-SSO-Sitzung',
        'message' => 'Ihre Keycloak-SSO-Sitzung konnte nicht gefunden oder erneuert werden bzw. ist nicht mehr gültig.',
    ],
];
