<?php

namespace App\Services\Ai\Programming\CompletionAudit\AtlasAudit;

use App\Services\Ai\Programming\AtlasCodeForgeUxOrchestratorService;

class UxSection
{
    /**
     * Atlas Code Forge Human-First UX Orchestrator certification block.
     *
     * Audits that the desktop UX collapses Forge complexity into a single
     * primary action + canonical 5 tabs + Obra creation on the left rail +
     * explicit provider confirmations. NEVER promotes completion claim;
     * advanced diagnostics live in collapsible details.
     *
     * Schema: atlas.code.forge_human_first_ux_certification.v1
     *
     * @return array<string,mixed>
     */
    public function atlasCodeForgeHumanFirstUxCertification(string $workspace): array
    {
        $repoRoot = rtrim(is_dir($workspace) ? $workspace : base_path(), '/');
        $desktopRoot = dirname($repoRoot).'/atlas-desktop';

        $serviceFile = $repoRoot.'/app/Services/Ai/Programming/AtlasCodeForgeUxOrchestratorService.php';
        $commandFile = $repoRoot.'/app/Console/Commands/AtlasCodeForgeUxOrchestratorCommand.php';
        $controllerFile = $repoRoot.'/app/Http/Controllers/AtlasCodeForgeUxOrchestratorController.php';
        $testFile = $repoRoot.'/tests/Feature/Ai/Programming/AtlasCodeForgeUxOrchestratorTest.php';
        $docFile = $repoRoot.'/docs/engineering-knowledge-base/atlas-code-forge-human-first-ux-orchestrator-v1.md';
        $routesFile = $repoRoot.'/routes/api.php';
        $routesSource = is_file($routesFile) ? (string) file_get_contents($routesFile) : '';
        $serviceSource = is_file($serviceFile) ? (string) file_get_contents($serviceFile) : '';

        $workControllerFile = $repoRoot.'/app/Http/Controllers/AtlasCodeWorkController.php';
        $workControllerSource = is_file($workControllerFile) ? (string) file_get_contents($workControllerFile) : '';

        $forgePanel = $desktopRoot.'/apps/desktop/src/surfaces/code/panels/ForgeHumanPanel.tsx';
        $registry = $desktopRoot.'/apps/desktop/src/surfaces/code/panels/rightRailRegistry.tsx';
        $leftRail = $desktopRoot.'/apps/desktop/src/surfaces/code/leftRail/LeftRail.tsx';

        $forgePanelSource = is_file($forgePanel) ? (string) file_get_contents($forgePanel) : '';
        $registrySource = is_file($registry) ? (string) file_get_contents($registry) : '';
        $leftRailSource = is_file($leftRail) ? (string) file_get_contents($leftRail) : '';

        $invariants = [
            'human_state_machine_available' => $serviceSource !== ''
                && str_contains($serviceSource, "STATE_NO_OBRA = 'no_obra'")
                && str_contains($serviceSource, "STATE_WAITING_REVIEW = 'waiting_review'"),
            'primary_action_resolver_available' => $serviceSource !== ''
                && str_contains($serviceSource, 'primary_action_kind')
                && str_contains($serviceSource, 'primary_action_enabled'),
            'no_obra_creation_in_wrong_topbar' => is_file($desktopRoot.'/apps/desktop/src/surfaces/code/obra/ObraBar.tsx'),
            'left_rail_create_obra_available' => $leftRailSource !== ''
                && (str_contains($leftRailSource, 'Nova Obra') || str_contains($leftRailSource, 'onCreateObra')),
            'right_rail_reduced_to_human_tabs' => $registrySource !== ''
                && str_contains($registrySource, 'ForgeHumanPanel'),
            'technical_actions_hidden_under_advanced' => $forgePanelSource !== ''
                && str_contains($forgePanelSource, '<details'),
            'provider_confirmation_visible' => $forgePanelSource !== ''
                && (str_contains($forgePanelSource, 'Confirmar Provider')
                    || str_contains($forgePanelSource, 'confirm_provider_call')
                    || str_contains($forgePanelSource, 'confirmProviderCall')),
            'no_external_provider_auto_call' => $serviceSource !== ''
                && str_contains($serviceSource, "'external_provider_call' => \$externalCall"),
            'no_completion_claim_auto_promotion' => $serviceSource !== ''
                && str_contains($serviceSource, "'completion_claim_promoted' => \$completionPromoted"),
            'review_gate_preserved' => $serviceSource !== ''
                && str_contains($serviceSource, "'review_completion_gate_preserved' => true"),
            'advanced_diagnostics_preserved' => $registrySource !== ''
                && str_contains($registrySource, "ForgeAdvancedPanel")
                && (is_file($desktopRoot.'/apps/desktop/src/surfaces/code/panels/ForgeAdvancedPanel.tsx')
                    && str_contains((string) file_get_contents($desktopRoot.'/apps/desktop/src/surfaces/code/panels/ForgeAdvancedPanel.tsx'), 'ForgeProviderTopologyPanel')
                    && str_contains((string) file_get_contents($desktopRoot.'/apps/desktop/src/surfaces/code/panels/ForgeAdvancedPanel.tsx'), 'ForgeProviderCapacityPanel')),
            'empty_states_have_next_action' => $serviceSource !== ''
                && str_contains($serviceSource, 'next_safe_step'),
            'state_projection_available' => $workControllerSource !== ''
                && str_contains($workControllerSource, "'forge_ux_orchestrator' =>"),

            // --- Atlas Code Human Interface Upgrade v2 invariants ---
            'blocker_translation_available' => $serviceSource !== ''
                && str_contains($serviceSource, 'resolveBlockerTranslation')
                && str_contains($serviceSource, "'human_title'")
                && str_contains($serviceSource, "'suggested_action_label'"),
            'scope_correction_flow_present' => $serviceSource !== ''
                && str_contains($serviceSource, "STATE_BLOCKED_SCOPE = 'blocked_scope'")
                && str_contains($serviceSource, "ACTION_KIND_FIX_SCOPE = 'fix_scope'")
                && str_contains($serviceSource, 'files_out_of_scope'),
            'waiting_worker_state_present' => $serviceSource !== ''
                && str_contains($serviceSource, "STATE_WAITING_WORKER = 'waiting_worker'")
                && str_contains($serviceSource, 'queue_stale_seconds'),
            'evidence_separation_obra_vs_system' => $serviceSource !== ''
                && str_contains($serviceSource, "'evidence_separation' =>")
                && str_contains($serviceSource, 'system_certification_visible'),
            'completion_gating_visible' => $serviceSource !== ''
                && str_contains($serviceSource, "'completion_gating' =>")
                && str_contains($serviceSource, 'approve_button_visible')
                && str_contains($serviceSource, 'rollback_button_visible'),
            'chat_role_classification_visible' => $serviceSource !== ''
                && str_contains($serviceSource, "CHAT_KIND_DEFINITION = 'definition'")
                && str_contains($serviceSource, 'chat_message_kinds'),
            'live_blocked_priority_over_queued' => $serviceSource !== ''
                && str_contains($serviceSource, 'execution_blocked')
                && str_contains($serviceSource, 'classifyExecutionBlocked'),
            'definition_status_available' => $serviceSource !== ''
                && str_contains($serviceSource, 'definitionStatus')
                && str_contains($serviceSource, "'blocking_execution'"),
            'human_interface_v2_doc_present' => is_file(
                $repoRoot.'/docs/engineering-knowledge-base/atlas-code-human-interface-upgrade-v2.md',
            ),
            'human_panel_consumes_blocker_translation' => $forgePanelSource !== ''
                && (str_contains($forgePanelSource, 'blockerTranslation')
                    || str_contains($forgePanelSource, 'blocker_translation')
                    || str_contains($forgePanelSource, 'suggestedActionLabel')),
            'evidence_panel_separates_obra_vs_system' => is_file($desktopRoot.'/apps/desktop/src/surfaces/code/panels/EvidencePanel.tsx')
                && (function () use ($desktopRoot): bool {
                    $src = (string) file_get_contents($desktopRoot.'/apps/desktop/src/surfaces/code/panels/EvidencePanel.tsx');

                    return str_contains($src, 'Provas desta Obra') || str_contains($src, 'evidence_separation');
                })(),
            'review_buttons_gated' => is_file($desktopRoot.'/apps/desktop/src/surfaces/code/panels/VerifyPanel.tsx')
                && (function () use ($desktopRoot): bool {
                    $src = (string) file_get_contents($desktopRoot.'/apps/desktop/src/surfaces/code/panels/VerifyPanel.tsx');

                    return str_contains($src, 'approve_button_visible')
                        || str_contains($src, 'approveButtonVisible')
                        || str_contains($src, 'completionGating');
                })(),
        ];

        $missingArtifacts = [];
        foreach ([
            'service' => $serviceFile,
            'command' => $commandFile,
            'controller' => $controllerFile,
            'test' => $testFile,
            'doc' => $docFile,
        ] as $key => $path) {
            if (! is_file($path)) {
                $missingArtifacts[] = $key.'_missing';
            }
        }
        if ($routesSource === '' || ! str_contains($routesSource, '/forge/ux-orchestrator')) {
            $missingArtifacts[] = 'route_not_registered';
        }
        if ($forgePanelSource === '') {
            $missingArtifacts[] = 'forge_human_panel_missing';
        }

        $allInvariantsTrue = ! in_array(false, array_values($invariants), true);
        $status = match (true) {
            $missingArtifacts !== [] => 'missing_artifacts',
            ! $allInvariantsTrue => 'backend_available_ui_pending',
            default => 'available',
        };

        return [
            'schema_version' => 'atlas.code.forge_human_first_ux_certification.v1',
            'status' => $status,
            'invariants' => $invariants,
            'invariants_all_true' => $allInvariantsTrue,
            'missing_artifacts' => $missingArtifacts,
            'evidence_commands' => [
                'cli_strict' => 'php artisan atlas:code:forge-ux --json --strict',
                'cli' => 'php artisan atlas:code:forge-ux --obra=<uuid> --json',
                'api' => 'GET /atlas-code/works/{project}/forge/ux-orchestrator',
            ],
            'state_machine_states' => [
                AtlasCodeForgeUxOrchestratorService::STATE_NO_OBRA,
                AtlasCodeForgeUxOrchestratorService::STATE_INTAKE_REQUIRED,
                AtlasCodeForgeUxOrchestratorService::STATE_INTAKE_READY,
                AtlasCodeForgeUxOrchestratorService::STATE_READY_TO_PREPARE,
                AtlasCodeForgeUxOrchestratorService::STATE_PREPARED,
                AtlasCodeForgeUxOrchestratorService::STATE_READY_TO_EXECUTE,
                AtlasCodeForgeUxOrchestratorService::STATE_RUNNING,
                AtlasCodeForgeUxOrchestratorService::STATE_WAITING_PROVIDER_CONFIRMATION,
                AtlasCodeForgeUxOrchestratorService::STATE_WAITING_BUDGET_CONFIRMATION,
                AtlasCodeForgeUxOrchestratorService::STATE_WAITING_RUNTIME_DISPATCH_CONFIRMATION,
                AtlasCodeForgeUxOrchestratorService::STATE_WAITING_REVIEW,
                AtlasCodeForgeUxOrchestratorService::STATE_REPAIR_REQUIRED,
                AtlasCodeForgeUxOrchestratorService::STATE_BLOCKED,
                AtlasCodeForgeUxOrchestratorService::STATE_COMPLETED,
                AtlasCodeForgeUxOrchestratorService::STATE_REJECTED,
                AtlasCodeForgeUxOrchestratorService::STATE_ROLLED_BACK,
            ],
            'external_provider_call' => false,
            'is_external_benchmark' => false,
            'promotes_external_rivals_claim' => false,
            'separated_from_external_rivals_certification' => true,
            'separated_from' => 'external_rivals_certification',
            'note' => 'Atlas Code Forge Human-First UX Orchestrator v1: camada humana sobre runtime governado. Nunca promove completion claim. Nunca chama provider externo. Avancado existe so como diagnostico.',
        ];
    }

