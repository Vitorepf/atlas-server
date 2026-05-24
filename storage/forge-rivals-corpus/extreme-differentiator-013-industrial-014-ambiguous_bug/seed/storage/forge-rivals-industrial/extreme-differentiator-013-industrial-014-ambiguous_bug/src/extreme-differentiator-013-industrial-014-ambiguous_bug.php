<?php

declare(strict_types=1);

final class ExtremeDifferentiator013Industrial014AmbiguousBugFixture
{
    public const CASE_ID = 'extreme-differentiator-013-industrial-014-ambiguous_bug';
    public const TASK_TYPE = 'ambiguous_bug';
    public const DIFFICULTY_LEVEL = 'L4';

    public function baseline(): array
    {
        return [
            'case_id' => self::CASE_ID,
            'scope' => 'Triar e corrigir bug ambiguo industrial 014, separando fatos, hipoteses e fix minimo.',
            'requires_evidence' => true,
            'external_provider_call' => false,
        ];
    }
}