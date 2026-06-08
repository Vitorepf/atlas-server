<?php

declare(strict_types=1);

namespace App\Services\Ai\Compounding;

use App\Models\AtlasLedgerEvent;
use App\Services\Ai\Kernel\Evidence\LedgerEventType;
use Carbon\CarbonImmutable;
use Illuminate\Support\Facades\Schema;

/**
 * Closes the compounding loop the evidence-OUT funnel opened.
 *
 * `atlas:ai:bridge-evidence` stamps governed provider/workflow evidence into the
 * ledger as `EvidencePacked` at `decision='hold'` / `promotion_allowed=false`.
 * Until now NOTHING consumed it — the held evidence entered the append-only ledger
 * and evaporated, so the N×M loop was mechanically open at the brain boundary.
 *
 * This miner is the missing consumer: it reads the held evidence back out of the
 * ledger, groups corroborating signals by kind, and materialises propose-only
 * learning proposals (via the canonical {@see AtlasLearningProposalService}, which
 * forces `status='proposed'` + human review and rejects any auto-apply). Because
 * the mined evidence already passed admit + governSignal + the secret-class guard
 * at funnel time, the miner is a strictly propose-only consumer: it reads the
 * ledger and proposes — it never promotes, never mutates, never spends.
 */
class AtlasHeldEvidenceMinerService
{
    public const SCHEMA_VERSION = 'atlas.ai.held_evidence_miner.v1';

    public function __construct(
        private readonly AtlasLearningProposalService $proposals,
    ) {}

    /**
     * @return array{schema_version:string,status:string,scanned:int,held:int,proposals:list<array<string,mixed>>}
     */
    public function mine(int $hours = 24, int $minCorroboration = 1): array
    {
        // Guard the table this miner reads; the proposal service owns its own.
        if (! Schema::hasTable('atlas_ledger_events')) {
            return ['schema_version' => self::SCHEMA_VERSION, 'status' => 'unavailable', 'scanned' => 0, 'held' => 0, 'proposals' => []];
        }

        $since = CarbonImmutable::now()->subHours(max(1, $hours));
        $events = AtlasLedgerEvent::query()
            ->where('event_type', LedgerEventType::EvidencePacked->value)
            // Bind explicitly to the bridge-evidence funnel: another EvidencePacked
            // producer must not be mined just because it stamps the same two payload
            // keys. emitter_stage is an indexed column the bridge always sets.
            ->where('emitter_stage', 'atlas.ai.bridge_evidence')
            ->where('occurred_at', '>=', $since)
            ->orderBy('occurred_at')
            ->get();

        // Only the funnel's HELD, non-promotable evidence is mineable.
        $held = $events->filter(static function (AtlasLedgerEvent $e): bool {
            $p = (array) $e->payload;

            return ($p['decision'] ?? null) === 'hold' && ($p['promotion_allowed'] ?? true) === false;
        });

        /** @var array<string,list<AtlasLedgerEvent>> $byKind */
        $byKind = [];
        foreach ($held as $e) {
            $kind = (string) (data_get($e->payload, 'kind') ?: 'provider_call');
            $byKind[$kind][] = $e;
        }

        $minted = [];
        foreach ($byKind as $evidenceKind => $group) {
            if (count($group) < max(1, $minCorroboration)) {
                continue;
            }

            $eventIds = array_values(array_map(static fn (AtlasLedgerEvent $e): string => (string) $e->event_id, $group));

            // Honest kind: gate/repair evidence is a failure pattern; everything else
            // is a heuristic candidate. Both are review-gated, never auto-applied.
            $proposalKind = in_array($evidenceKind, ['gate_result', 'repair_attempt'], true) ? 'failure_pattern' : 'heuristic';

            $proposal = $this->proposals->propose([
                'kind' => $proposalKind,
                'summary' => 'Held governed evidence ('.$evidenceKind.', '.count($group).' corroborating event(s)) surfaced for learning review.',
                'scope' => 'global',
                'evidence_refs' => $eventIds,
                'current_state' => ['source' => 'held_evidence_miner', 'evidence_kind' => $evidenceKind, 'corroboration' => count($group)],
                'proposed_state' => ['action' => 'review_held_evidence_for_compounding'],
            ]);

            $minted[] = [
                'proposal_id' => $proposal->id,
                'proposal_kind' => $proposalKind,
                'evidence_kind' => $evidenceKind,
                'corroboration' => count($group),
                'status' => $proposal->status,
                'evidence_refs' => $eventIds,
            ];
        }

        return [
            'schema_version' => self::SCHEMA_VERSION,
            'status' => 'mined',
            'scanned' => $events->count(),
            'held' => $held->count(),
            'proposals' => $minted,
        ];
    }
}
