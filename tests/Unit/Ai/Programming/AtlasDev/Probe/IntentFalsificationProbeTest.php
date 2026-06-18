<?php

declare(strict_types=1);

namespace Tests\Unit\Ai\Programming\AtlasDev\Probe;

use App\Services\Ai\Programming\AtlasDev\Probe\IntentFalsificationProbe;
use App\Services\Ai\Programming\AtlasDev\Provider\DiffParseResult;
use App\Services\Ai\Programming\AtlasDev\Schemas\Components\ProviderLock;
use App\Services\Ai\Programming\AtlasDev\Schemas\Components\RepairPolicy;
use App\Services\Ai\Programming\AtlasDev\Schemas\LightTaskContract;
use PHPUnit\Framework\TestCase;

/**
 * E1 — Intent-falsification probe (unit).
 *
 * Covers VAL-E1-003, VAL-E1-005, VAL-E1-014, VAL-E1-015, VAL-CROSS-005 at
 * the probe-class level. The deterministic probe answers "does at least one
 * added diff line implement the E2-established intent verb set?" by scanning
 * ALL hunks of a many-file diff (not just the first file) against the
 * persisted LightTaskContract::intentVerbs basis. An empty/no-patch write
 * task (verbs present, no diff) is conservatively treated as not-addressed
 * (never silently green over an unevaluated intent).
 *
 * The probe reads the E2-established intent_verbs basis from the contract,
 * NOT a re-detection (VAL-CROSS-005: E1 checks against the E2 basis).
 */
final class IntentFalsificationProbeTest extends TestCase
{
    // -- VAL-CROSS-005 / VAL-E1-003: probe checks the E2-established basis ----

    public function test_val_cross_005_probe_uses_persisted_intent_verbs_basis_not_a_re_detection(): void
    {
        // Contract carries intentVerbs=['corrigir'] (the E2-established basis).
        // A diff whose added lines implement 'corrigir' (e.g. a fixed() call,
        // or 'fix' in a comment) => the probe must NOT fire: the diff
        // addresses the E2 verb.
        $contract = $this->makeContract(intentVerbs: ['corrigir']);
        $diff = DiffParseResult::patch(
            diff: "--- a/file\n+++ b/file\n@@\n+    // fix the rate-limit guard\n",
            changedFiles: ['app/Foo.php'],
        );

        $probe = new IntentFalsificationProbe;
        $this->assertFalse(
            $probe->isIntentLikelyNotAddressed($contract, $diff),
            'VAL-CROSS-005: diff implementing the E2 verb => no flag',
        );
    }

    public function test_val_cross_005_probe_fires_when_e2_basis_verb_is_unimplemented_in_diff(): void
    {
        // Contract carries intentVerbs=['corrigir']. A diff whose added
        // lines do NOT implement 'corrigir' (no recognized surface form of
        // the verb) => the probe must fire (the E2 verb is unimplemented).
        $contract = $this->makeContract(intentVerbs: ['corrigir']);
        $diff = DiffParseResult::patch(
            diff: "--- a/file\n+++ b/file\n@@\n+    return 42;\n",
            changedFiles: ['app/Foo.php'],
        );

        $probe = new IntentFalsificationProbe;
        $this->assertTrue(
            $probe->isIntentLikelyNotAddressed($contract, $diff),
            'VAL-CROSS-005: diff missing the E2 verb => flag fires',
        );
    }

    // -- VAL-E1-005: genuine-intent diff passes the probe --------------------

    public function test_val_e1_005_genuine_intent_diff_does_not_fire_flag(): void
    {
        // Multi-verb intent ('renomear' + 'remover'). A diff that implements
        // BOTH verbs across hunks => no flag.
        $contract = $this->makeContract(intentVerbs: ['renomear', 'remover']);
        $diff = DiffParseResult::patch(
            diff: "--- a/a\n+++ b/a\n@@\n-OldName::class\n+NewName::class\n--- a/b\n+++ b/b\n@@\n+    // remove the dead branch\n",
            changedFiles: ['app/A.php', 'app/B.php'],
        );

        $probe = new IntentFalsificationProbe;
        $this->assertFalse(
            $probe->isIntentLikelyNotAddressed($contract, $diff),
            'VAL-E1-005: a diff genuinely implementing the verb must not raise a flag',
        );
    }

