<?php

declare(strict_types=1);

namespace App\Services\Ai\Autonomy;

use App\Models\AiCompoundingMemory;
use App\Models\AiLearningProposal;
use App\Models\AiMemoryDelta;
use App\Models\AtlasAemorMemoryCandidate;
use App\Models\AtlasMemoryEntry;
use App\Models\OperatorLearningCandidate;
use App\Models\OperatorPatternDetection;
use App\Models\OperatorProfileItem;
use App\Models\OperatorSkillProposal;
use App\Models\SemanticCurationProposal;
use App\Models\SemanticNote;
use App\Models\SemanticNoteActivation;
use App\Services\Ai\AtlasDecide\AtlasConductorRoutingMemory;
use Illuminate\Support\Facades\File;
use Illuminate\Support\Facades\Schema;
use Throwable;

/**
 * The Sunday memory digest — a READ-ONLY, mutation-free aggregation of everything
 * Atlas saved to memory over a window (default 7 days) plus every learning the
 * autonomous loop auto-applied, each carrying a one-command REVERSE handle so the
 * operator reviews AFTER the fact and prunes what they don't want.
 *
 * This is a read model. It NEVER writes, promotes, archives, or mutates anything —
 * it only reports. Reversal is always the operator's explicit, separate action.
 * It reuses the existing memory surfaces (central registry `atlas_memory_entries`,
 * compounding memory `ai_compounding_memories`, the conductor preferred-route log);
 * it does NOT build a parallel memory store.
 */
final class AtlasWeeklyMemoryDigestService
{
    public const SCHEMA = 'atlas.ai.weekly_memory_digest.v1';

    /** Per-section item cap so a busy week's digest stays readable (counts are still exact). */
    private const ITEM_CAP = 200;

    public function __construct(
        private readonly AtlasConductorRoutingMemory $routing = new AtlasConductorRoutingMemory(),
    ) {}

    /**
     * @return array<string,mixed>
     */
    public function digest(int $days = 7): array
    {
        $days = max(1, min(365, $days));
        $sinceIso = now()->subDays($days)->toIso8601String();

        $entries = $this->memoryEntries($days);
        $compounding = $this->compoundingMemory($days);
        $applied = $this->appliedLearnings($days);
        $proposals = $this->learningProposals($days);
        $staged = $this->stagedCaptures($days);
        $aemor = $this->aemorCandidates($days);
        $semantic = $this->semanticMemory($days);
        $operatorProfile = $this->operatorProfile($days);
        $proactive = $this->proactiveProposals($days);

        return [
            'schema_version' => self::SCHEMA,
            'generated_at' => now()->toIso8601String(),
            'window_days' => $days,
            'since' => $sinceIso,
            'memory_entries' => $entries,
            'compounding_memory' => $compounding,
            'applied_learnings' => $applied,
            'learning_proposals' => $proposals,
            'operator_profile' => $operatorProfile,
            'proactive_proposals' => $proactive,
            'staged_captures' => $staged,
            'aemor_candidates' => $aemor,
            'semantic_memory' => $semantic,
            'totals' => [
                'memory_entries' => $entries['count'],
                'compounding_candidates' => $compounding['count'],
                'auto_applied_learnings' => $applied['count'] + $proposals['auto_applied'] + $operatorProfile['auto_applied'],
                'operator_profile_learned' => $operatorProfile['learned_active'],
                'proactive_proposals' => $proactive['count'],
                'pending_your_review' => $proposals['pending_review'] + $staged['pending_confirmation'] + $semantic['pending_review'] + $operatorProfile['pending_review'] + $proactive['pending_review'],
                'staged_captures' => $staged['count'],
                'aemor_candidates' => $aemor['count'],
                'semantic_memory' => $semantic['count'],
                'total_saved' => $entries['count'] + $compounding['count'] + $proposals['count'] + $staged['count'] + $aemor['count'] + $semantic['count'] + $operatorProfile['count'],
            ],
            'review_note' => 'Everything below was saved automatically. "pending_your_review" is the queue to triage; '
                .'prune any saved item with its reverse handle — all reversals are non-destructive (archive/restore, never hard-delete).',
        ];
    }

