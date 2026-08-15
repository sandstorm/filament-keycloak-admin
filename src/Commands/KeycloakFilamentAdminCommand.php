<?php

namespace Broodfonds\KeycloakFilamentAdmin\Commands;

use Illuminate\Console\Command;

class KeycloakFilamentAdminCommand extends Command
{
    public $signature = 'keycloak-filament-admin';

    public $description = 'My command';

    public function handle(): int
    {
        $this->comment('All done');

        return self::SUCCESS;
    }
}
