<?php

namespace Broodfonds\KeycloakFilamentAdmin\Facades;

use Illuminate\Support\Facades\Facade;

/**
 * @see \Broodfonds\KeycloakFilamentAdmin\KeycloakFilamentAdmin
 */
class KeycloakFilamentAdmin extends Facade
{
    protected static function getFacadeAccessor(): string
    {
        return \Broodfonds\KeycloakFilamentAdmin\KeycloakFilamentAdmin::class;
    }
}