    public function test_val_e1_005_partial_implementation_still_clears_flag(): void
    {
        // Multi-verb intent where the diff implements only ONE verb is still
        // considered addressed (at least one verb is implemented). The probe
        // does not require every verb to be implemented to stay silent.
        $contract = $this->makeContract(intentVerbs: ['renomear', 'remover']);
        $diff = DiffParseResult::patch(
            diff: "--- a/a\n+++ b/a\n@@\n+    // rename OldName to NewName\n",
            changedFiles: ['app/A.php'],
        );

        $probe = new IntentFalsificationProbe;
        $this->assertFalse(
            $probe->isIntentLikelyNotAddressed($contract, $diff),
            'VAL-E1-005: implementing any one of the recognized verbs clears the flag',
        );
    }

    // -- VAL-E1-015: scans ALL hunks of a many-file diff ---------------------

    public function test_val_e1_015_verb_in_later_file_does_not_raise_flag(): void
    {
        // Many-file diff implementing the verb only in a later file. The
        // probe must NOT raise a flag (it scans all hunks, not just the
        // first file). This is the position-independence contract.
        $contract = $this->makeContract(intentVerbs: ['corrigir']);
        $diff = DiffParseResult::patch(
            diff: "--- a/a\n+++ b/a\n@@\n+    return 1;\n"
                ."--- a/b\n+++ b/b\n@@\n+    return 2;\n"
                ."--- a/c\n+++ b/c\n@@\n+    // fix the off-by-one\n",
            changedFiles: ['app/A.php', 'app/B.php', 'app/C.php'],
        );

        $probe = new IntentFalsificationProbe;
        $this->assertFalse(
            $probe->isIntentLikelyNotAddressed($contract, $diff),
            'VAL-E1-015: verb in a later file => no false flag',
        );
    }

    public function test_val_e1_015_verb_in_no_file_raises_flag(): void
    {
        // Equally large many-file diff implementing the verb in NONE of the
        // files => the flag must be raised (no hunk implements the verb).
        $contract = $this->makeContract(intentVerbs: ['corrigir']);
        $diff = DiffParseResult::patch(
            diff: "--- a/a\n+++ b/a\n@@\n+    return 1;\n"
                ."--- a/b\n+++ b/b\n@@\n+    return 2;\n"
                ."--- a/c\n+++ c/c\n@@\n+    return 3;\n",
            changedFiles: ['app/A.php', 'app/B.php', 'app/C.php'],
        );

        $probe = new IntentFalsificationProbe;
        $this->assertTrue(
            $probe->isIntentLikelyNotAddressed($contract, $diff),
            'VAL-E1-015: verb in no file => flag fires',
        );
    }

    // -- VAL-E1-014: empty / no-patch diff for a write task fires the flag ----

    public function test_val_e1_014_no_patch_diff_for_write_task_fires_flag(): void
    {
        // Write task (intentVerbs present) that returns MODE_NO_PATCH_NEEDED
        // => the probe must fire (the intent is not addressed by an empty
        // patch). Never silently green.
        $contract = $this->makeContract(intentVerbs: ['corrigir']);
        $diff = DiffParseResult::noPatchNeeded(reason: 'nothing to change');

        $probe = new IntentFalsificationProbe;
        $this->assertTrue(
            $probe->isIntentLikelyNotAddressed($contract, $diff),
            'VAL-E1-014: a no-patch write task must fire the flag',
        );
    }

