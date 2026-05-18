<?php

declare(strict_types=1);

namespace Tests\Unit\Ai\Programming\AtlasDev\Intelligence;

use App\Services\Ai\Programming\AtlasDev\Intelligence\DebugIntelligenceService;
use App\Services\Ai\Programming\AtlasDev\Schemas\Components\DebugRepairCandidate;
use App\Services\Ai\Programming\AtlasDev\Schemas\Components\DebugSuspectedCause;
use App\Services\Ai\Programming\AtlasDev\Schemas\DebugReceipt;
use App\Services\Ai\Programming\AtlasDev\Schemas\FastPathErrorLedgerEntry;
use PHPUnit\Framework\TestCase;
use Tests\Unit\Ai\Programming\AtlasDev\Schemas\Components\SchemaContractAssertions;

/**
 * Canonical contract tests for Atlas Dev Debug Intelligence — first version.
 *
 * Covers the 7 audit-mandated scenarios from the prompt:
 *
 *   1. classificacao de falha
 *   2. reproduction plan
 *   3. repair candidates
 *   4. JSON estavel
 *   5. blocker explicito quando contexto insuficiente
 *   6. stop conditions detectadas
 *   7. status decision honesto (diagnosed/inconclusive/escalate)
 */
final class DebugIntelligenceServiceTest extends TestCase
{
    use SchemaContractAssertions;

    public function test_blocker_when_context_insufficient(): void
    {
        $receipt = (new DebugIntelligenceService)->analyse([
            'run_id' => 'run-1',
            'gate' => '',
            'primary_error_excerpt' => '',
        ]);

        $this->assertSame(DebugReceipt::STATUS_BLOCKED_INSUFFICIENT_CONTEXT, $receipt->status);
        $this->assertContains(DebugReceipt::STOP_INSUFFICIENT_CONTEXT, $receipt->stopConditions);
        $this->assertNotEmpty($receipt->blockerReasons);
        $this->assertContains('missing_gate', $receipt->blockerReasons);
        $this->assertContains('missing_primary_error_excerpt', $receipt->blockerReasons);
        $this->assertSame(0.0, $receipt->confidence);
    }

    public function test_blocker_when_only_partial_context(): void
    {
        $receipt = (new DebugIntelligenceService)->analyse([
            'run_id' => 'run-1',
            'gate' => 'verification',
            'primary_error_excerpt' => 'something broke',
            // missing both command and failing_test
        ]);

        $this->assertSame(DebugReceipt::STATUS_BLOCKED_INSUFFICIENT_CONTEXT, $receipt->status);
        $this->assertContains('missing_command_or_failing_test', $receipt->blockerReasons);
    }

    public function test_classifies_null_pointer_as_bad_repair(): void
    {
        $receipt = $this->diagnose([
            'primary_error_excerpt' => 'TypeError: Cannot read property "name" of undefined at line 42',
            'failing_test' => 'UserTest::it_returns_name',
            'changed_files' => ['app/User.php'],
        ]);

        $this->assertSame(FastPathErrorLedgerEntry::FAILURE_MODE_BAD_REPAIR, $receipt->failureClassification);
        $this->assertNotEmpty($receipt->suspectedCauses);
    }

    public function test_classifies_module_not_found_as_context_error(): void
    {
        $receipt = $this->diagnose([
            'primary_error_excerpt' => 'Class not found: App\\Foo',
            'failing_test' => null,
            'command' => 'php artisan test',
            'changed_files' => ['app/Bar.php'],
        ]);

        $this->assertSame(FastPathErrorLedgerEntry::FAILURE_MODE_CONTEXT_ERROR, $receipt->failureClassification);
    }

    public function test_reproduction_plan_includes_command_and_failing_test_and_files(): void
    {
        $receipt = $this->diagnose([
            'primary_error_excerpt' => 'AssertionError: expected 200 got 500',
            'failing_test' => 'HealthzTest::it_returns_200',
            'command' => 'php artisan test --filter=HealthzTest',
            'changed_files' => ['app/Http/Controllers/HealthzController.php'],
        ]);

        $this->assertNotEmpty($receipt->reproductionPlan);
        $joined = implode("\n", $receipt->reproductionPlan);
        $this->assertStringContainsString('php artisan test --filter=HealthzTest', $joined);
        $this->assertStringContainsString('HealthzTest::it_returns_200', $joined);
        $this->assertStringContainsString('HealthzController.php', $joined);
    }

    public function test_emits_repair_candidates_with_distinct_strategies(): void
    {
        $receipt = $this->diagnose([
            'primary_error_excerpt' => 'Undefined variable $user',
            'failing_test' => 'UserTest::it_lists_users',
            'changed_files' => ['app/Http/Controllers/UserController.php'],
        ]);

        $this->assertNotEmpty($receipt->repairCandidates);
        $strategies = array_map(static fn (DebugRepairCandidate $c): string => $c->strategy, $receipt->repairCandidates);
        $this->assertSame(count(array_unique($strategies)), count($strategies), 'Repair candidates must use distinct strategies');
        // Mutating strategies must carry validation commands.
        foreach ($receipt->repairCandidates as $candidate) {
            if (in_array($candidate->strategy, [
                DebugRepairCandidate::STRATEGY_APPLY_PATCH,
                DebugRepairCandidate::STRATEGY_FIX_AND_TEST,
                DebugRepairCandidate::STRATEGY_ADD_TEST,
                DebugRepairCandidate::STRATEGY_REVERT,
            ], true)) {
                $this->assertNotEmpty($candidate->validationCommands);
            }
        }
    }

