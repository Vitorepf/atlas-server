<?php

declare(strict_types=1);

namespace App\Services\Ai\Programming\ForgeRivals;

use DateTimeImmutable;
use DateTimeInterface;
use DateTimeZone;
use InvalidArgumentException;

/**
 * Atlas Forge Rivals · Provider Performance Ledger.
 *
 * Append-only local ledger of every Rivals/Provider Arena run, captured by
 * provider × model × role × task_category × mode. Becomes the canonical
 * substrate that lets Atlas Decide learn — without ever spending tokens here.
 *
 * The ledger:
 *   - reads scorecard + manifest produced by the existing adjudicator/run-real
 *     pipeline (no provider calls of its own);
 *   - persists each record as one JSON file under `ledger/entries/<id>.json`
 *     plus a single append-only `ledger/entries.jsonl` index;
 *   - never overwrites: re-recording the same run_id yields a new entry with
 *     a fresh `entry_id` and the prior entries stay intact;
 *   - rejects records that lack the canonical safety fields (run_id,
 *     evidence_pack_hash, task_category, role);
 *   - keeps `claim_ready=false` by default for every entry — the ledger
 *     records evidence, it does NOT decide a claim;
 *   - never unblocks `external_rivals_certification` and never reads the
 *     real provider receipts beyond the local scorecard JSON.
 *
 * Schema: atlas.forge.rivals.provider_performance_ledger_entry.v1
 *
 * Aggregates exposed by this service (read-only views over the ledger file):
 *   - by_task_category
 *   - by_role
 *   - by_provider_model
 *   - by_framework (when scorecard surfaces it)
 *   - atlas_forge_vs_raw_provider_delta
 *   - fair_vs_full_power_delta
 *   - cost_quality_frontier
 *
 * Confidence model:
 *   - `evidence_count >= 6` ⇒ confidence='high'
 *   - `evidence_count >= 3` ⇒ confidence='medium'
 *   - `evidence_count >= 1` ⇒ confidence='low'
 *   - `evidence_count == 0` ⇒ confidence='insufficient_evidence'
 *   - `latest_age_days > 14` ⇒ `stale_data=true`
 *
 * IMPORTANT: This ledger NEVER calls a provider. NEVER spends tokens. NEVER
 * unlocks external_rivals_certification. It is read-side intelligence built
 * on top of evidence packs that already exist on disk.
 */
final class AtlasForgeRivalsProviderPerformanceLedgerService
{
    public const SCHEMA_VERSION = 'atlas.forge.rivals.provider_performance_ledger.v1';

    public const ENTRY_SCHEMA_VERSION = 'atlas.forge.rivals.provider_performance_ledger_entry.v1';

    /** @var list<string> The seven canonical Atlas operator roles a provider can fill. */
    public const ROLES = [
        'builder',
        'reviewer',
        'repair_agent',
        'context_scout',
        'test_generator',
        'architect',
        'docs',
    ];

    /** @var list<string> Canonical task categories. */
    public const TASK_CATEGORIES = [
        'planning',
        'frontend',
        'backend',
        'bugfix',
        'refactor',
        'feature',
        'test',
        'docs',
        'devops',
        'security',
        'unknown',
    ];

    public const STALE_AGE_DAYS = 14;

    public const CONFIDENCE_HIGH_THRESHOLD = 6;

    public const CONFIDENCE_MEDIUM_THRESHOLD = 3;

    public const CONFIDENCE_INSUFFICIENT = 'insufficient_evidence';

    public const CONFIDENCE_LOW = 'low';

    public const CONFIDENCE_MEDIUM = 'medium';

    public const CONFIDENCE_HIGH = 'high';

    public function __construct(
        private readonly AtlasForgeRivalsRunPathResolver $paths,
    ) {}

