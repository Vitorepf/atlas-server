<?php

namespace App\Console\Commands;

use App\Services\Ai\OperatorIntelligence\OperatorInitiativeBridge;
use App\Services\Ai\OperatorIntelligence\OperatorPatternDetector;
use App\Services\Ai\OperatorIntelligence\OperatorSkillProposalBridge;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\Schema;

/**
 * "Atlas notices you repeat X → it prepares for you." Detects genuine recurring operator
 * patterns (evidence-locked, ≥3 occurrences, capped, deduped) and routes each into the
 * two GOVERNED bridges: a proactive mission DRAFT (never auto-executes) and/or a skill
 * BUILD PROPOSAL (paused/never-merge, operator-promoted). Propose-only — surfaces in the
 * Sunday review. Nothing builds or executes without the operator.
 */
class AtlasOperatorPatternsCommand extends Command
{
    protected $signature = 'atlas:ai:operator-patterns
        {--operator= : operator id (default from config)}
        {--window= : lookback window in days}
        {--max= : max patterns per run}
        {--dry-run : detect + report, propose nothing}
        {--json : machine-readable output}';

    protected $description = 'Detect recurring operator patterns and prepare governed skill/mission proposals (propose-only, Sunday review).';

    public function handle(
        OperatorPatternDetector $detector,
        OperatorInitiativeBridge $initiative,
        OperatorSkillProposalBridge $skills,
    ): int {
        if (! Schema::hasTable('operator_pattern_detections')) {
            $this->warn('operator_pattern_detections table unavailable.');

            return self::SUCCESS;
        }

        $operatorId = (string) ($this->option('operator') ?: config('atlas_operator_intelligence.default_operator_id', 'default'));
        $opts = [];
        if ($this->option('window')) {
            $opts['window_days'] = (int) $this->option('window');
        }
        if ($this->option('max')) {
            $opts['max_patterns'] = (int) $this->option('max');
        }
        $dryRun = (bool) $this->option('dry-run');

        $detections = $detector->detect($operatorId, $opts);

        $missionsProposed = 0;
        $skillsProposed = 0;
        $rows = [];
        foreach ($detections as $detection) {
            $missionId = null;
            $skillTask = null;
            if (! $dryRun) {
                // Both bridges guard on their OWN proposal field, so a 'both' pattern gets
                // a mission AND a skill in the same pass (no status-collision skip).
                $mission = $initiative->propose($detection);
                $missionId = $mission?->id;
                if ($missionId !== null) {
                    $missionsProposed++;
                }
                $skillTask = $skills->propose($detection->refresh());
                if ($skillTask !== null) {
                    $skillsProposed++;
                }
            }
            $rows[] = [
                substr((string) $detection->pattern_id, 0, 10),
                (string) $detection->kind,
                (string) $detection->occurrence_count,
                (string) round((float) $detection->confidence, 2),
                $missionId ? 'mission' : ($skillTask ? 'skill' : ($dryRun ? '(dry)' : '-')),
            ];
        }

        $report = [
            'schema_version' => OperatorPatternDetector::SCHEMA,
            'operator_id' => $operatorId,
            'dry_run' => $dryRun,
            'patterns_detected' => count($detections),
            'missions_proposed' => $missionsProposed,
            'skills_proposed' => $skillsProposed,
        ];

        if ((bool) $this->option('json')) {
            $this->line((string) json_encode($report, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE));

            return self::SUCCESS;
        }

        $this->info(sprintf('Detected %d recurring pattern(s) for "%s" — %d mission draft(s), %d skill proposal(s).%s',
            count($detections), $operatorId, $missionsProposed, $skillsProposed, $dryRun ? ' (dry-run)' : ''));
        if ($rows !== []) {
            $this->table(['pattern', 'kind', 'occ', 'conf', 'proposed'], $rows);
        }
        $this->line('Proposals are PREPARED, not executed: missions are drafts (need your activation); skills are never-merge proposals (need your --confirm to promote). Review them Sunday.');

        return self::SUCCESS;
    }
}
