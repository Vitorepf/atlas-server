<?php

declare(strict_types=1);

namespace Tests\Feature\Engineering;

use App\Models\AiOutcomeLink;
use App\Services\Ai\AgenticEngineeringOs\Maturity\AtlasImplementationTruthService;
use App\Services\Engineering\AtlasDocumentationRealityOutcomeGroundingService;
use Illuminate\Support\Facades\Schema;
use Mockery\MockInterface;
use Tests\TestCase;

/**
 * L2-O1 (first increment) — the outcome-grounding scorer. ledger() is mocked for
 * determinism and the outcome lookup is driven through a real in-memory
 * ai_outcome_links table the test controls. No RefreshDatabase.
 *
 * The cardinal rule under test: outcome_grounded is reachable ONLY when a real,
 * resolved signal backs it; absence => implemented_no_outcome_signal; a
 * spec/not-implemented doc => not_implemented; no source => no-signal for all +
 * outcome_signal_source_available=false. The scorer never fabricates an outcome.
 */
final class AtlasDocumentationRealityOutcomeGroundingTest extends TestCase
{
    protected function tearDown(): void
    {
        Schema::dropIfExists('ai_outcome_links');
        parent::tearDown();
    }

    public function test_implemented_capability_with_real_outcome_signal_is_outcome_grounded(): void
    {
        $this->createOutcomeLinksTable();
        $this->insertResolvedLink(targetType: 'capability', targetId: 'atlas-impl-doc');

        $this->stubLedger($this->ledgerWith($this->implementedRow('atlas-impl-doc')));

        $payload = app(AtlasDocumentationRealityOutcomeGroundingService::class)->gradeAll();

        $this->assertSame(AtlasDocumentationRealityOutcomeGroundingService::SCHEMA, $payload['schema_version']);
        $this->assertTrue(data_get($payload, 'summary.outcome_signal_source_available'));
        $this->assertSame(1, data_get($payload, 'summary.outcome_grounded_count'));
        $this->assertSame(0, data_get($payload, 'summary.implemented_no_outcome_count'));

        $grade = $payload['grades'][0];
        $this->assertSame(AtlasDocumentationRealityOutcomeGroundingService::GRADE_OUTCOME_GROUNDED, $grade['grade']);
        $this->assertTrue($grade['outcome_grounded']);
        $this->assertNotEmpty($grade['outcome_signals']);
        $this->assertSame('ai_outcome_links', $grade['outcome_signals'][0]['source']);
        $this->assertSame('atlas-impl-doc', $grade['outcome_signals'][0]['target_id']);
    }

    public function test_implemented_capability_with_no_signal_is_implemented_no_outcome_signal_not_grounded(): void
    {
        // Source table EXISTS but holds no row that ties to this capability.
        $this->createOutcomeLinksTable();
        $this->insertResolvedLink(targetType: 'capability', targetId: 'some-other-capability');

        $this->stubLedger($this->ledgerWith($this->implementedRow('atlas-impl-doc')));

        $payload = app(AtlasDocumentationRealityOutcomeGroundingService::class)->gradeAll();

        $this->assertTrue(data_get($payload, 'summary.outcome_signal_source_available'));
        $this->assertSame(0, data_get($payload, 'summary.outcome_grounded_count'));
        $this->assertSame(1, data_get($payload, 'summary.implemented_no_outcome_count'));

        $grade = $payload['grades'][0];
        $this->assertSame(AtlasDocumentationRealityOutcomeGroundingService::GRADE_NO_SIGNAL, $grade['grade']);
        $this->assertFalse($grade['outcome_grounded']);
        $this->assertSame([], $grade['outcome_signals']);
    }

    public function test_negative_outcome_grades_implemented_negative_not_grounded(): void
    {
        // The cardinal L2 sin: a FAILURE/rejection outcome (human_marked_unsafe)
        // must NEVER be read as "it worked in the world". It grades
        // implemented_negative_outcome — never outcome_grounded.
        $this->createOutcomeLinksTable();
        $this->insertResolvedLink(targetType: 'capability', targetId: 'atlas-impl-doc', outcomeType: 'human_marked_unsafe');

        $this->stubLedger($this->ledgerWith($this->implementedRow('atlas-impl-doc')));

        $payload = app(AtlasDocumentationRealityOutcomeGroundingService::class)->gradeAll();

        $this->assertSame(0, data_get($payload, 'summary.outcome_grounded_count'));
        $this->assertSame(1, data_get($payload, 'summary.implemented_negative_outcome_count'));

        $grade = $payload['grades'][0];
        $this->assertSame(AtlasDocumentationRealityOutcomeGroundingService::GRADE_NEGATIVE_OUTCOME, $grade['grade']);
        $this->assertFalse($grade['outcome_grounded']);
        $this->assertSame('real_outcome_signal_but_world_pushed_back', $grade['reason']);
    }

