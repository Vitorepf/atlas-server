<?php

declare(strict_types=1);

namespace App\Services\Ai\Rivals\Core;

use App\Services\Ai\Kernel\Evidence\AtlasEvidenceLedger;
use App\Services\Ai\Kernel\Evidence\LedgerEventType;
use InvalidArgumentException;

/**
 * Sole in-process owner for the scoped Rivals claim lifecycle. Issue/expire/revoke are
 * the only writers of `claim.evaluated`/`claim.issued`/`claim.revoked`; every other
 * service is a read-only consumer (enforced by RivalsClaimAuthorityStaticScanner). When
 * a canonical ledger is supplied the transitions are emitted as `atlas_ledger_events`;
 * with no ledger the lifecycle still runs in-process for hermetic tests.
 */
final class RivalsClaimAuthority
{
    /** Run-state values that count as state-machine completion for issuance. */
    private const COMPLETION_STATES = [RunStateMachine::REPORTED, RunStateMachine::BUNDLED];

    /** Scope values that would over-generalise a claim into a universal one. */
    private const UNIVERSAL_TOKENS = ['*', 'all', 'any', 'universal', '__all__'];

    /** @param array<string,mixed> $evidence @return array<string,mixed> */
    public function issue(array $evidence, ?AtlasEvidenceLedger $ledger = null): array
    {
        try {
            $this->assertEvidence($evidence);
        } catch (InvalidArgumentException $e) {
            $this->emit($ledger, 'claim.evaluated', LedgerEventType::GateEvaluated, [
                'decision' => 'rejected',
                'reason' => $e->getMessage(),
                'experiment_hash' => $evidence['experiment_hash'] ?? null,
            ]);
            throw $e;
        }

        $this->emit($ledger, 'claim.evaluated', LedgerEventType::GateEvaluated, [
            'decision' => 'issued',
            'experiment_hash' => $evidence['experiment_hash'],
        ]);

        $claimId = 'rivals-'.substr(hash('sha256', json_encode($evidence, JSON_THROW_ON_ERROR)), 0, 24);
        $claim = [
            'schema_version' => 'atlas.rivals.claim.v1', 'claim_id' => $claimId, 'status' => 'issued',
            'level' => $evidence['claim_level'], 'scope' => $evidence['scope'],
            'run_state' => $evidence['run_state'], 'metric_weights' => $evidence['metric_weights'],
            'baseline_hash' => $evidence['baseline_hash'], 'evidence_pack_hash' => $evidence['evidence_pack_hash'],
            'experiment_hash' => $evidence['experiment_hash'], 'effect' => $evidence['effect'],
            'ci' => ['low' => $evidence['ci_low'], 'high' => $evidence['ci_high']], 'exposure' => $evidence['exposure'],
            'evidence_refs' => array_values($evidence['evidence_refs']),
            'issued_at' => $evidence['issued_at'], 'expires_at' => $evidence['expires_at'],
            'invalidators' => $evidence['invalidators'], 'claim_eligible' => true,
        ];

        $this->emit($ledger, 'claim.issued', LedgerEventType::GatePassed, [
            'claim_id' => $claimId, 'level' => $claim['level'], 'experiment_hash' => $claim['experiment_hash'],
        ]);

        return $claim;
    }

    /** @param array<string,mixed> $claim @return array<string,mixed> */
    public function expire(array $claim, string $reason, ?AtlasEvidenceLedger $ledger = null): array
    {
        $expired = $this->transition($claim, 'expired', $reason);
        $this->emit($ledger, 'claim.evaluated', LedgerEventType::GateEvaluated, [
            'decision' => 'expired', 'claim_id' => $claim['claim_id'] ?? null, 'reason' => $reason,
        ]);

        return $expired;
    }