    /**
     * Append a ledger entry built from a run's scorecard + manifest. Idempotent
     * in the sense that re-running the same run_id appends a new entry that
     * preserves history; nothing is overwritten.
     *
     * @param  array<string,mixed>  $input
     * @return array<string,mixed>
     */
    public function record(array $input): array
    {
        $runId = trim((string) ($input['run_id'] ?? ''));
        if ($runId === '') {
            return [
                'status' => 'blocked',
                'blockers' => ['run_id_required'],
                'next_command' => 'php artisan atlas:forge:rivals ledger-record --run-id=<id> --json',
            ];
        }

        try {
            $runPaths = $this->paths->paths($runId);
        } catch (\Throwable $e) {
            return [
                'status' => 'blocked',
                'blockers' => ['run_id_invalid:'.$e->getMessage()],
                'next_command' => '',
            ];
        }

        if (! is_dir($runPaths['base'])) {
            return [
                'status' => 'blocked',
                'blockers' => ['run_not_found:'.$runPaths['run_id']],
                'next_command' => 'php artisan atlas:forge:rivals run-battery --run-id='.$runPaths['run_id'].' --json',
            ];
        }

        $manifest = $this->readJson($runPaths['manifest_json']);
        $scorecard = $this->readJson($runPaths['scorecard_json']);
        $evidencePack = $this->readJson($runPaths['evidence'].'/evidence_pack.json');

        $blockers = [];
        if ($manifest === []) {
            $blockers[] = 'manifest_missing';
        }
        if ($scorecard === []) {
            $blockers[] = 'scorecard_missing';
        }
        if ($evidencePack === []) {
            $blockers[] = 'evidence_pack_missing';
        }
        if ($blockers !== []) {
            return [
                'status' => 'blocked',
                'blockers' => $blockers,
                'next_command' => 'php artisan atlas:forge:rivals adjudicate --run-id='.$runPaths['run_id'].' --json',
            ];
        }

        $taskCategory = $this->resolveTaskCategory($input, $manifest, $scorecard);
        $role = $this->resolveRole($input, $manifest);
        $framework = $this->resolveFramework($input, $manifest, $scorecard);

        if ($taskCategory === '') {
            return [
                'status' => 'blocked',
                'blockers' => ['task_category_required'],
                'next_command' => 'php artisan atlas:forge:rivals ledger-record --run-id='.$runPaths['run_id'].' --task-category=<cat> --json',
            ];
        }
        if ($role === '') {
            return [
                'status' => 'blocked',
                'blockers' => ['role_required'],
                'next_command' => 'php artisan atlas:forge:rivals ledger-record --run-id='.$runPaths['run_id'].' --role=<role> --json',
            ];
        }

        $evidenceHash = $this->resolveEvidenceHash($evidencePack);
        if ($evidenceHash === '') {
            return [
                'status' => 'blocked',
                'blockers' => ['evidence_hash_required'],
                'next_command' => 'php artisan atlas:forge:rivals collect-evidence --run-id='.$runPaths['run_id'].' --json',
            ];
        }

        $atlasEntry = $this->buildArmEntry(
            arm: 'atlas',
            runId: $runPaths['run_id'],
            manifest: $manifest,
            scorecard: $scorecard,
            evidencePack: $evidencePack,
            taskCategory: $taskCategory,
            role: $role,
            framework: $framework,
            evidenceHash: $evidenceHash,
        );
        $rivalEntry = $this->buildArmEntry(
            arm: 'rival',
            runId: $runPaths['run_id'],
            manifest: $manifest,
            scorecard: $scorecard,
            evidencePack: $evidencePack,
            taskCategory: $taskCategory,
            role: $role,
            framework: $framework,
            evidenceHash: $evidenceHash,
        );

        $this->ensureLedgerDirectory();
        $this->persistEntry($atlasEntry);
        $this->persistEntry($rivalEntry);

        return [
            'status' => 'ok',
            'schema_version' => self::SCHEMA_VERSION,
            'run_id' => $runPaths['run_id'],
            'entries_recorded' => [$atlasEntry, $rivalEntry],
            'ledger_path' => $this->ledgerIndexPath(),
            'evidence_paths' => [
                $this->ledgerIndexPath(),
                $this->ledgerEntryPath($atlasEntry['entry_id']),
                $this->ledgerEntryPath($rivalEntry['entry_id']),
            ],
            'separated_from_external_rivals_certification' => true,
            'external_provider_call' => false,
            'provider_tokens_spent' => false,
            'next_command' => 'php artisan atlas:forge:rivals ledger --json',
        ];
    }

