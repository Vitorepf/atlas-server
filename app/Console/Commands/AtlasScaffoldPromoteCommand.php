<?php

declare(strict_types=1);

namespace App\Console\Commands;

use App\Services\Ai\SelfConstruction\AtlasSelfConstructionPromotionPlanService;
use Illuminate\Console\Command;

/**
 * Atlas Scaffold Promotion CLI — DRY-RUN ONLY.
 *
 * This command produces a promotion plan for a staged scaffold. It DOES NOT
 * write to the source tree. Operator copies files manually based on the plan
 * envelope (staged_path → target_path).
 *
 * Doc canon: docs/engineering-knowledge-base/atlas-self-construction-scaffold-staging-executor.md
 */
class AtlasScaffoldPromoteCommand extends Command
{
    protected $signature = 'atlas:scaffold:promote
        {--action=plan : plan|list}
        {--proposal-id= : required for plan}
        {--proposal-hash= : required for plan}
        {--actor=operator}
        {--json}';

    protected $description = 'Atlas Self-Construction Scaffold Promotion — emit dry-run plan to promote staged scaffold to source tree (operator copies manually).';

    public function handle(AtlasSelfConstructionPromotionPlanService $svc): int
    {
        $action = (string) $this->option('action');
        $json = (bool) $this->option('json');

        switch ($action) {
            case 'plan':
                $proposalId = (string) ($this->option('proposal-id') ?? '');
                $proposalHash = (string) ($this->option('proposal-hash') ?? '');
                if ($proposalId === '' || $proposalHash === '') {
                    $this->error('plan requires --proposal-id and --proposal-hash.');

                    return self::FAILURE;
                }
                try {
                    $env = $svc->plan($proposalId, $proposalHash, (string) $this->option('actor'));
                } catch (\Throwable $e) {
                    $this->error($e->getMessage());

                    return self::FAILURE;
                }

                return $this->emit($env, $json);

            case 'list':
                $plans = $svc->listPlans();

                return $this->emit(['count' => count($plans), 'plans' => $plans], $json);

            default:
                $this->error("action '{$action}' desconhecida.");

                return self::FAILURE;
        }
    }

    private function emit(array $payload, bool $json): int
    {
        if ($json) {
            $this->line((string) json_encode($payload, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE | JSON_PRETTY_PRINT));
        } else {
            foreach ($payload as $k => $v) {
                $this->line(is_scalar($v) || $v === null ? "{$k}: ".var_export($v, true) : "{$k}: ".json_encode($v));
            }
        }

        return self::SUCCESS;
    }
}
