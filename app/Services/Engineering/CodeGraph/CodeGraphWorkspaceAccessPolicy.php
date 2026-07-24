<?php

declare(strict_types=1);

namespace App\Services\Engineering\CodeGraph;

use App\Services\Engineering\EngineeringStringListNormalizer;

/**
 * AP-815 · G-7 — Per-workspace sovereignty access policy for the code graph.
 *
 * Decides whether an ACTOR (an agent or provider identity, e.g. 'atlas-kernel',
 * 'local', 'operator', 'openai', 'anthropic') may READ a workspace's code graph
 * given that workspace's privacy class. This is the sovereignty gate the canon
 * mandates: sensitive/secret/cyber material must never leak to an untrusted
 * provider, so the policy is DEFAULT-DENY for anything it does not explicitly
 * recognise as allowed.
 *
 * Privacy-class ladder (least → most restricted):
 *
 *   - 'public'    → allow ALL actors (nothing sovereign at stake).
 *   - 'internal'  → allow ALL actors (internal-but-not-sovereign; readable).
 *   - 'sensitive' → allow only TRUSTED actors
 *                   (config('atlas.code_graph.trusted_actors', [...])
 *                    ∩ optional $opts['trusted'] when provided).
 *   - 'secret'    → allow only the SOVEREIGN (local/kernel) set
 *   - 'cyber'         (config('atlas.code_graph.sovereign_actors', [...])
 *                    ∩ optional $opts['sovereign'] when provided).
 *                   Cyber is sovereign-only because cyber-security material never
 *                   leaves the machine (local-first sovereignty).
 *
 * P1b.1: caller options may only NARROW identity sets, never append sovereign
 * (or trusted) actors beyond the configured allowlist.
 *
 * Anything else — an unknown class, an empty class, or an empty actor — is DENIED.
 * This is fail-closed by construction: a typo or malformed input can only ever
 * make the policy MORE restrictive, never accidentally open a sovereign graph.
 *
 * The sovereign set is always a subset of the trusted set in spirit: a sovereign
 * actor (kernel/local) is implicitly trusted, so it is also merged into the
 * trusted pool for the 'sensitive' tier even if an operator overrides the
 * trusted list without re-listing the kernel. This prevents the local kernel
 * from accidentally locking itself out of its own sensitive graphs.
 *
 * Pure of DB, clock and randomness. Configuration is read with inline default
 * literals so the policy works without any config changes. This is a [php] Kernel
 * service by the runtime-language boundary: it DECIDES/GOVERNS access; it does no
 * heavy data work.
 */
class CodeGraphWorkspaceAccessPolicy
{
    /**
     * Privacy classes that are readable by ANY actor — nothing sovereign at stake.
     *
     * @var array<int,string>
     */
    private const OPEN_CLASSES = ['public', 'internal'];

    /**
     * Privacy classes restricted to the sovereign (local/kernel) set only.
     *
     * @var array<int,string>
     */
    private const SOVEREIGN_CLASSES = ['secret', 'cyber'];

    /**
     * Privacy classes restricted to the trusted set.
     *
     * @var array<int,string>
     */
    private const TRUSTED_CLASSES = ['sensitive'];

    /**
     * Inline default trusted-actor allowlist (used when config is absent/invalid).
     *
     * @var array<int,string>
     */
    private const DEFAULT_TRUSTED_ACTORS = ['atlas-kernel', 'local', 'operator'];

    /**
     * Inline default sovereign-actor allowlist (used when config is absent/invalid).
     *
     * @var array<int,string>
     */
    private const DEFAULT_SOVEREIGN_ACTORS = ['atlas-kernel', 'local'];

    /**
     * Decide whether $actor may READ a workspace graph of the given $privacyClass.
     *
     * Never throws: malformed / unexpected input resolves to a deny with a
     * descriptive reason. The result is a small, stable, machine-readable shape so
     * callers (MCP tools, gates, audit) can branch and log uniformly.
     *
     * @param  string  $actor         The requesting agent/provider id (case-insensitive,
     *                                 trimmed). Empty → denied.
     * @param  string  $privacyClass  The workspace's privacy class (case-insensitive,
     *                                 trimmed). Unknown/empty → denied (fail-closed).
     * @param  array<string,mixed>  $opts  Optional overrides:
     *   - 'trusted'   array<int,string>  INTERSECTION filter for the sensitive tier.
     *   - 'sovereign' array<int,string>  INTERSECTION filter for secret/cyber tier.
     *   Both may only NARROW the configured/default sets (P1b.1) — never append
     *   new sovereign identity beyond what config already allows.
     * @return array{allowed:bool,reason:string}
     */
    public function allows(string $actor, string $privacyClass, array $opts = []): array
    {
        $actorNorm = $this->normalize($actor);
        if ($actorNorm === '') {
            return $this->deny('empty_actor');
        }

        $classNorm = $this->normalize($privacyClass);
        if ($classNorm === '') {
            return $this->deny('empty_privacy_class');
        }

        if (in_array($classNorm, self::OPEN_CLASSES, true)) {
            return $this->allow("open_class:{$classNorm}");
        }

        if (in_array($classNorm, self::SOVEREIGN_CLASSES, true)) {
            $allowed = $this->sovereignActors($opts);

            return in_array($actorNorm, $allowed, true)
                ? $this->allow("sovereign_actor:{$classNorm}")
                : $this->deny("not_sovereign:{$classNorm}");
        }

        if (in_array($classNorm, self::TRUSTED_CLASSES, true)) {
            $allowed = $this->trustedActors($opts);

            return in_array($actorNorm, $allowed, true)
                ? $this->allow("trusted_actor:{$classNorm}")
                : $this->deny("not_trusted:{$classNorm}");
        }

        // Unknown / unrecognised class → fail closed.
        return $this->deny("unknown_privacy_class:{$classNorm}");
    }

