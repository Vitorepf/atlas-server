<?php

declare(strict_types=1);

namespace App\Console\Commands;

use App\Models\AtlasEngineeringReviewFinding;
use App\Models\AtlasEngineeringRun;
use App\Models\AtlasTask;
use App\Services\Engineering\CodeGraph\CodeGraphReviewContextAssembler;
use App\Services\Engineering\EngineeringReviewService;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\DB;

class AtlasReviewDeepCommand extends Command
{
    protected $signature = 'atlas:review:deep
        {--task-id=}
        {--finding=* : JSON finding payload}
        {--json}';

    protected $description = 'Run a deep engineering review and emit a structured review packet.';

    public function handle(EngineeringReviewService $reviews, CodeGraphReviewContextAssembler $assembler): int
    {
        $taskId = trim((string) $this->option('task-id'));
        if ($taskId === '') {
            return $this->failClosed('Informe --task-id.');
        }

        $task = AtlasTask::query()->find($taskId);
        if (! $task) {
            return $this->failClosed("Task {$taskId} nao encontrada.");
        }

        $findings = collect((array) $this->option('finding'))
            ->map(fn (mixed $finding): mixed => is_string($finding) ? json_decode($finding, true) : null)
            ->filter(fn (mixed $finding): bool => is_array($finding))
            ->values()
            ->all();

        $review = $reviews->deepReview($task, ['findings' => $findings, 'recorded_by' => 'atlas_cli']);
        $packet = $this->buildPacket($task, $review, $assembler);

        if ((bool) $this->option('json')) {
            $this->line(json_encode($packet, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE));
        } else {
            $this->components->twoColumnDetail('Deep review packet', $packet['risk_level']);
            $this->components->twoColumnDetail('Files reviewed', (string) count($packet['files_reviewed']));
            $this->components->twoColumnDetail('Findings', (string) count($packet['findings']));
            $this->components->twoColumnDetail('Recommendation', $packet['recommendation']);
        }

        return $packet['risk_level'] === 'blocking' ? self::FAILURE : self::SUCCESS;
    }

    /**
     * @param  array<string,mixed>  $review
     * @return array<string,mixed>
     */
    private function buildPacket(AtlasTask $task, array $review, CodeGraphReviewContextAssembler $assembler): array
    {
        $runId = $review['run_id'] ?? null;
        $summary = $review['summary'] ?? [];
        $recorded = $review['recorded_findings'] ?? [];
        $blocking = $summary['blocking_findings'] ?? [];

        $allFindings = $this->mergeFindings($recorded, $blocking);

        $filesReviewed = $this->filesReviewed($allFindings);
        $context = $this->blastRadiusContext($filesReviewed, $assembler);

        $riskLevel = $this->mapRiskLevel($summary);
        $recommendation = $riskLevel === 'blocking'
            ? 'reject'
            : ($riskLevel === 'warning' ? 'review' : 'approve');

        return [
            'schema_version' => 'atlas.review.deep_packet.v1',
            'task_ref' => $task->id,
            'run_ref' => $runId,
            'files_reviewed' => $filesReviewed,
            'findings' => $this->mapFindings($allFindings),
            'risk_level' => $riskLevel,
            'verification_commands' => $this->buildVerificationCommands($task->id),
            'recommendation' => $recommendation,
            'blast_radius' => $context,
            'summary' => $summary,
            // P2g-QOS / R94: surface audit only — never mints eng pass / CertVerdict.
            'eng_gate' => false,
            'non_gating_surface' => true,
            'mints_engineering_outcome' => false,
        ];
    }

    /**
     * @param  array<int,array<string,mixed>>  $recorded
     * @param  array<int,array<string,mixed>>  $blocking
     * @return array<int,array<string,mixed>>
     */
    private function mergeFindings(array $recorded, array $blocking): array
    {
        $byId = [];
        foreach ([...$recorded, ...$blocking] as $finding) {
            if (! is_array($finding)) {
                continue;
            }
            $id = $finding['id'] ?? null;
            if ($id !== null && ! isset($byId[$id])) {
                $byId[$id] = $finding;
            } elseif ($id === null) {
                $byId[] = $finding;
            }
        }

        return array_values($byId);
    }

    /**
     * @param  array<int,array<string,mixed>>  $findings
     * @return array<int,string>
     */
    private function filesReviewed(array $findings): array
    {
        $files = collect($findings)
            ->pluck('file_path')
            ->filter(fn (mixed $path): bool => is_string($path) && $path !== '')
            ->unique()
            ->values()
            ->all();

        if ($files !== []) {
            return $files;
        }

        return ['task_root'];
    }

    /**
     * @param  array<int,string>  $files
     * @return array<string,mixed>
     */
    private function blastRadiusContext(array $files, CodeGraphReviewContextAssembler $assembler): array
    {
        try {
            return $assembler->assemble(
                changedNodeIds: $files,
                edges: [],
                nodeMeta: [],
                tokenBudget: 4000,
                opts: ['blast_depth' => 1],
            );
        } catch (\Throwable $e) {
            return [
                'schema_version' => CodeGraphReviewContextAssembler::SCHEMA,
                'changed' => $files,
                'blast_radius' => [],
                'pack' => [],
                'stats' => ['changed' => count($files), 'blast_radius' => 0, 'candidates' => count($files), 'included' => 0, 'depth' => 1],
                'error' => $e->getMessage(),
            ];
        }
    }

    /**
     * @param  array<int,array<string,mixed>>  $findings
     * @return array<int,array<string,mixed>>
     */
    private function mapFindings(array $findings): array
    {
        return collect($findings)
            ->map(fn (array $finding): array => [
                'id' => $finding['id'] ?? null,
                'severity' => $finding['severity'] ?? 'p2',
                'status' => $finding['status'] ?? 'open',
                'confidence' => $finding['confidence'] ?? null,
                'category' => $finding['category'] ?? 'uncategorized',
                'title' => $finding['title'] ?? 'Untitled finding',
                'file_path' => $finding['file_path'] ?? null,
                'start_line' => $finding['start_line'] ?? null,
                'end_line' => $finding['end_line'] ?? null,
                'recommendation' => $finding['recommendation'] ?? null,
            ])
            ->all();
    }

    /**
     * @param  array<string,mixed>  $summary
     */
    private function mapRiskLevel(array $summary): string
    {
        $blocking = (int) ($summary['blocking_count'] ?? 0);
        $open = (int) ($summary['open_count'] ?? 0);

        if ($blocking > 0) {
            return 'blocking';
        }

        return $open > 0 ? 'warning' : 'clean';
    }

    /**
     * @return array<int,string>
     */
    private function buildVerificationCommands(string $taskId): array
    {
        return [
            "atlas:review:deep --task-id={$taskId} --json",
            "atlas:task:show --task-id={$taskId} --json",
            "atlas:engineering:run:list --task-id={$taskId} --json",
        ];
    }

    private function failClosed(string $message): int
    {
        $payload = [
            'schema_version' => 'atlas.review.deep_packet.v1',
            'ok' => false,
            'error' => $message,
            'remediation' => 'Verifique o task id e tente novamente.',
        ];

        if ((bool) $this->option('json')) {
            $this->line(json_encode($payload, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE));
        } else {
            $this->error($message);
        }

        return self::FAILURE;
    }
}
