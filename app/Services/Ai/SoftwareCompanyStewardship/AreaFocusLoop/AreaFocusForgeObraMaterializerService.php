<?php

declare(strict_types=1);

namespace App\Services\Ai\SoftwareCompanyStewardship\AreaFocusLoop;

use App\Models\AtlasProject;
use App\Services\Ai\Mission\MissionCanonicalHash;
use App\Services\Ai\Programming\AtlasCodeForgeWorkIntakeService;
use Illuminate\Support\Str;

/**
 * S2 · Area Focus Forge Obra Materializer.
 *
 * Turns an explicit operator ACCEPT decision (AP-724 receipt) + a ready Forge
 * handoff (AP-729 packet with obra_candidate) into a REAL governed Obra
 * (AtlasProject) and returns its obra_id, so owner=forge can be dispatched.
 *
 * Honesty laws (anti-false-execution):
 *  - It NEVER fabricates an Obra. Creation is gated strictly on an explicit,
 *    integrity-verified operator accept receipt. No accept => no Obra.
 *  - Creating the AtlasProject is the ONLY mutation. It is NOT forge execution:
 *    the result reports forge_executed=false / mutates_target_repo=false. The
 *    cycle must still treat owner=forge as planned/blocked until a real provider
 *    runs (owner_cli_provider_calls>0) — that gate lives in the owner-flow.
 *  - Idempotent: the same (handoff_id, decision_id, finding_hash) never creates
 *    a second Obra; a partial-key collision blocks rather than reusing a wrong
 *    Obra.
 *
 * Mirrors the create+work-intake precedent in
 * AtlasSelfImprovementForgeActivationService::materialiseObra and the
 * idempotency discipline in DevToForgePromotionService — without duplicating
 * the AP-724 decision or AP-729 handoff surfaces (those are consumed, not rebuilt).
 */
final class AreaFocusForgeObraMaterializerService
{
    public const RESULT_SCHEMA = 'atlas.software_company_stewardship.area_focus_forge_obra_materialization.v1';

    public const ORIGIN = 'area-focus-forge-handoff';

    public const STATUS_OBRA_CREATED = 'obra_created';

    public const STATUS_IDEMPOTENT = 'obra_already_materialized';

    public const STATUS_BLOCKED = 'blocked';

    public const BLOCK_ACTOR_REQUIRED = 'operator_actor_required';

    public const BLOCK_RECEIPT_REQUIRED = 'decision_receipt_required';

    public const BLOCK_RECEIPT_INTEGRITY = 'decision_receipt_integrity_failed';

    public const BLOCK_NOT_ACCEPT = 'decision_not_accept';

    public const BLOCK_WORK_ORDER_MISMATCH = 'work_order_mismatch';

    public const BLOCK_HANDOFF_NOT_READY = 'forge_handoff_not_ready';

    public const BLOCK_HANDOFF_COLLISION = 'handoff_identity_collision';

    public function __construct(
        private readonly AtlasCodeForgeWorkIntakeService $forgeWorkIntake,
    ) {}

