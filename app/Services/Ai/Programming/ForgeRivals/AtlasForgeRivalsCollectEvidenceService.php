<?php

declare(strict_types=1);

namespace App\Services\Ai\Programming\ForgeRivals;

/**
 * Atlas Forge Rivals · Collect Evidence.
 *
 * Assembles the canonical evidence pack for a run by enumerating files in
 * `runs/<run_id>/evidence/`, the run's events.jsonl + intent.json, and
 * hashing every artifact for the replay step.
 *
 * Never invokes provider. Read-only.
 */
final class AtlasForgeRivalsCollectEvidenceService
{
    public const SCHEMA_VERSION = 'atlas.forge.rivals.evidence_pack.v1';

    public function __construct(
        private readonly AtlasForgeRivalsRunPathResolver $paths,
    ) {}

    /**
     * @param  array<string,mixed>  $input
     * @return array<string,mixed>
     */
    public function collect(array $input): array
    {
        $runId = trim((string) ($input['run_id'] ?? ''));
        if ($runId === '') {
            return [
                'status' => 'blocked',
                'blockers' => ['run_id_required'],
                'next_command' => 'php artisan atlas:forge:rivals collect-evidence --run-id=<id> --json',
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

        $artifacts = [];
        foreach ([
            'manifest' => $paths['manifest_json'],
            'events_jsonl' => $paths['events_jsonl'],
            'intent_json' => $paths['base'].'/intent.json',
            'atlas_receipt' => $paths['evidence'].'/atlas_receipt.json',
            'rival_receipt' => $paths['evidence'].'/rival_receipt.json',
            'workspace_hashes' => $paths['evidence'].'/workspace_hashes.json',
        ] as $key => $path) {
            $artifacts[$key] = $this->describeFile($path);
        }

        $missing = [];
        foreach (['manifest', 'events_jsonl', 'atlas_receipt', 'rival_receipt'] as $required) {
            if (! ($artifacts[$required]['present'] ?? false)) {
                $missing[] = 'missing_evidence:'.$required;
            }
        }

        $manifest = $this->readJson($paths['manifest_json']);
        $verdict = (string) ($manifest['verdict'] ?? 'unknown');
        $claimReady = (bool) ($manifest['claim_ready'] ?? false);
        if (! str_starts_with($verdict, 'invalid')) {
            // Only `comparable` runs that produced complete evidence are admissible for claim.
            if ($missing !== []) {
                $claimReady = false;
            }
        } else {
            $claimReady = false;
        }

        $pack = [
            'schema_version' => self::SCHEMA_VERSION,
            'run_id' => $paths['run_id'],
            'collected_at' => now()->toJSON(),
            'paths' => $paths,
            'artifacts' => $artifacts,
            'missing_evidence' => $missing,
            'verdict' => $verdict,
            'claim_ready' => $claimReady,
            'score' => $manifest['score'] ?? null,
            'manifest_summary' => $manifest === [] ? null : [
                'mode' => $manifest['mode'] ?? null,
                'preset' => $manifest['preset'] ?? null,
                'atlas_model' => $manifest['atlas_model'] ?? null,
                'rival_model' => $manifest['rival_model'] ?? null,
                'case_id' => $manifest['case_id'] ?? null,
                'dirty_after_run' => $manifest['dirty_after_run'] ?? null,
            ],
            'external_provider_call' => (bool) ($manifest['external_provider_call'] ?? false),
            'provider_tokens_spent' => (bool) ($manifest['provider_tokens_spent'] ?? false),
            'separated_from_external_rivals_certification' => true,
        ];

        // Persist the pack itself for replay.
        @mkdir($paths['evidence'], 0o755, true);
        file_put_contents(
            $paths['evidence'].'/evidence_pack.json',
            (string) json_encode($pack, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE)
        );

        return [
            'status' => $missing === [] ? 'ok' : 'blocked',
            'run_id' => $paths['run_id'],
            'evidence_pack' => $pack,
            'blockers' => $missing,
            'evidence_paths' => array_values(array_filter(array_map(
                static fn (array $a): ?string => ($a['present'] ?? false) ? (string) $a['path'] : null,
                $artifacts,
            ))),
            'next_command' => $missing === []
                ? 'php artisan atlas:forge:rivals replay --run-id='.$paths['run_id'].' --json'
                : 'check missing artifacts and rerun the run',
        ];
    }

    /**
     * @return array<string,mixed>
     */
    private function describeFile(string $path): array
    {
        if (! is_file($path)) {
            return ['path' => $path, 'present' => false];
        }
        $bytes = (int) @filesize($path);

        return [
            'path' => $path,
            'present' => true,
            'bytes' => $bytes,
            'sha256' => hash_file('sha256', $path) ?: null,
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
