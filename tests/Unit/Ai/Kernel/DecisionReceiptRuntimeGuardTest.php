<?php

namespace Tests\Unit\Ai\Kernel;

use App\Models\AiJob;
use App\Services\Ai\Kernel\Decision\DecisionReceipt;
use App\Services\Ai\Kernel\Decision\DecisionReceiptIssuer;
use App\Services\Ai\Kernel\Decision\DecisionReceiptRuntimeGuard;
use App\Services\Ai\Kernel\Envelope\OperationEnvelopeFactory;
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

    public function test_blocks_a_cryptographically_valid_v3_only_receipt_during_expand(): void
    {
        CarbonImmutable::setTestNow(CarbonImmutable::parse('2026-07-24T12:00:00Z'));

        $violation = (new DecisionReceiptRuntimeGuard)->violationForReceipt([
            'receipt_v3' => $this->validV3Receipt(),
        ]);

        $this->assertSame('decision_receipt_v3_non_authoritative', $violation?->errorCode);
        $this->assertSame('atlas.decide.v3', $violation?->schemaVersion);
    }

    public function test_canary_fails_closed_when_receipt_v3_is_present_but_not_an_array(): void
    {
        $guard = new DecisionReceiptRuntimeGuard;

        foreach ([null, 'scalar-v3', 42, true] as $malformed) {
            $violation = $guard->violationForReceipt([
                'receipt_v3' => $malformed,
            ]);
            $this->assertSame('decision_receipt_v3_invalid', $violation?->errorCode, 'malformed='.var_export($malformed, true));
        }
    }

    public function test_blocks_v3_only_receipt_when_a_bound_authority_field_is_tampered(): void
    {
        $receipt = $this->validV3Receipt();
        $receipt['authority']['effect']['class'] = 'different_effect';

        $violation = (new DecisionReceiptRuntimeGuard)->violationForReceipt(['receipt_v3' => $receipt]);

        $this->assertSame('decision_receipt_v3_hash_mismatch', $violation?->errorCode);
    }

    public function test_fails_closed_for_a_malformed_v3_authority_even_if_the_envelope_is_rehashed(): void
    {
        $receipt = $this->validV3Receipt();
        unset($receipt['authority']['nonce']);
        $receipt['receipt_hash'] = $this->independentV3Hash($receipt);

        $violation = (new DecisionReceiptRuntimeGuard)->violationForReceipt(['receipt_v3' => $receipt]);

        $this->assertSame('decision_receipt_v3_invalid', $violation?->errorCode);
    }

    public function test_shadow_vetoes_when_co_present_v3_is_tampered(): void
    {
        CarbonImmutable::setTestNow(CarbonImmutable::parse('2026-05-05T12:00:00Z'));
        $v3 = $this->validV3Receipt();
        $v3['authority']['budget']['max_effects'] = 2;

        $violation = (new DecisionReceiptRuntimeGuard)->violationForReceipt([
            'receipt_v2' => $this->issuedReceipt(),
            'receipt_v3' => $v3,
        ]);

        $this->assertSame('decision_receipt_v2_v3_shadow_contradiction', $violation?->errorCode);
    }

    public function test_shadow_allows_aligned_v2_and_v3_pair(): void
    {
        CarbonImmutable::setTestNow(CarbonImmutable::parse('2026-05-05T12:00:00Z'));
        $v2 = $this->issuedReceipt([
            'provider_selection' => [
                'primary' => 'codex_cli',
                'model' => 'gpt-5.5',
                'fallbacks' => [],
            ],
        ]);
        $v3 = $this->validV3Receipt();
        $v3['receipt_id'] = $v2['receipt_id'];
        $v3['envelope_id'] = $v2['envelope_id'];
        $v3['dry_run'] = $v2['dry_run'];
        $v3['domain'] = $v2['domain'];
        $v3['flow'] = $v2['flow'];
        $v3['provider_selection'] = $v2['provider_selection'];
        $v3['receipt_hash'] = $this->independentV3Hash($v3);

        $violation = (new DecisionReceiptRuntimeGuard)->violationForReceipt([
            'receipt_v2' => $v2,
            'receipt_v3' => $v3,
        ], runtimeProvider: 'codex_cli', runtimeModel: 'gpt-5.5');

        $this->assertNull($violation);
    }

    public function test_shadow_vetoes_when_shared_identity_diverges(): void
    {
        CarbonImmutable::setTestNow(CarbonImmutable::parse('2026-05-05T12:00:00Z'));
        $v2 = $this->issuedReceipt();
        $v3 = $this->validV3Receipt();
        $v3['receipt_id'] = 'different-receipt-id';
        $v3['envelope_id'] = $v2['envelope_id'];
        $v3['dry_run'] = $v2['dry_run'];
        $v3['domain'] = $v2['domain'];
        $v3['flow'] = $v2['flow'];
        $v3['receipt_hash'] = $this->independentV3Hash($v3);

        $violation = (new DecisionReceiptRuntimeGuard)->violationForReceipt([
            'receipt_v2' => $v2,
            'receipt_v3' => $v3,
        ]);

        $this->assertSame('decision_receipt_v2_v3_shadow_contradiction', $violation?->errorCode);
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

    public function test_accepts_issued_receipt_with_matching_hashes(): void
    {
        CarbonImmutable::setTestNow(CarbonImmutable::parse('2026-05-05T12:00:00Z'));

        $receipt = $this->issuedReceipt([
            'provider_selection' => [
                'primary' => 'codex_cli',
                'model' => 'gpt-5.5',
                'fallbacks' => [],
            ],
        ]);

        $violation = (new DecisionReceiptRuntimeGuard)->violationForReceipt(
            ['receipt_v2' => $receipt],
            runtimeProvider: 'codex_cli',
            runtimeModel: 'gpt-5.5',
        );

        $this->assertNull($violation);
    }

    public function test_blocks_issued_receipt_when_signed_payload_is_tampered(): void
    {
        CarbonImmutable::setTestNow(CarbonImmutable::parse('2026-05-05T12:00:00Z'));

        $receipt = $this->issuedReceipt([
            'provider_selection' => [
                'primary' => 'codex_cli',
                'model' => 'gpt-5.5',
                'fallbacks' => [],
            ],
        ]);
        $receipt['provider_selection']['primary'] = 'claude_cli';

        $violation = (new DecisionReceiptRuntimeGuard)->violationForReceipt(
            ['receipt_v2' => $receipt],
            runtimeProvider: 'claude_cli',
            runtimeModel: 'gpt-5.5',
        );

        $this->assertSame('decision_receipt_hash_mismatch', $violation?->errorCode);
        $this->assertSame($receipt['receipt_id'], $violation?->receiptId);
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

    /**
     * @param  array<string,mixed>  $decision
     * @return array<string,mixed>
     */
    private function issuedReceipt(array $decision = []): array
    {
        $envelope = app(OperationEnvelopeFactory::class)->create([
            'text' => 'execute tarefa protegida por receipt',
        ]);

        return app(DecisionReceiptIssuer::class)->issue($envelope, array_replace_recursive([
            'receipt_id' => 'rcpt_hash_guard',
            'issued_at' => '2026-05-05T12:00:00Z',
            'expires_at' => '2026-05-05T12:01:00Z',
            'domain' => 'programming',
            'flow' => 'programming.dev',
            'risk' => 'medium',
            'provider_selection' => [
                'primary' => 'codex_cli',
                'model' => 'gpt-5.5',
                'fallbacks' => [],
            ],
            'required_evidence' => ['summary'],
            'repair_policy' => ['enabled' => false, 'max_attempts' => 0],
        ], $decision))->toArray();
    }

    /** @return array<string,mixed> */
    private function validV3Receipt(): array
    {
        $receipt = [
            'receipt_id' => 'rcpt_v3_expand_only',
            'envelope_id' => 'env_v3_expand_only',
            'schema_version' => 'atlas.decide.v3',
            'issued_at' => '2026-07-24T11:59:00Z',
            'expires_at' => '2026-07-24T12:01:00Z',
            'dry_run' => false,
            'signed_by' => 'atlas.decide.v3',
            'domain' => 'programming',
            'flow' => 'programming.dev',
            'risk' => 'high',
            'provider_selection' => ['primary' => 'codex_cli', 'model' => 'gpt-5.5', 'fallbacks' => []],
            'budgets' => ['provider_calls' => 1],
            'required_gates' => ['authority'],
            'required_evidence' => ['receipt'],
            'repair_policy' => ['enabled' => false, 'max_attempts' => 0],
            'inputs_hash' => str_repeat('b', 64),
            'parent_receipt_id' => null,
            'chain_hash' => str_repeat('c', 64),
            'authority' => [
                'authority_id' => 'mandate-01',
                'issuer_key_id' => 'key-01',
                'lifecycle' => ['status' => 'active', 'revision' => 1],
                'audience' => ['tenant_id' => 'tenant-01', 'principal_id' => 'principal-01'],
                'scope' => ['workspace_id' => 'workspace-01', 'modes' => ['dev'], 'capability' => 'programming.dev'],
                'effect' => ['class' => 'provider_tool_sandbox_mutation', 'allowed' => true],
                'budget' => ['budget_id' => 'budget-01', 'max_effects' => 1],
                'nonce' => 'nonce-01',
                'revocation_head' => str_repeat('a', 64),
                'separation_of_duties' => [
                    'issuer_principal_id' => 'issuer-01',
                    'executor_principal_id' => 'executor-01',
                ],
            ],
        ];
        $receipt['receipt_hash'] = $this->independentV3Hash($receipt);

        return $receipt;
    }

    /** @param array<string,mixed> $receipt */
    private function independentV3Hash(array $receipt): string
    {
        unset($receipt['receipt_hash']);

        return hash('sha256', json_encode(
            $this->independentCanonicalize($receipt),
            JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE | JSON_THROW_ON_ERROR,
        ));
    }

    private function independentCanonicalize(mixed $value): mixed
    {
        if (! is_array($value)) {
            return $value;
        }

        $isList = array_is_list($value);
        if (! $isList) {
            ksort($value);
        }
        foreach ($value as $key => $item) {
            $value[$key] = $this->independentCanonicalize($item);
        }

        return $isList ? array_values($value) : $value;
    }
}
