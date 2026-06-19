<?php

declare(strict_types=1);

namespace App\Services\Ai\AutonomousEvolution\Pattern;

use InvalidArgumentException;

/**
 * LOOP-PATTERN-REGISTRY · Slice 1 — the quarantine intake for EXTERNAL pattern material.
 *
 * The Loop Library (forwardfuture.ai), MachinaOS playbooks, and any other off-Atlas catalog are a rich
 * source of *inspiration* about how loops can be shaped — but they are NOT trusted, NOT proven on Atlas
 * cases, and absolutely NOT executable. This intake is the ONE door through which such material enters
 * the registry, and that door is a quarantine, not an install: it transforms a loose `$material` array
 * into a governed {@see AtlasLoopPatternSpec} whose provenance is external and whose status can never be
 * better than `candidate`. It NEVER fetches a URL, runs a command, installs a package, or calls a
 * provider — it is a pure, deterministic array→spec transformation.
 *
 * The load-bearing invariant (why this class exists): external material must be born un-selectable. A
 * Loop Library entry that *claims* it is production-ready cannot become {ready,default} here no matter
 * what it carries — the very best it can earn is `candidate`, and only when the operator explicitly opts
 * in AND the material already carries a real success gate + a terminal `success` state + a sandbox. The
 * default, and the floor for anything weaker, is `source_material`: inspiration in quarantine. Promotion
 * past `candidate` is the {@see AtlasLoopPatternChampionGate}'s job after a fresh Atlas eval battery —
 * never the intake's, and never a foreign author's.
 */
final class AtlasLoopPatternSourceIntake
{
    /** The generic gate stamped on material that arrives without a real, proven success proof. */
    public const UNPROVEN_GATE = 'UNPROVEN external pattern: requires a fresh Atlas eval battery to certify before it may run';

    /**
     * Ingest external pattern material into a quarantined spec WITHOUT registering it.
     *
     * @param  array<string,mixed>  $material  id, name, summary, source, rationale, captured_at + optional
     *                                          proposed pattern fields (intent, success_gates,
     *                                          terminal_states, durability_mode, sandbox_profile,
     *                                          promote_to_candidate).
     *
     * @throws InvalidArgumentException when the material has no usable id (the one thing we cannot default).
     */
    public function intake(array $material): AtlasLoopPatternSpec
    {
        return AtlasLoopPatternSpec::fromArray($this->toSpecArray($material));
    }

    /**
     * Ingest AND register into the given registry. The registry is the read-model the selector ranks
     * over; because the produced spec is source_material/candidate (never selectable), registering it
     * adds inspiration to the quarantine lanes without ever exposing it to the selector. Returns the
     * same quarantined spec {@see intake()} would.
     */
    public function intakeInto(array $material, AtlasLoopPatternRegistry $registry): AtlasLoopPatternSpec
    {
        $spec = $this->intake($material);
        $registry->register($spec);

        return $spec;
    }

