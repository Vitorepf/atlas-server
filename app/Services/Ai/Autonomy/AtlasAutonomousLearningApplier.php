<?php

declare(strict_types=1);

namespace App\Services\Ai\Autonomy;

use App\Models\AiLearningProposal;
use App\Models\AiMemoryDelta;
use App\Models\AtlasAemorMemoryCandidate;
use App\Models\AtlasMemoryEntry;
use App\Services\Ai\Compounding\AtlasLearningProposalDecisionService;
use App\Services\Ai\Memory\AtlasMemoryDeltaPromotionService;
use App\Services\Ai\Compounding\AtlasLearningProposalApplier;
use App\Services\Ai\Compounding\AtlasLearningProposalService;
use App\Services\Ai\Governance\AtlasAutonomyAdmissionService;
use App\Services\Ai\LongHorizon\LongHorizonMemoryPromotionGuard;
use App\Services\Ai\Policy\PolicyCanon;
use App\Services\Ai\Support\DatabaseTableAvailability;
use Throwable;

/**
 * The autonomous loop's missing consumer — "Hermes mode" for self-learning. Routes the
 * three digest queues (learning proposals, memory deltas, AEMOR candidates) through one
 * fail-closed gate stack: what passes auto-applies with NO operator approval; everything
 * else is held for the Sunday digest with a named reason. Default-OFF behind
 * `atlas.ai.autonomous_learning.enabled`.
 *
 * Sovereignty is a FAIL-CLOSED gate stack — ALL must pass; any failure routes the item
 * to the digest as `held`, never applies. Reversibility is the safeguard:
 *   - proposals → atlas:ai:apply-learning --reverse
 *   - deltas/candidates → atlas:ai:memory-forget on the promoted entry
 *
 * Pétreo floor held for free: no git, no main, no critical/secret/cyber, no --force promote.
 */
final class AtlasAutonomousLearningApplier
{
    public const SCHEMA = 'atlas.ai.autonomous_learning_applier.v1';

    public const AUTO_APPLIED_BY = 'atlas-auto';

    /** @var list<string> */
    private const APPLYABLE_PRIVACY = ['public', 'normal'];

    /** @var list<string> */
    private const AUTO_DELTA_TYPES = [
        'decision',
        'preference',
        'feedback',
        'error_pattern',
        'issue',
        'resolution',
        'benchmark',
        'benchmark_observation',
        'harness_learning',
        'process',
        'technical_context',
    ];

    private const MIN_TRUSTED_CONFIDENCE = 0.86;

    public function __construct(
        private readonly AtlasLearningProposalDecisionService $classifier,
        private readonly AtlasAutonomyAdmissionService $admission,
        private readonly AtlasLearningProposalService $proposals,
        private readonly AtlasLearningProposalApplier $applier,
        private readonly AtlasMemoryDeltaPromotionService $deltaPromoter,
        private readonly LongHorizonMemoryPromotionGuard $longHorizonGuard = new LongHorizonMemoryPromotionGuard,
        // MAXK-05 — property-gated signature verification. When enabled (default
        // ON since the ledger is fail-safe: absent `signatures` payload keeps
        // the legacy behavior), boolean `true` in the promotion_gate no longer
        // proves anything — only a receipt in the append-only ledger does.
        private readonly ?AtlasAutonomyLadderSignatureLedger $signatureLedger = null,
    ) {}

