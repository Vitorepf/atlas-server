<?php

declare(strict_types=1);

namespace App\Services\Ai\Programming\ForgeRivals;

/**
 * Atlas Forge Rivals · Replay (v2 hardened).
 *
 * Validates the evidence pack by re-hashing every artifact that the pack
 * declared `present=true` and comparing required artifacts against the
 * stage policy. Re-derives the verdict / claim deterministically from the
 * manifest.
 *
 * Stage semantics (see {@see AtlasForgeRivalsEvidencePolicy}):
 *   - `pre_adjudication`: scorecard is not in the artifact list at all.
 *     Missing per-arm artifacts on a `local_fake`/`invalid` run go into
 *     `optional_missing` instead of blocking the replay.
 *   - `final`: scorecard is required + hashed.
 *
 * Failure model:
 *   - `required_mismatches`: required artifact absent or hash mismatch.
 *     These block the replay (`status=blocked`, `replay_passes=false`).
 *   - `optional_missing`: optional artifact absent. Reported but never
 *     blocks. Listed separately from `mismatches` so downstream tooling can
 *     differentiate "broken bundle" from "expected absence".
 *   - `hash_mismatches`: any artifact that was declared `present=true` but
 *     whose on-disk SHA-256 no longer matches the pack. Always a blocker.
 *
 * Read-only. Never invokes provider.
 *
 * Schema: `atlas.forge.rivals.replay.v2` (v1 fields preserved).
 */
final class AtlasForgeRivalsReplayService
{
    public const SCHEMA_VERSION = 'atlas.forge.rivals.replay.v2';

    public const SCHEMA_VERSION_LEGACY = 'atlas.forge.rivals.replay.v1';

    public function __construct(
        private readonly AtlasForgeRivalsRunPathResolver $paths,
        private readonly AtlasForgeRivalsEventStream $events,
    ) {}

