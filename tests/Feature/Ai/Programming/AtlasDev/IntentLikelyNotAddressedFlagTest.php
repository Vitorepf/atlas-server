<?php

declare(strict_types=1);

namespace Tests\Feature\Ai\Programming\AtlasDev;

use App\Services\Ai\Programming\AtlasDev\Gate\CompletionDecision;
use App\Services\Ai\Programming\AtlasDev\Gate\CompletionStateGate;
use App\Services\Ai\Programming\AtlasDev\Gate\VerificationGateResult;
use App\Services\Ai\Programming\AtlasDev\Probe\IntentFalsificationProbe;
use App\Services\Ai\Programming\AtlasDev\Provider\DiffParseResult;
use App\Services\Ai\Programming\AtlasDev\Provider\ProviderCallResult;
use App\Services\Ai\Programming\AtlasDev\Schemas\Components\CompletionSummary;
use App\Services\Ai\Programming\AtlasDev\Schemas\Components\ProviderLock;
use App\Services\Ai\Programming\AtlasDev\Schemas\Components\RepairPolicy;
use App\Services\Ai\Programming\AtlasDev\Schemas\Components\ScopeBaseline;
use App\Services\Ai\Programming\AtlasDev\Schemas\Components\ScopeContractView;
use App\Services\Ai\Programming\AtlasDev\Schemas\Components\ScopeFileDiff;
use App\Services\Ai\Programming\AtlasDev\Schemas\Components\ScopeObserved;
use App\Services\Ai\Programming\AtlasDev\Schemas\LightTaskContract;
use App\Services\Ai\Programming\AtlasDev\Schemas\ScopeGuardReceipt;
use Tests\TestCase;

/**
 * E1 — intent_likely_not_addressed honesty flag end-to-end propagation.
 *
 * Covers VAL-E1-003, VAL-E1-004, VAL-E1-005, VAL-E1-012, VAL-E1-014,
 * VAL-E1-015, VAL-CROSS-005 through the synthetic-diff harness: the
 * IntentFalsificationProbe computes the signal, the honesty flag is appended
 * to VerificationGateResult via withHonestyFlags(), and the
 * CompletionStateGate auto-downgrades PASSED -> needs_review (the
 * passed-forbids-flags invariant guarantees no green-with-flag).
 *
 * This is the integration-level proof that the ADVISORY channel works
 * end-to-end for the E1 intent-falsification probe: the probe's verdict maps
 * to the sanctioned honesty-flag channel, the flag drives the downgrade, and
 * the completion decision never reports passed over an intent-missing diff
 * — even when the verification gate is GREEN.
 *
 * VAL-E1-012: across the matrix (green gate; probe enabled), a diff not
 * implementing the intent verb never yields a green/passed completion.
 */
final class IntentLikelyNotAddressedFlagTest extends TestCase
{
    // -- VAL-E1-003 / VAL-E1-004: green gate + intent-missing diff => flag + downgrade

    public function test_val_e1_003_green_gate_with_intent_missing_diff_appends_flag(): void
    {
        // Green gate (STATUS_PASSED). The diff does NOT implement the E2 verb
        // ('corrigir'). The probe fires; the honesty flag is appended despite
        // the green gate.
        $contract = $this->makeContract(intentVerbs: ['corrigir']);
        $diff = DiffParseResult::patch(
            diff: "--- a/file\n+++ b/file\n@@\n+    return 42;\n",
            changedFiles: ['app/Foo.php'],
        );

        $probe = new IntentFalsificationProbe;
        $this->assertTrue(
            $probe->isIntentLikelyNotAddressed($contract, $diff),
            'VAL-E1-003: probe must fire for an intent-missing diff',
        );

        $verificationResult = $this->greenGateResult()->withHonestyFlags([
            IntentFalsificationProbe::FLAG_INTENT_LIKELY_NOT_ADDRESSED,
        ]);

        $this->assertSame(
            VerificationGateResult::STATUS_PASSED,
            $verificationResult->aggregateStatus,
            'VAL-E1-003: the gate is GREEN',
        );
        $this->assertContains(
            IntentFalsificationProbe::FLAG_INTENT_LIKELY_NOT_ADDRESSED,
            $verificationResult->honestyFlags,
            'VAL-E1-003: the flag is present despite the green gate',
        );
    }

