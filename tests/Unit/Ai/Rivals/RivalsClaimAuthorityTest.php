<?php

declare(strict_types=1);

namespace Tests\Unit\Ai\Rivals;

use App\Services\Ai\Kernel\Evidence\AtlasEvidenceLedger;
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

    public function test_claim_rejects_effect_outside_interval(): void
    {
        $this->expectException(InvalidArgumentException::class);
        (new RivalsClaimAuthority)->issue(array_replace($this->evidence(), ['effect' => 0.50]));
    }

    public function test_claim_rejects_expiry_beyond_ninety_days(): void
    {
        $this->expectException(InvalidArgumentException::class);
        (new RivalsClaimAuthority)->issue(array_replace($this->evidence(), ['expires_at' => '2026-12-31T00:00:00Z']));
    }

    public function test_claim_requires_content_addressed_evidence_refs(): void
    {
        $this->expectException(InvalidArgumentException::class);
        (new RivalsClaimAuthority)->issue(array_replace($this->evidence(), ['evidence_refs' => []]));
    }

    public function test_universal_scope_is_rejected(): void
    {
        $evidence = $this->evidence();
        $evidence['scope']['risk'] = '*';
        $this->expectException(InvalidArgumentException::class);
        (new RivalsClaimAuthority)->issue($evidence);
    }

    public function test_issue_requires_state_machine_completion(): void
    {
        $this->expectException(InvalidArgumentException::class);
        (new RivalsClaimAuthority)->issue(array_replace($this->evidence(), ['run_state' => 'planned']));
    }

    public function test_claim_requires_stack_unit_population_and_metric_weights(): void
    {
        $authority = new RivalsClaimAuthority;

        $noWeights = $this->evidence();
        unset($noWeights['metric_weights']);
        try {
            $authority->issue($noWeights);
            self::fail('missing metric_weights must be rejected');
        } catch (InvalidArgumentException) {
        }

        $noStack = $this->evidence();
        unset($noStack['scope']['stack']);
        $this->expectException(InvalidArgumentException::class);
        $authority->issue($noStack);
    }

    public function test_material_frontier_change_forces_revalidation(): void
    {
        $authority = new RivalsClaimAuthority;
        $claim = $authority->issue($this->evidence());

        self::assertTrue($authority->requiresRevalidation($claim, ['material_frontier_change']));
        self::assertTrue($authority->requiresRevalidation($claim, ['late_adverse_outcome'])); // a preregistered invalidator
        self::assertFalse($authority->requiresRevalidation($claim, ['unrelated_signal']));
    }

    public function test_issue_emits_evaluated_then_issued_canonical_events(): void
    {
        $events = [];
        $ledger = $this->createMock(AtlasEvidenceLedger::class);
        $ledger->method('record')->willReturnCallback(
            function ($type, array $payload) use (&$events) {
                $events[] = $payload['event_name'];

                return null;
            }
        );

        (new RivalsClaimAuthority)->issue($this->evidence(), $ledger);
        self::assertSame(['claim.evaluated', 'claim.issued'], $events);
    }

    public function test_revoke_emits_canonical_claim_revoked_event(): void
    {
        $events = [];
        $ledger = $this->createMock(AtlasEvidenceLedger::class);
        $ledger->method('record')->willReturnCallback(
            function ($type, array $payload) use (&$events) {
                $events[] = $payload['event_name'];

                return null;
            }
        );

        $authority = new RivalsClaimAuthority;
        $claim = $authority->issue($this->evidence());
        $authority->revoke($claim, 'late_adverse_outcome', $ledger);
        self::assertContains('claim.revoked', $events);
    }

    /** @return array<string,mixed> */
    private function evidence(): array
    {
        return [
            'adjudication_status' => 'passed', 'claim_level' => 'world_10x_quality_proven',
            'run_state' => 'bundled', 'metric_weights' => ['quality_loss' => 1.0, 'cost' => 0.0],
            'scope' => ['suite' => 'atlas-bench', 'mode' => 'atlas', 'risk' => 'R3', 'duration' => 'durable_task', 'stack' => 'php_laravel', 'unit_population' => 'atlas_bench_cases', 'cases' => ['c1', 'c2', 'c3']],
            'baseline_hash' => str_repeat('a', 64), 'evidence_pack_hash' => str_repeat('b', 64),
            'experiment_hash' => str_repeat('c', 64), 'effect' => 0.20, 'ci_low' => 0.08, 'ci_high' => 0.32,
            'exposure' => ['campaigns' => 3, 'attempts' => 300, 'distinct_cases' => 3],
            'issued_at' => '2026-07-12T00:00:00Z', 'expires_at' => '2026-10-10T00:00:00Z',
            'invalidators' => ['frontier_change', 'late_adverse_outcome'],
            'evidence_refs' => ['rivals://evidence-pack/b', 'ledger://experiment/c'],
        ];
    }
}
