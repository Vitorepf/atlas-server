<?php

namespace App\Services\Ai\Kernel\Failure;

final readonly class FailureHandlerRegistry
{
    public function __construct(
        private GenericFailureHandler $generic,
    ) {}

    public function handlerFor(FailureDomain $domain): FailureHandler
    {
        return $this->generic->forDomain($domain);
    }

    /**
     * @return array{ok:bool,missing:array<int,string>,count:int}
     */
    public function complianceReport(): array
    {
        $missing = [];

        foreach (FailureDomain::cases() as $domain) {
            if (! $this->handlerFor($domain) instanceof FailureHandler) {
                $missing[] = $domain->value;
            }
        }

        return [
            'ok' => $missing === [],
            'missing' => $missing,
            'count' => count(FailureDomain::cases()),
        ];
    }
}
