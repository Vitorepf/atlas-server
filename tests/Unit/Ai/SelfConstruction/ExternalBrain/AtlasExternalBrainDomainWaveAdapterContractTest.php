<?php

declare(strict_types=1);

namespace Tests\Unit\Ai\SelfConstruction\ExternalBrain;

use App\Services\Ai\SelfConstruction\ExternalBrain\AtlasExternalBrainDomainWaveAdapterContract;
use PHPUnit\Framework\TestCase;

final class AtlasExternalBrainDomainWaveAdapterContractTest extends TestCase
{
    private function valid(array $overrides = []): array
    {
        return array_merge([
            'domain_id' => 'programming', 'profile_id' => 'programming.default', 'version' => 'wave-1.v1',
            'rollback_profile_version' => 'programming.default.v0', 'adapter_kind' => 'profile_delta', 'executor_fork' => false,
            'kernel_route_receipt' => 'receipt:kernel', 'provider_contract_receipt' => 'receipt:provider', 'tool_contract_receipt' => 'receipt:tool',
            'evidence_refs' => ['fixture://wave-1'],
        ], $overrides);
    }

    public function test_profile_delta_reuses_existing_contracts_and_is_accepted(): void
    {
        $result = (new AtlasExternalBrainDomainWaveAdapterContract)->validate($this->valid());

        self::assertTrue($result['accepted']);
        self::assertFalse($result['executor_fork_allowed']);
        self::assertFalse($result['mutates_claims_or_routes']);
        self::assertSame(64, strlen($result['contract_hash']));
    }

    public function test_domain_executor_fork_and_missing_receipts_are_blocked(): void
    {
        $result = (new AtlasExternalBrainDomainWaveAdapterContract)->validate($this->valid([
            'executor_fork' => true, 'tool_contract_receipt' => '', 'evidence_refs' => [],
        ]));

        self::assertFalse($result['accepted']);
        self::assertContains('domain_executor_fork_forbidden', $result['blockers']);
        self::assertContains('tool_contract_receipt_required', $result['blockers']);
        self::assertContains('wave_evidence_refs_required', $result['blockers']);
    }
}
