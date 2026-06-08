<?php

declare(strict_types=1);

namespace Tests\Feature\Engineering;

use App\Models\AtlasAaeosTestRunReceipt;
use App\Models\AtlasEngineeringCodeSymbol;
use App\Services\Ai\Aaeos\AtlasAaeosImplementationTruthService;
use App\Services\Engineering\AtlasDocumentationRealityAutoHealService;
use App\Services\Engineering\AtlasDocumentationRealityWriteGateService;
use App\Services\Semantic\CanonicalDocsFrontmatterParser;
use Illuminate\Support\Facades\File;
use Illuminate\Support\Facades\Schema;
use Symfony\Component\Process\Process;
use Tests\TestCase;

/**
 * C4 — the commit-boundary AUTO-HEAL proof suite.
 *
 * Proves the operator-controlled auto-heal ({@see AtlasDocumentationRealityAutoHealService}):
 *   ACCEPTANCE  — a staged doc claiming `verified` WITHOUT resolvable evidence is rewritten
 *                 to the honest COMPUTED state, re-staged, a receipt is logged, the write-gate
 *                 flips BLOCKED -> ALLOWED, and a REAL commit then carries the honest doc.
 *   FAIL-ON-STUB— the downgrade TARGET is the REAL ledger-computed state, not a constant: two
 *                 over-claims with DIFFERENT evidence downgrade to DIFFERENT states, and
 *                 planting resolvable evidence REMOVES the heal.
 *   SAFETY      — honest=no-op, under-claim=no-op (never upgrades), downgrade-only/monotone,
 *                 only implementation_state ever changes, every heal emits a receipt, NO git
 *                 hook is created anywhere, and the REAL docs/ tree is never mutated.
 *
 * EVERY behavioral write lands in a SANDBOX (a throwaway temp git repo + temp docs). The
 * honest state is computed from the STAGED doc's frontmatter against a seeded sqlite :memory:
 * code index — the REAL, un-mocked AtlasAaeosImplementationTruthService — so the target is
 * genuinely ledger-derived. The real .git/hooks and the real docs/ tree are read-only here.
 *
 * sqlite :memory:, NO RefreshDatabase. setUp runs the two real migrations' up() (code
 * intelligence tables + green-run receipts) and snapshots the real-repo hook state; tearDown
 * drops the tables, deletes the sandbox, and re-asserts the real hook state is untouched.
 */
final class AtlasDocumentationRealityCommitAutoHealTest extends TestCase
{
    private string $repo;

    private bool $preHookAbsent = false;

    private ?string $disabledHookSha = null;

    private ?string $gitBinary = null;

    protected function setUp(): void
    {
        parent::setUp();

        // 1) Real migrations' up() — NOT RefreshDatabase.
        $this->runMigration('2026_05_02_010000_create_atlas_engineering_code_intelligence_tables.php');
        $this->runMigration('2026_06_02_090000_create_atlas_aaeos_test_run_receipts_table.php');
        $this->runMigration('2026_06_02_093000_add_freshness_hashes_to_atlas_aaeos_test_run_receipts.php');
        $this->assertTrue(Schema::hasTable('atlas_engineering_code_symbols'));
        $this->assertTrue(Schema::hasTable('atlas_aaeos_test_run_receipts'));

        // 2) Real-repo hook safety baseline (defense in depth — re-checked in tearDown).
        $this->preHookAbsent = ! file_exists(base_path('.git/hooks/pre-commit'));
        $this->disabledHookSha = file_exists(base_path('.git/hooks/pre-commit.disabled'))
            ? hash_file('sha256', base_path('.git/hooks/pre-commit.disabled'))
            : null;

        // 3) Throwaway git repo + temp docs under the framework testing dir (auto-ignored,
        //    never the real tree). markTestSkipped if git is unavailable.
        $this->gitBinary = $this->locateGit();
        $this->repo = base_path('storage/framework/testing/commit-autoheal-'.uniqid());
        File::ensureDirectoryExists($this->repo.'/docs/engineering-knowledge-base');

        if ($this->gitBinary !== null) {
            $this->git(['init', '-q', $this->repo]);
            $this->git(['-C', $this->repo, 'config', 'user.email', 't@t']);
            $this->git(['-C', $this->repo, 'config', 'user.name', 't']);
            $this->git(['-C', $this->repo, 'config', 'commit.gpgsign', 'false']);
            // Pin EOL handling off: a byte-preservation suite must never have git silently
            // normalize CRLF<->LF on add/commit regardless of the operator's global git config.
            $this->git(['-C', $this->repo, 'config', 'core.autocrlf', 'false']);
            $this->git(['-C', $this->repo, 'config', 'core.eol', 'lf']);
        }

        // 4) Seed ONE unrelated active class symbol so the index is HEALTHY (present +
        //    non-empty) => the verdict is REAL, never degraded. It deliberately matches none
        //    of the over-claimers' ghost refs.
        $this->seedSymbol('class', 'App\\Services\\Engineering\\AtlasDocumentationRealitySystemService');
    }

    protected function tearDown(): void
    {
        if (isset($this->repo) && File::isDirectory($this->repo)) {
            File::deleteDirectory($this->repo);
        }

        Schema::dropIfExists('atlas_aaeos_test_run_receipts');
        Schema::dropIfExists('atlas_engineering_doc_links');
        Schema::dropIfExists('atlas_engineering_code_symbols');
        Schema::dropIfExists('atlas_engineering_code_modules');

        // Real-repo hook safety re-assert: the heal codepath must never have created or
        // touched the live commit gate.
        if ($this->preHookAbsent) {
            $this->assertFileDoesNotExist(base_path('.git/hooks/pre-commit'));
        }
        if ($this->disabledHookSha !== null) {
            $this->assertSame($this->disabledHookSha, hash_file('sha256', base_path('.git/hooks/pre-commit.disabled')));
        }

        parent::tearDown();
    }

    // ===================== ACCEPTANCE =====================