    /**
     * Return a snapshot of the ledger plus computed aggregates.
     *
     * @param  array<string,mixed>  $input
     * @return array<string,mixed>
     */
    public function snapshot(array $input = []): array
    {
        $entries = $this->loadEntries();
        $filters = $this->buildFilters($input);
        $filtered = $this->applyFilters($entries, $filters);

        return [
            'status' => 'ok',
            'schema_version' => self::SCHEMA_VERSION,
            'generated_at' => $this->utcNow(),
            'ledger_path' => $this->ledgerIndexPath(),
            'ledger_root' => $this->ledgerRoot(),
            'filters' => $filters,
            'total_entries' => count($entries),
            'filtered_entries' => count($filtered),
            'entries_preview' => array_slice($filtered, -20),
            'aggregates' => [
                'by_task_category' => $this->aggregateByTaskCategory($filtered),
                'by_role' => $this->aggregateByRole($filtered),
                'by_provider_model' => $this->aggregateByProviderModel($filtered),
                'by_framework' => $this->aggregateByFramework($filtered),
                'atlas_forge_vs_raw_provider_delta' => $this->atlasVsRawDelta($filtered),
                'fair_vs_full_power_delta' => $this->fairVsFullPowerDelta($filtered),
                'cost_quality_frontier' => $this->costQualityFrontier($filtered),
            ],
            'invalid_entries_excluded_from_ranking' => true,
            'claim_ready' => false,
            'separated_from_external_rivals_certification' => true,
            'external_provider_call' => false,
            'provider_tokens_spent' => false,
        ];
    }

    /**
     * @return list<array<string,mixed>>
     */
    public function loadEntries(): array
    {
        $path = $this->ledgerIndexPath();
        if (! is_file($path)) {
            return [];
        }
        $entries = [];
        $handle = @fopen($path, 'r');
        if ($handle === false) {
            return [];
        }
        while (($line = fgets($handle)) !== false) {
            $line = trim($line);
            if ($line === '') {
                continue;
            }
            $row = json_decode($line, true);
            if (is_array($row)) {
                $entries[] = $row;
            }
        }
        fclose($handle);

        return $entries;
    }

    public function ledgerRoot(): string
    {
        $candidate = (string) (function_exists('config')
            ? (config('atlas_rivals.ledger_root') ?? $this->defaultLedgerRoot())
            : $this->defaultLedgerRoot());
        $candidate = rtrim(trim($candidate), '/');
        if ($candidate === '') {
            return $this->defaultLedgerRoot();
        }

        return $candidate;
    }

    public function ledgerIndexPath(): string
    {
        return $this->ledgerRoot().'/entries.jsonl';
    }

    public function ledgerEntryPath(string $entryId): string
    {
        $safe = preg_replace('/[^A-Za-z0-9_\-.]+/', '_', $entryId) ?? 'entry';

        return $this->ledgerRoot().'/entries/'.$safe.'.json';
    }

    /**
     * Pure helper: compute a confidence band for an `evidence_count`. Exposed
     * so the projection service can mirror identical thresholds.
     */
    public function confidenceFor(int $evidenceCount): string
    {
        if ($evidenceCount <= 0) {
            return self::CONFIDENCE_INSUFFICIENT;
        }
        if ($evidenceCount >= self::CONFIDENCE_HIGH_THRESHOLD) {
            return self::CONFIDENCE_HIGH;
        }
        if ($evidenceCount >= self::CONFIDENCE_MEDIUM_THRESHOLD) {
            return self::CONFIDENCE_MEDIUM;
        }

        return self::CONFIDENCE_LOW;
    }

    public function ageDays(string $isoDate, ?DateTimeImmutable $now = null): int
    {
        if ($isoDate === '') {
            return PHP_INT_MAX;
        }
        try {
            $dt = new DateTimeImmutable($isoDate);
        } catch (\Throwable) {
            return PHP_INT_MAX;
        }
        $now = $now ?? new DateTimeImmutable('now', new DateTimeZone('UTC'));
        $diff = $now->getTimestamp() - $dt->getTimestamp();

        return (int) max(0, intdiv($diff, 86_400));
    }

    /**
     * @param  array<string,mixed>  $input
     */
    private function resolveTaskCategory(array $input, array $manifest, array $scorecard): string
    {
        $raw = trim((string) ($input['task_category']
            ?? $manifest['task_category']
            ?? $scorecard['task_category']
            ?? ''));
        if ($raw === '') {
            return '';
        }
        $value = strtolower($raw);
        if (! in_array($value, self::TASK_CATEGORIES, true)) {
            return 'unknown';
        }

        return $value;
    }

