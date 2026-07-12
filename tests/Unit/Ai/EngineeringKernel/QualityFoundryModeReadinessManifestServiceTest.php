<?php

declare(strict_types=1);

namespace Tests\Unit\Ai\EngineeringKernel;

use App\Services\Ai\EngineeringKernel\QualityFoundry\QualityFoundryModeReadinessManifestService;
use Tests\TestCase;

final class QualityFoundryModeReadinessManifestServiceTest extends TestCase
{
    public function test_four_mode_manifest_is_ready_only_from_live_receipts(): void
    {
        $manifest = (new QualityFoundryModeReadinessManifestService)->build($this->input());

        self::assertSame('ready', $manifest['status']);
        self::assertTrue($manifest['completion_allowed']);
        self::assertSame(['kernel', 'dev', 'forge', 'autonomos'], $manifest['required_modes']);
        self::assertSame([], $manifest['blockers']);
        self::assertSame(4, $manifest['summary']['ready_modes']);
        self::assertFalse($manifest['claim_eligible']);
    }

    public function test_config_intent_or_missing_receipt_blocks_the_manifest(): void
    {
        $input = $this->input();
        $input['modes']['forge']['receipt_hashes'] = [];
        $input['modes']['autonomos']['source'] = 'config_intent';

        $manifest = (new QualityFoundryModeReadinessManifestService)->build($input);

        self::assertSame('blocked', $manifest['status']);
        self::assertFalse($manifest['completion_allowed']);
        self::assertContains('forge:live_receipts_missing', $manifest['blockers']);
        self::assertContains('autonomos:live_receipt_source_required', $manifest['blockers']);
        self::assertSame(2, $manifest['summary']['ready_modes']);
    }

    public function test_duplicate_provider_or_mutation_receipts_block_once_per_idempotency_key(): void
    {
        $input = $this->input();
        $input['idempotency']['provider_invocations'] = 2;
        $input['idempotency']['mutations'] = 2;

        $manifest = (new QualityFoundryModeReadinessManifestService)->build($input);

        self::assertSame('blocked', $manifest['status']);
        self::assertContains('provider_invocation_not_once', $manifest['blockers']);
        self::assertContains('mutation_not_once', $manifest['blockers']);
        self::assertFalse($manifest['shadow']['mutation_allowed']);
    }

    /** @return array<string,mixed> */
    private function input(): array
    {
        $receipt = static fn (string $mode): array => [
            'source' => 'live_receipt',
            'receipt_hashes' => [hash('sha256', 'receipt:'.$mode)],
            'kernel_routed' => true,
            'coverage_percent' => 100,
            'rollback_exercised' => true,
            'outcome_writer_active' => true,
        ];

        return [
            'modes' => [
                'kernel' => $receipt('kernel'),
                'dev' => $receipt('dev'),
                'forge' => $receipt('forge'),
                'autonomos' => $receipt('autonomos'),
            ],
            'mode_parity' => true,
            'idempotency' => [
                'provider_invocations' => 1,
                'mutations' => 1,
            ],
            'shadow' => [
                'replay_only' => true,
                'mutation_allowed' => false,
            ],
        ];
    }
}
