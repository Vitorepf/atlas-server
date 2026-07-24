<?php

declare(strict_types=1);

namespace App\Services\Ai\Programming;

use App\Services\Ai\AutonomousEvolution\AtlasLoopMorningDigestService;
use App\Services\Ai\Context\AobgSemanticRetrievalLiftService;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Artisan;
use Symfony\Component\Console\Output\BufferedOutput;
use Throwable;

/**
 * L4-13: final Fable N x M report and cold-session handoff packet.
 *
 * This is not a second reporting architecture. It composes the existing resolved
 * evidence commands/read-models from L3/L4 and freezes the handoff contract that
 * lets a fresh GPT-5.5/M3 session continue without re-deriving the campaign.
 */
final class AtlasFableFinalReportService
{
    use ProgrammingJsonHelper;

    public const SCHEMA_VERSION = 'atlas.fable.l4_13.final_report.v1';

    public const PACKET_SCHEMA_VERSION = 'atlas.fable.l4_13.handoff_packet.v1';

    public const DEFAULT_REPORT_PATH = 'app/atlas/evidence/fable-l4-13-final-report.json';

    public const DEFAULT_PACKET_PATH = 'app/atlas/evidence/fable-l4-13-handoff-packet.json';

    /**
     * @param  array<string,mixed>  $options
     * @return array<string,mixed>
     */
    public function report(array $options = []): array
    {
        $baselinePath = $this->stringOrNull($options['baseline_path'] ?? null)
            ?? storage_path('app/atlas/evidence/marco-zero-fable-2026-06-11.json');
        $seriesPath = $this->stringOrNull($options['series_path'] ?? null)
            ?? storage_path('app/atlas/evidence/acos-delta-series.jsonl');
        $date = $this->stringOrNull($options['date'] ?? null) ?? Carbon::now()->toDateString();
        $hours = max(1, min(168, (int) ($options['hours'] ?? 24)));

        $delta = $this->runJson('atlas:fable:delta', [
            '--baseline' => $baselinePath,
            '--json' => true,
        ]);
        $series = $this->runJson('atlas:fable:delta-series', [
            '--baseline' => $baselinePath,
            '--series' => $seriesPath,
            '--date' => $date,
            '--report' => true,
            '--json' => true,
        ]);
        $captureQuality = $this->runJson('atlas:ai:capture-quality-audit', [
            '--days' => max(1, (int) ($options['capture_days'] ?? 7)),
            '--json' => true,
        ]);

        $digest = app(AtlasLoopMorningDigestService::class)->digest($hours);
        $devBeat = app(AtlasDevBeatTestReportService::class)->report([
            'evidence_path' => $this->stringOrNull($options['dev_beat_evidence_path'] ?? null),
            'write_backlog' => false,
        ]);
        $forgeProof = app(AtlasForgeMultiNodeL410ProofService::class)->report([
            'evidence_path' => $this->stringOrNull($options['forge_evidence_path'] ?? null),
            'hours' => $hours,
        ]);
        $semanticLift = app(AobgSemanticRetrievalLiftService::class)->report();

        $packet = $this->handoffPacket([
            'baseline_path' => $baselinePath,
            'series_path' => $seriesPath,
            'date' => $date,
            'delta' => $delta,
            'series' => $series,
            'capture_quality' => $captureQuality,
            'digest' => $digest,
            'dev_beat' => $devBeat,
            'forge_proof' => $forgeProof,
            'semantic_lift' => $semanticLift,
        ]);
        $packetVerification = $this->verifyPacketPayload($packet);

        $report = [
            'schema_version' => self::SCHEMA_VERSION,
            'status' => $this->status($delta, $series, $packetVerification),
            'generated_at' => Carbon::now()->toIso8601String(),
            'title' => 'Fable L4-13 final N x M report',
            'scope' => [
                'campaign' => 'fable-listas-4-5-6',
                'item' => 'L4-13',
                'claim' => 'resolved evidence final report plus tested cold-session handoff packet',
            ],
            'n_x_m' => $this->nxmSummary($delta, $series, $captureQuality, $digest, $semanticLift),
            'resolved_sources' => $this->resolvedSources($baselinePath, $seriesPath, $delta, $series, $captureQuality, $digest, $devBeat, $forgeProof, $semanticLift),
            'source_reports' => [
                'delta' => $delta,
                'delta_series' => $series,
                'capture_quality' => $captureQuality,
                'morning_digest' => $digest,
                'dev_beat_test' => $devBeat,
                'forge_l4_10_proof' => $forgeProof,
                'semantic_lift' => $semanticLift,
            ],
            'handoff_packet' => $packet,
            'cold_session_verification' => $packetVerification,
            'claim_policy' => [
                'read_only_sources_only' => true,
                'provider_dispatches_now' => false,
                'synthetic_completion_claim_allowed' => false,
                'l4_10_real_execution_claim_allowed' => (bool) data_get($forgeProof, 'certified', false),
                'l4_13_completion_claim_allowed' => ($packetVerification['status'] ?? null) === 'ready',
                'honesty_note' => 'L4-13 can close the report/packet even when L4-9/L4-10 external provider evidence remains operator-gated.',
            ],
        ];

        if ((bool) ($options['write_report'] ?? false)) {
            $reportPath = $this->stringOrNull($options['report_path'] ?? null) ?? storage_path(self::DEFAULT_REPORT_PATH);
            $this->writeJson($reportPath, $report);
            $report['written_report_path'] = $reportPath;
        }

        if ((bool) ($options['write_packet'] ?? false)) {
            $packetPath = $this->stringOrNull($options['packet_path'] ?? null) ?? storage_path(self::DEFAULT_PACKET_PATH);
            $this->writeJson($packetPath, $packet);
            $report['written_packet_path'] = $packetPath;
        }

        $verifyPath = $this->stringOrNull($options['verify_packet_path'] ?? null);
        if ($verifyPath !== null) {
            $report['verified_packet_from_path'] = $this->verifyPacketPath($verifyPath);
        }

        return $report;
    }

