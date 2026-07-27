<?php

declare(strict_types=1);

namespace App\Console\Commands;

use App\Console\Commands\Concerns\LoadsFactsJsonOption;
use App\Services\Ai\SelfConstruction\Compounding\AtlasSelfConstructionGiveBackLessonConsolidator;
use App\Services\Ai\SelfConstruction\Compounding\AtlasSelfConstructionCompoundingOutcomeProjection;
use App\Services\Ai\SelfConstruction\Compounding\AtlasSelfConstructionCompoundingVelocityTracker;
use App\Services\Ai\SelfConstruction\Compounding\AtlasSelfConstructionLeverageDeltaReporter;
use App\Services\Ai\SelfConstruction\Compounding\AtlasSelfConstructionNextFrontierSelector;
use App\Services\Ai\SelfConstruction\ExternalBrain\AtlasExternalBrainPostImplementationLessonExtractor;
use Illuminate\Console\Command;

/**
 * Read-only operator surface for the Self-Construction compounding flywheel.
 *
 *   outcomes   AtlasSelfConstructionCompoundingOutcomeProjection::project(facts.records)
 *   velocity   AtlasSelfConstructionCompoundingVelocityTracker::track(facts.cycle_facts)
 *   leverage   AtlasSelfConstructionLeverageDeltaReporter::report(facts.before, facts.after)
 *   frontier   AtlasSelfConstructionNextFrontierSelector::select(...)
 *
 * Payload always carries `final_runtime_owner = "atlas_native"`. NEVER calls providers, NEVER mutates
 * runtime, NEVER touches git.
 */
final class AtlasSelfConstructionCompoundingCommand extends Command
{
    use LoadsFactsJsonOption;

    public const EXIT_OK = 0;

    public const EXIT_USAGE = 2;

    public const FINAL_RUNTIME_OWNER = 'atlas_native';

    protected $signature = 'atlas:self-construction:compounding {action : outcomes|velocity|leverage|frontier} {--facts=} {--json}';

    protected $description = 'Read-only Self-Construction compounding CLI: outcomes | velocity | leverage | frontier.';

    public function handle(
        AtlasSelfConstructionCompoundingOutcomeProjection $projection,
        AtlasSelfConstructionCompoundingVelocityTracker $velocity,
        AtlasSelfConstructionLeverageDeltaReporter $leverage,
        AtlasSelfConstructionNextFrontierSelector $frontier,
    ): int {
        $action = (string) $this->argument('action');
        $facts = $this->loadFacts();
        if ($facts === null) {
            return self::EXIT_USAGE;
        }

        $payload = match ($action) {
            'outcomes' => $projection->project((array) ($facts['records'] ?? [])),
            'velocity' => $velocity->track((array) ($facts['cycle_facts'] ?? [])),
            'leverage' => $leverage->report((array) ($facts['before'] ?? []), (array) ($facts['after'] ?? [])),
            'frontier' => $frontier->select(
                (array) ($facts['leverage_delta'] ?? []),
                (array) ($facts['unresolved_blockers'] ?? []),
                (array) ($facts['missing_organ_coverage'] ?? []),
                // Backward compatible: if give_back_lessons is already supplied, use it directly.
                // Otherwise run extractor + consolidator from raw outcomes.
                array_key_exists('give_back_lessons', $facts)
                    ? (array) $facts['give_back_lessons']
                    : $this->resolveGiveBackLessons($facts),
                [],
                $this->resolveFrontierVelocityRecommendation($velocity, $facts),
            ),
            default => null,
        };
        if ($payload === null) {
            $this->refuseUsage('unknown action: '.$action);

            return self::EXIT_USAGE;
        }

        $payload['final_runtime_owner'] = self::FINAL_RUNTIME_OWNER;
        $this->emit($payload);

        return self::EXIT_OK;
    }


    /**
     * @param  array<string,mixed>  $payload
     */
    private function emit(array $payload): void
    {
        if ($this->option('json')) {
            $this->line((string) json_encode($payload, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE | JSON_THROW_ON_ERROR));

            return;
        }
        foreach ($payload as $k => $v) {
            $this->line($k.': '.(is_scalar($v) ? (string) $v : json_encode($v, JSON_UNESCAPED_SLASHES)));
        }
    }

    /**
     * When give_back_lessons are NOT pre-supplied in the facts payload, derive
     * them by running the extractor then consolidator over the raw outcomes.
     *
     * @param  array<string,mixed>  $facts
     * @return list<array{class:string, repeat_count:int}>
     */
    private function resolveGiveBackLessons(array $facts): array
    {
        $rawOutcomes = (array) ($facts['outcomes'] ?? []);
        if ($rawOutcomes === []) {
            return [];
        }

        $extractor = new AtlasExternalBrainPostImplementationLessonExtractor;
        $consolidator = new AtlasSelfConstructionGiveBackLessonConsolidator;

        $extracted = $extractor->extract(['outcomes' => $rawOutcomes]);

        return $consolidator->consolidate($extracted);
    }

    /**
     * Run the velocity tracker on cycle_facts and return the aggregate recommendation.
     * Returns 'reduce_churn_before_scaling' if any row recommends it, otherwise ''.
     */
    private function resolveFrontierVelocityRecommendation(
        AtlasSelfConstructionCompoundingVelocityTracker $velocity,
        array $facts,
    ): string {
        $cycleFacts = (array) ($facts['cycle_facts'] ?? []);
        if ($cycleFacts === []) {
            return '';
        }

        $velocityResult = $velocity->track($cycleFacts);
        foreach ((array) ($velocityResult['rows'] ?? []) as $row) {
            if (($row['recommendation'] ?? '') === 'reduce_churn_before_scaling') {
                return 'reduce_churn_before_scaling';
            }
        }

        return '';
    }
}