    private function resolveRole(array $input, array $manifest): string
    {
        $raw = trim((string) ($input['role'] ?? $manifest['role'] ?? ''));
        if ($raw === '') {
            return '';
        }
        $value = strtolower($raw);
        if (! in_array($value, self::ROLES, true)) {
            throw new InvalidArgumentException(
                "Unknown role: '{$raw}'. Supported: ".implode(', ', self::ROLES).'.'
            );
        }

        return $value;
    }

    private function resolveFramework(array $input, array $manifest, array $scorecard): ?string
    {
        foreach ([$input['framework'] ?? null, $manifest['framework'] ?? null, $scorecard['framework'] ?? null] as $candidate) {
            if (is_string($candidate) && trim($candidate) !== '') {
                return strtolower(trim($candidate));
            }
        }

        return null;
    }

    private function resolveEvidenceHash(array $evidencePack): string
    {
        $artifacts = (array) ($evidencePack['artifacts'] ?? []);
        $parts = [];
        foreach (['manifest', 'atlas_receipt', 'rival_receipt', 'workspace_hashes'] as $key) {
            $row = $artifacts[$key] ?? null;
            if (is_array($row) && isset($row['sha256']) && is_string($row['sha256'])) {
                $parts[] = $key.':'.$row['sha256'];
            }
        }
        if ($parts === []) {
            return '';
        }

        return hash('sha256', implode('|', $parts));
    }

    /**
     * @return array<string,mixed>
     */
    private function buildArmEntry(
        string $arm,
        string $runId,
        array $manifest,
        array $scorecard,
        array $evidencePack,
        string $taskCategory,
        string $role,
        ?string $framework,
        string $evidenceHash,
    ): array {
        $score = $arm === 'atlas'
            ? ($scorecard['atlas_score'] ?? null)
            : ($scorecard['rival_score'] ?? null);
        $winner = $scorecard['winner'] ?? null;
        $hardFailures = $this->stringList($scorecard['hard_failures'] ?? []);
        $isInvalid = $hardFailures !== [] || $score === null;
        $isTie = $winner === 'human_review_required_tie';

        $outcome = 'invalid';
        if ($isInvalid) {
            $outcome = 'invalid';
        } elseif ($isTie) {
            $outcome = 'human_review_required';
        } elseif ($winner === $arm) {
            $outcome = 'winner';
        } elseif ($winner !== null) {
            $outcome = 'loser';
        }

        $dimensions = $scorecard['quality_dimensions'] ?? null;
        $scoresByDimension = [];
        if (is_array($dimensions)) {
            foreach ($dimensions as $name => $dim) {
                if (is_array($dim) && isset($dim[$arm])) {
                    $scoresByDimension[(string) $name] = (float) $dim[$arm];
                }
            }
        }

        $mode = (string) ($manifest['mode'] ?? 'unknown');
        $model = $arm === 'atlas'
            ? (string) ($manifest['atlas_model'] ?? 'unknown')
            : (string) ($manifest['rival_model'] ?? 'unknown');
        $provider = $this->inferProviderFromModel($model, $arm);
        $runnerType = $arm === 'atlas' ? 'atlas_forge' : 'raw_provider';
        $armId = $arm.':'.$provider.':'.$model.':'.$mode;

        $receiptKey = $arm.'_receipt';
        $receiptPath = (string) ($evidencePack['paths'][$receiptKey] ?? '');
        $receipt = is_file($receiptPath) ? $this->readJson($receiptPath) : [];

        $durationMs = $this->durationMs($receipt);
        $tokensUsed = (int) ($receipt['tokens_used'] ?? 0);
        $costEstimate = $this->coerceFloat($receipt['token_cost'] ?? null);
        $testsPassed = (int) ($receipt['test_exit_code'] ?? -1) === 0;
        $scopeViolations = count($this->stringList($receipt['out_of_scope_files'] ?? []));
        $interventionCount = (int) ($receipt['intervention_count'] ?? 0);
        $replayPassed = (bool) ($scorecard['replay_passes'] ?? false);

        $entryId = $this->buildEntryId($runId, $arm, $evidenceHash);
        $generatedAt = $this->utcNow();

        return [
            'schema_version' => self::ENTRY_SCHEMA_VERSION,
            'entry_id' => $entryId,
            'recorded_at' => $generatedAt,
            'run_id' => $runId,
            'battery_id' => (string) ($manifest['battery_id'] ?? $manifest['run_id'] ?? $runId),
            'arena_run_id' => (string) ($manifest['arena_run_id'] ?? $runId),
            'arm' => $arm,
            'arm_id' => $armId,
            'runner_type' => $runnerType,
            'provider' => $provider,
            'model' => $model,
            'task_category' => $taskCategory,
            'role' => $role,
            'framework' => $framework,
            'mode' => $mode,
            'preset' => (string) ($manifest['preset'] ?? 'unknown'),
            'score_total' => $score === null ? null : round((float) $score, 4),
            'scores_by_dimension' => $scoresByDimension,
            'winner' => $winner,
            'outcome' => $outcome,
            'human_review_required' => (bool) ($scorecard['human_review_required'] ?? false),
            'hard_failures' => $hardFailures,
            'tests_passed' => $testsPassed,
            'replay_passed' => $replayPassed,
            'scope_violations' => $scopeViolations,
            'intervention_count' => $interventionCount,
            'duration_ms' => $durationMs,
            'cost_estimate' => $costEstimate,
            'tokens_used' => $tokensUsed,
            'evidence_pack_hash' => $evidenceHash,
            'adjudication_hash' => $this->scorecardHash($scorecard),
            'valid_for_ranking' => $score !== null && $hardFailures === [],
            'claim_ready' => false,
            'separated_from_external_rivals_certification' => true,
            'external_provider_call' => false,
            'provider_tokens_spent' => false,
        ];
    }

