<?php

namespace App\Services\Ai\Rivals\Support;

/**
 * Fail-closed usage invariants for Hermes/Verboo production receipts.
 * Cost may be $0 with verboo_subscription_marginal only when usage is present.
 */
final class UsageCaptureContract
{
    /**
     * @param  array<string, mixed>  $receipt
     * @return list<string> blockers (empty = ok)
     */
    public function validate(array $receipt, string $provider = 'hermes'): array
    {
        if ($provider !== 'hermes') {
            return [];
        }
        if (($receipt['harness_only'] ?? false) === true) {
            return [];
        }
        if (($receipt['claim_tier'] ?? null) === 'harness') {
            return [];
        }

        $blockers = [];
        $presence = (array) ($receipt['field_presence'] ?? []);

        foreach (['tokens_in', 'tokens_out', 'wall_ms'] as $field) {
            $present = ($presence[$field]['present'] ?? null);
            if ($present !== true) {
                $reason = (string) ($presence[$field]['reason'] ?? 'missing');
                $blockers[] = "usage_capture_missing:{$field}:{$reason}";

                continue;
            }
            if ($field === 'wall_ms') {
                if (! is_numeric($receipt['wall_ms'] ?? null) || (float) $receipt['wall_ms'] < 0) {
                    $blockers[] = 'usage_capture_invalid:wall_ms';
                }

                continue;
            }
            $value = (int) ($receipt[$field] ?? 0);
            if ($value <= 0) {
                $blockers[] = "usage_capture_empty:{$field}";
            }
        }

        $costPresent = ($presence['cost_usd']['present'] ?? null) === true;
        $costReason = (string) ($presence['cost_usd']['reason'] ?? '');
        if ($costPresent
            && (float) ($receipt['cost_usd'] ?? 0) == 0.0
            && $costReason !== 'verboo_subscription_marginal') {
            $blockers[] = 'usage_capture_zero_cost_without_verboo_basis';
        }
        if (! $costPresent && $blockers === []) {
            // cost absence is allowed only when already blocked on tokens; otherwise flag.
            $blockers[] = 'usage_capture_missing:cost_usd:'.($presence['cost_usd']['reason'] ?? 'missing');
        }

        return array_values(array_unique($blockers));
    }

    /**
     * @param  array<string, mixed>  $receipt
     */
    public function isSatisfied(array $receipt, string $provider = 'hermes'): bool
    {
        return $this->validate($receipt, $provider) === [];
    }
}
