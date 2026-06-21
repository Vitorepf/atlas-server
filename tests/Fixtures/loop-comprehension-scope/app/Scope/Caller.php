<?php

namespace App\Scope;

final class Caller
{
    public function compute(): int
    {
        return (new \App\Scope\Callee())->value() + 1;
    }
}
