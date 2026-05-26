<?php

namespace App\Services\Ai\Programming\Frontend;

use App\Services\Ai\Mission\MissionCanonicalHash;
use Illuminate\Support\Facades\File;

final class AtlasFrontendSkillPackService
{
    public const SCHEMA_VERSION = 'atlas.frontend.skill_pack.v1';

    public const INSTALL_SCHEMA_VERSION = 'atlas.frontend.skill_pack_install.v1';

    public const RUNTIME_GUARDRAILS_SCHEMA_VERSION = 'atlas.frontend.skill_pack_runtime_guardrails.v1';

    /**
     * @param  array<string,mixed>  $input
     * @return array<string,mixed>
     */
    public function export(array $input): array
    {
        $output = rtrim(trim((string) ($input['output'] ?? '')), DIRECTORY_SEPARATOR);
        if ($output === '') {
            $output = storage_path('app/atlas/frontend-skill-pack');
        }

        File::ensureDirectoryExists($output.'/reference');

        $certification = app(AtlasFrontendDesignRuntimeService::class)->certify();
        $contract = app(AtlasFrontendDesignRuntimeService::class)->contract([
            'task' => 'Operate Atlas Frontend on company-owned local repositories',
            'surface' => 'programming.frontend',
            'acceptance_criteria' => true,
            'test_plan' => true,
            'visual_quality_plan' => true,
            'evidence_plan' => true,
            'senior_design_review' => true,
        ]);

        $files = [
            'SKILL.md' => $this->skillMarkdown(),
            'reference/commands.md' => $this->commandsMarkdown(),
            'reference/evidence.md' => $this->evidenceMarkdown(),
            'reference/claim-policy.md' => $this->claimPolicyMarkdown(),
            'reference/provider-handoff.md' => $this->providerHandoffMarkdown(),
        ];

        $written = [];
        foreach ($files as $relative => $contents) {
            File::put($output.'/'.$relative, $contents);
            $written[] = [
                'relative_name' => $relative,
                'sha256' => hash_file('sha256', $output.'/'.$relative),
            ];
        }

        $blockers = [];
        if (($certification['status'] ?? null) !== 'ready') {
            $blockers[] = 'frontend_runtime_certification_not_ready';
        }
        if (($contract['status'] ?? null) !== 'ready') {
            $blockers[] = 'frontend_runtime_contract_not_ready';
        }

        $manifest = [
            'schema_version' => self::SCHEMA_VERSION,
            'status' => $blockers === [] ? 'ready' : 'blocked',
            'skill_pack_type' => 'provider_safe_atlas_frontend_operating_skill',
            'source' => self::class,
            'output_path_hash' => hash('sha256', $output),
            'runtime_refs' => [
                'contract_schema_version' => AtlasFrontendDesignRuntimeService::CONTRACT_SCHEMA_VERSION,
                'certification_schema_version' => AtlasFrontendDesignRuntimeService::CERTIFICATION_SCHEMA_VERSION,
                'certification_hash' => $certification['certification_hash'] ?? null,
                'contract_hash' => $contract['contract_hash'] ?? null,
            ],
            'files' => $written,
            'capability_boundary' => [
                'portable_provider_instruction_surface' => true,
                'requires_atlas_runtime_commands_for_authoritative_gates' => true,
                'skill_pack_is_not_execution_evidence' => true,
                'raw_customer_source_returned' => false,
                'world_best_claim_allowed' => false,
            ],
            'runtime_guardrails' => $this->runtimeGuardrails(),
            'required_next_actions' => $blockers === []
                ? ['install_or_attach_skill_pack_to_provider_then_run_provider_packet_or_proof_pilot']
                : ['fix_frontend_runtime_certification_before_exporting_skill_pack'],
            'blockers' => $blockers,
            'warnings' => ['skill_pack_exports_operating_doctrine_not_measured_delivery_evidence'],
        ];
        $manifest['skill_pack_hash'] = MissionCanonicalHash::sha256($manifest);

        File::put($output.'/manifest.json', json_encode($manifest, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE).PHP_EOL);

        return $manifest;
    }