    /**
     * The effective sovereign (local/kernel) allowlist for a call.
     *
     * @param  array<string,mixed>  $opts
     * @return array<int,string>
     */
    private function sovereignActors(array $opts): array
    {
        $configured = $this->actorList(
            config('atlas.code_graph.sovereign_actors', self::DEFAULT_SOVEREIGN_ACTORS),
            self::DEFAULT_SOVEREIGN_ACTORS,
        );

        return $this->narrowActors($configured, $opts['sovereign'] ?? null);
    }

    /**
     * The effective trusted allowlist for a call.
     *
     * The sovereign set is folded in: a kernel/local actor is implicitly trusted,
     * so it can always read 'sensitive' graphs even if an operator override of the
     * trusted list forgets to re-list the kernel.
     *
     * @param  array<string,mixed>  $opts
     * @return array<int,string>
     */
    private function trustedActors(array $opts): array
    {
        $configured = $this->actorList(
            config('atlas.code_graph.trusted_actors', self::DEFAULT_TRUSTED_ACTORS),
            self::DEFAULT_TRUSTED_ACTORS,
        );

        $narrowed = $this->narrowActors($configured, $opts['trusted'] ?? null);

        return $this->mergeActors($narrowed, $this->sovereignActors($opts));
    }

    /**
     * Caller options may only narrow: intersection with configured base.
     * Absent/empty caller filter keeps the full base.
     *
     * @param  array<int,string>  $base
     * @return array<int,string>
     */
    private function narrowActors(array $base, mixed $filter): array
    {
        if ($filter === null) {
            return $base;
        }
        if (is_string($filter)) {
            $filter = [$filter];
        }
        if (! is_array($filter) || $filter === []) {
            return $base;
        }
        $allowed = $this->normalizeList($filter);
        if ($allowed === []) {
            return $base;
        }

        return array_values(array_intersect($base, $allowed));
    }

    /**
     * Coerce a config/option value to a clean list of normalized actor ids.
     *
     * Accepts an array (the expected shape) or a single string (lenient). Anything
     * else — or a value that normalizes to nothing — falls back to the inline
     * default so the policy is never left with an empty, mis-configured allowlist
     * that would silently deny the kernel itself.
     *
     * @param  mixed  $value
     * @param  array<int,string>  $fallback
     * @return array<int,string>
     */
    private function actorList(mixed $value, array $fallback): array
    {
        if (is_string($value)) {
            $value = [$value];
        }

        if (! is_array($value)) {
            return $this->normalizeList($fallback);
        }

        $normalized = $this->normalizeList($value);

        return $normalized !== [] ? $normalized : $this->normalizeList($fallback);
    }

    /**
     * Merge a base allowlist with caller-supplied extras (lenient on extras' type).
     *
     * @param  array<int,string>  $base
     * @param  mixed  $extra
     * @return array<int,string>
     */
    private function mergeActors(array $base, mixed $extra): array
    {
        if (is_string($extra)) {
            $extra = [$extra];
        }

        $extraNormalized = is_array($extra) ? $this->normalizeList($extra) : [];

        return EngineeringStringListNormalizer::uniqueNonEmptyStrings([...$base, ...$extraNormalized]);
    }

    /**
     * Normalize a list of mixed values to unique, non-empty, normalized actor ids.
     *
     * @param  array<int|string,mixed>  $values
     * @return array<int,string>
     */
    private function normalizeList(array $values): array
    {
        return EngineeringStringListNormalizer::uniqueNonEmptyStringValues($values, lowercase: true);
    }

    /**
     * Lower-case + trim an identifier for stable, case-insensitive comparison.
     */
    private function normalize(string $value): string
    {
        return strtolower(trim($value));
    }

    /**
     * @return array{allowed:bool,reason:string}
     */
    private function allow(string $reason): array
    {
        return ['allowed' => true, 'reason' => $reason];
    }

    /**
     * @return array{allowed:bool,reason:string}
     */
    private function deny(string $reason): array
    {
        return ['allowed' => false, 'reason' => $reason];
    }
}
