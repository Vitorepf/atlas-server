<?php

declare(strict_types=1);

namespace Tests\Unit\Ai\SelfConstruction\LearningTransfer;

use App\Services\Ai\SelfConstruction\LearningTransfer\AtlasSelfConstructionLearningTransferAdmissionLedger;
use Tests\TestCase;

class AtlasSelfConstructionLearningTransferAdmissionLedgerTest extends TestCase
{
    private string $path = '';

    protected function setUp(): void
    {
        parent::setUp();
        $this->path = sys_get_temp_dir().'/atlas-lt-admit-ledger-'.bin2hex(random_bytes(6)).'.jsonl';
    }

    protected function tearDown(): void
    {
        @unlink($this->path);
        parent::tearDown();
    }

    /** @return array<string,mixed> */
    private function validPlan(array $overrides = []): array
    {
        return array_merge([
            'target_surface' => 'docs_surface',
            'class' => 'reusable',
            'source_evidence_refs' => ['phpunit:test_passed'],
            'impact_class' => 'high_leverage',
            'design_path_refs' => ['docs/engineering-knowledge-base/loop-canonical-definition.md'],
        ], $overrides);
    }

    /** @return array<string,mixed> */
    private function validContext(array $overrides = []): array
    {
        return array_merge([
            'muscle_outcome' => ['status' => 'success'],
        ], $overrides);
    }

    public function test_append_writes_one_jsonl_line_with_required_fields(): void
    {
        $ledger = new AtlasSelfConstructionLearningTransferAdmissionLedger($this->path);
        $r = $ledger->append($this->validPlan(), $this->validContext());

        self::assertSame('recorded', $r['status']);
        self::assertNotEmpty($r['plan_hash']);

        $lines = file($this->path, FILE_IGNORE_NEW_LINES | FILE_SKIP_EMPTY_LINES) ?: [];
        self::assertCount(1, $lines);
        $row = json_decode($lines[0], true);
        foreach (['schema_version', 'plan_hash', 'recorded_at', 'mode', 'intended_action', 'plan', 'muscle_outcome', 'impact_class', 'design_path_refs'] as $field) {
            self::assertArrayHasKey($field, $row);
        }
    }

    public function test_idempotent_second_append_returns_already_recorded(): void
    {
        $ledger = new AtlasSelfConstructionLearningTransferAdmissionLedger($this->path);
        $plan = $this->validPlan();
        $first = $ledger->append($plan, $this->validContext());
        $second = $ledger->append($plan, $this->validContext());

        self::assertSame('recorded', $first['status']);
        self::assertSame('already_recorded', $second['status']);
        self::assertSame($first['plan_hash'], $second['plan_hash']);

        $lines = file($this->path, FILE_IGNORE_NEW_LINES | FILE_SKIP_EMPTY_LINES) ?: [];
        self::assertCount(1, $lines, 'idempotent — only one line on disk');
    }

    public function test_plan_hash_is_deterministic_across_key_order(): void
    {
        $ledger = new AtlasSelfConstructionLearningTransferAdmissionLedger($this->path);
        $a = $ledger->planHash(['target_surface' => 'docs_surface', 'class' => 'forbidden_target']);
        $b = $ledger->planHash(['class' => 'forbidden_target', 'target_surface' => 'docs_surface']);
        self::assertSame($a, $b);
    }

    public function test_all_returns_empty_for_non_existing_path(): void
    {
        @unlink($this->path);
        $ledger = new AtlasSelfConstructionLearningTransferAdmissionLedger($this->path);
        self::assertSame([], $ledger->all());
    }

    public function test_append_refuses_plan_missing_source_evidence_refs(): void
    {
        $this->expectException(\InvalidArgumentException::class);
        $this->expectExceptionMessageMatches('/missing_source_evidence_refs/');

        $ledger = new AtlasSelfConstructionLearningTransferAdmissionLedger($this->path);
        $ledger->append(['target_surface' => 'docs_surface']);

        self::assertFileDoesNotExist($this->path, 'no ledger write on refusal');
    }

    public function test_append_refuses_proxy_or_cosmetic_classification(): void
    {
        $ledger = new AtlasSelfConstructionLearningTransferAdmissionLedger($this->path);
        $plan = $this->validPlan();

        foreach (['proxy', 'cosmetic'] as $label) {
            try {
                $ledger->append($plan, $this->validContext(['classification' => ['label' => $label]]));
                self::fail("Expected exception for label=$label");
            } catch (\InvalidArgumentException $e) {
                self::assertStringContainsString($label, $e->getMessage());
            }
        }
        self::assertFileDoesNotExist($this->path, 'no ledger write on refusal');
    }

    public function test_append_refuses_invalid_gate_decision(): void
    {
        $this->expectException(\InvalidArgumentException::class);
        $this->expectExceptionMessageMatches('/invalid_gate_decision/');

        $ledger = new AtlasSelfConstructionLearningTransferAdmissionLedger($this->path);
        $ledger->append(
            $this->validPlan(),
            $this->validContext(['gate_decision' => ['verdict' => 'reject']]),
        );

        self::assertFileDoesNotExist($this->path, 'no ledger write on refusal');
    }

