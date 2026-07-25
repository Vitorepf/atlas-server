<?php

namespace App\Services\Ai\OperatorIntelligence;

use App\Services\Ai\OperatorIntelligence\Support\OperatorLearningCandidateSupport;
use App\Models\OperatorLearningCandidate;
use App\Models\OperatorLearningSignal;
use App\Services\Ai\Kernel\Evidence\AtlasEvidenceLedger;
use App\Services\Ai\Kernel\Evidence\LedgerEventType;
use Illuminate\Database\Eloquent\Collection;
use Illuminate\Support\Carbon;
use InvalidArgumentException;
use Throwable;

class OperatorLearningCandidateService
{
    public function __construct(
        private readonly OperatorLearningGate $gate,
        private readonly OperatorProfileRegistry $registry,
    ) {}

    /**
     * @param  array<string,mixed>  $options
     */
    public function createFromSignal(OperatorLearningSignal $signal, array $options = []): OperatorLearningCandidate
    {
        $gate = $this->gate->evaluate($signal->toArray());
        $value = $this->valueFromSignal($signal, $options);

        return OperatorLearningCandidate::query()->create([
            'signal_id' => $signal->id,
            'operator_id' => $signal->operator_id,
            'taxonomy_item_id' => $signal->taxonomy_item_id,
            'claim' => $signal->normalized_claim,
            'value' => $value,
            'status' => $gate['status'],
            'confidence' => $signal->confidence,
            'conflict_group' => $value['profile_key'] ?? $signal->taxonomy_item_id,
            'supersedes_id' => $options['supersedes_id'] ?? null,
            'requires_confirmation' => $gate['requires_confirmation'],
            'auto_apply_eligible' => $gate['auto_apply_eligible'],
            'gate_receipt' => $gate['gate_receipt'],
        ]);
    }

    /**
     * @param  array<string,mixed>  $classified
     * @return array<string,mixed>
     */
    public function preview(array $classified): array
    {
        $gate = $this->gate->evaluate($classified);

        return [
            'claim' => $classified['normalized_claim'] ?? null,
            'taxonomy_item_id' => $classified['taxonomy_item_id'] ?? null,
            'status' => $gate['status'],
            'requires_confirmation' => $gate['requires_confirmation'],
            'auto_apply_eligible' => $gate['auto_apply_eligible'],
            'gate_receipt' => $gate['gate_receipt'],
        ];
    }

    /**
     * @return Collection<int,OperatorLearningCandidate>
     */
    public function listPending(string $operatorId, int $limit = 50): Collection
    {
        return OperatorLearningCandidate::query()
            ->where('operator_id', $operatorId)
            ->pending()
            ->latest()
            ->limit(max(1, min(200, $limit)))
            ->get();
    }

    public function review(string $candidateId, string $decision, string $operator, ?string $notes = null): array
    {
        $candidate = OperatorLearningCandidate::query()->findOrFail($candidateId);
        $decision = strtolower(trim($decision));

        return match ($decision) {
            'approve', 'approved' => $this->approve($candidate, $operator, $notes),
            'reject', 'rejected' => $this->reject($candidate, $operator, $notes),
            'archive', 'archived' => $this->archive($candidate, $operator, $notes),
            default => throw new InvalidArgumentException('Unsupported operator learning decision: '.$decision),
        };
    }

    public function approve(OperatorLearningCandidate $candidate, string $operator, ?string $notes = null): array
    {
        $candidate->forceFill([
            'status' => OperatorLearningCandidate::STATUS_APPROVED,
            'decided_by' => $operator,
            'decided_at' => Carbon::now(),
            'gate_receipt' => array_merge((array) $candidate->gate_receipt, [
                'operator_decision' => 'approved',
                'operator_notes_present' => $notes !== null && trim($notes) !== '',
            ]),
        ])->save();

        $profileItem = $this->registry->promoteCandidate($candidate->refresh());

        return [
            'ok' => true,
            'decision' => 'approved',
            'candidate' => $this->payload($candidate->refresh()),
            'profile_item' => $this->registry->payload($profileItem),
        ];
    }

