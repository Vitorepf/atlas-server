<?php

declare(strict_types=1);

namespace App\Services\Ai\Programming\ForgeRivals;

/**
 * Atlas Forge Rivals · Premium Report.
 *
 * Renders the executive-grade `report.md` for a run by combining the
 * evidence manifest, the replay outcome, and the adjudication scorecard.
 *
 * Refuses to declare a quality winner unless ALL of these are true:
 *   - manifest.verdict === 'comparable'
 *   - replay.replay_passes === true
 *   - scorecard.hard_failures is empty
 *   - scorecard.winner is one of {atlas, rival, human_review_required_tie}
 *
 * One-sided test failures may declare `gate_winner` when the adjudicator marks
 * `score_source=gate_outcome`, but `winner=null`, scores remain null, and
 * `claim_ready=false` remains absolute. Other hard-gate failures force
 * `winner=null`, `score=null`, `claim_ready=false`, and a "ZERO claim" line.
 * Tie outcomes force `human_review_required=true` and surface a checklist for
 * the operator.
 *
 * Read-only. Never invokes provider. NEVER unlocks
 * `external_rivals_certification`.
 */
final class AtlasForgeRivalsReportService
{
    public const SCHEMA_VERSION = 'atlas.forge.rivals.report.v2';

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
        $atlasReceipt = $this->readJson($paths['evidence'].'/atlas_receipt.json');
        $rivalReceipt = $this->readJson($paths['evidence'].'/rival_receipt.json');
        $scorecard = $this->readJson($paths['scorecard_json']);

        $hardFailures = is_array($scorecard) ? (array) ($scorecard['hard_failures'] ?? []) : [];
        $scoreCardWinner = is_array($scorecard) ? ($scorecard['winner'] ?? null) : null;
        $gateWinner = is_array($scorecard) ? ($scorecard['gate_winner'] ?? null) : null;
        $atlasScore = is_array($scorecard) ? ($scorecard['atlas_score'] ?? null) : null;
        $rivalScore = is_array($scorecard) ? ($scorecard['rival_score'] ?? null) : null;
        $threshold = is_array($scorecard) ? ($scorecard['tie_threshold'] ?? AtlasForgeRivalsAdjudicatorService::DEFAULT_TIE_THRESHOLD) : AtlasForgeRivalsAdjudicatorService::DEFAULT_TIE_THRESHOLD;
        $qualityDimensions = is_array($scorecard) ? ($scorecard['quality_dimensions'] ?? null) : null;
        $hardGates = is_array($scorecard) ? (array) ($scorecard['hard_gates'] ?? []) : [];
        $scoreSource = is_array($scorecard) ? (string) ($scorecard['score_source'] ?? '') : '';
        $gateOutcomeAvailable = $scoreSource === 'gate_outcome'
            && $replayOk
            && in_array($gateWinner, [AtlasForgeRivalsAdjudicatorService::WINNER_ATLAS, AtlasForgeRivalsAdjudicatorService::WINNER_RIVAL], true);

        $winner = null;
        $declaredWhy = null;
        $humanReviewRequired = false;
        $claimReady = false;
        $adjudicationMissing = ! is_array($scorecard) || $scorecard === [];

        if ($gateOutcomeAvailable) {
            $declaredWhy = 'gate_winner:'.$gateWinner.'_no_quality_score';
            $claimReady = false;
        } elseif (str_starts_with($verdict, 'invalid')) {
            $declaredWhy = 'invalid:'.$verdict;
        } elseif (! $replayOk) {
            $declaredWhy = 'replay_failed';
        } elseif ($adjudicationMissing) {
            $declaredWhy = 'adjudication_missing';
        } elseif ($hardFailures !== []) {
            $declaredWhy = 'hard_failures:'.implode(',', array_map(static fn ($f): string => (string) $f, $hardFailures));
        } elseif ($scoreCardWinner === AtlasForgeRivalsAdjudicatorService::WINNER_TIE) {
            $winner = AtlasForgeRivalsAdjudicatorService::WINNER_TIE;
            $declaredWhy = 'statistical_tie_human_review_required';
            $humanReviewRequired = true;
        } elseif (in_array($scoreCardWinner, [AtlasForgeRivalsAdjudicatorService::WINNER_ATLAS, AtlasForgeRivalsAdjudicatorService::WINNER_RIVAL], true)) {
            $winner = $scoreCardWinner;
            $declaredWhy = 'quality_winner:'.$winner;
            $claimReady = true;
        } else {
            $declaredWhy = 'unknown_scorecard_state';
        }

