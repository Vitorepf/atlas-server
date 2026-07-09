<?php

namespace App\Console\Commands;

use App\Models\AtlasTask;
use App\Services\Engineering\CodeGraph\CodeGraphReviewContextAssembler;
use App\Services\Engineering\EngineeringReviewService;
use Illuminate\Console\Command;

class AtlasReviewDeepCommand extends Command
{
    protected $signature = 'atlas:review:deep
        {--task-id=}
        {--finding=* : JSON finding payload}
        {--json}';

    protected $description = 'Run or record a deep engineering review with severity, confidence and category thresholds.';

    public function handle(EngineeringReviewService $reviews, CodeGraphReviewContextAssembler $reviewAssembler): int
    {
        $taskId = trim((string) $this->option('task-id'));
        $task = $taskId !== '' ? AtlasTask::query()->find($taskId) : null;
        if (! $task) {
            $packet = $this->failClosedPacket($taskId);
            if ((bool) $this->option('json')) {
                $this->line(json_encode($packet, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE));
            } else {
                $this->error('--task-id precisa apontar para uma task existente.');
            }

            return self::FAILURE;
        }

        $findings = collect((array) $this->option('finding'))
            ->map(fn (mixed $finding): mixed => is_string($finding) ? json_decode($finding, true) : null)
            ->filter(fn (mixed $finding): bool => is_array($finding))
            ->values()
            ->all();

        $payload = $reviews->deepReview($task, ['findings' => $findings, 'recorded_by' => 'atlas_cli']);
        $packet = $this->buildReviewPacket($task, $payload, $reviewAssembler);

        if ((bool) $this->option('json')) {
            $this->line(json_encode($packet, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE));
        } else {
            $this->components->twoColumnDetail('Deep review', $packet['recommendation']);
            $this->components->twoColumnDetail('Risk level', $packet['risk_level']);
            $this->components->twoColumnDetail('Blocking findings', (string) count($packet['findings']));
        }

        return $packet['recommendation'] === 'reject' ? self::FAILURE : self::SUCCESS;
    }

    /**
     * Build a structured review packet from the deep review payload.
     *
     * @param  array<string,mixed>  $payload  deepReview() return value
     * @return array{schema_version:string,task_ref:array<string,mixed>,run_ref:array<string,mixed>,files_reviewed:array<int,string>,findings:array<int,array<string,mixed>>,risk_level:string,operator_next_actions:array<int,string>,recommendation:string,code_graph_context:array<string,mixed>}
     */
    private function buildReviewPacket(AtlasTask $task, array $payload, CodeGraphReviewContextAssembler $reviewAssembler): array
    {
        $recordedFindings = (array) ($payload['recorded_findings'] ?? []);
        $summary = (array) ($payload['summary'] ?? []);
        $blockingCount = (int) ($summary['blocking_count'] ?? 0);

        // Extract unique file paths from findings
        $filesReviewed = collect($recordedFindings)
            ->pluck('file_path')
            ->filter(fn (mixed $fp): bool => is_string($fp) && $fp !== '')
            ->unique()
            ->values()
            ->all();

        // Compute risk level from findings
        $riskLevel = $this->computeRiskLevel($recordedFindings);

        // Build operator next actions (verification commands)
        $operatorNextActions = $this->buildOperatorNextActions($task, $recordedFindings, $riskLevel);

        // Determine recommendation
        $recommendation = $blockingCount > 0 ? 'reject' : 'approve';

        // Assemble code graph context (blast radius) from the files touched by findings.
        // Fail-open: if the assembler throws or returns empty, we still emit the packet.
        $codeGraphContext = $this->assembleCodeGraphContext($filesReviewed, $reviewAssembler);

        return [
            'schema_version' => 'atlas.review_deep.packet.v1',
            'task_ref' => [
                'task_id' => $task->id,
                'title' => (string) ($task->title ?? ''),
                'status' => (string) ($task->status ?? ''),
            ],
            'run_ref' => [
                'run_id' => (string) ($payload['run_id'] ?? ''),
                'status' => $blockingCount > 0 ? 'failed' : 'passed',
            ],
            'files_reviewed' => $filesReviewed,
            'findings' => $recordedFindings,
            'risk_level' => $riskLevel,
            'operator_next_actions' => $operatorNextActions,
            'recommendation' => $recommendation,
            'code_graph_context' => $codeGraphContext,
        ];
    }

