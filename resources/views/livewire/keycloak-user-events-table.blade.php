<div>
    @keycloakboundary('Events unavailable — you are not signed in to Keycloak or lack the "view-events" role (or events are not enabled for this realm). See the application log.')
        {{ $this->table }}
    @endkeycloakboundary
</div>
