<?php

namespace App\Console\Commands;

use App\Services\Ai\AgenticEngineeringOs\Maturity\AtlasDepartmentLevelClassifier;
use App\Services\Ai\AgenticEngineeringOs\Maturity\AtlasClaimDefinitionOfDoneValidator;
use App\Services\Ai\AgenticEngineeringOs\Maturity\AtlasCognitiveImmuneInputClassifier;
use App\Services\Ai\AgenticEngineeringOs\Maturity\AtlasDepartmentMaturityBandClassifier;
use App\Services\Ai\AgenticEngineeringOs\Maturity\AtlasDepartmentMaturityService;
use App\Services\Ai\AgenticEngineeringOs\Maturity\AtlasDepartmentPromotionEligibilityEvaluator;
use App\Services\Ai\AgenticEngineeringOs\Maturity\AtlasDepartmentQualityBarLevelClassifier;
use App\Services\Ai\AgenticEngineeringOs\Maturity\AtlasDocMaturityClassifier;
use App\Services\Ai\AgenticEngineeringOs\Maturity\AtlasGateSignalEvaluator;
use App\Services\Ai\AgenticEngineeringOs\Maturity\AtlasDepartmentQualityBarService;
use App\Services\Ai\AgenticEngineeringOs\Maturity\AtlasRepairLoopGuard;
use App\Services\Ai\AgenticEngineeringOs\Maturity\AtlasVetoPropagationWatchdog;
use Illuminate\Console\Command;

/**
 * Runtime surface for the AAEOS department maturity + quality-bar matrices —
 * the command those canonical docs name in their next_actions. Exposes the
 * existing AtlasDepartmentMaturityService and AtlasDepartmentQualityBarService
 * (per-department L0..L7 maturity and numeric SLA/SLO quality bar) so the
 * matrices stop being doc-only and become a queryable runtime read-model.
 *
 * @see docs/engineering-knowledge-base/atlas-aaeos-department-maturity-matrix.md
 * @see docs/engineering-knowledge-base/atlas-aaeos-department-quality-bar-matrix.md
 */
class AtlasAaeosDepartmentStatusCommand extends Command
{
    protected $signature = 'atlas:aeos:department-status
        {--quality-bar : Include the quality-bar breach signal emission}
        {--claim-file= : Path to a JSON completion claim to validate against the Definition of Done}
        {--repair-iteration= : Current repair-loop iteration to guard (max-3 contract before escalation)}
        {--veto-events= : Path to a JSON list of veto events to replay through the veto-propagation watchdog}
        {--doc-maturity= : Path to a JSON sections map to classify DOC L0..L4 maturity}
        {--phase-gates= : Path to a JSON phase_outputs map (intent/spec_pack/task_pack) to evaluate runbook gate signals}
        {--cognitive-immune-input= : Path to a JSON {text, metadata} capture to classify through the cognitive immune input router}
        {--json : Print machine-readable JSON}';

    protected $description = 'Show AAEOS per-department maturity (L0..L7) and numeric quality bar. [was atlas:aaeos:*; TRI-HYGIENE rename]';

