<?php

declare(strict_types=1);

namespace App\Console\Commands;

use App\Services\Ai\AutonomousEvolution\AtlasLoopCriterionStabilitySelector;
use Illuminate\Console\Command;

/**
 * Arms the dormant orphan {@see AtlasLoopCriterionStabilitySelector::select()} at the operator surface: picks
 * the most criterion-stable candidate from a best-of-N set — the one satisfying the MOST falsifiable criteria
 * (ties → smaller change, then lexicographic id) — or null when none is usable.
 *
 * Pure + read-only: it selects over machine-resolved per-criterion pass counts and reports; it mutates nothing.
 */
final class AtlasLoopStableSelectCommand extends Command
{
    protected $signature = 'atlas:loop:stable-select {--candidates=} {--json}';

    protected $description = 'Read-only criterion-stability selection over best-of-N candidates (or null).';

    public function handle(): int
    {
        $raw = trim((string) $this->option('candidates'));
        if ($raw === '') {
            return $this->refuse('stable-select requires --candidates=<JSON array of candidates or a path>');
        }
        if (is_file($raw) && is_readable($raw)) {
            $raw = (string) file_get_contents($raw);
        }
        $decoded = json_decode($raw, true);
        if (! is_array($decoded)) {
            return $this->refuse('--candidates must be a JSON array/object');
        }
        $candidates = isset($decoded['candidates']) && is_array($decoded['candidates']) ? $decoded['candidates'] : $decoded;
        if (! array_is_list($candidates)) {
            return $this->refuse('--candidates must be a JSON array of candidates');
        }

        $selected = app(AtlasLoopCriterionStabilitySelector::class)->select(array_values($candidates));

        $facts = [
            'schema' => 'atlas.loop.criterion_stability_select.v1',
            'selected' => $selected,
            'selected_id' => is_array($selected) ? (string) ($selected['id'] ?? '') : null,
        ];

        if ($this->option('json')) {
            $this->line((string) json_encode($facts, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE | JSON_THROW_ON_ERROR));
        } else {
            $this->line('selected: '.($facts['selected_id'] ?? '(none)'));
        }

        return self::SUCCESS;
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
