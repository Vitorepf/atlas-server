<?php

declare(strict_types=1);

namespace App\Services\Ai\Programming\ForgeRivals;

/**
 * Atlas Forge Rivals · Report.
 *
 * Renders the human-readable report.md from the evidence pack and the
 * replay outcome. Refuses to declare a winner unless replay passed AND
 * verdict is `comparable` AND claim_ready is true. Invalid verdicts
 * always print `score=null, claim_ready=false, ZERO claim`.
 *
 * Read-only. Never invokes provider.
 */
final class AtlasForgeRivalsReportService
{
    public const SCHEMA_VERSION = 'atlas.forge.rivals.report.v1';

    public function __construct(
        private readonly AtlasForgeRivalsRunPathResolver $paths,
        private readonly AtlasForgeRivalsReplayService $replay,
    ) {}

    /**
     * @param  array<string,mixed>  $input
     * @return array<string,mixed>
     */
    public function render(array $input): array
    {
        $runId = trim((string) ($input['run_id'] ?? ''));
        if ($runId === '') {
            return [
                'status' => 'blocked',
                'blockers' => ['run_id_required'],
                'next_command' => 'php artisan atlas:forge:rivals report --run-id=<id> --json',
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
        if ($manifest === []) {
            return [
                'status' => 'blocked',
                'blockers' => ['manifest_missing'],
                'next_command' => '',
            ];
        }

        $replayReport = $this->replay->replay(['run_id' => $paths['run_id']]);
        $replayOk = (bool) ($replayReport['replay_passes'] ?? false);
        $verdict = (string) ($manifest['verdict'] ?? 'unknown');

        $winner = null;
        $declaredWhy = null;
        if ($verdict === 'comparable' && $replayOk && (bool) ($manifest['claim_ready'] ?? false)) {
            // Comparable score determination would happen here (Slice 6 expands).
            // For Slice-3..-4 the comparable_score is null pending real signals;
            // we never declare a winner without a non-null comparable_score.
            $winner = null;
            $declaredWhy = 'comparable_score_pending';
        } elseif (str_starts_with($verdict, 'invalid')) {
            $declaredWhy = 'invalid:'.$verdict;
        } elseif (! $replayOk) {
            $declaredWhy = 'replay_failed';
        } else {
            $declaredWhy = $verdict;
        }

        $reportMd = $this->renderMarkdown($paths['run_id'], $manifest, $replayReport, $winner, $declaredWhy);
        @mkdir($paths['evidence'], 0o755, true);
        file_put_contents($paths['report_md'], $reportMd);

        return [
            'status' => 'ok',
            'schema_version' => self::SCHEMA_VERSION,
            'run_id' => $paths['run_id'],
            'verdict' => $verdict,
            'winner' => $winner,
            'score' => $manifest['score'] ?? null,
            'claim_ready' => $winner !== null && $verdict === 'comparable',
            'declared_why' => $declaredWhy,
            'replay_passes' => $replayOk,
            'report_path' => $paths['report_md'],
            'evidence_paths' => [$paths['report_md']],
            'external_provider_call' => false,
            'note' => 'Report follows the canon: invalid ⇒ ZERO claim, score=null; replay-failed ⇒ no winner.',
            'next_command' => 'php artisan atlas:forge:rivals reset --run-id='.$paths['run_id'].' --reason=<text> --json',
        ];
    }

    private function renderMarkdown(string $runId, array $manifest, array $replay, ?string $winner, ?string $declaredWhy): string
    {
        $verdict = (string) ($manifest['verdict'] ?? 'unknown');
        $isInvalid = str_starts_with($verdict, 'invalid');
        $score = $isInvalid ? 'null' : (string) ($manifest['score']['comparable_score'] ?? 'null');
        $claimLine = $winner !== null && $verdict === 'comparable'
            ? "claim_ready: **true** · winner: **{$winner}**"
            : "claim_ready: **false** · ZERO claim (invalid ⇒ score=null)";

        return <<<MD
# Atlas Forge Rivals · Run Report

**Run id:** `{$runId}`
**Mode:** {$manifest['mode']}
**Preset:** {$manifest['preset']}
**Atlas model:** {$manifest['atlas_model']}
**Rival model:** {$manifest['rival_model']}
**Case:** {$manifest['case_id']}

## Verdict

- verdict: **{$verdict}**
- comparable_score: {$score}
- replay_passes: **{$this->bool($replay['replay_passes'] ?? false)}**
- {$claimLine}
- declared_why: `{$declaredWhy}`

## Evidence

- manifest: `evidence/manifest.json`
- events: `events.jsonl`
- atlas_receipt: `evidence/atlas_receipt.json`
- rival_receipt: `evidence/rival_receipt.json`
- workspace_hashes: `evidence/workspace_hashes.json`
- evidence_pack: `evidence/evidence_pack.json`

## Hashes

- atlas_receipt_hash: `{$manifest['atlas_receipt_hash']}`
- rival_receipt_hash: `{$manifest['rival_receipt_hash']}`
- workspace_before_atlas: `{$manifest['workspace_hash_before']['atlas']}`
- workspace_before_rival: `{$manifest['workspace_hash_before']['rival']}`
- workspace_after_atlas: `{$manifest['workspace_hash_after']['atlas']}`
- workspace_after_rival: `{$manifest['workspace_hash_after']['rival']}`

## Canon

- `separated_from_external_rivals_certification` ⇒ **true**
- external_rivals_certification remains BLOCKED — this report never unlocks it.

MD;
    }

    private function bool(bool $b): string
    {
        return $b ? 'true' : 'false';
    }

    /**
     * @return array<string,mixed>
     */
    private function readJson(string $path): array
    {
        if (! is_file($path)) {
            return [];
        }
        $row = json_decode((string) @file_get_contents($path), true);

        return is_array($row) ? $row : [];
    }
}
