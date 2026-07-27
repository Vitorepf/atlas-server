<?php

declare(strict_types=1);

namespace App\Services\Engineering\CodeGraph;

/**
 * AP-815 · G-1 — Workspace privacy classification for the code graph.
 *
 * Assigns a PRIVACY CLASS to a workspace id and answers "is this workspace
 * sensitive?". This is the PRODUCER that feeds the G-7 sovereignty enforcement
 * ({@see CodeGraphWorkspaceAccessPolicy}): G-1 decides WHAT class a workspace is,
 * G-7 decides WHO may read a workspace of that class. The class vocabulary is the
 * exact ladder G-7 enforces, least → most restricted:
 *
 *   - 'public'    → nothing sovereign at stake; readable by anyone.
 *   - 'internal'  → internal-but-not-sovereign; readable by anyone (the default).
 *   - 'sensitive' → trusted actors only (finance/health/payment/personal material).
 *   - 'secret'    → sovereign actors only (credentials/keys/vault material).
 *   - 'cyber'     → sovereign actors only; cyber-security material never leaves the
 *                   machine (local-first sovereignty).
 *
 * Resolution order (first hit wins):
 *
 *   1. EXPLICIT MAP — config('atlas.code_graph.workspace_privacy', []) keyed by
 *      workspace id (wsId => class). An operator pinning a class here is canonical
 *      and overrides every heuristic. The mapped class is still validated against
 *      the allowed set; a typo'd class falls through to the default (never an
 *      invalid class leaking downstream to G-7).
 *   2. KEYWORD HEURISTIC on the workspace id, evaluated MOST-RESTRICTIVE FIRST so
 *      that an ambiguous id can only ever ESCALATE privacy, never relax it:
 *        - contains 'cyber' or 'security'                    → 'cyber'
 *        - else contains 'secret', 'vault' or 'keys'         → 'secret'
 *        - else contains 'finance','health','payment','personal' → 'sensitive'
 *   3. DEFAULT — config('atlas.code_graph.default_privacy_class', 'internal'),
 *      itself validated against the allowed set (unknown configured default →
 *      the hard-coded 'internal' floor).
 *
 * Determinism & fail-safety (house contract):
 *   - Pure transform. No DB, no clock, no random, no provider, no filesystem. The
 *     same workspace id + config always yields the same class (case-insensitive,
 *     whitespace-insensitive on both the id and any class string).
 *   - Never throws on malformed input. An empty id, a non-string/garbage explicit
 *     map, a non-string mapped class, or an unknown configured default all resolve
 *     to a safe, valid class — never an exception, never an invalid class.
 *   - Config is read with inline default literals so it works with no config edits.
 *
 * This is a [php] Kernel service by the runtime-language boundary: it DECIDES /
 * GOVERNS a workspace's privacy (a classification decision); it does no heavy data.
 */
class CodeGraphWorkspacePrivacy
{
    public const SCHEMA = 'atlas.code_graph.workspace_privacy.v1';

    /**
     * The complete, ordered privacy-class ladder (least → most restricted). This is
     * the single source of truth for "is this a valid class?" and MUST stay aligned
     * with {@see CodeGraphWorkspaceAccessPolicy}'s recognised classes.
     *
     * @var array<int,string>
     */
    public const CLASSES = ['public', 'internal', 'sensitive', 'secret', 'cyber'];

    /**
     * Classes considered SENSITIVE for the {@see isSensitive()} predicate: any class
     * that G-7 does NOT open to all actors. 'public' and 'internal' are the only
     * non-sensitive classes.
     *
     * @var array<int,string>
     */
    public const SENSITIVE_CLASSES = ['sensitive', 'secret', 'cyber'];

    /**
     * Hard-coded final fallback used when even the configured default is invalid.
     * 'internal' is the safe floor: not world-public, yet not falsely escalating an
     * unclassified workspace to a sovereign tier.
     */
    public const DEFAULT_CLASS = 'internal';

