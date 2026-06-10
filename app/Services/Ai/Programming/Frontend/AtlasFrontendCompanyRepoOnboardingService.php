<?php

namespace App\Services\Ai\Programming\Frontend;

use App\Services\Ai\Mission\MissionCanonicalHash;
use App\Services\Ai\Support\JsonFileStore;
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
        $frontendApp = AtlasFrontendAppScope::relativeName($input['frontend_app'] ?? null);
        $write = (bool) ($input['write'] ?? false);
        $writeDocs = (bool) ($input['write_docs'] ?? false);
        $proofOutput = $this->proofOutput($input['output'] ?? null, $workspace, $task);

        $skillInstall = $write
            ? app(AtlasFrontendSkillPackService::class)->install(['workspace' => $workspace])
            : $this->inspectSkillPack($workspace);
        $bootstrap = app(AtlasFrontendEnterpriseBootstrapService::class)->run($input + [
            'task' => $task,
            'workspace' => $workspace,
            'write' => $writeDocs,
        ]);
        $proofPilot = $write
            ? app(AtlasFrontendProductProofRuntimeService::class)->pilotDossier($input + [
                'task' => $task,
                'workspace' => $workspace,
                'frontend_app' => $frontendApp ?? '',
                'provider' => $provider,
                'output' => $proofOutput,
            ])
            : $this->readOnlyProofPilotProjection($bootstrap, $frontendApp);

        $blockers = array_values(array_unique(array_merge(
            $write ? $this->prefix('skill_install', (array) ($skillInstall['blockers'] ?? [])) : [],
            $this->prefix('bootstrap', (array) ($bootstrap['blockers'] ?? [])),
            $write ? $this->prefix('proof_pilot', (array) ($proofPilot['blockers'] ?? [])) : [],
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
            && (! $write || $skillInstalled)
            && ($bootstrap['status'] ?? null) === 'ready'
            && (
                ($write && ($proofPilot['status'] ?? null) === 'ready_for_operator_execution')
                || (! $write && ($proofPilot['status'] ?? null) === 'ready_for_operator_execution_read_only_projection')
            );
        $prepared = $workspaceExists && ! $executionReady;

        $payload = [
            'schema_version' => self::SCHEMA_VERSION,
            'status' => $executionReady ? 'ready_for_operator_execution' : ($prepared ? 'prepared_needs_context' : 'blocked'),
            'onboarding_type' => 'company_frontend_repo_atlas_frontend_onboarding',
            'source' => self::class,
            'task_hash' => $task !== '' ? hash('sha256', $task) : null,
            'workspace_hash' => $workspace !== '' ? hash('sha256', $workspace) : null,
            'frontend_app_scope' => $proofPilot['frontend_app_scope'] ?? [
                'status' => 'repo_root',
                'relative_name_hash' => null,
            ],
            'provider' => $provider,
            'write_requested' => $write,
            'write_docs_requested' => $writeDocs,
            'readiness' => [
                'workspace_exists' => $workspaceExists,
                'skill_pack_installed' => $skillInstalled,
                'enterprise_bootstrap_ready' => ($bootstrap['status'] ?? null) === 'ready',
                'proof_pilot_ready' => $write
                    ? ($proofPilot['status'] ?? null) === 'ready_for_operator_execution'
                    : ($proofPilot['status'] ?? null) === 'ready_for_operator_execution_read_only_projection',
                'provider_dispatch_allowed' => $executionReady,
                'measured_evidence_present' => false,
                'world_best_claim_allowed' => false,
            ],
            'hash_refs' => [
                'skill_pack_install_hash' => $skillInstall['skill_pack_install_hash'] ?? null,
                'enterprise_bootstrap_hash' => $bootstrap['enterprise_bootstrap_hash'] ?? null,
                'pilot_dossier_hash' => $proofPilot['pilot_dossier_hash'] ?? null,
                'proof_pilot_projection_hash' => $proofPilot['projection_hash'] ?? null,
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
                'read_only_onboarding_does_not_write_workspace' => ! $write,
                'prepared_needs_context_is_not_ready_for_provider_dispatch' => true,
                'completion_requires_run_certification_handoff_and_outcome' => true,
                'raw_customer_source_returned' => false,
                'world_best_claim_allowed' => false,
            ],
            'blockers' => $blockers,
            'warnings' => $warnings,
        ];
        $payload['onboarding_hash'] = MissionCanonicalHash::sha256($payload);

        if ($write && $workspaceExists) {
            File::ensureDirectoryExists($workspace.'/.atlas/frontend');
            JsonFileStore::writeLine(
                $workspace.'/.atlas/frontend/onboarding-receipt.json',
                $payload,
                JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE,
            );
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
     * @return array<string,mixed>
     */
    private function inspectSkillPack(string $workspace): array
    {
        $exists = $workspace !== '' && File::isFile($workspace.'/.atlas/skills/atlas-frontend/SKILL.md');

        return [
            'schema_version' => AtlasFrontendSkillPackService::INSTALL_SCHEMA_VERSION,
            'status' => $exists ? 'installed' : 'not_installed',
            'skill_pack_install_hash' => $exists ? hash_file('sha256', $workspace.'/.atlas/skills/atlas-frontend/SKILL.md') : null,
            'provider_activation' => [
                'skill_path' => '.atlas/skills/atlas-frontend/SKILL.md',
                'provider_should_read_before_frontend_edits' => $exists,
                'runtime_commands_remain_authoritative' => true,
            ],
            'blockers' => [],
            'warnings' => $exists ? [] : ['skill_pack_not_installed_read_only'],
        ];
    }

    /**
     * @param  array<string,mixed>  $bootstrap
     * @return array<string,mixed>
     */
    private function readOnlyProofPilotProjection(array $bootstrap, ?string $frontendApp): array
    {
        $payload = [
            'schema_version' => AtlasFrontendProductProofRuntimeService::PILOT_DOSSIER_SCHEMA_VERSION,
            'status' => ($bootstrap['status'] ?? null) === 'ready'
                ? 'ready_for_operator_execution_read_only_projection'
                : 'not_generated_read_only',
            'proof_type' => 'read_only_company_repo_frontend_pilot_projection',
            'frontend_app_scope' => AtlasFrontendAppScope::fromTrustedRelativeName($frontendApp),
            'blockers' => [],
            'warnings' => ['proof_pilot_not_written_in_read_only_onboarding'],
        ];
        $payload['projection_hash'] = MissionCanonicalHash::sha256($payload);

        return $payload;
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
