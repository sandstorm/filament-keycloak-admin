<?php

declare(strict_types=1);

namespace Sandstorm\FilamentKeycloakAdmin\Tests\Fixtures;

use GuzzleHttp\HandlerStack;
use GuzzleHttp\Promise\Create;
use GuzzleHttp\Psr7\Response;
use Psr\Http\Message\RequestInterface;
use Sandstorm\FilamentKeycloakAdmin\Http\KeycloakHttpClientName;
use Sandstorm\FilamentKeycloakAdmin\Http\KeycloakAdminHttpHandlerStackCustomizer;

/**
 * A {@see KeycloakAdminHttpHandlerStackCustomizer} that records how the extension point is invoked and
 * pushes a middleware short-circuiting every request with a canned 200 response — so a test can prove the
 * package actually hands over a live, functioning `HandlerStack` (not just calls the method) without
 * making a real HTTP call.
 */
final class RecordingHandlerStackCustomizer implements KeycloakAdminHttpHandlerStackCustomizer
{
    public int $callCount = 0;

    public ?HandlerStack $receivedStack = null;

    /**
     * @var list<KeycloakHttpClientName>
     */
    public array $receivedClientNames = [];

    /**
     * @var array<string, HandlerStack> keyed by {@see KeycloakHttpClientName::name}
     */
    public array $receivedStacksByClientName = [];

    /**
     * @var list<RequestInterface>
     */
    public array $interceptedRequests = [];

    public function customizeHandlerStack(HandlerStack $handlerStack, KeycloakHttpClientName $clientName): HandlerStack
    {
        $this->callCount++;
        $this->receivedStack = $handlerStack;
        $this->receivedClientNames[] = $clientName;
        $this->receivedStacksByClientName[$clientName->name] = $handlerStack;

        $handlerStack->push(function (callable $handler) {
            return function (RequestInterface $request, array $options) {
                $this->interceptedRequests[] = $request;

                return Create::promiseFor(new Response(200, [], 'ok'));
            };
        }, 'test-recorder');

        return $handlerStack;
    }
}