    /**
     * Central memory registry — "a memória do Atlas" (atlas:memory:* surface).
     *
     * @return array<string,mixed>
     */
    private function memoryEntries(int $days): array
    {
        if (! $this->tableReady('atlas_memory_entries')) {
            return $this->emptySection('atlas_memory_entries table unavailable');
        }

        try {
            $base = AtlasMemoryEntry::query()->where('created_at', '>=', now()->subDays($days));
            $count = (clone $base)->count();
            $byType = (clone $base)->selectRaw('memory_type, count(*) as c')->groupBy('memory_type')->pluck('c', 'memory_type')->all();
            $byPrivacy = (clone $base)->selectRaw('privacy_class, count(*) as c')->groupBy('privacy_class')->pluck('c', 'privacy_class')->all();

            $items = (clone $base)->orderByDesc('created_at')->limit(self::ITEM_CAP)->get()
                ->map(fn (AtlasMemoryEntry $e): array => [
                    'id' => (string) $e->getKey(),
                    'memory_type' => (string) $e->memory_type,
                    'title' => (string) ($e->redacted_title ?: $e->title),
                    'summary' => (string) ($e->redacted_summary ?: $e->summary),
                    'confidence' => $e->confidence,
                    'privacy_class' => (string) $e->privacy_class,
                    'source' => (string) ($e->source_label ?: $e->source_type),
                    'status' => (string) $e->status,
                    'recorded_at' => optional($e->created_at)->toIso8601String(),
                    'reverse_handle' => 'php artisan atlas:ai:memory-forget '.$e->getKey().'   (undo: --restore)',
                ])->all();

            return [
                'count' => $count,
                'shown' => count($items),
                'by_type' => $byType,
                'by_privacy_class' => $byPrivacy,
                'items' => $items,
            ];
        } catch (Throwable $e) {
            return $this->emptySection('read failed: '.$e->getMessage());
        }
    }

    /**
     * Compounding learning candidates saved this window.
     *
     * @return array<string,mixed>
     */
    private function compoundingMemory(int $days): array
    {
        if (! $this->tableReady('ai_compounding_memories')) {
            return $this->emptySection('ai_compounding_memories table unavailable');
        }

        try {
            $base = AiCompoundingMemory::query()->where('created_at', '>=', now()->subDays($days));
            $count = (clone $base)->count();
            $byStatus = (clone $base)->selectRaw('status, count(*) as c')->groupBy('status')->pluck('c', 'status')->all();

            $items = (clone $base)->orderByDesc('created_at')->limit(self::ITEM_CAP)->get()
                ->map(fn (AiCompoundingMemory $m): array => [
                    'id' => (string) $m->getKey(),
                    'memory_type' => (string) $m->memory_type,
                    'scope' => (string) $m->scope,
                    'claim' => (string) $m->claim,
                    'confidence' => $m->confidence,
                    'status' => (string) $m->status,
                    'recorded_at' => optional($m->created_at)->toIso8601String(),
                ])->all();

            return [
                'count' => $count,
                'shown' => count($items),
                'by_status' => $byStatus,
                'items' => $items,
            ];
        } catch (Throwable $e) {
            return $this->emptySection('read failed: '.$e->getMessage());
        }
    }

    /**
     * Learnings the autonomous loop auto-applied to runtime (preferred conductor
     * routes), each reversible via the flywheel applier or a preferred clear.
     *
     * @return array<string,mixed>
     */
    private function appliedLearnings(int $days): array
    {
        try {
            $path = $this->routing->preferredPath();
            if (! File::exists($path)) {
                return ['count' => 0, 'shown' => 0, 'items' => [], 'note' => 'no auto-applied routes yet'];
            }
            $cutoff = now()->subDays($days)->getTimestamp();
            $rows = [];
            foreach (preg_split('/\r?\n/', (string) File::get($path)) ?: [] as $line) {
                if (trim($line) === '') {
                    continue;
                }
                $row = json_decode($line, true);
                if (! is_array($row) || ($row['action'] ?? '') !== 'set') {
                    continue;
                }
                $stamp = (string) ($row['recorded_at'] ?? $row['at'] ?? '');
                $at = $stamp !== '' ? strtotime($stamp) : null;
                if ($at !== null && $at !== false && $at < $cutoff) {
                    continue;
                }
                $rows[] = [
                    'task_category' => (string) ($row['task_category'] ?? ''),
                    'role' => (string) ($row['role'] ?? ''),
                    'provider' => (string) ($row['provider'] ?? ''),
                    'model' => (string) ($row['model'] ?? ''),
                    'applied_at' => $stamp,
                    'reverse_handle' => 'php artisan atlas:atlas-decide:meta-learning:deactivate'
                        .' --task-category='.($row['task_category'] ?? '').' --role='.($row['role'] ?? '')
                        .' --mode=apply --check=atlas-decide-meta-learning --confirm',
                ];
            }

            return ['count' => count($rows), 'shown' => count($rows), 'items' => array_slice($rows, -self::ITEM_CAP)];
        } catch (Throwable $e) {
            return $this->emptySection('read failed: '.$e->getMessage());
        }
    }

