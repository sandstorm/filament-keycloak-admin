<?php

namespace Sandstorm\FilamentKeycloakAdmin\Facades;

use Illuminate\Support\Facades\Facade;

/**
 * @see \Sandstorm\FilamentKeycloakAdmin\KeycloakFilamentAdmin
 */
class KeycloakFilamentAdmin extends Facade
{
    protected static function getFacadeAccessor(): string
    {
        return \Sandstorm\FilamentKeycloakAdmin\KeycloakFilamentAdmin::class;
    }
}
