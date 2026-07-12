<?php

declare(strict_types=1);

namespace Tests\Unit\Ai\Rivals;

use App\Services\Ai\Rivals\Core\RivalsClaimAuthority;
use InvalidArgumentException;
use PHPUnit\Framework\TestCase;

final class RivalsClaimAuthorityTest extends TestCase
{
    public function test_only_complete_adjudication_can_issue_a_scoped_claim(): void
    {
        $claim = (new RivalsClaimAuthority)->issue($this->evidence());

        self::assertSame('issued', $claim['status']);
        self::assertSame('world_10x_quality_proven', $claim['level']);
        self::assertTrue($claim['claim_eligible']);
        self::assertSame('atlas-bench', $claim['scope']['suite']);
    }

    public function test_missing_conjunctive_evidence_is_rejected(): void
    {
        $this->expectException(InvalidArgumentException::class);
        (new RivalsClaimAuthority)->issue(array_replace($this->evidence(), ['adjudication_status' => 'blocked']));
    }

    public function test_claim_expiry_and_revoke_never_delete_original_identity(): void
    {
        $authority = new RivalsClaimAuthority;
        $claim = $authority->issue($this->evidence());
        $expired = $authority->expire($claim, '90d_elapsed');
        $revoked = $authority->revoke($claim, 'late_adverse_outcome');

        self::assertSame('expired', $expired['status']);
        self::assertSame('revoked', $revoked['status']);
        self::assertSame($claim['claim_id'], $expired['claim_id']);
        self::assertSame($claim['claim_id'], $revoked['claim_id']);
        self::assertFalse($expired['claim_eligible']);
        self::assertFalse($revoked['claim_eligible']);
    }

    /** @return array<string,mixed> */
    private function evidence(): array
    {
        return [
            'adjudication_status' => 'passed', 'claim_level' => 'world_10x_quality_proven',
            'scope' => ['suite' => 'atlas-bench', 'mode' => 'atlas', 'risk' => 'R3', 'duration' => 'durable_task', 'cases' => ['c1','c2','c3']],
            'baseline_hash' => str_repeat('a', 64), 'evidence_pack_hash' => str_repeat('b', 64),
            'experiment_hash' => str_repeat('c', 64), 'effect' => 0.20, 'ci_low' => 0.08, 'ci_high' => 0.32,
            'exposure' => ['campaigns' => 3, 'attempts' => 300, 'distinct_cases' => 3],
            'issued_at' => '2026-07-12T00:00:00Z', 'expires_at' => '2026-10-10T00:00:00Z',
            'invalidators' => ['frontier_change', 'late_adverse_outcome'],
        ];
    }
}
