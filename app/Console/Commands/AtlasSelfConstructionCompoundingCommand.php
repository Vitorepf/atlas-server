<?php

declare(strict_types=1);

namespace App\Console\Commands;

use App\Services\Ai\SelfConstruction\Compounding\AtlasSelfConstructionCompoundingOutcomeProjection;
use App\Services\Ai\SelfConstruction\Compounding\AtlasSelfConstructionCompoundingVelocityTracker;
use App\Services\Ai\SelfConstruction\Compounding\AtlasSelfConstructionLeverageDeltaReporter;
use App\Services\Ai\SelfConstruction\Compounding\AtlasSelfConstructionNextFrontierSelector;
use Illuminate\Console\Command;
use Throwable;

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
                (array) ($facts['give_back_lessons'] ?? []),
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
     * @return array<string,mixed>|null
     */
    private function loadFacts(): ?array
    {
        $path = (string) $this->option('facts');
        if ($path === '' || ! is_file($path)) {
            $this->refuseUsage('--facts=<path> is required and must point to an existing JSON file');

            return null;
        }
        try {
            $decoded = json_decode((string) file_get_contents($path), true, 512, JSON_THROW_ON_ERROR);
        } catch (Throwable $e) {
            $this->refuseUsage('facts payload not valid JSON: '.mb_substr($e->getMessage(), 0, 200));

            return null;
        }
        if (! is_array($decoded)) {
            $this->refuseUsage('facts payload root must be a JSON object');

            return null;
        }

        return $decoded;
    }

    private function refuseUsage(string $reason): void
    {
        if ($this->option('json')) {
            $this->line((string) json_encode(['status' => 'usage_error', 'reason' => $reason], JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE));

            return;
        }
        $this->error($reason);
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
}
