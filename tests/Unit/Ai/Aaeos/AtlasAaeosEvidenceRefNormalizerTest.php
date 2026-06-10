<?php

declare(strict_types=1);

namespace Tests\Unit\Ai\Aaeos;

use App\Services\Ai\Aaeos\AtlasAaeosEvidenceRefNormalizer;
use Tests\TestCase;

class AtlasAaeosEvidenceRefNormalizerTest extends TestCase
{
    public function test_list_from_raw_preserves_authored_kind_while_trimming_refs(): void
    {
        $normalizer = new AtlasAaeosEvidenceRefNormalizer;

        $this->assertSame([
            ['kind' => 'Test', 'ref' => 'AtlasAaeosImplementationTruthServiceTest'],
            ['kind' => 'symbol', 'ref' => 'App\\Services\\Ai\\Aaeos\\AtlasAaeosImplementationTruthService'],
        ], $normalizer->listFromRaw([
            ['kind' => ' Test ', 'ref' => ' AtlasAaeosImplementationTruthServiceTest '],
            ' symbol : App\\Services\\Ai\\Aaeos\\AtlasAaeosImplementationTruthService ',
            'missing-colon',
            ['kind' => 'receipt', 'ref' => ''],
            false,
        ]));
    }

    public function test_kind_is_canonical_and_ref_is_trimmed_only(): void
    {
        $normalizer = new AtlasAaeosEvidenceRefNormalizer;

        $this->assertSame('test', $normalizer->kind(' Test '));
        $this->assertSame('AtlasAaeosImplementationTruthServiceTest', $normalizer->ref(' AtlasAaeosImplementationTruthServiceTest '));
    }

    public function test_non_array_raw_list_is_empty(): void
    {
        $this->assertSame([], (new AtlasAaeosEvidenceRefNormalizer)->listFromRaw('test: Example'));
    }
}
