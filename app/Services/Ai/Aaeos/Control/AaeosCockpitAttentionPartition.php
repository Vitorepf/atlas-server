<?php

declare(strict_types=1);

namespace App\Services\Ai\Aaeos\Control;

/**
 * P2e / R81: cockpit attention partition (path-core).
 *
 * Pure policy — partitions read-only review from sovereign H1–H7 attention.
 * Technical review never mints engineering truth and never becomes a global halt.
 */
final class AaeosCockpitAttentionPartition
{
    public const SCHEMA = 'atlas.aaeos.cockpit_attention_partition.v1';

    public const KIND_SOVEREIGN = 'sovereign_attention';

    public const KIND_READ_ONLY_REVIEW = 'read_only_review';

    public const KIND_TECHNICAL_HISTORY = 'technical_history';

    public const KIND_UNKNOWN = 'unknown';

    /**
     * @param  array{
     *   attention_kind?:string,
     *   h_gate?:string|null,
     *   mints_engineering_outcome?:bool,
     *   global_halt?:bool,
     *   technical_queue_as_halt?:bool,
     *   human_accept_as_eng_pass?:bool
     * }  $signal
     * @return array<string,mixed>
     */
    public static function partition(array $signal): array
    {
        $kind = self::normalizeKind((string) ($signal['attention_kind'] ?? ''));
        $hGate = strtoupper(trim((string) ($signal['h_gate'] ?? '')));
        if ($hGate !== '' && in_array($hGate, AaeosSovereignContinuationCoordinator::H_GATES, true)) {
            $kind = self::KIND_SOVEREIGN;
        }

        $blockers = [];
        if ((bool) ($signal['mints_engineering_outcome'] ?? false)) {
            $blockers[] = 'cockpit_cannot_mint_engineering_outcome';
        }
        if ((bool) ($signal['human_accept_as_eng_pass'] ?? false)) {
            $blockers[] = 'human_accept_reject_cannot_mint_eng_pass';
        }
        if ((bool) ($signal['global_halt'] ?? false)) {
            $blockers[] = 'cockpit_global_halt_forbidden';
        }
        if ((bool) ($signal['technical_queue_as_halt'] ?? false)) {
            $blockers[] = 'technical_review_queue_not_global_halt';
        }
        if ($kind === self::KIND_UNKNOWN) {
            $blockers[] = 'attention_kind_unknown';
        }

        $readOnly = in_array($kind, [self::KIND_READ_ONLY_REVIEW, self::KIND_TECHNICAL_HISTORY], true);
        $sovereign = $kind === self::KIND_SOVEREIGN;

        return [
            'schema' => self::SCHEMA,
            'attention_kind' => $kind,
            'h_gate' => $hGate !== '' ? $hGate : null,
            'sovereign_attention' => $sovereign,
            'read_only_review' => $readOnly,
            'can_mint_engineering_outcome' => false,
            'can_global_halt' => false,
            'blocks_unrelated_work' => false,
            'fail_open_display' => $readOnly,
            'fail_closed_qualification' => true,
            'accepted' => $blockers === [],
            'blockers' => array_values(array_unique($blockers)),
        ];
    }

    public static function normalizeKind(string $kind): string
    {
        $kind = strtolower(trim($kind));

        return match ($kind) {
            'sovereign', 'sovereign_attention', 'h1', 'h2', 'h3', 'h4', 'h5', 'h6', 'h7' => self::KIND_SOVEREIGN,
            'review', 'read_only', 'read_only_review', 'surface_audit' => self::KIND_READ_ONLY_REVIEW,
            'history', 'technical_history', 'landing_verdict_history' => self::KIND_TECHNICAL_HISTORY,
            default => $kind === '' ? self::KIND_UNKNOWN : self::KIND_UNKNOWN,
        };
    }
}
