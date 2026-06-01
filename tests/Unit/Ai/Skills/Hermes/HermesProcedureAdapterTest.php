<?php

namespace Tests\Unit\Ai\Skills\Hermes;

use App\Models\AiJob;
use App\Models\HermesProcedureCandidate;
use App\Services\Ai\Hermes\HermesProcedureAdapter;
use App\Services\Ai\Skills\Governance\SkillPackPromotionGate;
use Illuminate\Support\Facades\Schema;
use Tests\TestCase;

class HermesProcedureAdapterTest extends TestCase
{
    protected function setUp(): void
    {
        parent::setUp();

        (require database_path('migrations/2026_06_01_000600_create_hermes_procedure_candidates_table.php'))->up();
    }

    protected function tearDown(): void
    {
        Schema::dropIfExists('hermes_procedure_candidates');

        parent::tearDown();
    }

    public function test_returns_no_candidates_status_when_packet_has_no_procedure_candidates(): void
    {
        $receipt = $this->adapter()->persistCandidates(
            $this->job(),
            $this->resultPacket([]),
            $this->mission(),
            $this->invocation(),
            'atlas_adapter',
        );

        $this->assertSame('no_candidates', data_get($receipt, 'status'));
        $this->assertSame(0, data_get($receipt, 'candidate_count'));
        $this->assertSame(0, data_get($receipt, 'persisted_count'));
        $this->assertSame(0, HermesProcedureCandidate::query()->count());
    }

    public function test_skips_when_procedure_policy_is_off(): void
    {
        $receipt = $this->adapter()->persistCandidates(
            $this->job(),
            $this->resultPacket([$this->candidate()]),
            $this->mission(),
            $this->invocation(),
            'off',
        );

        $this->assertSame('skipped_by_policy', data_get($receipt, 'status'));
        $this->assertSame(1, data_get($receipt, 'skipped_count'));
        $this->assertSame('procedure_policy_not_atlas_adapter', data_get($receipt, 'skipped_candidates.0.reason'));
        $this->assertSame(0, HermesProcedureCandidate::query()->count());
    }

    public function test_persists_candidate_for_review_when_policy_atlas_adapter(): void
    {
        $receipt = $this->adapter()->persistCandidates(
            $this->job(),
            $this->resultPacket([$this->candidate()]),
            $this->mission(),
            $this->invocation(),
            'atlas_adapter',
        );

        $this->assertSame('persisted_for_review', data_get($receipt, 'status'));
        $this->assertSame(1, data_get($receipt, 'persisted_count'));
        $this->assertFalse((bool) data_get($receipt, 'promotion_allowed_now'));
        $this->assertSame(1, HermesProcedureCandidate::query()->count());

        $row = HermesProcedureCandidate::query()->first();
        $this->assertSame('persisted_for_review', $row->status);
        $this->assertSame('hermes_session', $row->source_status);
        $this->assertFalse($row->promotion_allowed);
        $this->assertTrue($row->review_required);
        $this->assertSame('persisted_for_atlas_skill_review', data_get($row->payload_json, 'gate_status'));
        $this->assertSame('pending', data_get($row->payload_json, 'review_status'));
        $this->assertNotNull($row->expires_at);
    }

    public function test_persisted_candidate_carries_hermes_result_packet_evidence(): void
    {
        $this->adapter()->persistCandidates(
            $this->job(),
            $this->resultPacket([$this->candidate()]),
            $this->mission(),
            $this->invocation(),
            'atlas_adapter',
        );

        $row = HermesProcedureCandidate::query()->first();
        $refs = collect($row->evidence_refs_json)
            ->first(fn (array $ref): bool => ($ref['kind'] ?? null) === 'hermes_result_packet');

        $this->assertNotNull($refs);
        $this->assertSame('hermes_result_test', data_get($refs, 'result_id'));
        $this->assertSame('result_hash_test', data_get($refs, 'result_hash'));
        $this->assertSame('hermes_mission_test', data_get($refs, 'mission_id'));
        $this->assertSame('mission_hash_test', data_get($refs, 'mission_hash'));
        $this->assertNotEmpty(data_get($refs, 'cli_invocation_hash'));
        $this->assertFalse((bool) data_get($refs, 'promotion_allowed_now'));
    }

