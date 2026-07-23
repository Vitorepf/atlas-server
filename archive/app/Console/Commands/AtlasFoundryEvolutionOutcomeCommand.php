<?php

declare(strict_types=1);

namespace App\Console\Commands;

use App\Services\Ai\Foundry\Frontier\Armor\FrontierMetricRollbackGate;
use App\Services\Ai\Foundry\Frontier\Outcome\FileRoadmapStorePort;
use App\Services\Ai\Foundry\Frontier\Outcome\FoundryEvolutionOutcomeMaterializerService;
use App\Services\Ai\Foundry\Frontier\Outcome\RealGitRevertPort;
use App\Services\Ai\Foundry\Frontier\Outcome\RealMeasureCommandPort;
use Illuminate\Console\Command;

/**
 * Foundry AP-E · measured-or-reverted evolution outcome (I5/I9).
 *
 * Thin entrypoint over FoundryEvolutionOutcomeMaterializerService::materialize().
 * AP-E INVIOLABLE RULE: action=consolidate ONLY when the proposal-bound
 * measure_cmd ran for REAL and proved improvement within tolerance on the
 * canonical property. No real green => NEVER consolidate. Non-improvement =>
 * git REVERT via the PORT (git revert, NEVER reset --hard).
 *
 * The materializer depends on UNBOUND port interfaces (MeasureCommandPort,
 * GitRevertPort, RoadmapStorePort). Per the AP-C lesson, this command builds
 * the port-needing service EXPLICITLY with real ports — it never lets the
 * container autowire an unbound interface (which would crash). The real ports
 * BLOCK honestly today (no real merged AFEF-origin finding to measure): NO real
 * shell measure and NO real git revert is run from this CLI.
 */
class AtlasFoundryEvolutionOutcomeCommand extends Command
{
    protected $signature = 'atlas:foundry:evolution-outcome
        {--cycle-receipt= : Path to the merged autonomous_loop_cycle_receipt.v1 JSON}
        {--proposal= : Path to the gate-admitted evolution_proposal.v1 JSON}
        {--area=agentic_engineering_os : Area id}
        {--roadmap-dir= : Optional storage dir override for the roadmap/outcome JSONL}
        {--json : Emit JSON}';

    protected $description = 'AP-E: measure-or-revert an AFEF-origin merged finding; consolidate only on real green, else invoke the git-revert PORT (blocks honestly today).';

    public function handle(): int
    {
        $cycleReceipt = $this->loadJsonFile((string) $this->option('cycle-receipt'));
        $proposal = $this->loadJsonFile((string) $this->option('proposal'));

        if ($cycleReceipt === null || $proposal === null) {
            $this->error('Both --cycle-receipt and --proposal JSON files are required.');

            return self::FAILURE;
        }

        // AP-C lesson: construct the unbound-interface ports EXPLICITLY. Never
        // resolve FoundryEvolutionOutcomeMaterializerService from the container
        // (its MeasureCommandPort/GitRevertPort/RoadmapStorePort are unbound).
        $roadmapStore = new FileRoadmapStorePort();
        $dirOverride = (string) $this->option('roadmap-dir');
        if ($dirOverride !== '') {
            $roadmapStore->setStorageDir($dirOverride);
        }

        $materializer = new FoundryEvolutionOutcomeMaterializerService(
            new FrontierMetricRollbackGate(),
            new RealMeasureCommandPort(),
            new RealGitRevertPort(),
            $roadmapStore,
        );
        if ($dirOverride !== '') {
            $materializer->setOutcomesStorageDirForTesting($dirOverride);
        }

        $result = $materializer->materialize($cycleReceipt, $proposal, (string) $this->option('area'));

        return $this->render($result);
    }

    /**
     * @return array<string,mixed>|null
     */
    private function loadJsonFile(string $path): ?array
    {
        if ($path === '' || ! is_file($path)) {
            return null;
        }
        $decoded = json_decode((string) file_get_contents($path), true);

        return is_array($decoded) ? $decoded : null;
    }

    /**
     * @param  array<string,mixed>  $result
     */
    private function render(array $result): int
    {
        $status = (string) ($result['status'] ?? 'unknown');

        if ((bool) $this->option('json')) {
            $this->line(json_encode($result, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE) ?: '{}');
        } else {
            $outcome = (array) ($result['outcome'] ?? []);
            $roadmap = (array) ($result['roadmap_after'] ?? []);
            $this->components->twoColumnDetail('Outcome status', $status);
            $this->components->twoColumnDetail('Blocker', (string) ($result['blocker_reason'] ?? ''));
            $this->components->twoColumnDetail('Action', (string) ($outcome['action'] ?? ''));
            $this->components->twoColumnDetail('Improved', ($outcome['improved'] ?? false) ? 'yes' : 'no');
            $this->components->twoColumnDetail('Post value', $outcome['post_value'] === null ? '(null)' : (string) ($outcome['post_value'] ?? ''));
            $this->components->twoColumnDetail('Refuted by reality', ($outcome['refuted_by_reality'] ?? false) ? 'yes' : 'no');
            $this->components->twoColumnDetail('Revert commit hash', (string) ($result['revert_commit_hash'] ?? ($outcome['revert_commit_hash'] ?? '')));
            $this->components->twoColumnDetail('Outcome hash', (string) ($outcome['outcome_hash'] ?? ''));
            if (isset($roadmap['capabilities'])) {
                $this->components->twoColumnDetail('Roadmap capabilities', (string) count((array) $roadmap['capabilities']));
            }
        }

        return match ($status) {
            FoundryEvolutionOutcomeMaterializerService::STATUS_CONSOLIDATED,
            FoundryEvolutionOutcomeMaterializerService::STATUS_REVERTED => self::SUCCESS,
            default => self::FAILURE,
        };
    }
}