    public function test_val_e1_004_advisory_flag_forces_completion_downgrade_never_passed(): void
    {
        $contract = $this->makeContract(intentVerbs: ['corrigir']);
        $verificationResult = $this->greenGateResult()->withHonestyFlags([
            IntentFalsificationProbe::FLAG_INTENT_LIKELY_NOT_ADDRESSED,
        ]);

        $decision = $this->decide($contract, $verificationResult);

        $this->assertNotSame(
            CompletionSummary::STATUS_PASSED,
            $decision->status,
            'VAL-E1-004: completion must NOT be passed when the flag is present',
        );
        $this->assertSame(
            CompletionSummary::STATUS_NEEDS_REVIEW,
            $decision->status,
            'VAL-E1-004: advisory flag downgrades PASSED -> needs_review',
        );
        $this->assertContains(
            'passed_downgraded_due_to_honesty_flags',
            $decision->reasons,
            'VAL-E1-004: downgrade reason cites the honesty flags',
        );
        $this->assertContains(
            IntentFalsificationProbe::FLAG_INTENT_LIKELY_NOT_ADDRESSED,
            $decision->honestyFlags,
            'VAL-E1-004: flag carried into the completion decision',
        );
    }

    // -- VAL-E1-005: genuine-intent diff preserves green completion ----------

    public function test_val_e1_005_genuine_intent_diff_preserves_passed_completion(): void
    {
        $contract = $this->makeContract(intentVerbs: ['corrigir']);
        $diff = DiffParseResult::patch(
            diff: "--- a/file\n+++ b/file\n@@\n+    // fix the off-by-one bug\n",
            changedFiles: ['app/Foo.php'],
        );

        $probe = new IntentFalsificationProbe;
        $this->assertFalse(
            $probe->isIntentLikelyNotAddressed($contract, $diff),
            'VAL-E1-005: a verb-implementing diff must not fire the probe',
        );

        // No flag appended (the executor only appends when the probe fires).
        $verificationResult = $this->greenGateResult();
        $decision = $this->decide($contract, $verificationResult);

        $this->assertSame(
            CompletionSummary::STATUS_PASSED,
            $decision->status,
            'VAL-E1-005: green completion preserved for a genuine-intent diff',
        );
        $this->assertNotContains(
            IntentFalsificationProbe::FLAG_INTENT_LIKELY_NOT_ADDRESSED,
            $decision->honestyFlags,
            'VAL-E1-005: no flag in the completion decision',
        );
    }

    // -- VAL-E1-014: empty/no-patch write task => flag + non-passed ----------

    public function test_val_e1_014_no_patch_write_task_never_completes_passed(): void
    {
        $contract = $this->makeContract(intentVerbs: ['corrigir']);
        $diff = DiffParseResult::noPatchNeeded(reason: 'nothing to change');

        $probe = new IntentFalsificationProbe;
        $this->assertTrue(
            $probe->isIntentLikelyNotAddressed($contract, $diff),
            'VAL-E1-014: empty/no-patch write task fires the probe',
        );

        $verificationResult = $this->greenGateResult()->withHonestyFlags([
            IntentFalsificationProbe::FLAG_INTENT_LIKELY_NOT_ADDRESSED,
        ]);
        $decision = $this->decide($contract, $verificationResult);

        $this->assertNotSame(
            CompletionSummary::STATUS_PASSED,
            $decision->status,
            'VAL-E1-014: empty diff never completes passed',
        );
    }

    // -- VAL-E1-012: no config combo yields a silent green for intent-missing

