<?php

declare(strict_types=1);

namespace App\Services\Ai\Programming\ForgeRivals;

use App\Services\Ai\Programming\ForgeRivals\Arms\AtlasForgeRivalsArmRegistryService;
use DateTimeImmutable;
use DateTimeInterface;
use DateTimeZone;

/**
 * Atlas Code · Provider Arena UI Snapshot.
 *
 * Read-only projection consumed by the Provider Arena RightRail panel in
 * Atlas Code Desktop. Combines the canonical registries (arms, modes,
 * task categories, presets) with the local history of arena runs (read
 * from disk; never invokes any provider, never spawns a subprocess).
 *
 * Honest empty state contract:
 *   - When there is no run on disk, `last_run`/`history` are `null`/`[]`.
 *   - We never invent a winner, a score, a claim or an external provider
 *     call.
 *   - This service NEVER unblocks `external_rivals_certification`.
 *
 * Schema: atlas.code.provider_arena_snapshot.v1
 */
final class AtlasCodeProviderArenaSnapshotService
{
    public const SCHEMA_VERSION = 'atlas.code.provider_arena_snapshot.v1';

    public const HISTORY_LIMIT_DEFAULT = 10;

    public function __construct(
        private readonly AtlasForgeRivalsArmRegistryService $armRegistry,
        private readonly AtlasForgeRivalsModeRegistry $modeRegistry,
        private readonly AtlasForgeRivalsCasesRegistry $casesRegistry,
        private readonly AtlasForgeRivalsRunPathResolver $paths,
    ) {}

    /**
     * @return array<string,mixed>
     */
    public function snapshot(int $historyLimit = self::HISTORY_LIMIT_DEFAULT): array
    {
        $limit = $historyLimit > 0 ? min($historyLimit, 100) : self::HISTORY_LIMIT_DEFAULT;
        $arms = $this->armRegistry->snapshot();
        $modes = $this->modesProjection();
        $presets = $this->presetsProjection();
        $history = $this->history($limit);
        $lastRun = $history === [] ? null : $history[0];

        return [
            'schema_version' => self::SCHEMA_VERSION,
            'generated_at' => (new DateTimeImmutable('now', new DateTimeZone('UTC')))->format(DateTimeInterface::ATOM),
            'arm_registry' => [
                'schema_version' => $arms['schema_version'] ?? AtlasForgeRivalsArmRegistryService::SCHEMA_VERSION,
                'arms' => array_values($arms['arms']),
                'arm_count' => (int) ($arms['arm_count'] ?? 0),
                'task_categories' => $arms['task_categories'] ?? AtlasForgeRivalsArmRegistryService::TASK_CATEGORIES,
                'task_category_count' => (int) ($arms['task_category_count'] ?? 0),
            ],
            'modes' => $modes,
            'presets' => $presets,
            'history' => $history,
            'history_count' => count($history),
            'history_limit' => $limit,
            'last_run' => $lastRun,
            'safety_promises' => [
                'never_promotes_completion_claim' => true,
                'never_unlocks_external_rivals_certification' => true,
                'requires_three_confirmations_for_real_provider' => true,
                'local_fake_never_invokes_provider' => true,
                'replay_required_before_winner' => true,
                'evidence_required_before_winner' => true,
            ],
            'external_provider_call' => false,
            'provider_tokens_spent' => false,
            'separated_from_external_rivals_certification' => true,
            'note' => 'Read-only Provider Arena projection. Real runs require explicit operator confirmations and never run from this snapshot.',
        ];
    }

    /**
     * @return list<array<string,mixed>>
     */
    private function modesProjection(): array
    {
        $arenaModes = [
            AtlasForgeRivalsModeRegistry::MODE_LOCAL_FAKE,
            AtlasForgeRivalsModeRegistry::MODE_FAIR,
            AtlasForgeRivalsModeRegistry::MODE_FULL_POWER,
        ];
        $out = [];
        foreach ($arenaModes as $mode) {
            $m = $this->modeRegistry->mode($mode);
            $out[] = [
                'mode' => $m['mode'],
                'requires_provider' => (bool) $m['requires_provider'],
                'allows_atlas_decide' => (bool) $m['allows_atlas_decide'],
                'allows_topology_declaration' => (bool) $m['allows_topology_declaration'],
                'claim_eligible' => (bool) $m['claim_eligible'],
                'allowed_models' => $m['allowed_models'],
                'note' => (string) $m['note'],
                'usable_in_arena' => true,
            ];
        }

        return $out;
    }

    /**
     * @return list<array<string,mixed>>
     */
    private function presetsProjection(): array
    {
        $out = [];
        foreach (AtlasForgeRivalsCasesRegistry::PRESETS as $preset) {
            $cases = [];
            try {
                $cases = $this->casesRegistry->casesForPreset($preset);
            } catch (\Throwable) {
                $cases = [];
            }
            $out[] = [
                'preset' => $preset,
                'case_count' => count($cases),
                'note' => match ($preset) {
                    'smoke' => 'Fast safety check — minimal case set.',
                    'quick' => 'Operator default — one canonical case.',
                    'release' => 'Release window — wider case set arrives in Slice 6.',
                    'full' => 'Full battery — broadest case set; longest runtime.',
                    default => '',
                },
            ];
        }

        return $out;
    }

