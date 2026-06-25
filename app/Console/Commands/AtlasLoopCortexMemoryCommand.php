<?php

declare(strict_types=1);

namespace App\Console\Commands;

use App\Services\Ai\AutonomousEvolution\Discovery\Cortex\Memory\AtlasCortexMemoryBlindSpotTracker;
use App\Services\Ai\AutonomousEvolution\Discovery\Cortex\Memory\AtlasCortexMemoryConvergenceObserver;
use App\Services\Ai\AutonomousEvolution\Discovery\Cortex\Memory\AtlasCortexMemoryRecurrencyDetector;
use App\Services\Ai\AutonomousEvolution\Discovery\Cortex\Memory\AtlasCortexMemoryRetrievalService;
use Illuminate\Console\Command;

/**
 * Operator surface over the Cortex Memory FACT services.
 *
 *   recurrent      AtlasCortexMemoryRecurrencyDetector::recurrentItems
 *   blindspots     AtlasCortexMemoryBlindSpotTracker::persistentBlindSpots
 *   convergence    AtlasCortexMemoryConvergenceObserver::stabilizedIntents
 *   recall         AtlasCortexMemoryRetrievalService::retrieve (current FACT from CLI flags)
 *
 * Exit codes: 0 ok, 1 invalid action, 2 missing recall fact fields.
 * --json mode: canonical json_encode (sorted keys, UNESCAPED_SLASHES|UNESCAPED_UNICODE).
 */
final class AtlasLoopCortexMemoryCommand extends Command
{
    public const EXIT_OK = 0;

    public const EXIT_INVALID_ACTION = 1;

    public const EXIT_MISSING_RECALL_FACT = 2;

    protected $signature = 'atlas:loop:cortex:memory {action : recall|recurrent|blindspots|convergence} {--min-runs=3} {--limit=20} {--since=} {--json} {--item-id=} {--fingerprint=} {--gap-id=} {--gap-kind=} {--intent-id=} {--canonical-form=}';

    protected $description = 'Cortex Memory operator surface: recall | recurrent | blindspots | convergence.';

    public function handle(
        AtlasCortexMemoryRecurrencyDetector $recurrent,
        AtlasCortexMemoryBlindSpotTracker $blindspots,
        AtlasCortexMemoryConvergenceObserver $convergence,
        AtlasCortexMemoryRetrievalService $recall,
    ): int {
        $action = (string) $this->argument('action');
        $minRuns = max(1, (int) $this->option('min-runs'));
        $limit = max(1, (int) $this->option('limit'));
        $since = $this->option('since');
        $sinceUnix = ($since === null || $since === '') ? null : (int) $since;

        return match ($action) {
            'recurrent' => $this->emit('recurrent_items', $recurrent->recurrentItems($minRuns, $sinceUnix)),
            'blindspots' => $this->emit('persistent_blind_spots', $blindspots->persistentBlindSpots($minRuns, $sinceUnix)),
            'convergence' => $this->emit('stabilized_intents', $convergence->stabilizedIntents($minRuns, $sinceUnix)),
            'recall' => $this->recall($recall, $limit),
            default => $this->invalidAction($action),
        };
    }

    private function recall(AtlasCortexMemoryRetrievalService $svc, int $limit): int
    {
        $fact = $this->buildCurrentFact();
        if ($fact === null) {
            $this->error('recall requires one of: (--item-id + --fingerprint) | (--gap-id [+ --gap-kind]) | (--intent-id + --canonical-form)');

            return self::EXIT_MISSING_RECALL_FACT;
        }

        return $this->emit('recall', $svc->retrieve($fact, $limit));
    }

    /**
     * @return array<string,mixed>|null
     */
    private function buildCurrentFact(): ?array
    {
        $itemId = trim((string) $this->option('item-id'));
        $fingerprint = trim((string) $this->option('fingerprint'));
        $gapId = trim((string) $this->option('gap-id'));
        $gapKind = trim((string) $this->option('gap-kind'));
        $intentId = trim((string) $this->option('intent-id'));
        $canonical = trim((string) $this->option('canonical-form'));

        if ($itemId !== '' && $fingerprint !== '') {
            return ['kind' => 'inventory_item', 'item_id' => $itemId, 'fingerprint' => $fingerprint];
        }
        if ($intentId !== '' && $canonical !== '') {
            return ['kind' => 'intent', 'intent_id' => $intentId, 'canonical_form' => $canonical];
        }
        if ($gapId !== '') {
            return ['kind' => 'blind_spot', 'gap_id' => $gapId, 'gap_kind' => $gapKind];
        }

        return null;
    }

    private function emit(string $key, array $rows): int
    {
        $payload = [$key => $rows];
        if ($this->option('json')) {
            $this->line($this->canonicalJson($payload));
        } else {
            $this->line($key.':');
            foreach ($rows as $row) {
                $this->line('  '.json_encode($row, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE));
            }
        }

        return self::EXIT_OK;
    }

    private function invalidAction(string $action): int
    {
        $this->error('invalid action: '.$action.' (expected: recall|recurrent|blindspots|convergence)');

        return self::EXIT_INVALID_ACTION;
    }

    private function canonicalJson(mixed $value): string
    {
        return (string) json_encode($this->canonicalize($value), JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE | JSON_THROW_ON_ERROR);
    }

    private function canonicalize(mixed $value): mixed
    {
        if (! is_array($value)) {
            return $value;
        }
        if (array_is_list($value)) {
            return array_map(fn ($v) => $this->canonicalize($v), $value);
        }
        ksort($value);
        foreach ($value as $k => $v) {
            $value[$k] = $this->canonicalize($v);
        }

        return $value;
    }
}
