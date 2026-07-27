<?php

declare(strict_types=1);

namespace App\Services\Ai\Compounding;

use App\Models\AiLearningProposal;
use App\Models\AtlasMemoryEntry;
use App\Services\Ai\AtlasDecide\AtlasConductorRoutingMemory;
use App\Services\Ai\Kernel\Evidence\AtlasEvidenceLedger;
use App\Services\Ai\Kernel\Evidence\LedgerEventType;
use App\Services\Ai\Learning\Harness\AtlasHarnessInstructionSurface;
use App\Services\Ai\Learning\Harness\AtlasHarnessSurface;
use Throwable;

/**
 * Closes the compounding flywheel: takes an APPROVED learning proposal and actually
 * applies it to runtime behaviour, so the next run is measurably better — the last
 * wire between "Atlas collects learning" and "Atlas learns".
 *
 * The hard law (AiLearningProposal) is preserved: nothing auto-mutates — the applier
 * acts ONLY on a proposal the operator already approved (`status=approved`), and the
 * application is governed (receipted) and reversible. First kind wired: `routing`
 * (an operator-approved learned route becomes the conductor's preferred route, ahead
 * of the raw success-rate heuristic). Other kinds report `kind_applier_pending` until
 * their behaviour appliers are wired — honest and extensible.
 *
 * Distinct from AtlasLearningProposalService::markApplied (a pure DB status flip with
 * its own lifecycle consumers): THIS applier pairs the status transition with the real
 * runtime route write + receipt. Do not call markApplied expecting the route to go live.
 */
final class AtlasLearningProposalApplier
{
    public const SCHEMA_VERSION = 'atlas.ai.learning_proposal_applier.v1';

    /** Source tag for memory entries this applier materializes (for reversal lookup). */
    public const AUTONOMOUS_SOURCE = 'atlas_autonomous_learning';

    /**
     * Non-critical kinds this applier can apply AND reverse by materializing a
     * reversible AtlasMemoryEntry (SoftDeletes + privacy + the forget handle). These
     * are the ONLY kinds eligible for autonomous auto-apply; everything else stays
     * operator-gated. Excludes documentation_health (absent from the persistence
     * ALLOWED_KINDS allow-list — fail-closed).
     */
    private const MEMORY_ENTRY_KINDS = ['memory', 'retrieval_hint', 'failure_pattern'];

    public function __construct(
        private readonly AtlasConductorRoutingMemory $routing,
    ) {}

    /** A kind is autonomously applyable ONLY if both apply AND reverse exist for it. */
    public function supportsAutoApply(string $kind): bool
    {
        return in_array($kind, self::MEMORY_ENTRY_KINDS, true);
    }

    /**
     * @return array<string,mixed>
     */
    public function apply(AiLearningProposal $proposal, string $operator = ''): array
    {
        if ($proposal->status !== 'approved') {
            return $this->refuse('proposal_not_approved');
        }

        $kind = (string) $proposal->kind;
        $change = match (true) {
            $kind === 'routing' => $this->applyRouting($proposal),
            // AP-819 Obra B/v2: kinds de harness NUNCA entram em MEMORY_ENTRY_KINDS —
            // supportsAutoApply() fica false por construção; o ÚNICO caminho
            // automático é o AtlasHarnessAutopilot, que carrega os 3 gates próprios.
            $kind === 'harness_config' => $this->applyHarnessConfig($proposal),
            $kind === 'harness_instruction' => $this->applyHarnessInstruction($proposal),
            $this->supportsAutoApply($kind) => $this->applyAsMemoryEntry($proposal),
            default => null,
        };

        if ($change === null) {
            return $this->refuse(match (true) {
                $kind === 'routing' => 'routing_proposed_state_incomplete',
                $kind === 'harness_config' => 'harness_config_rejected_by_surface',
                $kind === 'harness_instruction' => 'harness_instruction_rejected_by_surface',
                $this->supportsAutoApply($kind) => 'memory_state_incomplete_or_sensitive',
                default => 'kind_applier_pending:'.$kind,
            });
        }

        $persisted = $this->transition($proposal, 'applied', $operator);
        $this->recordReceipt($proposal, $operator, 'apply', $change);

        return [
            'schema_version' => self::SCHEMA_VERSION,
            'applied' => true,
            'reason' => null,
            'kind' => $kind,
            'change' => $change,
            'reversible' => true,
            // The route is live regardless; `persisted=false` flags that the DB status
            // bookkeeping lagged (fail-safe: re-apply is idempotent, last-set-wins).
            'persisted' => $persisted,
        ];
    }