    /**
     * @param  array<string,mixed>  $input
     * @return array<string,mixed>
     */
    public function install(array $input): array
    {
        $workspace = rtrim(trim((string) ($input['workspace'] ?? '')), DIRECTORY_SEPARATOR);
        $blockers = [];
        if ($workspace === '' || ! File::isDirectory($workspace)) {
            $blockers[] = 'workspace_not_found';
        }

        $target = $workspace !== ''
            ? $workspace.'/.atlas/skills/atlas-frontend'
            : storage_path('app/atlas/frontend-skill-pack-install-missing-workspace');

        $export = $blockers === []
            ? $this->export(['output' => $target])
            : [
                'schema_version' => self::SCHEMA_VERSION,
                'status' => 'blocked',
                'skill_pack_hash' => null,
                'files' => [],
            ];

        if (($export['status'] ?? null) !== 'ready') {
            $blockers[] = 'skill_pack_export_not_ready';
        }
        $blockers = array_values(array_unique($blockers));

        $payload = [
            'schema_version' => self::INSTALL_SCHEMA_VERSION,
            'status' => $blockers === [] ? 'installed' : 'blocked',
            'install_type' => 'company_repo_atlas_frontend_skill_install',
            'source' => self::class,
            'workspace_hash' => $workspace !== '' ? hash('sha256', $workspace) : null,
            'install_path_hash' => hash('sha256', $target),
            'skill_pack_hash' => $export['skill_pack_hash'] ?? null,
            'installed_files' => $export['files'] ?? [],
            'provider_activation' => [
                'skill_name' => 'atlas-frontend',
                'skill_path' => '.atlas/skills/atlas-frontend/SKILL.md',
                'provider_should_read_before_frontend_edits' => true,
                'runtime_commands_remain_authoritative' => true,
                'provider_packet_required_before_frontend_edits' => true,
                'selected_repo_is_workspace_frontend_app_is_subscope' => true,
            ],
            'claim_policy' => [
                'install_is_not_execution_evidence' => true,
                'install_does_not_authorize_delivery_done' => true,
                'raw_customer_source_returned' => false,
                'world_best_claim_allowed' => false,
            ],
            'required_next_actions' => $blockers === []
                ? ['run_atlas_frontend_proof_pilot_or_provider_packet_for_next_frontend_task']
                : ['provide_existing_company_frontend_workspace'],
            'blockers' => $blockers,
            'warnings' => ['installed_skill_pack_must_follow_runtime_gates'],
        ];
        $payload['skill_pack_install_hash'] = MissionCanonicalHash::sha256($payload);

        if ($blockers === []) {
            File::put($target.'/install-receipt.json', json_encode($payload, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE).PHP_EOL);
        }

        return $payload;
    }

    /**
     * @return array<string,mixed>
     */
    private function runtimeGuardrails(): array
    {
        return [
            'schema_version' => self::RUNTIME_GUARDRAILS_SCHEMA_VERSION,
            'selected_workspace_contract' => [
                'scan_folder_for_repositories_before_selection' => true,
                'operator_selected_repository_is_primary_workspace' => true,
                'frontend_app_is_optional_subscope_not_space' => true,
                'space_runtime_required' => false,
                'raw_absolute_path_returned' => false,
            ],
            'provider_packet_required' => true,
            'authoritative_runtime_commands' => [
                'atlas:frontend:onboard',
                'atlas:frontend:provider-packet',
                'atlas:frontend:gate',
                'atlas:frontend:evidence-kit',
                'atlas:frontend:detect',
                'atlas:frontend:run-certify',
                'atlas:frontend:handoff',
                'atlas:frontend:replay',
                'atlas:frontend:private-benchmark-plan',
                'atlas:frontend:world-best-plan',
            ],
            'mandatory_receipts_before_done_claim' => [
                'pre_execution_gate_hash',
                'provider_instruction_packet_hash',
                'visual_quality_report',
                'quality_budget_report',
                'design_review_report',
                'evidence_pack_hash',
                'run_certification_hash',
                'outcome_memory_hash',
                'handoff_hash',
            ],
            'mandatory_detector_receipts' => [
                'atlas_frontend_static_anti_slop_detector',
                'atlas_frontend_browser_detector_event',
                'design_system_drift_gate',
            ],
            'claim_boundary' => [
                'skill_pack_install_is_not_delivery_evidence' => true,
                'completion_claim_requires_run_certification_handoff_and_outcome' => true,
                'world_best_requires_external_rival_replay_decisive_lead_and_public_receipts' => true,
                'minimum_decisive_lead_points' => AtlasFrontendRivalReplayHarnessService::DECISIVE_LEAD_MINIMUM_POINTS,
            ],
        ];
    }

