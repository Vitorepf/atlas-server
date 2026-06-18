<?php

declare(strict_types=1);

namespace Tests\Unit\Ai\Programming\AtlasDev\Intelligence;

use App\Services\Ai\Programming\AtlasDev\Intelligence\IntentJudgeOutcome;
use App\Services\Ai\Programming\AtlasDev\Intelligence\ReviewIntelligenceService;
use App\Services\Ai\Programming\AtlasDev\Schemas\Components\ReviewFinding;
use App\Services\Ai\Programming\AtlasDev\Schemas\ReviewReceipt;
use PHPUnit\Framework\TestCase;

/**
 * E1 — Semantic critic `detectIntentFalsification()` detector (unit).
 *
 * Covers VAL-E1-009, VAL-E1-010, VAL-E1-011 at the critic-detector level:
 *
 *   - VAL-E1-009: green-gate-but-misses-intent diff => ReviewReceipt.findings
 *     contains a detectIntentFalsification finding with riskType in
 *     {bug, reliability} and a non-empty description referencing the
 *     unaddressed intent.
 *   - VAL-E1-010: the layer is doubt-additive only. It may emit a finding,
 *     escalate, or stay silent, but NEVER clears an existing flag or
 *     upgrades completion to passed.
 *   - VAL-E1-011: with the LLM-judge sub-flag on, a fake judge attempting to
 *     approve/clear cannot remove intent_likely_not_addressed; the judge may
 *     only add doubt/escalate.
 *
 * The detector is merged into ReviewIntelligenceService::analyse() through
 * the established detector-array pattern (one private detectX() + one
 * array_merge line). It reuses IntentFalsificationProbe as the single source
 * of truth for the verb-matching heuristic so the critic and the post-gate
 * probe never disagree on what "implements the intent" means.
 *
 * The layer is STRICTLY ADVERSARIAL: by construction it only ever appends
 * findings (which can only escalate the receipt status, never downgrade it)
 * and it never returns a mechanism that clears honesty flags or asserts
 * approval. The ReviewReceipt has no field that can carry "approve" / "clear
 * flag" semantics, and the critic never touches VerificationGateResult.
 */
final class IntentFalsificationCriticDetectorTest extends TestCase
{
    // -- VAL-E1-009: green-gate-but-misses-intent => finding present ---------

    public function test_val_e1_009_intent_missing_diff_emits_critic_finding_with_valid_risk_type(): void
    {
        $receipt = $this->analyse([
            'run_id' => 'run-e1-critic-009',
            'changed_files' => ['app/Foo.php'],
            'diff_chunks' => [
                ['file' => 'app/Foo.php', 'body' => '+    return 42;', 'line' => 10],
            ],
            'test_paths' => ['tests/Unit/FooTest.php'],
            'intent_basis' => [
                'intent_verbs' => ['corrigir'],
                'intent_text' => 'Fix the rate-limit guard',
                'diff' => "--- a/app/Foo.php\n+++ b/app/Foo.php\n@@\n+    return 42;\n",
            ],
        ]);

        $falsificationFindings = array_values(array_filter(
            $receipt->findings,
            static fn (ReviewFinding $f): bool => $f->riskType === ReviewFinding::RISK_BUG
                || $f->riskType === ReviewFinding::RISK_RELIABILITY,
        ));
        // At least one finding must come from detectIntentFalsification and
        // reference the unaddressed intent in its description.
        $intentFindings = array_values(array_filter(
            $falsificationFindings,
            static fn (ReviewFinding $f): bool => str_contains(strtolower($f->description), 'intent')
                || str_contains(strtolower($f->title), 'intent'),
        ));

        $this->assertNotEmpty(
            $intentFindings,
            'VAL-E1-009: detectIntentFalsification must emit a finding for an intent-missing diff',
        );
        $finding = $intentFindings[0];
        $this->assertContains(
            $finding->riskType,
            [ReviewFinding::RISK_BUG, ReviewFinding::RISK_RELIABILITY],
            'VAL-E1-009: riskType must be in {bug, reliability}',
        );
        $this->assertNotSame(
            '',
            trim($finding->description),
            'VAL-E1-009: description must be non-empty',
        );
        $this->assertNotSame(
            '',
            trim($finding->remediation),
            'VAL-E1-009: remediation must be non-empty (>= 8 chars for blocker/critical)',
        );
    }

