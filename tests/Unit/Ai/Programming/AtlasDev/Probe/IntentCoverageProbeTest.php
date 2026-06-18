<?php

declare(strict_types=1);

namespace Tests\Unit\Ai\Programming\AtlasDev\Probe;

use App\Services\Ai\Programming\AtlasDev\Probe\IntentCoverageProbe;
use App\Services\Ai\Programming\AtlasDev\Schemas\Components\ProviderLock;
use App\Services\Ai\Programming\AtlasDev\Schemas\Components\RepairPolicy;
use App\Services\Ai\Programming\AtlasDev\Schemas\Components\VerificationPlan;
use App\Services\Ai\Programming\AtlasDev\Schemas\LightTaskContract;
use App\Services\Ai\Programming\AtlasDev\Schemas\MiniProgrammingSpec;
use PHPUnit\Framework\TestCase;

/**
 * E2 — IntentCoverageProbe unit tests.
 *
 * Covers the core of VAL-E2-009 and VAL-E2-010 at the probe level (the
 * integration through the PipelineRunExecutor is covered separately by the
 * synthetic-diff harness feature tests).
 *
 * The probe answers: "does the spec define a real verification path for the
 * intent?" It returns true (intent_not_tested) when a write task's intent is
 * NOT backed by any behavioral AC (id prefixed `ac_behavior_`) with a real
 * verification_ref. It returns false when the intent IS tested, OR when the
 * task is not a write task (empty intent_text => no intent to flag).
 */
final class IntentCoverageProbeTest extends TestCase
{
    public function test_val_e2_009_intent_not_tested_fires_when_only_tautological_acs_back_intent(): void
    {
        // Write task with a non-empty intent_text but only tautological ACs
        // (command/scope backstop, NO behavioral AC with a real verification_ref).
        $contract = $this->makeContract(intentText: 'corrija o bug');
        $spec = $this->makeSpec(acceptanceCriteria: [
            ['id' => 'ac_cmd_1', 'description' => "comando 'composer test' termina com exit_code=0", 'verification' => 'test', 'verification_ref' => 'composer test'],
            ['id' => 'ac_scope', 'description' => 'diff toca somente arquivos previstos', 'verification' => 'scope_guard', 'verification_ref' => null],
        ]);

        $probe = new IntentCoverageProbe;

        $this->assertTrue(
            $probe->isIntentNotTested($contract, $spec),
            'VAL-E2-009: intent_not_tested must fire when only tautological ACs back the intent',
        );
    }

    public function test_val_e2_009_intent_not_tested_fires_when_no_ac_at_all(): void
    {
        $contract = $this->makeContract(intentText: 'corrija o bug');
        $spec = $this->makeSpec(acceptanceCriteria: []);

        $probe = new IntentCoverageProbe;

        $this->assertTrue(
            $probe->isIntentNotTested($contract, $spec),
            'VAL-E2-009: intent_not_tested must fire when no AC backs the intent',
        );
    }

    public function test_val_e2_009_intent_not_tested_fires_when_behavioral_ac_has_empty_verification_ref(): void
    {
        // A behavioral AC with an EMPTY verification_ref does NOT count as
        // "backing the intent" (VAL-E2-005 requires a real verification_ref).
        $contract = $this->makeContract(intentText: 'corrija o bug');
        $spec = $this->makeSpec(acceptanceCriteria: [
            ['id' => 'ac_behavior_corrigir', 'description' => 'behavioral', 'verification' => 'test', 'verification_ref' => null],
        ]);

        $probe = new IntentCoverageProbe;

        $this->assertTrue(
            $probe->isIntentNotTested($contract, $spec),
            'VAL-E2-009: behavioral AC with empty verification_ref does not count as backing the intent',
        );
    }

    public function test_val_e2_010_intent_not_tested_does_not_fire_when_behavioral_ac_with_real_ref_backs_intent(): void
    {
        // Write task with a behavioral AC carrying a real verification_ref.
        $contract = $this->makeContract(intentText: 'corrija o bug');
        $spec = $this->makeSpec(acceptanceCriteria: [
            ['id' => 'ac_behavior_corrigir', 'description' => "diff implementa o verbo 'corrigir'", 'verification' => 'test', 'verification_ref' => 'composer test'],
            ['id' => 'ac_cmd_1', 'description' => "comando 'composer test' termina com exit_code=0", 'verification' => 'test', 'verification_ref' => 'composer test'],
        ]);

        $probe = new IntentCoverageProbe;

        $this->assertFalse(
            $probe->isIntentNotTested($contract, $spec),
            'VAL-E2-010: intent_not_tested must NOT fire when a behavioral AC with a real verification_ref backs the intent',
        );
    }

