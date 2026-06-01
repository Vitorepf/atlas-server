<?php

namespace App\Console\Commands;

use App\Services\Ai\Aaeos\Generated\AtlasSelfConstructionOsOpenQuestionsService;
use Illuminate\Console\Command;
use Throwable;

/**
 * Read-only runtime surface for the Atlas Self-Construction OS Open Questions v1
 * doc. With no args it renders the decision-inbox snapshot: all 10 questions
 * open, every related runtime disabled, and runtime promotion on hold because
 * the OQ-1 + OQ-4 + OQ-5 + OQ-7 quorum is not closed. Proves the AI can never
 * close a question on its own.
 *
 * @see docs/engineering-knowledge-base/self-construction/audits/atlas-self-construction-os-open-questions-v1.md
 */
class AtlasSelfConstructionOsOpenQuestionsCommand extends Command
{
    protected $signature = 'atlas:aaeos:self-construction-os-open-questions {--json : Print machine-readable JSON}';

    protected $description = 'Render the read-only Self-Construction OS open-questions decision inbox (10 questions, human-only close, promotion-hold quorum).';

    public function handle(AtlasSelfConstructionOsOpenQuestionsService $service): int
    {
        try {
            // Safe default: no operator submissions supplied, so every question
            // stays open and promotion stays on hold exactly as the doc states.
            $payload = $service->snapshot();
        } catch (Throwable $e) {
            $payload = [
                'ok' => false,
                'error' => $e->getMessage(),
                'schema_version' => AtlasSelfConstructionOsOpenQuestionsService::SCHEMA_VERSION,
            ];

            if ((bool) $this->option('json')) {
                $this->line(json_encode($payload, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE) ?: '{}');
            } else {
                $this->error($e->getMessage());
            }

            return self::FAILURE;
        }

        if ((bool) $this->option('json')) {
            $this->line(json_encode($payload, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE) ?: '{}');

            return self::SUCCESS;
        }

        $this->components->twoColumnDetail('schema_version', (string) $payload['schema_version']);
        $this->components->twoColumnDetail('position_date', (string) $payload['position_date']);
        $this->components->twoColumnDetail('question_count', (string) $payload['question_count']);
        $this->components->twoColumnDetail('closed_count', (string) $payload['closed_count']);
        $this->components->twoColumnDetail('open_count', (string) $payload['open_count']);
        $this->components->twoColumnDetail('all_open', $payload['all_open'] ? 'true' : 'false');
        $this->components->twoColumnDetail('promotion_on_hold', $payload['promotion_on_hold'] ? 'true' : 'false');
        $this->components->twoColumnDetail('quorum_open', implode(', ', $payload['promotion_hold']['quorum_open']));

        return self::SUCCESS;
    }
}
