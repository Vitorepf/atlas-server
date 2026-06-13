<?php

namespace Tests\Feature\Ai\LongHorizon;

use App\Models\AtlasLongHorizonContinuationPack;
use App\Services\Ai\LongHorizon\AtlasLongHorizonCanon;
use App\Services\Ai\LongHorizon\Gate\LongHorizonContextFreshnessGate;
use App\Services\Ai\LongHorizon\Gate\LongHorizonContextFreshnessGateResult;
use Illuminate\Support\Carbon;
use InvalidArgumentException;
use Tests\Concerns\CreatesLongHorizonPersistenceTables;
use Tests\TestCase;

class LongHorizonContextFreshnessGateTest extends TestCase
{
    use CreatesLongHorizonPersistenceTables;

    private LongHorizonContextFreshnessGate $gate;

    protected function setUp(): void
    {
        parent::setUp();
        $this->createLongHorizonPersistenceTables();
        $this->gate = app(LongHorizonContextFreshnessGate::class);
    }

    protected function tearDown(): void
    {
        $this->dropLongHorizonPersistenceTables();
        parent::tearDown();
    }

    public function test_fresh_pack_passes_and_recommends_execute(): void
    {
        $now = Carbon::parse('2026-05-19T10:00:00Z');

        $result = $this->gate->evaluate([
            'scope_type' => AtlasLongHorizonCanon::SCOPE_TYPE_FORGE_OBRA,
            'scope_id' => 'obra-fresh',
            'continuation_pack_payload' => $this->buildFreshPack($now),
            'context_manifest' => [
                ['ref' => 'doc:atlas-forge-os.md', 'kind' => 'canonical_doc', 'stale_after' => $now->copy()->addDays(30)->toIso8601String(), 'required' => true],
            ],
            'now' => $now,
        ]);

        $this->assertSame(LongHorizonContextFreshnessGateResult::STATUS_PASS, $result->status);
        $this->assertSame([], $result->blockingReasons);
        $this->assertSame(AtlasLongHorizonCanon::SAFE_RESUME_EXECUTE, $result->recommendedSafeResumeMode);
        $this->assertTrue($result->writeAllowed);
        $this->assertSame(64, strlen($result->freshnessHash));
    }

    public function test_pack_stale_after_expired_blocks_when_intent_is_execute(): void
    {
        $now = Carbon::parse('2026-05-19T10:00:00Z');
        $pack = $this->buildFreshPack($now, AtlasLongHorizonCanon::SCOPE_TYPE_FORGE_OBRA, 'obra-stale');
        $pack['stale_after'] = $now->copy()->subHour()->toIso8601String();

        $result = $this->gate->evaluate([
            'scope_type' => AtlasLongHorizonCanon::SCOPE_TYPE_FORGE_OBRA,
            'scope_id' => 'obra-stale',
            'continuation_pack_payload' => $pack,
            'intended_mode' => AtlasLongHorizonCanon::SAFE_RESUME_EXECUTE,
            'now' => $now,
        ]);

        $this->assertSame(LongHorizonContextFreshnessGateResult::STATUS_BLOCKED, $result->status);
        $this->assertContains(
            LongHorizonContextFreshnessGateResult::REASON_PACK_STALE_AFTER_EXPIRED,
            $result->blockingReasons,
        );
        $this->assertFalse($result->writeAllowed);
        // Non-critical blocker but execute intent → recommended blocked / read_only path.
        $this->assertNotSame(AtlasLongHorizonCanon::SAFE_RESUME_EXECUTE, $result->recommendedSafeResumeMode);
    }