    /**
     * THE C4 ACCEPTANCE: a staged doc claiming `verified` without resolvable evidence is
     * auto-corrected to the honest computed state, re-staged, logged, and a real commit then
     * carries the honest doc — the literal "auto-corrects an over-claiming commit".
     */
    public function test_over_claiming_staged_doc_is_auto_corrected_restaged_logged_and_commit_carries_honest_doc(): void
    {
        $this->requireGit();

        $rel = 'docs/engineering-knowledge-base/atlas-over-claimer.md';
        $this->writeDoc(
            $rel,
            'verified',
            ['symbol: AtlasGhostSymbolThatDoesNotResolveXYZ', 'test: AtlasGhostNeverIndexedTest'],
            "# Over Claimer\n\nthis body must survive byte-identical.\n",
        );
        $this->stage($rel);

        // The staged blob BEFORE heal claims verified.
        $this->assertStringContainsString('implementation_state: verified', $this->stagedBlob($rel));

        // PRE-CONDITION: without heal the write-gate BLOCKS this commit.
        $pre = $this->gateVerdict($rel);
        $this->assertSame(AtlasDocumentationRealityWriteGateService::DECISION_BLOCKED, $pre['decision']);
        $row = collect($pre['drift_blockers'])->firstWhere('owner_doc', $rel);
        $this->assertIsArray($row, 'the gate must name the over-claiming doc');
        $this->assertSame('verified', $row['claimed_state']);
        $this->assertSame('spec', $row['computed_state']);

        // ACT.
        $result = $this->healer()->heal($this->repo);

        // Auto-correction: worktree now reads the COMPUTED state.
        $this->assertSame('spec', $this->worktreeState($rel));

        // Re-staged: the INDEX blob (not just the worktree) is now honest.
        $staged = $this->stagedBlob($rel);
        $this->assertStringContainsString('implementation_state: spec', $staged);
        $this->assertStringNotContainsString('implementation_state: verified', $staged);

        // Receipt logged with the full auditable shape.
        $this->assertSame(1, $result['summary']['healed']);
        $receipt = collect($result['receipts'])->firstWhere('doc_path', $rel);
        $this->assertIsArray($receipt);
        $this->assertSame('verified', $receipt['before_state']);
        $this->assertSame('spec', $receipt['after_state']);
        $this->assertNotSame($receipt['before_state'], $receipt['after_state']);
        $this->assertSame($this->computedState($rel), $receipt['after_state'], 'after_state must equal the truth-service computed state');
        $this->assertStringContainsString('over_claim', $receipt['reason']);
        $this->assertArrayHasKey('evidence', $receipt);
        $this->assertArrayHasKey('unmet_evidence', $receipt['evidence']);
        $this->assertNotSame([], $receipt['evidence']['unmet_evidence'], 'the receipt must carry the evidence the computation used');
        $this->assertTrue($receipt['restaged'] ?? false);

        // Gate now PASSES on the still-staged doc.
        $post = $this->gateVerdict($rel);
        $this->assertSame(AtlasDocumentationRealityWriteGateService::DECISION_ALLOWED, $post['decision']);
        $this->assertSame([], $post['drift_blockers']);

        // Commit proceeds honest: HEAD's blob carries spec, not verified.
        [$code] = $this->git(['-C', $this->repo, 'commit', '-m', 'heal']);
        $this->assertSame(0, $code, 'the commit must proceed');
        $head = $this->headBlob($rel);
        $this->assertStringContainsString('implementation_state: spec', $head);
        $this->assertStringNotContainsString('implementation_state: verified', $head);

        // Body + every OTHER frontmatter field survived byte-for-byte.
        $this->assertStringContainsString('this body must survive byte-identical.', $head);
        $this->assertStringContainsString('id: atlas-over-claimer', $head);
        $this->assertStringContainsString('status: active', $head);
        $this->assertStringContainsString('doc_schema: atlas_canonical_module_doc.v1', $head);
        $this->assertStringContainsString('- symbol: AtlasGhostSymbolThatDoesNotResolveXYZ', $head);
    }

    // ===================== FAIL-ON-STUB =====================

    /**
     * The target is REAL, not hardcoded: two over-claims (both claiming verified) with
     * DIFFERENT evidence downgrade to DIFFERENT computed states (spec vs partial). The SAME
     * healer producing two different targets from the same claim is impossible for a constant.
     */
    public function test_two_over_claims_with_different_evidence_downgrade_to_different_computed_targets(): void
    {
        $this->requireGit();

        // DOC A => computes 'spec' (nothing resolves).
        $relA = 'docs/engineering-knowledge-base/atlas-over-claimer-a.md';
        $this->writeDoc($relA, 'verified', ['symbol: GhostA', 'test: GhostATest']);

        // DOC B => computes 'partial' (symbol + wiring resolve; test ghost, no green receipt).
        $this->seedSymbolWithFile('class', 'App\\Real\\PartialImpl', 'app/Services/Ai/Aaeos/AtlasAaeosImplementationTruthService.php');
        $this->seedSymbol('cli_command', 'atlas:partial:cmd');
        $relB = 'docs/engineering-knowledge-base/atlas-over-claimer-b.md';
        $this->writeDoc($relB, 'verified', ['symbol: PartialImpl', 'command: atlas:partial:cmd', 'test: GhostBTest']);

        $this->stage($relA);
        $this->stage($relB);

        $result = $this->healer()->heal($this->repo);

        $afterA = collect($result['receipts'])->firstWhere('doc_path', $relA)['after_state'] ?? null;
        $afterB = collect($result['receipts'])->firstWhere('doc_path', $relB)['after_state'] ?? null;

        $this->assertSame('spec', $afterA);
        $this->assertSame('partial', $afterB);
        // The anti-hardcode core: same starting claim, two DIFFERENT targets.
        $this->assertNotSame($afterA, $afterB);

        // Each target equals that doc's truth-service computed state (binds to the ledger).
        $this->assertSame($this->computedState($relA), $afterA);
        $this->assertSame($this->computedState($relB), $afterB);

        // Both worktree + staged blobs reflect their OWN target.
        $this->assertSame('spec', $this->worktreeState($relA));
        $this->assertSame('partial', $this->worktreeState($relB));
        $this->assertStringContainsString('implementation_state: spec', $this->stagedBlob($relA));
        $this->assertStringContainsString('implementation_state: partial', $this->stagedBlob($relB));
    }

