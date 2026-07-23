<?php

declare(strict_types=1);

namespace Tests\Unit\Ai\Aaeos;

use App\Services\Ai\AgenticEngineeringOs\Support\AtlasEvidenceRefNormalizer;
use Tests\TestCase;

class AtlasAaeosEvidenceRefNormalizerTest extends TestCase
{
    public function test_list_from_raw_preserves_authored_kind_while_trimming_refs(): void
    {
        $normalizer = new AtlasEvidenceRefNormalizer;

        $this->assertSame([
            ['kind' => 'Test', 'ref' => 'AtlasAaeosImplementationTruthServiceTest'],
            ['kind' => 'symbol', 'ref' => 'App\\Services\\Ai\\Aaeos\\AtlasImplementationTruthService'],
        ], $normalizer->listFromRaw([
            ['kind' => ' Test ', 'ref' => ' AtlasAaeosImplementationTruthServiceTest '],
            ' symbol : App\\Services\\Ai\\Aaeos\\AtlasImplementationTruthService ',
            'missing-colon',
            ['kind' => 'receipt', 'ref' => ''],
            false,
        ]));
    }

    public function test_kind_is_canonical_and_ref_is_trimmed_only(): void
    {
        $normalizer = new AtlasEvidenceRefNormalizer;

        $this->assertSame('test', $normalizer->kind(' Test '));
        $this->assertSame('AtlasAaeosImplementationTruthServiceTest', $normalizer->ref(' AtlasAaeosImplementationTruthServiceTest '));
    }

    public function test_non_array_raw_list_is_empty(): void
    {
        $this->assertSame([], (new AtlasEvidenceRefNormalizer)->listFromRaw('test: Example'));
    }
}