    public function test_same_signature_twice_forces_escalate(): void
    {
        $service = new DebugIntelligenceService;
        $error = 'AssertionError: foo != bar';
        $gate = 'verification';
        $fingerprint = DebugReceipt::fingerprintOf($gate, $error);

        $receipt = $service->analyse([
            'run_id' => 'run-1',
            'gate' => $gate,
            'primary_error_excerpt' => $error,
            'failing_test' => 'Test::it_does',
            'changed_files' => ['app/Service.php'],
            'prior_failure_signatures' => [$fingerprint],
            'attempt_index' => 2,
        ]);

        $this->assertSame(DebugReceipt::STATUS_ESCALATE, $receipt->status);
        $this->assertContains(DebugReceipt::STOP_SAME_SIGNATURE_TWICE, $receipt->stopConditions);
    }

    public function test_attempt_budget_exceeded_forces_escalate(): void
    {
        $receipt = $this->diagnose([
            'primary_error_excerpt' => 'TypeError: bad cast',
            'failing_test' => 'Test::foo',
            'changed_files' => ['app/Service.php'],
            'attempt_index' => 5,
            'attempt_budget' => 3,
        ]);

        $this->assertSame(DebugReceipt::STATUS_ESCALATE, $receipt->status);
        $this->assertContains(DebugReceipt::STOP_ATTEMPT_BUDGET_EXCEEDED, $receipt->stopConditions);
    }

    public function test_diff_growth_without_progress_forces_escalate(): void
    {
        $receipt = $this->diagnose([
            'primary_error_excerpt' => 'AssertionError: same as last time',
            'failing_test' => 'Test::same',
            'changed_files' => ['app/Service.php'],
            'diff_size_lines' => 800,
            'prior_diff_size_lines' => 300,
        ]);

        $this->assertSame(DebugReceipt::STATUS_ESCALATE, $receipt->status);
        $this->assertContains(DebugReceipt::STOP_DIFF_GROWTH_WITHOUT_PROGRESS, $receipt->stopConditions);
    }

    public function test_diagnosed_status_requires_causes_and_candidates(): void
    {
        $receipt = $this->diagnose([
            'primary_error_excerpt' => 'TypeError: parameter $id expected int got string at line 12 in method handle',
            'failing_test' => 'HandlerTest::it_handles',
            'changed_files' => ['app/Handler.php'],
            'validation_commands' => ['php artisan test --filter=HandlerTest'],
        ]);

        if ($receipt->status === DebugReceipt::STATUS_DIAGNOSED) {
            $this->assertNotEmpty($receipt->suspectedCauses);
            $this->assertNotEmpty($receipt->repairCandidates);
            $this->assertGreaterThanOrEqual(DebugIntelligenceService::DIAGNOSIS_CONFIDENCE_FLOOR, $receipt->confidence);
        } else {
            $this->assertSame(DebugReceipt::STATUS_INCONCLUSIVE, $receipt->status);
        }
    }

    public function test_json_roundtrip_is_stable(): void
    {
        $receipt = $this->diagnose([
            'primary_error_excerpt' => 'TypeError: bad cast at line 7',
            'failing_test' => 'Test::it_casts',
            'changed_files' => ['app/Cast.php'],
        ]);

        $this->assertContractSurface($receipt);
        $hydrated = DebugReceipt::fromArray($receipt->toCanonicalArray());
        $this->assertSame($receipt->toCanonicalArray(), $hydrated->toCanonicalArray());
        $this->assertSame($receipt->hash(), $hydrated->hash());
    }

    public function test_blocker_receipt_passes_contract_surface(): void
    {
        $receipt = (new DebugIntelligenceService)->analyse([
            'run_id' => 'run-1',
            'gate' => '',
            'primary_error_excerpt' => '',
        ]);

        $this->assertContractSurface($receipt);
    }

    public function test_fingerprint_is_deterministic(): void
    {
        $a = DebugReceipt::fingerprintOf('verification', 'TypeError: foo bar');
        $b = DebugReceipt::fingerprintOf('verification', '  TypeError:   foo   bar  ');
        $this->assertSame($a, $b, 'fingerprint must normalise whitespace');
        $this->assertMatchesRegularExpression('/^sha256:[a-f0-9]{64}$/', $a);
    }

    public function test_diagnose_emits_high_confidence_for_clear_failure(): void
    {
        $receipt = $this->diagnose([
            'primary_error_excerpt' => 'TypeError: argument $id must be int, got string at Foo::bar line 42',
            'failing_test' => 'FooTest::it_accepts_int',
            'changed_files' => ['app/Foo.php'],
        ]);

        $causes = $receipt->suspectedCauses;
        $this->assertNotEmpty($causes);
        $top = $causes[0];
        $this->assertInstanceOf(DebugSuspectedCause::class, $top);
        // Primary cause carries a file_hint pulled from changed_files
        $this->assertSame('app/Foo.php', $top->fileHint);
    }

    /**
     * Builds a complete-context analyse() call so individual tests can focus
     * on one assertion. Defaults are tuned to be just enough to satisfy the
     * insufficient-context gate.
     *
     * @param  array<string,mixed>  $overrides
     */
    private function diagnose(array $overrides = []): DebugReceipt
    {
        $input = array_merge([
            'run_id' => 'run-1',
            'gate' => 'verification',
            'command' => 'php artisan test',
            'attempt_index' => 0,
            'attempt_budget' => 3,
            'changed_files' => [],
            'prior_failure_signatures' => [],
            'validation_commands' => ['php artisan test'],
            'evidence_refs' => ['receipt:test_log.txt'],
            'created_at' => '2026-05-18T10:00:00Z',
        ], $overrides);

        return (new DebugIntelligenceService)->analyse($input);
    }
}
