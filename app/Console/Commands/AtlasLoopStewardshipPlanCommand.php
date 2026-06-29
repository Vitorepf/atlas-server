<?php

declare(strict_types=1);

namespace App\Console\Commands;

use App\Services\Ai\SelfConstruction\E2E\AtlasSelfConstructionStewardshipInstanceRuntimePlan;
use Illuminate\Console\Command;

/**
 * Arms the dormant orphan {@see AtlasSelfConstructionStewardshipInstanceRuntimePlan::compose()} at the operator
 * surface: composes a stewardship runtime plan for an admitted lane given its organ coverage, or refuses with
 * the precise blockers (lane not admitted, coverage incomplete, cross-project scope escape, ...).
 *
 * Pure + read-only: it composes a plan envelope and reports; it mutates nothing.
 */
final class AtlasLoopStewardshipPlanCommand extends Command
{
    protected $signature = 'atlas:loop:stewardship-plan {--lane=} {--coverage=} {--json}';

    protected $description = 'Read-only stewardship runtime plan for a lane given its coverage (refuses with blockers).';

    public function handle(): int
    {
        $lane = $this->readJson('lane');
        $coverage = $this->readJson('coverage');
        if ($lane === null) {
            return $this->refuse('stewardship-plan requires --lane=<json object or path>');
        }
        if ($coverage === null) {
            return $this->refuse('stewardship-plan requires --coverage=<json object or path>');
        }

        $plan = app(AtlasSelfConstructionStewardshipInstanceRuntimePlan::class)->compose($lane, $coverage);

        if ($this->option('json')) {
            $this->line((string) json_encode($plan, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE | JSON_THROW_ON_ERROR));
        } else {
            $this->line('refused: '.($plan['refused'] ? 'yes' : 'no'));
            foreach ($plan['blockers'] as $b) {
                $this->line('  blocker: '.$b);
            }
        }

        return self::SUCCESS;
    }

    /** @return array<string,mixed>|null */
    private function readJson(string $option): ?array
    {
        $raw = trim((string) $this->option($option));
        if ($raw === '') {
            return null;
        }
        if (is_file($raw) && is_readable($raw)) {
            $raw = (string) file_get_contents($raw);
        }
        $decoded = json_decode($raw, true);

        return is_array($decoded) ? $decoded : null;
    }

    private function refuse(string $message): int
    {
        $this->line((string) json_encode([
            'outcome' => 'refused',
            'reason' => 'usage_error',
            'message' => $message,
        ], JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE | JSON_THROW_ON_ERROR));

        return self::FAILURE;
    }
}
