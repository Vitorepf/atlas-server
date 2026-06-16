<?php

declare(strict_types=1);

namespace App\Services\Ai\AutonomousEvolution;

use App\Models\AtlasLoopDeliveryContract;
use App\Services\Ai\AutonomousEvolution\Discovery\AtlasLoopBlastRadiusReader;
use Closure;
use Throwable;

/**
 * ACDE lever B4b — the certified-delivery BRAIN-FEEDBACK recorder (the MULTIPLIER write-end of the brain
 * flywheel B3 reads).
 *
 * On a certified+merged delivery the loop has PROVEN something: this target's public contract changed, these
 * files consume it (the blast radius), and the change cleared the frozen bar (the machine-resolved D2
 * dimensions). record() captures exactly that as a PROVIDER-SAFE candidate — target path + changed symbol
 * NAMES + consumer PATHS + dimensions + a deterministic confidence — so a future delivery can recall the
 * module's proven contract instead of re-discovering it. NEVER raw code, NEVER a model self-report, NEVER
 * engine-vs-engine.
 *
 * Anti-Goodhart: confidence is derived ONLY from the machine-resolved envelope (canary RED => 0; a clean
 * delivery with real consumers outranks a clean orphan; monotonic in consumers) — the recorder can never
 * grade its own bar. Fail-safe + flag-gated: with atlas.loop.delivery_brain_feedback_enabled OFF (default)
 * record() is a NO-OP and history() reports nothing, so the merge path is byte-identical until armed. Every DB
 * touch is defensively guarded; a container-less / DB-less / throwing caller degrades to inert, never throws —
 * the durable commit NEVER depends on this.
 */
final class AtlasLoopDeliveryContractRecorder
{
    /**
     * @param  Closure(string): array<string,mixed>|null  $blastResolver  target path → a blast-radius result
     *         (consumers/risk); defaults to the live {@see AtlasLoopBlastRadiusReader}. Injectable so the
     *         recorder is testable without the code-graph (the reader is final).
     */
    public function __construct(
        private readonly ?Closure $blastResolver = null,
        private readonly ?AtlasLoopDeliveryDimensionResolver $resolver = null,
    ) {}

    /**
     * Record ONE proven delivery contract. No-op when OFF / no DB (byte-identical). Deduped by candidate_hash
     * (recording twice for the same target+symbols+commit updates the single row, never a duplicate).
     *
     * @param  array<string,mixed>  $delivery  {target_path, changed_symbols:list<string>, canary:string, quality:array, commit_sha:?string}
     */
    public function record(array $delivery): void
    {
        if (! $this->enabled() || ! $this->dbAvailable()) {
            return;
        }

        try {
            $candidate = $this->toMemoryCandidates($delivery);
            if ($candidate === []) {
                return;
            }
            AtlasLoopDeliveryContract::query()->updateOrCreate(
                ['candidate_hash' => $candidate['candidate_hash']],
                $candidate,
            );
        } catch (Throwable) {
            // best-effort brain feedback — a recorder failure must NEVER break or unwind the merge.
        }
    }

    /**
     * PURE, provider-safe transform: a delivery → its contract record. No DB write; no raw diff/code ever
     * enters the record. Reuses {@see AtlasLoopBlastRadiusReader} (paths + risk only) for the consumer-set and
     * {@see AtlasLoopDeliveryDimensionResolver} (D2) for the machine-resolved dimensions. [] when no target.
     *
     * @param  array<string,mixed>  $delivery
     * @return array<string,mixed>
     */
    public function toMemoryCandidates(array $delivery): array
    {
        $target = trim((string) ($delivery['target_path'] ?? ''));
        if ($target === '') {
            return [];
        }

        $symbols = array_values(array_unique(array_filter(array_map(
            static fn ($s): string => trim((string) $s),
            is_array($delivery['changed_symbols'] ?? null) ? $delivery['changed_symbols'] : [],
        ), static fn (string $s): bool => $s !== '')));

        $blast = $this->blastResolver !== null
            ? ($this->blastResolver)($target)
            : (new AtlasLoopBlastRadiusReader)->read($target);
        $blast = is_array($blast) ? $blast : [];
        $consumers = is_array($blast['consumers'] ?? null) ? array_values($blast['consumers']) : [];
        $consumerCount = isset($blast['consumer_count']) ? (int) $blast['consumer_count'] : count($consumers);
        $riskBand = trim((string) ($blast['risk'] ?? '')) ?: 'unknown';

        $quality = is_array($delivery['quality'] ?? null) ? $delivery['quality'] : [];
        $dim = ($this->resolver ?? new AtlasLoopDeliveryDimensionResolver)->resolve([
            'attempted' => true,
            'committed' => true,
            'canary' => trim((string) ($delivery['canary'] ?? 'not_run')),
            'mutation_kill_ratio' => $this->numeric($quality, ['mutation_kill_ratio', 'kill_ratio']),
            'completeness' => $this->numeric($quality, ['completeness']),
            'cyclomatic_drop' => $this->numeric($quality, ['cyclomatic_drop']),
        ]);

        $record = [
            'target_path' => $target,
            'changed_symbols' => $symbols,
            'consumer_count' => $consumerCount,
            'consumers' => $consumers,
            'risk_band' => $riskBand,
            'canary' => (string) $dim['canary'],
            'mutation_kill_ratio' => (float) $dim['mutation_kill_ratio'],
            'completeness' => (float) $dim['completeness'],
            'confidence' => $this->confidence($dim, $consumerCount),
            'commit_sha' => trim((string) ($delivery['commit_sha'] ?? '')) ?: null,
        ];
        $record['candidate_hash'] = $this->candidateHash($record);

        return $record;
    }

