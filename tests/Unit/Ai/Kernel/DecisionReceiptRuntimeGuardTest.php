<?php

namespace Tests\Unit\Ai\Kernel;

use App\Models\AiJob;
use App\Services\Ai\Kernel\Decision\DecisionReceipt;
use App\Services\Ai\Kernel\Decision\DecisionReceiptRuntimeGuard;
use Carbon\CarbonImmutable;
use Tests\TestCase;

class DecisionReceiptRuntimeGuardTest extends TestCase
{
    protected function tearDown(): void
    {
        CarbonImmutable::setTestNow();

        parent::tearDown();
    }

    public function test_accepts_legacy_jobs_without_v2_receipt(): void
    {
        $guard = new DecisionReceiptRuntimeGuard;

        $this->assertNull($guard->violationForReceipt([]));
        $this->assertNull($guard->violationForReceipt(['decision_receipt' => ['receipt_id' => 'legacy']]));
    }

    public function test_accepts_valid_live_receipt(): void
    {
        CarbonImmutable::setTestNow(CarbonImmutable::parse('2026-05-05T12:00:00Z'));

        $guard = new DecisionReceiptRuntimeGuard;

        $this->assertNull($guard->violationForReceipt([
            'receipt_v2' => [
                'receipt_id' => 'rcpt_valid',
                'envelope_id' => 'env_valid',
                'schema_version' => DecisionReceipt::SCHEMA_VERSION,
                'expires_at' => '2026-05-05T12:01:00Z',
                'dry_run' => false,
            ],
        ]));
    }

    public function test_accepts_provider_and_model_that_match_receipt_selection(): void
    {
        CarbonImmutable::setTestNow(CarbonImmutable::parse('2026-05-05T12:00:00Z'));

        $violation = (new DecisionReceiptRuntimeGuard)->violationForReceipt([
            'receipt_v2' => [
                'receipt_id' => 'rcpt_match',
                'envelope_id' => 'env_match',
                'schema_version' => DecisionReceipt::SCHEMA_VERSION,
                'expires_at' => '2026-05-05T12:01:00Z',
                'dry_run' => false,
                'provider_selection' => [
                    'primary' => 'codex_cli',
                    'model' => 'gpt-5.5',
                    'fallbacks' => [],
                ],
            ],
        ], runtimeProvider: 'codex_cli', runtimeModel: 'gpt-5.5');

        $this->assertNull($violation);
    }

    public function test_blocks_invalid_schema(): void
    {
        $violation = (new DecisionReceiptRuntimeGuard)->violationForReceipt([
            'receipt_v2' => [
                'receipt_id' => 'rcpt_invalid',
                'envelope_id' => 'env_invalid',
                'schema_version' => 'atlas.decide.v1',
                'expires_at' => '2026-05-05T12:01:00Z',
                'dry_run' => false,
            ],
        ]);

        $this->assertSame('decision_receipt_invalid', $violation?->errorCode);
        $this->assertSame('rcpt_invalid', $violation?->receiptId);
        $this->assertSame('atlas.decide.v1', $violation?->schemaVersion);
    }

    public function test_blocks_dry_run_receipt(): void
    {
        $violation = (new DecisionReceiptRuntimeGuard)->violationForReceipt([
            'receipt_v2' => [
                'receipt_id' => 'rcpt_dry',
                'envelope_id' => 'env_dry',
                'schema_version' => DecisionReceipt::SCHEMA_VERSION,
                'expires_at' => '2026-05-05T12:01:00Z',
                'dry_run' => true,
            ],
        ]);

        $this->assertSame('decision_receipt_dry_run', $violation?->errorCode);
        $this->assertTrue($violation?->dryRun);
    }

    public function test_blocks_expired_receipt(): void
    {
        CarbonImmutable::setTestNow(CarbonImmutable::parse('2026-05-05T12:00:00Z'));

        $violation = (new DecisionReceiptRuntimeGuard)->violationForReceipt([
            'receipt_v2' => [
                'receipt_id' => 'rcpt_expired',
                'envelope_id' => 'env_expired',
                'schema_version' => DecisionReceipt::SCHEMA_VERSION,
                'expires_at' => '2026-05-05T11:59:59Z',
                'dry_run' => false,
            ],
        ]);

        $this->assertSame('decision_receipt_expired', $violation?->errorCode);
        $this->assertSame('env_expired', $violation?->envelopeId);
    }

