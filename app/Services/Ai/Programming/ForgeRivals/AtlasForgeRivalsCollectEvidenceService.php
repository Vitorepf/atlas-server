<?php

declare(strict_types=1);

namespace App\Services\Ai\Programming\ForgeRivals;

/**
 * Atlas Forge Rivals · Collect Evidence (v2 hardened).
 *
 * Assembles the canonical evidence pack for a run by enumerating files in
 * `runs/<run_id>/evidence/`, the run's `events.jsonl` + `intent.json`, and
 * hashing every artifact for the replay step.
 *
 * Two stages exist (see {@see AtlasForgeRivalsEvidencePolicy}):
 *   - `pre_adjudication`: collected BEFORE the adjudicator writes the
 *     scorecard. `scorecard` is intentionally not enumerated.
 *   - `final`: collected AFTER adjudication. `scorecard` is enumerated and
 *     required; replay (final stage) re-hashes it.
 *
 * The `missing_evidence` v1 field is preserved (it equals the v2
 * `missing_required` list, prefixed with `missing_evidence:` for legacy
 * adjudicator hard-gate `evidence_complete`).
 *
 * Never invokes provider. Read-only.
 *
 * Schema: `atlas.forge.rivals.evidence_pack.v2` (v1 fields preserved).
 */
final class AtlasForgeRivalsCollectEvidenceService
{
    public const SCHEMA_VERSION = 'atlas.forge.rivals.evidence_pack.v2';

    public const SCHEMA_VERSION_LEGACY = 'atlas.forge.rivals.evidence_pack.v1';

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

        $manifest = $this->readJson($paths['manifest_json']);
        $stage = AtlasForgeRivalsEvidencePolicy::normalizeStage((string) ($input['evidence_stage'] ?? ''));
        $plan = AtlasForgeRivalsEvidencePolicy::plan($stage, $manifest);

        $artifactPaths = [
            'manifest' => $paths['manifest_json'],
            'events_jsonl' => $paths['events_jsonl'],
            'intent_json' => $paths['base'].'/intent.json',
            'atlas_receipt' => $paths['evidence'].'/atlas_receipt.json',
            'rival_receipt' => $paths['evidence'].'/rival_receipt.json',
            'workspace_hashes' => $paths['evidence'].'/workspace_hashes.json',
            'atlas_patch' => $paths['evidence'].'/atlas_patch.diff',
            'rival_patch' => $paths['evidence'].'/rival_patch.diff',
            'atlas_test_log' => $paths['evidence'].'/atlas_test.log',
            'rival_test_log' => $paths['evidence'].'/rival_test.log',
            'scorecard' => $paths['scorecard_json'],
        ];
        $keys = AtlasForgeRivalsEvidencePolicy::keysForStage($plan['stage']);

        $artifacts = [];
        foreach ($keys as $key) {
            $path = $artifactPaths[$key] ?? null;
            if ($path === null) {
                continue;
            }
            $desc = $this->describeFile($path);
            $desc['policy'] = $plan['policy'][$key] ?? AtlasForgeRivalsEvidencePolicy::POLICY_OPTIONAL;
            $artifacts[$key] = $desc;
        }

        $missingRequired = [];
        foreach ($plan['required'] as $required) {
            if (! ($artifacts[$required]['present'] ?? false)) {
                $missingRequired[] = $required;
            }
        }
        $missingOptional = [];
        foreach ($plan['optional'] as $optional) {
            if (! ($artifacts[$optional]['present'] ?? false)) {
                $missingOptional[] = $optional;
            }
        }

        // Legacy v1 field: `missing_evidence` is the list of `missing_evidence:<key>`
        // strings (the format the adjudicator's `evidence_complete` hard gate
        // currently consumes).
        $missingEvidence = array_map(
            static fn (string $k): string => 'missing_evidence:'.$k,
            $missingRequired,
        );

        $verdict = (string) ($manifest['verdict'] ?? 'unknown');
        $claimReady = (bool) ($manifest['claim_ready'] ?? false);
        if (str_starts_with($verdict, 'invalid') || $missingRequired !== []) {
            $claimReady = false;
        }

        $pack = [
            'schema_version' => self::SCHEMA_VERSION,
            'evidence_stage' => $plan['stage'],
            'run_id' => $paths['run_id'],
            'collected_at' => now()->toJSON(),
            'paths' => $paths,
            'artifacts' => $artifacts,
            'required_artifacts' => $plan['required'],
            'optional_artifacts' => $plan['optional'],
            'artifact_policy' => $plan['policy'],
            'missing_required' => $missingRequired,
            'missing_optional' => $missingOptional,
            'missing_evidence' => $missingEvidence,
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
            'is_comparable_real_run' => $plan['is_comparable_real_run'],
            'external_provider_call' => (bool) ($manifest['external_provider_call'] ?? false),
            'provider_tokens_spent' => (bool) ($manifest['provider_tokens_spent'] ?? false),
            'separated_from_external_rivals_certification' => true,
        ];

        @mkdir($paths['evidence'], 0o755, true);
        file_put_contents(
            $paths['evidence'].'/evidence_pack.json',
            (string) json_encode($pack, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE)
        );

        return [
            'status' => $missingRequired === [] ? 'ok' : 'blocked',
            'run_id' => $paths['run_id'],
            'evidence_stage' => $plan['stage'],
            'evidence_pack' => $pack,
            'blockers' => $missingEvidence,
            'missing_required' => $missingRequired,
            'missing_optional' => $missingOptional,
            'evidence_paths' => array_values(array_filter(array_map(
                static fn (array $a): ?string => ($a['present'] ?? false) ? (string) $a['path'] : null,
                $artifacts,
            ))),
            'next_command' => $missingRequired === []
                ? 'php artisan atlas:forge:rivals replay --run-id='.$paths['run_id'].' --stage='.$plan['stage'].' --json'
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
