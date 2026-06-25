<?php

declare(strict_types=1);

namespace App\Console\Commands;

use App\Services\Ai\AutonomousEvolution\AtlasLoopMasterSwitch;
use App\Services\Ai\AutonomousEvolution\Quaternity\LoopIntentDrift\AtlasLoopAmbitionFacultyRecalibrator;
use App\Services\Ai\AutonomousEvolution\Quaternity\LoopIntentDrift\AtlasLoopAmbitionFacultyStore;
use App\Services\Ai\AutonomousEvolution\Quaternity\LoopIntentDrift\AtlasLoopIntentDriftReceiptLedger;
use App\Services\Ai\AutonomousEvolution\Quaternity\LoopIntentDrift\AtlasLoopOperatorIntentDriftDetector;
use App\Services\Ai\AutonomousEvolution\Quaternity\LoopIntentDrift\AtlasLoopOperatorIntentSource;
use Illuminate\Console\Command;
use Throwable;

/**
 * Operator surface for the intent-drift loop. Two actions:
 *
 *   inspect   read last --n intents → detect drift → recommend recalibration → APPEND a receipt with
 *             applied=false. No state mutation.
 *
 *   apply     same as inspect, BUT only proceeds when AtlasLoopMasterSwitch::enabled() AND
 *             config('atlas.loop.quaternity.intent_drift.apply_enabled') are BOTH true. On the green-light
 *             path, if FACT.overall_l2 >= deadband, persists the new target via AtlasLoopAmbitionFacultyStore
 *             and appends a receipt with applied=true. Either gate OFF ⇒ exit 2 + "gated: ..." + no save +
 *             no applied=true receipt.
 *
 * Exit codes: 0 ok / 2 gated / 1 error.
 */
final class AtlasLoopIntentDriftCommand extends Command
{
    public const EXIT_OK = 0;

    public const EXIT_ERROR = 1;

    public const EXIT_GATED = 2;

    public const GATED_MESSAGE = 'gated: master_or_apply_disabled';

    protected $signature = 'atlas:loop:intent:drift {action : inspect|apply} {--n=8 : window size} {--json}';

    protected $description = 'Read-only inspect / gated apply for the operator-intent drift recalibration loop.';

    public function handle(
        AtlasLoopOperatorIntentSource $source,
        AtlasLoopOperatorIntentDriftDetector $detector,
        AtlasLoopAmbitionFacultyRecalibrator $recalibrator,
        AtlasLoopAmbitionFacultyStore $store,
        AtlasLoopIntentDriftReceiptLedger $ledger,
    ): int {
        try {
            $action = (string) $this->argument('action');
            $n = max(1, (int) $this->option('n'));

            if ($action === 'apply' && ! $this->applyGreenLight()) {
                $this->line(self::GATED_MESSAGE);

                return self::EXIT_GATED;
            }
            if (! in_array($action, ['inspect', 'apply'], true)) {
                $this->error('unknown action: '.$action);

                return self::EXIT_ERROR;
            }

            $intents = $source->recent($n);
            $fact = $detector->detect($intents);
            $current = $store->current();
            $recommendation = $recalibrator->recommend($fact, $current);

            $proposed = $recommendation['target'];
            $token = $recommendation['reversal_token'];

            $applied = false;
            if ($action === 'apply') {
                $deadband = (float) config('atlas.loop.quaternity.intent_drift.deadband', 0.0);
                if ($fact->overallL2Magnitude >= $deadband) {
                    $store->save($proposed);
                    $applied = true;
                }
            }

            $recalibration = [
                'prior_target' => $current->toArray(),
                'proposed_target' => $proposed->toArray(),
                'reversal_token' => $token->toArray(),
                'applied' => $applied,
            ];
            $receipt = $ledger->append($fact->toArray(), $recalibration);

            $payload = [
                'receipt_id' => $receipt->receiptId,
                'fact' => $fact->toArray(),
                'recalibration' => $recalibration,
            ];
            $this->emit($payload, 'receipt='.$receipt->receiptId.' applied='.($applied ? 'yes' : 'no'));

            return self::EXIT_OK;
        } catch (Throwable $e) {
            $this->error('error: '.mb_substr($e->getMessage(), 0, 200));

            return self::EXIT_ERROR;
        }
    }

    private function applyGreenLight(): bool
    {
        return AtlasLoopMasterSwitch::enabled()
            && (bool) config('atlas.loop.quaternity.intent_drift.apply_enabled', false);
    }

    /**
     * @param  array<string,mixed>  $payload
     */
    private function emit(array $payload, string $humanLine): void
    {
        if ($this->option('json')) {
            $this->line((string) json_encode($payload, JSON_THROW_ON_ERROR | JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE));

            return;
        }
        $this->line($humanLine);
    }
}
