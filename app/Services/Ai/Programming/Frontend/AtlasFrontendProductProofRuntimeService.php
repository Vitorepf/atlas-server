<?php

namespace App\Services\Ai\Programming\Frontend;

use App\Services\Ai\Mission\MissionCanonicalHash;
use Illuminate\Support\Facades\File;

final class AtlasFrontendProductProofRuntimeService
{
    public const SCHEMA_VERSION = 'atlas.frontend.product_proof_runtime.v1';

    public const BUNDLE_SCHEMA_VERSION = 'atlas.frontend.product_proof_bundle.v1';

    public const PILOT_DOSSIER_SCHEMA_VERSION = 'atlas.frontend.company_repo_proof_dossier.v1';

    /**
     * @return array<string,mixed>
     */
    public function catalog(): array
    {
        $demos = $this->demos();
        $checks = $this->checks($demos);
        $readyCount = collect($checks)->where('status', 'ready')->count();

        $payload = [
            'schema_version' => self::SCHEMA_VERSION,
            'status' => $readyCount === count($checks) ? 'ready' : 'partial',
            'proof_type' => 'local_product_demo_catalog',
            'source' => self::class,
            'demo_count' => count($demos),
            'demos' => $demos,
            'checks' => $checks,
            'publication_policy' => [
                'public_site_claim_requires_hosted_demo' => true,
                'local_catalog_is_not_public_distribution' => true,
                'raw_customer_source_returned' => false,
                'provider_lock_in_required' => false,
            ],
            'remaining_gaps' => $readyCount === count($checks) ? [
                'external_hosted_product_site_required_for_public_distribution_claim',
                'real_rival_replay_required_for_world_best_claim',
            ] : [
                'local_demo_catalog_missing_required_proofs',
                'external_hosted_product_site_required_for_public_distribution_claim',
                'real_rival_replay_required_for_world_best_claim',
            ],
        ];
        $payload['product_proof_hash'] = MissionCanonicalHash::sha256($payload);

        return $payload;
    }

    /**
     * @return array<string,mixed>
     */
    public function buildStaticBundle(?string $outputDirectory = null): array
    {
        $catalog = $this->catalog();
        $outputDirectory = $outputDirectory ?: storage_path('app/atlas/frontend-product-proof');
        File::ensureDirectoryExists($outputDirectory);

        $assets = [];
        foreach ((array) $catalog['demos'] as $demo) {
            if (! is_array($demo)) {
                continue;
            }
            $slug = (string) $demo['id'];
            $file = $slug.'.html';
            File::put($outputDirectory.'/'.$file, $this->demoHtml($demo));
            $assets[] = [
                'id' => $slug,
                'path' => $file,
                'hash' => hash('sha256', File::get($outputDirectory.'/'.$file)),
            ];
        }

        File::put($outputDirectory.'/index.html', $this->indexHtml((array) $catalog['demos']));
        $manifest = [
            'schema_version' => self::BUNDLE_SCHEMA_VERSION,
            'status' => 'ready',
            'bundle_type' => 'static_publishable_product_proof',
            'output_path_hash' => hash('sha256', $outputDirectory),
            'index' => [
                'path' => 'index.html',
                'hash' => hash('sha256', File::get($outputDirectory.'/index.html')),
            ],
            'assets' => $assets,
            'publication_policy' => [
                'publishable_static_bundle_created' => true,
                'external_hosting_verified' => false,
                'public_url_present' => false,
                'raw_customer_source_returned' => false,
            ],
        ];
        $manifest['bundle_hash'] = MissionCanonicalHash::sha256($manifest);
        File::put($outputDirectory.'/manifest.json', json_encode($manifest, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE));

        return $manifest;
    }