    private function buildEntryId(string $runId, string $arm, string $evidenceHash): string
    {
        $micro = (string) (int) (microtime(true) * 1_000_000);
        $rand = bin2hex(random_bytes(3));

        return $runId.'-'.$arm.'-'.substr($evidenceHash, 0, 12).'-'.substr($micro, -6).$rand;
    }

    private function scorecardHash(array $scorecard): string
    {
        $serial = (string) json_encode([
            'winner' => $scorecard['winner'] ?? null,
            'atlas_score' => $scorecard['atlas_score'] ?? null,
            'rival_score' => $scorecard['rival_score'] ?? null,
            'hard_failures' => $scorecard['hard_failures'] ?? [],
            'tie_threshold' => $scorecard['tie_threshold'] ?? null,
        ]);

        return hash('sha256', $serial);
    }

    private function inferProviderFromModel(string $model, string $arm): string
    {
        $normalized = strtolower(trim($model));
        if ($normalized === '' || $normalized === 'unknown') {
            return 'unknown';
        }
        if (str_contains($normalized, 'claude')) {
            return 'anthropic_claude';
        }
        if (str_contains($normalized, 'codex')) {
            return 'openai_codex';
        }
        if (str_contains($normalized, 'gpt')) {
            return 'openai_gpt';
        }
        if (str_contains($normalized, 'gemini')) {
            return 'google_gemini';
        }
        if ($normalized === 'auto') {
            return 'atlas_decide';
        }

        return 'unknown';
    }

    /**
     * @param  array<string,mixed>  $receipt
     */
    private function durationMs(array $receipt): int
    {
        $started = (string) ($receipt['started_at'] ?? '');
        $finished = (string) ($receipt['finished_at'] ?? '');
        if ($started === '' || $finished === '') {
            return 0;
        }
        try {
            $a = new DateTimeImmutable($started);
            $b = new DateTimeImmutable($finished);
        } catch (\Throwable) {
            return 0;
        }

        return (int) max(0, ($b->getTimestamp() - $a->getTimestamp()) * 1_000);
    }

    private function ensureLedgerDirectory(): void
    {
        $root = $this->ledgerRoot();
        if (! is_dir($root)) {
            @mkdir($root, 0o755, true);
        }
        $entriesDir = $root.'/entries';
        if (! is_dir($entriesDir)) {
            @mkdir($entriesDir, 0o755, true);
        }
    }

    /**
     * @param  array<string,mixed>  $entry
     */
    private function persistEntry(array $entry): void
    {
        $entryFile = $this->ledgerEntryPath((string) $entry['entry_id']);
        file_put_contents(
            $entryFile,
            (string) json_encode($entry, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE)
        );

        $line = (string) json_encode($entry, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE);
        $index = $this->ledgerIndexPath();
        $handle = fopen($index, 'a');
        if ($handle !== false) {
            fwrite($handle, $line.PHP_EOL);
            fclose($handle);
        }
    }

