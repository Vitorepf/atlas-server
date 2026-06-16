<?php

declare(strict_types=1);

namespace App\Services\Ai\AutonomousEvolution\Parallel;

/**
 * Dispatches a bounded WAVE of scenario specs — the scenario-level analogue of
 * {@see LoopWorkerSpawnerContract} (which is task-level). Given the specs for one
 * parallel wave, it runs them in-flight together and returns one attempt array per
 * spec, ORDERED by the spec's ascending index so the explorer's fold stays
 * deterministic and byte-matches the serial scn-ordering.
 *
 * Typed against the contract (not the final concrete) so the flag-ON wave-fold unit
 * tests can inject a FAKE dispatcher returning canned attempts — mirroring the
 * existing LoopWorkerSpawnerContract pattern. The concrete dispatcher is `final`.
 */
interface ScenarioWaveDispatcherContract
{
    /**
     * Run a bounded wave of scenario specs in-flight together.
     *
     * @param  list<array{index:int,objective:string,strategy_text:string,strategy_key:string,base_workspace:string,acceptance:array<string,mixed>,surface_id:string,user_constraints:list<string>,surface_hints:array<string,mixed>,provider:string,keep_workspaces:bool,workspace_root:string,clone_mode:string}>  $specs
     * @return list<array<string,mixed>> one attempt array per spec, ordered by index ascending; same shape as
     *                                   AtlasEvolutionScenarioExplorer::runScenario returns (scenario_id,
     *                                   strategy_key, strategy, provider, loop_status, verdict, diff_size,
     *                                   diff_text, workspace, error, ...)
     */
    public function dispatch(array $specs): array;
}
