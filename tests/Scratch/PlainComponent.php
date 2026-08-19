<?php

declare(strict_types=1);

namespace Sandstorm\FilamentKeycloakAdmin\Tests\Scratch;

use Livewire\Component;

final class PlainComponent extends Component
{
    public function render(): string
    {
        return '<div>hello-plain</div>';
    }
}
