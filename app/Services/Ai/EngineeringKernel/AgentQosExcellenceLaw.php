<?php

declare(strict_types=1);

namespace App\Services\Ai\EngineeringKernel;

/**
 * P2g-QOS / R106: multi-loop excellence as path law (server-resolved depth).
 *
 * Pure policy — no I/O. Raise-only depth; C_ARCH refuse mutate; timeout never
 * promotes; no productive vanity dial. Map:
 * docs/engineering-knowledge-base/atlas-agent-qos-excellence-ceiling.md
 */
final class AgentQosExcellenceLaw
{
    public const SCHEMA = 'atlas.agent_qos.excellence_law.v1';

    public const DEPTH_BASELINE = 'baseline';

    public const DEPTH_ELEVATED = 'elevated';

    public const DEPTH_MAX = 'max';

    public const CLASS_ARCH = 'C_ARCH';

    public const CLASS_IMPL = 'C_IMPL';

    public const CLASS_HARD = 'C_HARD';

    public const CLASS_UNKNOWN = 'C_UNKNOWN';

    /** @var list<string> */
    public const DEPTHS = [self::DEPTH_BASELINE, self::DEPTH_ELEVATED, self::DEPTH_MAX];

    /**
     * Server-resolve excellence depth (raise-only). Caller may request higher;
     * never lower than server floor from risk/difficulty/class.
     *
     * @param  array{
     *   risk_class?:string,
     *   difficulty_level?:int,
     *   request_class?:string,
     *   mandate_excellence_depth?:string|null,
     *   caller_requested_depth?:string|null
     * }  $context
     */
    public static function resolveDepth(array $context): string
    {
        $server = self::DEPTH_BASELINE;
        $risk = strtoupper(trim((string) ($context['risk_class'] ?? 'R0')));
        $difficulty = (int) ($context['difficulty_level'] ?? 1);
        $class = self::normalizeClass((string) ($context['request_class'] ?? self::CLASS_IMPL));

        if (in_array($risk, ['R4', 'R5'], true) || $difficulty >= 4) {
            $server = self::DEPTH_ELEVATED;
        }
        if ($risk === 'R5' || $difficulty >= 5
            || in_array($class, [self::CLASS_ARCH, self::CLASS_HARD], true)
            || self::normalizeDepth((string) ($context['mandate_excellence_depth'] ?? '')) === self::DEPTH_MAX) {
            $server = self::DEPTH_MAX;
        }

        $caller = self::normalizeDepth((string) ($context['caller_requested_depth'] ?? ''));
        if ($caller === null) {
            return $server;
        }

        // Raise-only: max(server, caller) by rank.
        return self::rank($caller) >= self::rank($server) ? $caller : $server;
    }

    /**
     * Fail-closed checks for architecture / timeout / vanity dial.
     *
     * @param  array<string,mixed>  $context
     * @return list<string> blockers (empty = pass)
     */
    public static function blockers(array $context): array
    {
        $blockers = [];
        $class = self::normalizeClass((string) ($context['request_class'] ?? self::CLASS_IMPL));
        $depth = self::resolveDepth($context);
        $mutate = (bool) ($context['mutate'] ?? false);
        $candidates = (int) ($context['architecture_candidates_count'] ?? 0);

        if ($class === self::CLASS_ARCH && $mutate) {
            $blockers[] = 'architecture_mutate_refused';
        }

        if ($class === self::CLASS_ARCH && $depth === self::DEPTH_MAX && $candidates < 3) {
            $blockers[] = 'architecture_under_sampled';
        }

        if ($class === self::CLASS_ARCH && $depth === self::DEPTH_ELEVATED && $candidates < 2) {
            $blockers[] = 'architecture_under_sampled';
        }

        if ((bool) ($context['timeout_exhausted'] ?? false) && (bool) ($context['promote_requested'] ?? false)) {
            $blockers[] = 'timeout_never_promotes';
        }

        if ((bool) ($context['budget_exhausted'] ?? false) && (bool) ($context['promote_requested'] ?? false)) {
            $blockers[] = 'timeout_never_promotes';
        }

        // Productive vanity dial: any option that alone tries to force promote via quality_ceiling.
        if (array_key_exists('quality_ceiling', $context)
            && ($context['quality_ceiling_changes_promote_alone'] ?? true) === true) {
            $blockers[] = 'vanity_quality_ceiling_dial_forbidden';
        }

        // review:deep / surface audit must not mint eng pass.
        if ((bool) ($context['review_deep_as_eng_gate'] ?? false)) {
            $blockers[] = 'review_deep_non_gating';
        }

        return array_values(array_unique($blockers));
    }

    /**
     * @param  array<string,mixed>  $context
     * @return array<string,mixed>
     */
    public static function evaluate(array $context): array
    {
        $depth = self::resolveDepth($context);
        $blockers = self::blockers($context);

        return [
            'schema' => self::SCHEMA,
            'excellence_depth' => $depth,
            'request_class' => self::normalizeClass((string) ($context['request_class'] ?? self::CLASS_IMPL)),
            'accepted' => $blockers === [],
            'blockers' => $blockers,
            'r104_residual_open_honesty' => (bool) ($context['r104_transport_open'] ?? true),
            'raise_only' => true,
            'vanity_dial_forbidden' => true,
        ];
    }

    public static function normalizeClass(string $class): string
    {
        $class = strtoupper(trim($class));
        if (in_array($class, [self::CLASS_ARCH, self::CLASS_IMPL, self::CLASS_HARD], true)) {
            return $class;
        }

        return self::CLASS_UNKNOWN === $class ? self::CLASS_UNKNOWN : (
            str_contains(strtolower($class), 'arch') ? self::CLASS_ARCH : self::CLASS_IMPL
        );
    }

    public static function normalizeDepth(string $depth): ?string
    {
        $depth = strtolower(trim($depth));
        if (in_array($depth, self::DEPTHS, true)) {
            return $depth;
        }

        return null;
    }

    private static function rank(string $depth): int
    {
        return match ($depth) {
            self::DEPTH_MAX => 3,
            self::DEPTH_ELEVATED => 2,
            default => 1,
        };
    }
}
