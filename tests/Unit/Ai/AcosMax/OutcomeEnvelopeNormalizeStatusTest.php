<?php

declare(strict_types=1);

namespace Tests\Unit\Ai\AcosMax;

use App\Services\Ai\AcosMax\OutcomeEnvelope;
use App\Services\Ai\AtlasDecide\AtlasDecideLiveOutcomeFeedbackService;
use PHPUnit\Framework\TestCase;

final class OutcomeEnvelopeNormalizeStatusTest extends TestCase
{
    public function test_normalize_status_maps_aliases_and_fails_closed(): void
    {
        $this->assertSame('succeeded', OutcomeEnvelope::normalizeStatus('passed'));
        $this->assertSame('succeeded', OutcomeEnvelope::normalizeStatus('SUCCESS'));
        $this->assertSame('failed', OutcomeEnvelope::normalizeStatus('failure'));
        $this->assertSame('blocked', OutcomeEnvelope::normalizeStatus('needs_review'));
        $this->assertSame('blocked', OutcomeEnvelope::normalizeStatus(''));
    }

    public function test_from_adapter_stamps_schema_and_binds_native_origin(): void
    {
        $envelope = OutcomeEnvelope::fromAdapter('compounding', [
            'executor' => 'dev',
            'task_category' => 'dev',
            'provider' => 'absent',
            'status' => 'succeeded',
            'verified' => false,
            'verified_basis' => AtlasDecideLiveOutcomeFeedbackService::VERIFIED_BASIS_ABSENT,
            'verified_source_present' => false,
            'certified_receipt_id' => null,
            'evidence_ref_count' => 0,
            'episode_id' => null,
            'run_id' => 'r-1',
        ], [
            'flow_id' => 'atlas_dev',
        ])->toArray();

        $this->assertSame(OutcomeEnvelope::SCHEMA_VERSION, $envelope['schema_version']);
        $this->assertSame(OutcomeEnvelope::FORMULA_VERSION, $envelope['formula_version']);
        $this->assertSame('compounding', $envelope['adapter_origin']);
        $this->assertSame('compounding', $envelope['native_divergent']['origin']);
        $this->assertSame('atlas_dev', $envelope['native_divergent']['fields']['flow_id']);
    }
}
