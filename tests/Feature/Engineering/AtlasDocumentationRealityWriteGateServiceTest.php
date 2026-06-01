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
 * are mocked so report()/driftForFrontmatter() never touch the filesystem or the
 * index; the gate is resolved from the container so it receives the mocked
 * bindings. No RefreshDatabase — this decider reads nothing from the database.
 *
 * Includes the adversarial-hardening regressions: anchored (not substring)
 * violation attribution, case-insensitive matching, naked over-claim (empty refs),
 * junk-evidence over-claim (non-empty but unresolvable refs), and the partially-
 * staged / decoupled-worktree TOCTOU refusal.
 *
 * The driftForFrontmatter() stub mirrors the real ranking just enough for routing:
 * a partial/verified doc drifts UNLESS at least one evidence_ref is a resolvable
 * "kind: ref" string (a ':' is the stub's proxy for "resolves").
 */
class AtlasDocumentationRealityWriteGateServiceTest extends TestCase
{
    private const CANON_DOC = 'docs/engineering-knowledge-base/atlas-documentation-reality-system.md';

    /** @var array<string,mixed> A resolvable frontmatter (no drift). */
    private const CLEAN_FM = ['implementation_state' => 'partial', 'evidence_refs' => ['symbol: Foo']];

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

    public function test_naked_over_claim_empty_evidence_is_blocked(): void
    {
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
        $this->assertSame('frontmatter_over_claim', $verdict['drift_blockers'][0]['source']);
        $this->assertSame(self::CANON_DOC, $verdict['drift_blockers'][0]['owner_doc']);
    }

    public function test_junk_evidence_over_claim_non_empty_but_unresolvable_is_blocked(): void
    {
        // The hole the old count-keyed naked check missed: evidence_refs non-empty
        // but all-junk (no resolvable "kind: ref"). It must still block.
        $this->stubDocsHealth();
        $this->stubTruth();

        $verdict = $this->gate()->decide([
            'touched_paths' => [self::CANON_DOC],
            'touched_frontmatter' => [
                self::CANON_DOC => ['implementation_state' => 'verified', 'evidence_refs' => ['shipped', 'verified', '10/10']],
            ],
        ]);

        $this->assertSame(AtlasDocumentationRealityWriteGateService::DECISION_BLOCKED, $verdict['decision']);
        $this->assertCount(1, $verdict['drift_blockers']);
        $this->assertSame('frontmatter_over_claim', $verdict['drift_blockers'][0]['source']);
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

    public function test_new_blocker_referencing_touched_doc_blocks_cross_doc_causation(): void
    {
        // A NEW dup-graph_id blocker is keyed to the UNTOUCHED atlas-other.md but
        // names the touched CANON_DOC as the colliding doc. Editing CANON_DOC is the
        // plausible cause, so it must BLOCK (whole-path-token attribution catches a
        // blocker that mentions a touched doc anywhere, not just as the subject).
        $this->stubDocsHealth([
            'blocking' => [
                'docs/engineering-knowledge-base/atlas-other.md: duplicate graph_id [x] already used by '.self::CANON_DOC,
            ],
        ]);
        $this->stubTruth();

        $verdict = $this->gate()->decide([
            'touched_paths' => [self::CANON_DOC],
            'touched_frontmatter' => [self::CANON_DOC => self::CLEAN_FM],
        ]);

        $this->assertSame(AtlasDocumentationRealityWriteGateService::DECISION_BLOCKED, $verdict['decision']);
        $this->assertContains(
            'docs/engineering-knowledge-base/atlas-other.md: duplicate graph_id [x] already used by '.self::CANON_DOC,
            $verdict['new_docs_health_blockers'],
        );
    }

    public function test_prefix_collision_does_not_false_block(): void
    {
        // A NEW blocker about atlas-foobar.md must NOT charge a touched atlas-foo.md.
        // Whole-path-token extraction (ending at .md) prevents the prefix match.
        $this->stubDocsHealth([
            'blocking' => [
                'docs/engineering-knowledge-base/atlas-foobar.md: missing required frontmatter field [owner]',
            ],
        ]);
        $this->stubTruth();

        $verdict = $this->gate()->decide([
            'touched_paths' => ['docs/engineering-knowledge-base/atlas-foo.md'],
            'touched_frontmatter' => ['docs/engineering-knowledge-base/atlas-foo.md' => self::CLEAN_FM],
        ]);

        $this->assertSame(AtlasDocumentationRealityWriteGateService::DECISION_ALLOWED, $verdict['decision']);
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

    public function test_touched_doc_clean_report_and_resolvable_evidence_is_allowed(): void
    {
        $this->stubDocsHealth(['blocking' => []]);
        $this->stubTruth();

        $verdict = $this->gate()->decide([
            'touched_paths' => [self::CANON_DOC],
            'touched_frontmatter' => [self::CANON_DOC => self::CLEAN_FM],
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
        $this->stubTruth();

        $verdict = $this->gate()->decide([
            'touched_paths' => [self::CANON_DOC],
            'touched_frontmatter' => [self::CANON_DOC => self::CLEAN_FM],
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
     * Stubs driftForFrontmatter(): a partial/verified doc drifts UNLESS at least
     * one evidence_ref is a resolvable "kind: ref" string (':' is the proxy for
     * "resolves"). Empty OR all-junk refs => drift, mirroring the real evaluator.
     */
    private function stubTruth(): void
    {
        $this->mock(AtlasAaeosImplementationTruthService::class, function (MockInterface $mock): void {
            $mock->shouldReceive('driftForFrontmatter')->andReturnUsing(function (string $state, $refs): array {
                $claims = in_array($state, ['verified', 'partial'], true);
                $resolves = false;
                foreach (is_array($refs) ? $refs : [] as $r) {
                    if (is_string($r) && str_contains($r, ':')) {
                        $resolves = true;
                    }
                }
                $drift = $claims && ! $resolves;

                return [
                    'claimed_state' => $claims ? $state : 'spec',
                    'computed_state' => $drift ? 'spec' : ($claims ? $state : 'spec'),
                    'drift' => $drift,
                ];
            });
        });
    }
}
