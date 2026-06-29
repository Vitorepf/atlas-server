<?php

declare(strict_types=1);

namespace App\Console\Commands;

use App\Services\Ai\AutonomousEvolution\V4\AtlasLoopV4SelfArchitectureProposer;
use Closure;
use Illuminate\Console\Command;

/**
 * Arms the dormant {@see AtlasLoopV4SelfArchitectureProposer::propose()} at the operator surface via a
 * DETERMINISTIC in-memory architect: it proposes the first brain-topology node that is NOT in the
 * forbidden-self-targets (returning null when none qualify), then the proposer validates the pick against the
 * constitution and emits the verdict {proposed, kind, target_path, rationale, refuse_reason}.
 *
 * Pure + read-only: the architect is a closure, never an LLM; the proposer never admits a forbidden target.
 */
final class AtlasLoopArchProposeCommand extends Command
{
    protected $signature = 'atlas:loop:arch-propose {--topology=} {--forbidden=} {--json}';

    protected $description = 'Read-only self-architecture proposal over a brain topology (deterministic architect).';

    public function handle(): int
    {
        $topology = $this->readJsonList('topology');
        if ($topology === null) {
            return $this->refuse('arch-propose requires --topology=<JSON array of topology nodes>');
        }
        $forbidden = $this->readJsonList('forbidden') ?? [];

        $architect = static function (array $normalizedTopology, array $normalizedForbidden): ?array {
            foreach ($normalizedTopology as $node) {
                $node = (string) $node;
                $blocked = false;
                foreach ($normalizedForbidden as $f) {
                    $f = (string) $f;
                    if ($f !== '' && str_contains($node, $f)) {
                        $blocked = true;
                        break;
                    }
                }
                if (! $blocked) {
                    return [
                        'kind' => 'add_seam',
                        'target_path' => $node,
                        'rationale' => 'Introduce a constructor seam at '.$node.' so the node is unit-testable in isolation.',
                    ];
                }
            }

            return null; // no qualifying (non-forbidden) topology node
        };

        $proposer = new AtlasLoopV4SelfArchitectureProposer(Closure::fromCallable($architect));
        $verdict = $proposer->propose(array_values($topology), array_values($forbidden));

        $facts = ['schema' => 'atlas.loop.arch_propose.v1'] + $verdict;

        if ($this->option('json')) {
            $this->line((string) json_encode($facts, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE | JSON_THROW_ON_ERROR));
        } else {
            $this->line('proposed: '.($verdict['proposed'] ? 'yes' : 'no').'  target: '.($verdict['target_path'] ?? '-').'  refuse_reason: '.($verdict['refuse_reason'] ?? '-'));
        }

        return self::SUCCESS;
    }

    /** @return array<mixed>|null */
    private function readJsonList(string $option): ?array
    {
        $raw = trim((string) $this->option($option));
        if ($raw === '') {
            return null;
        }
        if (is_file($raw) && is_readable($raw)) {
            $raw = (string) file_get_contents($raw);
        }
        $decoded = json_decode($raw, true);

        return is_array($decoded) && array_is_list($decoded) ? $decoded : null;
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