    public function test_val_e1_012_no_silent_green_over_intent_missing_with_flag_appended(): void
    {
        // The contract: once the probe fires and the flag is appended on a
        // green gate, the CompletionDecision ctor invariant (passed forbids
        // flags) makes a silent green impossible. This is the mechanical
        // guarantee that no advisory path yields passed-with-flag.
        $contract = $this->makeContract(intentVerbs: ['corrigir']);
        $diff = DiffParseResult::patch(
            diff: "--- a/file\n+++ b/file\n@@\n+    return 42;\n",
            changedFiles: ['app/Foo.php'],
        );

        $probe = new IntentFalsificationProbe;
        $this->assertTrue($probe->isIntentLikelyNotAddressed($contract, $diff));

        $verificationResult = $this->greenGateResult()->withHonestyFlags([
            IntentFalsificationProbe::FLAG_INTENT_LIKELY_NOT_ADDRESSED,
        ]);
        $decision = $this->decide($contract, $verificationResult);

        $this->assertNotSame(
            CompletionSummary::STATUS_PASSED,
            $decision->status,
            'VAL-E1-012: no path yields passed/success for an intent-missing diff',
        );
    }

    public function test_val_e1_012_passed_forbids_flag_invariant_is_enforced(): void
    {
        // The CompletionDecision ctor throws on (passed + flag). This is the
        // mechanical floor that no config combo can bypass.
        $this->expectException(\InvalidArgumentException::class);
        $this->expectExceptionMessage('passed forbids honesty_flags');

        new CompletionDecision(
            status: CompletionSummary::STATUS_PASSED,
            honestyFlags: [IntentFalsificationProbe::FLAG_INTENT_LIKELY_NOT_ADDRESSED],
            residualRisks: [],
        );
    }

    // -- off-mode byte-identical + hard-mode STATUS_FAILED channel -----------

    public function test_off_mode_skips_probe_so_intent_missing_diff_stays_passed(): void
    {
        // VAL-M0-008 / VAL-CROSS-010 lineage: with e1.mode=off the probe is
        // never consulted, so an intent-missing diff on a green gate stays
        // passed (byte-identical to pre-E1). The probe class itself is a
        // no-op when off; this test asserts the OFF path does not surface.
        $contract = $this->makeContract(intentVerbs: ['corrigir']);
        $diff = DiffParseResult::patch(
            diff: "--- a/file\n+++ b/file\n@@\n+    return 42;\n",
            changedFiles: ['app/Foo.php'],
        );

        // The probe still fires when called directly (off-gating lives in the
        // executor, not the probe). This test documents that contract: the
        // EXECUTOR is responsible for skipping the probe when e1.mode=off.
        $probe = new IntentFalsificationProbe;
        $this->assertTrue(
            $probe->isIntentLikelyNotAddressed($contract, $diff),
            'The probe itself fires for an intent-missing diff; off-gating is the executor job',
        );

        // When off, the executor does NOT append the flag, so the green gate
        // produces a passed completion (byte-identical to pre-E1).
        $verificationResult = $this->greenGateResult();
        $decision = $this->decide($contract, $verificationResult);

        $this->assertSame(
            CompletionSummary::STATUS_PASSED,
            $decision->status,
            'off-mode: no flag appended => green completion preserved (byte-identical to pre-E1)',
        );
        $this->assertNotContains(
            IntentFalsificationProbe::FLAG_INTENT_LIKELY_NOT_ADDRESSED,
            $decision->honestyFlags,
            'off-mode: the flag never reaches the completion decision',
        );
    }

