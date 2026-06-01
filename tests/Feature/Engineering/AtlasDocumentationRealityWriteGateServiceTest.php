<?php

declare(strict_types=1);

namespace Tests\Feature\Engineering;

use App\Services\Ai\Aaeos\AtlasAaeosImplementationTruthService;
use App\Services\Engineering\AtlasDocumentationRealityWriteGateService;
use App\Services\Engineering\EngineeringDocumentationHealthService;
use Mockery\MockInterface;
use RuntimeException;
use Tests\TestCase;

/**
 * Pure-decider coverage for the ADRS L0 write-bound gate. The two collaborators
 * are mocked so report()/ledger()/compute() never touch the filesystem or the
 * index; the gate is resolved from the container so it receives the mocked
 * bindings. No RefreshDatabase — this decider reads nothing from the database.
 *
 * Includes the adversarial-hardening regressions: anchored (not substring)
 * violation attribution, case-insensitive matching, naked over-claim, and the
 * partially-staged TOCTOU refusal.
 */
class AtlasDocumentationRealityWriteGateServiceTest extends TestCase
{
    private const CANON_DOC = 'docs/engineering-knowledge-base/atlas-documentation-reality-system.md';

    public function test_read_only_change_is_allowed(): void
    {
        $this->stubDocsHealth();
        $this->stubTruth();

        $verdict = $this->gate()->decide([
            'touched_paths' => [self::CANON_DOC],
            'is_mutating' => false,
        ]);

        $this->assertSame(AtlasDocumentationRealityWriteGateService::DECISION_ALLOWED, $verdict['decision']);
        $this->assertSame('read_only_allowed', $verdict['reason']);
        $this->assertNull($verdict['blocker']);
    }

    public function test_empty_touched_paths_is_allowed(): void
    {
        $this->stubDocsHealth();
        $this->stubTruth();

        $verdict = $this->gate()->decide(['touched_paths' => []]);

        $this->assertSame(AtlasDocumentationRealityWriteGateService::DECISION_ALLOWED, $verdict['decision']);
        $this->assertSame('no_canonical_docs_touched', $verdict['reason']);
        $this->assertSame(0, $verdict['touched_doc_count']);
    }

    public function test_code_only_change_passes_the_doc_gate(): void
    {
        $this->stubDocsHealth();
        $this->stubTruth();

        $verdict = $this->gate()->decide([
            'touched_paths' => ['app/Foo.php'],
        ]);

        $this->assertSame(AtlasDocumentationRealityWriteGateService::DECISION_ALLOWED, $verdict['decision']);
        $this->assertSame('no_canonical_docs_touched', $verdict['reason']);
        $this->assertSame(0, $verdict['touched_doc_count']);
    }

    public function test_touched_doc_with_new_docs_health_blocker_is_blocked(): void
    {
        $this->stubDocsHealth([
            'blocking' => [
                self::CANON_DOC.': missing required frontmatter field [owner]',
            ],
        ]);
        $this->stubTruth();

        $verdict = $this->gate()->decide([
            'touched_paths' => [self::CANON_DOC],
        ]);

        $this->assertSame(AtlasDocumentationRealityWriteGateService::DECISION_BLOCKED, $verdict['decision']);
        $this->assertSame(AtlasDocumentationRealityWriteGateService::BLOCKER, $verdict['blocker']);
        $this->assertContains(
            self::CANON_DOC.': missing required frontmatter field [owner]',
            $verdict['new_docs_health_blockers'],
        );
    }

    public function test_touched_doc_with_ledger_over_claim_drift_is_blocked(): void
    {
        $this->stubDocsHealth();
        $this->stubTruth([
            'capabilities' => [
                [
                    'owner_doc' => self::CANON_DOC,
                    'claimed_state' => 'verified',
                    'computed_state' => 'partial',
                    'drift' => true,
                ],
            ],
        ]);

        $verdict = $this->gate()->decide([
            'touched_paths' => [self::CANON_DOC],
        ]);

        $this->assertSame(AtlasDocumentationRealityWriteGateService::DECISION_BLOCKED, $verdict['decision']);
        $this->assertSame(AtlasDocumentationRealityWriteGateService::BLOCKER, $verdict['blocker']);
        $this->assertCount(1, $verdict['drift_blockers']);
        $this->assertSame(self::CANON_DOC, $verdict['drift_blockers'][0]['owner_doc']);
        $this->assertSame('ledger_evidence_drift', $verdict['drift_blockers'][0]['source']);
    }

    public function test_touched_doc_with_naked_over_claim_no_evidence_is_blocked(): void
    {
        // Clean docs-health, doc absent from the ledger (no evidence_refs), but the
        // frontmatter claims runtime (verified) with NO evidence — the bypass the
        // ledger skips. compute(state, []) makes it drift.
        $this->stubDocsHealth();
        $this->stubTruth();

        $verdict = $this->gate()->decide([
            'touched_paths' => [self::CANON_DOC],
            'touched_frontmatter' => [
                self::CANON_DOC => ['implementation_state' => 'verified', 'evidence_refs' => []],
            ],
        ]);

        $this->assertSame(AtlasDocumentationRealityWriteGateService::DECISION_BLOCKED, $verdict['decision']);
        $this->assertCount(1, $verdict['drift_blockers']);
        $this->assertSame('naked_claim_no_evidence', $verdict['drift_blockers'][0]['source']);
        $this->assertSame(self::CANON_DOC, $verdict['drift_blockers'][0]['owner_doc']);
    }

