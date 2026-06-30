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

    public function test_append_writes_one_jsonl_line_with_required_fields(): void
    {
        $ledger = new AtlasSelfConstructionLearningTransferAdmissionLedger($this->path);
        $plan = ['target_surface' => 'docs_surface', 'class' => 'reusable', 'source_evidence_refs' => ['phpunit:test_passed']];
        $r = $ledger->append($plan);

        self::assertSame('recorded', $r['status']);
        self::assertNotEmpty($r['plan_hash']);

        $lines = file($this->path, FILE_IGNORE_NEW_LINES | FILE_SKIP_EMPTY_LINES) ?: [];
        self::assertCount(1, $lines);
        $row = json_decode($lines[0], true);
        foreach (['schema_version', 'plan_hash', 'recorded_at', 'mode', 'intended_action', 'plan'] as $field) {
            self::assertArrayHasKey($field, $row);
        }
    }

    public function test_idempotent_second_append_returns_already_recorded(): void
    {
        $ledger = new AtlasSelfConstructionLearningTransferAdmissionLedger($this->path);
        $plan = ['target_surface' => 'docs_surface', 'class' => 'reusable', 'source_evidence_refs' => ['phpunit:test_passed']];
        $first = $ledger->append($plan);
        $second = $ledger->append($plan);

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
        $plan = ['source_evidence_refs' => ['phpunit:exit_0']];

        foreach (['proxy', 'cosmetic'] as $label) {
            try {
                $ledger->append($plan, ['classification' => ['label' => $label]]);
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
            ['source_evidence_refs' => ['phpunit:exit_0']],
            ['gate_decision' => ['verdict' => 'reject']],
        );

        self::assertFileDoesNotExist($this->path, 'no ledger write on refusal');
    }

    public function test_append_admits_plan_with_valid_gate_decision_allow(): void
    {
        $ledger = new AtlasSelfConstructionLearningTransferAdmissionLedger($this->path);
        $plan = ['source_evidence_refs' => ['phpunit:exit_0'], 'lesson' => 'foo'];

        $r = $ledger->append($plan, ['gate_decision' => ['verdict' => 'allow']]);
        self::assertSame('recorded', $r['status']);
    }
}
