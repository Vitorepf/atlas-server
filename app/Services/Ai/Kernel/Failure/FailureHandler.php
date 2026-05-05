<?php

namespace App\Services\Ai\Kernel\Failure;

interface FailureHandler
{
    public function domain(): FailureDomain;

    /**
     * @param  array<string,mixed>  $failure
     * @param  array<string,mixed>  $context
     * @return array<string,mixed>
     */
    public function handle(array $failure, array $context = []): array;
}