    public function test_append_admits_plan_with_valid_gate_decision_allow(): void
    {
        $ledger = new AtlasSelfConstructionLearningTransferAdmissionLedger($this->path);
        $plan = $this->validPlan(['lesson' => 'foo']);

        $r = $ledger->append($plan, $this->validContext(['gate_decision' => ['verdict' => 'allow']]));
        self::assertSame('recorded', $r['status']);
    }

    // ── AC: muscle_outcome status must be success|resolved|green_commit ──────

    public function test_append_refuses_missing_muscle_outcome(): void
    {
        $this->expectException(\InvalidArgumentException::class);
        $this->expectExceptionMessageMatches('/missing_or_invalid_muscle_outcome/');

        $ledger = new AtlasSelfConstructionLearningTransferAdmissionLedger($this->path);
        $ledger->append($this->validPlan());

        self::assertFileDoesNotExist($this->path);
    }

    public function test_append_refuses_invalid_muscle_outcome_status(): void
    {
        $this->expectException(\InvalidArgumentException::class);
        $this->expectExceptionMessageMatches('/missing_or_invalid_muscle_outcome/');

        $ledger = new AtlasSelfConstructionLearningTransferAdmissionLedger($this->path);
        $ledger->append($this->validPlan(), ['muscle_outcome' => ['status' => 'give_back']]);
    }

    public function test_append_accepts_all_three_valid_muscle_outcome_statuses(): void
    {
        foreach (['success', 'resolved', 'green_commit'] as $status) {
            $path = sys_get_temp_dir().'/atlas-lt-admit-ledger-'.bin2hex(random_bytes(6)).'.jsonl';
            $ledger = new AtlasSelfConstructionLearningTransferAdmissionLedger($path);
            $r = $ledger->append($this->validPlan(), ['muscle_outcome' => ['status' => $status]]);
            self::assertSame('recorded', $r['status'], "status={$status} must be accepted");
            @unlink($path);
        }
    }

    // ── AC: impact_class and design_path_refs must be declared and non-empty ──

    public function test_append_refuses_missing_impact_class(): void
    {
        $this->expectException(\InvalidArgumentException::class);
        $this->expectExceptionMessageMatches('/missing_impact_class/');

        $ledger = new AtlasSelfConstructionLearningTransferAdmissionLedger($this->path);
        $plan = $this->validPlan();
        unset($plan['impact_class']);
        $ledger->append($plan, $this->validContext());
    }

    public function test_append_refuses_missing_design_path_refs(): void
    {
        $this->expectException(\InvalidArgumentException::class);
        $this->expectExceptionMessageMatches('/missing_design_path_refs/');

        $ledger = new AtlasSelfConstructionLearningTransferAdmissionLedger($this->path);
        $plan = $this->validPlan();
        unset($plan['design_path_refs']);
        $ledger->append($plan, $this->validContext());
    }

    public function test_append_refuses_empty_design_path_refs(): void
    {
        $this->expectException(\InvalidArgumentException::class);
        $this->expectExceptionMessageMatches('/missing_design_path_refs/');

        $ledger = new AtlasSelfConstructionLearningTransferAdmissionLedger($this->path);
        $plan = $this->validPlan(['design_path_refs' => []]);
        $ledger->append($plan, $this->validContext());
    }

    // ── AC: source_evidence_refs placeholder strings are refused ──────────────

    public function test_append_refuses_placeholder_evidence_refs(): void
    {
        foreach (['TODO: add proof', 'fake_evidence', 'synthetic-run', 'example_ref', 'tbd'] as $placeholder) {
            $ledger = new AtlasSelfConstructionLearningTransferAdmissionLedger($this->path);
            $plan = $this->validPlan(['source_evidence_refs' => [$placeholder]]);

            try {
                $ledger->append($plan, $this->validContext());
                self::fail("Expected exception for placeholder={$placeholder}");
            } catch (\InvalidArgumentException $e) {
                self::assertStringContainsString('placeholder_evidence_ref', $e->getMessage());
            }
        }
        self::assertFileDoesNotExist($this->path);
    }

    public function test_append_accepts_non_placeholder_evidence_refs(): void
    {
        $ledger = new AtlasSelfConstructionLearningTransferAdmissionLedger($this->path);
        $r = $ledger->append($this->validPlan(['source_evidence_refs' => ['phpunit:test_run_42']]), $this->validContext());
        self::assertSame('recorded', $r['status']);
    }

    // ── AC: recorded rows include the three fields in deterministic JSONL form ──

    public function test_recorded_row_includes_muscle_outcome_impact_class_and_design_path_refs(): void
    {
        $ledger = new AtlasSelfConstructionLearningTransferAdmissionLedger($this->path);
        $ledger->append(
            $this->validPlan(['impact_class' => 'foundational', 'design_path_refs' => ['docs/a.md', 'docs/b.md']]),
            $this->validContext(['muscle_outcome' => ['status' => 'green_commit', 'task_packet_id' => 't-1']]),
        );

        $rows = $ledger->all();
        self::assertCount(1, $rows);
        self::assertSame('foundational', $rows[0]['impact_class']);
        self::assertSame(['docs/a.md', 'docs/b.md'], $rows[0]['design_path_refs']);
        self::assertSame('green_commit', $rows[0]['muscle_outcome']['status']);
    }
}
