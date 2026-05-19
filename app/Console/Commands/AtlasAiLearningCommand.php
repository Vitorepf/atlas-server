<?php

declare(strict_types=1);

namespace App\Console\Commands;

use App\Services\Ai\Learning\AtlasAiLearningLoopService;
use Illuminate\Console\Command;
use Throwable;

/**
 * Atlas AI Memory & Learning Feedback Loop · CLI surface.
 *
 * Three actions paired with {@see AtlasAiLearningLoopService}:
 *
 *   - `collect`  scans the last N hours of mission/approval/quality/handoff
 *                outcomes and persists learning signals + (governed)
 *                learning proposals;
 *   - `list`     returns the current signals + proposals inbox;
 *   - `review`   records an operator decision on a proposal (approve/reject) —
 *                NEVER auto-applies the proposed delta.
 *
 * Read-only and side-effect-free for `list`. `collect` only INSERTs into
 * `ai_learning_signals` / `ai_learning_proposals`. `review` only UPDATEs the
 * specified proposal's decision fields.
 */
class AtlasAiLearningCommand extends Command
{
    protected $signature = 'atlas:ai:learning
        {action : collect | list | review}
        {--hours=24 : Window in hours for collect}
        {--proposal= : Proposal id or proposal_hash for review}
        {--decision= : approve | reject for review}
        {--operator=cli : Operator id for the review audit trail}
        {--notes= : Optional decision notes for review}
        {--source-type= : Filter signals by source_type for list}
        {--risk-level= : Filter signals by risk_level for list}
        {--status= : Filter signals by status for list}
        {--flow-id= : Filter signals by flow_id for list}
        {--proposal-status= : Filter proposals by status for list}
        {--kind= : Filter proposals by kind for list}
        {--limit=50 : Cap items returned by list}
        {--json : Emit JSON only}';

    protected $description = 'Atlas AI Memory & Learning loop: collect signals, list inbox, review proposals.';

    public function handle(AtlasAiLearningLoopService $service): int
    {
        $action = strtolower(trim((string) $this->argument('action')));
        try {
            return match ($action) {
                'collect' => $this->renderCollect($service),
                'list' => $this->renderList($service),
                'review' => $this->renderReview($service),
                default => $this->failWithMessage("unsupported action [{$action}]; supported: collect | list | review"),
            };
        } catch (Throwable $e) {
            $this->emit(['ok' => false, 'error' => 'exception', 'message' => $e->getMessage(), 'type' => $e::class]);

            return self::FAILURE;
        }
    }

    private function renderCollect(AtlasAiLearningLoopService $service): int
    {
        $hours = max(1, (int) $this->option('hours'));
        $report = $service->collect($hours);
        $this->emit($report);

        return self::SUCCESS;
    }

    private function renderList(AtlasAiLearningLoopService $service): int
    {
        $filters = array_filter([
            'source_type' => $this->stringOption('source-type'),
            'risk_level' => $this->stringOption('risk-level'),
            'status' => $this->stringOption('status'),
            'flow_id' => $this->stringOption('flow-id'),
            'proposal_status' => $this->stringOption('proposal-status'),
            'kind' => $this->stringOption('kind'),
        ], fn ($value): bool => $value !== null);
        $limit = max(1, min(200, (int) $this->option('limit')));
        $report = $service->list($filters, $limit);
        $this->emit($report);

        return self::SUCCESS;
    }

    private function renderReview(AtlasAiLearningLoopService $service): int
    {
        $proposalId = $this->stringOption('proposal');
        $decision = $this->stringOption('decision');
        $operator = $this->stringOption('operator') ?? 'cli';
        $notes = $this->stringOption('notes');

        if ($proposalId === null || $decision === null) {
            $this->emit(['ok' => false, 'error' => 'missing_required_options', 'required' => ['proposal', 'decision']]);

            return self::FAILURE;
        }

        $result = $service->review($proposalId, $decision, $operator, $notes);
        $this->emit($result);

        return ($result['ok'] ?? false) ? self::SUCCESS : self::FAILURE;
    }

    private function failWithMessage(string $message): int
    {
        $this->emit(['ok' => false, 'error' => 'invalid_arguments', 'message' => $message]);

        return self::FAILURE;
    }

    private function stringOption(string $key): ?string
    {
        $value = $this->option($key);

        return is_string($value) && trim($value) !== '' ? trim($value) : null;
    }

    /**
     * @param  array<string,mixed>  $payload
     */
    private function emit(array $payload): void
    {
        $this->line(json_encode(
            $payload,
            JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE,
        ) ?: '{}');
    }
}
