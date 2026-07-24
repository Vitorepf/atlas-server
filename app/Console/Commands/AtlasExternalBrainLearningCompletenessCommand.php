<?php

declare(strict_types=1);

namespace App\Console\Commands;

use App\Services\Ai\SelfConstruction\ExternalBrain\AtlasExternalBrainAmplifierOutcomeReplayRouter;
use App\Services\Ai\SelfConstruction\ExternalBrain\AtlasExternalBrainClosedLoopLearningCompletenessVerifier;
use App\Services\Ai\SelfConstruction\ExternalBrain\AtlasExternalBrainCompoundingOutcomeRouter;
use App\Services\Ai\SelfConstruction\ExternalBrain\AtlasExternalBrainLearningRetentionRunner;
use App\Services\Ai\SelfConstruction\ExternalBrain\AtlasExternalBrainWorkerFeedbackInbox;
use Illuminate\Console\Command;
use App\Console\Concerns\EmitsCanonicalJson;

/**
 * Read-only strict cycle-closure audit. Composes
 * {@see AtlasExternalBrainClosedLoopLearningCompletenessVerifier} (does every
 * originate→implement→evidence→learn→next-batch chain close),
 * {@see AtlasExternalBrainWorkerFeedbackInbox} (typed worker feedback facts) and
 * {@see AtlasExternalBrainCompoundingOutcomeRouter} (compounding bucket per outcome) so every
 * cycle is either provably closed or emits the exact missing link and repair hint.
 *
 * Never enqueues, mutates evidence, calls providers, or runs git — read-only reporting only.
 *
 * Input: a single JSON file (--input=PATH) with keys:
 *   { cycles:list, worker_notes:list, outcomes:list }
 * Missing/absent sections default to empty and simply produce no findings for that side.
 */
final class AtlasExternalBrainLearningCompletenessCommand extends Command
{
    use EmitsCanonicalJson;

    private const SCHEMA = 'atlas.external_brain.learning_completeness.v1';

    /** @var string */
    protected $signature = 'atlas:external-brain:learning-completeness
        {--input= : Path to a JSON file with cycles, worker_notes, outcomes}';

    /** @var string */
    protected $description = 'Read-only: closed-loop learning completeness audit (cycle verifier + worker feedback inbox + compounding outcome router).';

    public function handle(
        AtlasExternalBrainClosedLoopLearningCompletenessVerifier $completenessVerifier,
        AtlasExternalBrainWorkerFeedbackInbox $feedbackInbox,
        AtlasExternalBrainCompoundingOutcomeRouter $outcomeRouter,
        AtlasExternalBrainAmplifierOutcomeReplayRouter $amplifierOutcomeRouter,
    ): int {
        $inputPath = trim((string) $this->option('input'));
        if ($inputPath === '' || ! is_file($inputPath)) {
            $this->error('--input=<path> required and must exist');

            return self::FAILURE;
        }

        $decoded = json_decode((string) file_get_contents($inputPath), true);
        if (! is_array($decoded)) {
            $this->error('invalid input JSON');

            return self::FAILURE;
        }

        $cycles = is_array($decoded['cycles'] ?? null) ? $decoded['cycles'] : [];
        $workerNotes = is_array($decoded['worker_notes'] ?? null) ? $decoded['worker_notes'] : [];
        $outcomes = is_array($decoded['outcomes'] ?? null) ? $decoded['outcomes'] : [];
        $learningRecords = is_array($decoded['learning_records'] ?? null) ? $decoded['learning_records'] : [];

        $completeness = $completenessVerifier->verify(['cycles' => $cycles]);
        $feedback = $feedbackInbox->ingest($workerNotes);

        $compoundingRoutes = [];
        foreach ($outcomes as $outcome) {
            if (! is_array($outcome)) {
                continue;
            }
            $compoundingRoutes[] = $outcomeRouter->route($outcome);
        }

        // Distinct from the per-outcome compounding router above: the amplifier replay
        // router consumes the whole outcomes batch at once (its own routing/aggregation
        // pass over scaffold/model-tier sinks), so it is called once with the full list.
        $amplifierOutcomeRoutes = $amplifierOutcomeRouter->route(['task_outcomes' => $outcomes]);

        $retention = (new AtlasExternalBrainLearningRetentionRunner)->run([
            'lessons' => $learningRecords,
        ]);

        $payload = [
            'schema' => self::SCHEMA,
            'cycle_completeness' => $completeness,
            'worker_feedback' => $feedback,
            'compounding_routes' => $compoundingRoutes,
            'amplifier_outcome_routes' => $amplifierOutcomeRoutes,
            'learning_retention' => $retention,
            'complete' => $completeness['complete'],
            'missing_links' => $completeness['missing_links'],
            'next_repair_task_hint' => $completeness['next_repair_task_hint'],
        ];

        $this->line($this->encode($payload));

        return self::SUCCESS;
    }
}
