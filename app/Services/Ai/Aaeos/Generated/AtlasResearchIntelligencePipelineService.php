<?php

declare(strict_types=1);

namespace App\Services\Ai\Aaeos\Generated;

/**
 * Atlas AI Research Intelligence Pipeline decider.
 *
 * Pure, deterministic implementation of the canonical research pipeline that
 * carries a finding from a raw objective to a (possibly) promotable Research
 * Packet. Nothing here touches a database, provider, shell or filesystem: every
 * method returns a typed verdict array a caller may act on.
 *
 * The doc encodes four concrete contracts; each is enforced verbatim here:
 *
 *   1. Pipeline ordering (8 ordered steps). A finding advances strictly in
 *      order; a step may only run once all prior steps are complete:
 *        define_objective -> discover_sources -> classify_tier ->
 *        extract_claims -> detect_conflicts -> synthesize_impact ->
 *        create_packet -> decide.
 *      "Research must be packetized before it changes docs, APs, memory or
 *      runtime." -> the `decide` step is unreachable until `create_packet`.
 *
 *   2. Discovery mix. "Use a balanced source mix": repo evidence and existing
 *      docs FIRST; official docs/specs; papers/measurements; engineering
 *      postmortems; "community only as discovery lead." -> a mix with no repo
 *      evidence is unbalanced, and a mix that leans on community as *backing*
 *      (not merely a lead) is rejected.
 *
 *   3. Output quality. The doc lists six good signals (specific, source-backed,
 *      conflict-aware, time-aware, actionable, bounded) and six bad signals
 *      (generic, citation-free, hype-driven, implementation-first,
 *      uncertainty-blind, detached). A packet is publishable only when it is
 *      free of every bad signal AND carries the load-bearing good signals.
 *
 *   4. Terminal disposition. "Decide promote / hold / archive / research more."
 *      The frontmatter decision "The pipeline must preserve uncertainty and
 *      conflicting evidence" is binding: unresolved conflicts or live
 *      uncertainty can NEVER promote; they route to research_more or hold.
 *
 * The service NEVER promotes, writes a doc, applies code or runs a tool. It
 * returns the verdict; the caller decides.
 *
 * @see docs/engineering-knowledge-base/research-self-improvement/research-pipeline.md
 */
final class AtlasResearchIntelligencePipelineService
{
    /** Stable receipt schema id for the verdicts this service emits. */
    public const RECEIPT_SCHEMA = 'atlas.research.intelligence_pipeline.v1';

    /** Frozen Research Packet schema_version (from the doc's JSON contract). */
    public const PACKET_SCHEMA = 'atlas.research_packet.v1';

    /**
     * The 8 ordered pipeline steps, in canonical order. Index == required
     * predecessor count: a step at position i needs steps 0..i-1 complete.
     */
    public const STEPS = [
        'define_objective',
        'discover_sources',
        'classify_tier',
        'extract_claims',
        'detect_conflicts',
        'synthesize_impact',
        'create_packet',
        'decide',
    ];

    /** Closed enum for the Research Packet `recommended_action` (from the doc). */
    public const RECOMMENDED_ACTIONS = [
        'promote_to_doc',
        'create_ap',
        'measure',
        'archive',
        'research_more',
    ];

    /** Terminal dispositions for the `decide` step. */
    public const DISPOSITIONS = ['promote', 'hold', 'archive', 'research_more'];

    /**
     * Documented source kinds, strongest-first. "repo evidence and existing
     * docs first" -> repo is the trust anchor; "community only as discovery
     * lead" -> community may surface a lead but may never back a conclusion.
     */
    public const SOURCE_KINDS = ['repo', 'official_doc', 'paper', 'measurement', 'postmortem', 'community'];

    /** Kinds that count as evidence a conclusion may rest on. */
    private const BACKING_KINDS = ['repo', 'official_doc', 'paper', 'measurement', 'postmortem'];

    /** The six documented "good research output" signals. */
    public const GOOD_SIGNALS = ['specific', 'source_backed', 'conflict_aware', 'time_aware', 'actionable', 'bounded'];