    public function test_hard_mode_routes_intent_missing_diff_to_status_failed_gate_channel(): void
    {
        // VAL-CROSS-001 / VAL-E1-012: in hard mode the executor routes the
        // intent miss to the sanctioned HARD gate channel (STATUS_FAILED),
        // never the honesty-flag advisory channel. The completion then
        // resolves to failed (never silently passed).
        $contract = $this->makeContract(intentVerbs: ['corrigir']);

        // Simulate what the executor does in hard mode: rebuild the gate
        // result with STATUS_FAILED + the flag for auditability.
        $green = $this->greenGateResult();
        $verificationResult = new VerificationGateResult(
            tests: $green->tests,
            gates: $green->gates,
            aggregateStatus: VerificationGateResult::STATUS_FAILED,
            honestyFlags: $green->withHonestyFlags([
                IntentFalsificationProbe::FLAG_INTENT_LIKELY_NOT_ADDRESSED,
            ])->honestyFlags,
            evidenceRefs: $green->evidenceRefs,
            profile: $green->profile,
        );

        $this->assertSame(
            VerificationGateResult::STATUS_FAILED,
            $verificationResult->aggregateStatus,
            'hard mode: the gate is forced to STATUS_FAILED (sanctioned hard channel)',
        );
        $this->assertContains(
            IntentFalsificationProbe::FLAG_INTENT_LIKELY_NOT_ADDRESSED,
            $verificationResult->honestyFlags,
            'hard mode: the flag is retained for auditability',
        );

        $decision = $this->decide($contract, $verificationResult);
        $this->assertSame(
            CompletionSummary::STATUS_FAILED,
            $decision->status,
            'hard mode: completion is failed (never silently passed)',
        );
    }

    // -- Helpers -------------------------------------------------------------

    private function greenGateResult(): VerificationGateResult
    {
        return new VerificationGateResult(
            tests: [],
            gates: [],
            aggregateStatus: VerificationGateResult::STATUS_PASSED,
            honestyFlags: [],
        );
    }

    private function decide(LightTaskContract $contract, VerificationGateResult $verificationResult): CompletionDecision
    {
        return (new CompletionStateGate)->decide(
            taskContract: $contract,
            scopeReceipt: $this->buildScopeReceipt(),
            verificationResult: $verificationResult,
            callResult: ProviderCallResult::fromStdout(
                runId: 'run-e1-flag-test',
                actualProvider: 'hermes_cli',
                actualModelFamily: 'minimax-m3',
                exitStatus: 0,
                stdout: 'ok',
                stderr: '',
                durationMs: 100,
            ),
            diffResult: DiffParseResult::patch(
                diff: "--- a/file\n+++ b/file\n@@\n+added\n",
                changedFiles: ['app/Foo.php'],
            ),
        );
    }

    /**
     * @param  list<string>  $intentVerbs
     */
    private function makeContract(array $intentVerbs): LightTaskContract
    {
        return new LightTaskContract(
            runId: 'run-e1-flag-test',
            taskId: 'task-e1-flag',
            specHash: 'spec-hash-e1-flag',
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
            taskContractHash: 'tch-e1-flag',
            noTestReason: null,
            intentText: $intentVerbs === [] ? '' : 'write task intent',
            intentVerbs: $intentVerbs,
        );
    }

    private function buildScopeReceipt(): ScopeGuardReceipt
    {
        return ScopeGuardReceipt::issue(
            runId: 'run-e1-flag-test',
            taskContractHash: 'tch-e1-flag',
            baseline: new ScopeBaseline('clean', null),
            observed: new ScopeObserved(
                gitDiffHash: hash('sha256', 'diff'),
                changedFiles: ['app/Foo.php'],
                changedFilesCount: 1,
                fileDiffs: [
                    new ScopeFileDiff(
                        path: 'app/Foo.php',
                        added: 5,
                        removed: 0,
                        fileHashAfter: hash('sha256', 'foo'),
                    ),
                ],
            ),
            scopeContract: new ScopeContractView(
                allowedFiles: ['app/Foo.php'],
                watchedFiles: [],
                forbiddenFiles: [],
                expectedMaxFiles: 1,
            ),
            violations: [],
            status: ScopeGuardReceipt::STATUS_PASSED,
            statusReason: 'all_files_allowed',
            userPreExistingChanges: [],
        );
    }
}
