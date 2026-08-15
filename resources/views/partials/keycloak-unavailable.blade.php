{{-- Fallback rendered by @keycloakboundary when a Keycloak call is not authorized (401/403). --}}
<div class="text-sm text-gray-500 dark:text-gray-400">
    {{ $message !== '' ? $message : 'Unavailable — you are not signed in to Keycloak or lack permission. See the application log.' }}
</div>
