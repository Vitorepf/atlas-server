<?php

declare(strict_types=1);

namespace App\Console\Commands;

use App\Services\Ai\Aaeos\Generated\AtlasProgrammingFrontendProductProofEcommerceService;
use Illuminate\Console\Command;
use Throwable;

/**
 * Ecommerce product-page product-proof gate evaluator CLI.
 *
 *   php artisan atlas:aaeos:programming-frontend-product-proof-ecommerce
 *     [--viewports=desktop,mobile]
 *     [--evidence=visual_smoke,asset_provenance,anti_slop,performance_budget_or_reason]
 *     [--surfaces=media_gallery,variant_selector,cart_state,trust_content]
 *     [--performance-reason="below-budget asset set, reason logged"]
 *     [--claims-checkout]
 *     [--checkout-tested]
 *     [--checkout-scope-explicit]
 *     [--json]
 *
 * Read-only, deterministic. Emits the ready/blocked/claim_violation verdict +
 * receipt for one declared ecommerce product-page demo. With no options it
 * evaluates the canonical fully-green sample.
 *
 * @see docs/engineering-knowledge-base/domains/programming-frontend-product-proof-ecommerce_product_page.md
 */
class AtlasProgrammingFrontendProductProofEcommerceCommand extends Command
{
    protected $signature = 'atlas:aaeos:programming-frontend-product-proof-ecommerce
        {--viewports= : comma-separated rendered viewports (default desktop,mobile)}
        {--evidence= : comma-separated captured evidence ids (default all four gates)}
        {--surfaces= : comma-separated exercised UI surfaces (default gallery,variant,cart,trust)}
        {--performance-reason= : documented reason when no performance budget was captured}
        {--claims-checkout : the demo claims a functional/real checkout}
        {--checkout-tested : a passing checkout test exists}
        {--checkout-scope-explicit : the checkout scope is explicitly declared}
        {--json : machine-readable JSON output (default true)}';

    protected $description = 'Atlas frontend · ecommerce product-page product-proof gate (ready|blocked|claim_violation).';

    public function handle(AtlasProgrammingFrontendProductProofEcommerceService $service): int
    {
        try {
            $demo = $service->readySample();

            foreach (['viewports' => 'viewports', 'evidence' => 'evidence', 'surfaces' => 'surfaces'] as $opt => $key) {
                $raw = $this->option($opt);
                if (is_string($raw) && trim($raw) !== '') {
                    $demo[$key] = array_values(array_filter(
                        array_map('trim', explode(',', $raw)),
                        static fn ($v) => $v !== '',
                    ));
                }
            }

            $reason = $this->option('performance-reason');
            if (is_string($reason) && trim($reason) !== '') {
                $demo['performance_reason'] = trim($reason);
            }

            $demo['claims_checkout'] = (bool) $this->option('claims-checkout');
            $demo['checkout_tested'] = (bool) $this->option('checkout-tested');
            $demo['checkout_scope_explicit'] = (bool) $this->option('checkout-scope-explicit');

            $verdict = $service->evaluate($demo);

            $this->line((string) json_encode(
                ['ok' => true, 'verdict' => $verdict],
                JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES,
            ));

            return self::SUCCESS;
        } catch (Throwable $e) {
            $this->line((string) json_encode([
                'ok' => false,
                'error' => 'ecommerce_product_proof_failed',
                'message' => $e->getMessage(),
            ], JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES));

            return self::FAILURE;
        }
    }
}