    /** @param array<string,mixed> $claim @return array<string,mixed> */
    public function revoke(array $claim, string $reason, ?AtlasEvidenceLedger $ledger = null): array
    {
        $revoked = $this->transition($claim, 'revoked', $reason);
        $this->emit($ledger, 'claim.revoked', LedgerEventType::GateBlocked, [
            'claim_id' => $claim['claim_id'] ?? null, 'reason' => $reason,
        ]);

        return $revoked;
    }

    /**
     * A material frontier/harness/regression change, or any preregistered invalidator
     * present in the change set, forces the claim back through revalidation.
     *
     * @param  array<string,mixed>  $claim
     * @param  array<string,mixed>|list<string>  $changeSet
     */
    public function requiresRevalidation(array $claim, array $changeSet): bool
    {
        $invalidators = array_map('strval', (array) ($claim['invalidators'] ?? []));
        $signals = array_map('strval', array_is_list($changeSet) ? $changeSet : (array) ($changeSet['signals'] ?? []));
        foreach ($signals as $signal) {
            if (in_array($signal, $invalidators, true)
                || in_array($signal, ['material_frontier_change', 'harness_change', 'material_regression'], true)) {
                return true;
            }
        }

        return false;
    }

    /** @param array<string,mixed> $evidence */
    private function assertEvidence(array $evidence): void
    {
        foreach (['scope', 'run_state', 'metric_weights', 'baseline_hash', 'evidence_pack_hash', 'experiment_hash', 'effect', 'ci_low', 'ci_high', 'exposure', 'issued_at', 'expires_at', 'invalidators', 'evidence_refs'] as $field) {
            if (! array_key_exists($field, $evidence)) {
                throw new InvalidArgumentException('rivals_claim_'.$field.'_required');
            }
        }
        if (($evidence['adjudication_status'] ?? null) !== 'passed') {
            throw new InvalidArgumentException('rivals_claim_adjudication_required');
        }
        if (! in_array($evidence['run_state'], self::COMPLETION_STATES, true)) {
            throw new InvalidArgumentException('rivals_claim_run_state_incomplete');
        }
        if (! in_array($evidence['claim_level'] ?? null, ['multiplier_proven', 'world_leading', 'world_10x_quality_proven'], true)) {
            throw new InvalidArgumentException('rivals_claim_level_invalid');
        }
        if (! is_array($evidence['scope']) || $evidence['scope'] === [] || ! is_array($evidence['exposure']) || $evidence['exposure'] === []) {
            throw new InvalidArgumentException('rivals_claim_scope_or_exposure_required');
        }
        if (! is_array($evidence['metric_weights']) || $evidence['metric_weights'] === []) {
            throw new InvalidArgumentException('rivals_claim_metric_weights_required');
        }
        foreach (['mode', 'risk', 'duration', 'stack', 'unit_population'] as $scopeField) {
            $value = trim((string) ($evidence['scope'][$scopeField] ?? ''));
            if ($value === '') {
                throw new InvalidArgumentException('rivals_claim_scope_incomplete');
            }
            if (in_array(strtolower($value), self::UNIVERSAL_TOKENS, true)) {
                throw new InvalidArgumentException('rivals_claim_scope_universal_forbidden:'.$scopeField);
            }
        }
        if (! is_array($evidence['evidence_refs']) || $evidence['evidence_refs'] === []
            || count(array_filter($evidence['evidence_refs'], static fn ($ref): bool => is_string($ref) && trim($ref) !== '')) !== count($evidence['evidence_refs'])) {
            throw new InvalidArgumentException('rivals_claim_evidence_refs_required');
        }
        foreach (['baseline_hash', 'evidence_pack_hash', 'experiment_hash'] as $field) {
            if (preg_match('/^[a-f0-9]{64}$/', (string) $evidence[$field]) !== 1) {
                throw new InvalidArgumentException('rivals_claim_'.$field.'_invalid');
            }
        }
        if (! is_numeric($evidence['effect']) || ! is_numeric($evidence['ci_low']) || ! is_numeric($evidence['ci_high'])
            || (float) $evidence['ci_low'] <= 0 || (float) $evidence['ci_low'] > (float) $evidence['ci_high']
            || (float) $evidence['effect'] < (float) $evidence['ci_low'] || (float) $evidence['effect'] > (float) $evidence['ci_high']) {
            throw new InvalidArgumentException('rivals_claim_effect_interval_invalid');
        }
        $issuedAt = date_create_immutable((string) $evidence['issued_at']);
        $expiresAt = date_create_immutable((string) $evidence['expires_at']);
        if ($issuedAt === false || $expiresAt === false || $expiresAt <= $issuedAt || $expiresAt > $issuedAt->modify('+90 days')) {
            throw new InvalidArgumentException('rivals_claim_expiry_invalid');
        }
        if (! is_array($evidence['invalidators']) || $evidence['invalidators'] === []) {
            throw new InvalidArgumentException('rivals_claim_invalidators_required');
        }
        if ((int) ($evidence['exposure']['campaigns'] ?? 0) < 3 || (int) ($evidence['exposure']['attempts'] ?? 0) <= 0) {
            throw new InvalidArgumentException('rivals_claim_exposure_insufficient');
        }

        // P2g-CURR: strong multiplier claims require non-saturated frontier curriculum.
        $curriculumContext = [
            'curriculum_role' => $evidence['curriculum_role']
                ?? data_get($evidence, 'scope.curriculum_role')
                ?? null,
            'level_id' => $evidence['level_id'] ?? data_get($evidence, 'scope.level_id') ?? null,
            'frontier_saturated' => (bool) ($evidence['frontier_saturated']
                ?? data_get($evidence, 'scope.frontier_saturated')
                ?? false),
            'claim_level' => $evidence['claim_level'] ?? null,
            'm_excellence_claim' => (bool) ($evidence['m_excellence_claim'] ?? false),
            'm_excellence' => $evidence['m_excellence'] ?? null,
            'anti_ceiling_argument' => (bool) ($evidence['anti_ceiling_argument'] ?? false),
            'high_score_on_easy_as_max_multiplier' => (bool) (
                $evidence['high_score_on_easy_as_max_multiplier'] ?? false
            ),
        ];
        $curriculumBlockers = RivalsCurriculumLadder::claimBlockers($curriculumContext);
        if ($curriculumBlockers !== []) {
            throw new InvalidArgumentException('rivals_claim_curriculum:'.implode(',', $curriculumBlockers));
        }
    }

