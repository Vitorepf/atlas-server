<?php

namespace App\Providers;

use App\Services\Ai\Programming\Governance\Gates\ProgrammingCartographyGate;
use App\Services\Ai\Programming\Governance\Gates\ProgrammingCodeIntelligenceGate;
use App\Services\Ai\Programming\Governance\Gates\ProgrammingCompletionGate;
use App\Services\Ai\Programming\Governance\Gates\ProgrammingDocsHealthGate;
use App\Services\Ai\Programming\Governance\Gates\ProgrammingEvidenceGate;
use App\Services\Ai\Programming\Governance\Gates\ProgrammingPlacementGate;
use App\Services\Ai\Programming\Governance\Gates\ProgrammingScopeGuardGate;
use App\Services\Ai\Programming\Governance\Gates\ProgrammingSpecBeforeCodeGate;
use App\Services\Ai\Programming\Governance\ProgrammingGateRunner;
use Illuminate\Support\ServiceProvider;

/**
 * Wires up the Atlas Programming Governance gate registry.
 *
 * @see docs/engineering-knowledge-base/atlas-programming-governance-system.md
 */
class ProgrammingGovernanceServiceProvider extends ServiceProvider
{
    /** @var list<class-string> */
    private const GATE_CLASSES = [
        ProgrammingPlacementGate::class,
        ProgrammingCodeIntelligenceGate::class,
        ProgrammingSpecBeforeCodeGate::class,
        ProgrammingEvidenceGate::class,
        ProgrammingScopeGuardGate::class,
        ProgrammingDocsHealthGate::class,
        ProgrammingCartographyGate::class,
        ProgrammingCompletionGate::class,
    ];

    public function register(): void
    {
        foreach (self::GATE_CLASSES as $gateClass) {
            $this->app->singleton($gateClass);
            $this->app->tag($gateClass, 'programming.governance.gate');
        }

        $this->app->singleton(ProgrammingGateRunner::class, function ($app): ProgrammingGateRunner {
            $gates = [];
            foreach (self::GATE_CLASSES as $gateClass) {
                if (class_exists($gateClass)) {
                    $gates[] = $app->make($gateClass);
                }
            }

            return new ProgrammingGateRunner($gates);
        });
    }
}