    /**
     * @return array<string,mixed>
     */
    public function verifyPacketPath(string $path): array
    {
        if (! is_file($path)) {
            return [
                'schema_version' => 'atlas.fable.l4_13.cold_session_verification.v1',
                'status' => 'blocked',
                'blockers' => ['packet_file_missing'],
                'path' => $path,
            ];
        }

        $decoded = json_decode((string) @file_get_contents($path), true);
        if (! is_array($decoded)) {
            return [
                'schema_version' => 'atlas.fable.l4_13.cold_session_verification.v1',
                'status' => 'blocked',
                'blockers' => ['packet_json_invalid'],
                'path' => $path,
            ];
        }

        return $this->verifyPacketPayload($decoded) + ['path' => $path];
    }

    /**
     * @param  array<string,mixed>  $packet
     * @return array<string,mixed>
     */
    public function verifyPacketPayload(array $packet): array
    {
        $blockers = [];
        if (($packet['schema_version'] ?? null) !== self::PACKET_SCHEMA_VERSION) {
            $blockers[] = 'schema_version_mismatch';
        }
        if (mb_strlen((string) ($packet['start_prompt'] ?? '')) < 120) {
            $blockers[] = 'start_prompt_too_short';
        }

        $files = array_values((array) data_get($packet, 'cold_session.required_read_files', []));
        foreach ([
            'docs/fable-lista-4-14-itens.md',
            'docs/fable-lista-5-14-itens.md',
            'docs/fable-lista-6-14-itens.md',
        ] as $requiredFile) {
            if (! in_array($requiredFile, $files, true)) {
                $blockers[] = 'missing_required_file:'.$requiredFile;
            }
        }

        $commands = implode("\n", array_values((array) data_get($packet, 'cold_session.required_commands', [])));
        foreach ([
            'atlas:fable:final-report --json',
            'atlas:fable:delta-series --report --json',
            'atlas:loop:morning-digest --json',
        ] as $requiredCommand) {
            if (! str_contains($commands, $requiredCommand)) {
                $blockers[] = 'missing_required_command:'.$requiredCommand;
            }
        }

        if ((string) data_get($packet, 'resume_state.next_item') === '') {
            $blockers[] = 'next_item_missing';
        }
        if (data_get($packet, 'claim_policy.provider_dispatches_now') !== false) {
            $blockers[] = 'provider_dispatch_not_false';
        }
        if ((string) ($packet['handoff_packet_hash'] ?? '') !== $this->packetHash($packet)) {
            $blockers[] = 'packet_hash_mismatch';
        }

        return [
            'schema_version' => 'atlas.fable.l4_13.cold_session_verification.v1',
            'status' => $blockers === [] ? 'ready' : 'blocked',
            'blockers' => $blockers,
            'checked_at' => Carbon::now()->toIso8601String(),
            'claim_policy' => [
                'provider_dispatches_now' => false,
                'cold_session_resume_claim_allowed' => $blockers === [],
            ],
        ];
    }

