<?php

namespace App\Services\Ai\Kernel\Pipeline;

use RuntimeException;

final class KernelPipelinePlanViolation extends RuntimeException
{
    /**
     * @param  array<int,string>  $errors
     */
    public function __construct(public readonly array $errors)
    {
        parent::__construct('Invalid Atlas kernel pipeline contract.');
    }
}