    /**
     * @param  array<string,mixed>  $input
     * @return array<string,mixed>
     */
    private function buildFilters(array $input): array
    {
        $taskCategory = trim((string) ($input['task_category'] ?? ''));
        $role = trim((string) ($input['role'] ?? ''));
        $provider = trim((string) ($input['provider'] ?? ''));
        $framework = trim((string) ($input['framework'] ?? ''));

        return [
            'task_category' => $taskCategory === '' ? null : strtolower($taskCategory),
            'role' => $role === '' ? null : strtolower($role),
            'provider' => $provider === '' ? null : strtolower($provider),
            'framework' => $framework === '' ? null : strtolower($framework),
        ];
    }

    /**
     * @param  list<array<string,mixed>>  $entries
     * @param  array<string,mixed>  $filters
     * @return list<array<string,mixed>>
     */
    private function applyFilters(array $entries, array $filters): array
    {
        return array_values(array_filter($entries, function (array $e) use ($filters): bool {
            if ($filters['task_category'] !== null && ($e['task_category'] ?? null) !== $filters['task_category']) {
                return false;
            }
            if ($filters['role'] !== null && ($e['role'] ?? null) !== $filters['role']) {
                return false;
            }
            if ($filters['provider'] !== null && ($e['provider'] ?? null) !== $filters['provider']) {
                return false;
            }
            if ($filters['framework'] !== null && ($e['framework'] ?? null) !== $filters['framework']) {
                return false;
            }

            return true;
        }));
    }

    /**
     * @param  list<array<string,mixed>>  $entries
     * @return list<array<string,mixed>>
     */
    private function rankByKey(array $entries, string $keyPath): array
    {
        $buckets = [];
        foreach ($entries as $entry) {
            $key = (string) ($entry[$keyPath] ?? 'unknown');
            $buckets[$key] ??= [];
            $buckets[$key][] = $entry;
        }
        $rows = [];
        foreach ($buckets as $key => $items) {
            $rows[] = $this->summarizeBucket($key, $items, $keyPath);
        }
        usort($rows, static fn (array $a, array $b): int => $b['average_score_valid'] <=> $a['average_score_valid']);

        return $rows;
    }

    /**
     * @param  list<array<string,mixed>>  $items
     * @return array<string,mixed>
     */
    private function summarizeBucket(string $key, array $items, string $keyPath): array
    {
        $valid = array_values(array_filter($items, static fn (array $i): bool => (bool) ($i['valid_for_ranking'] ?? false)));
        $invalid = count($items) - count($valid);
        $scores = array_map(static fn (array $i): float => (float) ($i['score_total'] ?? 0), $valid);
        $latestIso = '';
        foreach ($items as $i) {
            $iso = (string) ($i['recorded_at'] ?? '');
            if ($iso !== '' && $iso > $latestIso) {
                $latestIso = $iso;
            }
        }
        $latestRunIds = [];
        $sortedByDate = $items;
        usort($sortedByDate, static fn (array $a, array $b): int => strcmp((string) ($b['recorded_at'] ?? ''), (string) ($a['recorded_at'] ?? '')));
        foreach (array_slice($sortedByDate, 0, 5) as $i) {
            $rid = (string) ($i['run_id'] ?? '');
            if ($rid !== '' && ! in_array($rid, $latestRunIds, true)) {
                $latestRunIds[] = $rid;
            }
        }
        $ageDays = $latestIso === '' ? null : $this->ageDays($latestIso);
        $stale = $ageDays !== null && $ageDays > self::STALE_AGE_DAYS;

        $providerSet = $this->collectUniqueStrings($items, 'provider');
        $modelSet = $this->collectUniqueStrings($items, 'model');

        return [
            'key_path' => $keyPath,
            'key' => $key,
            'evidence_count' => count($items),
            'valid_count' => count($valid),
            'invalid_count' => $invalid,
            'average_score_valid' => $valid === [] ? 0.0 : round(array_sum($scores) / max(1, count($scores)), 4),
            'max_score_valid' => $valid === [] ? null : round(max($scores), 4),
            'min_score_valid' => $valid === [] ? null : round(min($scores), 4),
            'providers' => $providerSet,
            'models' => $modelSet,
            'confidence' => $this->confidenceFor(count($valid)),
            'latest_recorded_at' => $latestIso === '' ? null : $latestIso,
            'latest_age_days' => $ageDays,
            'stale_data' => $stale,
            'latest_run_ids' => $latestRunIds,
        ];
    }