    /**
     * @param  array<string,mixed>  $input
     * @return array<string,mixed>
     */
    public function pilotDossier(array $input): array
    {
        $task = trim((string) ($input['task'] ?? ''));
        $workspace = rtrim(trim((string) ($input['workspace'] ?? '')), DIRECTORY_SEPARATOR);
        $provider = trim((string) ($input['provider'] ?? 'provider_neutral')) ?: 'provider_neutral';
        $output = rtrim(trim((string) ($input['output'] ?? '')), DIRECTORY_SEPARATOR);
        if ($output === '') {
            $output = storage_path('app/atlas/frontend-proof-pilot/'.hash('sha256', $task.'|'.$workspace.'|'.$provider));
        }

        File::ensureDirectoryExists($output);

        $sharedInput = [
            'task' => $task,
            'workspace' => $workspace,
            'provider' => $provider,
            'acceptance_criteria' => (bool) ($input['acceptance_criteria'] ?? false),
            'test_plan' => (bool) ($input['test_plan'] ?? false),
            'visual_quality_plan' => (bool) ($input['visual_quality_plan'] ?? false),
            'evidence_plan' => (bool) ($input['evidence_plan'] ?? false),
            'senior_design_review' => (bool) ($input['senior_design_review'] ?? false),
            'evidence_output' => $output.'/evidence-kit',
        ];

        $bootstrap = app(AtlasFrontendEnterpriseBootstrapService::class)->run($sharedInput);
        $providerPacket = app(AtlasFrontendProviderInstructionPacketService::class)->compile($sharedInput);
        $runbook = app(AtlasFrontendExecutionRunbookService::class)->compile($sharedInput);
        $evidenceKit = app(AtlasFrontendEvidenceKitService::class)->prepare($sharedInput + [
            'output' => $output.'/evidence-kit',
        ]);
        $runtimeCertification = app(AtlasFrontendDesignRuntimeService::class)->certify();
        $catalog = $this->catalog();

        $blockers = array_values(array_unique(array_merge(
            $this->prefix('bootstrap', (array) ($bootstrap['blockers'] ?? [])),
            $this->prefix('provider_packet', (array) ($providerPacket['blockers'] ?? [])),
            $this->prefix('runbook', (array) ($runbook['blockers'] ?? [])),
            $this->prefix('evidence_kit', (array) ($evidenceKit['blockers'] ?? [])),
        )));
        $warnings = array_values(array_unique(array_merge(
            $this->prefix('bootstrap', (array) ($bootstrap['warnings'] ?? [])),
            $this->prefix('provider_packet', (array) ($providerPacket['warnings'] ?? [])),
            $this->prefix('runbook', (array) ($runbook['warnings'] ?? [])),
            $this->prefix('evidence_kit', (array) ($evidenceKit['warnings'] ?? [])),
            ['pilot_dossier_is_not_measured_delivery_evidence'],
        )));

        $ready = $blockers === []
            && ($bootstrap['status'] ?? null) === 'ready'
            && ($providerPacket['status'] ?? null) === 'ready'
            && ($runbook['status'] ?? null) === 'ready'
            && ($evidenceKit['status'] ?? null) === 'ready'
            && ($runtimeCertification['status'] ?? null) === 'ready';

        $payload = [
            'schema_version' => self::PILOT_DOSSIER_SCHEMA_VERSION,
            'status' => $ready ? 'ready_for_operator_execution' : 'blocked',
            'proof_type' => 'company_repo_frontend_pilot_dossier',
            'source' => self::class,
            'task_hash' => $task !== '' ? hash('sha256', $task) : null,
            'workspace_hash' => $workspace !== '' ? hash('sha256', $workspace) : null,
            'provider' => $provider,
            'output_path_hash' => hash('sha256', $output),
            'readiness' => [
                'enterprise_bootstrap_ready' => ($bootstrap['status'] ?? null) === 'ready',
                'provider_packet_ready' => ($providerPacket['status'] ?? null) === 'ready',
                'runbook_ready' => ($runbook['status'] ?? null) === 'ready',
                'evidence_kit_ready' => ($evidenceKit['status'] ?? null) === 'ready',
                'frontend_runtime_certified' => ($runtimeCertification['status'] ?? null) === 'ready',
                'operator_execution_required' => true,
                'measured_evidence_present' => false,
                'rival_replay_present' => false,
                'world_best_claim_allowed' => false,
            ],
            'hash_refs' => [
                'enterprise_bootstrap_hash' => $bootstrap['enterprise_bootstrap_hash'] ?? null,
                'provider_instruction_packet_hash' => $providerPacket['provider_instruction_packet_hash'] ?? null,
                'runbook_hash' => $runbook['runbook_hash'] ?? null,
                'evidence_kit_hash' => $evidenceKit['evidence_kit_hash'] ?? null,
                'runtime_certification_hash' => $runtimeCertification['certification_hash'] ?? null,
                'product_proof_hash' => $catalog['product_proof_hash'] ?? null,
            ],
            'execution_contract' => [
                'provider_mandates' => $providerPacket['provider_mandates'] ?? [],
                'forbidden_provider_behaviors' => $providerPacket['forbidden_provider_behaviors'] ?? [],
                'runbook_steps' => $runbook['runbook_steps'] ?? [],
                'collection_commands' => $evidenceKit['collection_commands'] ?? [],
            ],
            'required_next_actions' => $ready
                ? [
                    'dispatch_provider_with_provider_instruction_packet',
                    'execute_runbook_in_local_company_repo',
                    'replace_evidence_templates_with_measured_artifacts',
                    'run_frontend_run_certify_and_delivery_handoff',
                    'run_real_rival_replay_before_world_best_claim',
                ]
                : array_values(array_unique(array_merge(
                    (array) ($bootstrap['required_next_actions'] ?? []),
                    (array) ($providerPacket['required_next_actions'] ?? []),
                    (array) ($runbook['required_next_actions'] ?? []),
                    (array) ($evidenceKit['required_next_actions'] ?? []),
                ))),
            'claim_policy' => [
                'pilot_dossier_is_pre_execution_proof' => true,
                'ready_for_operator_execution_is_not_delivery_done' => true,
                'measured_evidence_required_for_done_claim' => true,
                'rival_replay_required_for_market_superiority_claim' => true,
                'raw_customer_source_returned' => false,
                'world_best_claim_allowed' => false,
            ],
            'artifact_refs' => [
                'dossier' => 'pilot-dossier.json',
                'evidence_kit_manifest' => 'evidence-kit/evidence-kit-manifest.json',
                'provider_packet_embedded' => true,
                'runbook_embedded' => true,
            ],
            'blockers' => $blockers,
            'warnings' => $warnings,
        ];
        $payload['pilot_dossier_hash'] = MissionCanonicalHash::sha256($payload);

        File::put($output.'/pilot-dossier.json', json_encode($payload, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE).PHP_EOL);

        return $payload;
    }

