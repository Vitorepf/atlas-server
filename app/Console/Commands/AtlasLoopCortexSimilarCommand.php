<?php

declare(strict_types=1);

namespace App\Console\Commands;

use App\Services\Ai\AutonomousEvolution\Discovery\AtlasLoopScopeComprehensionModelBuilder;
use App\Services\Ai\AutonomousEvolution\Discovery\Cortex\Similarity\AtlasCortexSymbolSimilarityClusterReporter;
use App\Services\Ai\AutonomousEvolution\Discovery\Cortex\Similarity\AtlasCortexSymbolSimilarityFactExtractor;
use App\Services\Ai\AutonomousEvolution\Discovery\Cortex\Similarity\AtlasCortexSymbolSimilarityPairFact;
use Illuminate\Console\Command;

/**
 * Operator surface for the Cortex symbol-similarity subsystem. FACTS only — every output is a
 * raw counter / ratio / FQCN list; never a score, never a verdict, never a "recommendation".
 * ABSTAINS (exits non-zero with an explicit "no FACT" message) when the Cortex read-model is
 * unavailable, rather than fabricating an empty positive answer.
 */
final class AtlasLoopCortexSimilarCommand extends Command
{
    public const PAIR_FACTS_SOURCE_BINDING = 'atlas.loop.cortex.similar.pair_facts_source';

    public const HISTORY_DIR_BINDING = 'atlas.loop.cortex.similar.history_dir';

    private const VALID_ACTIONS = ['pair', 'cluster', 'history'];

    protected $signature = 'atlas:loop:cortex:similar
        {action : pair|cluster|history}
        {fqcnA? : positional argument; the FQCN (or for "pair", the first FQCN)}
        {fqcnB? : positional argument; the second FQCN of the pair}
        {--token= : token overlap threshold ∈ [0,1]}
        {--ast= : AST shape overlap threshold ∈ [0,1]}
        {--method= : method-name overlap threshold ∈ [0,1]}
        {--limit=20 : max history rows}
        {--json : machine-readable byte-identical output}';

    protected $description = 'Operator surface for Cortex symbol similarity (pair | cluster | history).';

    public function handle(): int
    {
        $action = (string) $this->argument('action');
        if (! in_array($action, self::VALID_ACTIONS, true)) {
            return $this->stderr(['error' => 'unknown_action', 'action' => $action, 'valid_actions' => self::VALID_ACTIONS], 1);
        }

        return match ($action) {
            'pair' => $this->doPair(),
            'cluster' => $this->doCluster(),
            'history' => $this->doHistory(),
        };
    }

    private function doPair(): int
    {
        $a = ltrim((string) $this->argument('fqcnA'), '\\');
        $b = ltrim((string) $this->argument('fqcnB'), '\\');
        if ($a === '' || $b === '') {
            return $this->stderr(['error' => 'fqcn_pair_required'], 1);
        }
        $facts = $this->loadPairFacts();
        if ($facts === null) {
            return $this->stderr(['error' => 'no_FACT', 'reason' => 'cortex_read_model_unavailable'], 2);
        }

        foreach ($facts as $fact) {
            $left = ltrim($fact->pairA, '\\');
            $right = ltrim($fact->pairB, '\\');
            if (($left === $a && $right === $b) || ($left === $b && $right === $a)) {
                $payload = $fact->toArray();

                return $this->emit($payload, 0);
            }
        }

        return $this->stderr([
            'error' => 'no_FACT',
            'reason' => 'pair_not_found_in_snapshot',
            'pair_a' => $a,
            'pair_b' => $b,
        ], 2);
    }

    private function doCluster(): int
    {
        $token = $this->parseThreshold('token');
        $ast = $this->parseThreshold('ast');
        $method = $this->parseThreshold('method');
        if ($token === null || $ast === null || $method === null) {
            return $this->stderr([
                'error' => 'invalid_threshold',
                'message' => 'all of --token --ast --method must be present and in [0,1]',
            ], 1);
        }

        $facts = $this->loadPairFacts();
        if ($facts === null) {
            return $this->stderr(['error' => 'no_FACT', 'reason' => 'cortex_read_model_unavailable'], 2);
        }

        // Reporter takes a single threshold + active channels. Compose by filtering qualifying
        // pairs across the THREE channels (each must pass its own threshold) and feeding the
        // result with threshold=0 + all 3 channels in MATCH_ALL mode.
        $qualified = [];
        foreach ($facts as $fact) {
            if ($fact->tokenOverlapRatio >= $token
                && $fact->astShapeOverlapRatio >= $ast
                && $fact->methodNameOverlapRatio >= $method) {
                $qualified[] = $fact;
            }
        }

        $reporter = $this->reporter();
        $clusters = $reporter->report(
            $qualified,
            0.0,
            ['token_overlap_ratio', 'ast_shape_overlap_ratio', 'method_name_overlap_ratio'],
            AtlasCortexSymbolSimilarityClusterReporter::MATCH_ALL,
        );

        // Sort members within each cluster lexically and emit one ClusterFact line per row.
        $rows = array_map(static function ($cluster): array {
            $arr = $cluster->toArray();
            if (isset($arr['members']) && is_array($arr['members'])) {
                sort($arr['members'], SORT_STRING);
            }

            return $arr;
        }, $clusters);

        return $this->emit(['action' => 'cluster', 'clusters' => $rows], 0);
    }

