<x-filament-panels::page>
    @if ($this->keycloakUserUnavailable())
        {{-- Single page-level gate: if the user can't be fetched (401/403), the whole detail view is
             unauthorized — so we show one notice instead of the tabs, and the view-users-gated tabs need
             no boundary of their own. --}}
        @include('keycloak-filament-admin::partials.keycloak-unavailable', [
            'message' => 'This user is unavailable — you are not signed in to Keycloak or lack the "view-users" role. See the application log.',
        ])
    @else
        {{ $this->detailSchema }}
    @endif
</x-filament-panels::page>