    public function test_blocks_provider_mismatch_before_provider_execution(): void
    {
        CarbonImmutable::setTestNow(CarbonImmutable::parse('2026-05-05T12:00:00Z'));

        $violation = (new DecisionReceiptRuntimeGuard)->violationForReceipt([
            'receipt_v2' => [
                'receipt_id' => 'rcpt_provider_mismatch',
                'envelope_id' => 'env_provider_mismatch',
                'schema_version' => DecisionReceipt::SCHEMA_VERSION,
                'expires_at' => '2026-05-05T12:01:00Z',
                'dry_run' => false,
                'provider_selection' => [
                    'primary' => 'codex_cli',
                    'model' => 'gpt-5.5',
                    'fallbacks' => [],
                ],
            ],
        ], runtimeProvider: 'claude_cli', runtimeModel: 'gpt-5.5');

        $this->assertSame('decision_receipt_provider_mismatch', $violation?->errorCode);
        $this->assertSame('codex_cli', $violation?->expectedProvider);
        $this->assertSame('claude_cli', $violation?->actualProvider);
    }

    public function test_blocks_model_mismatch_before_provider_execution(): void
    {
        CarbonImmutable::setTestNow(CarbonImmutable::parse('2026-05-05T12:00:00Z'));

        $violation = (new DecisionReceiptRuntimeGuard)->violationForReceipt([
            'receipt_v2' => [
                'receipt_id' => 'rcpt_model_mismatch',
                'envelope_id' => 'env_model_mismatch',
                'schema_version' => DecisionReceipt::SCHEMA_VERSION,
                'expires_at' => '2026-05-05T12:01:00Z',
                'dry_run' => false,
                'provider_selection' => [
                    'primary' => 'codex_cli',
                    'model' => 'gpt-5.5',
                    'fallbacks' => [],
                ],
            ],
        ], runtimeProvider: 'codex_cli', runtimeModel: 'gpt-5.4');

        $this->assertSame('decision_receipt_model_mismatch', $violation?->errorCode);
        $this->assertSame('gpt-5.5', $violation?->expectedModel);
        $this->assertSame('gpt-5.4', $violation?->actualModel);
    }

    public function test_allows_council_child_providers_and_context_scout_provider(): void
    {
        CarbonImmutable::setTestNow(CarbonImmutable::parse('2026-05-05T12:00:00Z'));

        $guard = new DecisionReceiptRuntimeGuard;
        $receipt = [
            'receipt_v2' => [
                'receipt_id' => 'rcpt_composed',
                'envelope_id' => 'env_composed',
                'schema_version' => DecisionReceipt::SCHEMA_VERSION,
                'expires_at' => '2026-05-05T12:01:00Z',
                'dry_run' => false,
                'provider_selection' => [
                    'primary' => 'claude_codex',
                    'model' => 'council',
                    'fallbacks' => [],
                ],
            ],
        ];

        $this->assertNull($guard->violationForReceipt($receipt, runtimeProvider: 'claude_cli'));
        $this->assertNull($guard->violationForReceipt($receipt, runtimeProvider: 'codex_cli'));

        $scoutReceipt = $receipt;
        $scoutReceipt['receipt_v2']['provider_selection']['primary'] = 'codex_cli';
        $scoutReceipt['receipt_v2']['provider_selection']['model'] = 'gpt-5.5';

        $this->assertNull($guard->violationForReceipt($scoutReceipt, runtimeProvider: 'gemini_cli', runtimeModel: 'gemini-2.5-pro', runtimeStage: 'context_scout'));
    }

    public function test_prefers_metadata_receipt_over_payload_receipt(): void
    {
        $job = new AiJob;
        $job->metadata = [
            'decision_receipt' => [
                'receipt_v2' => [
                    'receipt_id' => 'rcpt_metadata',
                ],
            ],
        ];
        $job->payload = [
            'decision_receipt' => [
                'receipt_v2' => [
                    'receipt_id' => 'rcpt_payload',
                ],
            ],
        ];

        $this->assertSame(
            'rcpt_metadata',
            data_get((new DecisionReceiptRuntimeGuard)->receiptForJob($job), 'receipt_v2.receipt_id'),
        );
    }
}