    public function test_val_e2_010_intent_not_tested_does_not_fire_for_multi_verb_with_all_tested(): void
    {
        $contract = $this->makeContract(intentText: 'rename X e remova Y');
        $spec = $this->makeSpec(acceptanceCriteria: [
            ['id' => 'ac_behavior_renomear', 'description' => 'rename', 'verification' => 'test', 'verification_ref' => 'composer test'],
            ['id' => 'ac_behavior_remover', 'description' => 'remove', 'verification' => 'test', 'verification_ref' => 'composer test'],
        ]);

        $probe = new IntentCoverageProbe;

        $this->assertFalse(
            $probe->isIntentNotTested($contract, $spec),
            'VAL-E2-010: multi-verb intent with all behavioral ACs tested must NOT fire',
        );
    }

    public function test_intent_not_tested_does_not_fire_for_read_only_task_empty_intent_text(): void
    {
        // Read-only / review / escalate-preview tasks have an empty intent_text
        // and must never trip the flag (byte-identical to pre-E2 for non-write).
        $contract = $this->makeContract(intentText: '');
        $spec = $this->makeSpec(acceptanceCriteria: []);

        $probe = new IntentCoverageProbe;

        $this->assertFalse(
            $probe->isIntentNotTested($contract, $spec),
            'read-only task (empty intent_text) must never trip intent_not_tested',
        );
    }

    public function test_intent_not_tested_conservatively_fires_when_spec_is_null(): void
    {
        // Safe degradation: when the spec is unreadable, a write task's intent
        // is conservatively treated as not-tested (never silently green).
        $contract = $this->makeContract(intentText: 'corrija o bug');

        $probe = new IntentCoverageProbe;

        $this->assertTrue(
            $probe->isIntentNotTested($contract, null),
            'safe degradation: write task with unreadable spec must be treated as not-tested',
        );
    }

    public function test_intent_not_tested_does_not_fire_when_spec_is_null_and_intent_empty(): void
    {
        // Null spec + empty intent_text (read-only) => no flag.
        $contract = $this->makeContract(intentText: '');

        $probe = new IntentCoverageProbe;

        $this->assertFalse(
            $probe->isIntentNotTested($contract, null),
            'read-only task with null spec must not trip the flag',
        );
    }

    public function test_flag_name_constant_is_stable(): void
    {
        $this->assertSame('intent_not_tested', IntentCoverageProbe::FLAG_INTENT_NOT_TESTED);
    }

    // -- Helpers -------------------------------------------------------------

    private function makeContract(string $intentText = ''): LightTaskContract
    {
        return new LightTaskContract(
            runId: 'run-probe-test',
            taskId: 'task-probe',
            specHash: 'spec-hash',
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
                provider: 'claude_cli',
                modelFamily: 'sonnet',
                fallbackAllowed: false,
            ),
            taskContractHash: 'tch',
            noTestReason: null,
            intentText: $intentText,
        );
    }

    /**
     * @param  list<array{id:string,description:string,verification:string,verification_ref:?string}>  $acceptanceCriteria
     */
    private function makeSpec(array $acceptanceCriteria): MiniProgrammingSpec
    {
        return new MiniProgrammingSpec(
            runId: 'run-probe-test',
            compactSddHash: 'compact-hash',
            goal: 'test goal',
            nonGoals: [],
            canonicalContext: [],
            expectedBehavior: [],
            assumptions: [],
            expectedFiles: [],
            allowedFiles: [],
            forbiddenFiles: [],
            acceptanceCriteria: $acceptanceCriteria,
            verificationPlan: new VerificationPlan(
                profile: 'php_laravel',
                commands: ['composer test'],
                noTestReason: null,
            ),
            rollbackOrContainment: 'git checkout -- .',
            completionCriteria: [],
            miniSpecHash: 'ms-hash',
        );
    }
}