    /**
     * The pure transformation: external `$material` → a fully-valid spec definition array that
     * {@see AtlasLoopPatternSpec::fromArray()} will accept. Every required spec field gets a SAFE default
     * if the material omitted it (read_only sandbox, generic unproven gate, success+blocked terminals,
     * single_cycle durability), so quarantine never fails for lack of structure — but the safe defaults
     * are deliberately inert: a read_only sandbox cannot touch the tree, and the gate text announces the
     * material is unproven.
     *
     * @param  array<string,mixed>  $material
     * @return array<string,mixed>
     *
     * @throws InvalidArgumentException
     */
    private function toSpecArray(array $material): array
    {
        $id = trim((string) ($material['id'] ?? ''));
        if ($id === '') {
            throw new InvalidArgumentException('AtlasLoopPatternSourceIntake: external material requires an id.');
        }

        $summary = trim((string) ($material['summary'] ?? ($material['description'] ?? '')));
        $source = $this->resolveSourceKind($material);
        $capturedAt = trim((string) ($material['captured_at'] ?? ''));
        $rationale = trim((string) ($material['rationale'] ?? ''));
        // The human-readable provenance label (e.g. 'Loop Library'), preserved verbatim in the snapshot.
        $sourceLabel = trim((string) ($material['source'] ?? ''));

        // Proposed pattern fields: accepted as PROPOSALS only — sanitized, never trusted, never run.
        $proposedGates = $this->cleanGates($material['success_gates'] ?? []);
        $proposedTerminals = $this->cleanTerminals($material['terminal_states'] ?? []);
        $proposedSandbox = $this->resolveSandbox($material['sandbox_profile'] ?? null);
        $proposedDurability = $this->resolveDurability($material['durability_mode'] ?? null);
        $intent = trim((string) ($material['intent'] ?? '')) !== '' ? (string) $material['intent'] : 'verification';

        // GATES: keep any real proposed gate as context, but ALWAYS prepend the unproven banner so the
        // spec can never read as "already certified". A gateless arrival is the common case — the banner
        // alone satisfies the fail-closed ≥1-gate rule without faking proof.
        $gates = array_values(array_unique(array_merge([self::UNPROVEN_GATE], $proposedGates)));

        $status = $this->resolveStatus($material, $proposedGates, $proposedTerminals, $proposedSandbox);

        return [
            'id' => $id,
            'version' => trim((string) ($material['version'] ?? '')) !== '' ? (string) $material['version'] : '0.1.0',
            'name' => trim((string) ($material['name'] ?? '')) !== '' ? (string) $material['name'] : $id,
            'description' => $summary !== '' ? $summary : 'External pattern material (quarantined inspiration; unproven on Atlas).',
            'intent' => $intent,
            'trigger_schema' => ['objective_kinds' => [$intent], 'use_when' => 'external inspiration — NOT YET PROVEN on Atlas cases'],
            'params_schema' => [],
            'output_schema' => [],
            'success_gates' => $gates,
            'terminal_states' => $proposedTerminals,
            'durability_mode' => $proposedDurability,
            // Deny-by-default frontier: read_only unless the material explicitly proposed a (validated)
            // capability set. Even a richer proposed sandbox is inert while status stays non-selectable.
            'sandbox_profile' => ['allowed' => $proposedSandbox],
            'agent_lane_policy' => ['self_approval' => false, 'verifier_independent' => true],
            'risk_level' => $this->resolveRisk($material['risk_level'] ?? null),
            'source' => $source,
            // The snapshot preserves the human-meaningful provenance verbatim: summary, source label,
            // capture date, rationale — exactly what a later reviewer needs to judge promotion.
            'source_snapshot' => [
                'source' => $sourceLabel !== '' ? $sourceLabel : 'external',
                'summary' => $summary,
                'rationale' => $rationale,
                'captured_at' => $capturedAt,
                'hash' => 'unpinned',
                'drift_notes' => 'NOT executed; quarantined as inspiration pending a fresh Atlas eval battery',
            ],
            'status' => $status,
        ];
    }

    /**
     * External provenance, always. Default is external_catalog (a Loop-Library-style catalog entry);
     * external_skill is allowed when the material self-identifies as a skill. We REFUSE to honour any
     * non-external source the material might claim — that is the whole point of the quarantine.
     *
     * @param  array<string,mixed>  $material
     */
    private function resolveSourceKind(array $material): string
    {
        $claimed = strtolower(trim((string) ($material['source_kind'] ?? '')));
        if ($claimed === AtlasLoopPatternSpec::SOURCE_EXTERNAL_SKILL) {
            return AtlasLoopPatternSpec::SOURCE_EXTERNAL_SKILL;
        }

        return AtlasLoopPatternSpec::SOURCE_EXTERNAL_CATALOG;
    }

