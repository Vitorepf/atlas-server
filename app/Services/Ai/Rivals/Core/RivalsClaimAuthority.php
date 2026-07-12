<?php

declare(strict_types=1);

namespace App\Services\Ai\Rivals\Core;

use InvalidArgumentException;

/** Sole in-process owner for scoped Rivals claim lifecycle. */
final class RivalsClaimAuthority
{
    /** @param array<string,mixed> $evidence @return array<string,mixed> */
    public function issue(array $evidence): array
    {
        $this->assertEvidence($evidence);
        $claimId = 'rivals-'.substr(hash('sha256', json_encode($evidence, JSON_THROW_ON_ERROR)), 0, 24);

        return [
            'schema_version' => 'atlas.rivals.claim.v1', 'claim_id' => $claimId, 'status' => 'issued',
            'level' => $evidence['claim_level'], 'scope' => $evidence['scope'],
            'baseline_hash' => $evidence['baseline_hash'], 'evidence_pack_hash' => $evidence['evidence_pack_hash'],
            'experiment_hash' => $evidence['experiment_hash'], 'effect' => $evidence['effect'],
            'ci' => ['low' => $evidence['ci_low'], 'high' => $evidence['ci_high']], 'exposure' => $evidence['exposure'],
            'issued_at' => $evidence['issued_at'], 'expires_at' => $evidence['expires_at'],
            'invalidators' => $evidence['invalidators'], 'claim_eligible' => true,
        ];
    }

    /** @param array<string,mixed> $claim @return array<string,mixed> */
    public function expire(array $claim, string $reason): array
    {
        return $this->transition($claim, 'expired', $reason);
    }

    /** @param array<string,mixed> $claim @return array<string,mixed> */
    public function revoke(array $claim, string $reason): array
    {
        return $this->transition($claim, 'revoked', $reason);
    }

    /** @param array<string,mixed> $evidence */
    private function assertEvidence(array $evidence): void
    {
        foreach (['scope','baseline_hash','evidence_pack_hash','experiment_hash','effect','ci_low','ci_high','exposure','issued_at','expires_at','invalidators'] as $field) {
            if (! array_key_exists($field, $evidence)) throw new InvalidArgumentException('rivals_claim_'.$field.'_required');
        }
        if (($evidence['adjudication_status'] ?? null) !== 'passed') throw new InvalidArgumentException('rivals_claim_adjudication_required');
        if (! in_array($evidence['claim_level'] ?? null, ['multiplier_proven','world_leading','world_10x_quality_proven'], true)) throw new InvalidArgumentException('rivals_claim_level_invalid');
        if (! is_array($evidence['scope']) || $evidence['scope'] === [] || ! is_array($evidence['exposure']) || $evidence['exposure'] === []) throw new InvalidArgumentException('rivals_claim_scope_or_exposure_required');
        foreach (['baseline_hash','evidence_pack_hash','experiment_hash'] as $field) if (preg_match('/^[a-f0-9]{64}$/', (string) $evidence[$field]) !== 1) throw new InvalidArgumentException('rivals_claim_'.$field.'_invalid');
        if (! is_numeric($evidence['effect']) || ! is_numeric($evidence['ci_low']) || ! is_numeric($evidence['ci_high']) || (float) $evidence['ci_low'] <= 0 || (float) $evidence['ci_low'] > (float) $evidence['ci_high']) throw new InvalidArgumentException('rivals_claim_effect_interval_invalid');
        if ((int) ($evidence['exposure']['campaigns'] ?? 0) < 3 || (int) ($evidence['exposure']['attempts'] ?? 0) <= 0) throw new InvalidArgumentException('rivals_claim_exposure_insufficient');
    }

    /** @param array<string,mixed> $claim @return array<string,mixed> */
    private function transition(array $claim, string $status, string $reason): array
    {
        if (! in_array($claim['status'] ?? null, ['issued'], true)) throw new InvalidArgumentException('rivals_claim_transition_forbidden');
        if (trim($reason) === '') throw new InvalidArgumentException('rivals_claim_transition_reason_required');
        $claim['status'] = $status; $claim['claim_eligible'] = false; $claim['transition_reason'] = $reason;

        return $claim;
    }
}