    /**
     * Planting resolvable evidence CHANGES then REMOVES the heal — proving it fires off the
     * live ledger verdict, not a static rule. (a) symbol+wiring only => verified downgrades
     * to partial. (b) after a GREEN-CURRENT receipt makes it legitimately verified =>
     * computed === claimed => ZERO heal.
     */
    public function test_planting_resolvable_evidence_changes_or_removes_the_heal(): void
    {
        $this->requireGit();

        // Real indexed symbol + wiring + a real indexed test class file (so existence + file
        // resolve) — the only thing missing for verified is a GREEN receipt.
        $this->seedSymbolWithFile('class', 'App\\Real\\DocBImpl', 'app/Services/Ai/Aaeos/AtlasAaeosImplementationTruthService.php');
        $this->seedSymbol('cli_command', 'atlas:docb:cmd');
        $this->seedSymbolWithFile('class', 'Tests\\Unit\\Real\\DocBGreenRunTest', 'tests/Unit/Ai/Aaeos/AtlasAaeosImplementationTruthServiceTest.php');

        $rel = 'docs/engineering-knowledge-base/atlas-over-claimer-plant.md';
        // A resolving receipt ref (a real file on disk) is required for the verified tier,
        // alongside symbol+wiring+green-test.
        $evidence = [
            'symbol: DocBImpl',
            'command: atlas:docb:cmd',
            'test: DocBGreenRunTest',
            'receipt: tests/Feature/Engineering/AtlasDocumentationRealityCommitAutoHealTest.php',
        ];
        $this->writeDoc($rel, 'verified', $evidence);
        $this->stage($rel);

        // (a) BEFORE the green receipt: computed 'partial' => heal downgrades verified->partial.
        $before = $this->healer()->heal($this->repo);
        $this->assertSame(1, $before['summary']['healed']);
        $receiptA = collect($before['receipts'])->firstWhere('doc_path', $rel);
        $this->assertSame('partial', $receiptA['after_state']);
        $this->assertSame('partial', $this->worktreeState($rel));

        // Reset the doc back to an over-claim and re-stage, so (b) starts from 'verified' again.
        $this->writeDoc($rel, 'verified', $evidence);
        $this->stage($rel);

        // (b) Plant a GREEN-CURRENT receipt so the doc legitimately computes 'verified'.
        $refs = $this->normalizedRefs($evidence);
        $hashes = $this->service()->freshnessHashes($refs, 'DocBGreenRunTest');
        $capabilityId = 'atlas-over-claimer-plant'; // == the doc's frontmatter id (derived from filename)
        $this->recordGreenReceipt(
            $capabilityId,
            'DocBGreenRunTest',
            $hashes['test_file_hash'],
            $hashes['impl_files_hash'],
        );

        // Sanity: the doc now computes verified (no over-claim).
        $this->assertSame('verified', $this->computedState($rel));

        // Re-run heal: ZERO heal — the doc still reads verified, the staged blob unchanged.
        $stagedBefore = $this->stagedBlob($rel);
        $after = $this->healer()->heal($this->repo);
        $this->assertSame(0, $after['summary']['healed'], 'a legitimately-verified doc must not be healed');
        $this->assertSame([], $after['receipts']);
        $this->assertSame('verified', $this->worktreeState($rel));
        $this->assertSame($stagedBefore, $this->stagedBlob($rel), 'no git add happened for the now-honest doc');
        $this->assertStringContainsString('implementation_state: verified', $this->stagedBlob($rel));
    }

    // ===================== SAFETY =====================

    /** Honest doc (claim == computed) => byte-identical no-op, no receipt, no re-stage. */
    public function test_honest_doc_is_a_noop(): void
    {
        $this->requireGit();

        // Claims partial; symbol + wiring resolve => computed partial => honest.
        $this->seedSymbolWithFile('class', 'App\\Real\\HonestImpl', 'app/Services/Ai/Aaeos/AtlasAaeosImplementationTruthService.php');
        $this->seedSymbol('cli_command', 'atlas:honest:cmd');

        $rel = 'docs/engineering-knowledge-base/atlas-honest.md';
        $this->writeDoc($rel, 'partial', ['symbol: HonestImpl', 'command: atlas:honest:cmd']);
        $this->stage($rel);

        $before = File::get($this->repo.'/'.$rel);
        $stagedBefore = $this->stagedBlob($rel);

        $result = $this->healer()->heal($this->repo);

        $this->assertSame(0, $result['summary']['healed']);
        $this->assertSame([], $result['receipts']);
        $this->assertSame($before, File::get($this->repo.'/'.$rel), 'an honest doc must be byte-identical after heal');
        $this->assertSame($stagedBefore, $this->stagedBlob($rel));
    }

    /** Under-claim (computed outranks claim) => no-op, never upgraded. */
    public function test_under_claim_is_a_noop_and_never_upgrades(): void
    {
        $this->requireGit();

        // Claims spec; symbol + wiring resolve => computed partial (under-claim).
        $this->seedSymbolWithFile('class', 'App\\Real\\UnderImpl', 'app/Services/Ai/Aaeos/AtlasAaeosImplementationTruthService.php');
        $this->seedSymbol('cli_command', 'atlas:under:cmd');

        $rel = 'docs/engineering-knowledge-base/atlas-under.md';
        $this->writeDoc($rel, 'spec', ['symbol: UnderImpl', 'command: atlas:under:cmd']);
        $this->stage($rel);

        $result = $this->healer()->heal($this->repo);

        $this->assertSame(0, $result['summary']['healed']);
        $this->assertSame([], $result['receipts']);
        // Still spec — NOT raised to partial.
        $this->assertSame('spec', $this->worktreeState($rel));
        $this->assertStringContainsString('implementation_state: spec', $this->stagedBlob($rel));
        $this->assertStringNotContainsString('implementation_state: partial', $this->stagedBlob($rel));
    }

