<?php

declare(strict_types=1);

namespace Tests\Unit\Ai\SoftwareCompanyStewardship\AreaFocusLoop;

use App\Services\Ai\SoftwareCompanyStewardship\AreaFocusLoop\AreaFocusEvidenceRefNormalizer;
use Tests\TestCase;

final class AreaFocusEvidenceRefNormalizerTest extends TestCase
{
    public function test_gate_passed_accepts_only_explicit_true_flags(): void
    {
        $this->assertTrue(AreaFocusEvidenceRefNormalizer::gatePassed(true));
        $this->assertTrue(AreaFocusEvidenceRefNormalizer::gatePassed(['passed' => true]));
        $this->assertTrue(AreaFocusEvidenceRefNormalizer::gatePassed(['met' => true]));
        $this->assertTrue(AreaFocusEvidenceRefNormalizer::gatePassed(['pass' => true]));

        $this->assertFalse(AreaFocusEvidenceRefNormalizer::gatePassed(['passed' => 'true']));
        $this->assertFalse(AreaFocusEvidenceRefNormalizer::gatePassed(['unknown' => true]));
        $this->assertFalse(AreaFocusEvidenceRefNormalizer::gatePassed(null));
    }

    public function test_gate_evidence_ref_prefers_explicit_evidence_ref_then_ref(): void
    {
        $this->assertSame(
            'ledger:primary',
            AreaFocusEvidenceRefNormalizer::gateEvidenceRef([
                'evidence_ref' => 'ledger:primary',
                'ref' => 'ledger:fallback',
            ], 'gate:', true),
        );

        $this->assertSame(
            'ledger:fallback',
            AreaFocusEvidenceRefNormalizer::gateEvidenceRef([
                'evidence_ref' => '',
                'ref' => 'ledger:fallback',
            ], 'gate:', true),
        );
    }

    public function test_gate_evidence_ref_preserves_legacy_literal_string_contract(): void
    {
        $this->assertSame(
            '   ',
            AreaFocusEvidenceRefNormalizer::gateEvidenceRef(['evidence_ref' => '   '], 'gate:', true),
        );

        $this->assertSame('gate:met', AreaFocusEvidenceRefNormalizer::gateEvidenceRef(null, 'gate:', true));
        $this->assertSame('gate:blocked', AreaFocusEvidenceRefNormalizer::gateEvidenceRef([], 'gate:', false));
    }
}
