<?php

declare(strict_types=1);

namespace Tests\Feature\Engineering;

use App\Services\Engineering\AtlasDocumentationRealitySystemService;
use Illuminate\Support\Facades\File;
use ReflectionClassConstant;
use Tests\TestCase;

/**
 * FAIL-ON-STUB honesty guard for the ADRS (Atlas Documentation Reality System) self-report.
 *
 * Context: the report used to hardcode a literal 'status' => 'ready' in ~32 of its 52 evaluation
 * nodes and roll that up into a "52/52 integrated / excellent_integrated_runtime" claim. That was an
 * over-claim — most of those nodes return a constant array describing what the block WOULD do, they
 * do not compute their declared verb from real input. The honesty fix tells the truth: every block is
 * classified executes / partial / declared, and its status/execution are DERIVED, never literal.
 *
 * Each contract below must FAIL if the fix is reverted or a stub is laundered back into the
 * integrated set:
 *   (a) ZERO literal 'status' => 'ready' survives anywhere in the evaluation surface (source regex),
 *       AND no declared evaluation carries a 'ready' status at runtime.
 *   (b) every declared block reports execution='declared' + status='spec' and is NOT integrated;
 *       there are exactly 29 declared-spec blocks.
 *   (c) at least two executes blocks FLIP off 'ready' when their real input signal is planted bad —
 *       proving the status is a function of input, not a constant.
 *   (d) the headline counts are DERIVED, not pinned: recomputing the executes/partial/declared split
 *       independently (from the service's own classification constants) matches the summary, and a
 *       hypothetical reclassification moves executing vs declared in lockstep (sum stays 52).
 *   (e) no spec block leaks into integrated_runtime_block_count; 'spec' is not an integrated status;
 *       and the retracted claim_policy over-claim is honestly false with the split summing to 52.
 *
 * sqlite :memory:, extends Tests\TestCase, NO RefreshDatabase. The code-intelligence symbols table is
 * intentionally absent here, so the drift guard degrades honestly — it does not affect these contracts.
 */
final class AtlasDocumentationRealityBlockSelfHonestyTest extends TestCase
{
    private ?string $docsRoot = null;

    protected function tearDown(): void
    {
        if ($this->docsRoot !== null && File::isDirectory($this->docsRoot)) {
            File::deleteDirectory($this->docsRoot);
        }

        parent::tearDown();
    }

    // ----------------------------------------------------------------------------------------------
    // (a) ZERO LITERAL STATUS IN THE EVALUATION SURFACE + no declared 'ready' at runtime.
    // ----------------------------------------------------------------------------------------------
    public function test_a_zero_literal_ready_status_in_evaluation_surface_and_no_declared_ready_at_runtime(): void
    {
        // Read the real service source from the reflected path so the assertion tracks the file
        // actually loaded, not a hardcoded path.
        $sourcePath = (new \ReflectionClass(AtlasDocumentationRealitySystemService::class))->getFileName();
        $this->assertIsString($sourcePath);
        $source = File::get($sourcePath);

        // Scope to the evaluation surface: from `private function evaluations(` to the start of the
        // chokepoint classifier `isIntegratedRuntimeBlock`. Every *Evaluation() method and the inline
        // implementation_readiness_matrix node live inside this region; the historical-context comment
        // on the class constants lives ABOVE it and is correctly excluded.
        $start = strpos($source, 'private function evaluations(');
        $end = strpos($source, 'private function isIntegratedRuntimeBlock(');
        $this->assertIsInt($start, 'evaluations() method must exist');
        $this->assertIsInt($end, 'isIntegratedRuntimeBlock() chokepoint must exist');
        $this->assertGreaterThan($start, $end);
        $region = substr($source, $start, $end - $start);

        $matched = preg_match_all("/'status'\\s*=>\\s*'ready'/", $region, $hits);
        $this->assertSame(
            0,
            $matched,
            "A literal \"'status' => 'ready'\" must not appear in any evaluation method; ".
            'every status must be DERIVED or the honest literal "spec". Found: '.var_export($hits[0] ?? [], true)
        );

        // Defence-in-depth: also assert no literal 'review'/'blocked' constant is written as a status.
        $this->assertSame(0, preg_match_all("/'status'\\s*=>\\s*'review',/", $region));
        $this->assertSame(0, preg_match_all("/'status'\\s*=>\\s*'blocked',/", $region));

        // RUNTIME half: no declared evaluation may carry status 'ready'. A declared block is a spec.
        $payload = app(AtlasDocumentationRealitySystemService::class)->report();
        foreach ($payload['evaluations'] as $key => $evaluation) {
            if (($evaluation['execution'] ?? null) === 'declared') {
                $this->assertSame('spec', $evaluation['status'] ?? null, "declared evaluation {$key} must report status 'spec'");
            }
        }
    }

