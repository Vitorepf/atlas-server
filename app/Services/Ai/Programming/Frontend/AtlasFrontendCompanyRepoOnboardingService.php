<?php

namespace App\Services\Ai\Programming\Frontend;

use App\Services\Ai\Mission\MissionCanonicalHash;
use Illuminate\Support\Facades\File;

final class AtlasFrontendCompanyRepoOnboardingService
{
    public const SCHEMA_VERSION = 'atlas.frontend.company_repo_onboarding.v1';

    /**
     * @param  array<string,mixed>  $input
     * @return array<string,mixed>
     */
    public function run(array $input): array
    {
        $task = trim((string) ($input['task'] ?? ''));
        $workspace = rtrim(trim((string) ($input['workspace'] ?? '')), DIRECTORY_SEPARATOR);
        $provider = trim((string) ($input['provider'] ?? 'provider_neutral')) ?: 'provider_neutral';
        $writeDocs = (bool) ($input['write_docs'] ?? false);
        $proofOutput = $this->proofOutput($input['output'] ?? null, $workspace, $task);

        $skillInstall = app(AtlasFrontendSkillPackService::class)->install([
            'workspace' => $workspace,
        ]);
        $bootstrap = app(AtlasFrontendEnterpriseBootstrapService::class)->run($input + [
            'task' => $task,
            'workspace' => $workspace,
            'write' => $writeDocs,
        ]);
        $proofPilot = app(AtlasFrontendProductProofRuntimeService::class)->pilotDossier($input + [
            'task' => $task,
            'workspace' => $workspace,
            'provider' => $provider,
            'output' => $proofOutput,
        ]);

        $blockers = array_values(array_unique(array_merge(
            $this->prefix('skill_install', (array) ($skillInstall['blockers'] ?? [])),
            $this->prefix('bootstrap', (array) ($bootstrap['blockers'] ?? [])),
            $this->prefix('proof_pilot', (array) ($proofPilot['blockers'] ?? [])),
        )));
        $warnings = array_values(array_unique(array_merge(
            $this->prefix('skill_install', (array) ($skillInstall['warnings'] ?? [])),
            $this->prefix('bootstrap', (array) ($bootstrap['warnings'] ?? [])),
            $this->prefix('proof_pilot', (array) ($proofPilot['warnings'] ?? [])),
            ['onboarding_is_not_delivery_evidence'],
        )));

        $workspaceExists = $workspace !== '' && File::isDirectory($workspace);
        $skillInstalled = ($skillInstall['status'] ?? null) === 'installed';
        $executionReady = $blockers === []
            && $skillInstalled
            && ($bootstrap['status'] ?? null) === 'ready'
            && ($proofPilot['status'] ?? null) === 'ready_for_operator_execution';
        $prepared = $workspaceExists && $skillInstalled && ! $executionReady;

        $payload = [
            'schema_version' => self::SCHEMA_VERSION,
            'status' => $executionReady ? 'ready_for_operator_execution' : ($prepared ? 'prepared_needs_context' : 'blocked'),
            'onboarding_type' => 'company_frontend_repo_atlas_frontend_onboarding',
            'source' => self::class,
            'task_hash' => $task !== '' ? hash('sha256', $task) : null,
            'workspace_hash' => $workspace !== '' ? hash('sha256', $workspace) : null,
            'provider' => $provider,
            'write_docs_requested' => $writeDocs,
            'readiness' => [
                'workspace_exists' => $workspaceExists,
                'skill_pack_installed' => $skillInstalled,
                'enterprise_bootstrap_ready' => ($bootstrap['status'] ?? null) === 'ready',
                'proof_pilot_ready' => ($proofPilot['status'] ?? null) === 'ready_for_operator_execution',
                'provider_dispatch_allowed' => $executionReady,
                'measured_evidence_present' => false,
                'world_best_claim_allowed' => false,
            ],
            'hash_refs' => [
                'skill_pack_install_hash' => $skillInstall['skill_pack_install_hash'] ?? null,
                'enterprise_bootstrap_hash' => $bootstrap['enterprise_bootstrap_hash'] ?? null,
                'pilot_dossier_hash' => $proofPilot['pilot_dossier_hash'] ?? null,
            ],
            'installed_refs' => [
                'skill_path' => data_get($skillInstall, 'provider_activation.skill_path'),
                'proof_pilot_output_hash' => hash('sha256', $proofOutput),
            ],
            'required_next_actions' => $executionReady
                ? ['dispatch_provider_with_provider_packet_then_execute_runbook_and_collect_measured_evidence']
                : $this->nextActions($skillInstall, $bootstrap, $proofPilot, $writeDocs),
            'claim_policy' => [
                'onboarding_is_not_delivery_evidence' => true,
                'prepared_needs_context_is_not_ready_for_provider_dispatch' => true,
                'completion_requires_run_certification_handoff_and_outcome' => true,
                'raw_customer_source_returned' => false,
                'world_best_claim_allowed' => false,
            ],
            'blockers' => $blockers,
            'warnings' => $warnings,
        ];
        $payload['onboarding_hash'] = MissionCanonicalHash::sha256($payload);

        if ($workspaceExists) {
            File::ensureDirectoryExists($workspace.'/.atlas/frontend');
            File::put($workspace.'/.atlas/frontend/onboarding-receipt.json', json_encode($payload, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE).PHP_EOL);
        }

        return $payload;
    }

    private function proofOutput(mixed $value, string $workspace, string $task): string
    {
        $output = is_string($value) ? trim($value) : '';
        if ($output !== '') {
            return $output;
        }

        if ($workspace !== '') {
            return $workspace.'/.atlas/frontend/proof-pilot/'.hash('sha256', $task.'|'.$workspace);
        }

        return storage_path('app/atlas/frontend-onboarding-proof-pilot/'.hash('sha256', $task));
    }

    /**
     * @param  array<int,mixed>  $items
     * @return array<int,string>
     */
    private function prefix(string $prefix, array $items): array
    {
        return collect($items)
            ->filter(fn (mixed $item): bool => is_string($item))
            ->map(fn (string $item): string => $prefix.'_'.$item)
            ->values()
            ->all();
    }

    /**
     * @param  array<string,mixed>  $skillInstall
     * @param  array<string,mixed>  $bootstrap
     * @param  array<string,mixed>  $proofPilot
     * @return array<int,string>
     */
    private function nextActions(array $skillInstall, array $bootstrap, array $proofPilot, bool $writeDocs): array
    {
        $actions = [];
        if (($skillInstall['status'] ?? null) !== 'installed') {
            $actions[] = 'install_atlas_frontend_skill_pack_in_existing_workspace';
        }
        if (! $writeDocs) {
            $actions[] = 'run_onboarding_with_write_docs_or_fill_existing_design_docs';
        }
        array_push($actions, ...array_values(array_filter((array) ($bootstrap['required_next_actions'] ?? []), 'is_string')));
        array_push($actions, ...array_values(array_filter((array) ($proofPilot['required_next_actions'] ?? []), 'is_string')));

        return array_values(array_unique($actions));
    }
}
