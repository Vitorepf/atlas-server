<?php

declare(strict_types=1);

namespace App\Console\Commands;

use App\Services\Ai\SelfConstruction\LearningTransfer\AtlasSelfConstructionLearningTransferAdmissionOrchestrator;
use Illuminate\Console\Command;

/**
 * Arms the dormant {@see AtlasSelfConstructionLearningTransferAdmissionOrchestrator::admit()} at the operator
 * surface: previews whether a give-back lesson is admitted for cross-scope learning transfer, emitting the
 * admission verdict (classification, gate decision, the would-apply plan and the resulting template) as
 * deterministic facts. Observe-mode only — it records the observation but executes NO transfer.
 *
 * --give-back / --template / --thresholds accept inline JSON or a path to a JSON file.
 */
final class AtlasLoopLearningTransferCommand extends Command
{
    protected $signature = 'atlas:loop:learning-transfer {--give-back=} {--template=} {--thresholds=} {--json}';

    protected $description = 'Read-only/observe: preview learning-transfer admission for a give-back lesson (no transfer run).';

    public function handle(AtlasSelfConstructionLearningTransferAdmissionOrchestrator $orchestrator): int
    {
        $giveBackOption = $this->option('give-back');
        if ($giveBackOption === null || trim((string) $giveBackOption) === '') {
            $this->line((string) json_encode(['status' => 'usage_error', 'reason' => 'give_back_required'], JSON_UNESCAPED_SLASHES));

            return self::INVALID;
        }
        $giveBack = $this->decode($giveBackOption);
        if ($giveBack === null) {
            $this->line((string) json_encode(['status' => 'invalid_json', 'option' => 'give-back'], JSON_UNESCAPED_SLASHES));

            return self::INVALID;
        }

        $this->line((string) json_encode(
            $orchestrator->admit($giveBack, $this->decode($this->option('template')) ?? [], $this->decode($this->option('thresholds')) ?? []),
            JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE | JSON_THROW_ON_ERROR,
        ));

        return self::SUCCESS;
    }

    /**
     * @return array<string,mixed>|null
     */
    private function decode(mixed $value): ?array
    {
        if ($value === null || trim((string) $value) === '') {
            return null;
        }
        $raw = is_file((string) $value) ? (string) file_get_contents((string) $value) : (string) $value;
        $decoded = json_decode($raw, true);

        return is_array($decoded) ? $decoded : null;
    }
}