    /**
     * Deterministic confidence in [0,1] from the machine-resolved D2 envelope + the consumer count — NEVER a
     * self-grade. An escaped defect (canary RED) or a non-clean delivery proves nothing => 0. A clean delivery
     * earns 0.5, plus up to 0.5 more that grows MONOTONICALLY with proven consumers (clean+wired outranks
     * clean+orphan). Saturates so a hub cannot inflate confidence without bound.
     *
     * @param  array<string,mixed>  $dim  a D2 resolve() result
     */
    public function confidence(array $dim, int $consumerCount): float
    {
        if (($dim['clean'] ?? false) !== true || ($dim['canary'] ?? '') === 'red') {
            return 0.0;
        }
        $consumerCount = max(0, $consumerCount);
        $wired = $consumerCount > 0 ? min(0.5, 0.1 + ($consumerCount / 20.0) * 0.4) : 0.0;

        return round(min(1.0, 0.5 + $wired), 4);
    }

    /**
     * The newest PROVEN (confidence > 0) contract recorded for $targetPath, or [] when OFF / no DB / none.
     * The read-back half B3-style consumers use to recall a module's proven contract.
     *
     * @return array<string,mixed>
     */
    public function history(string $targetPath): array
    {
        $targetPath = trim($targetPath);
        if ($targetPath === '' || ! $this->enabled() || ! $this->dbAvailable()) {
            return [];
        }

        try {
            $row = AtlasLoopDeliveryContract::query()
                ->where('target_path', $targetPath)
                ->where('confidence', '>', 0)
                ->orderByDesc('updated_at')
                ->first();

            return $row === null ? [] : [
                'target_path' => (string) $row->target_path,
                'changed_symbols' => is_array($row->changed_symbols) ? $row->changed_symbols : [],
                'consumer_count' => (int) $row->consumer_count,
                'consumers' => is_array($row->consumers) ? $row->consumers : [],
                'risk_band' => (string) $row->risk_band,
                'confidence' => (float) $row->confidence,
            ];
        } catch (Throwable) {
            return [];
        }
    }

    private function candidateHash(array $record): string
    {
        return substr(hash('sha256', (string) json_encode([
            $record['target_path'] ?? '',
            $record['changed_symbols'] ?? [],
            $record['commit_sha'] ?? null,
        ], JSON_UNESCAPED_SLASHES)), 0, 40);
    }

    /**
     * @param  array<string,mixed>  $quality
     * @param  list<string>  $keys
     */
    private function numeric(array $quality, array $keys): float
    {
        foreach ($keys as $key) {
            if (isset($quality[$key]) && is_numeric($quality[$key])) {
                return max(0.0, (float) $quality[$key]);
            }
        }

        return 0.0;
    }

    /** Is the brain-feedback flag ON? Read defensively so a container-less caller never fatals (=> OFF). */
    public function enabled(): bool
    {
        try {
            $app = function_exists('app') ? app() : null;
            if ($app === null || ! $app->bound('config')) {
                return false;
            }
        } catch (Throwable) {
            return false;
        }

        return (bool) config('atlas.loop.delivery_brain_feedback_enabled', false);
    }

    /** Is a DB connection resolvable in this process? (A pure-unit caller with no container is not.) */
    private function dbAvailable(): bool
    {
        try {
            $app = function_exists('app') ? app() : null;

            return $app !== null && $app->bound('db');
        } catch (Throwable) {
            return false;
        }
    }
}