    // ----------------------------------------------------------------------------------------------
    // (b) EVERY DECLARED BLOCK reports spec/declared and is NOT integrated; exactly 29 of them.
    // ----------------------------------------------------------------------------------------------
    public function test_b_every_declared_block_reports_spec_and_is_not_integrated(): void
    {
        $payload = app(AtlasDocumentationRealitySystemService::class)->report();
        $evaluations = $payload['evaluations'];

        $declaredBlocks = [];
        foreach ($payload['blocks'] as $block) {
            $ref = $block['evaluation_ref'] ?? null;
            $execution = $block['execution'] ?? null;
            if ($execution !== 'declared') {
                continue;
            }
            $declaredBlocks[] = $block['name'];

            // The backing evaluation is a spec, not a green runtime verdict.
            $this->assertNotNull($ref, $block['name']);
            $this->assertSame('spec', $evaluations[$ref]['status'] ?? null, $block['name']);
            $this->assertSame('declared', $evaluations[$ref]['execution'] ?? null, $block['name']);

            // A declared block must never be integrated.
            $this->assertNotSame('L4_integrated', $block['readiness_level'], $block['name']);
            $this->assertNull($block['integration_evidence'], $block['name']);
        }

        $this->assertCount(29, $declaredBlocks, 'exactly 29 blocks must be honestly declared specs');
    }

    // ----------------------------------------------------------------------------------------------
    // (c) EXECUTES blocks FLIP off 'ready' when their REAL signal is planted bad.
    // ----------------------------------------------------------------------------------------------
    public function test_c_executes_blocks_flip_off_ready_when_real_signal_is_planted_bad(): void
    {
        $service = app(AtlasDocumentationRealitySystemService::class);

        // Build an isolated docs root that is a faithful copy of the real canonical docs, so the
        // BASELINE renders these executes blocks green — exactly as the live report does.
        $this->docsRoot = base_path('storage/framework/testing/adrs-honesty-'.uniqid());
        File::copyDirectory(base_path('docs/engineering-knowledge-base'), $this->docsRoot);

        $baseline = $service->report($this->docsRoot)['evaluations'];
        // Sanity: with healthy docs these executes evaluators derive 'ready'. If they do not, the
        // planted-bad assertion below would be meaningless, so pin the baseline first.
        $this->assertSame('ready', $baseline['documentation_budget_governor']['status']);
        $this->assertSame('ready', $baseline['documentation_operating_system']['status']);
        $this->assertSame('ready', $baseline['auto_split_planner']['status']);
        $this->assertSame('ready', $baseline['documentation_lifecycle_state_machine']['status']);
        // And they are all genuinely 'executes', not declared specs.
        $this->assertSame('executes', $baseline['documentation_budget_governor']['execution']);
        $this->assertSame('executes', $baseline['auto_split_planner']['execution']);
        $this->assertSame('executes', $baseline['documentation_lifecycle_state_machine']['execution']);

        // PLANT bad input signal #1 — make the mother doc oversized (> 520 lines). This is the real
        // signal that documentation_budget_governor, documentation_operating_system and
        // auto_split_planner each compute over the source registry.
        $mother = $this->docsRoot.'/atlas-documentation-reality-system.md';
        $padding = str_repeat("\nfiller line to push this canonical doc over the 520-line budget\n", 60);
        File::append($mother, $padding);

        // PLANT bad input signal #2 — corrupt a canonical doc's frontmatter status to an illegal
        // value. This is the real signal lifecycleEvaluation() computes over allowed states.
        $registry = $this->docsRoot.'/atlas-documentation-reality-block-registry.md';
        File::put($registry, str_replace(
            'status: active',
            'status: bogus_illegal_lifecycle_state',
            File::get($registry),
        ));

        $planted = $service->report($this->docsRoot)['evaluations'];

        // The executes verdicts must now be DERIVED-bad — a function of the planted input, NOT a
        // constant 'ready'. If any of these were a hardcoded literal, they would still read 'ready'
        // and this test would fail (which is the point).
        $this->assertNotSame('ready', $planted['documentation_budget_governor']['status']);
        $this->assertSame('review', $planted['documentation_budget_governor']['status']);

        $this->assertNotSame('ready', $planted['documentation_operating_system']['status']);
        $this->assertSame('blocked', $planted['documentation_operating_system']['status']);

        $this->assertNotSame('ready', $planted['auto_split_planner']['status']);
        $this->assertSame('review', $planted['auto_split_planner']['status']);

        $this->assertNotSame('ready', $planted['documentation_lifecycle_state_machine']['status']);
        $this->assertSame('blocked', $planted['documentation_lifecycle_state_machine']['status']);
    }

