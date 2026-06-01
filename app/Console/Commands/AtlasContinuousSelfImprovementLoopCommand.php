<?php

namespace App\Console\Commands;

use App\Services\Ai\Aaeos\Generated\AtlasContinuousSelfImprovementLoopService;
use Illuminate\Console\Command;
use Throwable;

/**
 * Exercises the Continuous Self-Improvement Loop runtime: runs the documented
 * loop against a sample proposal and prints the resolved decision (promote /
 * rollback / hold), the next promotion level, and the earned autonomy band.
 *
 * @see docs/engineering-knowledge-base/research-self-improvement/continuous-self-improvement-loop.md
 */
class AtlasContinuousSelfImprovementLoopCommand extends Command
{
    protected $signature = 'atlas:aaeos:continuous-self-improvement-loop {--json}';

    protected $description = 'Run the Atlas continuous self-improvement loop decision over a sample proposal.';

    public function handle(AtlasContinuousSelfImprovementLoopService $loop): int
    {
        try {
            $proposal = [
                'source_evidence' => 'evidence-ledger:run-42',
                'affected_docs_code' => ['docs/engineering-knowledge-base/research-self-improvement/continuous-self-improvement-loop.md'],
                'risk' => 'low',
                'expected_gain' => 'fewer repeated failures on the same pattern',
                'validation' => 'php artisan atlas:engineering:knowledge docs-health --json',
                'rollback' => 'revert workspace block',
                'autonomy_level' => 'suggest_only',
                'reason_not_auto_applied' => 'autonomy not yet earned for this path',
                'promotion_level' => 'proposal',
            ];

            $result = $loop->runLoop(
                proposal: $proposal,
                satisfiedGates: ['review_passed'],
                validationPassed: true,
                consecutiveSuccessfulCycles: 3,
            );

            $payload = $result + ['generated_at' => now()->toJSON()];

            if ((bool) $this->option('json')) {
                $this->line(json_encode($payload, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE) ?: '{}');

                return self::SUCCESS;
            }

            $this->components->twoColumnDetail('proposal eligible', $result['proposal_eligible'] ? 'yes' : 'no');
            $this->components->twoColumnDetail('resolution', $result['resolution']);
            $this->components->twoColumnDetail('next level', (string) $result['promotion']['next_level']);
            $this->components->twoColumnDetail('earned autonomy', $result['earned_autonomy']);
            $this->components->twoColumnDetail('human review required', $result['human_review_required'] ? 'yes' : 'no');
            $this->info($result['reason']);

            return self::SUCCESS;
        } catch (Throwable $e) {
            $envelope = [
                'schema' => AtlasContinuousSelfImprovementLoopService::SCHEMA_VERSION,
                'ok' => false,
                'error' => $e->getMessage(),
            ];

            if ((bool) $this->option('json')) {
                $this->line(json_encode($envelope, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE) ?: '{}');
            } else {
                $this->error($e->getMessage());
            }

            return self::FAILURE;
        }
    }
}