    /** The six documented "bad research output" signals. */
    public const BAD_SIGNALS = ['generic', 'citation_free', 'hype_driven', 'implementation_first', 'uncertainty_blind', 'detached'];

    /**
     * Contract 1 — pipeline ordering.
     *
     * Given the set of completed steps, decide which step may run next and
     * whether the pipeline is complete. A step may only run when every earlier
     * step is complete; the first incomplete step is the one that may run.
     *
     * @param list<string> $completed step names already finished
     *
     * @return array{
     *     schema:string,
     *     ordered:bool,
     *     complete:bool,
     *     next_step:?string,
     *     blocked_step:?string,
     *     completed_count:int,
     *     reason:string
     * }
     */
    public function advancePipeline(array $completed): array
    {
        $done = [];
        foreach ($completed as $step) {
            if (is_string($step) && in_array($step, self::STEPS, true)) {
                $done[$step] = true;
            }
        }

        // Walk steps in canonical order; the first one not yet done is "next".
        $next = null;
        $blocked = null;
        $contiguousCount = 0;
        $gapSeen = false;

        foreach (self::STEPS as $step) {
            $isDone = isset($done[$step]);

            if ($isDone && $gapSeen) {
                // A later step is done while an earlier one is not: out of order.
                $blocked = $step;
            }

            if (! $isDone) {
                if ($next === null) {
                    $next = $step;
                }
                $gapSeen = true;
            } elseif (! $gapSeen) {
                $contiguousCount++;
            }
        }

        $complete = $next === null;
        $ordered = $blocked === null;

        $reason = match (true) {
            ! $ordered => 'out_of_order_step_completed_before_predecessor',
            $complete => 'pipeline_complete',
            $next === 'decide' => 'ready_to_decide_packet_exists',
            $next === 'create_packet' => 'must_packetize_before_decide',
            default => 'advance_to_next_step',
        };

        return [
            'schema' => self::RECEIPT_SCHEMA,
            'ordered' => $ordered,
            'complete' => $complete,
            'next_step' => $next,
            'blocked_step' => $blocked,
            'completed_count' => $contiguousCount,
            'reason' => $reason,
        ];
    }

    /**
     * Contract 2 — discovery mix balance.
     *
     * The doc demands a balanced mix anchored on repo evidence, with community
     * usable "only as discovery lead." This rejects a mix that:
     *   - contains NO repo evidence (no trust anchor), or
     *   - contains NO backing source at all (community-only), or
     *   - is empty.
     * It surfaces community presence as a discovery lead, never as backing.
     *
     * @param list<string> $kinds source kinds present in the discovery set
     *
     * @return array{
     *     schema:string,
     *     balanced:bool,
     *     has_repo_anchor:bool,
     *     has_backing:bool,
     *     community_as_lead_only:bool,
     *     missing:list<string>,
     *     reason:string
     * }
     */
    public function assessDiscoveryMix(array $kinds): array
    {
        $present = [];
        foreach ($kinds as $kind) {
            if (is_string($kind) && in_array($kind, self::SOURCE_KINDS, true)) {
                $present[$kind] = true;
            }
        }

        $hasRepo = isset($present['repo']);
        $backing = array_values(array_filter(self::BACKING_KINDS, static fn (string $k): bool => isset($present[$k])));
        $hasBacking = $backing !== [];
        $hasCommunity = isset($present['community']);

        // The minimum balanced mix the doc names first: repo evidence anchor.
        $missing = [];
        if (! $hasRepo) {
            $missing[] = 'repo';
        }
        if (! $hasBacking) {
            $missing[] = 'backing_source';
        }

        $balanced = $hasRepo && $hasBacking;
        // Community is a lead-only signal: true only when it appears with at
        // least one real backing source (so it never stands alone).
        $communityAsLeadOnly = $hasCommunity && $hasBacking;

        $reason = match (true) {
            $present === [] => 'empty_discovery_set',
            ! $hasRepo => 'missing_repo_evidence_anchor',
            $hasCommunity && ! $hasBacking => 'community_only_not_backing',
            $balanced => 'balanced_mix',
            default => 'insufficient_backing',
        };

        return [
            'schema' => self::RECEIPT_SCHEMA,
            'balanced' => $balanced,
            'has_repo_anchor' => $hasRepo,
            'has_backing' => $hasBacking,
            'community_as_lead_only' => $communityAsLeadOnly,
            'missing' => $missing,
            'reason' => $reason,
        ];
    }