    /**
     * @return array<int,array<string,mixed>>
     */
    private function demos(): array
    {
        return [
            $this->demo('saas_dashboard_repair', 'Enterprise SaaS dashboard repair with dense tables, filters, charts and stateful controls.', ['desktop', 'tablet', 'mobile'], ['visual_smoke', 'anti_slop', 'a11y_or_reason', 'state_transition']),
            $this->demo('ecommerce_product_page', 'Commerce product detail page with media gallery, variant selector, cart state and trust content.', ['desktop', 'mobile'], ['visual_smoke', 'asset_provenance', 'anti_slop', 'performance_budget_or_reason']),
            $this->demo('mobile_app_onboarding', 'Mobile onboarding and settings flow with motion-safe transitions and accessibility-friendly controls.', ['mobile'], ['visual_smoke', 'state_transition', 'a11y_or_reason', 'design_5d_review']),
            $this->demo('design_system_migration', 'Legacy UI migrated into tokenized design system with before/after evidence and drift checks.', ['desktop', 'tablet', 'mobile'], ['design_system_drift_check', 'anti_slop', 'visual_smoke', 'completion_hash']),
            $this->demo('live_mode_repair_loop', 'Browser-selected component receives preview variants, accepted source patch and recovery journal.', ['desktop'], ['browser_pick_event', 'preview_variant_event', 'accepted_variant_diff', 'recover_session']),
        ];
    }

    /**
     * @param  array<int,string>  $viewports
     * @param  array<int,string>  $evidence
     * @return array<string,mixed>
     */
    private function demo(string $id, string $summary, array $viewports, array $evidence): array
    {
        return [
            'id' => $id,
            'summary' => $summary,
            'required_viewports' => $viewports,
            'required_evidence' => $evidence,
            'artifact_manifest_path' => 'docs/engineering-knowledge-base/domains/programming-frontend-product-proof-'.$id.'.md',
            'minimum_claim' => 'demonstrates_atlas_frontend_capability_when_artifact_manifest_exists',
        ];
    }