    // ----------------------------------------------------------------------------------------------
    // (d) The headline split is DERIVED, not hardcoded — recompute it independently and match.
    // ----------------------------------------------------------------------------------------------
    public function test_d_summary_counts_are_derived_from_classification_not_hardcoded(): void
    {
        $payload = app(AtlasDocumentationRealitySystemService::class)->report();
        $summary = $payload['summary'];

        // Pull the service's OWN classification constants by reflection — the single source of truth.
        $executingKeys = (new ReflectionClassConstant(AtlasDocumentationRealitySystemService::class, 'EXECUTING_EVALUATION_KEYS'))->getValue();
        $partialKeys = (new ReflectionClassConstant(AtlasDocumentationRealitySystemService::class, 'PARTIAL_EVALUATION_KEYS'))->getValue();
        $this->assertIsArray($executingKeys);
        $this->assertIsArray($partialKeys);

        // Recompute the split independently from the per-block execution + evaluation_ref the report
        // already exposes. If the summary hardcoded "11" but the classification ever drifts, these
        // independent counts diverge and the test fails. The EXECUTES TIER is independent of pass/fail;
        // INTEGRATION is the subset of the executes tier that reached L4.
        $expectExecutingTier = 0;
        $expectIntegrated = 0;
        $expectPartial = 0;
        $expectDeclared = 0;
        foreach ($payload['blocks'] as $block) {
            $ref = $block['evaluation_ref'] ?? null;
            if ($ref !== null && in_array($ref, $executingKeys, true)) {
                $this->assertSame('executes', $block['execution'], $block['name']);
                $expectExecutingTier++;
                if ($block['readiness_level'] === 'L4_integrated') {
                    $expectIntegrated++;
                }
            } elseif ($ref !== null && in_array($ref, $partialKeys, true)) {
                $this->assertSame('partial', $block['execution'], $block['name']);
                $expectPartial++;
            } else {
                $this->assertSame('declared', $block['execution'], $block['name']);
                $expectDeclared++;
            }
        }

        $this->assertSame($expectExecutingTier, $summary['executing_block_count'], 'executing_block_count must be the derived executes tier, not pinned');
        $this->assertSame($expectIntegrated, $summary['integrated_runtime_block_count'], 'integrated == executes-and-passing, derived');
        $this->assertSame($expectPartial, $summary['partial_runtime_block_count'], 'partial_runtime_block_count must be derived');
        $this->assertSame($expectDeclared, $summary['declared_spec_block_count'], 'declared_spec_block_count must be derived');

        // The three-way split is a true partition of all 52 blocks.
        $this->assertSame(
            52,
            $summary['executing_block_count'] + $summary['partial_runtime_block_count'] + $summary['declared_spec_block_count'],
            'executing + partial + declared must sum to 52',
        );

        // Reclassification check (ungameable): if ONE declared key were promoted to executing, the
        // executing count would rise by one and declared fall by one, with the sum preserved. We
        // assert the lockstep relationship holds for the live numbers so a divergent hardcode fails.
        $this->assertSame(
            52 - $summary['executing_block_count'] - $summary['partial_runtime_block_count'],
            $summary['declared_spec_block_count'],
            'declared = 52 - executing - partial (a hardcoded count would break this identity)',
        );
    }

    // ----------------------------------------------------------------------------------------------
    // (e) NO spec block in integrated_runtime_block_count; 'spec' is not an integrated status.
    // ----------------------------------------------------------------------------------------------
    public function test_e_no_spec_block_is_counted_as_integrated_runtime(): void
    {
        $payload = app(AtlasDocumentationRealitySystemService::class)->report();
        $summary = $payload['summary'];

        // integrated count == executing count (no laundering of specs/partials into integration).
        $this->assertSame($summary['executing_block_count'], $summary['integrated_runtime_block_count']);

        // None of the declared-spec blocks may appear among L4_integrated blocks.
        $integratedNames = collect($payload['blocks'])
            ->filter(static fn (array $block): bool => $block['readiness_level'] === 'L4_integrated')
            ->pluck('name')
            ->all();
        $declaredNames = collect($payload['blocks'])
            ->filter(static fn (array $block): bool => ($block['execution'] ?? null) === 'declared')
            ->pluck('name')
            ->all();
        $this->assertSame([], array_values(array_intersect($integratedNames, $declaredNames)), 'no declared spec block may be L4_integrated');
        $this->assertCount($summary['executing_block_count'], $integratedNames);

        // 'spec' must NEVER be a member of the integrated status set (reflection on the private const).
        $integratedStatuses = (new ReflectionClassConstant(AtlasDocumentationRealitySystemService::class, 'INTEGRATED_EVALUATION_STATUSES'))->getValue();
        $this->assertIsArray($integratedStatuses);
        $this->assertNotContains('spec', $integratedStatuses, "'spec' must not be an integrated status");

        // The retracted over-claim is honestly false, and claim_policy surfaces the split summing to 52.
        $claimPolicy = $payload['claim_policy'];
        $this->assertFalse($claimPolicy['declares_all_52_adrs_blocks_integrated']);
        $this->assertSame(
            52,
            $claimPolicy['executing_block_count'] + $claimPolicy['partial_runtime_block_count'] + $claimPolicy['declared_spec_block_count'],
        );
    }
}
