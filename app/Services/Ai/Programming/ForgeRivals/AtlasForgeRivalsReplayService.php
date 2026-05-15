<?php

declare(strict_types=1);

namespace App\Services\Ai\Programming\ForgeRivals;

/**
 * Atlas Forge Rivals · Replay.
 *
 * Validates the evidence pack by re-hashing every recorded artifact and
 * comparing against the hashes captured at run time. Re-derives the
 * verdict / claim deterministically from the manifest. If anything mismatches
 * or is missing, replay fails and the report is forbidden from declaring
 * a winner.
 *
 * Read-only. Never invokes provider.
 */
final class AtlasForgeRivalsReplayService
{
    public const SCHEMA_VERSION = 'atlas.forge.rivals.replay.v1';

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

        $mismatches = [];
        foreach ($artifacts as $key => $desc) {
            if (! is_array($desc) || ! ($desc['present'] ?? false)) {
                $mismatches[] = $key.':not_present_at_replay';

                continue;
            }
            $path = (string) ($desc['path'] ?? '');
            $expected = (string) ($desc['sha256'] ?? '');
            if (! is_file($path)) {
                $mismatches[] = $key.':missing_at_replay';

                continue;
            }
            $actual = hash_file('sha256', $path) ?: '';
            if ($expected !== '' && $expected !== $actual) {
                $mismatches[] = $key.':hash_mismatch';
            }
        }

        $manifest = $this->readJson($paths['manifest_json']);
        $verdict = (string) ($manifest['verdict'] ?? 'unknown');
        $replayPasses = $mismatches === [];

        // Decision is deterministic: re-derive from manifest + pack hash validity.
        $decision = [
            'verdict' => $verdict,
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
            'run_id' => $paths['run_id'],
            'verdict' => $verdict,
            'replay_passes' => $replayPasses,
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
}
