<?php

namespace App\Wiring;

final class Hub
{
    public function boot(): void
    {
        new \App\Scope\Callee();
        new \App\Scope\Caller();
        new \App\Scope\CloneOne();
        new \App\Scope\CloneTwo();
        new \App\Scope\Widget();
    }
}
