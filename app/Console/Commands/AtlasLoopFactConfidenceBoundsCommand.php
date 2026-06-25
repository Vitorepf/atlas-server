<?php

declare(strict_types=1);

namespace App\Console\Commands;

use App\Services\Ai\AutonomousEvolution\FactConfidence\AtlasLoopFactBoundsMissingException;
use App\Services\Ai\AutonomousEvolution\FactConfidence\AtlasLoopFactConfidenceBoundsReceiptLedger;
use App\Services\Ai\AutonomousEvolution\FactConfidence\AtlasLoopFactConfidenceBoundsValidator;
use Illuminate\Console\Command;

/**
 * Operator surface for distinguishing single-sample FACTS (legacy) from multi-source bounded FACTS.
 * Read-only. Snapshot source is a container-bound callable so the producer (atlas:loop:comprehend
 * snapshot writer) can plug in without coupling at construction time. Ledger history is read by
 * directly tailing the configured ledger file path.
 */
final class AtlasLoopFactConfidenceBoundsCommand extends Command
{
    public const SNAPSHOT_SOURCE_BINDING = 'atlas.loop.fact_confidence.snapshot_source';

    public const LEDGER_PATH_BINDING = 'atlas.loop.fact_confidence.ledger_path';

    private const VALID_ACTIONS = ['inspect', 'validate', 'history'];

    protected $signature = 'atlas:loop:fact:bounds {action : inspect|validate|history} {--fact-key=} {--limit=20} {--json}';

    protected $description = 'Confidence-envelope surface for the comprehend snapshot (inspect | validate | history).';

    public function __construct(
        private readonly AtlasLoopFactConfidenceBoundsValidator $validator,
        private readonly AtlasLoopFactConfidenceBoundsReceiptLedger $ledger,
    ) {
        parent::__construct();
    }

    public function handle(): int
    {
        $action = (string) $this->argument('action');
        if (! in_array($action, self::VALID_ACTIONS, true)) {
            return $this->emit([
                'action' => $action,
                'outcome' => 'refused',
                'reason' => 'unknown_action',
                'valid_actions' => self::VALID_ACTIONS,
            ], 2);
        }

        return match ($action) {
            'inspect' => $this->doInspect(),
            'validate' => $this->doValidate(),
            'history' => $this->doHistory(),
        };
    }

    private function doInspect(): int
    {
        $rows = array_map(
            fn (array $f): array => [
                'fact_key' => (string) ($f['fact_key'] ?? ''),
                'value' => $f['value'] ?? null,
                'sample_size' => (int) ($f['sample_size'] ?? 1),
                'source_count' => (int) ($f['source_count'] ?? 1),
                'legacy' => $this->isLegacy($f),
            ],
            $this->loadSnapshot(),
        );

        return $this->emit(['action' => 'inspect', 'facts' => $rows], 0);
    }

    private function doValidate(): int
    {
        $facts = $this->loadSnapshot();
        $violations = [];
        foreach ($facts as $f) {
            $emissionPath = (string) ($f['emission_path'] ?? ($f['fact_key'] ?? ''));
            try {
                $this->validator->validate($emissionPath, $f);
            } catch (AtlasLoopFactBoundsMissingException $e) {
                $violations[] = [
                    'emission_path' => $emissionPath,
                    'fact_key' => (string) ($f['fact_key'] ?? ''),
                    'reason' => 'missing_confidence_bounds_envelope',
                    'message' => $e->getMessage(),
                ];
            }
        }

        $exit = $violations === [] ? 0 : 1;

        return $this->emit([
            'action' => 'validate',
            'outcome' => $exit === 0 ? 'ok' : 'failed',
            'violations' => $violations,
        ], $exit);
    }

    private function doHistory(): int
    {
        $factKey = (string) $this->option('fact-key');
        $limit = max(1, (int) $this->option('limit'));
        $rows = $this->readLedgerRows();
        if ($factKey !== '') {
            $rows = array_values(array_filter(
                $rows,
                static fn (array $r): bool => (string) ($r['fact_key'] ?? '') === $factKey,
            ));
        }
        // Most-recent-first: ledger is append-order, so reverse then slice.
        $rows = array_reverse($rows);
        $rows = array_slice($rows, 0, $limit);

        return $this->emit([
            'action' => 'history',
            'fact_key' => $factKey,
            'limit' => $limit,
            'rows' => $rows,
        ], 0);
    }

    /**
     * @return list<array<string,mixed>>
     */
    private function loadSnapshot(): array
    {
        if (! $this->getLaravel()->bound(self::SNAPSHOT_SOURCE_BINDING)) {
            return [];
        }
        $source = $this->getLaravel()->make(self::SNAPSHOT_SOURCE_BINDING);
        if (! is_callable($source)) {
            return [];
        }
        $out = [];
        foreach ((array) $source() as $row) {
            if (is_array($row)) {
                $out[] = $row;
            }
        }

        return $out;
    }

    /**
     * @return list<array<string,mixed>>
     */
    private function readLedgerRows(): array
    {
        $path = $this->ledger->ledgerPath();
        if ($this->getLaravel()->bound(self::LEDGER_PATH_BINDING)) {
            $bound = (string) $this->getLaravel()->make(self::LEDGER_PATH_BINDING);
            if ($bound !== '') {
                $path = $bound;
            }
        }
        if (! is_file($path)) {
            return [];
        }
        $rows = [];
        foreach ((array) file($path, FILE_IGNORE_NEW_LINES | FILE_SKIP_EMPTY_LINES) as $line) {
            $decoded = json_decode((string) $line, true);
            if (is_array($decoded)) {
                $rows[] = $decoded;
            }
        }

        return $rows;
    }

    /**
     * @param  array<string,mixed>  $fact
     */
    private function isLegacy(array $fact): bool
    {
        if (array_key_exists('legacy', $fact)) {
            return (bool) $fact['legacy'];
        }
        $hasEnvelope = is_array($fact['confidence_bounds'] ?? null);

        return ! $hasEnvelope;
    }

    /**
     * @param  array<string,mixed>  $payload
     */
    private function emit(array $payload, int $exit): int
    {
        $sorted = $this->sortRecursive($payload);
        $flags = JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE;
        if (! $this->option('json')) {
            $flags |= JSON_PRETTY_PRINT;
        }
        $this->line((string) json_encode($sorted, $flags));

        return $exit;
    }

    private function sortRecursive(mixed $value): mixed
    {
        if (! is_array($value)) {
            return $value;
        }
        if (! array_is_list($value)) {
            ksort($value, SORT_STRING);
        }
        foreach ($value as $k => $v) {
            $value[$k] = $this->sortRecursive($v);
        }

        return $value;
    }
}