    public function test_val_e1_009_finding_references_the_unaddressed_intent_text(): void
    {
        $receipt = $this->analyse([
            'run_id' => 'run-e1-critic-009b',
            'changed_files' => ['app/Billing/ChargeService.php'],
            'diff_chunks' => [
                ['file' => 'app/Billing/ChargeService.php', 'body' => '+    return 0;', 'line' => 5],
            ],
            'test_paths' => ['tests/Feature/Billing/ChargeServiceTest.php'],
            'intent_basis' => [
                'intent_verbs' => ['redirecionar'],
                'intent_text' => 'Redirect the legacy billing webhook to the new endpoint',
                'diff' => "--- a/app/Billing/ChargeService.php\n+++ b/app/Billing/ChargeService.php\n@@\n+    return 0;\n",
            ],
        ]);

        $intentFindings = $this->extractIntentFindings($receipt);
        $this->assertNotEmpty($intentFindings, 'finding must be emitted');
        $finding = $intentFindings[0];

        // The description must reference the unaddressed intent (verb or text).
        $combined = strtolower($finding->description.' '.$finding->title);
        $this->assertTrue(
            str_contains($combined, 'intent') || str_contains($combined, 'redirecionar')
                || str_contains($combined, 'redirect') || str_contains($combined, 'addressed'),
            'VAL-E1-009: finding description must reference the unaddressed intent, got: '.$finding->description,
        );
    }

    // -- VAL-E1-009 negative: genuine-intent diff => no intent-falsification finding

    public function test_val_e1_009_genuine_intent_diff_emits_no_intent_falsification_finding(): void
    {
        $receipt = $this->analyse([
            'run_id' => 'run-e1-critic-009-genuine',
            'changed_files' => ['app/Foo.php'],
            'diff_chunks' => [
                ['file' => 'app/Foo.php', 'body' => '+    // fix the rate-limit guard', 'line' => 10],
            ],
            'test_paths' => ['tests/Unit/FooTest.php'],
            'intent_basis' => [
                'intent_verbs' => ['corrigir'],
                'intent_text' => 'Fix the rate-limit guard',
                'diff' => "--- a/app/Foo.php\n+++ b/app/Foo.php\n@@\n+    // fix the rate-limit guard\n",
            ],
        ]);

        $intentFindings = $this->extractIntentFindings($receipt);
        $this->assertSame(
            [],
            $intentFindings,
            'VAL-E1-009 (negative): a genuine-intent diff must not emit an intent-falsification finding',
        );
    }

    public function test_val_e1_009_no_intent_basis_emits_no_intent_falsification_finding(): void
    {
        // No intent_basis carried => the detector stays silent (nothing to
        // probe; byte-identical to pre-E1 critic for non-write / read-only
        // paths). The detector is opt-in via the intent_basis payload.
        $receipt = $this->analyse([
            'run_id' => 'run-e1-critic-009-nobasis',
            'changed_files' => ['app/Foo.php', 'tests/Unit/FooTest.php'],
            'diff_chunks' => [
                ['file' => 'app/Foo.php', 'body' => 'public function go() { return true; }', 'line' => 10],
            ],
            'test_paths' => ['tests/Unit/FooTest.php'],
        ]);

        $intentFindings = $this->extractIntentFindings($receipt);
        $this->assertSame(
            [],
            $intentFindings,
            'VAL-E1-009 (no basis): without intent_basis the detector stays silent',
        );
    }

    // -- VAL-E1-010: doubt-additive only, never clears / never approves ------

    public function test_val_e1_010_detector_only_escalates_receipt_status_never_downgrades(): void
    {
        // An intent-missing diff that would otherwise be clean (no other
        // findings) => the detector's finding escalates the receipt to at
        // least STATUS_REVIEWED (never STATUS_NO_CONCERNS). The critic has
        // no mechanism to assert "approve" or "no concerns" over an intent
        // miss.
        $receipt = $this->analyse([
            'run_id' => 'run-e1-critic-010',
            'changed_files' => ['app/Foo.php', 'tests/Unit/FooTest.php'],
            'diff_chunks' => [
                ['file' => 'app/Foo.php', 'body' => '+    return 42;', 'line' => 10],
            ],
            'test_paths' => ['tests/Unit/FooTest.php'],
            'intent_basis' => [
                'intent_verbs' => ['corrigir'],
                'intent_text' => 'Fix the rate-limit guard',
                'diff' => "--- a/app/Foo.php\n+++ b/app/Foo.php\n@@\n+    return 42;\n",
            ],
        ]);

        $this->assertNotSame(
            ReviewReceipt::STATUS_NO_CONCERNS,
            $receipt->status,
            'VAL-E1-010: an intent-miss never yields STATUS_NO_CONCERNS (the detector only escalates)',
        );
        $this->assertNotEmpty(
            $receipt->findings,
            'VAL-E1-010: the detector appended a finding (doubt-additive)',
        );
    }

