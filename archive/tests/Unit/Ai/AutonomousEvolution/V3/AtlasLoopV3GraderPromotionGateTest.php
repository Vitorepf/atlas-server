<?php

declare(strict_types=1);

namespace Tests\Unit\Ai\AutonomousEvolution\V3;

use App\Services\Ai\AutonomousEvolution\V3\AtlasLoopV3GraderPromotionGate;
use App\Services\Ai\AutonomousEvolution\V3\AtlasLoopV3GraderSpec;
use Closure;
use PHPUnit\Framework\TestCase;
use RuntimeException;

final class PromotionGateSpecPassingShell
{
    /** @param array<string,mixed> $claim @return array<string,mixed> */
    public function validateClaim(array $claim, string $repoRoot): array
    {
        return ['material' => false, 'reason' => 'shell'];
    }
}

class PromotionGateNonFinalShell
{
    /** @param array<string,mixed> $claim @return array<string,mixed> */
    public function validateClaim(array $claim, string $repoRoot): array
    {
        return ['material' => false, 'reason' => 'non_final'];
    }
}

final class AtlasLoopV3GraderPromotionGateTest extends TestCase
{
    public function test_spec_violating_candidate_is_refused_before_instantiation(): void
    {
        $called = false;
        $gate = $this->gate(static function () use (&$called): object {
            $called = true;

            return new class
            {
                /** @return array<string,mixed> */
                public function validateClaim(array $claim, string $repoRoot): array
                {
                    return ['material' => true, 'reason' => 'should_not_run'];
                }
            };
        });

        $result = $gate->evaluate(PromotionGateNonFinalShell::class, [$this->realClaim()], [$this->plantedClaim()], '/repo');

        $this->assertFalse($result['promote']);
        $this->assertFalse($result['spec_ok']);
        $this->assertStringStartsWith('spec_violation:', $result['reason']);
        $this->assertFalse($called, 'spec violations fail before the injected candidate factory is called');
    }

    public function test_promotes_when_candidate_admits_real_and_rejects_all_planted_false_claims(): void
    {
        $result = $this->gate(fn (): object => $this->candidate(real: true, planted: false))
            ->evaluate(PromotionGateSpecPassingShell::class, [$this->realClaim()], [$this->plantedClaim(), $this->plantedClaim('p2')], '/repo');

        $this->assertTrue($result['promote']);
        $this->assertTrue($result['spec_ok']);
        $this->assertSame('promoted', $result['reason']);
        $this->assertSame(1, $result['admits_real']);
        $this->assertSame(2, $result['rejects_planted']);
    }

    public function test_planted_false_leak_fails_closed(): void
    {
        $result = $this->gate(fn (): object => $this->candidate(real: true, planted: true))
            ->evaluate(PromotionGateSpecPassingShell::class, [$this->realClaim()], [$this->plantedClaim(), $this->plantedClaim('p2')], '/repo');

        $this->assertFalse($result['promote']);
        $this->assertSame('leaks_planted_false', $result['reason']);
        $this->assertSame(1, $result['admits_real']);
        $this->assertSame(0, $result['rejects_planted']);
    }

    public function test_zero_new_admissions_fails_closed(): void
    {
        $result = $this->gate(fn (): object => $this->candidate(real: false, planted: false))
            ->evaluate(PromotionGateSpecPassingShell::class, [$this->realClaim()], [$this->plantedClaim()], '/repo');

        $this->assertFalse($result['promote']);
        $this->assertSame('no_new_admission', $result['reason']);
        $this->assertSame(0, $result['admits_real']);
        $this->assertSame(0, $result['rejects_planted']);
    }

    public function test_candidate_throw_fails_closed(): void
    {
        $result = $this->gate(fn (): object => new class
        {
            /** @return array<string,mixed> */
            public function validateClaim(array $claim, string $repoRoot): array
            {
                throw new RuntimeException('boom');
            }
        })->evaluate(PromotionGateSpecPassingShell::class, [$this->realClaim()], [$this->plantedClaim()], '/repo');

        $this->assertFalse($result['promote']);
        $this->assertStringStartsWith('candidate_threw:boom', $result['reason']);
    }

    public function test_empty_planted_claims_fails_closed_no_leak_probe(): void
    {
        $result = $this->gate(fn (): object => $this->candidate(real: true, planted: false))
            ->evaluate(PromotionGateSpecPassingShell::class, [$this->realClaim()], [], '/repo');

        $this->assertFalse($result['promote']);
        $this->assertSame('no_leak_probe', $result['reason']);
    }

    public function test_empty_real_previously_rejected_claims_fail_closed(): void
    {
        $result = $this->gate(fn (): object => $this->candidate(real: true, planted: false))
            ->evaluate(PromotionGateSpecPassingShell::class, [], [$this->plantedClaim()], '/repo');

        $this->assertFalse($result['promote']);
        $this->assertSame('no_new_admission', $result['reason']);
        $this->assertSame(0, $result['admits_real']);
    }

    private function gate(Closure $instantiate): AtlasLoopV3GraderPromotionGate
    {
        return new AtlasLoopV3GraderPromotionGate(new AtlasLoopV3GraderSpec, $instantiate);
    }

    private function candidate(bool $real, bool $planted): object
    {
        return new class($real, $planted)
        {
            public function __construct(
                private readonly bool $real,
                private readonly bool $planted,
            ) {}

            /** @return array<string,mixed> */
            public function validateClaim(array $claim, string $repoRoot): array
            {
                return [
                    'material' => ($claim['kind'] ?? '') === 'real' ? $this->real : $this->planted,
                    'reason' => 'fixture',
                ];
            }
        };
    }

    /** @return array<string,mixed> */
    private function realClaim(string $id = 'r1'): array
    {
        return ['kind' => 'real', 'id' => $id];
    }

    /** @return array<string,mixed> */
    private function plantedClaim(string $id = 'p1'): array
    {
        return ['kind' => 'planted', 'id' => $id];
    }
}
