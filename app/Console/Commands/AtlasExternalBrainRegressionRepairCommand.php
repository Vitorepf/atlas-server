<?php

declare(strict_types=1);

namespace App\Console\Commands;

use App\Services\Ai\SelfConstruction\ExternalBrain\AtlasExternalBrainAmplifierRegressionCaseMiner;
use App\Services\Ai\SelfConstruction\ExternalBrain\AtlasExternalBrainAutonomyIncidentPostmortemMiner;
use App\Services\Ai\SelfConstruction\ExternalBrain\AtlasExternalBrainCrossProjectPortabilityPlanner;
use App\Services\Ai\SelfConstruction\ExternalBrain\AtlasExternalBrainGateRegressionResponsePlanner;
use App\Services\Ai\SelfConstruction\ExternalBrain\AtlasExternalBrainGiveBackRootCauseMiner;
use App\Services\Ai\SelfConstruction\ExternalBrain\AtlasExternalBrainGiveBackToQueueRepairPlanner;
use App\Services\Ai\SelfConstruction\ExternalBrain\AtlasExternalBrainRegressionRepairTaskSynthesizer;
use Illuminate\Console\Command;
use App\Console\Concerns\EmitsCanonicalJson;

/**
 * Read-only operator entry point turning gate regressions and repeated
 * give-backs into repairable macro-packets: ranks safe queue repairs ahead
 * of blind retries, and synthesizes runnable repair specs so poisoned
 * backlog unblocks without operator dependence in steady state.
 *
 * Combines {@see AtlasExternalBrainGateRegressionResponsePlanner} (audit → repair-first
 * verdict), {@see AtlasExternalBrainGiveBackRootCauseMiner} (give_back events → root
 * cause clusters), {@see AtlasExternalBrainGiveBackToQueueRepairPlanner} (give_back
 * events → ranked repair candidates) and {@see AtlasExternalBrainRegressionRepairTaskSynthesizer}
 * (diagnostics → runnable repair task specs).
 *
 * Never mutates files, calls providers, or runs git — read-only reporting only.
 *
 * Input: a single JSON file (--input=PATH) with keys:
 *   { audit:{...}, give_backs:list<...>, claimable_per_active_worker?:float, diagnostics:list<...>,
 *     existing_queued_targets?:list<string> }
 * Missing/absent sections default to empty/defaults and produce a clean report.
 */
final class AtlasExternalBrainRegressionRepairCommand extends Command
{
    use EmitsCanonicalJson;

    /** @var string */
    protected $signature = 'atlas:external-brain:regression-repair
        {--input= : Path to a JSON file with audit, give_backs and diagnostics sections}';

    /** @var string */
    protected $description = 'Read-only gate-regression + give-back root-cause + queue-repair-ranking + repair-task-synthesis report.';

    public function handle(
        AtlasExternalBrainGateRegressionResponsePlanner $regressionPlanner,
        AtlasExternalBrainGiveBackRootCauseMiner $rootCauseMiner,
        AtlasExternalBrainGiveBackToQueueRepairPlanner $repairPlanner,
        AtlasExternalBrainRegressionRepairTaskSynthesizer $synthesizer,
        AtlasExternalBrainAmplifierRegressionCaseMiner $amplifierRegressionCaseMiner,
        AtlasExternalBrainAutonomyIncidentPostmortemMiner $incidentPostmortemMiner,
        AtlasExternalBrainCrossProjectPortabilityPlanner $portabilityPlanner,
    ): int {
        $inputPath = trim((string) $this->option('input'));
        if ($inputPath === '' || ! is_file($inputPath)) {
            $this->error('--input=<path> required and must exist');

            return self::FAILURE;
        }

        $decoded = json_decode((string) file_get_contents($inputPath), true);
        if (! is_array($decoded)) {
            $this->error('invalid input JSON');

            return self::FAILURE;
        }

        $audit = is_array($decoded['audit'] ?? null) ? $decoded['audit'] : [];
        $giveBacks = is_array($decoded['give_backs'] ?? null) ? $decoded['give_backs'] : [];
        $claimablePerActiveWorker = $decoded['claimable_per_active_worker'] ?? null;
        $diagnostics = is_array($decoded['diagnostics'] ?? null) ? $decoded['diagnostics'] : [];
        $existingQueuedTargets = array_values(array_map('strval', (array) ($decoded['existing_queued_targets'] ?? [])));
        $amplifierFailures = is_array($decoded['amplifier_failures'] ?? null) ? $decoded['amplifier_failures'] : [];
        $autonomyIncidents = is_array($decoded['autonomy_incidents'] ?? null) ? $decoded['autonomy_incidents'] : [];

        $regression = $regressionPlanner->plan(['audit' => $audit]);
        $rootCauses = $rootCauseMiner->mine($giveBacks);
        $amplifierRegressionCases = $amplifierRegressionCaseMiner->mine(['failure_records' => $amplifierFailures]);
        $incidentPostmortems = array_map(
            static fn ($incident): array => $incidentPostmortemMiner->mine((array) $incident),
            $autonomyIncidents,
        );
        $repairRanking = $repairPlanner->plan(array_filter([
            'give_backs' => $giveBacks,
            'claimable_per_active_worker' => $claimablePerActiveWorker,
        ], static fn ($v) => $v !== null));
        $synthesis = $synthesizer->synthesize([
            'diagnostics' => $diagnostics,
            'existing_queued_targets' => $existingQueuedTargets,
        ]);

        $blockedOrigination = (bool) $regression['blocked_origination'];
        $hasPoisonPackets = $rootCauses['poison_packets'] !== [];
        $hasRunnableRepairSpecs = $synthesis['repair_specs'] !== [];

        $payload = [
            'status' => 'ok',
            'gate_regression' => $regression,
            'give_back_root_causes' => $rootCauses,
            'amplifier_regression_cases' => $amplifierRegressionCases,
            'autonomy_incident_postmortems' => $incidentPostmortems,
            'queue_repair_ranking' => $repairRanking,
            'repair_synthesis' => $synthesis,
            'blocked_origination' => $blockedOrigination,
            'has_poison_packets' => $hasPoisonPackets,
            'has_runnable_repair_specs' => $hasRunnableRepairSpecs,
        ];

        // Optional cross-project portability plan: a repair-repository can run outside Atlas only
        // once docs sync, task namespace, worker routing, evidence gates and workspace isolation
        // are all real — never inferred from repair success alone. Distinct from the repair
        // sections above, so it only runs when the caller explicitly supplies a
        // cross_project_portability section.
        if (is_array($decoded['cross_project_portability'] ?? null)) {
            $payload['cross_project_portability'] = $portabilityPlanner->plan($decoded['cross_project_portability']);
        }

        $this->line($this->encode($payload));

        return self::SUCCESS;
    }
}