    /**
     * Keyword → class heuristic table, ordered MOST-RESTRICTIVE FIRST. Each entry is
     * a class with the substrings (already lower-case) that map an id to it. The
     * first class whose ANY keyword is contained in the normalized id wins, so a
     * collision (e.g. an id mentioning both 'security' and 'finance') resolves to
     * the more restrictive class — the over-claim-safe direction for privacy.
     *
     * @var array<int,array{class:string, keywords:array<int,string>}>
     */
    private const HEURISTICS = [
        ['class' => 'cyber', 'keywords' => ['cyber', 'security']],
        ['class' => 'secret', 'keywords' => ['secret', 'vault', 'keys']],
        ['class' => 'sensitive', 'keywords' => ['finance', 'health', 'payment', 'personal']],
    ];

    /**
     * Resolve the privacy class of a workspace id.
     *
     * @param  string  $workspaceId  the stable workspace id (e.g. from
     *   {@see CodeGraphWorkspaceIdentity::resolve()}). Case-insensitive and
     *   whitespace-trimmed for both the explicit-map lookup and the heuristic. An
     *   empty / whitespace-only id has nothing to classify and resolves to the
     *   default class.
     * @return string one of {@see CLASSES} — always a valid class, never empty.
     */
    public function classOf(string $workspaceId): string
    {
        $id = $this->normalize($workspaceId);

        if ($id === '') {
            return $this->defaultClass();
        }

        // 1 — explicit operator-pinned map (canonical, overrides heuristics).
        $explicit = $this->explicitClassFor($id);
        if ($explicit !== null) {
            return $explicit;
        }

        // 2 — keyword heuristic, most-restrictive first.
        $heuristic = $this->heuristicClassFor($id);
        if ($heuristic !== null) {
            return $heuristic;
        }

        // 3 — configured default (validated), else the hard-coded floor.
        return $this->defaultClass();
    }

    /**
     * Whether the workspace's class is sensitive (sensitive | secret | cyber) — i.e.
     * any class G-7 restricts beyond "readable by anyone". Convenience predicate for
     * callers that only need the coarse public-or-internal vs. needs-governance bit.
     */
    public function isSensitive(string $workspaceId): bool
    {
        return in_array($this->classOf($workspaceId), self::SENSITIVE_CLASSES, true);
    }

    /**
     * Look up an explicit class for a normalized id in the configured map.
     *
     * The map is read leniently: only string keys whose normalized form equals the
     * id and whose value is a string naming a VALID class produce a hit. A mapped
     * value that is non-string or names an unknown class is ignored here so the
     * caller falls through to the heuristic/default rather than emitting an invalid
     * class downstream to G-7.
     *
     * @return string|null a valid class, or null when the id is not validly mapped.
     */
    private function explicitClassFor(string $id): ?string
    {
        $map = config('atlas.code_graph.workspace_privacy', []);
        if (! is_array($map)) {
            return null;
        }

        foreach ($map as $key => $value) {
            if (! is_string($key)) {
                continue;
            }
            if ($this->normalize($key) !== $id) {
                continue;
            }
            if (! is_string($value)) {
                return null;
            }

            return $this->validClassOrNull($value);
        }

        return null;
    }

    /**
     * Apply the keyword heuristic to a normalized id, most-restrictive first.
     *
     * @return string|null the first matching class, or null when no keyword matches.
     */
    private function heuristicClassFor(string $id): ?string
    {
        foreach (self::HEURISTICS as $rule) {
            foreach ($rule['keywords'] as $keyword) {
                if (str_contains($id, $keyword)) {
                    return $rule['class'];
                }
            }
        }

        return null;
    }

    /**
     * The configured default class, validated against the allowed set. An absent,
     * non-string, or unknown configured default collapses to {@see DEFAULT_CLASS}.
     */
    private function defaultClass(): string
    {
        $configured = config('atlas.code_graph.default_privacy_class', self::DEFAULT_CLASS);
        if (! is_string($configured)) {
            return self::DEFAULT_CLASS;
        }

        return $this->validClassOrNull($configured) ?? self::DEFAULT_CLASS;
    }

    /**
     * Return the normalized class if it is one of {@see CLASSES}, else null.
     */
    private function validClassOrNull(string $class): ?string
    {
        $normalized = $this->normalize($class);

        return in_array($normalized, self::CLASSES, true) ? $normalized : null;
    }

    /**
     * Lower-case + trim an identifier/class for stable, case-insensitive matching.
     */
    private function normalize(string $value): string
    {
        return strtolower(trim($value));
    }
}
