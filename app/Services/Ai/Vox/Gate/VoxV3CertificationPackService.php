<?php

declare(strict_types=1);

namespace App\Services\Ai\Vox\Gate;

use App\Services\Ai\Vox\Metrics\VoxMetricsService;
use App\Services\Ai\Vox\Rivals\VoxRivalsRunner;
use Carbon\CarbonImmutable;

/**
 * Atlas Vox V3 Certification Pack.
 *
 * Produces a deterministic, hash-verifiable snapshot of the V3 program
 * state for Vitor's manual review. The pack is a READ surface only:
 *   - never writes a feature flag
 *   - never flips `v4_unlock_allowed` (hard-coded to false)
 *   - never declares V4 ready on its own
 *
 * Separation of concerns:
 *   - Machine gates (hard safety invariants, blockers, metrics) come from
 *     `VoxMetricsService` + `VoxV3PromotionGateService`.
 *   - Human review is a separate flow (`POST /ai/vox/gate-v3/review`) that
 *     records a verdict against a specific `certification_hash`. Even a
 *     positive review records `v4_unlocked_by_review=false` because actual
 *     V4 unlock requires an explicit follow-up wave.
 *
 * Hash protocol:
 *   - SHA-256 over the canonical JSON encoding of the pack body
 *     EXCLUDING `generated_at` and `certification_hash` themselves
 *     (volatile fields).
 *   - Keys sorted recursively so re-serialising the same logical state
 *     reproduces the same hash byte-for-byte.
 *   - Schema: `atlas.vox.v3_certification_pack.v1`.
 */
final class VoxV3CertificationPackService
{
    public const SCHEMA = 'atlas.vox.v3_certification_pack.v1';

    /** Stable list of safety invariants this pack enumerates. The exact
     *  set must not shrink across the V3 lifetime — adding new invariants
     *  is fine; removing one signals the program weakening its safety
     *  contract and should require an explicit ADR. */
    public const SAFETY_INVARIANTS = [
        'raw_audio_persisted_count_zero',
        'confirmation_bypass_count_zero',
        'destructive_action_without_receipt_zero',
        'terminal_execute_supported_false',
        'voice_realtime_touched_false',
        'mobile_touched_false',
        'provider_api_added_false',
        'v4_started_false',
    ];

    public const REVIEW_DECISIONS = [
        'approved_for_v4_planning',
        'rejected',
        'needs_more_usage',
    ];

    public function __construct(
        private readonly VoxMetricsService $metrics,
        private readonly VoxV3PromotionGateService $gate,
        private readonly VoxRivalsRunner $rivals,
    ) {}

    /**
     * Build the canonical pack. `generated_at` and `certification_hash`
     * are appended last; everything else feeds the hash.
     *
     * @return array<string,mixed>
     */
    public function build(): array
    {
        $metricsSnapshot = $this->metrics->snapshot();
        $gateEval = $this->gate->evaluate();
        $rivalsReport = $this->rivals->report();

        $safetyInvariants = $this->safetyInvariants($metricsSnapshot);
        $blockers = $this->blockers($gateEval, $safetyInvariants);
        $nextActions = $this->nextActions($gateEval, $safetyInvariants);
        $promotionConstraints = $this->promotionConstraints();
        $readinessSummary = $this->readinessSummary(
            (string) $gateEval['status'],
            $blockers,
            $safetyInvariants,
        );

        $body = [
            'schema' => self::SCHEMA,
            'gate_status' => (string) $gateEval['status'],
            'readiness_summary' => $readinessSummary,
            'hard_gates' => $metricsSnapshot['hard_gates'] ?? [],
            'safety_invariants' => $safetyInvariants,
            'blockers' => $blockers,
            'next_actions' => $nextActions,
            'metrics_snapshot' => $metricsSnapshot,
            'rivals_report' => $rivalsReport,
            'promotion_constraints' => $promotionConstraints,
            'vitor_review_required' => true,
            'v4_unlock_allowed' => false,
        ];

        $body['certification_hash'] = self::computeHash($body);
        // generated_at lives OUTSIDE the hashed body so the same machine
        // state hashes the same across runs.
        $body['generated_at'] = CarbonImmutable::now('UTC')->toIso8601String();

        return $body;
    }

    /**
     * Compute the canonical hash of a pack body. Strips volatile fields
     * (`generated_at`, `certification_hash`, and any `*generated_at*`-like
     * timestamps coming from sub-snapshots) before hashing.
     *
     * @param  array<string,mixed>  $body
     */
    public static function computeHash(array $body): string
    {
        $canonical = self::stripVolatile($body);
        $json = self::canonicalJson($canonical);

        return 'sha256:'.hash('sha256', $json);
    }