    /**
     * Strictly downgrade-only + monotone across the full claim x computed matrix: after_state
     * rank is NEVER above the claim; it equals computed ONLY on a real over-claim, else it is
     * a no-op (doc left at the claim).
     */
    public function test_heal_is_strictly_downgrade_only_and_monotone(): void
    {
        $this->requireGit();

        // One seeded index that lets us realize each computed tier on demand:
        //   no refs            => spec
        //   symbol+wiring      => partial
        //   symbol+wiring+green=> verified
        $this->seedSymbolWithFile('class', 'App\\Real\\MatrixImpl', 'app/Services/Ai/Aaeos/AtlasAaeosImplementationTruthService.php');
        $this->seedSymbol('cli_command', 'atlas:matrix:cmd');
        $this->seedSymbolWithFile('class', 'Tests\\Unit\\Real\\MatrixGreenTest', 'tests/Unit/Ai/Aaeos/AtlasAaeosImplementationTruthServiceTest.php');

        $rank = ['spec' => 0, 'partial' => 1, 'verified' => 2];

        // evidence sets that realize each computed tier (verified additionally needs a
        // resolving receipt ref — a real file on disk):
        $receiptRef = 'receipt: tests/Feature/Engineering/AtlasDocumentationRealityCommitAutoHealTest.php';
        $evidenceFor = [
            'spec' => ['symbol: MatrixGhost'],
            'partial' => ['symbol: MatrixImpl', 'command: atlas:matrix:cmd', 'test: MatrixGhostTest'],
            'verified' => ['symbol: MatrixImpl', 'command: atlas:matrix:cmd', 'test: MatrixGreenTest', $receiptRef],
        ];

        foreach (['spec', 'partial', 'verified'] as $computedTier) {
            foreach (['spec', 'partial', 'verified'] as $claimTier) {
                $rel = "docs/engineering-knowledge-base/atlas-matrix-{$claimTier}-{$computedTier}.md";
                $capabilityId = "atlas-matrix-{$claimTier}-{$computedTier}";
                $this->writeDoc($rel, $claimTier, $evidenceFor[$computedTier]);
                $this->stage($rel);

                // For the verified computed tier, plant a green-current receipt for THIS doc.
                if ($computedTier === 'verified') {
                    $hashes = $this->service()->freshnessHashes($this->normalizedRefs($evidenceFor['verified']), 'MatrixGreenTest');
                    $this->recordGreenReceipt($capabilityId, 'MatrixGreenTest', $hashes['test_file_hash'], $hashes['impl_files_hash']);
                }

                // Confirm the computed tier is what we intend before asserting heal behavior.
                $this->assertSame($computedTier, $this->computedState($rel), "computed tier setup for claim={$claimTier} computed={$computedTier}");

                $result = $this->healer()->heal($this->repo);
                $receipt = collect($result['receipts'])->firstWhere('doc_path', $rel);

                if ($rank[$claimTier] > $rank[$computedTier]) {
                    // Real over-claim => downgraded to EXACTLY computed.
                    $this->assertIsArray($receipt, "over-claim claim={$claimTier} computed={$computedTier} must heal");
                    $this->assertSame($computedTier, $receipt['after_state']);
                    $this->assertSame($computedTier, $this->worktreeState($rel));
                    $this->assertLessThan($rank[$claimTier], $rank[$receipt['after_state']], 'a heal never raises');
                } else {
                    // Equal or under-claim => no-op, doc stays at the claim.
                    $this->assertNull($receipt, "claim={$claimTier} computed={$computedTier} must be a no-op");
                    $this->assertSame($claimTier, $this->worktreeState($rel));
                }
            }
        }
    }

    /** Only the implementation_state line ever changes — every other field + the body survive. */
    public function test_only_implementation_state_frontmatter_field_is_ever_changed(): void
    {
        $this->requireGit();

        $rel = 'docs/engineering-knowledge-base/atlas-rich.md';
        $body = "# Rich Doc\n\nThis prose mentions verified deliberately and must NOT change.\n\n## Section\n\nmore body.\n";
        // A rich frontmatter: many fields + a list, over-claiming verified with ghost refs.
        $lines = [
            '---',
            'doc_schema: atlas_canonical_module_doc.v1',
            'id: atlas-rich',
            'owner: atlas-documentation-governance',
            'status: active',
            'tags: [alpha, beta]',
            'implementation_state: verified',
            'evidence_refs:',
            '  - symbol: AtlasRichGhostSymbol',
            '  - test: AtlasRichGhostTest',
            '---',
            '',
        ];
        File::put($this->repo.'/'.$rel, implode("\n", $lines)."\n".$body);
        $this->stage($rel);

        $before = File::get($this->repo.'/'.$rel);

        $this->healer()->heal($this->repo);

        $after = File::get($this->repo.'/'.$rel);

        // Exactly one differing line, and it is the implementation_state line.
        $diff = $this->changedLines($before, $after);
        $this->assertCount(1, $diff, 'exactly one line may change');
        $this->assertStringStartsWith('implementation_state:', trim($diff[0]['before']));
        $this->assertSame('implementation_state: verified', trim($diff[0]['before']));
        $this->assertSame('implementation_state: spec', trim($diff[0]['after']));

        // Spot-checks: other fields + the prose word 'verified' survive.
        $this->assertStringContainsString('id: atlas-rich', $after);
        $this->assertStringContainsString('status: active', $after);
        $this->assertStringContainsString('tags: [alpha, beta]', $after);
        $this->assertStringContainsString('- symbol: AtlasRichGhostSymbol', $after);
        $this->assertStringContainsString('This prose mentions verified deliberately', $after);
    }