    /**
     * @return array<string,mixed>
     */
    public function run(int $limit = 50): array
    {
        if (! (bool) config('atlas.ai.autonomous_learning.enabled', false)) {
            return $this->summary(false, 0, 0, [], 'disabled — default max friction (operator opt-in required)');
        }

        $limit = (new AtlasOperatorReviewDebtMeter())->effectiveAutoApplyLimit(max(1, min(500, $limit)));
        $applied = 0;
        $held = 0;
        $items = [];

        if ($this->tableReady('ai_learning_proposals')) {
            foreach (AiLearningProposal::query()->where('status', 'proposed')->orderBy('created_at')->limit($limit)->get() as $proposal) {
                $decision = $this->decide($proposal);
                if ($decision['auto_apply'] === true && $this->tryApplyProposal($proposal, $items)) {
                    $applied++;

                    continue;
                }
                $held++;
                $items[] = $this->heldItem('learning_proposals', (string) $proposal->getKey(), (string) $proposal->kind, $decision['reason']);
            }
        }

        if ($this->tableReady('ai_memory_deltas')) {
            foreach (AiMemoryDelta::query()
                ->whereIn('status', ['pending', 'accepted'])
                ->orderBy('created_at')
                ->limit($limit)
                ->get() as $delta) {
                $decision = $this->decideDelta($delta);
                if ($decision['auto_apply'] === true && $this->tryApplyDelta($delta, $items)) {
                    $applied++;

                    continue;
                }
                $held++;
                $items[] = $this->heldItem('memory_deltas', (string) $delta->getKey(), (string) $delta->type, $decision['reason']);
            }
        }

        if ($this->tableReady('atlas_aemor_memory_candidates')) {
            foreach (AtlasAemorMemoryCandidate::query()
                ->whereIn('status', ['watch', 'candidate'])
                ->orderBy('created_at')
                ->limit($limit)
                ->get() as $candidate) {
                $decision = $this->decideCandidate($candidate);
                if ($decision['auto_apply'] === true && $this->tryApplyCandidate($candidate, $items)) {
                    $applied++;

                    continue;
                }
                $held++;
                $items[] = $this->heldItem('aemor_candidates', (string) $candidate->getKey(), (string) $candidate->memory_type, $decision['reason']);
            }
        }

        return $this->summary(true, $applied, $held, $items, null);
    }

    /**
     * @param  list<array<string,mixed>>  $items
     */
    private function tryApplyProposal(AiLearningProposal $proposal, array &$items): bool
    {
        try {
            $this->proposals->approve($proposal, self::AUTO_APPLIED_BY, 'autonomous-safe-apply');
            $result = $this->applier->apply($proposal, self::AUTO_APPLIED_BY);
            if (($result['applied'] ?? false) === true) {
                $items[] = [
                    'queue' => 'learning_proposals',
                    'id' => (string) $proposal->getKey(),
                    'kind' => (string) $proposal->kind,
                    'action' => 'auto_applied',
                    'reverse_handle' => $result['change']['reverse_handle'] ?? ('php artisan atlas:ai:apply-learning '.$proposal->getKey().' --reverse'),
                ];

                return true;
            }
            $this->revertProposalApproval($proposal);
        } catch (Throwable) {
            $this->revertProposalApproval($proposal);
        }

        return false;
    }

    /**
     * @param  list<array<string,mixed>>  $items
     */
    private function tryApplyDelta(AiMemoryDelta $delta, array &$items): bool
    {
        try {
            $entry = $this->deltaPromoter->promote($delta, [
                'force' => $delta->status !== 'accepted',
                'promoted_by' => self::AUTO_APPLIED_BY,
                'metadata' => [
                    'autonomous_auto_apply' => [
                        'applied_at' => now()->toIso8601String(),
                        'actor' => self::AUTO_APPLIED_BY,
                    ],
                ],
            ]);
            $items[] = [
                'queue' => 'memory_deltas',
                'id' => (string) $delta->getKey(),
                'kind' => (string) $delta->type,
                'action' => 'auto_applied',
                'memory_entry_id' => (string) $entry->getKey(),
                'reverse_handle' => 'php artisan atlas:ai:memory-forget '.$entry->getKey().'   (undo: --restore)',
            ];

            return true;
        } catch (Throwable) {
            return false;
        }
    }

    /**
     * @param  list<array<string,mixed>>  $items
     */
    private function tryApplyCandidate(AtlasAemorMemoryCandidate $candidate, array &$items): bool
    {
        $deltaId = $candidate->memory_delta_id;
        if (! is_string($deltaId) || $deltaId === '') {
            return false;
        }

        $delta = AiMemoryDelta::query()->find($deltaId);
        if (! $delta instanceof AiMemoryDelta) {
            return false;
        }

        if (! $this->tryApplyDelta($delta, $items)) {
            return false;
        }

        try {
            $candidate->forceFill([
                'status' => 'promoted',
                'metadata' => array_merge(is_array($candidate->metadata) ? $candidate->metadata : [], [
                    'autonomous_auto_apply' => [
                        'applied_at' => now()->toIso8601String(),
                        'actor' => self::AUTO_APPLIED_BY,
                    ],
                ]),
            ])->save();
            $last = &$items[count($items) - 1];
            $last['queue'] = 'aemor_candidates';
            $last['id'] = (string) $candidate->getKey();
            $last['kind'] = (string) $candidate->memory_type;
            $last['linked_delta_id'] = $deltaId;
        } catch (Throwable) {
            return false;
        }

        return true;
    }

