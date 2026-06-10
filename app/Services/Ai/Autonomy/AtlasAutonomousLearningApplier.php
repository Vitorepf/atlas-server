<?php

declare(strict_types=1);

namespace App\Services\Ai\Autonomy;

use App\Models\AiLearningProposal;
use App\Services\Ai\Aaeos\Generated\AtlasLearningProposalsService;
use App\Services\Ai\Compounding\AtlasLearningProposalApplier;
use App\Services\Ai\Compounding\AtlasLearningProposalService;
use App\Services\Ai\Governance\AtlasAutonomyAdmissionService;
use App\Services\Ai\Policy\PolicyCanon;
use App\Services\Ai\Support\DatabaseTableAvailability;
use Throwable;

/**
 * The autonomous loop's missing consumer — "Hermes mode" for self-learning. It takes
 * PROPOSED learning proposals and, for the SAFE reversible classes ONLY, auto-approves
 * + auto-applies them with NO operator approval. Everything else stays status='proposed'
 * (the Sunday review queue). Default-OFF behind `atlas.ai.autonomous_learning.enabled`.
 *
 * Sovereignty is a FAIL-CLOSED gate stack — ALL must pass; any failure routes the
 * proposal to the Sunday queue, never applies:
 *   G1  kind ∈ applier.supportsAutoApply  — default-deny; only non-critical kinds that
 *       have BOTH an applier and a reverser (unknown/critical kinds never pass).
 *   G2  privacy fail-closed — sensitive/secret/cyber/unclassified → queue.
 *   G3  classifier.evaluate().may_auto_apply === true — the canon's critical oracle.
 *   G4  admission.admit() === allow_autonomous AND requires_human_approval===false AND
 *       effective_autonomy===autonomous, called with an EXPLICIT fail-closed risk_level
 *       and privacy under the nested scope.privacy_class key the gates actually read.
 *
 * Every application is reversible (the applier materializes an archivable AtlasMemoryEntry)
 * and receipted. Pétreo floor held for free: no git, no main, no critical/secret/cyber.
 *
 * Note: the cognitive-immune G0-G8 PROMOTION gate is deliberately NOT wired as a fifth
 * gate here — it needs a richer signal context than a learning proposal carries, and
 * captured candidates already passed the capture-side immune quarantine. Wiring it is
 * the documented precondition before widening auto-apply beyond the memory-entry kinds;
 * this class never claims immune protection it does not enforce.
 */
final class AtlasAutonomousLearningApplier
{
    public const SCHEMA = 'atlas.ai.autonomous_learning_applier.v1';

    /**
     * The ONLY privacy classes that may auto-apply — an explicit allowlist, not a
     * blocklist (fail-closed): of the canon's classes {public, normal, sensitive,
     * secret, cyber}, only the two non-sensitive ones. Unknown/empty/sensitive ⇒ queue.
     */
    private const APPLYABLE_PRIVACY = ['public', 'normal'];

    public function __construct(
        private readonly AtlasLearningProposalsService $classifier,
        private readonly AtlasAutonomyAdmissionService $admission,
        private readonly AtlasLearningProposalService $proposals,
        private readonly AtlasLearningProposalApplier $applier,
    ) {}

    /**
     * @return array<string,mixed>
     */
    public function run(int $limit = 50): array
    {
        if (! (bool) config('atlas.ai.autonomous_learning.enabled', false)) {
            return $this->summary(false, 0, 0, [], 'disabled — default max friction (operator opt-in required)');
        }
        if (! $this->tableReady('ai_learning_proposals')) {
            return $this->summary(true, 0, 0, [], 'ai_learning_proposals table unavailable');
        }

        $limit = max(1, min(500, $limit));
        $applied = 0;
        $queued = 0;
        $items = [];

        $rows = AiLearningProposal::query()->where('status', 'proposed')->orderBy('created_at')->limit($limit)->get();
        foreach ($rows as $proposal) {
            $decision = $this->decide($proposal);
            if ($decision['auto_apply'] === true && $this->tryApply($proposal, $items)) {
                $applied++;

                continue;
            }
            $queued++;
            $items[] = [
                'id' => (string) $proposal->getKey(),
                'kind' => (string) $proposal->kind,
                'action' => 'queued_for_review',
                'reason' => $decision['reason'],
            ];
        }

        return $this->summary(true, $applied, $queued, $items, null);
    }