        $reportMd = $this->renderMarkdown(
            runId: $paths['run_id'],
            manifest: $manifest,
            replay: $replayReport,
            scorecard: $scorecard,
            atlasReceipt: $atlasReceipt,
            rivalReceipt: $rivalReceipt,
            winner: $winner,
            declaredWhy: $declaredWhy,
            humanReviewRequired: $humanReviewRequired,
            claimReady: $claimReady,
            paths: $paths,
        );
        @mkdir($paths['evidence'], 0o755, true);
        file_put_contents($paths['report_md'], $reportMd);

        $artifacts = array_values(array_filter([
            'manifest' => $paths['manifest_json'],
            'events_jsonl' => $paths['events_jsonl'],
            'atlas_receipt' => $paths['evidence'].'/atlas_receipt.json',
            'rival_receipt' => $paths['evidence'].'/rival_receipt.json',
            'workspace_hashes' => $paths['evidence'].'/workspace_hashes.json',
            'atlas_patch' => $paths['evidence'].'/atlas_patch.diff',
            'rival_patch' => $paths['evidence'].'/rival_patch.diff',
            'atlas_test_log' => $paths['evidence'].'/atlas_test.log',
            'rival_test_log' => $paths['evidence'].'/rival_test.log',
            'scorecard' => $paths['scorecard_json'],
            'report' => $paths['report_md'],
        ], static fn (string $p): bool => is_file($p)));

