<?php

declare(strict_types=1);

namespace App\Console\Commands;

use App\Services\Ai\Aaeos\Generated\AtlasProgrammingFrontendProductProofMobileAppOnboardingService;
use Illuminate\Console\Command;
use Throwable;

/**
 * Mobile app-onboarding product-proof gate evaluator CLI.
 *
 *   php artisan atlas:aaeos:programming-frontend-product-proof-mobile-app-onboarding
 *     [--viewports=mobile]
 *     [--evidence=visual_smoke,state_transition,a11y_or_reason,design_5d_review]
 *     [--states=next,back,complete,settings]
 *     [--a11y-reason="reduced-motion verified manually, reason logged"]
 *     [--claims-published]
 *     [--build-proof]
 *     [--store-proof]
 *     [--json]
 *
 * Read-only, deterministic. Emits the ready/blocked/claim_violation verdict +
 * receipt for one declared mobile onboarding demo. With no options it evaluates
 * the canonical fully-green sample.
 *
 * @see docs/engineering-knowledge-base/domains/programming-frontend-product-proof-mobile_app_onboarding.md
 */
class AtlasProgrammingFrontendProductProofMobileAppOnboardingCommand extends Command
{
    protected $signature = 'atlas:aaeos:programming-frontend-product-proof-mobile-app-onboarding
        {--viewports= : comma-separated rendered viewports (default mobile)}
        {--evidence= : comma-separated captured evidence ids (default all four gates)}
        {--states= : comma-separated exercised onboarding states (default next,back,complete,settings)}
        {--a11y-reason= : documented reason when no accessibility check was captured}
        {--claims-published : the demo claims a published/native app}
        {--build-proof : a real build artifact/proof exists}
        {--store-proof : a store-publication proof exists}
        {--json : machine-readable JSON output (default true)}';

    protected $description = 'Atlas frontend · mobile onboarding product-proof gate (ready|blocked|claim_violation).';

    public function handle(AtlasProgrammingFrontendProductProofMobileAppOnboardingService $service): int
    {
        try {
            $demo = $service->readySample();

            foreach (['viewports' => 'viewports', 'evidence' => 'evidence', 'states' => 'states'] as $opt => $key) {
                $raw = $this->option($opt);
                if (is_string($raw) && trim($raw) !== '') {
                    $demo[$key] = array_values(array_filter(
                        array_map('trim', explode(',', $raw)),
                        static fn ($v) => $v !== '',
                    ));
                }
            }

            $reason = $this->option('a11y-reason');
            if (is_string($reason) && trim($reason) !== '') {
                $demo['a11y_reason'] = trim($reason);
            }

            $demo['claims_published'] = (bool) $this->option('claims-published');
            $demo['build_proof'] = (bool) $this->option('build-proof');
            $demo['store_proof'] = (bool) $this->option('store-proof');

            $verdict = $service->evaluate($demo);

            $this->line((string) json_encode(
                ['ok' => true, 'verdict' => $verdict],
                JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES,
            ));

            return self::SUCCESS;
        } catch (Throwable $e) {
            $this->line((string) json_encode([
                'ok' => false,
                'error' => 'mobile_app_onboarding_product_proof_failed',
                'message' => $e->getMessage(),
            ], JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES));

            return self::FAILURE;
        }
    }
}