    public function test_missing_required_refs_blocks_execute_and_recommends_blocked(): void
    {
        $now = Carbon::parse('2026-05-19T10:00:00Z');
        $pack = $this->buildFreshPack($now, AtlasLongHorizonCanon::SCOPE_TYPE_FORGE_OBRA, 'obra-missing');

        $result = $this->gate->evaluate([
            'scope_type' => AtlasLongHorizonCanon::SCOPE_TYPE_FORGE_OBRA,
            'scope_id' => 'obra-missing',
            'continuation_pack_payload' => $pack,
            'context_manifest' => [
                ['ref' => 'doc:critical-spec.md', 'kind' => 'canonical_doc', 'required' => true, 'missing' => true],
            ],
            'required_evidence_kinds' => ['verification_receipt'],
            'intended_mode' => AtlasLongHorizonCanon::SAFE_RESUME_EXECUTE,
            'now' => $now,
        ]);

        $this->assertSame(LongHorizonContextFreshnessGateResult::STATUS_BLOCKED, $result->status);
        $this->assertContains(
            LongHorizonContextFreshnessGateResult::REASON_MISSING_REQUIRED_REFS,
            $result->blockingReasons,
        );
        $this->assertContains(
            LongHorizonContextFreshnessGateResult::REASON_MISSING_CRITICAL_EVIDENCE,
            $result->blockingReasons,
        );
        $this->assertContains('doc:critical-spec.md', $result->missingRequiredRefs);
        $this->assertContains('evidence_kind:verification_receipt', $result->missingRequiredRefs);
        $this->assertSame(AtlasLongHorizonCanon::SAFE_RESUME_BLOCKED, $result->recommendedSafeResumeMode);
        $this->assertFalse($result->writeAllowed);
    }

    public function test_compaction_unresolved_loss_blocks_with_critical_blocker(): void
    {
        $now = Carbon::parse('2026-05-19T10:00:00Z');

        $result = $this->gate->evaluate([
            'scope_type' => AtlasLongHorizonCanon::SCOPE_TYPE_DEV_SESSION,
            'scope_id' => 'dev-session-loss',
            'continuation_pack_payload' => $this->buildFreshPack(
                $now,
                AtlasLongHorizonCanon::SCOPE_TYPE_DEV_SESSION,
                'dev-session-loss',
            ),
            'compaction_receipt_payload' => [
                'must_keep_coverage' => 0.83,
                'unresolved_loss' => [
                    ['id' => 'mk-blocker-1', 'kind' => 'blocker', 'reason' => 'budget_pressure'],
                ],
                'loss_risk' => AtlasLongHorizonCanon::LOSS_RISK_HIGH,
            ],
            'intended_mode' => AtlasLongHorizonCanon::SAFE_RESUME_EXECUTE,
            'now' => $now,
        ]);

        $this->assertSame(LongHorizonContextFreshnessGateResult::STATUS_BLOCKED, $result->status);
        $this->assertContains(
            LongHorizonContextFreshnessGateResult::REASON_COMPACTION_UNRESOLVED_LOSS,
            $result->blockingReasons,
        );
        $this->assertContains(
            LongHorizonContextFreshnessGateResult::REASON_COMPACTION_COVERAGE_BELOW_ONE,
            $result->blockingReasons,
        );
        $this->assertSame(AtlasLongHorizonCanon::SAFE_RESUME_BLOCKED, $result->recommendedSafeResumeMode);
        $this->assertFalse($result->writeAllowed);
        $this->assertNotNull($result->compactionLoss);
        $this->assertSame(0.83, $result->mustKeepCoverage);
    }

    public function test_read_only_intent_allows_warn_path_with_stale_refs(): void
    {
        $now = Carbon::parse('2026-05-19T10:00:00Z');
        $pack = $this->buildFreshPack($now, AtlasLongHorizonCanon::SCOPE_TYPE_DEV_SESSION, 'dev-readonly');

        $result = $this->gate->evaluate([
            'scope_type' => AtlasLongHorizonCanon::SCOPE_TYPE_DEV_SESSION,
            'scope_id' => 'dev-readonly',
            'continuation_pack_payload' => $pack,
            'context_manifest' => [
                [
                    'ref' => 'doc:atlas-forge-os.md',
                    'kind' => 'canonical_doc',
                    'stale_after' => $now->copy()->subDays(2)->toIso8601String(),
                ],
            ],
            'intended_mode' => AtlasLongHorizonCanon::SAFE_RESUME_READ_ONLY,
            'now' => $now,
        ]);

        $this->assertSame(LongHorizonContextFreshnessGateResult::STATUS_WARN, $result->status);
        $this->assertSame([], $result->blockingReasons);
        $this->assertContains(LongHorizonContextFreshnessGateResult::REASON_STALE_REFS, $result->warnings);
        $this->assertSame(AtlasLongHorizonCanon::SAFE_RESUME_READ_ONLY, $result->recommendedSafeResumeMode);
        $this->assertFalse($result->writeAllowed, 'read-only never enables write');
    }