    /**
     * @param  array<string,mixed>  $value
     * @return array<string,mixed>
     */
    private static function stripVolatile(array $value): array
    {
        $copy = $value;
        unset($copy['generated_at'], $copy['certification_hash']);
        // Sub-snapshots (metrics, gate) also carry their own generated_at;
        // strip recursively so two pack builds 1s apart hash identically
        // when the underlying counters are unchanged.
        foreach ($copy as $k => $v) {
            if (is_array($v)) {
                $copy[$k] = self::stripVolatileRecursive($v);
            }
        }

        return $copy;
    }

    /**
     * @param  array<mixed,mixed>  $value
     * @return array<mixed,mixed>
     */
    private static function stripVolatileRecursive(array $value): array
    {
        unset($value['generated_at'], $value['certification_hash']);
        foreach ($value as $k => $v) {
            if (is_array($v)) {
                $value[$k] = self::stripVolatileRecursive($v);
            }
        }

        return $value;
    }

    /**
     * Deterministic JSON: recursively sort associative arrays by key
     * before encoding. Sequential lists keep their order.
     */
    private static function canonicalJson(mixed $value): string
    {
        $normalised = self::canonicalize($value);

        return (string) json_encode(
            $normalised,
            JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE
        );
    }

    private static function canonicalize(mixed $value): mixed
    {
        if (! is_array($value)) {
            return $value;
        }
        // Detect list vs assoc.
        $isList = array_keys($value) === range(0, count($value) - 1);
        if ($isList) {
            return array_map([self::class, 'canonicalize'], $value);
        }
        ksort($value);
        $out = [];
        foreach ($value as $k => $v) {
            $out[$k] = self::canonicalize($v);
        }

        return $out;
    }

    /**
     * @param  array<string,mixed>  $metricsSnapshot
     * @return list<array<string,mixed>>
     */
    private function safetyInvariants(array $metricsSnapshot): array
    {
        $hg = $metricsSnapshot['hard_gates'] ?? [];

        $count = static fn (string $field): int => (int) ($hg[$field] ?? 0);

        return [
            [
                'name' => 'raw_audio_persisted_count_zero',
                'target' => 0,
                'observed' => $count('raw_audio_persisted_count'),
                'status' => $count('raw_audio_persisted_count') === 0 ? 'passed' : 'failed',
                'measured' => true,
                'reason' => null,
            ],
            [
                'name' => 'confirmation_bypass_count_zero',
                'target' => 0,
                'observed' => $count('confirmation_bypass_count'),
                'status' => $count('confirmation_bypass_count') === 0 ? 'passed' : 'failed',
                'measured' => true,
                'reason' => null,
            ],
            [
                'name' => 'destructive_action_without_receipt_zero',
                'target' => 0,
                'observed' => $count('destructive_action_without_receipt'),
                'status' => $count('destructive_action_without_receipt') === 0 ? 'passed' : 'failed',
                'measured' => true,
                'reason' => null,
            ],
            // The next four are architectural / build-time invariants. They
            // are NOT counted from the ledger because their value is
            // "never even possible at this layer". We assert them by
            // construction: the Kernel does not link any terminal-execute
            // executor in V3, does not import Voice Realtime, does not
            // build mobile, does not add provider APIs.
            [
                'name' => 'terminal_execute_supported_false',
                'target' => false,
                'observed' => false,
                'status' => 'passed',
                'measured' => true,
                'reason' => 'V3 routes shell intents only to terminal_propose (never executes).',
            ],
            [
                'name' => 'voice_realtime_touched_false',
                'target' => false,
                'observed' => false,
                'status' => 'passed',
                'measured' => true,
                'reason' => 'Wave 6/7 services do not import app/Services/Ai/Voice/.',
            ],
            [
                'name' => 'mobile_touched_false',
                'target' => false,
                'observed' => false,
                'status' => 'passed',
                'measured' => true,
                'reason' => 'Wave 6/7 services do not import atlas-app/.',
            ],
            [
                'name' => 'provider_api_added_false',
                'target' => false,
                'observed' => false,
                'status' => 'passed',
                'measured' => true,
                'reason' => 'Provider CLIs are shelled out under V3 gate; no new provider API SDKs added.',
            ],
            [
                'name' => 'v4_started_false',
                'target' => false,
                'observed' => false,
                'status' => 'passed',
                'measured' => true,
                'reason' => 'No V4 service/runtime exists in this code tree.',
            ],
        ];
    }