    public function handle(
        AtlasDepartmentMaturityService $maturity,
        AtlasDepartmentQualityBarService $qualityBar,
        AtlasDepartmentMaturityBandClassifier $bandClassifier,
        AtlasDepartmentPromotionEligibilityEvaluator $promotionEligibility,
        AtlasClaimDefinitionOfDoneValidator $claimValidator,
        AtlasDepartmentLevelClassifier $levelClassifier,
        AtlasRepairLoopGuard $repairLoopGuard,
        AtlasDepartmentQualityBarLevelClassifier $qualityBarLevelClassifier,
        AtlasVetoPropagationWatchdog $vetoPropagationWatchdog,
        AtlasDocMaturityClassifier $docMaturityClassifier,
        AtlasGateSignalEvaluator $gateSignalEvaluator,
        AtlasCognitiveImmuneInputClassifier $cognitiveImmuneInputClassifier,
    ): int {
        $qualityBarResult = $qualityBar->qualityBar();
        $maturityResult = $maturity->maturity();

        $payload = [
            'schema_version' => 'atlas.aaeos.department_status.v1',
            'maturity' => $maturityResult,
            'quality_bar' => $qualityBarResult,
            'maturity_band_classification' => $this->classifyQualityBarBands($bandClassifier, $qualityBarResult),
            'promotion_eligibility' => $this->evaluatePromotionEligibility($promotionEligibility, $maturityResult, $qualityBarResult),
            'department_level_classification' => $this->classifyDepartmentLevels($levelClassifier, $qualityBarResult),
            'quality_bar_level_classification' => $this->classifyQualityBarLevels($qualityBarLevelClassifier, $qualityBarResult),
        ];
        if ((bool) $this->option('quality-bar')) {
            $payload['quality_bar_signal'] = $qualityBar->emitSignal();
        }

        // Optional: a department status report may attach evidence-vs-narrative validation for a
        // completion claim, so promotion readouts stop resting on narrative-only self-reports.
        $claimFile = trim((string) $this->option('claim-file'));
        if ($claimFile !== '' && is_file($claimFile)) {
            $claim = json_decode((string) file_get_contents($claimFile), true);
            if (is_array($claim)) {
                $payload['claim_validation'] = $claimValidator->validate($claim);
            }
        }

        // Optional: guard the next repair-loop attempt against the max-3-iteration contract
        // (4th iteration auto-escalates to Architect + Operator).
        $repairIterationOption = $this->option('repair-iteration');
        if ($repairIterationOption !== null && trim((string) $repairIterationOption) !== '') {
            $payload['repair_loop_guard'] = $repairLoopGuard->guard((int) $repairIterationOption);
        }

        // Optional: replay a sequence of veto events through the veto-propagation watchdog,
        // so cross-department pause/lift state is queryable alongside the maturity/quality-bar
        // read-model instead of only being available as raw choreography evaluations.
        $vetoEventsFile = trim((string) $this->option('veto-events'));
        if ($vetoEventsFile !== '' && is_file($vetoEventsFile)) {
            $vetoEvents = json_decode((string) file_get_contents($vetoEventsFile), true);
            if (is_array($vetoEvents)) {
                $payload['veto_propagation'] = $vetoPropagationWatchdog->watch($vetoEvents);
            }
        }

        // Optional: classify the DOC L0..L4 maturity of a documented structural-parts map
        // (mother doc, contracts, runbook, matrix, quality bar, evidence, gates), so doc
        // maturity is queryable alongside the department maturity/quality-bar read-model.
        // runtime_ready is always false here — doc maturity is never runtime proof.
        $docMaturityFile = trim((string) $this->option('doc-maturity'));
        if ($docMaturityFile !== '' && is_file($docMaturityFile)) {
            $docMaturitySections = json_decode((string) file_get_contents($docMaturityFile), true);
            if (is_array($docMaturitySections)) {
                $payload['doc_maturity'] = $docMaturityClassifier->classify($docMaturitySections);
            }
        }

        // Optional: evaluate the AAEOS runbook phase gates (P1 intent clarity, P7 spec-pack
        // acceptance criteria, P8 task-pack atomicity) over a phase_outputs map, so gate pass/fail
        // is queryable alongside the maturity/quality-bar read-model instead of only living in the
        // runbook doc as a hard-pass description.
        $phaseGatesFile = trim((string) $this->option('phase-gates'));
        if ($phaseGatesFile !== '' && is_file($phaseGatesFile)) {
            $phaseOutputs = json_decode((string) file_get_contents($phaseGatesFile), true);
            if (is_array($phaseOutputs)) {
                $payload['phase_gates'] = $gateSignalEvaluator->evaluatePhaseGates($phaseOutputs);
            }
        }

        // Optional: route a raw capture through the cognitive immune input classifier, so
        // capture classification (destination/memory-eligible/embedding-allowed) is queryable
        // alongside the maturity/quality-bar read-model instead of only living behind AAEOS's
        // internal immune learning kernel.
        $cognitiveImmuneInputFile = trim((string) $this->option('cognitive-immune-input'));
        if ($cognitiveImmuneInputFile !== '' && is_file($cognitiveImmuneInputFile)) {
            $cognitiveImmuneInput = json_decode((string) file_get_contents($cognitiveImmuneInputFile), true);
            if (is_array($cognitiveImmuneInput) && is_string($cognitiveImmuneInput['text'] ?? null)) {
                $metadata = is_array($cognitiveImmuneInput['metadata'] ?? null) ? $cognitiveImmuneInput['metadata'] : [];
                $payload['cognitive_immune_classification'] = $cognitiveImmuneInputClassifier->classify($cognitiveImmuneInput['text'], $metadata);
            }
        }

        if ((bool) $this->option('json')) {
            $this->line(json_encode($payload, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE) ?: '{}');

            return self::SUCCESS;
        }

        $departments = (array) data_get($payload, 'maturity.departments', []);
        if ($departments !== []) {
            $this->table(
                ['department', 'level', 'next', 'blockers'],
                collect($departments)->map(fn (array $d): array => [
                    (string) ($d['department'] ?? $d['id'] ?? '-'),
                    (string) ($d['level'] ?? $d['maturity_level'] ?? '-'),
                    (string) ($d['next_level'] ?? '-'),
                    (string) count((array) ($d['blockers'] ?? [])),
                ])->all(),
            );
        } else {
            $this->components->twoColumnDetail('maturity schema', (string) data_get($payload, 'maturity.schema_version', '-'));
            $this->components->twoColumnDetail('quality-bar schema', (string) data_get($payload, 'quality_bar.schema_version', '-'));
        }

        return self::SUCCESS;
    }

