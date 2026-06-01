<?php

namespace App\Console\Commands;

use App\Services\Ai\Aaeos\Generated\AtlasCodeMultiProjectWorkspaceOsService;
use Illuminate\Console\Command;
use Throwable;

/**
 * Read-only runtime surface for the Atlas Code Multi-Project Workspace OS router.
 *
 * With safe defaults (no active project, empty request) the router must refuse
 * to classify any code work and return tier=none with execution blocked: the
 * doc's first invariant is "Sempre pergunte ou infira o Projeto ativo antes de
 * orientar trabalho de codigo." This proves the contract is live — code work is
 * never routed without a resolved Project/Workspace.
 *
 * @see docs/engineering-knowledge-base/atlas-code-multi-project-workspace-os.md
 */
class AtlasCodeMultiProjectWorkspaceOsCommand extends Command
{
    protected $signature = 'atlas:aaeos:atlas-code-multi-project-workspace-os {--json : Print machine-readable JSON}';

    protected $description = 'Route a code-work request inside the active Project/Workspace per the Atlas Code Multi-Project Workspace OS "Regras para IA".';

    public function handle(AtlasCodeMultiProjectWorkspaceOsService $service): int
    {
        try {
            // Safe default: no active project, empty request. Contract => tier=none.
            $payload = $service->routeWork([], []);
        } catch (Throwable $e) {
            $payload = [
                'ok' => false,
                'error' => $e->getMessage(),
                'schema_version' => AtlasCodeMultiProjectWorkspaceOsService::SCHEMA_VERSION,
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
        $this->components->twoColumnDetail('tier', (string) $payload['tier']);
        $this->components->twoColumnDetail('active_project', $payload['active_project'] ? 'true' : 'false');
        $this->components->twoColumnDetail('execution_allowed', $payload['execution_allowed'] ? 'true' : 'false');
        $this->components->twoColumnDetail('effective_risk', (string) $payload['effective_risk']);
        $this->components->twoColumnDetail('invariant_violations', (string) count($payload['invariant_violations']));
        $this->components->twoColumnDetail('required_next_action', (string) $payload['required_next_action']);

        return self::SUCCESS;
    }
}