    public function test_val_e1_010_review_receipt_has_no_approval_or_clear_flag_field(): void
    {
        // Structural invariant: the ReviewReceipt contract exposes no field
        // that can carry "approve", "clear_flag", or "passed" semantics. The
        // critic layer cannot express an approval — only findings + status
        // (reviewed/escalate/no_concerns/blocked_insufficient_context).
        $receipt = $this->analyse([
            'run_id' => 'run-e1-critic-010-struct',
            'changed_files' => ['app/Foo.php', 'tests/Unit/FooTest.php'],
            'diff_chunks' => [
                ['file' => 'app/Foo.php', 'body' => '+    return 42;', 'line' => 10],
            ],
            'test_paths' => ['tests/Unit/FooTest.php'],
            'intent_basis' => [
                'intent_verbs' => ['corrigir'],
                'intent_text' => 'Fix the rate-limit guard',
                'diff' => "--- a/app/Foo.php\n+++ b/app/Foo.php\n@@\n+    return 42;\n",
            ],
        ]);

        $canonical = $receipt->toCanonicalArray();
        foreach (array_keys($canonical) as $key) {
            $this->assertDoesNotMatchRegularExpression(
                '/^(approve|clear_flag|override|passed|success)/i',
                (string) $key,
                'VAL-E1-010: ReviewReceipt exposes no approval/clear/override field (found: '.$key.')',
            );
        }
        $allowed = ReviewReceipt::ALLOWED_STATUSES;
        $this->assertNotContains('passed', $allowed);
        $this->assertNotContains('approved', $allowed);
        $this->assertNotContains('success', $allowed);
    }

    public function test_val_e1_010_detector_never_clears_an_existing_probe_flag_by_construction(): void
    {
        // The deterministic probe's flag lives on
        // VerificationGateResult.honestyFlags (set BEFORE the critic runs in
        // the executor). The critic returns a ReviewReceipt — it has no
        // reference to the gate result and therefore no path to clear the
        // flag. This test documents the construction: the critic output type
        // (ReviewReceipt) does not expose a honesty-flags-mutation surface.
        // We assert the analyse() signature returns a ReviewReceipt (not a
        // gate result) and that ReviewReceipt has no setter for honesty flags.
        $receipt = $this->analyse([
            'run_id' => 'run-e1-critic-010-construction',
            'changed_files' => ['app/Foo.php', 'tests/Unit/FooTest.php'],
            'diff_chunks' => [['file' => 'app/Foo.php', 'body' => '+    return 42;', 'line' => 10]],
            'test_paths' => ['tests/Unit/FooTest.php'],
            'intent_basis' => [
                'intent_verbs' => ['corrigir'],
                'intent_text' => 'Fix the rate-limit guard',
                'diff' => "--- a/app/Foo.php\n+++ b/app/Foo.php\n@@\n+    return 42;\n",
            ],
        ]);

        // The detector's output is merged into findings; the receipt carries
        // no honestyFlags field at all (those live on the gate result).
        $this->assertInstanceOf(ReviewReceipt::class, $receipt);
        // property_exists accounts for readonly typed props; PHPUnit 12
        // removed assertObjectNotHasAttribute, so we assert directly.
        $this->assertFalse(
            property_exists($receipt, 'honestyFlags') || property_exists($receipt, 'honesty_flags'),
            'VAL-E1-010: ReviewReceipt has no honestyFlags surface => critic cannot clear a gate flag',
        );
    }

    // -- VAL-E1-011: LLM-judge sub-flag — can only add doubt, never clear ----

    public function test_val_e1_011_llm_judge_sub_flag_off_by_default_so_judge_never_invoked(): void
    {
        // The LLM-judge is OPT-IN behind a sub-flag. With the sub-flag off,
        // no judge runs and the detector behaves as the pure deterministic
        // probe (no judge-attributable finding, no escalation from the judge).
        $judge = new FakeIntentJudge([
            IntentJudgeOutcome::approve(), // would clear if it could — but sub-flag off
        ]);

        $receipt = $this->analyseWithJudge(
            judge: $judge,
            llmJudgeEnabled: false,
            input: [
                'run_id' => 'run-e1-critic-011-off',
                'changed_files' => ['app/Foo.php', 'tests/Unit/FooTest.php'],
                'diff_chunks' => [['file' => 'app/Foo.php', 'body' => '+    return 42;', 'line' => 10]],
                'test_paths' => ['tests/Unit/FooTest.php'],
                'intent_basis' => [
                    'intent_verbs' => ['corrigir'],
                    'intent_text' => 'Fix the rate-limit guard',
                    'diff' => "--- a/app/Foo.php\n+++ b/app/Foo.php\n@@\n+    return 42;\n",
                ],
            ],
        );

        $this->assertSame(0, $judge->invocationCount, 'sub-flag off => judge never invoked');
        $intentFindings = $this->extractIntentFindings($receipt);
        $this->assertNotEmpty(
            $intentFindings,
            'sub-flag off => deterministic probe still fires its finding (judge cannot suppress it)',
        );
    }

