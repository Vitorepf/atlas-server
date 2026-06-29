<?php

declare(strict_types=1);

namespace App\Console\Commands;

use App\Services\Ai\SelfConstruction\Maestro\ClosedLoop\AtlasMaestroReplenisherFeedback;
use Illuminate\Console\Command;

/**
 * Arms the dormant orphan {@see AtlasMaestroReplenisherFeedback::renderFactsBlock()} at the operator surface:
 * renders the maestro replenisher feedback facts block from mined outcome patterns (supplied via --facts as the
 * miner output). Flag-gated (atlas.maestro.closed_loop.feedback_enabled, default OFF ⇒ empty block).
 *
 * Pure + read-only: it renders the facts block and reports; it mutates nothing (only the receipt ledger append).
 */
final class AtlasLoopReplenisherFeedbackCommand extends Command
{
    protected $signature = 'atlas:loop:replenisher-feedback {--facts=} {--json}';

    protected $description = 'Read-only render of the maestro replenisher feedback facts block from mined patterns.';

    public function handle(): int
    {
        $minedData = [];
        $raw = trim((string) $this->option('facts'));
        if ($raw !== '') {
            if (is_file($raw) && is_readable($raw)) {
                $raw = (string) file_get_contents($raw);
            }
            $decoded = json_decode($raw, true);
            if (! is_array($decoded)) {
                $this->line((string) json_encode([
                    'outcome' => 'refused',
                    'reason' => 'usage_error',
                    'message' => '--facts must be a JSON object (the miner output)',
                ], JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE | JSON_THROW_ON_ERROR));

                return self::FAILURE;
            }
            $minedData = $decoded;
        }

        // The feedback needs a miner object exposing mine() — supply a deterministic in-memory miner.
        $miner = new class($minedData)
        {
            /** @param array<string,mixed> $data */
            public function __construct(private array $data) {}

            /** @return array<string,mixed> */
            public function mine(): array
            {
                return $this->data;
            }
        };

        $block = (new AtlasMaestroReplenisherFeedback($miner))->renderFactsBlock();

        $facts = [
            'schema' => 'atlas.loop.replenisher_feedback.v1',
            'empty' => $block === '',
            'facts_block' => $block,
        ];

        if ($this->option('json')) {
            $this->line((string) json_encode($facts, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE | JSON_THROW_ON_ERROR));
        } else {
            $this->line($block === '' ? '(empty — feedback disabled or no supported patterns)' : $block);
        }

        return self::SUCCESS;
    }
}
