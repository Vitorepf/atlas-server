<?php

namespace App\Services\Engineering;

use App\Models\AiInboxItem;
use App\Models\AtlasEngineeringBenchmarkRun;
use App\Services\Ai\Mobile\AtlasInboxService;
use App\Services\Ai\Support\DatabaseTableAvailability;
use Illuminate\Support\Str;

class EngineeringReleaseGateAlertService
{
    public function __construct(
        private readonly AtlasInboxService $inbox,
    ) {}

    public function emitIfNeeded(AtlasEngineeringBenchmarkRun $run): ?AiInboxItem
    {
        if (! DatabaseTableAvailability::has('ai_inbox_items')) {
            return null;
        }

        if (! in_array((string) $run->release_gate_status, ['failed', 'warning'], true)) {
            return null;
        }

        $run->loadMissing('suite');
        $failed = $run->release_gate_status === 'failed';
        $issues = array_values(array_filter(array_merge(
            (array) ($run->release_gate_failures_json ?? []),
            (array) ($run->release_gate_warnings_json ?? []),
        ), fn (mixed $issue): bool => is_scalar($issue) && trim((string) $issue) !== ''));
        $summary = $failed
            ? 'Atlas-Bench bloqueou este run pelo release gate.'
            : 'Atlas-Bench encontrou avisos no release gate deste run.';

        return $this->inbox->create([
            'type' => 'alert',
            'category' => 'engineering_release_gate',
            'severity' => $failed ? 'critical' : 'warning',
            'title' => $failed ? 'Atlas-Bench: release gate bloqueou' : 'Atlas-Bench: release gate com aviso',
            'summary' => $summary.' Suite '.($run->suite?->slug ?? $run->suite_id).', perfil '.($run->release_gate_profile ?: 'release').'.',
            'body' => $this->body($run, $issues),
            'source_type' => 'atlas_engineering_benchmark_run',
            'source_id' => $run->id,
            'initiator' => 'atlas',
            'dedupe_key' => 'engineering-release-gate:'.$run->id,
            'available_actions' => [
                ['id' => 'open_engineering', 'label' => 'Abrir Engineering', 'style' => 'primary', 'deep_link' => '/engineering'],
                ['id' => 'discuss', 'label' => 'Discutir com Atlas', 'style' => 'default'],
                ['id' => 'dismiss', 'label' => 'Descartar', 'style' => 'default'],
            ],
            'payload' => [
                'category' => 'engineering_release_gate',
                'benchmark_run_id' => $run->id,
                'suite_id' => $run->suite_id,
                'suite_slug' => $run->suite?->slug,
                'status' => $run->status,
                'release_gate_status' => $run->release_gate_status,
                'release_gate_profile' => $run->release_gate_profile,
                'trend_status' => $run->trend_status,
                'pass_rate' => $run->pass_rate,
                'average_score' => $run->average_score,
                'quality_debt' => $this->qualityDebt($run),
                'failures' => $run->release_gate_failures_json ?? [],
                'warnings' => $run->release_gate_warnings_json ?? [],
            ],
            'deep_link' => '/engineering',
            'push_policy' => [
                'send' => $failed ? 'immediate' : 'auto',
                'reason' => 'engineering_release_gate',
                'force' => false,
            ],
            'priority_score' => $failed ? 92 : 76,
            'confidence_score' => 0.95,
            'expires_at' => now()->addDays(14),
        ]);
    }

    /**
     * @param  array<int,mixed>  $issues
     */
    private function body(AtlasEngineeringBenchmarkRun $run, array $issues): string
    {
        $topIssues = collect($issues)
            ->map(fn (mixed $issue): string => trim((string) $issue))
            ->filter()
            ->take(5)
            ->map(fn (string $issue): string => '- '.Str::limit($issue, 220))
            ->implode("\n");

        return implode("\n\n", array_filter([
            'Release gate do Atlas Engineering Harness Runner.',
            'Suite: '.($run->suite?->slug ?? $run->suite_id),
            'Run: '.$run->id,
            'Status do gate: '.($run->release_gate_status ?: 'unknown'),
            'Perfil: '.($run->release_gate_profile ?: 'release'),
            'Pass rate: '.($run->pass_rate === null ? '-' : $run->pass_rate.'%'),
            'Score medio: '.($run->average_score === null ? '-' : $run->average_score),
            'Trend: '.($run->trend_status ?: '-'),
            $topIssues !== '' ? "Principais sinais:\n".$topIssues : null,
        ]));
    }

    private function qualityDebt(AtlasEngineeringBenchmarkRun $run): int
    {
        return (int) $run->failed_control_count
            + (int) $run->blocked_control_count
            + (int) $run->skipped_required_control_count
            + (int) $run->failed_test_count
            + (int) $run->blocking_review_finding_count;
    }
}