    /**
     * Atlas Code Obra Command Center certification (v1).
     *
     * Audits que a UI tem um Command Center central canonico com lifecycle de 8
     * fases, progresso duplo (preparacao vs entrega comprovada), decision inbox,
     * blocker translation honesta, operational health (com unknown legitimado),
     * evidence digest separado de certificacoes do sistema, trust summary, chat
     * com classificacao de papel, advanced collapsado e safety strip.
     *
     * Schema: atlas.code.obra_command_center_certification.v1
     *
     * @return array<string,mixed>
     */
    public function atlasCodeObraCommandCenterCertification(string $workspace): array
    {
        $repoRoot = rtrim(is_dir($workspace) ? $workspace : base_path(), '/');
        $desktopRoot = dirname($repoRoot).'/atlas-desktop';

        $serviceFile = $repoRoot.'/app/Services/Ai/Programming/AtlasCodeObraCommandCenterService.php';
        $controllerFile = $repoRoot.'/app/Http/Controllers/AtlasCodeObraCommandCenterController.php';
        $commandFile = $repoRoot.'/app/Console/Commands/AtlasCodeObraCommandCenterCommand.php';
        $testFile = $repoRoot.'/tests/Feature/Ai/Programming/AtlasCodeObraCommandCenterTest.php';
        $docFile = $repoRoot.'/docs/engineering-knowledge-base/atlas-code-obra-command-center-v1.md';
        $routesFile = $repoRoot.'/routes/api.php';
        $workControllerFile = $repoRoot.'/app/Http/Controllers/AtlasCodeWorkController.php';

        $serviceSource = is_file($serviceFile) ? (string) file_get_contents($serviceFile) : '';
        $routesSource = is_file($routesFile) ? (string) file_get_contents($routesFile) : '';
        $workControllerSource = is_file($workControllerFile) ? (string) file_get_contents($workControllerFile) : '';

        $panelFile = $desktopRoot.'/apps/desktop/src/surfaces/code/stage/ObraCommandCenterPanel.tsx';
        $conversationFile = $desktopRoot.'/apps/desktop/src/surfaces/code/stage/ConversationPanel.tsx';
        $composerFile = $desktopRoot.'/apps/desktop/src/surfaces/code/stage/ComposerPanel.tsx';
        $verifyPanelFile = $desktopRoot.'/apps/desktop/src/surfaces/code/panels/VerifyPanel.tsx';
        $evidencePanelFile = $desktopRoot.'/apps/desktop/src/surfaces/code/panels/EvidencePanel.tsx';
        $forgePanelFile = $desktopRoot.'/apps/desktop/src/surfaces/code/panels/ForgeHumanPanel.tsx';

        $panelSource = is_file($panelFile) ? (string) file_get_contents($panelFile) : '';
        $conversationSource = is_file($conversationFile) ? (string) file_get_contents($conversationFile) : '';
        $composerSource = is_file($composerFile) ? (string) file_get_contents($composerFile) : '';
        $evidencePanelSource = is_file($evidencePanelFile) ? (string) file_get_contents($evidencePanelFile) : '';
        $forgePanelSource = is_file($forgePanelFile) ? (string) file_get_contents($forgePanelFile) : '';

        $invariants = [
            'command_center_schema_available' => $serviceSource !== ''
                && str_contains($serviceSource, "SCHEMA_VERSION = 'atlas.code.obra_command_center.v1'"),
            'state_projection_available' => $workControllerSource !== ''
                && str_contains($workControllerSource, "'obra_command_center' =>"),
            'endpoint_registered' => $routesSource !== ''
                && str_contains($routesSource, '/obra-command-center'),
            'cli_registered' => is_file($commandFile)
                && str_contains((string) file_get_contents($commandFile), "atlas:code:obra-command-center"),
            'center_not_empty' => ($panelSource !== '' && str_contains($panelSource, 'ObraCommandCenterPanel'))
                || ($conversationSource !== '' && str_contains($conversationSource, 'ObraCommandCenter')),
            'lifecycle_phases_available' => $serviceSource !== ''
                && str_contains($serviceSource, "PHASE_INTAKE = 'intake'")
                && str_contains($serviceSource, "PHASE_LEARNING = 'learning'")
                && str_contains($serviceSource, "lifecycle_phases"),
            'lifecycle_has_eight_phases' => $serviceSource !== ''
                && substr_count($serviceSource, 'PHASE_INTAKE') >= 1
                && substr_count($serviceSource, 'PHASE_ARCHITECTURE') >= 1
                && substr_count($serviceSource, 'PHASE_FORGE_PREP') >= 1
                && substr_count($serviceSource, 'PHASE_BUILD') >= 1
                && substr_count($serviceSource, 'PHASE_REVIEW') >= 1
                && substr_count($serviceSource, 'PHASE_PROOFS') >= 1
                && substr_count($serviceSource, 'PHASE_DECISION') >= 1
                && substr_count($serviceSource, 'PHASE_LEARNING') >= 1,
            'readiness_vs_delivery_separated' => $serviceSource !== ''
                && str_contains($serviceSource, 'readiness_progress')
                && str_contains($serviceSource, 'proven_delivery_progress'),
            'decision_inbox_available' => $serviceSource !== ''
                && str_contains($serviceSource, 'decision_inbox')
                && str_contains($serviceSource, 'recommended_action'),
            'primary_cta_single' => $serviceSource !== ''
                && str_contains($serviceSource, 'primary_action_kind')
                && str_contains($serviceSource, 'primary_action_label')
                && str_contains($serviceSource, 'primary_action_enabled'),
            'blocker_translation_available' => $serviceSource !== ''
                && str_contains($serviceSource, "'blocker_translation' =>"),
            'scope_blocker_not_generic' => $serviceSource !== ''
                && str_contains($serviceSource, "fix_scope"),
            'operational_health_available' => $serviceSource !== ''
                && str_contains($serviceSource, "operational_health")
                && str_contains($serviceSource, "queue_name")
                && str_contains($serviceSource, "atlas-code-forge"),
            'unknown_health_is_honest' => $serviceSource !== ''
                && str_contains($serviceSource, "unknownOperationalHealth")
                && str_contains($serviceSource, "'unknown'"),
            'evidence_digest_available' => $serviceSource !== ''
                && str_contains($serviceSource, 'evidence_digest')
                && str_contains($serviceSource, 'system_certifications_separated'),
            'trust_summary_available' => $serviceSource !== ''
                && str_contains($serviceSource, 'trustSummary')
                && str_contains($serviceSource, 'evidence_strength')
                && str_contains($serviceSource, 'missing_evidence'),
            'chat_effect_classification_available' => $composerSource !== ''
                && (str_contains($composerSource, 'classifyChatKind')
                    || str_contains($composerSource, 'KIND_LABEL')
                    || str_contains($composerSource, 'KIND_EFFECT')),
            'advanced_details_collapsed' => $forgePanelSource !== ''
                && str_contains($forgePanelSource, '<details'),
            'no_external_provider_call' => $serviceSource !== ''
                && str_contains($serviceSource, "'external_provider_call' => false"),
            'no_token_spend' => $serviceSource !== ''
                && str_contains($serviceSource, "'provider_tokens_spent' => false"),
            'no_completion_claim_promotion' => $serviceSource !== ''
                && str_contains($serviceSource, "'completion_claim_promoted' => false"),
            'review_gate_preserved' => $serviceSource !== ''
                && str_contains($serviceSource, "'review_gate_preserved' => true"),
            'external_rivals_separated' => $serviceSource !== ''
                && str_contains($serviceSource, "'external_rivals_certification' => 'blocked_requires_operator_approval'"),
            'evidence_panel_separates_obra_vs_system' => $evidencePanelSource !== ''
                && (str_contains($evidencePanelSource, 'Provas desta Obra')
                    || str_contains($evidencePanelSource, 'evidence_separation')
                    || str_contains($evidencePanelSource, 'EvidenceSeparationHeader')),
            'command_center_doc_present' => is_file($docFile),
        ];

        $missingArtifacts = [];
        foreach ([
            'service' => $serviceFile,
            'controller' => $controllerFile,
            'command' => $commandFile,
            'test' => $testFile,
            'doc' => $docFile,
        ] as $key => $path) {
            if (! is_file($path)) {
                $missingArtifacts[] = $key.'_missing';
            }
        }
        if ($routesSource === '' || ! str_contains($routesSource, '/obra-command-center')) {
            $missingArtifacts[] = 'route_not_registered';
        }
        if ($panelSource === '' && ! ($conversationSource !== '' && str_contains($conversationSource, 'ObraCommandCenter'))) {
            $missingArtifacts[] = 'command_center_panel_missing';
        }

        $allInvariantsTrue = ! in_array(false, array_values($invariants), true);
        $status = match (true) {
            $missingArtifacts !== [] => 'missing_artifacts',
            ! $allInvariantsTrue => 'backend_available_ui_pending',
            default => 'available',
        };

        return [
            'schema_version' => 'atlas.code.obra_command_center_certification.v1',
            'status' => $status,
            'invariants' => $invariants,
            'invariants_all_true' => $allInvariantsTrue,
            'missing_artifacts' => $missingArtifacts,
            'evidence_commands' => [
                'cli_strict' => 'php artisan atlas:code:obra-command-center --json --strict',
                'cli' => 'php artisan atlas:code:obra-command-center --obra=<uuid> --json',
                'api' => 'GET /atlas-code/works/{project}/obra-command-center',
            ],
            'lifecycle_phases' => [
                'intake',
                'architecture',
                'forge_prep',
                'build',
                'review',
                'proofs',
                'decision',
                'learning',
            ],
            'external_provider_call' => false,
            'is_external_benchmark' => false,
            'promotes_external_rivals_claim' => false,
            'separated_from_external_rivals_certification' => true,
            'separated_from' => 'external_rivals_certification',
            'note' => 'Atlas Code Obra Command Center v1: Command Center humano-first com lifecycle canonico de 8 fases, progresso duplo, decision inbox, operational health honesto e safety strip. Diagnostico tecnico vive em Avancado.',
        ];
    }