    /** @param array<string,mixed> $claim @return array<string,mixed> */
    private function transition(array $claim, string $status, string $reason): array
    {
        if (! in_array($claim['status'] ?? null, ['issued'], true)) {
            throw new InvalidArgumentException('rivals_claim_transition_forbidden');
        }
        if (trim($reason) === '') {
            throw new InvalidArgumentException('rivals_claim_transition_reason_required');
        }
        $claim['status'] = $status;
        $claim['claim_eligible'] = false;
        $claim['transition_reason'] = $reason;

        return $claim;
    }

    /**
     * Emit a canonical claim event when a ledger is present. No-op otherwise, so the
     * lifecycle stays hermetic in tests. This is the only path that writes claim events.
     *
     * @param  array<string,mixed>  $payload
     */
    private function emit(?AtlasEvidenceLedger $ledger, string $eventName, LedgerEventType $type, array $payload): void
    {
        if (! $ledger instanceof AtlasEvidenceLedger) {
            return;
        }
        $scopeId = (string) ($payload['claim_id'] ?? $payload['experiment_hash'] ?? 'unknown');
        $ledger->record($type, ['event_name' => $eventName] + $payload, [
            'event_id' => $eventName.'-'.substr(hash('sha256', $eventName.'|'.$scopeId), 0, 24),
            'correlation_id' => $scopeId,
            'scope_type' => 'rivals_claim',
            'scope_id' => $scopeId,
            'emitter_stage' => 'atlas.rivals.claim_authority',
        ]);
    }
}