    private function skillMarkdown(): string
    {
        return <<<'MD'
        # Atlas Frontend

        Use this skill when improving, creating, auditing, or certifying frontend product work in Atlas-owned or operator-owned company repositories.

        ## Operating Rule
        - Treat Atlas runtime commands as authoritative. This skill is a portable instruction surface, not the source of truth.
        - Atlas AI/Atlas Code first scans a local folder of repositories; the operator-selected repository is the workspace.
        - `frontend_app` is only an optional sub-scope such as `apps/web`, never a Space or separate selected workspace.
        - Before editing, run or request `atlas:frontend:proof pilot`, `atlas:frontend:provider-packet`, or `atlas:frontend:work-order` for the target repo.
        - Preserve the repository product intent, design system, UX journeys, brand rules, routes, tests, and release constraints.
        - Prefer small, product-correct patches with measurable evidence over decorative redesigns.
        - Run static anti-slop detection, browser-side detector events, and design-system drift checks or return exact blocker reasons.
        - Never claim delivery is done without run certification, evidence pack, quality budget, design review, outcome memory, and handoff.
        - Never claim world-best or market superiority without external rival replay and verified public receipts.

        ## Default Flow
        1. Identify task, local workspace, company/product context, design docs, routes, states, viewports, and acceptance criteria.
        2. Run the Atlas Frontend gate/work-order/provider packet before provider edits.
        3. Inspect the design system and reuse existing components/tokens unless the approved dossier allows change.
        4. Implement or prototype the smallest useful product change.
        5. Run repo-native tests, typecheck/lint, build, visual-quality, quality-budget, design-review, evidence verification, run-certify, outcome memory, and handoff.
        6. Report evidence refs and limitations. Do not return raw customer source, secrets, prompts, or private traces.

        ## Use For
        - Premium refinement of existing company products such as BlackInk or Refinar.
        - New SaaS or ecommerce frontend product creation.
        - Design-system migration, repair, visual QA, live-mode iteration, prototype generation, and frontend handoff.
        - Competitive proof work against Impeccable, Claude Design Plugin, and similar tools.

        ## Do Not Do
        - Do not create generic landing pages when product context exists.
        - Do not use screenshot-only completion claims.
        - Do not introduce design-system drift without report and approval.
        - Do not copy external skill code or proprietary provider instructions.
        - Do not treat templates as evidence.

        See `reference/commands.md`, `reference/evidence.md`, `reference/claim-policy.md`, and `reference/provider-handoff.md`.
        MD;
    }

