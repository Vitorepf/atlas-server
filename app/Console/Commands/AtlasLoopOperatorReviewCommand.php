<?php

declare(strict_types=1);

namespace App\Console\Commands;

use App\Services\Ai\AutonomousEvolution\AtlasLoopOperatorReviewMobilePublisher;
use App\Services\Ai\AutonomousEvolution\AtlasLoopOperatorReviewQueueService;
use Illuminate\Console\Command;

/**
 * L4-7 · Operator review queue for parked Loop proposals.
 * L5-12 · `publish-mobile` action bridges the queue to the mobile inbox.
 */
final class AtlasLoopOperatorReviewCommand extends Command
{
    protected $signature = 'atlas:loop:operator-review
        {--action=list : list, approve, reject, publish-mobile}
        {--proposal= : Proposal id or proposal_hash for approve/reject}
        {--operator=operator : Operator label}
        {--reason= : Review reason}
        {--base= : Base repo for approve/merge (default: repo root)}
        {--limit=10 : Queue item limit}
        {--approve : Required with --action=approve to merge}
        {--json : Machine-readable JSON output}';

    protected $description = 'List and review Loop proposals parked for operator decision.';

    public function handle(AtlasLoopOperatorReviewQueueService $queue, AtlasLoopOperatorReviewMobilePublisher $mobilePublisher): int
    {
        if (! (bool) config('atlas.loop.operator_review.enabled', true)) {
            $payload = [
                'schema_version' => AtlasLoopOperatorReviewQueueService::SCHEMA_VERSION,
                'status' => 'disabled',
                'reason' => 'operator_review_disabled',
            ];
            if ((bool) $this->option('json')) {
                $this->line((string) json_encode($payload, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE));

                return self::FAILURE;
            }
            $this->warn('Loop operator review is disabled.');

            return self::FAILURE;
        }

        $action = strtolower(trim((string) $this->option('action') ?: 'list'));
        $payload = match ($action) {
            'list' => $queue->queue($this->intOption('limit') ?? (int) config('atlas.loop.operator_review.limit', 10)),
            'approve' => $queue->approve((string) $this->option('proposal'), [
                'operator_id' => (string) $this->option('operator'),
                'reason' => $this->stringOption('reason') ?? 'operator approved parked proposal',
                'base_path' => $this->stringOption('base') ?? base_path(),
                'approved' => (bool) $this->option('approve'),
            ]),
            'reject' => $queue->reject((string) $this->option('proposal'), [
                'operator_id' => (string) $this->option('operator'),
                'reason' => $this->stringOption('reason') ?? 'operator rejected parked proposal',
            ]),
            'publish-mobile', 'publish_mobile' => $mobilePublisher->publish(
                $this->intOption('limit') ?? (int) config('atlas.loop.operator_review.limit', 10),
            ),
            default => ['schema_version' => AtlasLoopOperatorReviewQueueService::SCHEMA_VERSION, 'status' => 'failed', 'reason' => 'unknown_action:'.$action],
        };

        if ((bool) $this->option('json')) {
            $this->line((string) json_encode($payload, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE));

            return $this->exitCode($payload);
        }

        $this->renderHuman($payload);

        return $this->exitCode($payload);
    }

    /**
     * @param  array<string,mixed>  $payload
     */
    private function exitCode(array $payload): int
    {
        return in_array(($payload['status'] ?? null), ['ok', 'merged', 'rejected'], true)
            ? self::SUCCESS
            : self::FAILURE;
    }

    /**
     * @param  array<string,mixed>  $payload
     */
    private function renderHuman(array $payload): void
    {
        $this->components->info('Loop operator review');
        $this->components->twoColumnDetail('Status', (string) ($payload['status'] ?? 'unknown'));
        if (isset($payload['reason'])) {
            $this->components->twoColumnDetail('Reason', (string) $payload['reason']);
        }
        foreach ((array) ($payload['items'] ?? []) as $item) {
            $this->line(sprintf(
                '- %s · %s · %s',
                (string) ($item['id'] ?? ''),
                (string) ($item['reason'] ?? ''),
                (string) ($item['target_path'] ?? ''),
            ));
        }
        if (array_key_exists('published', $payload)) {
            $this->components->twoColumnDetail('Published to mobile', (string) ($payload['published_count'] ?? 0));
            foreach ((array) $payload['published'] as $published) {
                $this->line(sprintf(
                    '- inbox %s · %s',
                    (string) ($published['inbox_item_id'] ?? ''),
                    (string) ($published['target_path'] ?? ''),
                ));
            }
        }
    }

    private function intOption(string $key): ?int
    {
        $raw = trim((string) ($this->option($key) ?: ''));

        return $raw === '' || ! ctype_digit($raw) ? null : (int) $raw;
    }

    private function stringOption(string $key): ?string
    {
        $raw = trim((string) ($this->option($key) ?: ''));

        return $raw !== '' ? $raw : null;
    }
}