    /**
     * Governed learning proposals: status='proposed' IS the Sunday review queue (what
     * was routed to YOU, not auto-applied); status='applied' + decided_by='atlas-auto'
     * are the autonomous applications, each reversible. This is the single most
     * important section for "entender tudo que foi salvo".
     *
     * @return array<string,mixed>
     */
    private function learningProposals(int $days): array
    {
        if (! $this->tableReady('ai_learning_proposals')) {
            return $this->emptySection('ai_learning_proposals table unavailable') + ['pending_review' => 0, 'auto_applied' => 0];
        }

        try {
            $base = AiLearningProposal::query()->where('created_at', '>=', now()->subDays($days));
            $count = (clone $base)->count();
            $byStatus = (clone $base)->selectRaw('status, count(*) as c')->groupBy('status')->pluck('c', 'status')->all();
            $byKind = (clone $base)->selectRaw('kind, count(*) as c')->groupBy('kind')->pluck('c', 'kind')->all();
            $pendingReview = (int) ($byStatus['proposed'] ?? 0);
            $autoApplied = (clone $base)->where('status', 'applied')->where('decided_by', 'atlas-auto')->count();

            $items = (clone $base)->orderByDesc('created_at')->limit(self::ITEM_CAP)->get()
                ->map(function (AiLearningProposal $p): array {
                    $status = (string) $p->status;
                    $autoApplied = $status === 'applied' && (string) $p->decided_by === 'atlas-auto';

                    return [
                        'id' => (string) $p->getKey(),
                        'kind' => (string) $p->kind,
                        'scope' => (string) $p->scope,
                        'status' => $status,
                        'decided_by' => (string) $p->decided_by,
                        'recorded_at' => optional($p->created_at)->toIso8601String(),
                        'reverse_handle' => $autoApplied
                            ? 'php artisan atlas:ai:apply-learning '.$p->getKey().' --reverse'
                            : ($status === 'proposed' ? '(pending your review — approve/reject)' : ''),
                    ];
                })->all();

            return [
                'count' => $count,
                'shown' => count($items),
                'pending_review' => $pendingReview,
                'auto_applied' => $autoApplied,
                'by_status' => $byStatus,
                'by_kind' => $byKind,
                'items' => $items,
            ];
        } catch (Throwable $e) {
            return $this->emptySection('read failed: '.$e->getMessage()) + ['pending_review' => 0, 'auto_applied' => 0];
        }
    }

    /**
     * "Atlas learns YOU" — the operator-profile surface (the 170-item taxonomy). Reports
     * what was captured this window (candidates), what auto-applied (safe explicit
     * high-confidence items), and the live profile now shaping every response, each with
     * a one-command undo. THIS is the section that answers "what did Atlas learn about me".
     *
     * @return array<string,mixed>
     */
    private function operatorProfile(int $days): array
    {
        $out = [
            'count' => 0, 'pending_review' => 0, 'auto_applied' => 0, 'learned_active' => 0,
            'by_status' => [], 'by_taxonomy' => [], 'learned' => [],
            'note' => 'what Atlas learned about you; auto-applied items are live in context, reversible',
        ];
        $since = now()->subDays($days);

        if ($this->tableReady('operator_learning_candidates')) {
            try {
                $base = OperatorLearningCandidate::query()->where('created_at', '>=', $since);
                $out['count'] = (clone $base)->count();
                $out['by_status'] = (clone $base)->selectRaw('status, count(*) as c')->groupBy('status')->pluck('c', 'status')->all();
                $out['pending_review'] = (int) ($out['by_status']['candidate'] ?? 0);
                $out['auto_applied'] = (clone $base)->where('status', 'approved')
                    ->where('decided_by', 'atlas-operator-intelligence-auto')->count();
            } catch (Throwable $e) {
                $out['note'] = 'candidates read failed: '.$e->getMessage();
            }
        }

        if ($this->tableReady('operator_profile_items')) {
            try {
                $live = OperatorProfileItem::query()
                    ->where('status', OperatorProfileItem::STATUS_ACTIVE)
                    ->where('updated_at', '>=', $since);
                $out['learned_active'] = (clone $live)->count();
                $out['by_taxonomy'] = (clone $live)->selectRaw('taxonomy_item_id, count(*) as c')
                    ->groupBy('taxonomy_item_id')->pluck('c', 'taxonomy_item_id')->all();
                $out['learned'] = (clone $live)->orderByDesc('updated_at')->limit(self::ITEM_CAP)->get()
                    ->map(fn (OperatorProfileItem $i): array => [
                        'id' => (string) $i->id,
                        'taxonomy_item_id' => (string) $i->taxonomy_item_id,
                        'summary' => $i->privacy_class === 'normal' ? (string) $i->summary : '['.$i->privacy_class.' — redacted at rest]',
                        'confidence' => $i->confidence,
                        'automation_level' => (string) $i->automation_level,
                        'auto_applied' => $i->automation_level === 'auto_apply_reversible',
                        'scope' => $i->scope_type.($i->scope_id ? ':'.$i->scope_id : ''),
                        'reverse_handle' => 'php artisan atlas:ai:operator-profile archive '.$i->id.'   (undo: ... restore '.$i->id.')',
                    ])->all();
            } catch (Throwable $e) {
                $out['note'] = ($out['note'] ?? '').' | items read failed: '.$e->getMessage();
            }
        }

        return $out;
    }