    /**
     * @param  list<array<string,mixed>>  $entries
     * @return list<array<string,mixed>>
     */
    private function aggregateByTaskCategory(array $entries): array
    {
        return $this->rankByKey($entries, 'task_category');
    }

    /**
     * @param  list<array<string,mixed>>  $entries
     * @return list<array<string,mixed>>
     */
    private function aggregateByRole(array $entries): array
    {
        return $this->rankByKey($entries, 'role');
    }

    /**
     * @param  list<array<string,mixed>>  $entries
     * @return list<array<string,mixed>>
     */
    private function aggregateByProviderModel(array $entries): array
    {
        $buckets = [];
        foreach ($entries as $entry) {
            $provider = (string) ($entry['provider'] ?? 'unknown');
            $model = (string) ($entry['model'] ?? 'unknown');
            $key = $provider.':'.$model;
            $buckets[$key] ??= [];
            $buckets[$key][] = $entry;
        }
        $rows = [];
        foreach ($buckets as $key => $items) {
            $row = $this->summarizeBucket($key, $items, 'provider_model');
            [$provider, $model] = explode(':', $key, 2) + ['unknown', 'unknown'];
            $row['provider'] = $provider;
            $row['model'] = $model;
            $rows[] = $row;
        }
        usort($rows, static fn (array $a, array $b): int => $b['average_score_valid'] <=> $a['average_score_valid']);

        return $rows;
    }

    /**
     * @param  list<array<string,mixed>>  $entries
     * @return list<array<string,mixed>>
     */
    private function aggregateByFramework(array $entries): array
    {
        $relevant = array_values(array_filter($entries, static fn (array $e): bool => isset($e['framework']) && $e['framework'] !== null));
        if ($relevant === []) {
            return [];
        }

        return $this->rankByKey($relevant, 'framework');
    }

    /**
     * @param  list<array<string,mixed>>  $entries
     * @return array<string,mixed>
     */
    private function atlasVsRawDelta(array $entries): array
    {
        $valid = array_values(array_filter($entries, static fn (array $e): bool => (bool) ($e['valid_for_ranking'] ?? false)));
        if ($valid === []) {
            return [
                'status' => self::CONFIDENCE_INSUFFICIENT,
                'atlas_forge_avg' => null,
                'raw_provider_avg' => null,
                'delta' => null,
                'sample_size_atlas_forge' => 0,
                'sample_size_raw_provider' => 0,
            ];
        }
        $atlasForge = array_filter($valid, static fn (array $e): bool => ($e['runner_type'] ?? null) === 'atlas_forge');
        $raw = array_filter($valid, static fn (array $e): bool => ($e['runner_type'] ?? null) === 'raw_provider');
        $atlasAvg = $atlasForge === [] ? null : $this->averageScore($atlasForge);
        $rawAvg = $raw === [] ? null : $this->averageScore($raw);
        $delta = ($atlasAvg !== null && $rawAvg !== null) ? round($atlasAvg - $rawAvg, 4) : null;

        return [
            'status' => $delta === null ? self::CONFIDENCE_INSUFFICIENT : 'ok',
            'atlas_forge_avg' => $atlasAvg,
            'raw_provider_avg' => $rawAvg,
            'delta' => $delta,
            'sample_size_atlas_forge' => count($atlasForge),
            'sample_size_raw_provider' => count($raw),
        ];
    }

    /**
     * @param  list<array<string,mixed>>  $entries
     * @return array<string,mixed>
     */
    private function fairVsFullPowerDelta(array $entries): array
    {
        $valid = array_values(array_filter($entries, static fn (array $e): bool => (bool) ($e['valid_for_ranking'] ?? false)));
        $fair = array_filter($valid, static fn (array $e): bool => ($e['mode'] ?? null) === 'fair');
        $full = array_filter($valid, static fn (array $e): bool => ($e['mode'] ?? null) === 'full_power');
        $fairAvg = $fair === [] ? null : $this->averageScore($fair);
        $fullAvg = $full === [] ? null : $this->averageScore($full);
        $delta = ($fairAvg !== null && $fullAvg !== null) ? round($fullAvg - $fairAvg, 4) : null;

        return [
            'status' => $delta === null ? self::CONFIDENCE_INSUFFICIENT : 'ok',
            'fair_avg' => $fairAvg,
            'full_power_avg' => $fullAvg,
            'delta_full_minus_fair' => $delta,
            'sample_size_fair' => count($fair),
            'sample_size_full_power' => count($full),
        ];
    }