    public function test_val_e1_011_fake_judge_attempting_to_approve_cannot_remove_deterministic_finding(): void
    {
        // VAL-E1-011: with the LLM-judge sub-flag ON and a fake judge that
        // attempts to approve/clear, the deterministic probe's finding
        // REMAINS in the receipt. The judge can only ADD doubt/escalate — it
        // has no API surface to remove the deterministic probe's verdict.
        $judge = new FakeIntentJudge([
            IntentJudgeOutcome::approve(),
            IntentJudgeOutcome::approve(), // judge screams "approved" repeatedly
        ]);

        $receipt = $this->analyseWithJudge(
            judge: $judge,
            llmJudgeEnabled: true,
            input: [
                'run_id' => 'run-e1-critic-011-approve',
                'changed_files' => ['app/Foo.php', 'tests/Unit/FooTest.php'],
                'diff_chunks' => [['file' => 'app/Foo.php', 'body' => '+    return 42;', 'line' => 10]],
                'test_paths' => ['tests/Unit/FooTest.php'],
                'intent_basis' => [
                    'intent_verbs' => ['corrigir'],
                    'intent_text' => 'Fix the rate-limit guard',
                    'diff' => "--- a/app/Foo.php\n+++ b/app/Foo.php\n@@\n+    return 42;\n",
                ],
            ],
        );

        $this->assertGreaterThan(0, $judge->invocationCount, 'sub-flag on => judge invoked');
        $intentFindings = $this->extractIntentFindings($receipt);
        $this->assertNotEmpty(
            $intentFindings,
            'VAL-E1-011: the deterministic finding persists despite the judge attempting to approve',
        );
        // No finding is allowed to carry an "approved"/"cleared" semantic.
        foreach ($receipt->findings as $f) {
            $this->assertDoesNotMatchRegularExpression(
                '/(intent (is )?(addressed|approved|resolved)|no concern)/i',
                $f->description,
                'VAL-E1-011: no finding may assert approval/clear semantics',
            );
        }
    }

    public function test_val_e1_011_judge_escalating_doubt_adds_a_finding_or_raises_severity(): void
    {
        // The judge MAY add doubt/escalate. When it returns doubt, the
        // receipt must reflect the added doubt (more findings, or escalated
        // severity/status) — never less than the deterministic probe alone.
        $deterministicReceipt = $this->analyse([
            'run_id' => 'run-e1-critic-011-det',
            'changed_files' => ['app/Foo.php', 'tests/Unit/FooTest.php'],
            'diff_chunks' => [['file' => 'app/Foo.php', 'body' => '+    return 42;', 'line' => 10]],
            'test_paths' => ['tests/Unit/FooTest.php'],
            'intent_basis' => [
                'intent_verbs' => ['corrigir'],
                'intent_text' => 'Fix the rate-limit guard',
                'diff' => "--- a/app/Foo.php\n+++ b/app/Foo.php\n@@\n+    return 42;\n",
            ],
        ]);

        $judge = new FakeIntentJudge([
            IntentJudgeOutcome::doubt(note: 'judge suspects the diff also misses an edge case'),
        ]);
        $withJudge = $this->analyseWithJudge(
            judge: $judge,
            llmJudgeEnabled: true,
            input: [
                'run_id' => 'run-e1-critic-011-judge',
                'changed_files' => ['app/Foo.php', 'tests/Unit/FooTest.php'],
                'diff_chunks' => [['file' => 'app/Foo.php', 'body' => '+    return 42;', 'line' => 10]],
                'test_paths' => ['tests/Unit/FooTest.php'],
                'intent_basis' => [
                    'intent_verbs' => ['corrigir'],
                    'intent_text' => 'Fix the rate-limit guard',
                    'diff' => "--- a/app/Foo.php\n+++ b/app/Foo.php\n@@\n+    return 42;\n",
                ],
            ],
        );

        // The judge-attributed run must be AT LEAST as severe as the
        // deterministic-only run (doubt-additive: more findings OR a status
        // at least as escalated).
        $severityRank = static fn (ReviewReceipt $r): int => $r->findings === []
            ? PHP_INT_MAX
            : min(array_map(static fn (ReviewFinding $f): int => $f->severityRank(), $r->findings));
        $this->assertLessThanOrEqual(
            $severityRank($deterministicReceipt),
            $severityRank($withJudge),
            'VAL-E1-011: judge doubt must not lower the severity (doubt-additive only)',
        );
        $this->assertGreaterThanOrEqual(
            count($deterministicReceipt->findings),
            count($withJudge->findings),
            'VAL-E1-011: judge doubt must not reduce the finding count',
        );
    }