    /**
     * @param  array<string,mixed>  $input
     * @return array<string,mixed>
     */
    public function materialize(array $input): array
    {
        $actor = trim((string) ($input['operator_actor'] ?? ''));
        if ($actor === '') {
            return $this->blocked(self::BLOCK_ACTOR_REQUIRED, 'operator_actor is required; an Obra is a unit of work authorization and is never materialized without an owning operator.');
        }

        $receipt = is_array($input['decision_receipt'] ?? null) ? $input['decision_receipt'] : null;
        if ($receipt === null) {
            return $this->blocked(self::BLOCK_RECEIPT_REQUIRED, 'A real AP-724 operator decision receipt is required to materialize an Obra.');
        }

        // SEC-005 authenticity: recompute the canonical decision_hash and compare.
        // A hand-crafted JSON blob (e.g. from a runaway autonomous loop) will not
        // reproduce the issuer's hash, so it is rejected — structure alone is not
        // a security decision.
        if (! $this->receiptIntegrityHolds($receipt)) {
            return $this->blocked(self::BLOCK_RECEIPT_INTEGRITY, 'Decision receipt failed integrity verification: its decision_hash does not match a canonical recomputation. Only a receipt issued by the operator decision service is accepted.');
        }

        if ((string) ($receipt['decision'] ?? '') !== 'accept') {
            return $this->blocked(self::BLOCK_NOT_ACCEPT, 'Only an explicit operator accept decision materializes an Obra; reject/defer/request_changes never create work authorization.');
        }

        $handoff = is_array($input['handoff'] ?? null) ? $input['handoff'] : [];
        $candidate = is_array($handoff['obra_candidate'] ?? null) ? $handoff['obra_candidate'] : [];
        if ($candidate === [] || (bool) ($candidate['forge_executed'] ?? false) === true) {
            return $this->blocked(self::BLOCK_HANDOFF_NOT_READY, 'A ready AP-729 forge handoff with a not-yet-executed obra_candidate is required.');
        }

        // Receipt and handoff must describe the SAME work order.
        $receiptWo = trim((string) ($receipt['work_order_id'] ?? ''));
        $candidateWo = trim((string) ($candidate['source_work_order_id'] ?? ''));
        if ($receiptWo !== '' && $candidateWo !== '' && $receiptWo !== $candidateWo) {
            return $this->blocked(self::BLOCK_WORK_ORDER_MISMATCH, 'The accept receipt and the forge handoff name different work orders; refusing to materialize an Obra from mismatched authorization.', [
                'receipt_work_order_id' => $receiptWo,
                'handoff_work_order_id' => $candidateWo,
            ]);
        }

        $handoffId = trim((string) ($handoff['handoff_id'] ?? ''));
        $decisionId = trim((string) ($receipt['decision_id'] ?? ''));
        $findingHash = trim((string) ($receipt['finding_hash'] ?? ''));

        // SEC-006 idempotency on the full identity triple.
        $idempotent = $this->existingObra($handoffId, $decisionId, $findingHash);
        if ($idempotent instanceof AtlasProject) {
            return $this->result(self::STATUS_IDEMPOTENT, $idempotent, $handoffId, $decisionId, $findingHash, true, null);
        }
        if ($idempotent === false) {
            return $this->blocked(self::BLOCK_HANDOFF_COLLISION, 'An Obra exists for this handoff_id but with a different decision_id/finding_hash. Refusing to reuse a mismatched Obra or create a duplicate.', [
                'handoff_id' => $handoffId,
            ]);
        }

        $obra = $this->createObra($candidate, $handoff, $receipt, $actor, $handoffId, $decisionId, $findingHash);
        $intake = $this->populateIntake($obra, $candidate, $actor, $handoffId);

        return $this->result(self::STATUS_OBRA_CREATED, $obra, $handoffId, $decisionId, $findingHash, false, $intake);
    }

    /**
     * @param  array<string,mixed>  $receipt
     */
    private function receiptIntegrityHolds(array $receipt): bool
    {
        $claimed = trim((string) ($receipt['decision_hash'] ?? ''));
        if ($claimed === '') {
            return false;
        }
        // The issuer computes decision_hash over the receipt WITHOUT decision_hash
        // and decided_at (both are added afterwards). Reproduce exactly that.
        $canonical = $receipt;
        unset($canonical['decision_hash'], $canonical['decided_at']);
        $recomputed = 'sha256:'.MissionCanonicalHash::sha256($canonical);

        return hash_equals($recomputed, $claimed);
    }

    /**
     * Returns the existing Obra when the FULL identity triple matches
     * (idempotent), false on a partial/conflicting match (collision), or null
     * when no Obra exists yet for this handoff.
     */
    private function existingObra(string $handoffId, string $decisionId, string $findingHash): AtlasProject|false|null
    {
        if ($handoffId === '') {
            return null;
        }
        $existing = AtlasProject::query()
            ->where('metadata->'.self::ORIGIN.'->handoff_id', $handoffId)
            ->first();
        if (! $existing instanceof AtlasProject) {
            return null;
        }
        $meta = (array) data_get($existing->metadata, self::ORIGIN, []);
        $sameDecision = (string) ($meta['decision_id'] ?? '') === $decisionId;
        $sameFinding = (string) ($meta['finding_hash'] ?? '') === $findingHash;

        return ($sameDecision && $sameFinding) ? $existing : false;
    }