    /**
     * @param  array<string,mixed>  $context
     * @return array<string,mixed>
     */
    private function handoffPacket(array $context): array
    {
        $packet = [
            'schema_version' => self::PACKET_SCHEMA_VERSION,
            'status' => 'ready_for_cold_session',
            'generated_at' => Carbon::now()->toIso8601String(),
            'audience' => 'gpt-5.5/M3 cold session after Fable',
            'start_prompt' => $this->startPrompt(),
            'resume_state' => [
                'completed_through' => 'L4-13 report/packet harness',
                'next_item' => 'L4-14 final M capture',
                'then_continue' => ['Lista 5', 'Lista 6'],
                'operator_gated_truths' => $this->operatorGatedTruths($context),
            ],
            'cold_session' => [
                'required_read_files' => [
                    'AGENTS.md',
                    'docs/fable-listas-4-5-6-execution-prompt.md',
                    'docs/fable-lista-4-14-itens.md',
                    'docs/fable-lista-5-14-itens.md',
                    'docs/fable-lista-6-14-itens.md',
                    'docs/fable-campanha-11-dias-nxm.md',
                    'docs/fable-campanha-handoff-packet.md',
                ],
                'required_commands' => [
                    '/opt/homebrew/bin/php artisan atlas:context-pack "Fable Lista 4 L4-14 final M capture" --json',
                    '/opt/homebrew/bin/php artisan atlas:fable:final-report --json',
                    '/opt/homebrew/bin/php artisan atlas:fable:delta-series --report --json',
                    '/opt/homebrew/bin/php artisan atlas:loop:morning-digest --json',
                    '/opt/homebrew/bin/php artisan atlas:aobg:semantic-lift --json --strict',
                    '/opt/homebrew/bin/php artisan atlas:fable:final-report --verify-packet=<packet.json> --json --strict',
                ],
                'resume_rules' => [
                    'Use /opt/homebrew/bin/php for Artisan.',
                    'Run Atlas context/bootstrap before implementation.',
                    'Do not claim L4-9 or L4-10 external proof green without real provider receipts.',
                    'Continue the list order; do not skip L4-14 capture.',
                ],
            ],
            'evidence_summary' => [
                'baseline_path' => $context['baseline_path'],
                'series_path' => $context['series_path'],
                'delta_metrics' => data_get($context, 'delta.metrics', []),
                'delta_series_trend' => data_get($context, 'series.trend', []),
                'digest_headline' => (string) data_get($context, 'digest.headline', ''),
                'semantic_status' => (string) data_get($context, 'semantic_lift.status', 'unknown'),
                'dev_beat_status' => (string) data_get($context, 'dev_beat.status', 'unknown'),
                'forge_l4_10_status' => (string) data_get($context, 'forge_proof.status', 'unknown'),
                'capture_quality_waste_pct' => data_get($context, 'capture_quality.overall.waste_pct'),
            ],
            'guardrails' => [
                'resolved_sources_only',
                'provider_safe_packet',
                'no_provider_dispatch_by_report',
                'operator_decisions_remain_operator_decisions',
            ],
            'claim_policy' => [
                'provider_dispatches_now' => false,
                'raw_sensitive_context_included' => false,
                'packet_is_instruction_not_authorization' => true,
            ],
        ];
        $packet['handoff_packet_hash'] = $this->packetHash($packet);

        return $packet;
    }