    /**
     * @return list<array<string,mixed>>
     */
    private function history(int $limit): array
    {
        $root = $this->paths->rootDirectory();
        if (! is_dir($root)) {
            return [];
        }

        $entries = [];
        $handle = @opendir($root);
        if ($handle === false) {
            return [];
        }
        try {
            while (false !== ($name = readdir($handle))) {
                if ($name === '.' || $name === '..') {
                    continue;
                }
                $base = $root.'/'.$name;
                if (! is_dir($base)) {
                    continue;
                }
                $events = $base.'/events.jsonl';
                if (! is_file($events)) {
                    continue;
                }
                $entries[] = ['run_id' => $name, 'base' => $base, 'mtime' => @filemtime($events) ?: 0];
            }
        } finally {
            closedir($handle);
        }

        usort($entries, static fn (array $a, array $b): int => $b['mtime'] <=> $a['mtime']);
        $entries = array_slice($entries, 0, $limit);

        $history = [];
        foreach ($entries as $e) {
            $history[] = $this->readRunEntry((string) $e['run_id'], (string) $e['base'], (int) $e['mtime']);
        }

        return $history;
    }

    /**
     * @return array<string,mixed>
     */
    private function readRunEntry(string $runId, string $base, int $mtime): array
    {
        $manifest = $this->readJsonIfFile($base.'/evidence/manifest.json');
        $scorecard = $this->readJsonIfFile($base.'/evidence/scorecard.json');
        $arena = $this->readJsonIfFile($base.'/evidence/arena_context.json');
        $reportMd = $base.'/evidence/report.md';

        return [
            'run_id' => $runId,
            'base_path' => $base,
            'updated_at_unix' => $mtime,
            'mode' => $this->stringOrNull($manifest['mode'] ?? null),
            'preset' => $this->stringOrNull($manifest['preset'] ?? null),
            'task_category' => $this->stringOrNull($arena['task_category'] ?? ($manifest['task_category'] ?? null)),
            'arm_a' => $this->shallowMap($arena['arm_a'] ?? null),
            'arm_b' => $this->shallowMap($arena['arm_b'] ?? null),
            'winner' => $this->stringOrNull($scorecard['winner'] ?? null),
            'verdict' => $this->stringOrNull($scorecard['verdict'] ?? null),
            'claim_ready' => isset($scorecard['claim_ready']) ? (bool) $scorecard['claim_ready'] : null,
            'comparable_score' => $this->numberOrNull($scorecard['comparable_score'] ?? null),
            'diagnostic_score' => $this->numberOrNull($scorecard['diagnostic_score'] ?? null),
            'report_md_present' => is_file($reportMd),
            'evidence_dir' => $base.'/evidence',
            'events_jsonl' => $base.'/events.jsonl',
            'external_provider_call' => isset($manifest['external_provider_call']) ? (bool) $manifest['external_provider_call'] : null,
        ];
    }

    /**
     * @return array<string,mixed>|null
     */
    private function readJsonIfFile(string $path): ?array
    {
        if (! is_file($path)) {
            return null;
        }
        $raw = @file_get_contents($path);
        if (! is_string($raw) || $raw === '') {
            return null;
        }
        $decoded = json_decode($raw, true);
        if (! is_array($decoded)) {
            return null;
        }

        return $decoded;
    }

    private function stringOrNull(mixed $value): ?string
    {
        if ($value === null) {
            return null;
        }
        $s = is_scalar($value) ? (string) $value : '';

        return $s === '' ? null : $s;
    }

    private function numberOrNull(mixed $value): ?float
    {
        if ($value === null) {
            return null;
        }
        if (is_int($value) || is_float($value)) {
            return (float) $value;
        }
        if (is_string($value) && is_numeric($value)) {
            return (float) $value;
        }

        return null;
    }

    /**
     * @return array<string,mixed>|null
     */
    private function shallowMap(mixed $value): ?array
    {
        if (! is_array($value)) {
            return null;
        }

        return [
            'arm_id' => $this->stringOrNull($value['arm_id'] ?? null),
            'runner_type' => $this->stringOrNull($value['runner_type'] ?? null),
            'provider' => $this->stringOrNull($value['provider'] ?? null),
            'model' => $this->stringOrNull($value['model'] ?? null),
            'legacy_model_id' => $this->stringOrNull($value['legacy_model_id'] ?? null),
            'status' => $this->stringOrNull($value['status'] ?? null),
            'human_label' => $this->stringOrNull($value['human_label'] ?? null),
        ];
    }
}