        return [
            'status' => 'ok',
            'schema_version' => self::SCHEMA_VERSION,
            'run_id' => $paths['run_id'],
            'verdict' => $verdict,
            'winner' => $winner,
            'gate_winner' => $gateWinner,
            'gate_result' => is_array($scorecard) ? ($scorecard['gate_result'] ?? null) : null,
            'winner_reason' => is_array($scorecard) ? ($scorecard['winner_reason'] ?? []) : [],
            'atlas_score' => $atlasScore,
            'rival_score' => $rivalScore,
            'threshold' => $threshold,
            'hard_failures' => $hardFailures,
            'human_review_required' => $humanReviewRequired,
            'claim_ready' => $claimReady,
            'replay_passes' => $replayOk,
            'declared_why' => $declaredWhy,
            'quality_dimensions' => $qualityDimensions,
            'hard_gates' => $hardGates,
            'report_path' => $paths['report_md'],
            'scorecard_path' => $paths['scorecard_json'],
            'artifacts' => $artifacts,
            'evidence_paths' => $artifacts,
            'external_provider_call' => false,
            'separated_from_external_rivals_certification' => true,
            'unlocks_external_rivals_certification' => false,
            'score_source' => $scoreSource,
            'quality_score_available' => (bool) (is_array($scorecard) ? ($scorecard['quality_score_available'] ?? ($qualityDimensions !== null)) : false),
            'quality_score_reason' => is_array($scorecard) ? ($scorecard['quality_score_reason'] ?? null) : null,
            'note' => 'Premium report — evidence/replay/infrastructure invalid ⇒ winner=null. One-sided test failure may produce gate_winner only, with score=null and claim_ready=false. external_rivals_certification stays BLOCKED.',
            'next_command' => 'php artisan atlas:forge:rivals reset --run-id='.$paths['run_id'].' --reason=<text> --json',
        ];
    }

    /**
     * @param  array<string,mixed>  $manifest
     * @param  array<string,mixed>  $replay
     * @param  array<string,mixed>  $scorecard
     * @param  array<string,mixed>  $atlasReceipt
     * @param  array<string,mixed>  $rivalReceipt
     * @param  array<string,mixed>  $paths
     */
    private function renderMarkdown(
        string $runId,
        array $manifest,
        array $replay,
        array $scorecard,
        array $atlasReceipt,
        array $rivalReceipt,
        ?string $winner,
        ?string $declaredWhy,
        bool $humanReviewRequired,
        bool $claimReady,
        array $paths,
    ): string {
        $verdict = (string) ($manifest['verdict'] ?? 'unknown');
        $isInvalid = str_starts_with($verdict, 'invalid');
        $replayOk = (bool) ($replay['replay_passes'] ?? false);
        $hardFailures = (array) ($scorecard['hard_failures'] ?? []);
        $atlasScore = $scorecard['atlas_score'] ?? null;
        $rivalScore = $scorecard['rival_score'] ?? null;
        $threshold = $scorecard['tie_threshold'] ?? AtlasForgeRivalsAdjudicatorService::DEFAULT_TIE_THRESHOLD;
        $winnerReason = (array) ($scorecard['winner_reason'] ?? []);
        $dimensions = is_array($scorecard['quality_dimensions'] ?? null) ? $scorecard['quality_dimensions'] : [];
        $hardGates = (array) ($scorecard['hard_gates'] ?? []);
        $scoreSource = (string) ($scorecard['score_source'] ?? '');
        $gateWinner = $scorecard['gate_winner'] ?? null;
        $gateOutcome = $scoreSource === 'gate_outcome'
            && in_array($gateWinner, [AtlasForgeRivalsAdjudicatorService::WINNER_ATLAS, AtlasForgeRivalsAdjudicatorService::WINNER_RIVAL], true);

        $summaryLine = match (true) {
            $gateOutcome => '**Verdict:** GATE WINNER = '.($gateWinner === AtlasForgeRivalsAdjudicatorService::WINNER_ATLAS ? 'Atlas Forge' : 'Rival baseline').' · QUALITY SCORE = N/A · ZERO external claim',
            $isInvalid => '**Verdict:** INVALID · `'.$verdict.'` · ZERO claim · score=null',
            ! $replayOk => '**Verdict:** REPLAY FAILED · evidence pack untrustworthy · ZERO claim',
            $hardFailures !== [] => '**Verdict:** HARD-FAIL · '.count($hardFailures).' gate(s) failed · ZERO claim',
            $winner === AtlasForgeRivalsAdjudicatorService::WINNER_TIE => '**Verdict:** TIE · human review required',
            $winner === AtlasForgeRivalsAdjudicatorService::WINNER_ATLAS => '**Verdict:** WINNER = Atlas Forge',
            $winner === AtlasForgeRivalsAdjudicatorService::WINNER_RIVAL => '**Verdict:** WINNER = Rival baseline',
            default => '**Verdict:** UNKNOWN · '.($declaredWhy ?? 'no_reason'),
        };

        $hardGatesTable = $this->renderHardGatesTable($hardGates);
        $qualityTable = $this->renderQualityTable($dimensions);
        $patchCompareTable = $this->renderPatchCompareTable($atlasReceipt, $rivalReceipt);
        $testCompareTable = $this->renderTestCompareTable($atlasReceipt, $rivalReceipt);
        $costCompareTable = $this->renderCostCompareTable($atlasReceipt, $rivalReceipt);
        $winnerReasonBullets = $winnerReason === []
            ? '_(none reported)_'
            : implode("\n", array_map(static fn ($r): string => '- '.(string) $r, $winnerReason));
        $humanChecklist = $this->renderHumanChecklist($humanReviewRequired, $hardFailures, $winner, $isInvalid, $replayOk);
        $artifactsList = $this->renderArtifactsList($paths);

        $scoreLine = $atlasScore === null
            ? 'atlas_score: **null** · rival_score: **null** · threshold: '.$threshold
            : sprintf(
                'atlas_score: **%s** · rival_score: **%s** · threshold: %s · diff: %s',
                (string) $atlasScore,
                (string) $rivalScore,
                (string) $threshold,
                (string) (($atlasScore !== null && $rivalScore !== null) ? round((float) $atlasScore - (float) $rivalScore, 2) : 'null'),
            );

        $claimLine = $gateOutcome
            ? 'claim_ready: **false** · gate_winner: **'.$gateWinner.'** · quality_score: **null** · external claim blocked'
            : ($claimReady
            ? 'claim_ready: **true** · winner: **'.$winner.'**'
            : 'claim_ready: **false** · ZERO claim');

        $declaredWhyLine = $declaredWhy !== null ? '`'.$declaredWhy.'`' : '`unknown`';

        $mode = (string) ($manifest['mode'] ?? 'unknown');
        $preset = (string) ($manifest['preset'] ?? 'unknown');
        $atlasModel = (string) ($manifest['atlas_model'] ?? 'unknown');
        $rivalModel = (string) ($manifest['rival_model'] ?? 'unknown');
        $caseId = (string) ($manifest['case_id'] ?? 'unknown');
        $atlasReceiptHash = (string) ($manifest['atlas_receipt_hash'] ?? '');
        $rivalReceiptHash = (string) ($manifest['rival_receipt_hash'] ?? '');
        $wsBefore = is_array($manifest['workspace_hash_before'] ?? null) ? $manifest['workspace_hash_before'] : [];
        $wsAfter = is_array($manifest['workspace_hash_after'] ?? null) ? $manifest['workspace_hash_after'] : [];
        $wsBeforeAtlas = (string) ($wsBefore['atlas'] ?? '');
        $wsAfterAtlas = (string) ($wsAfter['atlas'] ?? '');
        $wsBeforeRival = (string) ($wsBefore['rival'] ?? '');
        $wsAfterRival = (string) ($wsAfter['rival'] ?? '');

        return <<<MD
# Atlas Forge Rivals · Premium Battery Report

{$summaryLine}

## Executive Summary

- **Run id:** `{$runId}`
- **Mode:** {$mode}
- **Preset:** {$preset}
- **Atlas model:** {$atlasModel}
- **Rival model:** {$rivalModel}
- **Case:** {$caseId}
- {$scoreLine}
- gate_winner: **{$this->nullable($gateWinner)}**
- replay_passes: **{$this->bool($replayOk)}**
- {$claimLine}
- declared_why: {$declaredWhyLine}

## Why this outcome

{$winnerReasonBullets}

## Hard Gates

{$hardGatesTable}

## Quality Dimensions

{$qualityTable}

## Patch Comparison

{$patchCompareTable}

## Test Comparison

{$testCompareTable}

## Cost / Time / Provider Usage

{$costCompareTable}

## Evidence Integrity

- manifest: `evidence/manifest.json`
- events: `events.jsonl`
- atlas_receipt: `evidence/atlas_receipt.json` · sha256 `{$atlasReceiptHash}`
- rival_receipt: `evidence/rival_receipt.json` · sha256 `{$rivalReceiptHash}`
- workspace_before_atlas: `{$wsBeforeAtlas}`
- workspace_after_atlas: `{$wsAfterAtlas}`
- workspace_before_rival: `{$wsBeforeRival}`
- workspace_after_rival: `{$wsAfterRival}`
- scorecard: `evidence/scorecard.json`

## Replay Status

- replay_passes: **{$this->bool($replayOk)}**
- mismatches: {$this->renderInlineList((array) ($replay['mismatches'] ?? []))}
- event_count: {$this->intOrNull($replay['event_count'] ?? null)}

## Human Review Checklist

{$humanChecklist}

## Artifacts

{$artifactsList}

## Canon

- `separated_from_external_rivals_certification` ⇒ **true**
- **This report does NOT unlock `external_rivals_certification`.** External rivals claim remains operator-approval-gated, separately tracked.
- Adjudicator is deterministic and local-only. No LLM judged this run.
- Invalid evidence/replay/scope ⇒ ZERO claim, score=null. One-sided deterministic test failure ⇒ gate_winner only, quality score=null, claim_ready=false.

MD;
    }

    /**
     * @param  array<int,mixed>  $hardGates
     */
    private function renderHardGatesTable(array $hardGates): string
    {
        if ($hardGates === []) {
            return '_(scorecard missing — run `atlas:forge:rivals adjudicate --run-id=<id>` first)_';
        }
        $rows = ['| Gate | Status | Detail |', '| --- | --- | --- |'];
        foreach ($hardGates as $row) {
            if (! is_array($row)) {
                continue;
            }
            $code = (string) ($row['code'] ?? 'unknown');
            $ok = ! empty($row['ok']);
            $detail = (string) ($row['detail'] ?? '');
            $rows[] = '| `'.$code.'` | '.($ok ? 'OK' : 'FAIL').' | '.$detail.' |';
        }

        return implode("\n", $rows);
    }

    /**
     * @param  array<string,array<string,mixed>>  $dimensions
     */
    private function renderQualityTable(array $dimensions): string
    {
        if ($dimensions === []) {
            return '_(no quality scoring — hard gate failed or adjudication missing)_';
        }
        $rows = ['| Dimension | Atlas | Rival | Diff | Explanation |', '| --- | ---: | ---: | ---: | --- |'];
        foreach ($dimensions as $name => $d) {
            $atlas = (float) ($d['atlas'] ?? 0);
            $rival = (float) ($d['rival'] ?? 0);
            $diff = round($atlas - $rival, 1);
            $rows[] = sprintf(
                '| %s | %s | %s | %s | %s |',
                (string) $name,
                number_format($atlas, 1),
                number_format($rival, 1),
                ($diff >= 0 ? '+' : '').number_format($diff, 1),
                (string) ($d['explanation'] ?? ''),
            );
        }

        return implode("\n", $rows);
    }

    /**
     * @param  array<string,mixed>  $atlas
     * @param  array<string,mixed>  $rival
     */
    private function renderPatchCompareTable(array $atlas, array $rival): string
    {
        $rows = ['| Metric | Atlas | Rival |', '| --- | --- | --- |'];
        $rows[] = '| patch_diff_bytes | '.(int) ($atlas['patch_diff_bytes'] ?? 0).' | '.(int) ($rival['patch_diff_bytes'] ?? 0).' |';
        $rows[] = '| changed_files | '.count((array) ($atlas['changed_files'] ?? [])).' | '.count((array) ($rival['changed_files'] ?? [])).' |';
        $rows[] = '| out_of_scope_files | '.count((array) ($atlas['out_of_scope_files'] ?? [])).' | '.count((array) ($rival['out_of_scope_files'] ?? [])).' |';
        $rows[] = '| bytecode_artifacts | '.count((array) ($atlas['bytecode_artifacts'] ?? [])).' | '.count((array) ($rival['bytecode_artifacts'] ?? [])).' |';
        $rows[] = '| patch_diff_sha256 | `'.(string) ($atlas['patch_diff_hash'] ?? '').'` | `'.(string) ($rival['patch_diff_hash'] ?? '').'` |';

        return implode("\n", $rows);
    }

    /**
     * @param  array<string,mixed>  $atlas
     * @param  array<string,mixed>  $rival
     */
    private function renderTestCompareTable(array $atlas, array $rival): string
    {
        $rows = ['| Metric | Atlas | Rival |', '| --- | --- | --- |'];
        $rows[] = '| test_command | `'.(string) ($atlas['test_command'] ?? '').'` | `'.(string) ($rival['test_command'] ?? '').'` |';
        $rows[] = '| test_exit_code | '.(int) ($atlas['test_exit_code'] ?? -1).' | '.(int) ($rival['test_exit_code'] ?? -1).' |';
        $rows[] = '| test_log_path | `'.(string) ($atlas['test_log_path'] ?? '').'` | `'.(string) ($rival['test_log_path'] ?? '').'` |';
        $rows[] = '| test_log_sha256 | `'.(string) ($atlas['test_log_hash'] ?? '').'` | `'.(string) ($rival['test_log_hash'] ?? '').'` |';

        return implode("\n", $rows);
    }

    /**
     * @param  array<string,mixed>  $atlas
     * @param  array<string,mixed>  $rival
     */
    private function renderCostCompareTable(array $atlas, array $rival): string
    {
        $rows = ['| Metric | Atlas | Rival |', '| --- | --- | --- |'];
        $rows[] = '| started_at | '.(string) ($atlas['started_at'] ?? '').' | '.(string) ($rival['started_at'] ?? '').' |';
        $rows[] = '| finished_at | '.(string) ($atlas['finished_at'] ?? '').' | '.(string) ($rival['finished_at'] ?? '').' |';
        $rows[] = '| exit_code | '.(int) ($atlas['exit_code'] ?? -1).' | '.(int) ($rival['exit_code'] ?? -1).' |';
        $rows[] = '| killed | '.($atlas['killed'] ?? false ? 'true' : 'false').' | '.($rival['killed'] ?? false ? 'true' : 'false').' |';
        $rows[] = '| stdout_bytes | '.(int) ($atlas['stdout_bytes'] ?? 0).' | '.(int) ($rival['stdout_bytes'] ?? 0).' |';
        $rows[] = '| stderr_bytes | '.(int) ($atlas['stderr_bytes'] ?? 0).' | '.(int) ($rival['stderr_bytes'] ?? 0).' |';
        $rows[] = '| tokens_used | '.(string) ($atlas['tokens_used'] ?? 'null').' | '.(string) ($rival['tokens_used'] ?? 'null').' |';
        $rows[] = '| token_cost | '.(string) ($atlas['token_cost'] ?? 'null').' | '.(string) ($rival['token_cost'] ?? 'null').' |';

        return implode("\n", $rows);
    }

    /**
     * @param  list<string>  $hardFailures
     */
    private function renderHumanChecklist(
        bool $humanReviewRequired,
        array $hardFailures,
        ?string $winner,
        bool $isInvalid,
        bool $replayOk,
    ): string {
        $items = [];
        if ($isInvalid) {
            $items[] = '- [ ] Investigate `invalid_*` verdict in the manifest before doing anything else.';
            $items[] = '- [ ] Treat the run as ZERO claim until the invalidation cause is resolved.';
        } elseif (! $replayOk) {
            $items[] = '- [ ] Replay failed — evidence pack is no longer trustworthy.';
            $items[] = '- [ ] Re-run `atlas:forge:rivals run-real` rather than trusting partial evidence.';
        } elseif ($hardFailures !== []) {
            foreach ($hardFailures as $f) {
                $items[] = '- [ ] Fix hard-fail gate `'.(string) $f.'` before claiming.';
            }
        } elseif ($humanReviewRequired) {
            $items[] = '- [ ] Inspect `evidence/atlas_patch.diff` vs `evidence/rival_patch.diff`.';
            $items[] = '- [ ] Read `evidence/atlas_test.log` vs `evidence/rival_test.log`.';
            $items[] = '- [ ] Decide which patch is qualitatively better — adjudicator only saw heuristics.';
            $items[] = '- [ ] If both patches look equivalent, accept the tie outcome — do not force a winner.';
            $items[] = '- [ ] Never elevate a tie into a claim without operator signoff.';
        } else {
            $items[] = '- [x] Adjudicator returned a quality-determined winner ('.(string) $winner.').';
            $items[] = '- [ ] Confirm `replay_passes=true` and `dirty_after_run=false`.';
            $items[] = '- [ ] Inspect the diff for surprises before broadcasting the result.';
        }

        return implode("\n", $items);
    }

    /**
     * @param  array<string,mixed>  $paths
     */
    private function renderArtifactsList(array $paths): string
    {
        $candidates = [
            'events_jsonl' => $paths['events_jsonl'] ?? '',
            'manifest_json' => $paths['manifest_json'] ?? '',
            'atlas_receipt' => ($paths['evidence'] ?? '').'/atlas_receipt.json',
            'rival_receipt' => ($paths['evidence'] ?? '').'/rival_receipt.json',
            'workspace_hashes' => ($paths['evidence'] ?? '').'/workspace_hashes.json',
            'atlas_patch' => ($paths['evidence'] ?? '').'/atlas_patch.diff',
            'rival_patch' => ($paths['evidence'] ?? '').'/rival_patch.diff',
            'atlas_test_log' => ($paths['evidence'] ?? '').'/atlas_test.log',
            'rival_test_log' => ($paths['evidence'] ?? '').'/rival_test.log',
            'scorecard_json' => $paths['scorecard_json'] ?? '',
            'report_md' => $paths['report_md'] ?? '',
        ];
        $rows = [];
        foreach ($candidates as $label => $path) {
            $present = is_string($path) && $path !== '' && is_file($path);
            $rows[] = '- '.$label.': `'.$path.'` '.($present ? '· present' : '· absent');
        }

        return implode("\n", $rows);
    }

    /**
     * @param  array<int,mixed>  $list
     */
    private function renderInlineList(array $list): string
    {
        if ($list === []) {
            return '_none_';
        }

        return '`'.implode('`, `', array_map(static fn ($v): string => (string) $v, $list)).'`';
    }

    private function intOrNull(mixed $v): string
    {
        return is_int($v) ? (string) $v : 'null';
    }

    private function nullable(mixed $v): string
    {
        return $v === null || $v === '' ? 'null' : (string) $v;
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