    /**
     * "Atlas prepared things for you" — the proactive surface. Recurring patterns Atlas
     * detected, the mission DRAFTS it prepared (review + activate), and the skill BUILD
     * proposals it queued (promote with --confirm). All propose-only: nothing here ran or
     * was promoted without you.
     *
     * @return array<string,mixed>
     */
    private function proactiveProposals(int $days): array
    {
        $out = [
            'count' => 0, 'pending_review' => 0, 'patterns' => 0, 'missions' => 0, 'skills' => 0,
            'by_kind' => [], 'items' => [],
            'note' => 'recurring patterns Atlas detected + prepared (drafts/build proposals) — nothing executed or promoted without you',
        ];
        $since = now()->subDays($days);

        if ($this->tableReady('operator_pattern_detections')) {
            try {
                $base = OperatorPatternDetection::query()->where('created_at', '>=', $since);
                $out['patterns'] = (clone $base)->count();
                $out['by_kind'] = (clone $base)->selectRaw('kind, count(*) as c')->groupBy('kind')->pluck('c', 'kind')->all();
                $out['missions'] = (clone $base)->whereNotNull('proposed_mission_id')->count();
                $out['skills'] = (clone $base)->whereNotNull('proposed_skill_task_id')->count();
                $out['pending_review'] = (clone $base)->whereIn('status', [
                    OperatorPatternDetection::STATUS_DETECTED, OperatorPatternDetection::STATUS_PROPOSED,
                ])->count();

                $out['items'] = (clone $base)->whereIn('status', [OperatorPatternDetection::STATUS_DETECTED, OperatorPatternDetection::STATUS_PROPOSED])
                    ->orderByDesc('confidence')->limit(self::ITEM_CAP)->get()
                    ->map(fn (OperatorPatternDetection $d): array => [
                        'pattern_id' => (string) $d->pattern_id,
                        'kind' => (string) $d->kind,
                        'summary' => $d->privacy_class === 'normal' ? (string) $d->summary : '['.$d->privacy_class.' — redacted]',
                        'occurrence_count' => (int) $d->occurrence_count,
                        'confidence' => $d->confidence,
                        'prepared' => array_values(array_filter([
                            $d->proposed_mission_id ? 'mission_draft' : null,
                            $d->proposed_skill_task_id ? 'skill_build_proposal' : null,
                        ])),
                        'mission_handle' => $d->proposed_mission_id
                            ? 'review mission '.$d->proposed_mission_id.' (draft — activate to run; nothing executes until you do)'
                            : null,
                        'skill_handle' => $d->proposed_skill_task_id
                            ? 'php artisan atlas:ai:operator-skill approve <proposal-id> --confirm   (promote the built skill into the live vault)'
                            : null,
                        'dismiss_handle' => 'mark pattern '.substr((string) $d->pattern_id, 0, 10).' dismissed to stop proposing it',
                    ])->all();
                $out['count'] = $out['patterns'];
            } catch (Throwable $e) {
                $out['note'] = 'read failed: '.$e->getMessage();
            }
        }

        // Skills Atlas auto-BUILT (staged in the sandbox) awaiting your explicit --confirm.
        if ($this->tableReady('operator_skill_proposals')) {
            try {
                $staged = OperatorSkillProposal::query()
                    ->where('status', OperatorSkillProposal::STATUS_STAGED)
                    ->where('created_at', '>=', $since)
                    ->orderByDesc('confidence')->limit(self::ITEM_CAP)->get();
                $out['skills_built'] = $staged->count();
                $out['skills_awaiting_promotion'] = $staged->map(fn (OperatorSkillProposal $p): array => [
                    'slug' => (string) $p->slug,
                    'title' => (string) $p->title,
                    'confidence' => $p->confidence,
                    'promote_handle' => 'php artisan atlas:ai:operator-skill approve '.$p->slug.' --confirm',
                    'inspect_handle' => 'php artisan atlas:ai:operator-skill show '.$p->slug,
                    'reject_handle' => 'php artisan atlas:ai:operator-skill reject '.$p->slug,
                ])->all();
                $out['pending_review'] += $staged->count();
            } catch (Throwable) {
                // skills surface is best-effort
            }
        }

        return $out;
    }