    private function revertProposalApproval(AiLearningProposal $proposal): void
    {
        try {
            $proposal->refresh();
            if ($proposal->status !== 'approved' || $proposal->decided_by !== self::AUTO_APPLIED_BY) {
                return;
            }
            $proposal->forceFill([
                'status' => 'proposed',
                'decided_by' => null,
                'decided_at' => null,
                'decision_notes' => 'auto_apply_failed: aplicação não concluiu; devolvido à fila held',
            ])->save();
        } catch (Throwable) {
            // revert impossível — o run() ainda reporta held
        }
    }

    /**
     * @return array{auto_apply:bool,reason:string}
     */
    public function decide(AiLearningProposal $proposal): array
    {
        $kind = (string) $proposal->kind;

        if (! $this->applier->supportsAutoApply($kind)) {
            return ['auto_apply' => false, 'reason' => 'kind_not_auto_applyable:'.$kind];
        }

        $privacy = $this->derivePrivacy($proposal);
        if (! in_array($privacy, self::APPLYABLE_PRIVACY, true)) {
            return ['auto_apply' => false, 'reason' => 'privacy_'.($privacy === '' ? 'unclassified' : $privacy)];
        }

        try {
            $verdict = $this->classifier->evaluate($this->signalFor($proposal));
            if (($verdict['may_auto_apply'] ?? false) !== true) {
                return ['auto_apply' => false, 'reason' => 'classifier_blocked'];
            }
        } catch (Throwable) {
            return ['auto_apply' => false, 'reason' => 'classifier_threw'];
        }

        try {
            $env = $this->admission->admit([
                'actor' => self::AUTO_APPLIED_BY,
                'change_kind' => $kind,
                'change_class' => $kind,
                'requested_autonomy' => PolicyCanon::AUTONOMY_AUTONOMOUS,
                'risk_level' => PolicyCanon::RISK_LOW,
                'proposed_effect' => 'autonomous safe learning apply ('.$kind.')',
                'scope' => ['privacy_class' => $privacy],
            ]);
        } catch (Throwable) {
            return ['auto_apply' => false, 'reason' => 'admission_threw'];
        }

        if (($env['decision'] ?? '') !== AtlasAutonomyAdmissionService::DECISION_ALLOW_AUTONOMOUS) {
            return ['auto_apply' => false, 'reason' => 'admission_not_autonomous:'.(string) ($env['decision'] ?? '')];
        }
        if (($env['requires_human_approval'] ?? true) !== false) {
            return ['auto_apply' => false, 'reason' => 'requires_human_approval'];
        }
        if (($env['effective_autonomy'] ?? '') !== PolicyCanon::AUTONOMY_AUTONOMOUS) {
            return ['auto_apply' => false, 'reason' => 'not_effective_autonomous'];
        }

        return ['auto_apply' => true, 'reason' => 'all_gates_passed'];
    }

    /**
     * @return array{auto_apply:bool,reason:string}
     */
    public function decideDelta(AiMemoryDelta $delta): array
    {
        if (! in_array($delta->status, ['pending', 'accepted'], true)) {
            return ['auto_apply' => false, 'reason' => 'status_not_applyable:'.$delta->status];
        }

        if ($delta->requires_confirmation && $delta->status !== 'accepted') {
            return ['auto_apply' => false, 'reason' => 'requires_confirmation'];
        }

        if ($this->deltaEvidence($delta) === []) {
            return ['auto_apply' => false, 'reason' => 'missing_evidence'];
        }

        if ((float) $delta->confidence < self::MIN_TRUSTED_CONFIDENCE) {
            return ['auto_apply' => false, 'reason' => 'confidence_below_threshold'];
        }

        if (! in_array((string) $delta->type, self::AUTO_DELTA_TYPES, true)) {
            return ['auto_apply' => false, 'reason' => 'type_not_auto_applyable:'.$delta->type];
        }

        if ($delta->valid_until !== null && $delta->valid_until->isPast()) {
            return ['auto_apply' => false, 'reason' => 'expired_valid_until'];
        }

        $immuneBlock = $this->immunePromotionBlockReason($delta);
        if ($immuneBlock !== null) {
            return ['auto_apply' => false, 'reason' => $immuneBlock];
        }

        $guardReasons = $this->longHorizonGuard->evaluate($delta, [
            'promoted_by' => self::AUTO_APPLIED_BY,
        ]);
        if ($guardReasons !== []) {
            return ['auto_apply' => false, 'reason' => 'long_horizon_guard:'.implode(',', $guardReasons)];
        }

        return ['auto_apply' => true, 'reason' => 'all_gates_passed'];
    }

