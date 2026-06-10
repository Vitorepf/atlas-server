<?php

declare(strict_types=1);

namespace App\Services\Ai\SoftwareCompanyStewardship\AreaFocusLoop;

use App\Services\Ai\Mission\MissionCanonicalHash;
use App\Services\Ai\SoftwareCompanyStewardship\ProductMode\ProductModeRuntimeResultEventService;
use InvalidArgumentException;
use Throwable;

/**
 * AP-806 — Stewardship Autonomy Envelope arming service.
 *
 * The operator configures a standing autonomy policy ONCE (arm), then turns on
 * the loop; the loop loads the armed envelope and runs without per-cycle
 * approval. This service is the arming/persistence layer — it BUILDS the
 * capability; it never decides the operator's policy and never runs the loop.
 *
 * Safety: an invalid or unsafe envelope (no operator actor, or cross-system
 * targeting main) is blocked, never armed. With nothing armed, the loop is
 * byte-identical to its prior behavior.
 */
final class StewardshipAutonomyEnvelopeService
{
    public const RECEIPT_SCHEMA = 'atlas.software_company_stewardship.loop_autonomy_envelope.v1';

    public const STATUS_ARMED = 'armed';

    public const STATUS_DISARMED = 'disarmed';

    public const STATUS_BLOCKED = 'blocked';

    private ?string $storageRootOverride = null;

    private ?ProductModeRuntimeResultEventService $productMode = null;

    public function setStorageRootForTesting(?string $dir): void
    {
        $this->storageRootOverride = $dir;
    }

    public function setProductModeForTesting(?ProductModeRuntimeResultEventService $service): void
    {
        $this->productMode = $service;
    }

    /**
     * Arm a standing autonomy envelope. Operator-invoked (CLI); never auto-armed.
     *
     * @param  array<string,mixed>  $input
     * @return array<string,mixed>
     */
    public function arm(array $input): array
    {
        $area = AreaFocusSlugNormalizer::lowerUnderscoreToken((string) ($input['area_id'] ?? $input['area'] ?? ''), 'agentic_engineering_os');
        $focus = trim((string) ($input['focus'] ?? 'dev_forge')) ?: 'dev_forge';
        $operatorActor = trim((string) ($input['operator_actor'] ?? ''));

        if ($operatorActor === '') {
            return $this->blocked($area, $focus, ['operator_actor_required'], 'An armed envelope must name the authorizing operator actor; never fabricated.');
        }

        try {
            // Safe defaults: cross-system work always targets the integration lane,
            // never main; the value object throws on the unsafe combination.
            $envelope = StewardshipAutonomyEnvelope::fromArray($input + [
                'area_id' => $area,
                'focus' => $focus,
                'merge_target' => $input['merge_target'] ?? StewardshipAutonomyEnvelope::MERGE_TARGET_INTEGRATION_LANE,
                'operator_actor' => $operatorActor,
            ]);
        } catch (InvalidArgumentException $e) {
            return $this->blocked($area, $focus, ['invalid_envelope_policy'], $e->getMessage());
        }

        // Defense in depth: even though the value object enforces it, never arm a
        // cross-system envelope that could reach main.
        if ($envelope->admitCrossSystem && ! $envelope->routesToIntegrationLane()) {
            return $this->blocked($area, $focus, ['cross_system_must_target_integration_lane'], 'Refusing to arm cross-system work that does not route to the integration lane.');
        }

        $policyHash = $envelope->policyHash();
        $envelopeId = 'env_'.substr(MissionCanonicalHash::sha256([$area, $focus, $policyHash, $operatorActor, AreaFocusUtcClock::atomNow()]), 0, 18);

        $receipt = [
            'schema_version' => self::RECEIPT_SCHEMA,
            'ap_contract' => 'AP-806',
            'status' => self::STATUS_ARMED,
            'envelope_id' => $envelopeId,
            'area_id' => $area,
            'focus' => $focus,
            'policy_hash' => $policyHash,
            'operator_actor' => $operatorActor,
            'armed_at' => AreaFocusUtcClock::atomNow(),
            'policy' => $envelope->toArray(),
            'safety' => [
                'merge_target' => $envelope->mergeTarget(),
                'never_merges_cross_system_to_main' => true,
                'forge_real_execution' => 'not_implemented_blocked',
                'promotion_to_main' => 'operator_only_outside_loop',
            ],
            'claim_policy' => [
                'operator_authored_policy' => true,
                'auto_armed' => false,
                'runs_loop' => false,
                'per_cycle_approval_required' => false,
                'cross_system_merges_to_main' => false,
            ],
        ];

        $event = $this->emitProductModeArmed($receipt);
        $receipt['product_mode_event_id'] = (string) ($event['event_id'] ?? '');
        $receipt['inbox_item_id'] = data_get($event, 'inbox.inbox_item_id');

        $this->append($area, $focus, $receipt);

        return $receipt;
    }