    public function test_val_e1_011_judge_downgrade_attempt_is_ignored(): void
    {
        // A malicious judge returning a "downgrade" outcome cannot lower the
        // deterministic probe's severity: the detector ignores any outcome
        // that is not doubt/escalate. The deterministic finding stays at its
        // original severity.
        $judge = new FakeIntentJudge([
            IntentJudgeOutcome::downgrade(),
        ]);

        $baselineReceipt = $this->analyse([
            'run_id' => 'run-e1-critic-011-baseline',
            'changed_files' => ['app/Foo.php', 'tests/Unit/FooTest.php'],
            'diff_chunks' => [['file' => 'app/Foo.php', 'body' => '+    return 42;', 'line' => 10]],
            'test_paths' => ['tests/Unit/FooTest.php'],
            'intent_basis' => [
                'intent_verbs' => ['corrigir'],
                'intent_text' => 'Fix the rate-limit guard',
                'diff' => "--- a/app/Foo.php\n+++ b/app/Foo.php\n@@\n+    return 42;\n",
            ],
        ]);
        $withJudge = $this->analyseWithJudge(
            judge: $judge,
            llmJudgeEnabled: true,
            input: [
                'run_id' => 'run-e1-critic-011-downgrade',
                'changed_files' => ['app/Foo.php', 'tests/Unit/FooTest.php'],
                'diff_chunks' => [['file' => 'app/Foo.php', 'body' => '+    return 42;', 'line' => 10]],
                'test_paths' => ['tests/Unit/FooTest.php'],
                'intent_basis' => [
                    'intent_verbs' => ['corrigir'],
                    'intent_text' => 'Fix the rate-limit guard',
                    'diff' => "--- a/app/Foo.php\n+++ b/app/Foo.php\n@@\n+    return 42;\n",
                ],
            ],
        );

        $baselineIntent = $this->extractIntentFindings($baselineReceipt);
        $judgeIntent = $this->extractIntentFindings($withJudge);
        $this->assertNotEmpty($baselineIntent);
        $this->assertNotEmpty($judgeIntent);
        $this->assertSame(
            $baselineIntent[0]->severity,
            $judgeIntent[0]->severity,
            'VAL-E1-011: a judge downgrade outcome must not lower the deterministic severity',
        );
    }

    // -- Helpers -------------------------------------------------------------

    /**
     * @param  array<string,mixed>  $input
     */
    private function analyse(array $input): ReviewReceipt
    {
        return (new ReviewIntelligenceService)->analyse($input);
    }

    /**
     * @param  array<string,mixed>  $input
     */
    private function analyseWithJudge(?object $judge, bool $llmJudgeEnabled, array $input): ReviewReceipt
    {
        return (new ReviewIntelligenceService)->analyse($input, [
            'llm_judge' => $llmJudgeEnabled,
            'judge' => $judge,
        ]);
    }

    /**
     * @return list<ReviewFinding>
     */
    private function extractIntentFindings(ReviewReceipt $receipt): array
    {
        return array_values(array_filter(
            $receipt->findings,
            static function (ReviewFinding $f): bool {
                if (! in_array($f->riskType, [ReviewFinding::RISK_BUG, ReviewFinding::RISK_RELIABILITY], true)) {
                    return false;
                }
                $hay = strtolower($f->title.' '.$f->description);

                return str_contains($hay, 'intent') || str_contains($hay, 'addressed')
                    || str_contains($hay, 'falsif');
            },
        ));
    }
}

/**
 * Fake LLM-as-judge for VAL-E1-011 adversarial testing.
 *
 * Records every invocation and returns the queued outcomes in order. Used to
 * prove the judge sub-layer can never clear the deterministic probe's flag:
 * the fake screams "approve" / "downgrade" and the deterministic finding
 * still persists.
 */
final class FakeIntentJudge
{
    public int $invocationCount = 0;

    /**
     * @param  list<IntentJudgeOutcome>  $outcomes
     */
    public function __construct(
        private readonly array $outcomes,
    ) {}

    public function __invoke(): IntentJudgeOutcome
    {
        $idx = min($this->invocationCount, count($this->outcomes) - 1);
        $this->invocationCount++;

        return $this->outcomes[$idx];
    }
}