    /**
     * @return array{auto_apply:bool,reason:string}
     */
    public function decideCandidate(AtlasAemorMemoryCandidate $candidate): array
    {
        if (! in_array((string) $candidate->status, ['watch', 'candidate'], true)) {
            return ['auto_apply' => false, 'reason' => 'status_not_applyable:'.$candidate->status];
        }

        $gate = is_array($candidate->promotion_gate) ? $candidate->promotion_gate : [];
        if (($gate['status'] ?? '') !== 'pass') {
            $blockers = is_array($gate['blockers'] ?? null) ? implode(',', $gate['blockers']) : 'promotion_gate_blocked';

            return ['auto_apply' => false, 'reason' => 'promotion_gate:'.$blockers];
        }

        // MAXK-05 — property-gated signature verification. `promotion_gate.status`
        // set to `pass` is not sufficient by itself: if the caller advertises
        // `signatures` on the gate, every declared signature MUST match an
        // append-only receipt in the ledger. Boolean `true` is never accepted
        // — it is the exact input the adversarial test forges. Missing
        // `signatures` key falls back to the legacy shape (audit unchanged).
        $signatureVerdict = $this->verifyCandidateSignatures($candidate, $gate);
        if ($signatureVerdict !== null) {
            return $signatureVerdict;
        }

        $refs = is_array($candidate->evidence_refs) ? array_values(array_filter($candidate->evidence_refs, static fn ($r): bool => $r !== null && $r !== '')) : [];
        if ($refs === []) {
            return ['auto_apply' => false, 'reason' => 'missing_evidence_refs'];
        }

        if (! is_string($candidate->memory_delta_id) || $candidate->memory_delta_id === '') {
            return ['auto_apply' => false, 'reason' => 'missing_memory_delta_link'];
        }

        $delta = AiMemoryDelta::query()->find($candidate->memory_delta_id);
        if (! $delta instanceof AiMemoryDelta) {
            return ['auto_apply' => false, 'reason' => 'linked_delta_missing'];
        }

        $deltaDecision = $this->decideDelta($delta);
        if ($deltaDecision['auto_apply'] !== true) {
            return ['auto_apply' => false, 'reason' => 'linked_delta:'.$deltaDecision['reason']];
        }

        return ['auto_apply' => true, 'reason' => 'all_gates_passed'];
    }