    /**
     * @param  array<string,mixed>  $candidate
     * @param  array<string,mixed>  $handoff
     * @param  array<string,mixed>  $receipt
     */
    private function createObra(array $candidate, array $handoff, array $receipt, string $actor, string $handoffId, string $decisionId, string $findingHash): AtlasProject
    {
        return AtlasProject::create([
            'id' => (string) Str::uuid(),
            'title' => (string) ($candidate['title'] ?? 'Area Focus Forge Obra'),
            'description' => (string) ($candidate['objective'] ?? ''),
            'status' => 'active',
            'domain' => 'programming',
            'goal' => (string) ($candidate['objective'] ?? ''),
            'desired_outcome' => (string) (data_get($candidate, 'risk_policy.desired_outcome') ?? $candidate['objective'] ?? ''),
            'priority' => 'normal',
            'metadata' => [
                'origin' => self::ORIGIN,
                self::ORIGIN => [
                    'handoff_id' => $handoffId,
                    'decision_id' => $decisionId,
                    'finding_hash' => $findingHash,
                    'work_order_id' => (string) ($candidate['source_work_order_id'] ?? ''),
                    'area_id' => (string) ($handoff['area_id'] ?? ''),
                    'operator_actor' => $actor,
                    'decision_hash' => (string) ($receipt['decision_hash'] ?? ''),
                ],
                // Honest markers: an Obra now exists, but nothing was executed.
                'forge_executed' => false,
                'mutates_target_repo' => false,
            ],
        ]);
    }

    /**
     * @param  array<string,mixed>  $candidate
     * @return array<string,mixed>
     */
    private function populateIntake(AtlasProject $obra, array $candidate, string $actor, string $handoffId): array
    {
        return $this->forgeWorkIntake->save($obra, [
            'objective' => (string) ($candidate['objective'] ?? ''),
            'business_rule' => (string) (data_get($candidate, 'constraints.business_rule') ?? ''),
            'acceptance_criteria' => (array) (data_get($candidate, 'risk_policy.acceptance_gates') ?? $candidate['acceptance_gates'] ?? []),
            'canonical_docs' => array_values((array) (data_get($candidate, 'scope.canonical_docs') ?? [])),
            'scope_in' => array_values((array) (data_get($candidate, 'scope.allowed_paths') ?? [])),
            'scope_out' => array_values((array) (data_get($candidate, 'scope.forbidden_paths') ?? [])),
            'risk_level' => (string) (data_get($candidate, 'risk_policy.risk_level') ?? 'medium'),
            'constraints' => [
                'never_call_provider_without_explicit_approval',
                'never_promote_completion_claim_from_obra_creation',
            ],
            'operator_notes' => 'Materialized from Area Focus Forge handoff '.$handoffId.' by '.$actor.'.',
        ]);
    }

    /**
     * @param  array<string,mixed>|null  $intake
     * @return array<string,mixed>
     */
    private function result(string $status, AtlasProject $obra, string $handoffId, string $decisionId, string $findingHash, bool $idempotent, ?array $intake): array
    {
        $payload = [
            'schema_version' => self::RESULT_SCHEMA,
            'status' => $status,
            'created_obra_id' => (string) $obra->getKey(),
            'obra_title' => (string) $obra->title,
            'handoff_id' => $handoffId,
            'decision_id' => $decisionId,
            'finding_hash' => $findingHash,
            'idempotent' => $idempotent,
            // Hard honesty guarantees consumed downstream.
            'forge_executed' => false,
            'mutates_target_repo' => false,
            'provider_invoked' => false,
            'requires_owner_execution' => true,
            'next_allowed_action' => 'bootstrap_forge_authority_then_dispatch_owner_forge',
        ];
        if ($intake !== null) {
            $payload['work_intake'] = $intake;
        }
        $payload['materialization_hash'] = 'sha256:'.MissionCanonicalHash::sha256([
            'schema' => self::RESULT_SCHEMA,
            'obra_id' => $payload['created_obra_id'],
            'handoff_id' => $handoffId,
            'decision_id' => $decisionId,
            'finding_hash' => $findingHash,
        ]);

        return $payload;
    }

    /**
     * @param  array<string,mixed>  $extra
     * @return array<string,mixed>
     */
    private function blocked(string $blocker, string $detail, array $extra = []): array
    {
        return [
            'schema_version' => self::RESULT_SCHEMA,
            'status' => self::STATUS_BLOCKED,
            'created_obra_id' => null,
            'forge_executed' => false,
            'mutates_target_repo' => false,
            'blocker' => $blocker,
            'detail' => $detail,
        ] + $extra;
    }
}