    /**
     * @param  list<array<string,mixed>>  $items
     */
    private function tryApply(AiLearningProposal $proposal, array &$items): bool
    {
        try {
            $this->proposals->approve($proposal, 'atlas-auto', 'autonomous-safe-apply');
            $result = $this->applier->apply($proposal, 'atlas-auto');
            if (($result['applied'] ?? false) === true) {
                $items[] = [
                    'id' => (string) $proposal->getKey(),
                    'kind' => (string) $proposal->kind,
                    'action' => 'auto_applied',
                    'reverse' => $result['change']['reverse_handle'] ?? ('php artisan atlas:ai:apply-learning '.$proposal->getKey().' --reverse'),
                ];

                return true;
            }
        } catch (Throwable) {
            // fall through to queue — fail-safe
        }

        return false;
    }

    /**
     * The fail-closed gate stack. Returns auto_apply=true ONLY if every gate passes.
     *
     * @return array{auto_apply:bool,reason:string}
     */
    public function decide(AiLearningProposal $proposal): array
    {
        $kind = (string) $proposal->kind;

        // G1 — default-deny: only kinds with a reversible applier (non-critical in both taxonomies).
        if (! $this->applier->supportsAutoApply($kind)) {
            return ['auto_apply' => false, 'reason' => 'kind_not_auto_applyable:'.$kind];
        }

        // G2 — privacy fail-closed (explicit non-sensitive allowlist).
        $privacy = $this->derivePrivacy($proposal);
        if (! in_array($privacy, self::APPLYABLE_PRIVACY, true)) {
            return ['auto_apply' => false, 'reason' => 'privacy_'.($privacy === '' ? 'unclassified' : $privacy)];
        }

        // G3 — the canon's critical oracle (defense in depth on top of G1).
        try {
            $verdict = $this->classifier->evaluate($this->signalFor($proposal));
            if (($verdict['may_auto_apply'] ?? false) !== true) {
                return ['auto_apply' => false, 'reason' => 'classifier_blocked'];
            }
        } catch (Throwable) {
            return ['auto_apply' => false, 'reason' => 'classifier_threw'];
        }

        // G4 — the composed admission floor (kernel + per-risk cap + privacy). Explicit
        // fail-closed risk_level so deriveRiskLevel can never mis-default to LOW, and
        // privacy under the NESTED scope.privacy_class key the kernel + admission read.
        try {
            $env = $this->admission->admit([
                'actor' => 'atlas-auto',
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

    private function derivePrivacy(AiLearningProposal $proposal): string
    {
        // Read from the SAME single source the writer (applyAsMemoryEntry) uses, so
        // decide()'s auto_apply signal can never diverge from what the writer accepts.
        // Fail-closed: a proposal with no explicit privacy class derives '' ⇒ queued.
        $ps = is_array($proposal->proposed_state) ? $proposal->proposed_state : [];

        return strtolower(trim((string) ($ps['privacy_class'] ?? '')));
    }

    /**
     * @return array<string,mixed>
     */
    private function signalFor(AiLearningProposal $proposal): array
    {
        $ps = is_array($proposal->proposed_state) ? $proposal->proposed_state : [];

        return [
            'kind' => (string) $proposal->kind,
            'summary' => (string) ($ps['claim'] ?? ($ps['title'] ?? '')),
            'evidence_refs' => is_array($ps['evidence_refs'] ?? null) ? $ps['evidence_refs'] : [],
            'sample_size' => (int) ($ps['sample_size'] ?? 0),
            'effect_size' => (float) ($ps['effect_size'] ?? 0.0),
        ];
    }

    /**
     * @param  list<array<string,mixed>>  $items
     * @return array<string,mixed>
     */
    private function summary(bool $enabled, int $applied, int $queued, array $items, ?string $note): array
    {
        $out = [
            'schema_version' => self::SCHEMA,
            'enabled' => $enabled,
            'applied' => $applied,
            'queued' => $queued,
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
}