    /**
     * @param  array<int,array<string,mixed>>  $demos
     * @return array<int,array<string,mixed>>
     */
    private function checks(array $demos): array
    {
        return array_map(function (array $demo): array {
            $path = (string) $demo['artifact_manifest_path'];
            $absolute = base_path($path);
            $present = File::isFile($absolute);

            return [
                'id' => 'demo_manifest_'.$demo['id'],
                'status' => $present ? 'ready' : 'missing',
                'severity' => 'warn',
                'path' => $path,
                'content_hash' => $present ? hash('sha256', File::get($absolute)) : null,
            ];
        }, $demos);
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
     * @param  array<string,mixed>  $demo
     */
    private function demoHtml(array $demo): string
    {
        $title = htmlspecialchars((string) $demo['id'], ENT_QUOTES, 'UTF-8');
        $summary = htmlspecialchars((string) $demo['summary'], ENT_QUOTES, 'UTF-8');
        $evidence = implode('', array_map(
            fn (string $item): string => '<li>'.htmlspecialchars($item, ENT_QUOTES, 'UTF-8').'</li>',
            (array) $demo['required_evidence'],
        ));
        $viewports = implode('', array_map(
            fn (string $item): string => '<li>'.htmlspecialchars($item, ENT_QUOTES, 'UTF-8').'</li>',
            (array) $demo['required_viewports'],
        ));

        return <<<HTML
        <!doctype html>
        <html lang="en">
        <head>
          <meta charset="utf-8">
          <meta name="viewport" content="width=device-width, initial-scale=1">
          <title>Atlas Frontend Product Proof - {$title}</title>
          <style>
            body{font-family:ui-sans-serif,system-ui,sans-serif;margin:0;color:#17202a;background:#f8fafc}
            main{max-width:920px;margin:0 auto;padding:48px 24px}
            header{border-bottom:1px solid #d7dde5;padding-bottom:24px}
            section{margin-top:28px}
            code{background:#e8edf3;padding:2px 6px;border-radius:4px}
            .panel{border:1px solid #d7dde5;border-radius:8px;padding:20px;background:white}
          </style>
        </head>
        <body>
          <main>
            <header>
              <p><a href="./index.html">Atlas Frontend Product Proof</a></p>
              <h1>{$title}</h1>
              <p>{$summary}</p>
            </header>
            <section class="panel">
              <h2>Required Evidence</h2>
              <ul>{$evidence}</ul>
            </section>
            <section class="panel">
              <h2>Required Viewports</h2>
              <ul>{$viewports}</ul>
            </section>
            <section class="panel">
              <h2>Claim Boundary</h2>
              <p>This local static proof is publishable, but public distribution claims require hosted URL verification and rival replay.</p>
            </section>
          </main>
        </body>
        </html>
        HTML;
    }

    /**
     * @param  array<int,array<string,mixed>>  $demos
     */
    private function indexHtml(array $demos): string
    {
        $items = implode('', array_map(function (array $demo): string {
            $id = htmlspecialchars((string) $demo['id'], ENT_QUOTES, 'UTF-8');
            $summary = htmlspecialchars((string) $demo['summary'], ENT_QUOTES, 'UTF-8');

            return '<li><a href="./'.$id.'.html">'.$id.'</a><p>'.$summary.'</p></li>';
        }, $demos));

        return <<<HTML
        <!doctype html>
        <html lang="en">
        <head>
          <meta charset="utf-8">
          <meta name="viewport" content="width=device-width, initial-scale=1">
          <title>Atlas Frontend Product Proof</title>
          <style>
            body{font-family:ui-sans-serif,system-ui,sans-serif;margin:0;color:#17202a;background:#f8fafc}
            main{max-width:960px;margin:0 auto;padding:48px 24px}
            li{margin:18px 0;padding:16px;border:1px solid #d7dde5;border-radius:8px;background:white}
            a{color:#0f5f8f;font-weight:700}
          </style>
        </head>
        <body>
          <main>
            <h1>Atlas Frontend Product Proof</h1>
            <p>Static local bundle for Atlas Frontend demo proof. External hosting and rival replay are still required for world-best claims.</p>
            <ol>{$items}</ol>
          </main>
        </body>
        </html>
        HTML;
    }
}
