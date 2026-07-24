<?php

namespace App\Console\Commands;

use App\Console\Concerns\EmitsCanonicalJson;
use App\Services\Ai\Programming\Governance\ProgrammingGovernanceService;
use App\Services\Ai\Programming\Governance\ProgrammingSpecCompiler;
use Illuminate\Console\Command;
use Throwable;
use App\Support\YesNo;

/**
 * Compile (and optionally critique + attach) a draft spec for a work item
 * from intake, placement and code intelligence — no LLM call.
 */
class AtlasProgrammingSpecCompileCommand extends Command
{
    use EmitsCanonicalJson;

    protected $signature = 'atlas:programming:spec-compile
        {work_item : Work item code or UUID}
        {--critique : Also return the critic report}
        {--attach : Persist the compiled spec via attachSpec (requires the critic to be clean unless --force)}
        {--force : Allow attach even when the critic reports issues}
        {--strict : Exit non-zero when the critic reports blocking issues}
        {--json : Print machine-readable JSON}';

    protected $description = 'Synthesize a draft programming spec from intake + placement + code intelligence.';

    public function handle(
        ProgrammingGovernanceService $governance,
        ProgrammingSpecCompiler $compiler,
    ): int {
        try {
            $workItem = $governance->find((string) $this->argument('work_item'));
            $compiled = $compiler->compile($workItem);
            $critique = $compiler->critique($compiled);
        } catch (Throwable $e) {
            $this->error($e->getMessage());

            return self::FAILURE;
        }

        $payload = [
            'work_item' => $workItem->code,
            'compiled' => $compiled,
        ];
        if ((bool) $this->option('critique') || (bool) $this->option('attach') || (bool) $this->option('strict')) {
            $payload['critique'] = $critique;
        }

        $blockingIssues = (array) ($critique['blocking_issues'] ?? []);

        if ((bool) $this->option('attach')) {
            if ($blockingIssues !== [] && ! (bool) $this->option('force')) {
                $payload['attach'] = ['skipped' => true, 'reason' => 'critic_blocking_issues'];
            } else {
                try {
                    $payload['attach'] = $governance->attachSpec($workItem, $compiled['spec']);
                } catch (Throwable $e) {
                    $this->error($e->getMessage());

                    return self::FAILURE;
                }
            }
        }

        if ((bool) $this->option('json')) {
            $this->line($this->encodeOrEmptyObject($payload));
        } else {
            $this->components->twoColumnDetail('<fg=bright-blue;options=bold>Spec compiled</>', $workItem->code);
            $this->components->twoColumnDetail('Critic status', (string) ($critique['status'] ?? 'n/a'));
            $this->components->twoColumnDetail('Blocking issues', (string) count($blockingIssues));
            $this->components->twoColumnDetail('Attached', issetYesNo::format($payload['attach']));
        }

        if ((bool) $this->option('strict') && $blockingIssues !== []) {
            return self::FAILURE;
        }

        return self::SUCCESS;
    }

}
