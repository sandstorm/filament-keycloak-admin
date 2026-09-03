<?php

declare(strict_types=1);

namespace Sandstorm\FilamentKeycloakAdmin\Http;

enum KeycloakHttpClientName
{
    case KEYCLOAK_TOKEN_PROVIDER;
    case KEYCLOAK_TRANSPORT;
}