    public function test_partially_staged_touched_doc_yields_needs_review(): void
    {
        $this->stubDocsHealth();
        $this->stubTruth();

        $verdict = $this->gate()->decide([
            'touched_paths' => [self::CANON_DOC],
            'partially_staged_paths' => [self::CANON_DOC],
        ]);

        $this->assertSame(AtlasDocumentationRealityWriteGateService::DECISION_NEEDS_REVIEW, $verdict['decision']);
        $this->assertSame(AtlasDocumentationRealityWriteGateService::BLOCKER, $verdict['blocker']);
        $this->assertSame('partially_staged_canonical_doc_index_differs_from_worktree', $verdict['reason']);
    }

    public function test_violation_embedding_another_docs_path_does_not_block_a_clean_touched_doc(): void
    {
        // A docs-health message for atlas-other.md EMBEDS CANON_DOC's path in its
        // body. Anchored attribution (subject token only) must NOT charge the clean
        // touched CANON_DOC for atlas-other.md's violation.
        $this->stubDocsHealth([
            'blocking' => [
                'docs/engineering-knowledge-base/atlas-other.md: duplicate graph_id [x] already used by '.self::CANON_DOC,
            ],
        ]);
        $this->stubTruth();

        $verdict = $this->gate()->decide([
            'touched_paths' => [self::CANON_DOC],
        ]);

        $this->assertSame(AtlasDocumentationRealityWriteGateService::DECISION_ALLOWED, $verdict['decision']);
        $this->assertSame('touched_docs_clean', $verdict['reason']);
        $this->assertSame([], $verdict['new_docs_health_blockers']);
    }

    public function test_case_variant_touched_path_still_attributes_its_violation(): void
    {
        $caseVariant = 'docs/engineering-knowledge-base/Atlas-Documentation-Reality-System.md';

        $this->stubDocsHealth([
            'blocking' => [
                self::CANON_DOC.': missing required frontmatter field [owner]',
            ],
        ]);
        $this->stubTruth();

        $verdict = $this->gate()->decide([
            'touched_paths' => [$caseVariant],
        ]);

        $this->assertSame(AtlasDocumentationRealityWriteGateService::DECISION_BLOCKED, $verdict['decision']);
        $this->assertSame(1, $verdict['touched_doc_count']);
        $this->assertNotSame([], $verdict['new_docs_health_blockers']);
    }

    public function test_touched_doc_clean_report_and_ledger_is_allowed(): void
    {
        $this->stubDocsHealth(['blocking' => []]);
        $this->stubTruth([
            'capabilities' => [
                [
                    'owner_doc' => self::CANON_DOC,
                    'claimed_state' => 'partial',
                    'computed_state' => 'partial',
                    'drift' => false,
                ],
            ],
        ]);

        $verdict = $this->gate()->decide([
            'touched_paths' => [self::CANON_DOC],
            'touched_frontmatter' => [
                self::CANON_DOC => ['implementation_state' => 'partial', 'evidence_refs' => ['symbol: Foo']],
            ],
        ]);

        $this->assertSame(AtlasDocumentationRealityWriteGateService::DECISION_ALLOWED, $verdict['decision']);
        $this->assertSame('touched_docs_clean', $verdict['reason']);
        $this->assertNull($verdict['blocker']);
        $this->assertSame([], $verdict['new_docs_health_blockers']);
        $this->assertSame([], $verdict['drift_blockers']);
    }

    public function test_report_throwing_yields_needs_review(): void
    {
        $this->mock(EngineeringDocumentationHealthService::class, function (MockInterface $mock): void {
            $mock->shouldReceive('report')->andThrow(new RuntimeException('index unavailable'));
        });
        $this->mock(AtlasAaeosImplementationTruthService::class, function (MockInterface $mock): void {
            $mock->shouldReceive('ledger')->andReturn(['capabilities' => []]);
            $mock->shouldReceive('compute')->andReturn(['claimed_state' => 'spec', 'computed_state' => 'spec', 'drift' => false]);
        });

        $verdict = $this->gate()->decide([
            'touched_paths' => [self::CANON_DOC],
        ]);

        $this->assertSame(AtlasDocumentationRealityWriteGateService::DECISION_NEEDS_REVIEW, $verdict['decision']);
        $this->assertSame(AtlasDocumentationRealityWriteGateService::BLOCKER, $verdict['blocker']);
        $this->assertStringStartsWith('gate_inputs_unavailable_', $verdict['reason']);
    }

    private function gate(): AtlasDocumentationRealityWriteGateService
    {
        return app(AtlasDocumentationRealityWriteGateService::class);
    }

    /**
     * @param  array<string,mixed>  $report
     */
    private function stubDocsHealth(array $report = ['blocking' => []]): void
    {
        $this->mock(EngineeringDocumentationHealthService::class, function (MockInterface $mock) use ($report): void {
            $mock->shouldReceive('report')->andReturn($report);
        });
    }

    /**
     * Stubs ledger() (corpus over-claim rows) and compute() (used by the naked
     * over-claim path with EMPTY refs). The compute stub mirrors the real ranking:
     * partial/verified with empty refs => drift; everything else => no drift.
     *
     * @param  array<string,mixed>  $ledger
     */
    private function stubTruth(array $ledger = ['capabilities' => []]): void
    {
        $this->mock(AtlasAaeosImplementationTruthService::class, function (MockInterface $mock) use ($ledger): void {
            $mock->shouldReceive('ledger')->andReturn($ledger);
            $mock->shouldReceive('compute')->andReturnUsing(function (string $state, array $refs): array {
                $claims = in_array($state, ['verified', 'partial'], true);
                $drift = $claims && $refs === [];

                return [
                    'claimed_state' => $claims ? $state : 'spec',
                    'computed_state' => $drift ? 'spec' : ($claims ? $state : 'spec'),
                    'drift' => $drift,
                ];
            });
        });
    }
}