    /**
     * Pareto-style sketch: each (provider, model, mode) bucket contributes a
     * point (avg_cost, avg_score). Invalid entries are excluded from the
     * frontier so a hard-failed run can never appear as a cost win.
     *
     * @param  list<array<string,mixed>>  $entries
     * @return list<array<string,mixed>>
     */
    private function costQualityFrontier(array $entries): array
    {
        $valid = array_values(array_filter($entries, static fn (array $e): bool => (bool) ($e['valid_for_ranking'] ?? false)));
        if ($valid === []) {
            return [];
        }
        $buckets = [];
        foreach ($valid as $entry) {
            $key = ($entry['provider'] ?? 'unknown').':'.($entry['model'] ?? 'unknown').':'.($entry['mode'] ?? 'unknown');
            $buckets[$key] ??= ['cost' => [], 'score' => [], 'tokens' => [], 'duration' => []];
            $buckets[$key]['cost'][] = (float) ($entry['cost_estimate'] ?? 0.0);
            $buckets[$key]['score'][] = (float) ($entry['score_total'] ?? 0.0);
            $buckets[$key]['tokens'][] = (int) ($entry['tokens_used'] ?? 0);
            $buckets[$key]['duration'][] = (int) ($entry['duration_ms'] ?? 0);
        }
        $rows = [];
        foreach ($buckets as $key => $data) {
            $count = count($data['score']);
            $rows[] = [
                'key' => $key,
                'avg_cost' => $count === 0 ? 0.0 : round(array_sum($data['cost']) / $count, 6),
                'avg_score' => $count === 0 ? 0.0 : round(array_sum($data['score']) / $count, 4),
                'avg_tokens' => $count === 0 ? 0 : (int) round(array_sum($data['tokens']) / $count),
                'avg_duration_ms' => $count === 0 ? 0 : (int) round(array_sum($data['duration']) / $count),
                'sample_size' => $count,
            ];
        }
        // Sort by score desc, then cost asc.
        usort($rows, static function (array $a, array $b): int {
            if ($a['avg_score'] === $b['avg_score']) {
                return $a['avg_cost'] <=> $b['avg_cost'];
            }

            return $b['avg_score'] <=> $a['avg_score'];
        });

        return $rows;
    }

    /**
     * @param  iterable<array<string,mixed>>  $entries
     */
    private function averageScore(iterable $entries): float
    {
        $sum = 0.0;
        $count = 0;
        foreach ($entries as $entry) {
            $sum += (float) ($entry['score_total'] ?? 0);
            $count++;
        }

        return $count === 0 ? 0.0 : round($sum / $count, 4);
    }

    /**
     * @param  list<array<string,mixed>>  $entries
     * @return list<string>
     */
    private function collectUniqueStrings(array $entries, string $field): array
    {
        $out = [];
        foreach ($entries as $entry) {
            $value = (string) ($entry[$field] ?? '');
            if ($value !== '' && ! in_array($value, $out, true)) {
                $out[] = $value;
            }
        }
        sort($out);

        return $out;
    }

    private function readJson(string $path): array
    {
        if (! is_file($path)) {
            return [];
        }
        $blob = (string) @file_get_contents($path);
        $row = json_decode($blob, true);

        return is_array($row) ? $row : [];
    }

    /**
     * @param  mixed  $value
     */
    private function coerceFloat($value): ?float
    {
        if (is_int($value) || is_float($value)) {
            return (float) $value;
        }
        if (is_string($value) && is_numeric($value)) {
            return (float) $value;
        }

        return null;
    }

    private function stringList(mixed $value): array
    {
        if (! is_array($value)) {
            return [];
        }

        return array_values(array_map(static fn ($v): string => (string) $v, $value));
    }

    private function utcNow(): string
    {
        return (new DateTimeImmutable('now', new DateTimeZone('UTC')))->format(DateTimeInterface::ATOM);
    }

    private function defaultLedgerRoot(): string
    {
        if (function_exists('storage_path')) {
            return storage_path('app/rivals-forge-ledger');
        }

        return '/Users/vitorepf/develop/Atlas-rivals/ledger';
    }
}
