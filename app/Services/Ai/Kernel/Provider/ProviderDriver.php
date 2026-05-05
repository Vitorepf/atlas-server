<?php

namespace App\Services\Ai\Kernel\Provider;

interface ProviderDriver
{
    public function providerId(): string;

    /**
     * @return array<int,string>
     */
    public function supportedModels(): array;

    /**
     * @param  array<string,mixed>  $prompt
     * @param  array<string,mixed>  $context
     * @return array<string,mixed>
     */
    public function prepareRequest(array $prompt, array $context = []): array;

    /**
     * @param  array<string,mixed>  $request
     * @param  array<string,mixed>  $context
     * @return array<string,mixed>
     */
    public function execute(array $request, array $context = []): array;

    public function identityFragment(): IdentityFragment;
}