    /**
     * @return array<string,mixed>
     */
    public function autoApplyIfAllowed(OperatorLearningCandidate $candidate): array
    {
        $decision = $this->autoApplyDecision($candidate);

        if (($decision['apply'] ?? false) !== true) {
            return $decision;
        }

        try {
            $result = $this->approve($candidate, 'atlas-operator-intelligence-auto', 'auto-apply-safe-operator-profile-item');
            $candidate->refresh()->forceFill([
                'gate_receipt' => array_merge((array) $candidate->gate_receipt, [
                    'auto_apply_attempted' => true,
                    'auto_apply_applied' => true,
                    'auto_apply_actor' => 'atlas-operator-intelligence-auto',
                ]),
            ])->save();
            $candidate->refresh();

            $this->recordAutoApplyReceipt($candidate, $result['profile_item'] ?? []);

            return array_merge($decision, [
                'applied' => true,
                'candidate' => $this->payload($candidate),
                'profile_item' => $result['profile_item'],
            ]);
        } catch (Throwable $e) {
            $candidate->forceFill([
                'gate_receipt' => array_merge((array) $candidate->gate_receipt, [
                    'auto_apply_attempted' => true,
                    'auto_apply_applied' => false,
                    'auto_apply_reason' => 'exception',
                    'auto_apply_exception' => $e::class,
                ]),
            ])->save();

            return [
                'schema_version' => 'atlas.operator_learning_auto_apply.v1',
                'apply' => false,
                'applied' => false,
                'reason' => 'exception',
                'message' => $e->getMessage(),
                'candidate_id' => $candidate->id,
            ];
        }
    }

    public function reject(OperatorLearningCandidate $candidate, string $operator, ?string $notes = null): array
    {
        $candidate->forceFill([
            'status' => OperatorLearningCandidate::STATUS_REJECTED,
            'decided_by' => $operator,
            'decided_at' => Carbon::now(),
            'gate_receipt' => array_merge((array) $candidate->gate_receipt, [
                'operator_decision' => 'rejected',
                'operator_notes_present' => $notes !== null && trim($notes) !== '',
            ]),
        ])->save();

        return ['ok' => true, 'decision' => 'rejected', 'candidate' => $this->payload($candidate->refresh())];
    }

    public function archive(OperatorLearningCandidate $candidate, string $operator, ?string $notes = null): array
    {
        $candidate->forceFill([
            'status' => OperatorLearningCandidate::STATUS_ARCHIVED,
            'decided_by' => $operator,
            'decided_at' => Carbon::now(),
            'gate_receipt' => array_merge((array) $candidate->gate_receipt, [
                'operator_decision' => 'archived',
                'operator_notes_present' => $notes !== null && trim($notes) !== '',
            ]),
        ])->save();

        return ['ok' => true, 'decision' => 'archived', 'candidate' => $this->payload($candidate->refresh())];
    }

    /**
     * @return array<string,mixed>
     */
    public function payload(OperatorLearningCandidate $candidate): array
    {
        return [
            'id' => $candidate->id,
            'signal_id' => $candidate->signal_id,
            'operator_id' => $candidate->operator_id,
            'taxonomy_item_id' => $candidate->taxonomy_item_id,
            'claim' => $candidate->claim,
            'value' => $candidate->value,
            'status' => $candidate->status,
            'confidence' => $candidate->confidence,
            'requires_confirmation' => $candidate->requires_confirmation,
            'auto_apply_eligible' => $candidate->auto_apply_eligible,
            'gate_receipt' => $candidate->gate_receipt,
            'decided_by' => $candidate->decided_by,
            'decided_at' => $candidate->decided_at?->toIso8601String(),
        ];
    }

    /**
     * @param  array<string,mixed>  $options
     * @return array<string,mixed>
     */
    private function valueFromSignal(OperatorLearningSignal $signal, array $options): array
    {
        $value = is_array($options['value'] ?? null) ? $options['value'] : [];

        return array_merge([
            'profile_key' => $options['profile_key'] ?? $this->profileKey($signal),
            'summary' => $signal->normalized_claim,
            'effect' => $options['effect'] ?? $this->effectForTaxonomy($signal->taxonomy_item_id, $signal->signal_kind),
            'source_signal_id' => $signal->id,
        ], $value);
    }