    /**
     * Read-only classification for the weekly digest (applied + held with reasons).
     *
     * @return array<string,mixed>
     */
    public function digestWindow(int $days = 7): array
    {
        $days = max(1, min(365, $days));
        $since = now()->subDays($days);
        $applied = [];
        $held = [];

        if ($this->tableReady('ai_learning_proposals')) {
            foreach (AiLearningProposal::query()->where('created_at', '>=', $since)->orderByDesc('created_at')->limit(self::DIGEST_ITEM_CAP)->get() as $proposal) {
                if ($proposal->status === 'applied' && (string) $proposal->decided_by === self::AUTO_APPLIED_BY) {
                    $applied[] = [
                        'queue' => 'learning_proposals',
                        'id' => (string) $proposal->getKey(),
                        'kind' => (string) $proposal->kind,
                        'action' => 'auto_applied',
                        'reverse_handle' => 'php artisan atlas:ai:apply-learning '.$proposal->getKey().' --reverse',
                    ];

                    continue;
                }
                if ($proposal->status !== 'proposed') {
                    continue;
                }
                $decision = $this->decide($proposal);
                if ($decision['auto_apply'] !== true) {
                    $held[] = $this->heldItem('learning_proposals', (string) $proposal->getKey(), (string) $proposal->kind, $decision['reason']);
                }
            }
        }

        if ($this->tableReady('ai_memory_deltas')) {
            foreach (AiMemoryDelta::query()->where('created_at', '>=', $since)->orderByDesc('created_at')->limit(self::DIGEST_ITEM_CAP)->get() as $delta) {
                if ($delta->status === 'promoted' && $this->wasAutoAppliedDelta($delta)) {
                    $entryId = (string) ($delta->promoted_memory_entry_id ?? '');
                    $applied[] = [
                        'queue' => 'memory_deltas',
                        'id' => (string) $delta->getKey(),
                        'kind' => (string) $delta->type,
                        'action' => 'auto_applied',
                        'memory_entry_id' => $entryId,
                        'reverse_handle' => $entryId !== ''
                            ? 'php artisan atlas:ai:memory-forget '.$entryId.'   (undo: --restore)'
                            : 'php artisan atlas:ai:memory-forget <entry-id>   (undo: --restore)',
                    ];

                    continue;
                }
                if (! in_array($delta->status, ['pending', 'accepted'], true)) {
                    continue;
                }
                $decision = $this->decideDelta($delta);
                if ($decision['auto_apply'] !== true) {
                    $held[] = $this->heldItem('memory_deltas', (string) $delta->getKey(), (string) $delta->type, $decision['reason']);
                }
            }
        }

        if ($this->tableReady('atlas_aemor_memory_candidates')) {
            foreach (AtlasAemorMemoryCandidate::query()->where('created_at', '>=', $since)->orderByDesc('created_at')->limit(self::DIGEST_ITEM_CAP)->get() as $candidate) {
                if ($candidate->status === 'promoted' && $this->wasAutoAppliedCandidate($candidate)) {
                    $entryId = $this->linkedMemoryEntryId($candidate);
                    $applied[] = [
                        'queue' => 'aemor_candidates',
                        'id' => (string) $candidate->getKey(),
                        'kind' => (string) $candidate->memory_type,
                        'action' => 'auto_applied',
                        'memory_entry_id' => $entryId,
                        'reverse_handle' => $entryId !== ''
                            ? 'php artisan atlas:ai:memory-forget '.$entryId.'   (undo: --restore)'
                            : 'php artisan atlas:ai:memory-forget <entry-id>   (undo: --restore)',
                    ];

                    continue;
                }
                if (! in_array((string) $candidate->status, ['watch', 'candidate'], true)) {
                    continue;
                }
                $decision = $this->decideCandidate($candidate);
                if ($decision['auto_apply'] !== true) {
                    $held[] = $this->heldItem('aemor_candidates', (string) $candidate->getKey(), (string) $candidate->memory_type, $decision['reason']);
                }
            }
        }

        return [
            'applied' => ['count' => count($applied), 'items' => $applied],
            'held' => ['count' => count($held), 'items' => $held],
        ];
    }

    private const DIGEST_ITEM_CAP = 200;

    private function wasAutoAppliedDelta(AiMemoryDelta $delta): bool
    {
        if (! is_string($delta->promoted_memory_entry_id) || $delta->promoted_memory_entry_id === '') {
            return false;
        }
        if (! $this->tableReady('atlas_memory_entries')) {
            return false;
        }
        $entry = AtlasMemoryEntry::query()->find($delta->promoted_memory_entry_id);
        if ($entry === null) {
            return false;
        }
        $meta = is_array($entry->metadata) ? $entry->metadata : [];

        return (($meta['promoted_by'] ?? '') === self::AUTO_APPLIED_BY)
            || (($meta['autonomous_auto_apply']['actor'] ?? '') === self::AUTO_APPLIED_BY);
    }

    private function wasAutoAppliedCandidate(AtlasAemorMemoryCandidate $candidate): bool
    {
        $meta = is_array($candidate->metadata) ? $candidate->metadata : [];

        return is_array($meta['autonomous_auto_apply'] ?? null)
            && (($meta['autonomous_auto_apply']['actor'] ?? '') === self::AUTO_APPLIED_BY);
    }

    private function linkedMemoryEntryId(AtlasAemorMemoryCandidate $candidate): string
    {
        if (! is_string($candidate->memory_delta_id) || $candidate->memory_delta_id === '') {
            return '';
        }
        $delta = AiMemoryDelta::query()->find($candidate->memory_delta_id);

        return is_string($delta?->promoted_memory_entry_id) ? $delta->promoted_memory_entry_id : '';
    }

    /**
     * @return list<mixed>
     */
    private function deltaEvidence(AiMemoryDelta $delta): array
    {
        return is_array($delta->evidence)
            ? array_values(array_filter($delta->evidence, static fn ($item): bool => $item !== null && $item !== ''))
            : [];
    }

    private function immunePromotionBlockReason(AiMemoryDelta $delta): ?string
    {
        foreach ($this->deltaEvidence($delta) as $item) {
            if (! is_array($item) || ($item['kind'] ?? null) !== 'capture_quarantine') {
                continue;
            }
            if (($item['immune_memory_promotion_allowed_now'] ?? true) === false) {
                return 'immune_audit_blocked';
            }
        }

        return null;
    }