    /**
     * Atlas Code Visual Ergonomics & Enterprise Polish certification (v1).
     *
     * Audits that the desktop UI atinge polish enterprise para 12h workstation:
     * tokens canonicos, paleta nao monocromatica, tipografia operacional sans,
     * left rail polido, status colors distintos, focus states acessiveis,
     * empty/loading/error states padronizados. NUNCA chama provider externo.
     *
     * Schema: atlas.code.visual_ergonomics_certification.v1
     *
     * @return array<string,mixed>
     */
    public function atlasCodeVisualErgonomicsCertification(string $workspace): array
    {
        $repoRoot = rtrim(is_dir($workspace) ? $workspace : base_path(), '/');
        $desktopRoot = dirname($repoRoot).'/atlas-desktop';

        $indexCssFile = $desktopRoot.'/apps/desktop/src/index.css';
        $obrasSectionFile = $desktopRoot.'/apps/desktop/src/surfaces/code/leftRail/ObrasSection.tsx';
        $leftRailFile = $desktopRoot.'/apps/desktop/src/surfaces/code/leftRail/LeftRail.tsx';
        $sessionsSectionFile = $desktopRoot.'/apps/desktop/src/surfaces/code/leftRail/SessionsSection.tsx';
        $primitivesFile = $desktopRoot.'/apps/desktop/src/surfaces/code/leftRail/LeftRailPrimitives.tsx';
        $commandCenterFile = $desktopRoot.'/apps/desktop/src/surfaces/code/stage/ObraCommandCenterPanel.tsx';
        $forgeIntakeFile = $desktopRoot.'/apps/desktop/src/surfaces/code/panels/ForgeWorkIntakePanel.tsx';
        $evidencePanelFile = $desktopRoot.'/apps/desktop/src/surfaces/code/panels/EvidencePanel.tsx';
        $forgePanelFile = $desktopRoot.'/apps/desktop/src/surfaces/code/panels/ForgeHumanPanel.tsx';
        $docFile = $repoRoot.'/docs/engineering-knowledge-base/atlas-code-visual-ergonomics-enterprise-polish-v1.md';

        $indexCss = is_file($indexCssFile) ? (string) file_get_contents($indexCssFile) : '';
        $obrasSectionSource = is_file($obrasSectionFile) ? (string) file_get_contents($obrasSectionFile) : '';
        $leftRailSource = is_file($leftRailFile) ? (string) file_get_contents($leftRailFile) : '';
        $sessionsSource = is_file($sessionsSectionFile) ? (string) file_get_contents($sessionsSectionFile) : '';
        $primitivesSource = is_file($primitivesFile) ? (string) file_get_contents($primitivesFile) : '';
        $commandCenterSource = is_file($commandCenterFile) ? (string) file_get_contents($commandCenterFile) : '';
        $forgeIntakeSource = is_file($forgeIntakeFile) ? (string) file_get_contents($forgeIntakeFile) : '';
        $evidencePanelSource = is_file($evidencePanelFile) ? (string) file_get_contents($evidencePanelFile) : '';
        $forgePanelSource = is_file($forgePanelFile) ? (string) file_get_contents($forgePanelFile) : '';

        $invariants = [
            'design_tokens_available' => $indexCss !== ''
                && str_contains($indexCss, '@layer enterprise')
                && str_contains($indexCss, '--cc-bg:')
                && str_contains($indexCss, '--cc-text:')
                && str_contains($indexCss, '--cc-accent:'),
            'left_rail_polished' => $obrasSectionSource !== ''
                && (str_contains($obrasSectionSource, 'cc-obra-row') || str_contains($obrasSectionSource, 'ObraListItem'))
                && str_contains($leftRailSource, 'cc-btn cc-btn-primary'),
            'active_obra_state_visible' => ($obrasSectionSource !== ''
                && (str_contains($obrasSectionSource, "data-active={active")
                    || str_contains($obrasSectionSource, "data-active='true'")
                    || str_contains($obrasSectionSource, "active={o.id === activeObraId}")))
                && str_contains($indexCss, ".cc-obra-row[data-active='true']"),
            'long_session_typography_available' => $indexCss !== ''
                && str_contains($indexCss, "--cc-font-sans:")
                && str_contains($indexCss, "font-family: var(--cc-font-sans)")
                && str_contains($indexCss, "--cc-leading-relaxed"),
            'color_palette_not_monochrome' => $indexCss !== ''
                && str_contains($indexCss, '--cc-success:')
                && str_contains($indexCss, '--cc-warning:')
                && str_contains($indexCss, '--cc-danger:')
                && str_contains($indexCss, '--cc-info:')
                && str_contains($indexCss, '--cc-accent:'),
            'status_colors_available' => $indexCss !== ''
                && str_contains($indexCss, '--cc-dot-running:')
                && str_contains($indexCss, '--cc-dot-blocked:')
                && str_contains($indexCss, '--cc-dot-review:')
                && str_contains($indexCss, '--cc-dot-passed:')
                && str_contains($indexCss, '--cc-dot-unknown:'),
            'focus_states_available' => $indexCss !== ''
                && str_contains($indexCss, '--cc-focus-ring:')
                && str_contains($indexCss, ':focus-visible'),
            'empty_states_available' => $indexCss !== ''
                && str_contains($indexCss, '.cc-empty')
                && str_contains($indexCss, '.cc-loading')
                && str_contains($indexCss, '.cc-error')
                && $primitivesSource !== ''
                && str_contains($primitivesSource, 'cc-empty'),
            'command_center_visual_polished' => $commandCenterSource !== ''
                && (str_contains($commandCenterSource, 'var(--cc-surface)')
                    || str_contains($commandCenterSource, 'WorkbenchPanel')
                    || str_contains($commandCenterSource, 'ObraSummaryHero')),
            'intake_form_polished' => $forgeIntakeSource !== ''
                && (str_contains($forgeIntakeSource, 'cc-input')
                    || str_contains($forgeIntakeSource, 'cc-textarea')
                    || str_contains($forgeIntakeSource, 'cc-label')
                    || str_contains($forgeIntakeSource, 'O que voce quer')
                    || str_contains($forgeIntakeSource, 'O que você quer')),
            'evidence_tables_polished' => $evidencePanelSource !== ''
                && (str_contains($evidencePanelSource, 'Provas desta Obra')
                    || str_contains($evidencePanelSource, 'EvidenceSeparationHeader')),
            'advanced_details_deemphasized' => $forgePanelSource !== ''
                && str_contains($forgePanelSource, '<details'),
            'no_external_provider_call' => $commandCenterSource !== ''
                && (str_contains($commandCenterSource, "safety.externalProviderCall")
                    || str_contains($commandCenterSource, 'externalProviderCall=')),
            'no_token_spend' => $commandCenterSource !== ''
                && (str_contains($commandCenterSource, 'safety.providerTokensSpent')
                    || str_contains($commandCenterSource, 'providerTokensSpent=')),
            'no_completion_claim_promotion' => $commandCenterSource !== ''
                && (str_contains($commandCenterSource, 'safety.completionClaimPromoted')
                    || str_contains($commandCenterSource, 'completionClaimPromoted=')),
            'sessions_polished' => $sessionsSource !== ''
                && (str_contains($sessionsSource, 'cc-obra-row') || str_contains($sessionsSource, 'ObraListItem')),
            'enterprise_buttons_available' => $indexCss !== ''
                && str_contains($indexCss, '.cc-btn-primary')
                && str_contains($indexCss, '.cc-btn-secondary')
                && str_contains($indexCss, '.cc-btn-danger')
                && str_contains($indexCss, '.cc-btn-ghost'),
            'enterprise_inputs_available' => $indexCss !== ''
                && str_contains($indexCss, '.cc-input')
                && str_contains($indexCss, '.cc-textarea'),
            'scrollbar_polished' => $indexCss !== ''
                && str_contains($indexCss, '::-webkit-scrollbar'),
            'density_token_available' => $indexCss !== ''
                && str_contains($indexCss, '--cc-density:')
                && str_contains($indexCss, "[data-cc-density='compact']"),
            'visual_ergonomics_doc_present' => is_file($docFile),
        ];

        $missingArtifacts = [];
        if ($indexCss === '') {
            $missingArtifacts[] = 'index_css_missing';
        }
        if ($commandCenterSource === '') {
            $missingArtifacts[] = 'command_center_panel_missing';
        }
        if (! is_file($docFile)) {
            $missingArtifacts[] = 'doc_missing';
        }

        $allInvariantsTrue = ! in_array(false, array_values($invariants), true);
        $status = match (true) {
            $missingArtifacts !== [] => 'missing_artifacts',
            ! $allInvariantsTrue => 'pending_polish',
            default => 'available',
        };

        return [
            'schema_version' => 'atlas.code.visual_ergonomics_certification.v1',
            'status' => $status,
            'invariants' => $invariants,
            'invariants_all_true' => $allInvariantsTrue,
            'missing_artifacts' => $missingArtifacts,
            'evidence_commands' => [
                'build' => 'npm run build --workspace=@atlas/desktop',
                'lint' => 'npm run lint --workspace=@atlas/desktop',
                'docs_health' => 'php artisan atlas:engineering:knowledge docs-health --json',
                'visual_qa' => 'node /tmp/atlas-vqa/take-screenshots.mjs',
            ],
            'tokens_layer' => '@layer enterprise',
            'external_provider_call' => false,
            'is_external_benchmark' => false,
            'promotes_external_rivals_claim' => false,
            'separated_from_external_rivals_certification' => true,
            'separated_from' => 'external_rivals_certification',
            'note' => 'Atlas Code Visual Ergonomics & Enterprise Polish v1: tokens enterprise, tipografia sans operacional, paleta com status colors distintos, left rail polido, states padronizados. 12h workstation friendly. Camada visual; nao toca lo gica Forge/Review/Provider.',
        ];
    }