    /**
     * Disarm the standing envelope for an area/focus (operator-invoked).
     *
     * @param  array<string,mixed>  $input
     * @return array<string,mixed>
     */
    public function disarm(array $input): array
    {
        $area = AreaFocusSlugNormalizer::lowerUnderscoreToken((string) ($input['area_id'] ?? $input['area'] ?? ''), 'agentic_engineering_os');
        $focus = trim((string) ($input['focus'] ?? 'dev_forge')) ?: 'dev_forge';
        $operatorActor = trim((string) ($input['operator_actor'] ?? ''));
        if ($operatorActor === '') {
            return $this->blocked($area, $focus, ['operator_actor_required'], 'Disarming must name the operator actor.');
        }

        $receipt = [
            'schema_version' => self::RECEIPT_SCHEMA,
            'ap_contract' => 'AP-806',
            'status' => self::STATUS_DISARMED,
            'area_id' => $area,
            'focus' => $focus,
            'operator_actor' => $operatorActor,
            'disarmed_at' => AreaFocusUtcClock::atomNow(),
        ];
        $this->append($area, $focus, $receipt);

        return $receipt;
    }

    /**
     * The currently-armed envelope for an area/focus, or null if none is armed
     * (or the latest record disarmed it). This is what the loop loads so a
     * one-time arming applies to every cycle without per-cycle approval.
     */
    public function current(string $area, string $focus = 'dev_forge'): ?StewardshipAutonomyEnvelope
    {
        $latest = $this->latestReceipt(AreaFocusSlugNormalizer::lowerUnderscoreToken($area, 'agentic_engineering_os'), trim($focus) ?: 'dev_forge');
        if ($latest === null || (string) ($latest['status'] ?? '') !== self::STATUS_ARMED) {
            return null;
        }

        try {
            return StewardshipAutonomyEnvelope::fromArray((array) ($latest['policy'] ?? []));
        } catch (Throwable) {
            return null; // a corrupt/unsafe stored policy is treated as "no envelope"
        }
    }

    /**
     * Quality-bar breach auto-block gate entry (step 3/3). Validates input seams
     * and applies the matrix floor rule: any breach count above zero blocks 24h autonomy.
     *
     * @param  array<string,mixed>  $input
     * @return array<string,mixed>
     */
    public function qualityBarBreachAutoBlockGate(array $input = []): array
    {
        $area = AreaFocusSlugNormalizer::lowerUnderscoreToken((string) ($input['area_id'] ?? $input['area'] ?? ''), 'agentic_engineering_os');
        $focus = trim((string) ($input['focus'] ?? 'dev_forge')) ?: 'dev_forge';

        if (array_key_exists('breach_count', $input) && ! is_numeric($input['breach_count'])) {
            throw new InvalidArgumentException('breach_count must be numeric.');
        }

        if (array_key_exists('evaluated_window_days', $input) && ! is_numeric($input['evaluated_window_days'])) {
            throw new InvalidArgumentException('evaluated_window_days must be numeric.');
        }

        return QualityBarBreachAutoBlockGateContract::fromArray($input + [
            'area_id' => $area,
            'focus' => $focus,
        ])->toArray();
    }