    private function profileKey(OperatorLearningSignal $signal): string
    {
        return OperatorLearningCandidateSupport::profileKey(
            (string) $signal->taxonomy_item_id,
            (string) $signal->signal_kind,
        );
    }

    private function effectForTaxonomy(string $taxonomy, string $kind): string
    {
        return OperatorLearningCandidateSupport::effectForTaxonomy($taxonomy, $kind);
    }

    /**
     * Best-effort CENTRAL audit append for an auto-applied operator learning (beyond the
     * local gate_receipt). A ledger miss must NEVER roll back the apply. Only summary/keys
     * are written — privacy is already guaranteed 'normal' by the gate, so no raw claim or
     * PII reaches the ledger.
     *
     * @param  array<string,mixed>  $profileItem
     */
    private function recordAutoApplyReceipt(OperatorLearningCandidate $candidate, array $profileItem): void
    {
        try {
            $profileItemId = (string) ($profileItem['id'] ?? '');
            app(AtlasEvidenceLedger::class)->record(
                LedgerEventType::MemoryDeltaAccepted,
                [
                    'schema_version' => 'atlas.operator_learning_auto_apply.v1',
                    'candidate_id' => $candidate->id,
                    'profile_item_id' => $profileItemId,
                    'taxonomy_item_id' => $candidate->taxonomy_item_id,
                    'privacy_class' => 'normal',
                    'confidence' => $candidate->confidence,
                    'automation_level' => 'auto_apply_reversible',
                    'actor' => 'atlas-operator-intelligence-auto',
                    'reverse_handle' => $profileItemId !== '' ? 'php artisan atlas:ai:operator-profile archive '.$profileItemId : null,
                ],
                [
                    'operator_id' => $candidate->operator_id,
                    'scope_type' => 'operator_profile',
                    'correlation_id' => (string) $candidate->id,
                    'emitter_stage' => 'atlas.operator_intelligence.auto_apply',
                    'emitter_version' => 'v1',
                ],
            );
        } catch (Throwable) {
            // central audit is best-effort; the apply stands regardless
        }
    }

    /**
     * @return array<string,mixed>
     */
    private function autoApplyDecision(OperatorLearningCandidate $candidate): array
    {
        $reason = null;

        if (! (bool) config('atlas_operator_intelligence.enabled', true)) {
            $reason = 'operator_intelligence_disabled';
        } elseif (! (bool) config('atlas_operator_intelligence.auto_apply_enabled', false)) {
            $reason = 'auto_apply_disabled';
        } elseif ((bool) config('atlas_operator_intelligence.shadow_mode', true)) {
            $reason = 'shadow_mode';
        } elseif (! $candidate->auto_apply_eligible) {
            $reason = 'candidate_not_auto_apply_eligible';
        } elseif ($candidate->requires_confirmation) {
            $reason = 'candidate_requires_confirmation';
        } elseif ($candidate->status !== OperatorLearningCandidate::STATUS_CANDIDATE) {
            $reason = 'candidate_status_not_applyable:'.$candidate->status;
        }

        $receipt = array_merge((array) $candidate->gate_receipt, [
            'auto_apply_checked' => true,
            'auto_apply_config_enabled' => (bool) config('atlas_operator_intelligence.auto_apply_enabled', false),
            'auto_apply_shadow_mode' => (bool) config('atlas_operator_intelligence.shadow_mode', true),
            'auto_apply_reason' => $reason ?? 'all_gates_passed',
        ]);

        $candidate->forceFill(['gate_receipt' => $receipt])->save();

        return [
            'schema_version' => 'atlas.operator_learning_auto_apply.v1',
            'apply' => $reason === null,
            'applied' => false,
            'reason' => $reason ?? 'all_gates_passed',
            'candidate_id' => $candidate->id,
        ];
    }
}