    /**
     * Builds a single-band, single-metric ladder from the real quality-bar
     * threshold/current per department and classifies each department against
     * it, so the maturity band classifier runs on real data instead of a
     * fabricated ladder.
     *
     * @param  array<string,mixed>  $qualityBarResult
     * @return array<string,mixed>
     */
    private function classifyQualityBarBands(AtlasDepartmentMaturityBandClassifier $bandClassifier, array $qualityBarResult): array
    {
        $bandLadders = [];
        $snapshots = [];

        foreach ((array) ($qualityBarResult['departments'] ?? []) as $department) {
            $id = (string) ($department['department'] ?? '');
            if ($id === '') {
                continue;
            }

            $bandLadders[$id] = [
                [
                    'band' => 'meets_quality_bar',
                    'rank' => 0,
                    'thresholds' => [
                        ['metric' => 'quality_score', 'comparator' => '>=', 'value' => (float) ($department['threshold'] ?? 0.0)],
                    ],
                ],
            ];
            $snapshots[$id] = ['quality_score' => (float) ($department['current'] ?? 0.0)];
        }

        return $bandClassifier->classifyDepartments($bandLadders, $snapshots);
    }

    /**
     * Runs AtlasDepartmentLevelClassifier per department over the same real quality-bar
     * threshold/current data as classifyQualityBarBands, so the earned-level read (with capping
     * metric and missing-metric diagnostics) is available alongside the coarser pass/fail band.
     *
     * @param  array<string,mixed>  $qualityBarResult
     * @return array<string,mixed>
     */
    private function classifyDepartmentLevels(AtlasDepartmentLevelClassifier $levelClassifier, array $qualityBarResult): array
    {
        $results = [];

        foreach ((array) ($qualityBarResult['departments'] ?? []) as $department) {
            $id = (string) ($department['department'] ?? '');
            if ($id === '') {
                continue;
            }

            $bandLadder = [
                [
                    'level' => 'meets_quality_bar',
                    'thresholds' => [
                        ['metric' => 'quality_score', 'comparator' => '>=', 'value' => (float) ($department['threshold'] ?? 0.0)],
                    ],
                ],
            ];
            $metricsSnapshot = ['quality_score' => (float) ($department['current'] ?? 0.0)];

            $results[$id] = $levelClassifier->classify($id, $metricsSnapshot, $bandLadder);
        }

        return [
            'schema_version' => 'atlas.aaeos.department_level_classification_batch.v1',
            'departments' => $results,
        ];
    }

