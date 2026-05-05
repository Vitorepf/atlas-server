<?php

namespace App\Services\Ai\Kernel\Surface;

use App\Services\Ai\Kernel\Envelope\KernelInput;

interface SurfaceAdapter
{
    public function surfaceId(): string;

    /**
     * @return array<int,string>
     */
    public function supportedCapabilities(): array;

    /**
     * @param  array<string,mixed>  $payload
     */
    public function normalizeInput(array $payload): KernelInput;

    /**
     * @param  array<string,mixed>  $output
     * @param  array<string,mixed>  $context
     * @return array<string,mixed>
     */
    public function renderOutput(array $output, array $context = []): array;

    /**
     * @return array{ok:bool,errors:array<int,string>}
     */
    public function complianceReport(): array;
}
