<?php

namespace App\Services\Ai\Programming\Frontend;

use App\Services\Ai\Mission\MissionCanonicalHash;
use Illuminate\Support\Facades\File;

final class AtlasFrontendProductProofRuntimeService
{
    public const SCHEMA_VERSION = 'atlas.frontend.product_proof_runtime.v1';

    public const BUNDLE_SCHEMA_VERSION = 'atlas.frontend.product_proof_bundle.v1';

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