    public function test_dedupes_identical_candidate_on_second_run(): void
    {
        $candidate = $this->candidate();

        $this->adapter()->persistCandidates(
            $this->job(),
            $this->resultPacket([$candidate]),
            $this->mission(),
            $this->invocation(),
            'atlas_adapter',
        );

        $second = $this->adapter()->persistCandidates(
            $this->job(),
            $this->resultPacket([$candidate]),
            $this->mission(),
            $this->invocation(),
            'atlas_adapter',
        );

        $this->assertSame('deduplicated', data_get($second, 'status'));
        $this->assertSame(1, data_get($second, 'duplicate_count'));
        $this->assertSame(0, data_get($second, 'persisted_count'));
        $this->assertSame(1, HermesProcedureCandidate::query()->count());
    }

    public function test_high_risk_candidate_persists_but_never_auto_promotes(): void
    {
        $receipt = $this->adapter()->persistCandidates(
            $this->job(),
            $this->resultPacket([$this->candidate(['risk_level' => 'high'])]),
            $this->mission(),
            $this->invocation(),
            'atlas_adapter',
        );

        $this->assertSame('persisted_for_review', data_get($receipt, 'status'));
        $this->assertFalse((bool) data_get($receipt, 'promotion_allowed_now'));

        $row = HermesProcedureCandidate::query()->first();
        $this->assertSame('high', $row->risk_level);
        $this->assertFalse($row->promotion_allowed);
        $this->assertTrue(data_get($row->promotion_gate_json, 'danger_requires_operator_authority') === true);
        $this->assertFalse((bool) data_get($row->promotion_gate_json, 'promotion_allowed_now'));
    }

    public function test_skips_candidate_missing_name_or_purpose(): void
    {
        $receipt = $this->adapter()->persistCandidates(
            $this->job(),
            $this->resultPacket([
                ['candidate_id' => 'hermes_procedure_candidate_x', 'purpose' => 'only purpose, no name'],
            ]),
            $this->mission(),
            $this->invocation(),
            'atlas_adapter',
        );

        $this->assertSame(1, data_get($receipt, 'skipped_count'));
        $this->assertSame('missing_name_or_purpose', data_get($receipt, 'skipped_candidates.0.reason'));
        $this->assertSame(0, HermesProcedureCandidate::query()->count());
    }

    public function test_returns_gate_unavailable_when_table_missing(): void
    {
        Schema::dropIfExists('hermes_procedure_candidates');

        $receipt = $this->adapter()->persistCandidates(
            $this->job(),
            $this->resultPacket([$this->candidate()]),
            $this->mission(),
            $this->invocation(),
            'atlas_adapter',
        );

        $this->assertSame('procedure_gate_unavailable', data_get($receipt, 'status'));
        $this->assertSame('hermes_procedure_candidates_table_missing', data_get($receipt, 'skipped_candidates.0.reason'));
    }

    public function test_receipt_is_sealed_with_receipt_hash(): void
    {
        $receipt = $this->adapter()->persistCandidates(
            $this->job(),
            $this->resultPacket([$this->candidate()]),
            $this->mission(),
            $this->invocation(),
            'atlas_adapter',
        );

        $this->assertNotEmpty(data_get($receipt, 'receipt_hash'));
        $this->assertSame('atlas.hermes.procedure_adapter_receipt.v1', data_get($receipt, 'schema_version'));
        $this->assertSame('hermes_procedure_adapter', data_get($receipt, 'adapter'));

        $expected = hash('sha256', json_encode(
            collect($receipt)->except('receipt_hash')->all(),
            JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE | JSON_THROW_ON_ERROR,
        ));
        $this->assertSame($expected, data_get($receipt, 'receipt_hash'));
    }