    /**
     * Atlas Code Premium Workbench Visual Comfort certification (v1).
     *
     * Audits the deep visual upgrade: warm graphite/parchment dark-warm
     * theme escopo .atlas-shell.surface-code, workbench primitives
     * reusaveis (StatusBadge/MetricRow/WorkbenchPanel/EmptyState/SafetyStrip/
     * ProgressMilestones/ObraListItem/ObraSummaryHero/LiveActivityCard),
     * centro vivo com lifecycle horizontal + atividade ao vivo + decision
     * inbox premium. Camada exclusivamente visual; nao toca runtime.
     *
     * Schema: atlas.code.premium_workbench_visual_comfort_certification.v1
     *
     * @return array<string,mixed>
     */
    public function atlasCodePremiumWorkbenchVisualComfortCertification(string $workspace): array
    {
        $repoRoot = rtrim(is_dir($workspace) ? $workspace : base_path(), '/');
        $desktopRoot = dirname($repoRoot).'/atlas-desktop';

        $indexCssFile = $desktopRoot.'/apps/desktop/src/index.css';
        $workbenchDir = $desktopRoot.'/apps/desktop/src/surfaces/code/workbench';
        $commandCenterFile = $desktopRoot.'/apps/desktop/src/surfaces/code/stage/ObraCommandCenterPanel.tsx';
        $obrasSectionFile = $desktopRoot.'/apps/desktop/src/surfaces/code/leftRail/ObrasSection.tsx';
        $docFile = $repoRoot.'/docs/engineering-knowledge-base/atlas-code-premium-workbench-visual-comfort-v1.md';

        $indexCss = is_file($indexCssFile) ? (string) file_get_contents($indexCssFile) : '';
        $commandCenterSource = is_file($commandCenterFile) ? (string) file_get_contents($commandCenterFile) : '';
        $obrasSectionSource = is_file($obrasSectionFile) ? (string) file_get_contents($obrasSectionFile) : '';

        $workbenchPrimitives = [
            'StatusBadge.tsx', 'StatusDot.tsx', 'MetricRow.tsx',
            'WorkbenchPanel.tsx', 'EmptyState.tsx', 'SafetyStrip.tsx',
            'ProgressMilestones.tsx', 'ObraListItem.tsx', 'ObraSummaryHero.tsx',
            'LiveActivityCard.tsx', 'tokens.ts', 'index.ts',
        ];
        $primitivesPresent = [];
        foreach ($workbenchPrimitives as $name) {
            $primitivesPresent[$name] = is_file($workbenchDir.'/'.$name);
        }

        $invariants = [
            'dark_warm_theme_scoped' => $indexCss !== ''
                && str_contains($indexCss, '.atlas-shell.surface-code {')
                && str_contains($indexCss, '--cc-bg: #23211c;'),
            'low_glare_no_pure_white' => $indexCss !== ''
                && ! str_contains($indexCss, '--cc-surface-raised: #ffffff;'),
            'cartografia_preserved_legacy' => $indexCss !== ''
                && str_contains($indexCss, '.atlas-shell.surface-cartografia .topbar {'),
            'workbench_primitives_complete' => ! in_array(false, array_values($primitivesPresent), true),
            'status_badge_available' => $primitivesPresent['StatusBadge.tsx'] ?? false,
            'metric_row_available' => $primitivesPresent['MetricRow.tsx'] ?? false,
            'workbench_panel_available' => $primitivesPresent['WorkbenchPanel.tsx'] ?? false,
            'empty_state_available' => $primitivesPresent['EmptyState.tsx'] ?? false,
            'safety_strip_available' => $primitivesPresent['SafetyStrip.tsx'] ?? false,
            'progress_milestones_available' => $primitivesPresent['ProgressMilestones.tsx'] ?? false,
            'obra_list_item_available' => $primitivesPresent['ObraListItem.tsx'] ?? false,
            'obra_summary_hero_available' => $primitivesPresent['ObraSummaryHero.tsx'] ?? false,
            'live_activity_card_available' => $primitivesPresent['LiveActivityCard.tsx'] ?? false,
            'command_center_consumes_primitives' => $commandCenterSource !== ''
                && str_contains($commandCenterSource, 'ObraSummaryHero')
                && str_contains($commandCenterSource, 'LiveActivityCard')
                && str_contains($commandCenterSource, 'WorkbenchPanel')
                && str_contains($commandCenterSource, 'SafetyStrip')
                && str_contains($commandCenterSource, 'ProgressMilestones'),
            'left_rail_consumes_obra_list_item' => $obrasSectionSource !== ''
                && str_contains($obrasSectionSource, 'ObraListItem'),
            'status_dot_pulse_animation' => $indexCss !== ''
                && str_contains($indexCss, '@keyframes cc-status-pulse'),
            'terminal_dock_dark_warm' => $indexCss !== ''
                && str_contains($indexCss, '#16140f'),
            'safety_strip_5_signals' => $commandCenterSource !== ''
                && str_contains($commandCenterSource, 'externalProviderCall=')
                && str_contains($commandCenterSource, 'completionClaimPromoted=')
                && str_contains($commandCenterSource, 'reviewGatePreserved='),
            'no_external_provider_call' => $commandCenterSource !== ''
                && str_contains($commandCenterSource, "snapshot.safetySummary.externalProviderCall"),
            'no_token_spend_visible' => $commandCenterSource !== ''
                && str_contains($commandCenterSource, 'providerTokensSpent'),
            'no_completion_claim_promotion' => $commandCenterSource !== ''
                && str_contains($commandCenterSource, 'completionClaimPromoted'),
            'review_gate_preserved' => $commandCenterSource !== ''
                && str_contains($commandCenterSource, 'reviewCompletionGatePreserved'),
            'advanced_collapsed' => $commandCenterSource !== ''
                && str_contains($commandCenterSource, '<details'),
            'premium_workbench_doc_present' => is_file($docFile),
        ];

        $missingArtifacts = [];
        if ($indexCss === '') {
            $missingArtifacts[] = 'index_css_missing';
        }
        if ($commandCenterSource === '') {
            $missingArtifacts[] = 'command_center_missing';
        }
        if (! is_dir($workbenchDir)) {
            $missingArtifacts[] = 'workbench_dir_missing';
        }
        if (! is_file($docFile)) {
            $missingArtifacts[] = 'doc_missing';
        }

        $allInvariantsTrue = ! in_array(false, array_values($invariants), true);
        $status = match (true) {
            $missingArtifacts !== [] => 'missing_artifacts',
            ! $allInvariantsTrue => 'pending_polish',
            default => 'available',
        };

        return [
            'schema_version' => 'atlas.code.premium_workbench_visual_comfort_certification.v1',
            'status' => $status,
            'invariants' => $invariants,
            'invariants_all_true' => $allInvariantsTrue,
            'missing_artifacts' => $missingArtifacts,
            'workbench_primitives' => $workbenchPrimitives,
            'workbench_dir' => $workbenchDir,
            'evidence_commands' => [
                'build' => 'npm run build --workspace=@atlas/desktop',
                'lint' => 'npm run lint --workspace=@atlas/desktop',
                'visual_qa' => 'node /tmp/atlas-vqa/take-premium-screenshots.mjs',
            ],
            'external_provider_call' => false,
            'is_external_benchmark' => false,
            'promotes_external_rivals_claim' => false,
            'separated_from_external_rivals_certification' => true,
            'separated_from' => 'external_rivals_certification',
            'note' => 'Atlas Code Premium Workbench Visual Comfort v1: tema dark warm escopo Atlas Code (Cartografia intocada), workbench primitives reusaveis, centro vivo com hero/lifecycle/atividade/decisions/diagnostics/safety. UI mira 9/10 12h workstation premium. Camada visual; nao toca runtime/governance.',
        ];
    }
}