    /**
     * @return array<string,mixed>
     */
    private function heldItem(string $queue, string $id, string $kind, string $reason): array
    {
        return [
            'queue' => $queue,
            'id' => $id,
            'kind' => $kind,
            'action' => 'held',
            'reason' => $reason,
        ];
    }

    private function derivePrivacy(AiLearningProposal $proposal): string
    {
        $ps = is_array($proposal->proposed_state) ? $proposal->proposed_state : [];

        return strtolower(trim((string) ($ps['privacy_class'] ?? '')));
    }

    /**
     * @return array<string,mixed>
     */
    private function signalFor(AiLearningProposal $proposal): array
    {
        $ps = is_array($proposal->proposed_state) ? $proposal->proposed_state : [];
        $canonicalRefs = is_array($proposal->evidence_refs) ? array_values(array_filter($proposal->evidence_refs, static fn ($r): bool => $r !== null && $r !== '')) : [];

        return [
            'kind' => (string) $proposal->kind,
            'summary' => (string) ($ps['claim'] ?? ($ps['title'] ?? '')),
            'evidence_refs' => $canonicalRefs,
            'sample_size' => $canonicalRefs === [] ? 0 : (int) ($ps['sample_size'] ?? 0),
            'effect_size' => $canonicalRefs === [] ? 0.0 : (float) ($ps['effect_size'] ?? 0.0),
        ];
    }

    /**
     * @param  list<array<string,mixed>>  $items
     * @return array<string,mixed>
     */
    private function summary(bool $enabled, int $applied, int $held, array $items, ?string $note): array
    {
        $out = [
            'schema_version' => self::SCHEMA,
            'enabled' => $enabled,
            'applied' => $applied,
            'held' => $held,
            'queued' => $held,
            'items' => $items,
        ];
        if ($note !== null) {
            $out['note'] = $note;
        }

        return $out;
    }

    private function tableReady(string $table): bool
    {
        return DatabaseTableAvailability::has($table);
    }

    /**
     * MAXK-05 — verify property-gated signatures on a candidate's promotion_gate.
     *
     * Returns `null` when the check is satisfied (or absent by design). Returns
     * a `{auto_apply: false, reason: ...}` verdict when the mutation must be
     * blocked. Named reasons:
     *   - `signature_forged_boolean:<name>`   — the gate sent `true`/`false`
     *   - `signature_receipt_field_missing`   — receipt payload malformed
     *   - `signature_receipt_missing:<name>`  — no matching row in the ledger
     *   - `signature_nonce_reused:<name>`     — nonce already consumed
     *
     * @param  array<string,mixed>  $gate
     * @return array{auto_apply:bool,reason:string}|null
     */
    private function verifyCandidateSignatures(AtlasAemorMemoryCandidate $candidate, array $gate): ?array
    {
        if (! (bool) config('atlas.ai.autonomy_ladder.signature_verification_enabled', true)) {
            return null;
        }

        $signatures = $gate['signatures'] ?? null;
        if (! is_array($signatures) || $signatures === []) {
            // Legacy shape — no `signatures` payload, behavior unchanged.
            return null;
        }

        $ledger = $this->signatureLedger ?? new AtlasAutonomyLadderSignatureLedger;
        $targetId = (string) $candidate->getKey();
        $targetKind = 'atlas_aemor_memory_candidate';

        foreach ($signatures as $name => $receipt) {
            $signature = (string) $name;
            // The exact case the plan names: booleans (or any non-array) are
            // rejected before touching the ledger — boolean no mapa deixa de
            // ser aceito.
            if (! is_array($receipt)) {
                return ['auto_apply' => false, 'reason' => 'signature_forged_boolean:'.$signature];
            }
            $actor = isset($receipt['actor']) ? (string) $receipt['actor'] : '';
            $nonce = isset($receipt['nonce']) ? (string) $receipt['nonce'] : '';
            $policyHash = isset($receipt['policy_hash']) ? (string) $receipt['policy_hash'] : '';
            $verdict = $ledger->verify(
                $signature,
                $actor,
                $nonce,
                $policyHash,
                $targetKind,
                $targetId,
            );
            if ($verdict['ok'] !== true) {
                return ['auto_apply' => false, 'reason' => (string) $verdict['reason'].':'.$signature];
            }
        }

        return null;
    }
}