    private function commandsMarkdown(): string
    {
        return <<<'MD'
        # Atlas Frontend Commands

        ## Pre-Execution
        ```bash
        php artisan atlas:frontend:certify --json --strict
        php artisan atlas:frontend:enterprise-bootstrap inspect --task="<intent>" --workspace=<local-company-repo> --json --strict
        php artisan atlas:frontend:work-order --task="<intent>" --workspace=<local-company-repo> --acceptance --test-plan --visual-quality-plan --evidence-plan --json --strict
        php artisan atlas:frontend:provider-packet --task="<intent>" --workspace=<local-company-repo> --provider=<provider> --acceptance --test-plan --visual-quality-plan --evidence-plan --senior-design-review --json --strict
        php artisan atlas:frontend:proof pilot --task="<intent>" --workspace=<local-company-repo> --provider=<provider> --acceptance --test-plan --visual-quality-plan --evidence-plan --senior-design-review --json --strict
        ```

        ## Repo Understanding
        ```bash
        php artisan atlas:frontend:intake --workspace=<local-company-repo> --json --strict
        php artisan atlas:frontend:design-dossier inspect --workspace=<local-company-repo> --json --strict
        php artisan atlas:frontend:inventory --workspace=<local-company-repo> --json
        php artisan atlas:frontend:scenarios --task="<intent>" --workspace=<local-company-repo> --acceptance --json --strict
        ```

        ## Evidence And Certification
        ```bash
        php artisan atlas:frontend:evidence-kit prepare --task="<intent>" --workspace=<local-company-repo> --acceptance --output=<evidence-dir> --json --strict
        php artisan atlas:frontend:visual-quality inspect --report=<evidence-dir>/visual-quality-report.json --json --strict
        php artisan atlas:frontend:quality-budget inspect --report=<evidence-dir>/quality-budget-report.json --json --strict
        php artisan atlas:frontend:review inspect --report=<evidence-dir>/design-review-report.json --json --strict
        php artisan atlas:frontend:evidence verify --manifest=<evidence-dir>/evidence/evidence-pack.json --root=<evidence-dir>/evidence --json
        php artisan atlas:frontend:run-certify --provider-packet=<provider-packet> --visual-report=<evidence-dir>/visual-quality-report.json --design-review-report=<evidence-dir>/design-review-report.json --quality-budget-report=<evidence-dir>/quality-budget-report.json --evidence-manifest=<evidence-dir>/evidence/evidence-pack.json --outcome-store=<evidence-dir>/outcomes.jsonl --json --strict
        php artisan atlas:frontend:handoff compile --run-certification=<run-certification-report> --evidence-manifest=<evidence-dir>/evidence/evidence-pack.json --json --strict
        ```

        ## Private Benchmark Proof
        ```bash
        php artisan atlas:frontend:proof catalog --json
        php artisan atlas:frontend:proof build --output=<bundle-dir> --json
        php artisan atlas:frontend:replay runner-kit --output=<replay-dir> --json
        php artisan atlas:frontend:replay inspect --manifest-dir=<replay-dir> --json --strict
        php artisan atlas:frontend:private-benchmark-plan --rival-evidence=<replay-dir> --bundle=<bundle-dir> --publication-receipt=<receipt.json> --json --strict
        php artisan atlas:frontend:world-best-plan --rival-evidence=<replay-dir> --bundle=<bundle-dir> --publication-receipt=<receipt.json> --json --strict
        ```
        MD;
    }

    private function evidenceMarkdown(): string
    {
        return <<<'MD'
        # Evidence Requirements

        A frontend delivery claim needs measured evidence, not templates:

        - visual-quality report across required routes, viewports, states, console, accessibility/performance reason, overlap, and anti-slop checks
        - quality-budget report with measured values or approved exceptions
        - 5D design review with score, rationale, and evidence refs
        - evidence pack manifest with real files and matching hashes
        - repo-native test, typecheck/lint, and build receipts or explicit approved reasons
        - outcome memory entry safe for AEMOR learning
        - delivery handoff with known limitations

        Templates from `atlas:frontend:evidence-kit` are scaffolds. They do not prove delivery until replaced with real measured artifacts.
        MD;
    }

    private function claimPolicyMarkdown(): string
    {
        return <<<'MD'
        # Claim Policy

        Allowed after `atlas:frontend:certify --strict`:

        - Atlas Frontend has a governed runtime contract.
        - Atlas Frontend has broader governed delivery coverage than Impeccable and Claude Design Plugin.

        Allowed after repo proof pilot:

        - Atlas is ready for operator/provider execution in the target local company repo.

        Allowed only after run certification and handoff:

        - The specific frontend delivery is complete.

        Allowed only after external rival replay and verified publication receipts:

        - Atlas is best-in-market or world-best for the benchmarked frontend workload.

        Forbidden:

        - documentation-only ready claims
        - screenshot-only done claims
        - generic landing-page claims for real products
        - public/world-best claims without replay and receipts
        MD;
    }

    private function providerHandoffMarkdown(): string
    {
        return <<<'MD'
        # Provider Handoff

        Give providers the provider instruction packet, not loose instructions.

        Provider must return:

        - files changed summary
        - design-system reuse/drift summary
        - test/build/quality receipts or exact blocker reasons
        - visual evidence refs
        - known limitations
        - outcome memory payload

        Provider must not return:

        - raw private prompts
        - secrets
        - customer source dumps
        - hidden provider traces
        - unverified market claims
        MD;
    }
}
