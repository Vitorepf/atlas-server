<?php

declare(strict_types=1);

namespace App\Services\Ai\AutonomousEvolution;

use App\Services\Ai\AutonomousEvolution\Verify\AtlasDeadCodeAnalyzer;
use App\Services\Ai\AutonomousEvolution\Verify\AtlasEngineeringHonestyGate;

/**
 * The DETERMINISTIC, PROVIDER-LESS dead-code work-type — the full mill→author→cert chain for one file with
 * ZERO provider calls, so it MILLS and CERTIFIES real value WITHOUT the provider (the hermes-free path to a
 * live certified delivery; fronts #3 + #6, around the declined 1-line hermes fix).
 *
 *   detect  — {@see AtlasDeadCodeAnalyzer}: which private members are provably dead (single-file-sound)?
 *   author  — {@see AtlasLoopDeadCodeRemover}: surgically delete them, no provider (fail-closed on any risk).
 *   cert    — {@see AtlasEngineeringHonestyGate::evaluateDeadCodeRemoval}: re-derives deadness from the FRESH
 *             original (never trusts the author), requires net-reduction + lint-clean + zero-dead-after +
 *             no-unflagged-removals + no-new-declarations. Deterministic; never trusts its inputs.
 *
 * It returns a CERTIFIED removal proposal or null (fail-closed) — nothing dead / removal refused / cert
 * refused all yield null, never a half-result. This is the standalone work-type the live
 * {@see Campaign\AtlasLoopCampaignSupervisor} will call as a provider-less supply+grind source (next slice,
 * flag-OFF). The "tests-still-green" backstop (revert-recheck) belongs to that full-loop wiring; this proves
 * the deterministic content chain certifies on its own.
 */
final class AtlasLoopDeterministicDeadCodeWorkType
{
    private readonly AtlasDeadCodeAnalyzer $analyzer;

    private readonly AtlasLoopDeadCodeRemover $remover;

    private readonly AtlasEngineeringHonestyGate $gate;

    public function __construct(
        ?AtlasDeadCodeAnalyzer $analyzer = null,
        ?AtlasLoopDeadCodeRemover $remover = null,
        ?AtlasEngineeringHonestyGate $gate = null,
    ) {
        $this->analyzer = $analyzer ?? new AtlasDeadCodeAnalyzer;
        $this->remover = $remover ?? new AtlasLoopDeadCodeRemover($this->analyzer);
        $this->gate = $gate ?? new AtlasEngineeringHonestyGate($this->analyzer);
    }

    /**
     * Deterministically produce a CERTIFIED dead-code-removal proposal for one file, or null (fail-closed).
     *
     * @return array{
     *     rel_path:string, original:string, proposed:string,
     *     dead_members:list<array{kind:string,name:string,line:int,class:string}>,
     *     removed:list<array{kind:string,name:string,line:int}>,
     *     certified:true, gate_reasons:list<string>, gate_report:array<string,mixed>,
     *     provider_used:false
     * }|null
     */
    public function produceCertifiedRemoval(string $repoRoot, string $relPath): ?array
    {
        $abs = rtrim($repoRoot, '/').'/'.ltrim($relPath, '/');

        $report = $this->analyzer->analyzeFile($abs);
        if (! ($report['parseable'] ?? false) || ($report['dead'] ?? []) === []) {
            return null; // nothing provably dead — fail closed
        }
        $deadMembers = $report['dead'];

        $removal = $this->remover->remove($abs, $deadMembers);
        if ($removal === null) {
            return null; // author fail-closed (multi-declaration / unresolved span)
        }

        $original = (string) @file_get_contents($abs);
        $proposed = $removal['content'];

        $verdict = $this->gate->evaluateDeadCodeRemoval($repoRoot, $relPath, $original, $proposed, $deadMembers);
        if (! (bool) ($verdict['certified'] ?? false)) {
            return null; // cert refused — never propose an uncertified removal
        }

        return [
            'rel_path' => $relPath,
            'original' => $original,
            'proposed' => $proposed,
            'dead_members' => $deadMembers,
            'removed' => $removal['removed'],
            'certified' => true,
            'gate_reasons' => (array) ($verdict['reasons'] ?? []),
            'gate_report' => (array) ($verdict['report'] ?? []),
            'provider_used' => false,
        ];
    }
}