    /**
     * Contract 3 — output quality classifier.
     *
     * Maps a candidate output's signals to the doc's good/bad lists. Output is
     * publishable only when it carries ZERO bad signals AND holds the
     * load-bearing good signals the doc names (source-backed, conflict-aware,
     * bounded, actionable). Generic/citation-free/hype always fail.
     *
     * @param list<string> $signals signal tokens describing the output
     *
     * @return array{
     *     schema:string,
     *     publishable:bool,
     *     good:list<string>,
     *     bad:list<string>,
     *     missing_required:list<string>,
     *     reason:string
     * }
     */
    public function classifyOutputQuality(array $signals): array
    {
        $set = [];
        foreach ($signals as $sig) {
            if (is_string($sig)) {
                $set[$sig] = true;
            }
        }

        $good = array_values(array_filter(self::GOOD_SIGNALS, static fn (string $s): bool => isset($set[$s])));
        $bad = array_values(array_filter(self::BAD_SIGNALS, static fn (string $s): bool => isset($set[$s])));

        // Load-bearing good signals without which output cannot be trusted.
        $required = ['source_backed', 'conflict_aware', 'bounded', 'actionable'];
        $missingRequired = array_values(array_filter($required, static fn (string $s): bool => ! isset($set[$s])));

        $publishable = $bad === [] && $missingRequired === [];

        $reason = match (true) {
            $bad !== [] => 'has_bad_signals',
            $missingRequired !== [] => 'missing_required_good_signals',
            default => 'publishable',
        };

        return [
            'schema' => self::RECEIPT_SCHEMA,
            'publishable' => $publishable,
            'good' => $good,
            'bad' => $bad,
            'missing_required' => $missingRequired,
            'reason' => $reason,
        ];
    }

    /**
     * Contract 4 — terminal disposition (the `decide` step).
     *
     * Decides promote / hold / archive / research_more for a Research Packet,
     * honouring the binding decision "preserve uncertainty and conflicting
     * evidence" and "packetized before it changes docs":
     *
     *   - unresolved conflicts OR live uncertainties => research_more
     *     (never promote — the pipeline must preserve them, not bury them);
     *   - no usable atlas_impact and nothing left to chase => archive;
     *   - quality not publishable (or recommended_action not promote_to_doc)
     *     => hold (cannot mutate Atlas from a non-publishable packet);
     *   - clean, publishable, impactful, conflict-free => promote.
     *
     * @param array<string,mixed> $packet a Research Packet (atlas.research_packet.v1)
     *
     * @return array{
     *     schema:string,
     *     disposition:string,
     *     may_change_atlas:bool,
     *     preserved_uncertainty:bool,
     *     open_conflicts:int,
     *     open_uncertainties:int,
     *     reason:string
     * }
     */
    public function decidePacketDisposition(array $packet): array
    {
        $conflicts = $this->countOpen($packet['conflicts'] ?? []);
        $uncertainties = $this->countOpen($packet['uncertainties'] ?? []);
        $impact = is_array($packet['atlas_impact'] ?? null) ? array_filter($packet['atlas_impact']) : [];
        $hasImpact = $impact !== [];

        $recommended = is_string($packet['recommended_action'] ?? null) ? $packet['recommended_action'] : '';
        $qualityOk = ($packet['quality_publishable'] ?? false) === true;

        // Binding rule: conflicts/uncertainties must be preserved, never promoted over.
        if ($conflicts > 0 || $uncertainties > 0) {
            $disposition = 'research_more';
            $reason = $conflicts > 0
                ? 'unresolved_conflicts_preserved'
                : 'open_uncertainty_preserved';
        } elseif (! $hasImpact && $recommended === 'archive') {
            $disposition = 'archive';
            $reason = 'no_atlas_impact_archive';
        } elseif (! $qualityOk || $recommended !== 'promote_to_doc' || ! $hasImpact) {
            // Cannot mutate Atlas from a packet that is not publishable, not
            // recommending promotion, or carries no impact.
            $disposition = 'hold';
            $reason = match (true) {
                ! $qualityOk => 'quality_not_publishable_hold',
                ! $hasImpact => 'no_atlas_impact_hold',
                default => 'recommended_action_not_promote_hold',
            };
        } else {
            $disposition = 'promote';
            $reason = 'clean_publishable_impactful_promote';
        }

        return [
            'schema' => self::RECEIPT_SCHEMA,
            'disposition' => $disposition,
            'may_change_atlas' => $disposition === 'promote',
            'preserved_uncertainty' => ($conflicts > 0 || $uncertainties > 0),
            'open_conflicts' => $conflicts,
            'open_uncertainties' => $uncertainties,
            'reason' => $reason,
        ];
    }