    /**
     * @return array<string,mixed>
     */
    public function show(string $area, string $focus = 'dev_forge'): array
    {
        $area = AreaFocusSlugNormalizer::lowerUnderscoreToken($area, 'agentic_engineering_os');
        $focus = trim($focus) ?: 'dev_forge';
        $latest = $this->latestReceipt($area, $focus);
        $armed = $latest !== null && (string) ($latest['status'] ?? '') === self::STATUS_ARMED;

        return [
            'schema_version' => self::RECEIPT_SCHEMA,
            'ap_contract' => 'AP-806',
            'area_id' => $area,
            'focus' => $focus,
            'armed' => $armed,
            'current' => $armed ? $latest : null,
        ];
    }

    /**
     * @return array<string,mixed>|null
     */
    private function latestReceipt(string $area, string $focus): ?array
    {
        $latest = null;
        foreach (AreaFocusJsonlReader::rows($this->path($area)) as $decoded) {
            if ((string) ($decoded['focus'] ?? '') !== $focus) {
                continue;
            }
            $latest = $decoded;
        }

        return $latest;
    }

    /**
     * @param  array<string,mixed>  $receipt
     */
    private function append(string $area, string $focus, array $receipt): void
    {
        $path = $this->path($area);
        AreaFocusAppendOnlyJsonlRecorder::append($path, $receipt);
    }

    /**
     * @param  array<string,mixed>  $receipt
     * @return array<string,mixed>
     */
    private function emitProductModeArmed(array $receipt): array
    {
        try {
            return $this->productMode()->record([
                'area_id' => (string) $receipt['area_id'],
                'owner' => 'operator',
                'event_kind' => 'autonomy_envelope_armed',
                'envelope_id' => (string) $receipt['envelope_id'],
                'policy_hash' => (string) $receipt['policy_hash'],
                'operator_actor' => (string) $receipt['operator_actor'],
                'result' => ['status' => 'armed'],
                'summary' => 'Autonomy envelope armed: '.$receipt['focus'].' → '.data_get($receipt, 'safety.merge_target'),
            ]);
        } catch (Throwable) {
            return []; // visibility is best-effort; arming never fails on it
        }
    }

    private function productMode(): ProductModeRuntimeResultEventService
    {
        if ($this->productMode === null) {
            $this->productMode = app(ProductModeRuntimeResultEventService::class);
            if ($this->storageRootOverride !== null && method_exists($this->productMode, 'setStorageRootForTesting')) {
                $this->productMode->setStorageRootForTesting($this->storageRootOverride.'/product_mode');
            }
        }

        return $this->productMode;
    }

    /**
     * @param  list<string>  $blockers
     * @return array<string,mixed>
     */
    private function blocked(string $area, string $focus, array $blockers, string $detail): array
    {
        return [
            'schema_version' => self::RECEIPT_SCHEMA,
            'ap_contract' => 'AP-806',
            'status' => self::STATUS_BLOCKED,
            'area_id' => $area,
            'focus' => $focus,
            'blockers' => $blockers,
            'detail' => $detail,
        ];
    }

    private function path(string $area): string
    {
        return $this->storageDir().DIRECTORY_SEPARATOR.AreaFocusSlugNormalizer::lowerUnderscoreToken($area, 'agentic_engineering_os').'.jsonl';
    }

    private function storageDir(): string
    {
        if ($this->storageRootOverride !== null) {
            return $this->storageRootOverride.'/autonomy_envelopes';
        }

        return function_exists('storage_path')
            ? storage_path('atlas/software_company_stewardship/autonomy_envelopes')
            : sys_get_temp_dir().'/atlas/software_company_stewardship/autonomy_envelopes';
    }
}