    /**
     * @return array<string,mixed>
     */
    public function reverse(AiLearningProposal $proposal, string $operator = ''): array
    {
        if ($proposal->status !== 'applied') {
            return $this->refuse('proposal_not_applied');
        }
        $kind = (string) $proposal->kind;
        if ($kind === 'routing') {
            $ps = is_array($proposal->proposed_state) ? $proposal->proposed_state : [];
            $this->routing->clearPreferred((string) ($ps['task_category'] ?? ''), (string) ($ps['role'] ?? ''));
            $change = ['task_category' => (string) ($ps['task_category'] ?? ''), 'role' => (string) ($ps['role'] ?? '')];
        } elseif ($kind === 'harness_config') {
            $ps = is_array($proposal->proposed_state) ? $proposal->proposed_state : [];
            $result = app(AtlasHarnessSurface::class)
                ->reverseOverride((string) ($ps['key'] ?? ''));
            if (! $result['reversed']) {
                return $this->refuse('harness_config_reverse_failed:'.(string) $result['reason']);
            }
            $change = ['key' => $result['key'], 'restored' => $result['restored']];
        } elseif ($kind === 'harness_instruction') {
            $ps = is_array($proposal->proposed_state) ? $proposal->proposed_state : [];
            $result = app(AtlasHarnessInstructionSurface::class)
                ->reverseOverride((string) ($ps['key'] ?? ''));
            if (! $result['reversed']) {
                return $this->refuse('harness_instruction_reverse_failed:'.(string) $result['reason']);
            }
            $change = ['section' => $result['section'], 'restored_text_sha' => hash('sha256', (string) $result['restored'])];
        } elseif ($this->supportsAutoApply($kind)) {
            // Archive (never hard-delete) the memory entries this proposal materialized.
            $archived = AtlasMemoryEntry::query()
                ->where('source_type', self::AUTONOMOUS_SOURCE)
                ->where('source_id', (string) $proposal->getKey())
                ->whereNull('archived_at')
                ->update(['status' => 'archived', 'archived_at' => now()]);
            $change = ['archived_memory_entries' => $archived];
        } else {
            return $this->refuse('kind_reverser_pending:'.$kind);
        }

        $persisted = $this->transition($proposal, 'approved', $operator);
        $this->recordReceipt($proposal, $operator, 'reverse', $change);

        return ['schema_version' => self::SCHEMA_VERSION, 'reversed' => true, 'kind' => $kind, 'change' => $change, 'persisted' => $persisted];
    }

    /**
     * @return array<string,mixed>|null
     */
    private function applyRouting(AiLearningProposal $proposal): ?array
    {
        $ps = is_array($proposal->proposed_state) ? $proposal->proposed_state : [];
        $route = [
            'task_category' => trim((string) ($ps['task_category'] ?? '')),
            'role' => trim((string) ($ps['role'] ?? '')),
            'provider' => trim((string) ($ps['provider'] ?? '')),
            'model' => (string) ($ps['model'] ?? ''),
        ];
        if ($route['task_category'] === '' || $route['role'] === '' || $route['provider'] === '') {
            return null;
        }

        $this->routing->applyPreferred($route);

        // O único dos quatro appliers que devolvia a mudança sem dizer como desfazê-la.
        // Reversível via clearPreferred(task_category, role), que é o que o --reverse do
        // comando chama — mesmo contrato de handle dos outros três.
        $route['reverse_handle'] = 'php artisan atlas:ai:apply-learning '.$proposal->getKey().' --reverse';

        return $route;
    }

    /**
     * AP-819 Obra B — aplica um edit de harness na SUPERFÍCIE DECLARADA (e só nela):
     * o AtlasHarnessSurface valida allowlist+bounds e persiste o override reversível.
     * Um key fora da superfície ou valor fora dos bounds ⇒ null ⇒ refuse — o G1
     * estrutural na prática.
     *
     * @return array<string,mixed>|null
     */
    private function applyHarnessConfig(AiLearningProposal $proposal): ?array
    {
        $ps = is_array($proposal->proposed_state) ? $proposal->proposed_state : [];
        $key = trim((string) ($ps['key'] ?? ''));
        $value = $ps['value'] ?? null;
        if ($key === '' || $value === null) {
            return null;
        }

        $result = app(AtlasHarnessSurface::class)
            ->applyOverride($key, $value, (string) $proposal->getKey());
        if (! $result['applied']) {
            return null;
        }

        return [
            'key' => $result['key'],
            'value' => $result['value'],
            'previous' => $result['previous'],
            'reverse_handle' => 'php artisan atlas:harness reverse '.$result['key'],
        ];
    }

