<?php

declare(strict_types=1);

namespace Tests\Unit\Ai\SelfConstruction\ExternalBrain;

use App\Services\Ai\SelfConstruction\ExternalBrain\AtlasExternalBrainDomainWaveReadinessManifest;
use App\Services\Ai\SelfConstruction\ExternalBrain\AtlasExternalBrainProposalArena;
use App\Services\Ai\SelfConstruction\ExternalBrain\AtlasExternalBrainSimplificationCausalCreditGate;
use PHPUnit\Framework\TestCase;

/**
 * Composite negative fixture: template farms, unproven simplification and
 * under-evidenced domain waves must all stop before any governed application.
 */
final class AtlasExternalBrainCompoundingSafetyFixtureTest extends TestCase
{
    public function test_every_safety_gate_holds_on_a_compounding_regression_fixture(): void
    {
        $arena = (new AtlasExternalBrainProposalArena)->compete([
            'proposals' => [[
                'proposal_id' => 'template-1',
                'leverage' => 1.0,
                'implementability' => 1.0,
                'evidence_strength' => 1.0,
                'template_similarity' => 0.95,
                'repeated_pattern_count' => 4,
                'evidence_refs' => ['receipt:a', 'receipt:b'],
            ]],
        ]);

        $simplification = (new AtlasExternalBrainSimplificationCausalCreditGate)->adjudicate([
            'candidate_id' => 'unproven-delete',
            'kind' => 'deletion',
            'consumer_count' => 0,
            'consumer_paths' => [],
            'behavior_coverage' => true,
            'behavior_equivalence_commands' => ['php artisan test --filter=Equivalence'],
            'test_coverage' => true,
            'rollback_notes' => 'restore previous implementation',
            'lines_deleted' => 5000,
        ]);

        $wave = (new AtlasExternalBrainDomainWaveReadinessManifest)->evaluate([
            'requested_wave' => 1,
            'waves' => ['wave_1' => [
                'version' => 'wave-1.v1',
                'corpus_coverage' => 0.9,
                'private_hidden_cases' => 0,
                'public_only' => true,
                'independent_oracles' => 2,
                'capability_routes' => 1,
                'risk_depths' => ['low'],
            ]],
        ]);

        $this->assertSame(AtlasExternalBrainProposalArena::VERDICT_ALL_REJECTED, $arena['verdict']);
        $this->assertSame(AtlasExternalBrainProposalArena::DISQUALIFY_TEMPLATE_FARM, $arena['rejected'][0]['reason']);
        $this->assertFalse($simplification['credit_eligible']);
        $this->assertSame('hold', $simplification['causal_verdict']);
        $this->assertSame(AtlasExternalBrainDomainWaveReadinessManifest::STATUS_BLOCKED, $wave['waves']['wave_1']['status']);
        $this->assertFalse($wave['promotion_allowed']);
    }
}