    public function test_execute_intent_with_stale_refs_escalates_warn_to_read_only_recommendation(): void
    {
        $now = Carbon::parse('2026-05-19T10:00:00Z');
        $pack = $this->buildFreshPack($now, AtlasLongHorizonCanon::SCOPE_TYPE_DEV_SESSION, 'dev-execute-warn');

        $result = $this->gate->evaluate([
            'scope_type' => AtlasLongHorizonCanon::SCOPE_TYPE_DEV_SESSION,
            'scope_id' => 'dev-execute-warn',
            'continuation_pack_payload' => $pack,
            'context_manifest' => [
                ['ref' => 'doc:x.md', 'kind' => 'canonical_doc', 'stale_after' => $now->copy()->subDay()->toIso8601String()],
            ],
            'intended_mode' => AtlasLongHorizonCanon::SAFE_RESUME_EXECUTE,
            'now' => $now,
        ]);

        $this->assertSame(LongHorizonContextFreshnessGateResult::STATUS_WARN, $result->status);
        $this->assertSame(AtlasLongHorizonCanon::SAFE_RESUME_READ_ONLY, $result->recommendedSafeResumeMode);
        $this->assertFalse($result->writeAllowed);
        $this->assertContains('downgrade_intended_mode_to_read_only', $result->remediation);
    }

    public function test_strict_mode_escalates_warning_to_block(): void
    {
        $now = Carbon::parse('2026-05-19T10:00:00Z');
        $pack = $this->buildFreshPack($now, AtlasLongHorizonCanon::SCOPE_TYPE_DEV_SESSION, 'dev-strict');

        $result = $this->gate->evaluate([
            'scope_type' => AtlasLongHorizonCanon::SCOPE_TYPE_DEV_SESSION,
            'scope_id' => 'dev-strict',
            'continuation_pack_payload' => $pack,
            'context_manifest' => [
                ['ref' => 'doc:x.md', 'kind' => 'canonical_doc', 'stale_after' => $now->copy()->subDay()->toIso8601String()],
            ],
            'intended_mode' => AtlasLongHorizonCanon::SAFE_RESUME_READ_ONLY,
            'strict' => true,
            'now' => $now,
        ]);

        $this->assertSame(LongHorizonContextFreshnessGateResult::STATUS_BLOCKED, $result->status);
        $this->assertContains(
            LongHorizonContextFreshnessGateResult::REASON_STRICT_MODE_ESCALATED_WARN,
            $result->blockingReasons,
        );
    }

    public function test_source_hash_mismatch_is_critical_blocker(): void
    {
        $now = Carbon::parse('2026-05-19T10:00:00Z');

        $result = $this->gate->evaluate([
            'scope_type' => AtlasLongHorizonCanon::SCOPE_TYPE_DEV_SESSION,
            'scope_id' => 'dev-hash-drift',
            'continuation_pack_payload' => $this->buildFreshPack(
                $now,
                AtlasLongHorizonCanon::SCOPE_TYPE_DEV_SESSION,
                'dev-hash-drift',
            ),
            'context_manifest' => [
                [
                    'ref' => 'code:Router::dispatch',
                    'kind' => 'code_symbol',
                    'source_hash_expected' => str_repeat('a', 64),
                    'source_hash_actual' => str_repeat('b', 64),
                ],
            ],
            'intended_mode' => AtlasLongHorizonCanon::SAFE_RESUME_EXECUTE,
            'now' => $now,
        ]);

        $this->assertSame(LongHorizonContextFreshnessGateResult::STATUS_BLOCKED, $result->status);
        $this->assertContains(
            LongHorizonContextFreshnessGateResult::REASON_SOURCE_HASH_MISMATCH,
            $result->blockingReasons,
        );
        $this->assertSame(AtlasLongHorizonCanon::SAFE_RESUME_BLOCKED, $result->recommendedSafeResumeMode);
        $this->assertCount(1, $result->sourceHashMismatches);
        $this->assertSame('code:Router::dispatch', $result->sourceHashMismatches[0]['ref']);
    }