    /**
     * Staged auto-captured deltas (ai_memory_deltas) — the raw "every use saves
     * something" surface, born pending + requires_confirmation by the cognitive-immune
     * default. These are NOT live memory; they wait for promotion/confirmation.
     *
     * @return array<string,mixed>
     */
    private function stagedCaptures(int $days): array
    {
        if (! $this->tableReady('ai_memory_deltas')) {
            return $this->emptySection('ai_memory_deltas table unavailable') + ['pending_confirmation' => 0];
        }

        try {
            $base = AiMemoryDelta::query()->where('created_at', '>=', now()->subDays($days));
            $count = (clone $base)->count();
            $byStatus = (clone $base)->selectRaw('status, count(*) as c')->groupBy('status')->pluck('c', 'status')->all();
            $pending = (clone $base)->where('requires_confirmation', true)->count();

            return [
                'count' => $count,
                'pending_confirmation' => $pending,
                'by_status' => $byStatus,
                'note' => 'staged captures wait for confirmation/promotion — not live memory',
            ];
        } catch (Throwable $e) {
            return $this->emptySection('read failed: '.$e->getMessage()) + ['pending_confirmation' => 0];
        }
    }

    /**
     * AEMOR distilled memory candidates (atlas_aemor_memory_candidates).
     *
     * @return array<string,mixed>
     */
    private function aemorCandidates(int $days): array
    {
        if (! $this->tableReady('atlas_aemor_memory_candidates')) {
            return $this->emptySection('atlas_aemor_memory_candidates table unavailable');
        }

        try {
            $base = AtlasAemorMemoryCandidate::query()->where('created_at', '>=', now()->subDays($days));
            $count = (clone $base)->count();
            $byStatus = (clone $base)->selectRaw('status, count(*) as c')->groupBy('status')->pluck('c', 'status')->all();

            return ['count' => $count, 'by_status' => $byStatus];
        } catch (Throwable $e) {
            return $this->emptySection('read failed: '.$e->getMessage());
        }
    }

    /**
     * Semantic-memory product surfaces (notes + curation proposals + activations) —
     * a real saved-memory product the operator may want to see and prune.
     *
     * @return array<string,mixed>
     */
    private function semanticMemory(int $days): array
    {
        $out = ['count' => 0, 'pending_review' => 0, 'notes' => 0, 'curation_proposals' => 0, 'activations' => 0];
        $since = now()->subDays($days);
        try {
            if ($this->tableReady('semantic_notes')) {
                $out['notes'] = SemanticNote::query()->where('created_at', '>=', $since)->count();
            }
            if ($this->tableReady('semantic_curation_proposals')) {
                $out['curation_proposals'] = SemanticCurationProposal::query()->where('created_at', '>=', $since)->count();
            }
            if ($this->tableReady('semantic_note_activations')) {
                $out['activations'] = SemanticNoteActivation::query()->where('created_at', '>=', $since)->count();
            }
        } catch (Throwable $e) {
            $out['note'] = 'read failed: '.$e->getMessage();
        }
        $out['pending_review'] = $out['curation_proposals']; // curation proposals are reviewable by nature
        $out['count'] = $out['notes'] + $out['curation_proposals'] + $out['activations'];

        return $out;
    }

    /**
     * @return array<string,mixed>
     */
    private function emptySection(string $note): array
    {
        return ['count' => 0, 'shown' => 0, 'items' => [], 'note' => $note];
    }

    private function tableReady(string $table): bool
    {
        try {
            return Schema::hasTable($table);
        } catch (Throwable) {
            return false;
        }
    }
}
