<?php

declare(strict_types=1);

namespace Sandstorm\FilamentKeycloakAdmin\Http;

use GuzzleHttp\HandlerStack;
use Sandstorm\FilamentKeycloakAdmin\FilamentKeycloakAdminServiceProvider;

/**
 * Optional customization point for the Guzzle `HandlerStack` backing this package's Keycloak Admin API
 * client (token requests included). Bind an implementation in the host app's own service provider to add
 * middleware (e.g. request/response logging):
 *
 *     $this->app->bind(KeycloakAdminHttpHandlerStackCustomizer::class, MyHandlerStackCustomizer::class);
 *
 * When bound, {@see FilamentKeycloakAdminServiceProvider::buildHttpClient()} resolves it and passes the
 * client's handler stack through {@see customizeHandlerStack()} once, while building the client — return
 * the stack to use (typically the same instance, with middleware pushed onto it).
 *
 * The client itself carries no logging middleware of its own: token requests carry the client secret and
 * admin responses carry user PII, so redaction is the host's responsibility to get right for its own
 * environment — this package will not guess at it.
 */
interface KeycloakAdminHttpHandlerStackCustomizer
{
    public function customizeHandlerStack(HandlerStack $handlerStack): HandlerStack;
}