    public function test_continuation_pack_missing_blocks_with_canonical_reason(): void
    {
        $now = Carbon::parse('2026-05-19T10:00:00Z');

        $result = $this->gate->evaluate([
            'scope_type' => AtlasLongHorizonCanon::SCOPE_TYPE_FORGE_OBRA,
            'scope_id' => 'obra-no-pack',
            'now' => $now,
        ]);

        $this->assertSame(LongHorizonContextFreshnessGateResult::STATUS_BLOCKED, $result->status);
        $this->assertContains(
            LongHorizonContextFreshnessGateResult::REASON_CONTINUATION_PACK_MISSING,
            $result->blockingReasons,
        );
        $this->assertSame(AtlasLongHorizonCanon::SAFE_RESUME_BLOCKED, $result->recommendedSafeResumeMode);
    }

    public function test_scope_mismatch_between_caller_and_pack_blocks(): void
    {
        $now = Carbon::parse('2026-05-19T10:00:00Z');
        $pack = $this->buildFreshPack($now);
        $pack['scope_type'] = AtlasLongHorizonCanon::SCOPE_TYPE_DEV_SESSION;
        $pack['scope_id'] = 'other-id';

        $result = $this->gate->evaluate([
            'scope_type' => AtlasLongHorizonCanon::SCOPE_TYPE_FORGE_OBRA,
            'scope_id' => 'mine',
            'continuation_pack_payload' => $pack,
            'now' => $now,
        ]);

        $this->assertSame(LongHorizonContextFreshnessGateResult::STATUS_BLOCKED, $result->status);
        $this->assertContains(
            LongHorizonContextFreshnessGateResult::REASON_SCOPE_MISMATCH,
            $result->blockingReasons,
        );
    }

    public function test_superseded_decisions_yield_warning(): void
    {
        $now = Carbon::parse('2026-05-19T10:00:00Z');
        $pack = $this->buildFreshPack($now, AtlasLongHorizonCanon::SCOPE_TYPE_DEV_SESSION, 'dev-superseded');
        $pack['decisions'] = [
            ['decision_id' => 'd-001', 'text' => 'use kernel canonical RAG', 'superseded_by' => 'd-002'],
        ];

        $result = $this->gate->evaluate([
            'scope_type' => AtlasLongHorizonCanon::SCOPE_TYPE_DEV_SESSION,
            'scope_id' => 'dev-superseded',
            'continuation_pack_payload' => $pack,
            'intended_mode' => AtlasLongHorizonCanon::SAFE_RESUME_READ_ONLY,
            'now' => $now,
        ]);

        $this->assertSame(LongHorizonContextFreshnessGateResult::STATUS_WARN, $result->status);
        $this->assertContains(
            LongHorizonContextFreshnessGateResult::REASON_SUPERSEDED_DECISIONS,
            $result->warnings,
        );
        $this->assertCount(1, $result->supersededDecisions);
        $this->assertSame('d-001', $result->supersededDecisions[0]['decision_id']);
        $this->assertSame('d-002', $result->supersededDecisions[0]['superseded_by']);
    }

    public function test_freshness_hash_is_deterministic_for_equivalent_inputs(): void
    {
        $now = Carbon::parse('2026-05-19T10:00:00Z');
        $payloadInput = [
            'scope_type' => AtlasLongHorizonCanon::SCOPE_TYPE_DEV_SESSION,
            'scope_id' => 'dev-determ',
            'continuation_pack_payload' => $this->buildFreshPack(
                $now,
                AtlasLongHorizonCanon::SCOPE_TYPE_DEV_SESSION,
                'dev-determ',
            ),
            'context_manifest' => [
                ['ref' => 'doc:y.md', 'kind' => 'canonical_doc'],
            ],
            'intended_mode' => AtlasLongHorizonCanon::SAFE_RESUME_EXECUTE,
            'now' => $now,
        ];

        $a = $this->gate->evaluate($payloadInput);
        $b = $this->gate->evaluate($payloadInput);

        $this->assertSame($a->freshnessHash, $b->freshnessHash);
        $this->assertSame(64, strlen($a->freshnessHash));
    }