    /**
     * The status ladder for intake, tightest-first:
     *   - DEFAULT floor: source_material (pure quarantine).
     *   - At MOST candidate, and ONLY when the operator explicitly passes promote_to_candidate=true AND
     *     the material already carries its own real success gate + terminal 'success' + a real (non-empty,
     *     non-read_only) sandbox proposal. Even then it stays un-selectable.
     *   - NEVER ready/default — there is no branch that returns those, by construction.
     *
     * @param  array<string,mixed>  $material
     * @param  list<string>         $proposedGates
     * @param  list<string>         $proposedTerminals
     * @param  list<string>         $proposedSandbox
     */
    private function resolveStatus(array $material, array $proposedGates, array $proposedTerminals, array $proposedSandbox): string
    {
        $operatorOptIn = ($material['promote_to_candidate'] ?? false) === true;

        $carriesRealGate = $proposedGates !== [];
        $carriesSuccessTerminal = in_array(AtlasLoopPatternSpec::TERMINAL_SUCCESS, $proposedTerminals, true);
        $carriesRealSandbox = $proposedSandbox !== [] && $proposedSandbox !== [AtlasLoopPatternSpec::CAP_READ_ONLY];

        if ($operatorOptIn && $carriesRealGate && $carriesSuccessTerminal && $carriesRealSandbox) {
            return AtlasLoopPatternSpec::STATUS_CANDIDATE;
        }

        return AtlasLoopPatternSpec::STATUS_SOURCE_MATERIAL;
    }

    /**
     * Sanitize a proposed success-gate list to validated, non-empty strings. Returns [] when nothing
     * usable was proposed (the common case — the unproven banner is added by the caller regardless).
     *
     * @return list<string>
     */
    private function cleanGates(mixed $gates): array
    {
        return array_values(array_filter(array_map(
            static fn ($g): string => trim((string) $g),
            is_array($gates) ? $gates : []
        ), static fn (string $g): bool => $g !== ''));
    }

    /**
     * Sanitize proposed terminal states to the known vocabulary and GUARANTEE a success terminal so the
     * fail-closed spec factory accepts the quarantined material. Unknown tokens are dropped (never
     * trusted blindly), and 'blocked' is always available as an honest non-success exit.
     *
     * @return list<string>
     */
    private function cleanTerminals(mixed $terminals): array
    {
        $clean = array_values(array_unique(array_filter(array_map(
            static fn ($s): string => trim((string) $s),
            is_array($terminals) ? $terminals : []
        ), static fn (string $s): bool => in_array($s, AtlasLoopPatternSpec::TERMINAL_STATES, true))));

        if (! in_array(AtlasLoopPatternSpec::TERMINAL_SUCCESS, $clean, true)) {
            array_unshift($clean, AtlasLoopPatternSpec::TERMINAL_SUCCESS);
        }
        if (! in_array(AtlasLoopPatternSpec::TERMINAL_BLOCKED, $clean, true)) {
            $clean[] = AtlasLoopPatternSpec::TERMINAL_BLOCKED;
        }

        return array_values($clean);
    }

    /**
     * Resolve the proposed sandbox to a validated capability allow-list. Anything not in the known
     * capability vocabulary is dropped; an absent/empty proposal falls back to the inert read_only
     * frontier (the spec's own deny-by-default also enforces this, but being explicit keeps the
     * candidate-promotion check honest about whether the material proposed REAL capabilities).
     *
     * @return list<string>
     */
    private function resolveSandbox(mixed $sandbox): array
    {
        $allowed = [];
        if (is_array($sandbox)) {
            $allowed = $sandbox['allowed'] ?? $sandbox;
        }

        $clean = array_values(array_unique(array_filter(array_map(
            static fn ($c): string => trim((string) $c),
            is_array($allowed) ? $allowed : []
        ), static fn (string $c): bool => in_array($c, AtlasLoopPatternSpec::SANDBOX_CAPABILITIES, true))));

        return $clean === [] ? [AtlasLoopPatternSpec::CAP_READ_ONLY] : $clean;
    }

    /** A proposed durability mode is honoured only if it is in-vocab; otherwise the safe single_cycle. */
    private function resolveDurability(mixed $durability): string
    {
        $value = trim((string) ($durability ?? ''));

        return in_array($value, AtlasLoopPatternSpec::DURABILITY_MODES, true)
            ? $value
            : AtlasLoopPatternSpec::DURABILITY_SINGLE_CYCLE;
    }

    /** A proposed risk level is honoured only if in-vocab; unknown/absent external material is 'high'. */
    private function resolveRisk(mixed $risk): string
    {
        $value = strtolower(trim((string) ($risk ?? '')));

        return in_array($value, AtlasLoopPatternSpec::RISK_LEVELS, true) ? $value : 'high';
    }
}