    /**
     * @param  array<string,mixed>  $context
     * @return list<string>
     */
    private function operatorGatedTruths(array $context): array
    {
        $devBeatStatus = (string) data_get($context, 'dev_beat.status', 'unknown');
        $atlasPassed = (int) data_get($context, 'dev_beat.summary.atlas_dev_passed_count', 0);
        $atlasMissing = (int) data_get($context, 'dev_beat.summary.atlas_dev_missing_count', 0);
        $comparableExternal = (int) data_get($context, 'dev_beat.summary.comparable_external_count', 0);
        $atlasWinCount = (int) data_get($context, 'dev_beat.summary.atlas_win_count', 0);
        $externalWinCount = (int) data_get($context, 'dev_beat.summary.external_win_count', 0);
        $externalComparisonAllowed = (bool) data_get($context, 'dev_beat.claim_policy.external_comparison_claim_allowed', false);
        $externalSuperiorityAllowed = (bool) data_get($context, 'dev_beat.claim_policy.external_superiority_claim_allowed', false);

        $l49Truth = 'L4-9 Atlas Dev/external comparison evidence is still pending';
        if ($devBeatStatus === 'external_claim_blocked' && $atlasPassed >= 3 && $atlasMissing === 0 && $comparableExternal === 0) {
            $l49Truth = 'L4-9 Atlas Dev internal 3/3 evidence is collected; external comparison/benchmark receipt is still pending';
        } elseif ($devBeatStatus === 'comparable_report_ready_no_superiority' && $externalComparisonAllowed && ! $externalSuperiorityAllowed) {
            $l49Truth = sprintf(
                'L4-9 comparable external evidence exists, but Atlas Dev has no superiority claim yet (%d Atlas wins / %d external wins)',
                $atlasWinCount,
                $externalWinCount,
            );
        } elseif ($devBeatStatus === 'atlas_dev_beats_baseline' && $externalComparisonAllowed && $externalSuperiorityAllowed) {
            $l49Truth = 'L4-9 Atlas Dev external superiority claim is backed by comparable receipts';
        }

        return [
            $l49Truth,
            'L4-10 real multi-node provider Obra evidence still pending unless a real receipt is supplied',
            'Provider/API spend remains an operator decision',
        ];
    }

    private function startPrompt(): string
    {
        return <<<'PROMPT'
You are resuming the Atlas Fable Lists 4-5-6 campaign in a cold session. Start in /Users/vitorepf/develop/Atlas/atlas-server, read AGENTS.md, then read docs/fable-listas-4-5-6-execution-prompt.md and the three list ledgers. Treat Atlas memory/context as canonical, run the Atlas context/bootstrap gates before implementation, and continue in order from L4-14. Do not mark L4-9 or L4-10 real-provider proof complete unless a real receipt is supplied and the strict commands pass. Use /opt/homebrew/bin/php for Artisan and keep all claims tied to resolved evidence.
PROMPT;
    }

    /**
     * @param  array<string,mixed>  $delta
     * @param  array<string,mixed>  $series
     * @param  array<string,mixed>  $captureQuality
     * @param  array<string,mixed>  $digest
     * @param  array<string,mixed>  $semanticLift
     * @return array<string,mixed>
     */
    private function nxmSummary(array $delta, array $series, array $captureQuality, array $digest, array $semanticLift): array
    {
        return [
            'waste' => [
                'baseline_waste_rate' => data_get($delta, 'metrics.capture_gate_mode.baseline') === 'observe'
                    ? data_get($delta, 'metrics.capture_gate_mode.note')
                    : null,
                'current_waste_pct_7d' => data_get($captureQuality, 'overall.waste_pct'),
                'source' => 'atlas:ai:capture-quality-audit --days=7 --json',
            ],
            'merges_per_day' => [
                'total_merged_delta' => data_get($delta, 'metrics.loop_proposals_merged_to_main.delta'),
                'merged_in_digest_window' => data_get($digest, 'sections.merges.merged_24h'),
                'source' => 'atlas_loop_proposals via atlas:fable:delta + atlas:loop:morning-digest',
            ],
            'measured_cost' => [
                'delta_cost_coverage_pct' => data_get($delta, 'metrics.cost_measured_coverage_pct.current'),
                'digest_cost_coverage_pct_24h' => data_get($digest, 'sections.cost.coverage_pct_24h'),
                'total_cost_usd_24h' => data_get($digest, 'sections.cost.total_cost_usd_24h'),
                'source' => 'ai_programming_runtime_telemetry_events.cost_estimate_usd',
            ],
            'scorecard' => [
                'baseline' => data_get($delta, 'metrics.scorecard_overall.baseline'),
                'current' => data_get($delta, 'metrics.scorecard_overall.current'),
                'delta' => data_get($delta, 'metrics.scorecard_overall.delta'),
                'trend' => data_get($series, 'trend.metrics.scorecard_overall'),
                'source' => 'AtlasCognitionScoreCardService::build()',
            ],
            'recall' => [
                'semantic_recall_real' => data_get($delta, 'metrics.semantic_recall_real.current'),
                'semantic_lift_status' => data_get($semanticLift, 'status'),
                'average_lift' => data_get($semanticLift, 'measurement.average_lift'),
                'positive_lift_cases' => data_get($semanticLift, 'measurement.positive_lift_case_count'),
                'source' => 'AobgSemanticRetrievalLiftService::report()',
            ],
        ];
    }