    /** Every heal emits an auditable receipt; an edit + re-stage never happens without one. */
    public function test_every_heal_emits_an_auditable_receipt(): void
    {
        $this->requireGit();

        $relA = 'docs/engineering-knowledge-base/atlas-receipt-a.md';
        $relB = 'docs/engineering-knowledge-base/atlas-receipt-b.md';
        $this->writeDoc($relA, 'verified', ['symbol: ReceiptGhostA']);
        $this->writeDoc($relB, 'partial', ['symbol: ReceiptGhostB']); // partial w/ unresolved symbol => spec => over-claim
        $this->stage($relA);
        $this->stage($relB);

        $result = $this->healer()->heal($this->repo);

        // count(edited docs) === count(receipts): no silent edit.
        $this->assertSame($result['summary']['healed'], count($result['receipts']));
        $this->assertSame(2, $result['summary']['healed']);

        foreach ([$relA, $relB] as $rel) {
            $receipt = collect($result['receipts'])->firstWhere('doc_path', $rel);
            $this->assertIsArray($receipt);
            $this->assertArrayHasKey('before_state', $receipt);
            $this->assertArrayHasKey('after_state', $receipt);
            $this->assertSame($this->computedState($rel), $receipt['after_state']);
            $this->assertNotSame($receipt['before_state'], $receipt['after_state']);
            $this->assertNotSame('', trim((string) $receipt['reason']));
            $this->assertStringContainsString('over_claim', $receipt['reason']);
            $this->assertArrayHasKey('evidence', $receipt);
        }
    }

    /** THE C4 SAFETY CONTRACT — no git hook file is created anywhere. */
    public function test_no_git_hook_file_is_created_anywhere(): void
    {
        $this->requireGit();

        $rel = 'docs/engineering-knowledge-base/atlas-hook-probe.md';
        $this->writeDoc($rel, 'verified', ['symbol: HookProbeGhost']);
        $this->stage($rel);

        $this->healer()->heal($this->repo);

        // Real repo: the BLOCKING write-gate stays disabled. The active pre-commit
        // (if the operator has activated C4) is the NON-BLOCKING auto-cura hook —
        // never the blocking write-gate. The healer SERVICE itself installs no hook
        // (proven in the sandbox below); any active hook is a deliberate deployment
        // choice, not something this run created.
        $this->assertFileExists(base_path('.git/hooks/pre-commit.disabled'));
        $activePreCommit = base_path('.git/hooks/pre-commit');
        if (file_exists($activePreCommit)) {
            $contents = (string) file_get_contents($activePreCommit);
            $this->assertStringContainsString(
                'auto-cura',
                $contents,
                'the only active pre-commit allowed is the NON-BLOCKING C4 auto-cura hook — never the blocking write-gate'
            );
            $this->assertStringContainsString('NON-BLOCKING', $contents);
        } else {
            $this->assertTrue($this->preHookAbsent, 'real pre-commit was unexpectedly removed during the run');
        }

        // Sandbox repo: the healer (a service) must NEVER write a hook.
        $this->assertFileDoesNotExist($this->repo.'/.git/hooks/pre-commit');
        $nonSampleHooks = array_values(array_filter(
            glob($this->repo.'/.git/hooks/*') ?: [],
            static fn (string $p): bool => ! str_ends_with($p, '.sample'),
        ));
        $this->assertSame([], $nonSampleHooks, 'no non-sample hook may be created in the sandbox');
    }

    /** The REAL docs/ tree is never mutated by the heal codepath. */
    public function test_real_docs_tree_is_never_mutated(): void
    {
        $this->requireGit();

        $realDocs = base_path('docs/engineering-knowledge-base');
        $manifestBefore = $this->docsManifest($realDocs);

        $rel = 'docs/engineering-knowledge-base/atlas-real-tree-probe.md';
        $this->writeDoc($rel, 'verified', ['symbol: RealTreeGhost']);
        $this->stage($rel);
        $this->healer()->heal($this->repo);

        $this->assertSame($manifestBefore, $this->docsManifest($realDocs), 'the real docs tree must be byte-identical after heal');
    }

    /** Blind index (present but empty) => degrade, never a wrong heal. */
    public function test_blind_index_withholds_all_heals(): void
    {
        $this->requireGit();

        // Empty the index (present + zero active rows) — the dangerous blind state.
        AtlasEngineeringCodeSymbol::query()->delete();

        $rel = 'docs/engineering-knowledge-base/atlas-blind.md';
        $this->writeDoc($rel, 'verified', ['symbol: BlindGhost']);
        $this->stage($rel);

        $stagedBefore = $this->stagedBlob($rel);
        $result = $this->healer()->heal($this->repo);

        $this->assertTrue($result['degraded']);
        $this->assertSame('code_intelligence_index_empty_or_absent_auto_heal_withheld', $result['degraded_reason']);
        $this->assertSame(0, $result['summary']['healed']);
        $this->assertSame([], $result['receipts']);
        // The doc is untouched in both worktree and index.
        $this->assertSame('verified', $this->worktreeState($rel));
        $this->assertSame($stagedBefore, $this->stagedBlob($rel));
    }

    /** Dry-run reports the heal but writes NOTHING (no file edit, no re-stage). */
    public function test_dry_run_reports_without_writing_or_restaging(): void
    {
        $this->requireGit();

        $rel = 'docs/engineering-knowledge-base/atlas-dry.md';
        $this->writeDoc($rel, 'verified', ['symbol: DryGhost']);
        $this->stage($rel);

        $before = File::get($this->repo.'/'.$rel);
        $stagedBefore = $this->stagedBlob($rel);

        $result = $this->healer()->heal($this->repo, dryRun: true);

        // It REPORTS the heal it would make...
        $this->assertTrue($result['dry_run']);
        $this->assertFalse($result['writes']);
        $this->assertSame(1, $result['summary']['healed']);
        $receipt = collect($result['receipts'])->firstWhere('doc_path', $rel);
        $this->assertSame('spec', $receipt['after_state']);
        $this->assertTrue($receipt['dry_run']);

        // ...but writes NOTHING: worktree + index byte-identical.
        $this->assertSame($before, File::get($this->repo.'/'.$rel), 'dry-run must not edit the worktree');
        $this->assertSame($stagedBefore, $this->stagedBlob($rel), 'dry-run must not re-stage');
        $this->assertStringContainsString('implementation_state: verified', File::get($this->repo.'/'.$rel));
    }

