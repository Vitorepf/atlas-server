<?php

declare(strict_types=1);

namespace Tests\Feature\Ai\Compounding;

use App\Models\AiLearningProposal;
use App\Models\AtlasLedgerEvent;
use App\Services\Ai\Compounding\AtlasHeldEvidenceMinerService;
use App\Services\Ai\Compounding\AtlasLearningProposalService;
use Illuminate\Support\Str;
use Tests\Concerns\CreatesAtlasToolRuntimeTables;
use Tests\TestCase;

/**
 * The miner closes the compounding loop the evidence-OUT funnel opened: it reads
 * the held `EvidencePacked` evidence back out of the ledger and materialises
 * propose-only learning proposals whose evidence_refs are DERIVED from the ledger
 * (the exact input `AtlasLearningProposalService::propose()` used to demand
 * externally). It must filter to held/non-promotable evidence, respect the
 * corroboration threshold, and — the safety invariant — only ever PROPOSE.
 */
class AtlasHeldEvidenceMinerServiceTest extends TestCase
{
    use CreatesAtlasToolRuntimeTables;

    protected function setUp(): void
    {
        parent::setUp();
        $this->createAtlasToolRuntimeTables();
    }

    protected function tearDown(): void
    {
        $this->dropAtlasToolRuntimeTables();
        parent::tearDown();
    }

    private function proposalSpy(): AtlasLearningProposalService
    {
        return new class extends AtlasLearningProposalService
        {
            /** @var list<array<string,mixed>> */
            public array $calls = [];

            public function propose(array $input): AiLearningProposal
            {
                $this->calls[] = $input;

                return (new AiLearningProposal())->forceFill(['id' => 'stub', 'status' => 'proposed']);
            }
        };
    }

    private function event(string $kind, string $decision = 'hold', bool $promotionAllowed = false, string $type = 'EVIDENCE_PACKED'): string
    {
        $id = (string) Str::ulid();
        AtlasLedgerEvent::create([
            'event_id' => $id,
            'schema_version' => 'atlas.ledger_event.v1',
            'tenant_id' => 'default',
            'operator_id' => 'system',
            'envelope_id' => 'test-env',
            'correlation_id' => 'test-corr',
            'event_type' => $type,
            'emitter_stage' => 'atlas.ai.bridge_evidence',
            'emitter_version' => 'bridge-evidence-v1',
            'payload' => ['kind' => $kind, 'decision' => $decision, 'promotion_allowed' => $promotionAllowed],
            'payload_hash' => hash('sha256', $id),
            'occurred_at' => now(),
        ]);

        return $id;
    }

    public function test_mines_held_evidence_with_ledger_derived_refs(): void
    {
        $a = $this->event('provider_call');
        $b = $this->event('provider_call');
        $c = $this->event('provider_call');
        $this->event('tool_run');                                   // 1 held tool_run
        $this->event('provider_call', 'applied', true);             // promoted → NOT held
        $this->event('provider_call', 'hold', false, 'DECISION_ISSUED'); // not EvidencePacked

        $spy = $this->proposalSpy();
        $report = (new AtlasHeldEvidenceMinerService($spy))->mine(24, 1);

        $this->assertSame(5, $report['scanned']);   // 5 EvidencePacked
        $this->assertSame(4, $report['held']);      // 3 provider_call + 1 tool_run held
        $this->assertCount(2, $report['proposals']); // grouped by kind

        $providerCall = collect($spy->calls)->firstWhere(
            fn (array $c): bool => ($c['current_state']['evidence_kind'] ?? null) === 'provider_call'
        );
        $this->assertNotNull($providerCall);
        $this->assertSame('heuristic', $providerCall['kind']);
        // The gap closure: evidence_refs come from the ledger event ids, not external input.
        $this->assertEqualsCanonicalizing([$a, $b, $c], $providerCall['evidence_refs']);
    }

    public function test_min_corroboration_skips_thin_evidence(): void
    {
        $this->event('provider_call');
        $this->event('provider_call');
        $this->event('tool_run'); // only 1 — below threshold 2

        $spy = $this->proposalSpy();
        $report = (new AtlasHeldEvidenceMinerService($spy))->mine(24, 2);

        $this->assertCount(1, $report['proposals']);
        $this->assertSame('provider_call', $report['proposals'][0]['evidence_kind']);
    }

    public function test_only_proposes_never_promotes(): void
    {
        $this->event('gate_result');
        $this->event('gate_result');

        $spy = $this->proposalSpy();
        (new AtlasHeldEvidenceMinerService($spy))->mine(24, 1);

        $this->assertCount(1, $spy->calls);
        // gate/repair evidence is honestly a failure_pattern, not a heuristic.
        $this->assertSame('failure_pattern', $spy->calls[0]['kind']);
        // The funnel/miner never request application — propose-only by construction.
        $this->assertArrayNotHasKey('apply', $spy->calls[0]);
        $this->assertArrayNotHasKey('auto_apply', $spy->calls[0]);
    }
}