    /**
     * AP-819 Surface v2 — aplica uma troca de seção de INSTRUÇÃO. A superfície
     * só aceita texto do espaço de busca declarado (default + variantes curadas);
     * texto fora da biblioteca ⇒ null ⇒ refuse, por construção.
     *
     * @return array<string,mixed>|null
     */
    private function applyHarnessInstruction(AiLearningProposal $proposal): ?array
    {
        $ps = is_array($proposal->proposed_state) ? $proposal->proposed_state : [];
        $section = trim((string) ($ps['key'] ?? ''));
        $text = $ps['text'] ?? null;
        if ($section === '' || ! is_string($text)) {
            return null;
        }

        $result = app(AtlasHarnessInstructionSurface::class)
            ->applyOverride($section, $text, (string) $proposal->getKey());
        if (! $result['applied']) {
            return null;
        }

        return [
            'section' => $result['section'],
            'text_sha' => hash('sha256', $text),
            'previous_text_sha' => hash('sha256', (string) $result['previous']),
            'reverse_handle' => 'php artisan atlas:harness reverse '.$result['section'],
        ];
    }

    /**
     * Materialize a non-critical learning as a REVERSIBLE AtlasMemoryEntry (SoftDeletes
     * + privacy_class + the atlas:ai:memory-forget handle). Fail-closed: refuses to
     * write live memory for a sensitive/secret/cyber/unclassified privacy class — those
     * never auto-materialize, they stay for the operator's Sunday review.
     *
     * @return array<string,mixed>|null
     */
    private function applyAsMemoryEntry(AiLearningProposal $proposal): ?array
    {
        $ps = is_array($proposal->proposed_state) ? $proposal->proposed_state : [];
        $privacy = strtolower(trim((string) ($ps['privacy_class'] ?? '')));
        if (! in_array($privacy, ['public', 'normal'], true)) {
            return null; // fail-closed: only known non-sensitive privacy classes materialize as live memory
        }
        $title = trim((string) ($ps['title'] ?? $ps['claim'] ?? ((string) $proposal->kind.' learning')));
        $body = trim((string) ($ps['body'] ?? $ps['claim'] ?? ''));
        if ($body === '') {
            return null;
        }

        $entry = new AtlasMemoryEntry;
        $entry->forceFill([
            'memory_type' => (string) $proposal->kind,
            'scope_type' => 'global',
            'title' => mb_substr($title, 0, 200),
            'body' => $body,
            'summary' => mb_substr($title, 0, 200),
            'privacy_class' => $privacy,
            'status' => 'active',
            'confidence' => (float) ($ps['confidence'] ?? 0.7),
            'source_type' => self::AUTONOMOUS_SOURCE,
            'source_id' => (string) $proposal->getKey(),
            'source_label' => 'atlas-autonomous-learning',
        ])->save();

        return [
            'memory_entry_id' => (string) $entry->getKey(),
            'privacy_class' => $privacy,
            'kind' => (string) $proposal->kind,
            'reverse_handle' => 'php artisan atlas:ai:memory-forget '.$entry->getKey(),
        ];
    }

    private function transition(AiLearningProposal $proposal, string $status, string $operator): bool
    {
        $proposal->forceFill([
            'status' => $status,
            'decided_by' => $operator !== '' ? $operator : $proposal->decided_by,
        ]);
        if (! $proposal->exists) {
            return true; // no persisted row (in-memory context) — nothing to lag
        }
        try {
            $proposal->save();

            return true;
        } catch (Throwable) {
            // bookkeeping save is best-effort; the behaviour change is the contract.
            return false;
        }
    }

    /**
     * @param  array<string,mixed>  $change
     */
    private function recordReceipt(AiLearningProposal $proposal, string $operator, string $action, array $change): void
    {
        try {
            app(AtlasEvidenceLedger::class)->record(
                LedgerEventType::DecisionIssued,
                [
                    'schema_version' => self::SCHEMA_VERSION,
                    'decision' => 'learning_'.$action,
                    'kind' => (string) $proposal->kind,
                    'proposal_hash' => (string) ($proposal->proposal_hash ?? ''),
                    'change' => $change,
                ],
                [
                    'operator_id' => $operator,
                    'emitter_stage' => 'atlas.ai.learning_applier',
                    'emitter_version' => 'learning-applier-v1',
                ],
            );
        } catch (Throwable) {
            // receipt is best-effort; the gate's contract does not depend on it.
        }
    }

    /**
     * @return array<string,mixed>
     */
    private function refuse(string $reason): array
    {
        return [
            'schema_version' => self::SCHEMA_VERSION,
            'applied' => false,
            'reason' => $reason,
            'kind' => null,
            'change' => null,
            'reversible' => false,
        ];
    }
}