    /**
     * Last-wins duplicate in-fence key: the value the parser RESOLVES (the last top-level
     * implementation_state in the first fence) is the one healed — a naive first-occurrence
     * edit would be a silent no-op on the value the gate actually reads.
     */
    public function test_duplicate_in_fence_key_heals_the_parser_resolved_last_occurrence(): void
    {
        $this->requireGit();

        $rel = 'docs/engineering-knowledge-base/atlas-dup-key.md';
        // TWO column-0 implementation_state lines inside the SINGLE first fence; the parser
        // resolves the LAST (= 'verified'); the first is a bespoke string (= spec when read).
        $lines = [
            '---',
            'doc_schema: atlas_canonical_module_doc.v1',
            'id: atlas-dup-key',
            'status: active',
            'implementation_state: runtime_surface_ready_bespoke_string',
            'evidence_refs:',
            '  - symbol: AtlasDupKeyGhost',
            'implementation_state: verified',
            '---',
            '',
            '# Dup Key',
            '',
        ];
        File::put($this->repo.'/'.$rel, implode("\n", $lines)."\n");
        $this->stage($rel);

        // Parser resolves 'verified' (last wins) => over-claim.
        $this->assertSame('verified', (new CanonicalDocsFrontmatterParser)->parse(File::get($this->repo.'/'.$rel))['frontmatter']['implementation_state']);

        $result = $this->healer()->heal($this->repo);
        $this->assertSame(1, $result['summary']['healed']);

        // The LAST occurrence was rewritten to spec; the FIRST bespoke line is untouched.
        $after = File::get($this->repo.'/'.$rel);
        $this->assertStringContainsString('implementation_state: runtime_surface_ready_bespoke_string', $after);
        $this->assertStringContainsString('implementation_state: spec', $after);
        $this->assertStringNotContainsString('implementation_state: verified', $after);
        // The parser now resolves the healed value.
        $this->assertSame('spec', $this->worktreeState($rel));
    }

    /**
     * CRLF over-claimer: a doc whose frontmatter lines end in \r\n is healed exactly like its LF
     * twin, and every \r\n EOL byte survives. REGRESSION GUARD: the byte-level state-line locator
     * was `$`-anchored, but in PCRE /m the `$` matches before a `\n` (never before a `\r`), so on a
     * CRLF doc the locator found ZERO state lines and every CRLF over-claim fell through to
     * 'over_claim_unwritable' — silently never healed, contradicting the "\r?\n EOL flavor
     * preserved" contract. Before the fix this test fails at `summary.healed === 1`.
     */
    public function test_crlf_over_claimer_heals_and_preserves_crlf_line_endings(): void
    {
        $this->requireGit();

        $rel = 'docs/engineering-knowledge-base/atlas-crlf-over-claimer.md';
        // EXPLICIT \r\n on every line, body included — a genuine CRLF doc.
        $lines = [
            '---',
            'doc_schema: atlas_canonical_module_doc.v1',
            'id: atlas-crlf-over-claimer',
            'owner: atlas-documentation-governance',
            'status: active',
            'implementation_state: verified',
            'evidence_refs:',
            '  - symbol: AtlasCrlfGhostSymbol',
            '  - test: AtlasCrlfGhostTest',
            '---',
            '',
            '# CRLF Over Claimer',
            '',
            'this CRLF body must survive byte-identical.',
            '',
        ];
        $raw = implode("\r\n", $lines)."\r\n";
        File::ensureDirectoryExists(dirname($this->repo.'/'.$rel));
        File::put($this->repo.'/'.$rel, $raw);
        $this->stage($rel);

        $before = File::get($this->repo.'/'.$rel);
        // Precondition: a real CRLF doc (every \n is a \r\n) over-claiming verified.
        $this->assertStringContainsString("implementation_state: verified\r\n", $before);
        $this->assertSame(substr_count($before, "\n"), substr_count($before, "\r\n"), 'doc must start as pure CRLF');

        // ACT.
        $result = $this->healer()->heal($this->repo);

        // It HEALED (the bug pinned this at 0) and downgraded to the honest computed state.
        $this->assertSame(1, $result['summary']['healed'], 'a CRLF over-claim must heal, not fall through to over_claim_unwritable');
        $this->assertSame(0, $result['summary']['skipped']);
        $receipt = collect($result['receipts'])->firstWhere('doc_path', $rel);
        $this->assertIsArray($receipt);
        $this->assertSame('verified', $receipt['before_state']);
        $this->assertSame('spec', $receipt['after_state']);
        $this->assertSame($this->computedState($rel), $receipt['after_state']);

        // Worktree now resolves the computed state.
        $this->assertSame('spec', $this->worktreeState($rel));

        $after = File::get($this->repo.'/'.$rel);

        // The healed value is followed by the ORIGINAL \r\n (not a bare \n, not a dropped \r).
        $this->assertStringContainsString("implementation_state: spec\r\n", $after);
        $this->assertStringNotContainsString('implementation_state: verified', $after);

        // EOL bytes preserved corpus-wide: still pure CRLF, identical \r and \n counts.
        $this->assertSame(substr_count($before, "\r"), substr_count($after, "\r"), 'no \r dropped or added');
        $this->assertSame(substr_count($before, "\n"), substr_count($after, "\n"), 'no \n dropped or added');
        $this->assertSame(substr_count($after, "\n"), substr_count($after, "\r\n"), 'no bare LF introduced');

        // Decisive byte-identity: the ONLY change is the state token; all other bytes (CRLF EOLs,
        // BOM-free fences, body, every other field) are preserved exactly.
        $expected = str_replace('implementation_state: verified', 'implementation_state: spec', $before);
        $this->assertSame($expected, $after, 'only the implementation_state token changed; all bytes incl. CRLF EOLs preserved');
        $this->assertStringContainsString('this CRLF body must survive byte-identical.', $after);
    }