    public function test_future_dated_outcome_does_not_ground_the_capability(): void
    {
        // A future-dated occurred_at is not a settled past outcome and must not score.
        $this->createOutcomeLinksTable();
        $this->insertResolvedLink(targetType: 'capability', targetId: 'atlas-impl-doc', occurredAt: '2099-01-01 00:00:00');

        $this->stubLedger($this->ledgerWith($this->implementedRow('atlas-impl-doc')));

        $payload = app(AtlasDocumentationRealityOutcomeGroundingService::class)->gradeAll();

        $this->assertSame(0, data_get($payload, 'summary.outcome_grounded_count'));
        $this->assertSame(1, data_get($payload, 'summary.implemented_no_outcome_count'));
    }

    public function test_an_unresolved_link_without_occurred_at_does_not_ground_the_capability(): void
    {
        // A link that targets the capability but never actually occurred is NOT a
        // real settled world outcome and must not score it.
        $this->createOutcomeLinksTable();
        $this->insertResolvedLink(targetType: 'capability', targetId: 'atlas-impl-doc', occurredAt: null);

        $this->stubLedger($this->ledgerWith($this->implementedRow('atlas-impl-doc')));

        $payload = app(AtlasDocumentationRealityOutcomeGroundingService::class)->gradeAll();

        $this->assertSame(0, data_get($payload, 'summary.outcome_grounded_count'));
        $this->assertSame(1, data_get($payload, 'summary.implemented_no_outcome_count'));
        $this->assertSame(
            AtlasDocumentationRealityOutcomeGroundingService::GRADE_NO_SIGNAL,
            $payload['grades'][0]['grade'],
        );
    }

    public function test_spec_capability_is_not_implemented_and_never_outcome_grounded(): void
    {
        // Even with a real signal sitting in the table, a spec/not-implemented doc is
        // not eligible — you cannot ask "did it work in the world?" of a north-star.
        $this->createOutcomeLinksTable();
        $this->insertResolvedLink(targetType: 'capability', targetId: 'atlas-spec-doc');

        $this->stubLedger($this->ledgerWith($this->specRow('atlas-spec-doc')));

        $payload = app(AtlasDocumentationRealityOutcomeGroundingService::class)->gradeAll();

        $this->assertSame(0, data_get($payload, 'summary.outcome_grounded_count'));
        $this->assertSame(0, data_get($payload, 'summary.implemented_no_outcome_count'));
        $this->assertSame(1, data_get($payload, 'summary.not_implemented_count'));

        $grade = $payload['grades'][0];
        $this->assertSame(AtlasDocumentationRealityOutcomeGroundingService::GRADE_NOT_IMPLEMENTED, $grade['grade']);
        $this->assertFalse($grade['outcome_grounded']);
        $this->assertSame([], $grade['outcome_signals']);
    }

    public function test_in_drift_capability_is_not_outcome_graded(): void
    {
        // An implemented-but-over-claiming (drift) doc is not eligible for outcome
        // grading either — internal truth must be clean first.
        $this->createOutcomeLinksTable();
        $this->insertResolvedLink(targetType: 'capability', targetId: 'atlas-drift-doc');

        $row = $this->implementedRow('atlas-drift-doc');
        $row['drift'] = true;
        $this->stubLedger($this->ledgerWith($row));

        $payload = app(AtlasDocumentationRealityOutcomeGroundingService::class)->gradeAll();

        $this->assertSame(0, data_get($payload, 'summary.outcome_grounded_count'));
        $this->assertSame(1, data_get($payload, 'summary.not_implemented_count'));
        $this->assertSame(
            AtlasDocumentationRealityOutcomeGroundingService::GRADE_NOT_IMPLEMENTED,
            $payload['grades'][0]['grade'],
        );
        $this->assertSame('in_drift_not_eligible_for_outcome_grading', $payload['grades'][0]['reason']);
    }

    public function test_absent_outcome_source_makes_every_implemented_doc_no_signal(): void
    {
        // No ai_outcome_links table at all: we cannot observe outcomes, so every
        // implemented doc is honestly no-signal and the envelope says so.
        Schema::dropIfExists('ai_outcome_links');
        $this->assertFalse(Schema::hasTable('ai_outcome_links'));

        $this->stubLedger($this->ledgerWith(
            $this->implementedRow('atlas-impl-a'),
            $this->implementedRow('atlas-impl-b'),
        ));

        $payload = app(AtlasDocumentationRealityOutcomeGroundingService::class)->gradeAll();

        $this->assertFalse(data_get($payload, 'summary.outcome_signal_source_available'));
        $this->assertSame(0, data_get($payload, 'summary.outcome_grounded_count'));
        $this->assertSame(2, data_get($payload, 'summary.implemented_no_outcome_count'));
        foreach ($payload['grades'] as $grade) {
            $this->assertSame(AtlasDocumentationRealityOutcomeGroundingService::GRADE_NO_SIGNAL, $grade['grade']);
            $this->assertFalse($grade['outcome_grounded']);
            $this->assertSame('outcome_signal_source_unavailable', $grade['reason']);
        }
    }