    public function test_val_e1_014_empty_patch_for_write_task_fires_flag(): void
    {
        // Even a MODE_PATCH diff whose body contains zero added lines (only
        // context/removals) is treated as not-addressed for a write task.
        $contract = $this->makeContract(intentVerbs: ['corrigir']);
        $diff = DiffParseResult::patch(
            diff: "--- a/file\n+++ b/file\n@@\n-removed line\n context line\n",
            changedFiles: ['app/Foo.php'],
        );

        $probe = new IntentFalsificationProbe;
        $this->assertTrue(
            $probe->isIntentLikelyNotAddressed($contract, $diff),
            'VAL-E1-014: a patch with no added lines must fire the flag for a write task',
        );
    }

    // -- byte-identical off-equivalent: no recognized verb => never fires ----

    public function test_no_recognized_intent_verbs_never_fires_regardless_of_diff(): void
    {
        // Pre-E1 / non-write task (intentVerbs empty): the probe must NEVER
        // fire, regardless of the diff content. This is the byte-identical
        // off-equivalent at the probe level (gated by e1.mode in the
        // executor, but the probe itself is also a no-op with no basis).
        $contract = $this->makeContract(intentVerbs: []);
        $diff = DiffParseResult::patch(
            diff: "--- a/file\n+++ b/file\n@@\n+    return 42;\n",
            changedFiles: ['app/Foo.php'],
        );

        $probe = new IntentFalsificationProbe;
        $this->assertFalse(
            $probe->isIntentLikelyNotAddressed($contract, $diff),
            'A task with no recognized intent verb must never trip the probe',
        );
    }

    // -- E1 repair-loop feedback: probeReason() ------------------------------
    // VAL-E1-006, VAL-E1-013, VAL-CROSS-006: the probe reason is the live
    // input fed into the M2 repair loop as a SEPARATE field (never mutating
    // $failureExcerpt). These unit tests document the method's direct
    // contract; the feature test (RepairLoopIntentProbeFeedbackTest) covers
    // the end-to-end integration with the repair prompt.

    public function test_probe_reason_returns_empty_when_intent_is_addressed(): void
    {
        // VAL-E1-005 lineage: a verb-implementing diff => no flag => no reason.
        $contract = $this->makeContract(intentVerbs: ['corrigir']);
        $diff = DiffParseResult::patch(
            diff: "--- a/file\n+++ b/file\n@@\n+    // fix the off-by-one bug\n",
            changedFiles: ['app/Foo.php'],
        );

        $probe = new IntentFalsificationProbe;
        $this->assertFalse($probe->isIntentLikelyNotAddressed($contract, $diff));
        $this->assertSame(
            '',
            $probe->probeReason($contract, $diff),
            'VAL-E1-006: when the intent IS addressed, no reason is fed forward (byte-identical baseline)',
        );
    }

    public function test_probe_reason_returns_empty_when_no_recognized_verb(): void
    {
        // No recognized verb => the probe never fires => no reason.
        $contract = $this->makeContract(intentVerbs: []);
        $diff = DiffParseResult::patch(
            diff: "--- a/file\n+++ b/file\n@@\n+    return 42;\n",
            changedFiles: ['app/Foo.php'],
        );

        $probe = new IntentFalsificationProbe;
        $this->assertSame(
            '',
            $probe->probeReason($contract, $diff),
            'No recognized verb => empty reason (byte-identical to pre-E1)',
        );
    }

    public function test_probe_reason_references_unaddressed_verbs_when_intent_missing(): void
    {
        // VAL-E1-006: the reason is a live input referencing the unaddressed
        // verb set so the regenerated attempt knows WHAT to implement.
        $contract = $this->makeContract(intentVerbs: ['corrigir', 'remover']);
        $diff = DiffParseResult::patch(
            diff: "--- a/file\n+++ b/file\n@@\n+    return 42;\n",
            changedFiles: ['app/Foo.php'],
        );

        $probe = new IntentFalsificationProbe;
        $this->assertTrue($probe->isIntentLikelyNotAddressed($contract, $diff));
        $reason = $probe->probeReason($contract, $diff);
        $this->assertNotSame('', $reason, 'VAL-E1-006: a non-empty reason is fed forward when the intent is missing');
        $this->assertStringContainsString('corrigir', $reason, 'The reason references the unaddressed verb');
        $this->assertStringContainsString('remover', $reason, 'The reason references every unaddressed verb');
    }

