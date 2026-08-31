<?php

declare(strict_types=1);

namespace Sandstorm\FilamentKeycloakAdmin\Http;

use GuzzleHttp\HandlerStack;

/**
 * Binding key only — resolves to the `GuzzleHttp\HandlerStack` backing the Guzzle client this package
 * uses for every Keycloak Admin API call (token requests included). The bound value is a real
 * `GuzzleHttp\HandlerStack` instance, not an instance of this class — this exists only so the host app has
 * a stable, collision-free container key to fetch it by (mirrors {@see
 * \Sandstorm\FilamentKeycloakAdmin\Logging\KeycloakAdminLogger}).
 *
 * Push your own middleware (e.g. request/response logging) onto it from the host app's own service
 * provider:
 *
 *     /** @var HandlerStack $stack *\/
 *     $stack = app(KeycloakAdminHttpHandlerStack::class);
 *     $stack->push($myLoggingMiddleware, 'http-logging');
 *
 * Do this any time before the panel makes its first Keycloak Admin API call — Guzzle resolves the
 * middleware chain per request, not at stack-construction time, so pushing onto it later (e.g. in a
 * provider's `boot()`) still takes effect for every request from then on.
 *
 * The client itself carries no logging middleware of its own: token requests carry the client secret and
 * admin responses carry user PII, so redaction is the host's responsibility to get right for its own
 * environment — this package will not guess at it.
 */
final class KeycloakAdminHttpHandlerStack {}
