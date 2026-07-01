<?php

declare(strict_types=1);

namespace Tests\Unit\Ai\Aaeos\Generated;

use App\Services\Ai\Aaeos\Generated\AtlasSelfConstructionOsGapAuditService;
use PHPUnit\Framework\TestCase;

final class AtlasSelfConstructionOsGapAuditServiceTest extends TestCase
{
    private function service(): AtlasSelfConstructionOsGapAuditService
    {
        return new AtlasSelfConstructionOsGapAuditService;
    }

    public function test_maturity_gap_index_reports_not_yet_runtime_capable_count_and_next_build_slice(): void
    {
        $r = $this->service()->maturityGapIndex();

        self::assertSame(
            count(AtlasSelfConstructionOsGapAuditService::NOT_YET_RUNTIME_CAPABLE),
            $r['not_yet_runtime_capable_count'],
        );
        self::assertSame(4, $r['not_yet_runtime_capable_count']);
        self::assertSame(AtlasSelfConstructionOsGapAuditService::NEXT_BUILD_SLICE, $r['next_unblock_target']);
        self::assertSame(AtlasSelfConstructionOsGapAuditService::NEXT_BUILD_SLICE, $r['next_build_slice']);
    }

    public function test_contract_certification_dry_run_surfaces_counted_separately_from_observed(): void
    {
        $r = $this->service()->maturityGapIndex();

        self::assertSame(
            count(AtlasSelfConstructionOsGapAuditService::CONTRACT_CERT_DRYRUN_SURFACES),
            $r['contract_certification_dry_run_count'],
        );
        self::assertNotSame($r['contract_certification_dry_run_count'], $r['not_yet_runtime_capable_count']);
        self::assertSame(482, $r['observed_capability_count']);
    }

    public function test_claim_is_blocked_when_any_proof_part_missing(): void
    {
        $service = $this->service();

        $missingAll = $service->evaluateClaim('self_programming_is_enabled', []);
        self::assertFalse($missingAll['allowed']);

        $missingGate = $service->evaluateClaim('self_programming_is_enabled', [
            'signed_promotion_artifact' => 'artifact-1',
            'replay_diff_status' => 'passed',
        ]);
        self::assertFalse($missingGate['allowed']);

        $invalidReplay = $service->evaluateClaim('self_programming_is_enabled', [
            'signed_promotion_artifact' => 'artifact-1',
            'replay_diff_status' => 'regressed',
            'promotion_gate_status' => 'green',
        ]);
        self::assertFalse($invalidReplay['allowed']);
    }

    public function test_claim_is_allowed_only_when_all_three_proofs_present_and_valid(): void
    {
        $r = $this->service()->evaluateClaim('self_programming_is_enabled', [
            'signed_promotion_artifact' => 'artifact-1',
            'replay_diff_status' => 'passed',
            'promotion_gate_status' => 'green',
        ]);

        self::assertTrue($r['allowed']);
        self::assertSame([], $r['missing_proofs']);
        self::assertSame([], $r['invalid_proofs']);
    }
}