    public function test_probe_reason_references_intent_text_subject_when_present(): void
    {
        // VAL-E1-013: the reason carries the intent subject (a live input the
        // regenerated attempt can act on for convergence).
        $contract = new LightTaskContract(
            runId: 'run-e1-probe-reason',
            taskId: 'task-e1-probe-reason',
            specHash: 'spec-hash-e1-probe-reason',
            allowedTools: ['read', 'write', 'grep', 'run_test'],
            blockedActions: ['production_write'],
            allowedFiles: ['app/Foo.php'],
            watchedFiles: [],
            forbiddenFiles: [],
            maxFilesChanged: 1,
            validationCommands: ['composer test'],
            evidenceRequired: ['verification_receipt'],
            repairPolicy: new RepairPolicy(
                maxAttempts: 1,
                sameProvider: true,
                requiresFailedGateOutput: true,
                abortOnSameSignatureTwice: true,
            ),
            escalationOn: [],
            providerLock: new ProviderLock(
                provider: 'hermes_cli',
                modelFamily: 'minimax-m3',
                fallbackAllowed: false,
            ),
            taskContractHash: 'tch-e1-probe-reason',
            noTestReason: null,
            intentText: 'Fix the rate-limit guard in Foo',
            intentVerbs: ['corrigir'],
        );
        $diff = DiffParseResult::patch(
            diff: "--- a/file\n+++ b/file\n@@\n+    return 42;\n",
            changedFiles: ['app/Foo.php'],
        );

        $probe = new IntentFalsificationProbe;
        $reason = $probe->probeReason($contract, $diff);
        $this->assertStringContainsString(
            'Fix the rate-limit guard in Foo',
            $reason,
            'VAL-E1-013: the reason carries the intent subject as a live input for convergence',
        );
    }

    public function test_probe_reason_is_stable_across_iterations_for_same_unaddressed_intent(): void
    {
        // VAL-E1-007/008 lineage: the reason is deterministic for the same
        // unaddressed intent (it never destabilizes the failure signature
        // because it lives in its own prompt section, not in the hashed excerpt).
        $contract = $this->makeContract(intentVerbs: ['corrigir']);
        $diff = DiffParseResult::patch(
            diff: "--- a/file\n+++ b/file\n@@\n+    return 42;\n",
            changedFiles: ['app/Foo.php'],
        );

        $probe = new IntentFalsificationProbe;
        $reason1 = $probe->probeReason($contract, $diff);
        $reason2 = $probe->probeReason($contract, $diff);
        $this->assertSame(
            $reason1,
            $reason2,
            'The reason is deterministic across iterations for the same unaddressed intent',
        );
    }

    // -- Helpers -------------------------------------------------------------

    /**
     * @param  list<string>  $intentVerbs
     */
    private function makeContract(array $intentVerbs): LightTaskContract
    {
        return new LightTaskContract(
            runId: 'run-e1-probe-test',
            taskId: 'task-e1-probe',
            specHash: 'spec-hash-e1-probe',
            allowedTools: ['read', 'write', 'grep', 'run_test'],
            blockedActions: ['production_write'],
            allowedFiles: ['app/Foo.php'],
            watchedFiles: [],
            forbiddenFiles: [],
            maxFilesChanged: 1,
            validationCommands: ['composer test'],
            evidenceRequired: ['verification_receipt'],
            repairPolicy: new RepairPolicy(
                maxAttempts: 1,
                sameProvider: true,
                requiresFailedGateOutput: true,
                abortOnSameSignatureTwice: true,
            ),
            escalationOn: [],
            providerLock: new ProviderLock(
                provider: 'hermes_cli',
                modelFamily: 'minimax-m3',
                fallbackAllowed: false,
            ),
            taskContractHash: 'tch-e1-probe',
            noTestReason: null,
            intentText: $intentVerbs === [] ? '' : 'write task intent',
            intentVerbs: $intentVerbs,
        );
    }
}