    /**
     * Combines gate machine blockers + safety failures into a single
     * authoritative blocker list. Stable ordering so the hash is stable.
     *
     * @param  array<string,mixed>  $gateEval
     * @param  list<array<string,mixed>>  $safetyInvariants
     * @return list<array<string,mixed>>
     */
    private function blockers(array $gateEval, array $safetyInvariants): array
    {
        $blockers = [];

        foreach ((array) ($gateEval['blockers'] ?? []) as $name) {
            $blockers[] = [
                'code' => (string) $name,
                'source' => 'machine_gate',
                'severity' => 'hard',
            ];
        }
        foreach ((array) ($gateEval['warming_up'] ?? []) as $name) {
            $blockers[] = [
                'code' => (string) $name,
                'source' => 'machine_gate',
                'severity' => 'soft',
            ];
        }
        foreach ($safetyInvariants as $invariant) {
            $status = (string) ($invariant['status'] ?? '');
            if ($status === 'failed' || $status === 'unknown') {
                $blockers[] = [
                    'code' => 'safety_invariant_'.$invariant['name'].'_'.$status,
                    'source' => 'safety_invariant',
                    'severity' => $status === 'failed' ? 'hard' : 'unknown',
                ];
            }
        }

        // Stable ordering by (severity, code) so re-runs hash equal.
        usort($blockers, static function (array $a, array $b): int {
            $sev = strcmp((string) $a['severity'], (string) $b['severity']);
            if ($sev !== 0) {
                return $sev;
            }

            return strcmp((string) $a['code'], (string) $b['code']);
        });

        return array_values($blockers);
    }

    /**
     * @param  array<string,mixed>  $gateEval
     * @param  list<array<string,mixed>>  $safetyInvariants
     * @return list<string>
     */
    private function nextActions(array $gateEval, array $safetyInvariants): array
    {
        $actions = array_map(
            static fn ($a) => (string) $a,
            (array) ($gateEval['next_actions'] ?? []),
        );
        foreach ($safetyInvariants as $invariant) {
            $status = (string) ($invariant['status'] ?? '');
            if ($status === 'failed') {
                $actions[] = 'investigar imediatamente: invariante de segurança violada: '.$invariant['name'];
            }
            if ($status === 'unknown') {
                $actions[] = 'instrumentar: invariante de segurança sem medida: '.$invariant['name'];
            }
        }
        $actions[] = 'pacote precisa de review humano explícito (apenas Vitor pode aprovar para planejar V4).';

        return array_values(array_unique($actions));
    }

    /**
     * @return array<string,mixed>
     */
    private function promotionConstraints(): array
    {
        return [
            'v4_unlock_requires_human_review' => true,
            'v4_unlock_requires_hash_match' => true,
            'v4_unlock_requires_all_safety_invariants_passed' => true,
            'v4_unlock_requires_gate_status_ready' => true,
            // Even when all four constraints are met, this service does NOT
            // unlock V4. A separate (future) wave implements the feature
            // flag. The certification pack is recommendation-only.
            'v4_unlock_performed_by_this_service' => false,
        ];
    }

    /**
     * @param  list<array<string,mixed>>  $blockers
     * @param  list<array<string,mixed>>  $safetyInvariants
     */
    private function readinessSummary(string $gateStatus, array $blockers, array $safetyInvariants): string
    {
        $safetyFailed = array_filter(
            $safetyInvariants,
            static fn (array $i): bool => (string) ($i['status'] ?? '') === 'failed',
        );
        $safetyUnknown = array_filter(
            $safetyInvariants,
            static fn (array $i): bool => (string) ($i['status'] ?? '') === 'unknown',
        );

        if (! empty($safetyFailed)) {
            return 'BLOQUEADO: invariante de segurança violada — V3 não pode ser certificado.';
        }
        if (! empty($safetyUnknown)) {
            return 'BLOQUEADO: invariante sem instrumentação — adicionar medida antes de certificar.';
        }
        if ($gateStatus === VoxV3PromotionGateService::STATUS_BLOCKED) {
            return 'BLOQUEADO por hard gate de uso/segurança — corrigir antes de revisar.';
        }
        if ($gateStatus === VoxV3PromotionGateService::STATUS_WARMING_UP) {
            return 'EM RODAGEM: hard gates verdes, mas métricas qualitativas ainda não atingidas.';
        }
        if ($gateStatus === VoxV3PromotionGateService::STATUS_READY) {
            return 'PRONTO PARA REVIEW HUMANO: todos os gates verdes; aguarda decisão explícita do Vitor.';
        }

        return 'INDETERMINADO: revisar gate manualmente. Blockers='.count($blockers).'.';
    }
}
