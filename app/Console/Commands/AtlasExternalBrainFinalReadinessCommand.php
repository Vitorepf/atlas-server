<?php

declare(strict_types=1);

namespace App\Console\Commands;

use App\Services\Ai\SelfConstruction\ExternalBrain\AtlasExternalBrainClosedLoopSafetyGateRunner;
use App\Services\Ai\SelfConstruction\ExternalBrain\AtlasExternalBrainFinal95GapBurnDownScheduler;
use App\Services\Ai\SelfConstruction\ExternalBrain\AtlasExternalBrainFinalCertificationGate;
use App\Services\Ai\SelfConstruction\ExternalBrain\AtlasExternalBrainFinalityEvidenceBundle;
use App\Services\Ai\SelfConstruction\ExternalBrain\AtlasExternalBrainFinalReadinessMap;
use Illuminate\Console\Command;
use App\Console\Concerns\EmitsCanonicalJson;

/**
 * Read-only final-95 readiness composer. Combines
 * {@see AtlasExternalBrainFinalReadinessMap} (per-area evidence honesty gate),
 * {@see AtlasExternalBrainFinal95GapBurnDownScheduler} (ordered closure
 * schedule for open gaps), {@see AtlasExternalBrainFinalCertificationGate}
 * (seven-dimension certification verdict) and
 * {@see AtlasExternalBrainFinalityEvidenceBundle} (per-dimension finality
 * blocker ranking + missing-evidence bundle) into one honest final-95
 * readiness verdict with blocker-ranked closure steps — so the brain stops
 * hand-waving about maturity and proves exactly what still blocks real
 * autonomous finality. overall_verdict is "final_95_ready" ONLY when all
 * four sub-verdicts independently agree; any one of them saying "not ready"
 * wins.
 *
 * Never enqueues, mutates the queue, or calls a provider. Exit code is
 * non-zero unless overall_verdict is "final_95_ready".
 *
 * Input: a single JSON file (--input=PATH) with keys:
 *   { area_evidence?:map<area,evidence_record>, gaps?:list<gap_report>,
 *     certification_evidence?:{...AtlasExternalBrainFinalCertificationGate::certify input},
 *     finality_evidence?:{...AtlasExternalBrainFinalityEvidenceBundle::assemble input} }
 */
final class AtlasExternalBrainFinalReadinessCommand extends Command
{
    use EmitsCanonicalJson;

    /** @var string */
    protected $signature = 'atlas:external-brain:final-readiness
        {--input= : Path to a JSON file with area_evidence, gaps, certification_evidence, finality_evidence}';

    /** @var string */
    protected $description = 'Read-only final-95 readiness composer (readiness map + gap burn-down + certification gate + finality evidence bundle), fail-closed on any sub-verdict.';

    public function handle(
        AtlasExternalBrainFinalReadinessMap $readinessMap,
        AtlasExternalBrainFinal95GapBurnDownScheduler $burnDownScheduler,
        AtlasExternalBrainFinalCertificationGate $certificationGate,
        AtlasExternalBrainFinalityEvidenceBundle $evidenceBundle,
        ?AtlasExternalBrainClosedLoopSafetyGateRunner $closedLoopSafetyGate = null,
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

        $areaEvidence = is_array($decoded['area_evidence'] ?? null) ? $decoded['area_evidence'] : [];
        $gaps = is_array($decoded['gaps'] ?? null) ? $decoded['gaps'] : [];
        $certificationEvidence = is_array($decoded['certification_evidence'] ?? null) ? $decoded['certification_evidence'] : [];
        $finalityEvidence = is_array($decoded['finality_evidence'] ?? null) ? $decoded['finality_evidence'] : [];
        $closedLoopSafetyInput = is_array($decoded['closed_loop_safety'] ?? null) ? $decoded['closed_loop_safety'] : [];

        $map = $readinessMap->map($areaEvidence);
        $burnDown = $burnDownScheduler->schedule($gaps);
        $certification = $certificationGate->certify($certificationEvidence);
        $finality = $evidenceBundle->assemble($finalityEvidence);

        $closedLoopSafety = $closedLoopSafetyGate?->run($closedLoopSafetyInput);

        $mapReady = $map['overall_status'] === AtlasExternalBrainFinalReadinessMap::OVERALL_FINAL_READY;
        $gapsClear = $burnDown['total_gaps'] === 0;
        $certificationReady = $certification['verdict'] === AtlasExternalBrainFinalCertificationGate::VERDICT_FINAL_95;
        $finalityReady = $finality['is_final'] === true;
        $overallReady = $mapReady && $gapsClear && $certificationReady && $finalityReady;

        $closureSteps = array_map(
            static fn (array $entry): array => [
                'source' => 'gap_burn_down',
                'target' => $entry['organ_id'],
                'action' => $entry['resolution_approach'],
                'next_proof' => $entry['cheapest_next_proof'],
                'priority_rank' => $entry['priority_rank'],
            ],
            $burnDown['burn_down_schedule'],
        );
        foreach ($certification['blockers'] as $blocker) {
            $closureSteps[] = [
                'source' => 'certification_gate',
                'target' => $blocker['dimension'],
                'action' => $blocker['action'],
                'next_proof' => $blocker['reason'],
                'priority_rank' => null,
            ];
        }
        foreach ($finality['blockers'] as $blocker) {
            $closureSteps[] = [
                'source' => 'finality_evidence_bundle',
                'target' => $blocker['dimension'],
                'action' => 'resolve_blocker:'.$blocker['blocker_type'],
                'next_proof' => 'blocker_type:'.$blocker['blocker_type'],
                'priority_rank' => null,
            ];
        }

        $payload = [
            'status' => 'ok',
            'overall_verdict' => $overallReady ? 'final_95_ready' : 'not_ready',
            'readiness_map' => [
                'overall_status' => $map['overall_status'],
                'blocking_areas' => $map['blocking_areas'],
                'final_readiness_percent' => $map['final_readiness_percent'],
                'missing_evidence_by_area' => $map['missing_evidence_by_area'],
                'next_closure_action' => $map['next_closure_action'],
            ],
            'gap_burn_down' => [
                'total_gaps' => $burnDown['total_gaps'],
                'gaps_closeable_without_new_feature_work' => $burnDown['gaps_closeable_without_new_feature_work'],
                'next_batch_recommendation' => $burnDown['next_batch_recommendation'],
            ],
            'certification' => [
                'verdict' => $certification['verdict'],
                'passed_count' => $certification['passed_count'],
                'required_count' => $certification['required_count'],
                'next_certification_action' => $certification['next_certification_action'],
            ],
            'finality_evidence' => [
                'is_final' => $finality['is_final'],
                'readiness_band' => $finality['readiness_band'],
                'finality_score' => $finality['finality_score'],
                'missing_categories' => $finality['missing_categories'],
                'next_highest_leverage_gap' => $finality['next_highest_leverage_gap'],
            ],
            'closed_loop_safety' => $closedLoopSafety,
            'blocker_ranked_closure_steps' => $closureSteps,
        ];

        $this->line($this->encode($payload));

        return $overallReady ? self::SUCCESS : self::FAILURE;
    }
}