    /**
     * @param  array<string,mixed>  $delta
     * @param  array<string,mixed>  $series
     * @param  array<string,mixed>  $captureQuality
     * @param  array<string,mixed>  $digest
     * @param  array<string,mixed>  $devBeat
     * @param  array<string,mixed>  $forgeProof
     * @param  array<string,mixed>  $semanticLift
     * @return array<string,mixed>
     */
    private function resolvedSources(
        string $baselinePath,
        string $seriesPath,
        array $delta,
        array $series,
        array $captureQuality,
        array $digest,
        array $devBeat,
        array $forgeProof,
        array $semanticLift,
    ): array {
        return [
            'marco_zero' => [
                'status' => is_file($baselinePath) ? 'resolved' : 'missing',
                'path' => $baselinePath,
                'command' => 'storage evidence baseline',
            ],
            'delta' => [
                'status' => ($delta['schema_version'] ?? null) === 'atlas.fable.delta.v1' ? 'resolved' : 'blocked',
                'command' => 'php artisan atlas:fable:delta --json',
            ],
            'delta_series' => [
                'status' => ($series['schema_version'] ?? null) === 'atlas.fable.delta_series.report.v1' ? 'resolved' : 'blocked',
                'path' => $seriesPath,
                'series_length' => data_get($series, 'series_length'),
                'command' => 'php artisan atlas:fable:delta-series --report --json',
            ],
            'capture_quality' => [
                'status' => isset($captureQuality['overall']) ? 'resolved' : 'blocked',
                'command' => 'php artisan atlas:ai:capture-quality-audit --days=7 --json',
            ],
            'morning_digest' => [
                'status' => (string) ($digest['status'] ?? 'unknown'),
                'command' => 'php artisan atlas:loop:morning-digest --json',
            ],
            'dev_beat_test' => [
                'status' => (string) ($devBeat['status'] ?? 'unknown'),
                'command' => 'php artisan atlas:dev:beat-test --json',
            ],
            'forge_l4_10_proof' => [
                'status' => (string) ($forgeProof['status'] ?? 'unknown'),
                'certified' => (bool) ($forgeProof['certified'] ?? false),
                'command' => 'php artisan atlas:forge:l4-10-proof --json',
            ],
            'semantic_lift' => [
                'status' => (string) ($semanticLift['status'] ?? 'unknown'),
                'command' => 'php artisan atlas:aobg:semantic-lift --json',
            ],
        ];
    }

    /**
     * @param  array<string,mixed>  $delta
     * @param  array<string,mixed>  $series
     * @param  array<string,mixed>  $packetVerification
     */
    private function status(array $delta, array $series, array $packetVerification): string
    {
        if (($delta['schema_version'] ?? null) !== 'atlas.fable.delta.v1') {
            return 'blocked_missing_delta';
        }
        if (($series['schema_version'] ?? null) !== 'atlas.fable.delta_series.report.v1') {
            return 'blocked_missing_delta_series';
        }
        if (($packetVerification['status'] ?? null) !== 'ready') {
            return 'blocked_handoff_packet';
        }

        return 'ready_with_operator_gated_external_proofs';
    }

    /**
     * @param  array<string,mixed>  $arguments
     * @return array<string,mixed>
     */
    private function runJson(string $command, array $arguments): array
    {
        $out = new BufferedOutput;
        try {
            $exit = Artisan::call($command, $arguments, $out);
            $raw = trim($out->fetch());
            $decoded = $raw !== '' ? json_decode($raw, true) : null;

            return is_array($decoded)
                ? $decoded + ['_command_exit_code' => $exit]
                : [
                    'schema_version' => 'atlas.fable.l4_13.command_result.v1',
                    'status' => 'blocked',
                    'reason' => 'invalid_json_output',
                    'command' => $command,
                    'exit_code' => $exit,
                ];
        } catch (Throwable $e) {
            return [
                'schema_version' => 'atlas.fable.l4_13.command_result.v1',
                'status' => 'blocked',
                'reason' => 'command_failed',
                'command' => $command,
                'error' => mb_substr($e->getMessage(), 0, 220),
            ];
        }
    }

    /**
     * @param  array<string,mixed>  $payload
     */

    /**
     * @param  array<string,mixed>  $packet
     */
    private function packetHash(array $packet): string
    {
        unset($packet['handoff_packet_hash']);
        ksort($packet);

        return hash('sha256', (string) json_encode($packet, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE));
    }

    private function stringOrNull(mixed $value): ?string
    {
        if (! is_string($value)) {
            return null;
        }
        $value = trim($value);

        return $value === '' ? null : $value;
    }
}
