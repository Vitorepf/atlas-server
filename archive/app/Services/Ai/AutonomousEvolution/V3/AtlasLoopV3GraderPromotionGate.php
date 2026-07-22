<?php

declare(strict_types=1);

namespace App\Services\Ai\AutonomousEvolution\V3;

use Closure;
use Throwable;

final class AtlasLoopV3GraderPromotionGate
{
    private Closure $instantiate;

    /**
     * @param  Closure(string):object|null  $instantiate
     */
    public function __construct(
        private readonly AtlasLoopV3GraderSpec $spec = new AtlasLoopV3GraderSpec,
        ?Closure $instantiate = null,
    ) {
        $this->instantiate = $instantiate ?? static fn (string $fqcn): object => app($fqcn);
    }

    /**
     * @param  list<array<string,mixed>>  $realClaimsPreviouslyRejected
     * @param  list<array<string,mixed>>  $plantedFalseClaims
     * @return array{promote:bool,reason:string,admits_real:int,rejects_planted:int,spec_ok:bool}
     */
    public function evaluate(
        string $candidateFqcn,
        array $realClaimsPreviouslyRejected,
        array $plantedFalseClaims,
        string $repoRoot,
    ): array {
        $specClass = $this->spec::class;
        $spec = $specClass::validate($candidateFqcn);
        if (! $spec['ok']) {
            $violation = (string) ($spec['violations'][0] ?? 'unknown');

            return $this->result(false, 'spec_violation:'.$violation, 0, 0, false);
        }

        try {
            $candidate = ($this->instantiate)($candidateFqcn);

            $admitsReal = 0;
            foreach ($realClaimsPreviouslyRejected as $claim) {
                if ($this->isMaterial($candidate->validateClaim($claim, $repoRoot))) {
                    $admitsReal++;
                }
            }

            if ($admitsReal === 0) {
                return $this->result(false, 'no_new_admission', 0, 0, true);
            }

            if ($plantedFalseClaims === []) {
                return $this->result(false, 'no_leak_probe', $admitsReal, 0, true);
            }

            $rejectsPlanted = 0;
            foreach ($plantedFalseClaims as $claim) {
                if (! $this->isMaterial($candidate->validateClaim($claim, $repoRoot))) {
                    $rejectsPlanted++;
                }
            }

            if ($rejectsPlanted !== count($plantedFalseClaims)) {
                return $this->result(false, 'leaks_planted_false', $admitsReal, $rejectsPlanted, true);
            }

            return $this->result(true, 'promoted', $admitsReal, $rejectsPlanted, true);
        } catch (Throwable $e) {
            return $this->result(false, 'candidate_threw:'.$e->getMessage(), 0, 0, true);
        }
    }

    private function isMaterial(mixed $verdict): bool
    {
        return is_array($verdict) && ($verdict['material'] ?? null) === true;
    }

    /**
     * @return array{promote:bool,reason:string,admits_real:int,rejects_planted:int,spec_ok:bool}
     */
    private function result(bool $promote, string $reason, int $admitsReal, int $rejectsPlanted, bool $specOk): array
    {
        return [
            'promote' => $promote,
            'reason' => $reason,
            'admits_real' => $admitsReal,
            'rejects_planted' => $rejectsPlanted,
            'spec_ok' => $specOk,
        ];
    }
}
