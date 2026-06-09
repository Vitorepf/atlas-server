<?php

namespace Tests\Unit\Ai\Context;

use App\Services\Ai\Context\ContextPackSelfReflectionGate;
use App\Services\Ai\ValueObjects\AiContextPack;
use Carbon\Carbon;
use Tests\TestCase;

class ContextPackSelfReflectionGateTest extends TestCase
{
    protected function setUp(): void
    {
        parent::setUp();

        Carbon::setTestNow(Carbon::parse('2026-05-06 03:30:00', 'UTC'));
    }

    protected function tearDown(): void
    {
        Carbon::setTestNow();

        parent::tearDown();
    }

    public function test_context_pack_manifest_has_sources_created_at_and_expires_at(): void
    {
        config()->set('atlas.ai.context_pack_ttl_seconds', 900);

        $pack = new AiContextPack([
            'task' => ['type' => 'dev', 'risk_level' => 'low'],
            'surface' => ['kind' => 'atlas_cli_dev', 'workspace' => '/repo'],
            'evidence' => ['sources' => ['docs/kernel.md']],
            'memory' => ['semantic' => []],
        ], [
            ['type' => 'semantic_note', 'id' => 10, 'path' => 'Vault/Atlas.md', 'privacy_class' => 'normal'],
        ]);

        $manifest = $pack->toArray()['manifest'];

        $this->assertSame('atlas.context_pack.manifest.v1', $manifest['schema_version']);
        $this->assertStringStartsWith('ctx_', $manifest['context_pack_id']);
        $this->assertSame('2026-05-06T03:30:00.000000Z', $manifest['created_at']);
        $this->assertSame('2026-05-06T03:45:00.000000Z', $manifest['expires_at']);
        $this->assertSame(900, $manifest['ttl_seconds']);
        $this->assertSame(2, $manifest['source_count']);
        $this->assertSame('docs/kernel.md', $manifest['sources'][0]['id']);
        $this->assertSame('semantic_note', $manifest['sources'][1]['type']);
        $this->assertSame(1, $manifest['context_ref_count']);
        $this->assertNotEmpty($manifest['context_ref_hash']);
    }

    public function test_self_reflection_gate_classifies_sufficient_insufficient_contradictory_and_risky_context(): void
    {
        $gate = app(ContextPackSelfReflectionGate::class);

        $sufficient = $gate->assess(new AiContextPack([
            'task' => ['type' => 'dev', 'risk_level' => 'low'],
            'memory' => ['semantic' => [['title' => 'Atlas Kernel']]],
        ], []));
        $this->assertSame(ContextPackSelfReflectionGate::STATUS_SUFFICIENT, $sufficient['status']);
        $this->assertSame('atlas.context_pack.self_reflection.v1', $sufficient['schema_version']);
        $this->assertSame('continue_with_context', $sufficient['recommended_action']);

        $insufficient = $gate->assess(new AiContextPack([
            'task' => ['type' => 'dev', 'risk_level' => 'low'],
            'memory' => ['semantic' => []],
        ], []));
        $this->assertSame(ContextPackSelfReflectionGate::STATUS_INSUFFICIENT, $insufficient['status']);
        $this->assertSame('refresh_or_request_context', $insufficient['recommended_action']);

        $contradictory = $gate->assess(new AiContextPack([
            'task' => ['type' => 'dev', 'risk_level' => 'low'],
            'memory' => ['semantic' => [['title' => 'Conflito entre docs']]],
            'open_questions' => ['Existe contradicao entre o fluxo antigo e o novo.'],
        ], []));
        $this->assertSame(ContextPackSelfReflectionGate::STATUS_CONTRADICTORY, $contradictory['status']);
        $this->assertSame('surface_conflict_before_execution', $contradictory['recommended_action']);

        $risky = $gate->assess(new AiContextPack([
            'task' => ['type' => 'dev', 'risk_level' => 'high'],
            'memory' => ['semantic' => [['title' => 'Deploy']]],
            'gates' => ['human_approval_required' => true],
        ], []));
        $this->assertSame(ContextPackSelfReflectionGate::STATUS_RISKY, $risky['status']);
        $this->assertSame('require_review_before_execution', $risky['recommended_action']);
    }

    public function test_hedge_certainty_kernel_is_off_by_default_and_does_not_flip_clean_context(): void
    {
        // Flags default OFF — a hedge+absolute mix that the kernel WOULD flag must NOT
        // change the verdict (live behavior preserved byte-for-byte).
        $gate = app(ContextPackSelfReflectionGate::class);

        $pack = new AiContextPack([
            'task' => ['type' => 'dev', 'risk_level' => 'low'],
            'memory' => ['semantic' => [['title' => 'Atlas maybe always ships']]],
        ], []);

        $this->assertSame(ContextPackSelfReflectionGate::STATUS_SUFFICIENT, $gate->assess($pack)['status']);
    }

    public function test_hedge_certainty_kernel_flags_contradiction_when_flag_enabled(): void
    {
        // Flag ON — the consolidated HedgeCertaintyConflictDetector kernel now turns a
        // hedge-vs-absolute certainty mix into a contradiction verdict (the new behavior).
        config()->set('atlas.claim_coherence.hedge_certainty_conflict_enabled', true);
        $gate = app(ContextPackSelfReflectionGate::class);

        $pack = new AiContextPack([
            'task' => ['type' => 'dev', 'risk_level' => 'low'],
            'memory' => ['semantic' => [['title' => 'Atlas maybe always ships']]],
        ], []);

        $result = $gate->assess($pack);
        $this->assertSame(ContextPackSelfReflectionGate::STATUS_CONTRADICTORY, $result['status']);
        $this->assertSame('surface_conflict_before_execution', $result['recommended_action']);
    }

    public function test_self_coherence_helper_is_off_by_default_and_returns_kernel_output_when_enabled(): void
    {
        $gate = app(ContextPackSelfReflectionGate::class);

        $this->assertNull($gate->scoreClaimSelfCoherence('atlas', 'ships features', 'definitely', 0.9, 0));

        config()->set('atlas.claim_coherence.self_coherence_enabled', true);
        $scored = $gate->scoreClaimSelfCoherence('atlas', 'ships features', 'definitely', 0.9, 0);
        $this->assertNotNull($scored);
        $this->assertSame('atlas.cognitive.claim_coherence.self_coherence.v1', $scored['schema_version']);
        $this->assertSame(0.5, $scored['coherence']);
        $this->assertContains('confidence_without_evidence', $scored['penalties']);
    }

    public function test_qualifier_strength_helper_is_off_by_default_and_returns_kernel_output_when_enabled(): void
    {
        $gate = app(ContextPackSelfReflectionGate::class);

        $this->assertNull($gate->classifyQualifierStrength(['must', 'shall', 'will']));

        config()->set('atlas.claim_coherence.qualifier_strength_enabled', true);
        $classified = $gate->classifyQualifierStrength(['must', 'shall', 'will']);
        $this->assertNotNull($classified);
        $this->assertSame('atlas.cognitive.claim_coherence.qualifier_strength.v1', $classified['schema_version']);
        $this->assertSame(3, $classified['modalCount']);
        $this->assertSame('hard', $classified['band']);
    }
}