    /**
     * Assemble code graph context (blast radius) for the files touched by findings.
     *
     * @param  array<int,string>  $filePaths
     * @return array{schema_version:string,changed:array<int,string>,blast_radius:array<int,string>,stats:array<string,int>}
     */
    private function assembleCodeGraphContext(array $filePaths, CodeGraphReviewContextAssembler $reviewAssembler): array
    {
        try {
            // Use file paths as node IDs for the review context.
            $context = $reviewAssembler->assemble($filePaths, [], [], 0);

            return $context;
        } catch (\Throwable $e) {
            // Fail-open: code graph context is supplementary, never blocks the packet.
            return [
                'schema_version' => CodeGraphReviewContextAssembler::SCHEMA,
                'changed' => $filePaths,
                'blast_radius' => [],
                'stats' => [
                    'changed' => count($filePaths),
                    'blast_radius' => 0,
                    'candidates' => count($filePaths),
                    'included' => 0,
                    'depth' => 0,
                ],
            ];
        }
    }

    /**
     * Compute risk level from findings.
     */
    private function computeRiskLevel(array $findings): string
    {
        foreach ($findings as $finding) {
            $severity = (string) ($finding['severity'] ?? '');
            if ($severity === 'p0') {
                return 'critical';
            }
        }

        foreach ($findings as $finding) {
            $severity = (string) ($finding['severity'] ?? '');
            $confidence = (float) ($finding['confidence'] ?? 0);
            if ($severity === 'p1' && $confidence >= 0.8) {
                return 'high';
            }
        }

        if (count($findings) > 0) {
            return 'medium';
        }

        return 'low';
    }

    /**
     * Build operator next actions based on findings and risk.
     *
     * @param  array<int,array<string,mixed>>  $findings
     * @return array<int,string>
     */
    private function buildOperatorNextActions(AtlasTask $task, array $findings, string $riskLevel): array
    {
        $actions = [];

        // Always include task verification
        $actions[] = "php artisan atlas:task show {$task->id} --json";

        // Add file-specific verification commands
        foreach ($findings as $finding) {
            $filePath = (string) ($finding['file_path'] ?? '');
            if ($filePath !== '') {
                $actions[] = "php artisan atlas:verify file {$filePath} --json";
            }
        }

        // Add risk-specific actions
        if ($riskLevel === 'critical') {
            $actions[] = 'php artisan atlas:review:deep --task-id=' . $task->id . ' --json';
            $actions[] = 'REVIEW: Critical findings require immediate operator attention';
        } elseif ($riskLevel === 'high') {
            $actions[] = 'php artisan atlas:review:deep --task-id=' . $task->id . ' --json';
        }

        return $actions;
    }

    /**
     * Build a fail-closed packet when task is not found.
     *
     * @return array{schema_version:string,task_ref:array<string,mixed>,run_ref:array<string,mixed>,files_reviewed:array<int,string>,findings:array<int,array<string,mixed>>,risk_level:string,operator_next_actions:array<int,string>,recommendation:string,error:string,remediation:string}
     */
    private function failClosedPacket(?string $taskId): array
    {
        return [
            'schema_version' => 'atlas.review_deep.packet.v1',
            'task_ref' => [
                'task_id' => $taskId ?? '',
                'title' => '',
                'status' => 'not_found',
            ],
            'run_ref' => [
                'run_id' => '',
                'status' => 'failed',
            ],
            'files_reviewed' => [],
            'findings' => [],
            'risk_level' => 'unknown',
            'operator_next_actions' => [
                'Verify task ID exists: php artisan atlas:task list --json',
                'Check task status: php artisan atlas:task show <task-id> --json',
            ],
            'recommendation' => 'reject',
            'error' => 'task_not_found',
            'remediation' => 'Provide a valid --task-id that exists in the Atlas task registry.',
        ];
    }
}
