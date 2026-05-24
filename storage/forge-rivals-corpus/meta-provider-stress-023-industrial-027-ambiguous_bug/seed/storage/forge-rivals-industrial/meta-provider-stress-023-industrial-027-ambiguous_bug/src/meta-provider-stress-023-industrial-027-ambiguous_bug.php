<?php

declare(strict_types=1);

final class MetaProviderStress023Industrial027AmbiguousBugFixture
{
    public const CASE_ID = 'meta-provider-stress-023-industrial-027-ambiguous_bug';
    public const TASK_TYPE = 'ambiguous_bug';
    public const DIFFICULTY_LEVEL = 'L2';

    public function baseline(): array
    {
        return [
            'case_id' => self::CASE_ID,
            'scope' => 'Triar e corrigir bug ambiguo industrial 027, separando fatos, hipoteses e fix minimo.',
            'requires_evidence' => true,
            'external_provider_call' => false,
        ];
    }
}