    /**
     * End-to-end worked run over the four contracts, returning each verdict plus
     * the terminal disposition. Used by the CLI and as a single audit surface.
     *
     * @param list<string>        $completedSteps
     * @param list<string>        $discoveryKinds
     * @param list<string>        $outputSignals
     * @param array<string,mixed> $packet
     *
     * @return array<string,mixed>
     */
    public function runPipeline(
        array $completedSteps,
        array $discoveryKinds,
        array $outputSignals,
        array $packet,
    ): array {
        $advance = $this->advancePipeline($completedSteps);
        $mix = $this->assessDiscoveryMix($discoveryKinds);
        $quality = $this->classifyOutputQuality($outputSignals);

        // Fold the quality verdict into the packet so the disposition honours it.
        $packet['quality_publishable'] = $quality['publishable'];
        $decision = $this->decidePacketDisposition($packet);

        return [
            'schema' => self::RECEIPT_SCHEMA,
            'advance' => $advance,
            'discovery_mix' => $mix,
            'output_quality' => $quality,
            'decision' => $decision,
        ];
    }

    /**
     * Validates a Research Packet against the doc's JSON contract: known
     * schema_version, required non-empty fields, and a recommended_action in
     * the closed enum. Shape only — opens no gate.
     *
     * @param array<string,mixed> $packet
     *
     * @return array{schema:string,valid:bool,missing_fields:list<string>,enum_errors:list<string>,reason:string}
     */
    public function validatePacket(array $packet): array
    {
        $schemaOk = ($packet['schema_version'] ?? null) === self::PACKET_SCHEMA;

        $required = ['schema_version', 'objective', 'question', 'recommended_action', 'created_at'];
        $missing = [];
        foreach ($required as $field) {
            $value = $packet[$field] ?? null;
            if ($value === null || $value === '' || $value === []) {
                $missing[] = $field;
            }
        }

        $enumErrors = [];
        $action = $packet['recommended_action'] ?? null;
        if (is_string($action) && $action !== '' && ! in_array($action, self::RECOMMENDED_ACTIONS, true)) {
            $enumErrors[] = 'recommended_action_out_of_enum';
        }

        $valid = $schemaOk && $missing === [] && $enumErrors === [];

        $reason = match (true) {
            ! $schemaOk => 'unknown_schema_version',
            $missing !== [] => 'missing_required_fields',
            $enumErrors !== [] => 'enum_out_of_range',
            default => 'valid',
        };

        return [
            'schema' => self::RECEIPT_SCHEMA,
            'valid' => $valid,
            'missing_fields' => $missing,
            'enum_errors' => $enumErrors,
            'reason' => $reason,
        ];
    }

    /**
     * Counts entries in a conflict/uncertainty list that are still OPEN. A bare
     * scalar count, or items lacking an explicit resolved=true flag, count as
     * open (default: unresolved, so nothing is silently buried).
     *
     * @param mixed $items
     */
    private function countOpen(mixed $items): int
    {
        if (is_int($items)) {
            return max(0, $items);
        }

        if (! is_array($items)) {
            return 0;
        }

        $open = 0;
        foreach ($items as $item) {
            if (is_array($item)) {
                if (($item['resolved'] ?? false) !== true) {
                    $open++;
                }
            } elseif ($item !== null && $item !== '') {
                $open++;
            }
        }

        return $open;
    }
}