    /**
     * CRLF + duplicate in-fence key: last-wins is preserved on a CRLF doc — the LAST top-level
     * implementation_state (the value the parser resolves) is the one healed, the first bespoke
     * line is untouched, and the \r\n EOLs survive. Guards the fix against regressing either the
     * CRLF tolerance or the last-wins splice target.
     */
    public function test_crlf_duplicate_in_fence_key_heals_last_occurrence_preserving_crlf(): void
    {
        $this->requireGit();

        $rel = 'docs/engineering-knowledge-base/atlas-crlf-dup-key.md';
        $lines = [
            '---',
            'doc_schema: atlas_canonical_module_doc.v1',
            'id: atlas-crlf-dup-key',
            'status: active',
            'implementation_state: runtime_surface_ready_bespoke_string',
            'evidence_refs:',
            '  - symbol: AtlasCrlfDupGhost',
            'implementation_state: verified',
            // A trailing field AFTER the duplicate so NEITHER state line is the last fence line:
            // both end in a mid-fence \r\n — the exact path the `$`-anchored bug failed to match
            // (the dup line at end-of-fence would otherwise be rescued by end-of-subject `$`).
            'owner: atlas-documentation-governance',
            '---',
            '',
            '# Dup Key CRLF',
            '',
        ];
        $raw = implode("\r\n", $lines)."\r\n";
        File::ensureDirectoryExists(dirname($this->repo.'/'.$rel));
        File::put($this->repo.'/'.$rel, $raw);
        $this->stage($rel);

        // Parser resolves the LAST occurrence (verified) even on CRLF => over-claim.
        $this->assertSame('verified', (new CanonicalDocsFrontmatterParser)->parse(File::get($this->repo.'/'.$rel))['frontmatter']['implementation_state']);

        $result = $this->healer()->heal($this->repo);
        $this->assertSame(1, $result['summary']['healed']);

        $after = File::get($this->repo.'/'.$rel);
        // The bespoke FIRST line is untouched; only the LAST occurrence became spec.
        $this->assertStringContainsString("implementation_state: runtime_surface_ready_bespoke_string\r\n", $after);
        $this->assertStringContainsString("implementation_state: spec\r\n", $after);
        $this->assertStringNotContainsString('implementation_state: verified', $after);
        // The trailing field after the duplicate is untouched (still CRLF-terminated).
        $this->assertStringContainsString("owner: atlas-documentation-governance\r\n", $after);
        $this->assertSame('spec', $this->worktreeState($rel));

        // EOLs preserved: identical \r/\n counts and still pure CRLF.
        $this->assertSame(substr_count($raw, "\r"), substr_count($after, "\r"));
        $this->assertSame(substr_count($raw, "\n"), substr_count($after, "\n"));
        $this->assertSame(substr_count($after, "\n"), substr_count($after, "\r\n"), 'no bare LF introduced');
    }

    /** No frontmatter / no implementation_state field => skipped (never authored). */
    public function test_doc_without_state_field_is_skipped_never_authored(): void
    {
        $this->requireGit();

        // (a) No fence at all.
        $relNoFence = 'docs/engineering-knowledge-base/atlas-no-fence.md';
        File::put($this->repo.'/'.$relNoFence, "# No Fence\n\njust body, no frontmatter.\n");
        $this->stage($relNoFence);

        // (b) Fence but no implementation_state field.
        $relNoField = 'docs/engineering-knowledge-base/atlas-no-field.md';
        File::put($this->repo.'/'.$relNoField, "---\ndoc_schema: atlas_canonical_module_doc.v1\nid: atlas-no-field\nstatus: active\n---\n\n# No Field\n");
        $this->stage($relNoField);

        $beforeNoFence = File::get($this->repo.'/'.$relNoFence);
        $beforeNoField = File::get($this->repo.'/'.$relNoField);

        $result = $this->healer()->heal($this->repo);

        $this->assertSame(0, $result['summary']['healed']);
        // Neither doc gained an implementation_state field.
        $this->assertSame($beforeNoFence, File::get($this->repo.'/'.$relNoFence));
        $this->assertSame($beforeNoField, File::get($this->repo.'/'.$relNoField));
        $this->assertStringNotContainsString('implementation_state', File::get($this->repo.'/'.$relNoField));
    }

    // ---- helpers -------------------------------------------------------------

    private function healer(): AtlasDocumentationRealityAutoHealService
    {
        // The REAL, un-mocked truth service so the target is genuinely ledger-derived.
        return new AtlasDocumentationRealityAutoHealService(
            $this->service(),
            new CanonicalDocsFrontmatterParser,
        );
    }

    private function service(): AtlasAaeosImplementationTruthService
    {
        return app(AtlasAaeosImplementationTruthService::class);
    }

    /**
     * The write-gate verdict over a single staged doc, computed from that doc's worktree
     * frontmatter (the same RAW frontmatter the auto-heal reads), via the REAL gate.
     *
     * @return array<string,mixed>
     */
    private function gateVerdict(string $rel): array
    {
        $abs = $this->repo.'/'.$rel;
        $fm = (new CanonicalDocsFrontmatterParser)->parse(File::get($abs))['frontmatter'] ?? [];

        return app(AtlasDocumentationRealityWriteGateService::class)->decide([
            'touched_paths' => [$rel],
            'is_mutating' => true,
            'touched_frontmatter' => [
                $rel => [
                    'implementation_state' => (string) ($fm['implementation_state'] ?? ''),
                    'evidence_refs' => $fm['evidence_refs'] ?? null,
                ],
            ],
            'partially_staged_paths' => [],
        ]);
    }

    /**
     * The truth-service computed_state for a doc's current worktree frontmatter, CAPABILITY-
     * BOUND on the doc's frontmatter id — the SAME path the auto-heal uses, so a green-current
     * receipt lifts a verified claim here exactly as it does in the heal.
     */
    private function computedState(string $rel): string
    {
        $fm = (new CanonicalDocsFrontmatterParser)->parse(File::get($this->repo.'/'.$rel))['frontmatter'] ?? [];

        return (string) $this->service()->driftForFrontmatterBound(
            (string) ($fm['implementation_state'] ?? ''),
            $fm['evidence_refs'] ?? null,
            (string) ($fm['id'] ?? ''),
        )['computed_state'];
    }