    public function test_promotion_only_after_explicit_skill_pack_promotion_gate(): void
    {
        $this->adapter()->persistCandidates(
            $this->job(),
            $this->resultPacket([$this->candidate()]),
            $this->mission(),
            $this->invocation(),
            'atlas_adapter',
        );

        $row = HermesProcedureCandidate::query()->first();
        $this->assertFalse($row->promotion_allowed, 'Adapter must never set promotion_allowed=true.');

        $gate = app(SkillPackPromotionGate::class);

        // Manifest built straight off the persisted candidate WITHOUT canon_docs/quality_gates -> blocked.
        $blocked = $gate->evaluate([
            'pack_id' => 'hermes.procedure.'.data_get($row->payload_json, 'name'),
            'schema_version' => SkillPackPromotionGate::SCHEMA_VERSION,
            'domain' => 'programming',
            'version' => data_get($row->payload_json, 'name') ? '1.0.0' : null,
        ]);
        $this->assertSame('promotion_blocked', data_get($blocked, 'promotion_decision'));

        // Compliant manifest -> approved.
        $approved = $gate->evaluate([
            'pack_id' => 'hermes.procedure.reviewed',
            'schema_version' => SkillPackPromotionGate::SCHEMA_VERSION,
            'domain' => 'programming',
            'version' => '1.0.0',
            'quality_gates' => ['lint', 'unit', 'evidence'],
            'evidence_contracts' => ['atlas.hermes.procedure_adapter_receipt.v1'],
            'skills' => [
                ['skill_id' => 'hermes.procedure.reviewed', 'policy_class' => 'read'],
            ],
            'canon_docs' => ['docs/engineering-knowledge-base/atlas-hermes-executive-runtime.md'],
        ]);
        $this->assertSame('promotion_approved', data_get($approved, 'promotion_decision'));

        // The adapter alone never flips the row to promotable.
        $row->refresh();
        $this->assertFalse($row->promotion_allowed);
    }

    private function adapter(): HermesProcedureAdapter
    {
        return app(HermesProcedureAdapter::class);
    }

    private function job(): AiJob
    {
        return new AiJob(['payload' => ['workspace' => '/tmp/ws']]);
    }

    /**
     * @param  array<int,array<string,mixed>>  $candidates
     * @return array<string,mixed>
     */
    private function resultPacket(array $candidates): array
    {
        return [
            'result_id' => 'hermes_result_test',
            'result_hash' => 'result_hash_test',
            'procedure_gate' => [
                'schema_version' => 'atlas.hermes.procedure_gate.v1',
                'canonical_skill_authority' => 'atlas',
                'promotion_allowed_now' => false,
                'candidate_count' => count($candidates),
                'candidates' => $candidates,
            ],
        ];
    }

    /**
     * @return array<string,mixed>
     */
    private function mission(): array
    {
        return [
            'mission_id' => 'hermes_mission_test',
            'mission_hash' => 'mission_hash_test',
        ];
    }

    /**
     * @return array<string,mixed>
     */
    private function invocation(): array
    {
        return [
            'provider_cli' => 'hermes_cli',
            'command' => ['hermes', 'chat', '--quiet'],
        ];
    }

    /**
     * @param  array<string,mixed>  $overrides
     * @return array<string,mixed>
     */
    private function candidate(array $overrides = []): array
    {
        return array_merge([
            'candidate_id' => 'hermes_procedure_candidate_x',
            'name' => 'Repair flaky migration test',
            'purpose' => 'Re-run the migration and assert the table exists before seeding.',
            'steps' => ['drop table', 'run migration', 'assert schema'],
            'required_tools' => ['php artisan migrate'],
            'risk_level' => 'medium',
            'source' => 'hermes_session',
            'gate_status' => 'quarantined_for_atlas_skill_review',
            'promotion_allowed_now' => false,
        ], $overrides);
    }
}