    /**
     * @param  array<string,mixed>  $input
     * @return array<string,mixed>
     */
    public function replay(array $input): array
    {
        $runId = trim((string) ($input['run_id'] ?? ''));
        if ($runId === '') {
            return [
                'status' => 'blocked',
                'blockers' => ['run_id_required'],
                'next_command' => 'php artisan atlas:forge:rivals replay --run-id=<id> --json',
            ];
        }
        $paths = $this->paths->paths($runId);
        if (! is_dir($paths['base'])) {
            return [
                'status' => 'blocked',
                'blockers' => ['run_not_found:'.$paths['run_id']],
                'next_command' => '',
            ];
        }

        $packPath = $paths['evidence'].'/evidence_pack.json';
        if (! is_file($packPath)) {
            return [
                'status' => 'blocked',
                'blockers' => ['evidence_pack_missing'],
                'next_command' => 'php artisan atlas:forge:rivals collect-evidence --run-id='.$paths['run_id'].' --json',
            ];
        }
        $pack = $this->readJson($packPath);
        $artifacts = (array) ($pack['artifacts'] ?? []);

        $manifest = $this->readJson($paths['manifest_json']);

        // Stage resolution: caller-provided wins; else pack's recorded stage;
        // else fall back to `final` (legacy v1 packs were implicitly "final").
        $callerStage = (string) ($input['evidence_stage'] ?? '');
        $packStage = (string) ($pack['evidence_stage'] ?? '');
        $stage = AtlasForgeRivalsEvidencePolicy::normalizeStage(
            $callerStage !== '' ? $callerStage : $packStage,
        );

        $plan = AtlasForgeRivalsEvidencePolicy::plan($stage, $manifest);

        // Required / optional resolution:
        //   - v2 pack carries `required_artifacts` + `optional_artifacts`
        //     explicitly. Trust those.
        //   - v1 pack lacks both fields. Preserve v1 semantics: every
        //     artifact the collector listed is treated as required (the
        //     collector pre-filtered to what mattered). This keeps legacy
        //     replay outcomes identical while v2 packs get full policy.
        $hasV2Markers = array_key_exists('required_artifacts', $pack)
            || array_key_exists('optional_artifacts', $pack)
            || array_key_exists('evidence_stage', $pack);
        if ($hasV2Markers) {
            $required = $this->stringList($pack['required_artifacts'] ?? null) ?: $plan['required'];
            $optional = $this->stringList($pack['optional_artifacts'] ?? null) ?: $plan['optional'];
        } else {
            $required = array_keys($artifacts);
            $optional = [];
        }

        $requiredMismatches = [];
        $optionalMissing = [];
        $hashMismatches = [];

        foreach ($required as $key) {
            $desc = $artifacts[$key] ?? null;
            if (! is_array($desc) || ! ($desc['present'] ?? false)) {
                $requiredMismatches[] = $key.':not_present_at_replay';

                continue;
            }
            $path = (string) ($desc['path'] ?? '');
            $expected = (string) ($desc['sha256'] ?? '');
            if (! is_file($path)) {
                $requiredMismatches[] = $key.':missing_at_replay';

                continue;
            }
            $actual = hash_file('sha256', $path) ?: '';
            if ($expected !== '' && $expected !== $actual) {
                $hashMismatches[] = $key.':hash_mismatch';
            }
        }

        // Optional artifacts: hash check only when present. Their absence is
        // informational, never a blocker.
        foreach ($optional as $key) {
            $desc = $artifacts[$key] ?? null;
            if (! is_array($desc) || ! ($desc['present'] ?? false)) {
                $optionalMissing[] = $key.':optional_missing';

                continue;
            }
            $path = (string) ($desc['path'] ?? '');
            $expected = (string) ($desc['sha256'] ?? '');
            if (! is_file($path)) {
                $optionalMissing[] = $key.':optional_missing_at_replay';

                continue;
            }
            $actual = hash_file('sha256', $path) ?: '';
            if ($expected !== '' && $expected !== $actual) {
                $hashMismatches[] = $key.':hash_mismatch';
            }
        }

        // Legacy v1 `mismatches` semantics: the list of strings that BLOCK
        // replay. v2 splits this into `required_mismatches` + `hash_mismatches`.
        $mismatches = array_values(array_merge($requiredMismatches, $hashMismatches));
        $replayPasses = $mismatches === [];

        $verdict = (string) ($manifest['verdict'] ?? 'unknown');

        $decision = [
            'verdict' => $verdict,
            'evidence_stage' => $plan['stage'],
            'replay_passes' => $replayPasses,
            'claim_ready' => $replayPasses
                && (bool) ($manifest['claim_ready'] ?? false)
                && $verdict === 'comparable'
                && ! str_starts_with($verdict, 'invalid'),
            'score' => $replayPasses ? ($manifest['score'] ?? null) : null,
        ];

        $events = $this->events->tail($runId, 500);

        return [
            'status' => $replayPasses ? 'ok' : 'blocked',
            'schema_version' => self::SCHEMA_VERSION,
            'replay_stage' => $plan['stage'],
            'run_id' => $paths['run_id'],
            'verdict' => $verdict,
            'replay_passes' => $replayPasses,
            'required_mismatches' => $requiredMismatches,
            'optional_missing' => $optionalMissing,
            'hash_mismatches' => $hashMismatches,
            'mismatches' => $mismatches,
            'decision' => $decision,
            'event_count' => count($events),
            'events_tail' => array_slice($events, -10),
            'blockers' => $mismatches,
            'external_provider_call' => false,
            'next_command' => $replayPasses
                ? 'php artisan atlas:forge:rivals report --run-id='.$paths['run_id'].' --json'
                : 'replay failed — evidence pack is no longer trustworthy',
        ];
    }

    /**
     * @return array<string,mixed>
     */
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
     * @return list<string>
     */
    private function stringList(mixed $value): array
    {
        if (! is_array($value)) {
            return [];
        }

        return array_values(array_map(static fn ($v): string => (string) $v, $value));
    }
}
