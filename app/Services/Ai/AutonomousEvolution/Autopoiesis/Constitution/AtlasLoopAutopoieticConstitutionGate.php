<?php

declare(strict_types=1);

namespace App\Services\Ai\AutonomousEvolution\Autopoiesis\Constitution;

use Illuminate\Support\Facades\Log;
use Psr\Log\LoggerInterface;
use Throwable;

/**
 * ATLAS LOOP AUTOPOIETIC CONSTITUTION GATE — the SINGLE chokepoint every autopoietic scope-origination call MUST
 * traverse before the system is allowed to consider extending its own scope. The gate consumes the
 * {@see AtlasLoopAutopoieticConstitutionRegistry} (p1) and emits an
 * {@see AutopoieticScopeOriginationVerdict} for each {@see AutopoieticScopeOriginationRequest}.
 *
 * PÉTREO (anti-Goodhart, fail-closed):
 *  - A request whose target path starts with ANY forbidden_scopes prefix is REFUSED (`forbidden_scope`),
 *    regardless of operator receipt level — forbidden scopes cannot be unlocked by approval.
 *  - A request whose category requires a 'two-step' (or otherwise mandated) operator receipt and lacks a
 *    verified receipt at the required level is REFUSED (`operator_receipt_missing`).
 *  - Only requests that pass BOTH gates are admitted (`admitted`).
 *  - There is NO bypass: no env consult, no public method whose name matches /disable|bypass|override|force/i,
 *    no toggle, no flag. The single `admit()` method is the only entry point. Every decision is logged via a
 *    dedicated channel (a per-call audit trail). Construction-time choices cannot relax enforcement.
 */
final class AtlasLoopAutopoieticConstitutionGate
{
    private const REASON_FORBIDDEN_SCOPE = 'forbidden_scope';

    private const REASON_RECEIPT_MISSING = 'operator_receipt_missing';

    private const REASON_ADMITTED = 'admitted';

    /** thresholds that demand a verified operator receipt at the matching level (or stronger) */
    private const RECEIPT_REQUIRING_THRESHOLDS = ['explicit', 'two-step'];

    private readonly LoggerInterface $logger;

    public function __construct(
        private readonly AtlasLoopAutopoieticConstitutionRegistry $registry,
        ?LoggerInterface $logger = null,
    ) {
        $this->logger = $logger ?? $this->defaultLogger();
    }

    /**
     * The single entry point. Returns a verdict; downstream callers MUST refuse to act on a denied verdict.
     */
    public function admit(AutopoieticScopeOriginationRequest $req): AutopoieticScopeOriginationVerdict
    {
        if ($this->isForbiddenScope($req->targetPath)) {
            return $this->log($req, new AutopoieticScopeOriginationVerdict(false, self::REASON_FORBIDDEN_SCOPE, null));
        }

        $required = $this->registry->approvalThresholdFor($req->category);
        if (in_array($required, self::RECEIPT_REQUIRING_THRESHOLDS, true)
            && ! $this->receiptSatisfies($req->operatorReceiptLevel, $required)) {
            return $this->log($req, new AutopoieticScopeOriginationVerdict(false, self::REASON_RECEIPT_MISSING, $required));
        }

        return $this->log($req, new AutopoieticScopeOriginationVerdict(true, self::REASON_ADMITTED, $required === AtlasLoopAutopoieticConstitutionRegistry::UNKNOWN_CATEGORY_SENTINEL ? null : $required));
    }

    private function isForbiddenScope(string $targetPath): bool
    {
        $normalized = ltrim(trim($targetPath), '/');
        if ($normalized === '') {
            return true; // empty target is treated as forbidden (no path = no constitutional anchor)
        }
        foreach ($this->registry->forbiddenScopes() as $forbidden) {
            $forbidden = trim((string) $forbidden);
            if ($forbidden === '') {
                continue;
            }
            if ($normalized === $forbidden || str_starts_with($normalized, $forbidden.'/') || str_starts_with($normalized, $forbidden)) {
                return true;
            }
        }

        return false;
    }

    /**
     * A two-step receipt satisfies both 'explicit' and 'two-step' requirements; an explicit receipt satisfies
     * only 'explicit'. Anything else (null, unknown level) is insufficient — fail-closed.
     */
    private function receiptSatisfies(?string $provided, string $required): bool
    {
        if ($provided === null || $provided === '') {
            return false;
        }
        if ($provided === 'two-step') {
            return true;
        }
        if ($provided === 'explicit') {
            return $required === 'explicit';
        }

        return false;
    }

    private function log(AutopoieticScopeOriginationRequest $req, AutopoieticScopeOriginationVerdict $verdict): AutopoieticScopeOriginationVerdict
    {
        try {
            $this->logger->info('autopoietic_constitution_gate_decision', [
                'target_path' => $req->targetPath,
                'category' => $req->category,
                'operator_receipt_level' => $req->operatorReceiptLevel,
                'allowed' => $verdict->allowed,
                'reason_code' => $verdict->reasonCode,
                'operator_receipt_required' => $verdict->operatorReceiptRequired,
                'registry_fingerprint' => $this->registry->fingerprint(),
            ]);
        } catch (Throwable) {
            // Logging must never alter the verdict; a logger failure is non-load-bearing.
        }

        return $verdict;
    }

    private function defaultLogger(): LoggerInterface
    {
        try {
            return Log::channel('atlas_autopoiesis_constitution');
        } catch (Throwable) {
            try {
                return Log::channel(config('logging.default', 'stack'));
            } catch (Throwable) {
                return new \Psr\Log\NullLogger;
            }
        }
    }
}
