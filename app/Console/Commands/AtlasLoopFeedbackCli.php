<?php

declare(strict_types=1);

namespace App\Console\Commands;

use App\Services\Ai\AutonomousEvolution\Feedback\AtlasLoopFeedbackReceiptLedger;
use App\Services\Ai\AutonomousEvolution\Feedback\AtlasLoopGiveBackPatternMiner;
use App\Services\Ai\AutonomousEvolution\Feedback\AtlasLoopGiveBackReader;
use App\Services\Ai\AutonomousEvolution\Feedback\AtlasLoopGiveBackToReplenisherFeedback;
use Illuminate\Console\Command;

/**
 * OPERATOR SURFACE for the give_back self-feedback loop — without a CLI the loop is invisible/ungovernable.
 *
 *   inspect  — print the mined give_back FACTs ({@see AtlasLoopGiveBackPatternMiner}) for the current queue.
 *   apply    — run the {@see AtlasLoopGiveBackToReplenisherFeedback} honoring the config flag and append a
 *              receipt to the {@see AtlasLoopFeedbackReceiptLedger}; REFUSES (non-zero) when the flag is OFF
 *              unless --force is given.
 *   history  — print the last N receipts from the ledger in chronological order.
 *
 * --force only bypasses the apply refusal gate; it never edits config or any frozen target.
 */
final class AtlasLoopFeedbackCli extends Command
{
    protected $signature = 'atlas:loop:feedback {action : inspect|apply|history} {--limit=20} {--force : apply even when the flag is OFF} {--json}';

    protected $description = 'Operator surface for the give_back→replenisher self-feedback loop (inspect|apply|history).';

    public function __construct(
        private readonly AtlasLoopGiveBackReader $reader,
        private readonly AtlasLoopGiveBackToReplenisherFeedback $feedback,
        private readonly AtlasLoopFeedbackReceiptLedger $ledger,
    ) {
        parent::__construct();
    }

    public function handle(): int
    {
        $limit = max(1, (int) $this->option('limit'));

        return match ((string) $this->argument('action')) {
            'inspect' => $this->inspect($limit),
            'apply' => $this->apply($limit),
            'history' => $this->history($limit),
            default => $this->refuse('unknown action — use inspect|apply|history', ['action' => (string) $this->argument('action')], self::INVALID),
        };
    }

    private function inspect(int $limit): int
    {
        $facts = $this->miner()->mine($limit);
        $this->emit(['action' => 'inspect', 'facts' => $facts]);

        return self::SUCCESS;
    }

    private function apply(int $limit): int
    {
        $enabled = $this->feedback->enabled();
        $force = (bool) $this->option('force');

        if (! $enabled && ! $force) {
            return $this->refuse(
                'Refusing apply: atlas.loop.feedback.replenisher_enabled is OFF. Re-run with --force to apply anyway.',
                ['action' => 'apply', 'status' => 'refused', 'reason' => 'flag_off', 'flag' => 'atlas.loop.feedback.replenisher_enabled'],
                self::FAILURE,
            );
        }

        $facts = $this->miner()->mine($limit);
        $keysAdded = array_values(array_diff(array_keys($this->feedback->augment([])), []));
        $receiptId = $this->ledger->record([
            'input_records' => $this->reader->recent($limit),
            'mined_facts' => $facts,
            'context_keys_added' => $keysAdded,
        ]);

        $this->emit([
            'action' => 'apply',
            'status' => 'applied',
            'forced' => $force && ! $enabled,
            'receipt_id' => $receiptId,
            'context_keys_added' => $keysAdded,
        ]);

        return self::SUCCESS;
    }

    private function history(int $limit): int
    {
        $receipts = $this->ledger->list($limit);
        $this->emit(['action' => 'history', 'count' => count($receipts), 'receipts' => $receipts]);

        return self::SUCCESS;
    }

    private function miner(): AtlasLoopGiveBackPatternMiner
    {
        return new AtlasLoopGiveBackPatternMiner($this->reader);
    }

    /**
     * @param  array<string,mixed>  $payload
     */
    private function emit(array $payload): void
    {
        if ((bool) $this->option('json')) {
            $this->line((string) json_encode($payload, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE));

            return;
        }

        $this->line((string) ($payload['action'] ?? ''));
        foreach ($payload as $key => $value) {
            if ($key === 'action') {
                continue;
            }
            $this->line('  '.$key.': '.(is_scalar($value) || $value === null ? (string) $value : (string) json_encode($value, JSON_UNESCAPED_SLASHES)));
        }
    }

    /**
     * @param  array<string,mixed>  $payload
     */
    private function refuse(string $message, array $payload, int $code): int
    {
        if ((bool) $this->option('json')) {
            $this->line((string) json_encode($payload, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES));
        } else {
            $this->error($message);
        }

        return $code;
    }
}