    /** The parser-resolved implementation_state of the worktree doc. */
    private function worktreeState(string $rel): string
    {
        $fm = (new CanonicalDocsFrontmatterParser)->parse(File::get($this->repo.'/'.$rel))['frontmatter'] ?? [];

        return (string) ($fm['implementation_state'] ?? '');
    }

    /**
     * @param  array<int,string>  $evidenceRefs  list of "kind: ref" lines
     */
    private function writeDoc(string $rel, string $implState, array $evidenceRefs, string $body = "# doc\n"): void
    {
        $id = pathinfo($rel, PATHINFO_FILENAME);
        $lines = [
            '---',
            'doc_schema: atlas_canonical_module_doc.v1',
            'id: '.$id,
            'owner: atlas-documentation-governance',
            'status: active',
            'implementation_state: '.$implState,
            'evidence_refs:',
        ];
        foreach ($evidenceRefs as $ref) {
            $lines[] = '  - '.$ref;
        }
        $lines[] = '---';
        $lines[] = '';

        File::ensureDirectoryExists(dirname($this->repo.'/'.$rel));
        File::put($this->repo.'/'.$rel, implode("\n", $lines)."\n".$body);
    }

    private function stage(string $rel): void
    {
        $this->git(['-C', $this->repo, 'add', '--', $rel]);
    }

    private function stagedBlob(string $rel): string
    {
        return $this->git(['-C', $this->repo, 'show', ':'.$rel])[1];
    }

    private function headBlob(string $rel): string
    {
        return $this->git(['-C', $this->repo, 'show', 'HEAD:'.$rel])[1];
    }

    /**
     * @param  array<int,string>  $evidenceLines
     * @return array<int,array{kind:string, ref:string}>
     */
    private function normalizedRefs(array $evidenceLines): array
    {
        $refs = [];
        foreach ($evidenceLines as $line) {
            [$kind, $ref] = array_map('trim', explode(':', $line, 2));
            $refs[] = ['kind' => $kind, 'ref' => $ref];
        }

        return $refs;
    }

    private function recordGreenReceipt(string $capabilityId, string $testRef, ?string $testFileHash, ?string $implFilesHash): void
    {
        AtlasAaeosTestRunReceipt::query()->updateOrCreate(
            ['capability_id' => $capabilityId, 'test_ref' => $testRef],
            [
                'filter' => $testRef,
                'passed' => true,
                'tests_run' => 3,
                'exit_code' => 0,
                'commit_stamp' => 'healstamp01',
                'test_file_hash' => $testFileHash,
                'impl_files_hash' => $implFilesHash,
                'output_tail' => 'OK (3 tests)',
                'runner' => 'phpunit',
                'ran_at' => now(),
            ],
        );
    }

    private function seedSymbol(string $type, string $name): void
    {
        AtlasEngineeringCodeSymbol::query()->create([
            'symbol_type' => $type,
            'symbol_name' => $name,
            'file_path' => 'tests/seed/'.md5($type.$name).'.php',
            'language' => 'php',
            'status' => 'active',
            'docs_status' => 'documented',
            'source_hash' => 'seed-'.md5($type.$name),
        ]);
    }

    private function seedSymbolWithFile(string $type, string $name, string $realRelativeFile): void
    {
        AtlasEngineeringCodeSymbol::query()->create([
            'symbol_type' => $type,
            'symbol_name' => $name,
            'file_path' => $realRelativeFile,
            'language' => 'php',
            'status' => 'active',
            'docs_status' => 'documented',
            'source_hash' => 'seed-'.md5($name),
        ]);
    }

    /**
     * Line-by-line diff: the records where the two texts differ at the same index.
     *
     * @return array<int,array{before:string, after:string}>
     */
    private function changedLines(string $before, string $after): array
    {
        $b = explode("\n", $before);
        $a = explode("\n", $after);
        $max = max(count($b), count($a));
        $diff = [];
        for ($i = 0; $i < $max; $i++) {
            $bl = $b[$i] ?? '<absent>';
            $al = $a[$i] ?? '<absent>';
            if ($bl !== $al) {
                $diff[] = ['before' => $bl, 'after' => $al];
            }
        }

        return $diff;
    }

    /**
     * A sha256-per-file manifest of a docs tree, for proving the real tree is untouched.
     *
     * @return array<string,string>
     */
    private function docsManifest(string $root): array
    {
        if (! File::isDirectory($root)) {
            return [];
        }
        $manifest = [];
        foreach (File::allFiles($root) as $file) {
            $manifest[$file->getRelativePathname()] = (string) hash_file('sha256', $file->getPathname());
        }
        ksort($manifest);

        return $manifest;
    }

    private function requireGit(): void
    {
        if ($this->gitBinary === null) {
            $this->markTestSkipped('git binary unavailable in this environment.');
        }
    }

    private function locateGit(): ?string
    {
        $probe = new Process(['git', '--version']);
        $probe->setTimeout(20.0);
        try {
            $probe->run();
        } catch (\Throwable) {
            return null;
        }

        return $probe->isSuccessful() ? 'git' : null;
    }

    /**
     * Run a git command, returning [exitCode, combinedOutput]. Throws nothing; callers that
     * need the binary call requireGit() first.
     *
     * @param  array<int,string>  $args
     * @return array{0:int,1:string}
     */
    private function git(array $args): array
    {
        $process = new Process(array_merge(['git'], $args));
        $process->setTimeout(60.0);
        $process->run();

        return [$process->getExitCode() ?? -1, $process->getOutput().$process->getErrorOutput()];
    }

    private function runMigration(string $file): void
    {
        $migration = require base_path('database/migrations/'.$file);
        $migration->up();
    }
}
