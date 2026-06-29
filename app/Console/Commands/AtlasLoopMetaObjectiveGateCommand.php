<?php

declare(strict_types=1);

namespace App\Console\Commands;

use App\Services\Ai\AutonomousEvolution\V4\AtlasLoopV4MetaObjectiveGate;
use Illuminate\Console\Command;

/**
 * Arms the dormant orphan {@see AtlasLoopV4MetaObjectiveGate::admit()} at the operator surface: previews whether
 * a meta-objective is admitted given its capability-delta attribution — refusing one that is not originated,
 * cites a confounded (unattributed) capability dimension, or targets capability with a dead attribution signal.
 *
 * Pure + read-only: it decides admission and reports; it mutates nothing.
 */
final class AtlasLoopMetaObjectiveGateCommand extends Command
{
    protected $signature = 'atlas:loop:meta-objective-gate {--meta-objective=} {--attribution=} {--json}';

    protected $description = 'Read-only meta-objective admission verdict given its capability-delta attribution.';

    public function handle(): int
    {
        $metaObjective = $this->readJson('meta-objective');
        $attribution = $this->readJson('attribution');
        if ($metaObjective === null) {
            return $this->refuse('meta-objective-gate requires --meta-objective=<json object or path>');
        }
        if ($attribution === null) {
            return $this->refuse('meta-objective-gate requires --attribution=<json object or path>');
        }

        $verdict = app(AtlasLoopV4MetaObjectiveGate::class)->admit($metaObjective, $attribution);

        $facts = ['schema' => 'atlas.loop.v4_meta_objective_gate.v1'] + $verdict;

        if ($this->option('json')) {
            $this->line((string) json_encode($facts, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE | JSON_THROW_ON_ERROR));
        } else {
            $this->line('admitted: '.($verdict['admitted'] ? 'yes' : 'no').'  refuse_reason: '.($verdict['refuse_reason'] ?? '-'));
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
