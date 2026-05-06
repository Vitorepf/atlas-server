<?php

namespace Tests\Feature\Architecture;

use App\Services\Ai\Kernel\Decision\DecisionReceipt;
use App\Services\Ai\Kernel\Decision\DecisionReceiptIssuer;
use App\Services\Ai\Kernel\Decision\DecisionReceiptRuntimeGuard;
use App\Services\Ai\Kernel\Envelope\OperationEnvelopeFactory;
use Carbon\CarbonImmutable;
use Tests\TestCase;

class DecisionReceiptDeterminismTest extends TestCase
{
    protected function tearDown(): void
    {
        CarbonImmutable::setTestNow();

        parent::tearDown();
    }

    public function test_receipt_hashes_are_stable_for_same_envelope_and_decision_contract(): void
    {
        CarbonImmutable::setTestNow(CarbonImmutable::parse('2026-05-05T12:00:00Z'));

        $envelope = app(OperationEnvelopeFactory::class)->create([
            'text' => 'implemente feature protegida por receipt',
            'hints' => ['flow' => 'programming.dev'],
        ]);
        $decision = $this->decisionContract();
        $issuer = app(DecisionReceiptIssuer::class);

        $first = $issuer->issue($envelope, $decision);
        $second = $issuer->issue($envelope, $decision);

        $this->assertSame(DecisionReceipt::SCHEMA_VERSION, $first->schemaVersion);
        $this->assertSame($first->inputsHash, $second->inputsHash);
        $this->assertSame($first->receiptHash, $second->receiptHash);
        $this->assertSame($first->chainHash, $second->chainHash);
        $this->assertSame($envelope->input->inputHash, $first->metadata['envelope_input_hash']);
        $this->assertNull((new DecisionReceiptRuntimeGuard)->violationForReceipt(['receipt_v2' => $first->toArray()]));
    }

    public function test_receipt_hash_changes_when_authorized_provider_changes(): void
    {
        CarbonImmutable::setTestNow(CarbonImmutable::parse('2026-05-05T12:00:00Z'));

        $envelope = app(OperationEnvelopeFactory::class)->create([
            'text' => 'mesma tarefa com provider diferente',
        ]);
        $issuer = app(DecisionReceiptIssuer::class);

        $codex = $issuer->issue($envelope, $this->decisionContract([
            'provider_selection' => [
                'primary' => 'codex_cli',
                'model' => 'gpt-5.5',
                'fallbacks' => [],
            ],
        ]));
        $claude = $issuer->issue($envelope, $this->decisionContract([
            'provider_selection' => [
                'primary' => 'claude_cli',
                'model' => 'opus',
                'fallbacks' => [],
            ],
        ]));

        $this->assertNotSame($codex->inputsHash, $claude->inputsHash);
        $this->assertNotSame($codex->receiptHash, $claude->receiptHash);
        $this->assertNotSame($codex->chainHash, $claude->chainHash);
    }

    public function test_runtime_guard_rejects_replayed_receipt_after_signed_payload_mutation(): void
    {
        CarbonImmutable::setTestNow(CarbonImmutable::parse('2026-05-05T12:00:00Z'));

        $envelope = app(OperationEnvelopeFactory::class)->create([
            'text' => 'mutacao deve quebrar replay',
        ]);
        $receipt = app(DecisionReceiptIssuer::class)
            ->issue($envelope, $this->decisionContract())
            ->toArray();

        $receipt['repair_policy']['max_attempts'] = 9;

        $violation = (new DecisionReceiptRuntimeGuard)->violationForReceipt(['receipt_v2' => $receipt]);

        $this->assertSame('decision_receipt_hash_mismatch', $violation?->errorCode);
        $this->assertSame('rcpt_arch_deterministic', $violation?->receiptId);
    }

    /**
     * @param  array<string,mixed>  $overrides
     * @return array<string,mixed>
     */
    private function decisionContract(array $overrides = []): array
    {
        return array_replace_recursive([
            'receipt_id' => 'rcpt_arch_deterministic',
            'issued_at' => '2026-05-05T12:00:00Z',
            'expires_at' => '2026-05-05T12:01:00Z',
            'domain' => 'programming',
            'flow' => 'programming.dev',
            'risk' => 'medium',
            'provider_selection' => [
                'primary' => 'codex_cli',
                'model' => 'gpt-5.5',
                'fallbacks' => ['claude_cli'],
                'selection_mode' => 'auto_best_allowed',
                'selection_reason' => 'architecture determinism fixture',
            ],
            'budgets' => [
                'max_cost_usd' => 1.25,
                'max_wall_seconds' => 120,
            ],
            'required_gates' => ['tests'],
            'required_evidence' => ['summary', 'diff'],
            'repair_policy' => [
                'enabled' => true,
                'max_attempts' => 2,
            ],
        ], $overrides);
    }
}