    public function test_result_serialises_to_stable_json(): void
    {
        $now = Carbon::parse('2026-05-19T10:00:00Z');
        $result = $this->gate->evaluate([
            'scope_type' => AtlasLongHorizonCanon::SCOPE_TYPE_DEV_SESSION,
            'scope_id' => 'dev-json',
            'continuation_pack_payload' => $this->buildFreshPack(
                $now,
                AtlasLongHorizonCanon::SCOPE_TYPE_DEV_SESSION,
                'dev-json',
            ),
            'now' => $now,
        ]);

        $json = json_encode($result->toCanonicalArray(), JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE);
        $this->assertNotFalse($json);
        $decoded = json_decode((string) $json, true);
        $this->assertSame(LongHorizonContextFreshnessGateResult::SCHEMA_VERSION, $decoded['schema_version']);
        $this->assertSame($result->freshnessHash, $decoded['freshness_hash']);
    }

    public function test_resolves_persisted_pack_by_non_uuid_canonical_uuid(): void
    {
        $now = Carbon::parse('2026-05-19T10:00:00Z');
        $payload = [
            'uuid' => 'long-horizon-non-uuid-alias',
            'scope_type' => AtlasLongHorizonCanon::SCOPE_TYPE_LONG_HORIZON,
            'scope_id' => 'fable-lista-6',
            'objective' => 'Resume from provider-safe continuity pack.',
            'current_phase' => 'continuity_certification',
            'state_summary' => 'Pack uuid is a canonical alias, not a database UUID.',
            'decisions' => [],
            'superseded_decisions' => [],
            'open_tasks' => [],
            'completed_tasks' => [],
            'blockers' => [],
            'risks' => [],
            'evidence_refs' => [['kind' => 'doc', 'ref' => 'docs/fable-lista-6-14-itens.md']],
            'context_manifest' => [],
            'context_pack_hash' => str_repeat('a', 64),
            'summary_hash' => str_repeat('b', 64),
            'source_receipts' => [],
            'stale_after' => $now->copy()->addDays(7),
            'safe_resume_mode' => AtlasLongHorizonCanon::SAFE_RESUME_EXECUTE,
            'next_safe_action' => 'run continuity certification',
            'human_decisions_required' => [],
            'confidence' => 0.86,
            'pack_hash' => str_repeat('c', 64),
        ];
        AtlasLongHorizonContinuationPack::query()->create($payload);

        $result = $this->gate->evaluate([
            'scope_type' => AtlasLongHorizonCanon::SCOPE_TYPE_LONG_HORIZON,
            'scope_id' => 'fable-lista-6',
            'continuation_pack_id' => 'long-horizon-non-uuid-alias',
            'now' => $now,
        ]);

        $this->assertSame(LongHorizonContextFreshnessGateResult::STATUS_PASS, $result->status);
        $this->assertSame([], $result->blockingReasons);
    }

    public function test_invalid_scope_type_throws(): void
    {
        $this->expectException(InvalidArgumentException::class);
        $this->gate->evaluate([
            'scope_type' => 'not_a_real_scope',
        ]);
    }

    public function test_invalid_intended_mode_throws(): void
    {
        $this->expectException(InvalidArgumentException::class);
        $this->gate->evaluate([
            'scope_type' => AtlasLongHorizonCanon::SCOPE_TYPE_DEV_SESSION,
            'intended_mode' => 'ultra_mode',
        ]);
    }

    /**
     * Returns a minimal pack payload with stale_after well in the future.
     * Caller can override the scope to match its own scope_type/scope_id so
     * the gate's scope-mismatch guard does not trip on helper defaults.
     *
     * @return array<string,mixed>
     */
    private function buildFreshPack(
        Carbon $now,
        ?string $scopeType = null,
        ?string $scopeId = null,
    ): array {
        return [
            'scope_type' => $scopeType ?? AtlasLongHorizonCanon::SCOPE_TYPE_FORGE_OBRA,
            'scope_id' => $scopeId ?? 'obra-fresh',
            'stale_after' => $now->copy()->addDays(7)->toIso8601String(),
            'context_manifest' => [],
            'evidence_refs' => [
                ['kind' => 'plan', 'ref' => 'plan-1'],
                ['kind' => 'context_pack', 'ref' => 'cp-1'],
            ],
            'decisions' => [],
            'superseded_decisions' => [],
        ];
    }
}