    private function doHistory(): int
    {
        $fqcn = ltrim((string) $this->argument('fqcnA'), '\\');
        $limit = max(1, (int) $this->option('limit'));
        if ($fqcn === '') {
            return $this->stderr(['error' => 'no_FACT', 'reason' => 'fqcn_required'], 2);
        }

        $dir = $this->historyDir();
        if (! is_dir($dir)) {
            return $this->stderr([
                'error' => 'no_FACT',
                'reason' => 'snapshot_directory_missing',
                'path' => $dir,
            ], 2);
        }

        $rows = [];
        foreach (array_values(array_filter((array) glob(rtrim($dir, '/').'/*.json'), 'is_file')) as $path) {
            $decoded = json_decode((string) file_get_contents($path), true);
            if (! is_array($decoded)) {
                continue;
            }
            foreach ((array) ($decoded['clusters'] ?? []) as $cluster) {
                $members = array_map(static fn ($m): string => ltrim((string) $m, '\\'), (array) ($cluster['members'] ?? []));
                if (in_array($fqcn, $members, true)) {
                    $rows[] = [
                        'snapshot' => basename((string) $path, '.json'),
                        'cluster_id' => (string) ($cluster['cluster_id'] ?? ''),
                        'members' => $members,
                    ];
                }
            }
        }

        if ($rows === []) {
            return $this->stderr([
                'error' => 'no_FACT',
                'reason' => 'fqcn_not_in_any_cluster',
                'fqcn' => $fqcn,
            ], 2);
        }

        // Byte-stable: lex-sort by snapshot then cluster_id, then slice newest N (i.e. last N).
        usort($rows, static fn (array $a, array $b): int => strcmp($a['snapshot'], $b['snapshot']) ?: strcmp($a['cluster_id'], $b['cluster_id']));
        $rows = array_slice($rows, -$limit);

        return $this->emit(['action' => 'history', 'fqcn' => $fqcn, 'rows' => $rows], 0);
    }

    private function parseThreshold(string $option): ?float
    {
        $raw = $this->option($option);
        if ($raw === null || $raw === '') {
            return null;
        }
        if (! is_numeric($raw)) {
            return null;
        }
        $value = (float) $raw;
        if ($value < 0.0 || $value > 1.0) {
            return null;
        }

        return $value;
    }

    /**
     * @return list<AtlasCortexSymbolSimilarityPairFact>|null
     */
    private function loadPairFacts(): ?array
    {
        // Explicit binding takes precedence (no behaviour change for the bound path).
        if ($this->getLaravel()->bound(self::PAIR_FACTS_SOURCE_BINDING)) {
            $source = $this->getLaravel()->make(self::PAIR_FACTS_SOURCE_BINDING);
            if (! is_callable($source)) {
                return null;
            }
            $out = [];
            foreach ((array) $source() as $fact) {
                if ($fact instanceof AtlasCortexSymbolSimilarityPairFact) {
                    $out[] = $fact;
                }
            }

            return $out;
        }

        // Fallback path: build a real comprehension snapshot from the Discovery scope
        // (same base_path()+scope pattern as AtlasLoopComprehensionCommand) and ask
        // the FactExtractor organ to produce the pair-fact list directly. Returns
        // null only when the snapshot cannot be built (organ is read-only by contract).
        try {
            $model = (new AtlasLoopScopeComprehensionModelBuilder)->build(
                base_path(),
                'app/Services/Ai/AutonomousEvolution/Discovery',
                ['docs_roots' => []]
            );
            $facts = app(AtlasCortexSymbolSimilarityFactExtractor::class)->extract($model);
        } catch (\Throwable) {
            return null;
        }

        return is_array($facts) ? array_values(array_filter(
            $facts,
            static fn (mixed $f): bool => $f instanceof AtlasCortexSymbolSimilarityPairFact,
        )) : null;
    }

    private function historyDir(): string
    {
        if ($this->getLaravel()->bound(self::HISTORY_DIR_BINDING)) {
            return (string) $this->getLaravel()->make(self::HISTORY_DIR_BINDING);
        }

        return storage_path('atlas/cortex/similarity');
    }

    private function reporter(): AtlasCortexSymbolSimilarityClusterReporter
    {
        return $this->getLaravel()->make(AtlasCortexSymbolSimilarityClusterReporter::class);
    }

    /**
     * @param  array<string|int,mixed>  $payload
     */
    private function emit(array $payload, int $exit): int
    {
        $payload = $this->sortRecursive($payload);
        $flags = JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE;
        if (! $this->option('json')) {
            $flags |= JSON_PRETTY_PRINT;
        }
        $this->line((string) json_encode($payload, $flags));

        return $exit;
    }

    /**
     * @param  array<string,mixed>  $payload
     */
    private function stderr(array $payload, int $exit): int
    {
        $output = $this->output;
        $msg = (string) json_encode($payload, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE);
        if ($output instanceof \Symfony\Component\Console\Output\ConsoleOutputInterface) {
            $output->getErrorOutput()->writeln($msg);
        }
        $this->line($msg);

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
