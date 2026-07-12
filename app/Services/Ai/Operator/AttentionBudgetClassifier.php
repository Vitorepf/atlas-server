<?php

declare(strict_types=1);

namespace App\Services\Ai\Operator;

/**
 * MULTN15-05 — Attention Budget classifier (§2750-2754).
 *
 * Pure classifier `{interrupt_now | batch_to_digest}` derived deterministic-
 * ally from the ask signals. This class is the DECIDER — the caller (the
 * `require_confirmation` seam) is what actually re-schedules the ask; nothing
 * here creates NEW asks (charter guard: this slice can never widen the
 * operator's approval surface, only route what already exists).
 *
 * Pinned thresholds baked in the source (ELEV-03 freeze targets):
 *   - risk band `high|critical`         ⇒ interrupt_now
 *   - reversibility `false`             ⇒ interrupt_now
 *   - expiry within URGENT_EXPIRY_MINUTES ⇒ interrupt_now (§2753
 *     anti-silence — a batched ask that ages to expiry ESCALATES)
 *   - deny_rate >= HIGH_DENY_RATE_FLOOR ⇒ interrupt_now (the class refuses
 *     often — do not lump it into the digest and pretend it's low-cost)
 *   - anything else                     ⇒ batch_to_digest
 *
 * Every routing decision carries its `basis` array so the digest can render
 * WHY a specific ask was interrupted-now vs batched (transparency ELEV-25).
 */
final class AttentionBudgetClassifier
{
    public const SCHEMA_VERSION = 'atlas.operator.attention_budget.v1';

    /** §2753 pinned floors. */
    public const URGENT_EXPIRY_MINUTES = 30;

    public const HIGH_DENY_RATE_FLOOR = 0.6;

    /** @var array<int,string> */
    public const HIGH_RISK_BANDS = ['high', 'critical'];

    /** @var array<int,string> */
    public const VERDICTS = ['interrupt_now', 'batch_to_digest'];

    /**
     * @param  array<string,mixed>  $ask  {
     *   risk_band: string,
     *   reversible: bool|null,
     *   expiry_minutes: int|null (minutes until the ask expires — null ⇒
     *     unknown; unknown never escalates on its own),
     *   deny_rate: float|null (MULTN15-02 class deny_rate, 0.0-1.0; null ⇒
     *     insufficient_signal on this arm — do not fabricate),
     *   n_deny_rate: int|null (denominator for deny_rate — n<10 ⇒ arm off),
     * }
     * @return array{verdict:string,basis:list<string>,unmeasured:list<string>}
     */
    public function classify(array $ask): array
    {
        $basis = [];
        $unmeasured = [];

        $riskBand = strtolower(trim((string) ($ask['risk_band'] ?? '')));
        if ($riskBand === '') {
            $unmeasured[] = 'risk_band';
        } elseif (in_array($riskBand, self::HIGH_RISK_BANDS, true)) {
            $basis[] = 'risk_band:'.$riskBand;
        }

        $reversible = $ask['reversible'] ?? null;
        if ($reversible === null) {
            $unmeasured[] = 'reversible';
        } elseif ((bool) $reversible === false) {
            $basis[] = 'irreversible';
        }

        $expiry = $ask['expiry_minutes'] ?? null;
        if ($expiry === null) {
            $unmeasured[] = 'expiry_minutes';
        } elseif (is_numeric($expiry) && (int) $expiry <= self::URGENT_EXPIRY_MINUTES) {
            $basis[] = 'expiry_within_urgent_window';
        }

        $denyRate = $ask['deny_rate'] ?? null;
        $nDeny = $ask['n_deny_rate'] ?? null;
        $armReady = $denyRate !== null
            && is_numeric($denyRate)
            && $nDeny !== null
            && (int) $nDeny >= 10;
        if (! $armReady) {
            $unmeasured[] = 'deny_rate';
        } elseif ((float) $denyRate >= self::HIGH_DENY_RATE_FLOOR) {
            $basis[] = 'deny_rate_above_floor';
        }

        $verdict = $basis === [] ? 'batch_to_digest' : 'interrupt_now';

        return [
            'schema_version' => self::SCHEMA_VERSION,
            'verdict' => $verdict,
            'basis' => $basis,
            'unmeasured' => $unmeasured,
            'source' => [
                'read_only' => true,
                'creates_new_asks' => false,
                'promotes_ceiling' => false,
                'reroutes_only' => true,
            ],
        ];
    }

    /**
     * §2753 anti-silence rule: a batched ask that ages to within the urgent
     * expiry window ESCALATES automatically. This is the ONLY caller-driven
     * mutation this classifier owns; it does not create asks, it only routes
     * already-existing ones from batch → interrupt.
     *
     * @param  array<string,mixed>  $ask
     * @return array{verdict:string,basis:list<string>,unmeasured:list<string>,promoted:bool}
     */
    public function promoteBatchedNearExpiry(array $ask, string $currentRouting): array
    {
        if ($currentRouting !== 'batch_to_digest') {
            $decision = $this->classify($ask);

            return $decision + ['promoted' => false];
        }
        $expiry = $ask['expiry_minutes'] ?? null;
        if ($expiry !== null && is_numeric($expiry) && (int) $expiry <= self::URGENT_EXPIRY_MINUTES) {
            return [
                'schema_version' => self::SCHEMA_VERSION,
                'verdict' => 'interrupt_now',
                'basis' => ['expiry_within_urgent_window', 'anti_silence_promotion'],
                'unmeasured' => [],
                'source' => [
                    'read_only' => true,
                    'creates_new_asks' => false,
                    'promotes_ceiling' => false,
                    'reroutes_only' => true,
                ],
                'promoted' => true,
            ];
        }
        $decision = $this->classify($ask);

        return $decision + ['promoted' => false];
    }
}
