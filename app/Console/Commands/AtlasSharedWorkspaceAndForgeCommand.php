<?php

declare(strict_types=1);

namespace App\Console\Commands;

use App\Services\Ai\Aaeos\Generated\AtlasSharedWorkspaceAndForgeService;
use Illuminate\Console\Command;
use Throwable;

/**
 * Atlas Obras — Shared Workspace And Forge conformance CLI.
 *
 *   php artisan atlas:aaeos:shared-workspace-and-forge [--json]
 *
 * Runs the whole-contract audit over a reference workspace bundle and emits the
 * pass|fail evidence document (canonical name, governed-work link gate, minimum
 * workspace contract, token-and-context law and the artifact bus). Read-only and
 * deterministic; it never runs providers, mutates workspace state or sends
 * context.
 *
 * @see docs/engineering-knowledge-base/obras/shared-workspace-and-forge.md
 */
class AtlasSharedWorkspaceAndForgeCommand extends Command
{
    protected $signature = 'atlas:aaeos:shared-workspace-and-forge {--json : machine-readable JSON output (default true)}';

    protected $description = 'Atlas Obras · audits an Obras Shared Workspace bundle against canonical naming, the governed-work link gate, the minimum workspace contract, the token-and-context law and the artifact bus.';

    public function handle(AtlasSharedWorkspaceAndForgeService $service): int
    {
        try {
            // Safe default: a conformant reference workspace bundle that demonstrates
            // a green audit. Operators can wire real workspace state later.
            $bundle = [
                'name' => AtlasSharedWorkspaceAndForgeService::CANONICAL_NAME,
                'declared_as_forge_specialization' => false,
                'governed_links' => array_fill_keys(
                    array_keys(AtlasSharedWorkspaceAndForgeService::GOVERNED_WORK_LINKS),
                    true,
                ),
                'minimum_contract' => array_fill_keys(
                    array_keys(AtlasSharedWorkspaceAndForgeService::MINIMUM_WORKSPACE_CONTRACT),
                    true,
                ),
                // Token law: at most one role on the whole raw context.
                'whole_context_roles' => [
                    'gemini_scout' => true,
                    'claude_planner_reviewer' => false,
                    'codex_implementer' => false,
                    'local_agent' => false,
                ],
                'artifact' => [
                    'id' => 'art_0001',
                    'kind' => 'spec',
                    'source' => 'context_compiler',
                    'timestamp' => '2026-06-01T00:00:00Z',
                    'owning_provider_session' => 'claude:session-1',
                    'input_hash' => 'sha256:input',
                    'output_hash' => 'sha256:output',
                    'status' => 'validated',
                ],
                'artifact_schema_validated' => true,
                'artifact_evidence_policy_met' => true,
            ];

            $result = $service->audit($bundle);

            $this->line((string) json_encode(
                ['ok' => true, 'result' => $result],
                JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES,
            ));

            return $result['status'] === AtlasSharedWorkspaceAndForgeService::STATUS_PASS
                ? self::SUCCESS
                : self::FAILURE;
        } catch (Throwable $e) {
            $this->line((string) json_encode([
                'ok' => false,
                'error' => 'shared_workspace_and_forge_failed',
                'message' => $e->getMessage(),
            ], JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES));

            return self::FAILURE;
        }
    }
}