    public function test_envelope_never_reports_grounded_without_a_real_signal_backing_it(): void
    {
        // No source + an implemented doc: grounded count MUST be zero. This is the
        // anti-fabrication invariant — grounded > 0 here would be a lie.
        Schema::dropIfExists('ai_outcome_links');

        $this->stubLedger($this->ledgerWith($this->implementedRow('atlas-impl-doc')));

        $payload = app(AtlasDocumentationRealityOutcomeGroundingService::class)->gradeAll();

        $this->assertSame(0, data_get($payload, 'summary.outcome_grounded_count'));

        // Every grounded grade in the list (there are none) must carry a real signal.
        foreach ($payload['grades'] as $grade) {
            if ($grade['outcome_grounded'] === true) {
                $this->assertNotEmpty($grade['outcome_signals'], 'outcome_grounded must always carry a real signal');
            }
        }

        // Policy is explicit and honest.
        $this->assertFalse($payload['writes']);
        $this->assertTrue($payload['claim_policy']['read_only']);
        $this->assertFalse($payload['claim_policy']['fabricates_outcome']);
        $this->assertFalse($payload['claim_policy']['infers_outcome']);
        $this->assertTrue($payload['claim_policy']['outcome_grounded_requires_real_signal']);
        $this->assertIsString($payload['outcome_grounding_hash']);
    }

    public function test_grade_for_doc_passes_the_capability_filter_to_the_ledger(): void
    {
        Schema::dropIfExists('ai_outcome_links');

        $this->mock(AtlasImplementationTruthService::class, function (MockInterface $mock): void {
            $mock->shouldReceive('ledger')
                ->once()
                ->with('atlas-impl-doc')
                ->andReturn($this->ledgerWith($this->implementedRow('atlas-impl-doc')));
        });

        $payload = app(AtlasDocumentationRealityOutcomeGroundingService::class)
            ->gradeForDoc('atlas-impl-doc');

        $this->assertSame('atlas-impl-doc', $payload['capability_filter']);
        $this->assertSame(1, data_get($payload, 'summary.evaluated'));
    }

    // --- helpers -----------------------------------------------------------

    /**
     * @param  array<string,mixed>  $ledger
     */
    private function stubLedger(array $ledger): void
    {
        $this->mock(AtlasImplementationTruthService::class, function (MockInterface $mock) use ($ledger): void {
            $mock->shouldReceive('ledger')->andReturn($ledger);
        });
    }

    /**
     * @param  array<string,mixed>  ...$rows
     * @return array<string,mixed>
     */
    private function ledgerWith(array ...$rows): array
    {
        return [
            'schema_version' => AtlasImplementationTruthService::LEDGER_SCHEMA,
            'summary' => [
                'evaluated' => count($rows),
                'drift_count' => count(array_filter($rows, static fn (array $r): bool => (bool) ($r['drift'] ?? false))),
                'by_computed_state' => ['spec' => 0, 'partial' => 0, 'verified' => 0],
                'test_resolution' => 'existence_only',
            ],
            'capabilities' => array_values($rows),
        ];
    }

    /**
     * @return array<string,mixed>
     */
    private function implementedRow(string $id): array
    {
        return [
            'capability_id' => $id,
            'owner_doc' => "docs/engineering-knowledge-base/{$id}.md",
            'claimed_state' => 'partial',
            'computed_state' => 'partial',
            'drift' => false,
            'under_claim' => false,
            'unmet_evidence' => [],
            'evidence' => [],
        ];
    }

    /**
     * @return array<string,mixed>
     */
    private function specRow(string $id): array
    {
        return [
            'capability_id' => $id,
            'owner_doc' => "docs/engineering-knowledge-base/{$id}.md",
            'claimed_state' => 'spec',
            'computed_state' => 'spec',
            'drift' => false,
            'under_claim' => false,
            'unmet_evidence' => ['needs >=1 resolved symbol (class/method) for partial'],
            'evidence' => [],
        ];
    }

    private function createOutcomeLinksTable(): void
    {
        Schema::dropIfExists('ai_outcome_links');
        Schema::create('ai_outcome_links', function ($table): void {
            $table->uuid('id')->primary();
            $table->uuid('trace_id')->nullable();
            $table->uuid('thread_id')->nullable();
            $table->uuid('session_id')->nullable();
            $table->string('outcome_type', 80);
            $table->string('target_type', 80)->nullable();
            $table->string('target_id', 255)->nullable();
            $table->unsignedSmallInteger('value_score')->nullable();
            $table->decimal('confidence', 4, 3)->nullable();
            $table->string('source', 40)->default('system');
            $table->timestamp('occurred_at')->nullable();
            $table->json('metadata')->nullable();
            $table->timestamp('created_at')->nullable();
        });
    }

    private function insertResolvedLink(
        string $targetType,
        string $targetId,
        ?string $occurredAt = '2026-05-30 12:00:00',
        string $outcomeType = 'capability_adopted',
    ): void {
        AiOutcomeLink::query()->create([
            'outcome_type' => $outcomeType,
            'target_type' => $targetType,
            'target_id' => $targetId,
            'value_score' => 80,
            'confidence' => 0.9,
            'source' => 'system',
            'occurred_at' => $occurredAt,
            'metadata' => ['capability_id' => $targetId],
            'created_at' => '2026-05-30 12:00:00',
        ]);
    }
}