    /**
     * Runs AtlasDepartmentQualityBarLevelClassifier per department over the same real
     * quality-bar threshold/current data as classifyDepartmentLevels, so the cumulative-climb
     * band read (achieved_level/next_level/binding_breaches) is available alongside the other
     * two classifications.
     *
     * @param  array<string,mixed>  $qualityBarResult
     * @return array<string,mixed>
     */
    private function classifyQualityBarLevels(AtlasDepartmentQualityBarLevelClassifier $qualityBarLevelClassifier, array $qualityBarResult): array
    {
        $results = [];

        foreach ((array) ($qualityBarResult['departments'] ?? []) as $department) {
            $id = (string) ($department['department'] ?? '');
            if ($id === '') {
                continue;
            }

            $bandLadder = [
                [
                    'level' => 'meets_quality_bar',
                    'thresholds' => [
                        ['metric' => 'quality_score', 'comparator' => '>=', 'value' => (float) ($department['threshold'] ?? 0.0)],
                    ],
                ],
            ];
            $metricsSnapshot = ['quality_score' => (float) ($department['current'] ?? 0.0)];

            $results[$id] = $qualityBarLevelClassifier->classify($id, $metricsSnapshot, $bandLadder);
        }

        return [
            'schema_version' => 'atlas.aaeos.quality_bar_level_classification_batch.v1',
            'departments' => $results,
        ];
    }

    /**
     * Evaluates promotion eligibility per department from real maturity data
     * (current tier, blockers_to_next, last_evaluation) and, when a matching
     * quality-bar entry exists (case-insensitive department name), its real
     * current_score/target_threshold. Departments without a quality-bar match
     * fall back to the evaluator's own zero defaults rather than fabricated data.
     *
     * @param  array<string,mixed>  $maturityResult
     * @param  array<string,mixed>  $qualityBarResult
     * @return array<string,mixed>
     */
    private function evaluatePromotionEligibility(
        AtlasDepartmentPromotionEligibilityEvaluator $promotionEligibility,
        array $maturityResult,
        array $qualityBarResult,
    ): array {
        $qualityByLowerName = [];
        foreach ((array) ($qualityBarResult['departments'] ?? []) as $qb) {
            $name = strtolower((string) ($qb['department'] ?? ''));
            if ($name !== '') {
                $qualityByLowerName[$name] = $qb;
            }
        }

        $results = [];
        foreach ((array) ($maturityResult['departments'] ?? []) as $department) {
            $id = (string) ($department['department'] ?? '');
            if ($id === '') {
                continue;
            }

            $matchedQualityBar = $qualityByLowerName[strtolower($id)] ?? [];

            $results[$id] = $promotionEligibility->evaluate(
                [
                    'current_tier' => $department['maturity_tier'] ?? 0,
                    'blockers_to_next' => $department['blockers_to_next'] ?? [],
                    'last_evaluation' => $department['last_evaluation'] ?? '',
                ],
                [
                    'current_score' => $matchedQualityBar['current'] ?? 0.0,
                    'target_threshold' => $matchedQualityBar['threshold'] ?? 0.0,
                ],
            );
        }

        return [
            'schema_version' => 'atlas.aaeos.department_promotion_eligibility_batch.v1',
            'departments' => $results,
        ];
    }
}
