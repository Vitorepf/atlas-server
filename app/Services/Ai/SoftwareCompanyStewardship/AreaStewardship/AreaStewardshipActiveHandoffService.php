<?php

declare(strict_types=1);

namespace App\Services\Ai\SoftwareCompanyStewardship\AreaStewardship;

use App\Services\Ai\Mission\MissionCanonicalHash;
use App\Services\Ai\SoftwareCompanyStewardship\StewardshipEvolution\StewardshipEvolutionReadModelService;
use DateTimeImmutable;
use DateTimeInterface;
use DateTimeZone;
use Illuminate\Support\Facades\File;

/**
 * AP-743 · Area Stewardship active handoff.
 *
 * Builds an operator-reviewable handoff packet after AP-732 proves that an
 * AP-730 Area Stewardship proposal has AP-731 acceptance. It does not run the
 * active loop, invoke Dev/Forge, create branches, mutate repos or auto-promote.
 */
final class AreaStewardshipActiveHandoffService
{
    public const REPORT_SCHEMA = 'atlas.area_stewardship.active_handoff.v1';

    public const PACKET_SCHEMA = 'atlas.area_stewardship.active_handoff_packet.v1';

    public const STATUS_READY = 'ready';

    public const STATUS_AWAITING_OPERATOR_ACCEPTANCE = 'awaiting_operator_acceptance';

    public const STATUS_BLOCKED = 'blocked';

    private ?string $storageRootOverride = null;

    public function __construct(
        private readonly AreaStewardshipPromotionReadinessService $readiness,
    ) {}

    public function setStorageRootForTesting(?string $dir): void
    {
        $this->storageRootOverride = $dir;
    }

    public function storageDir(): string
    {
        if ($this->storageRootOverride !== null) {
            return $this->storageRootOverride;
        }

        return function_exists('storage_path')
            ? storage_path('atlas/software_company_stewardship/area_stewardship_active_handoffs')
            : sys_get_temp_dir().'/atlas/software_company_stewardship/area_stewardship_active_handoffs';
    }

    public function packetFilePath(string $areaId): string
    {
        return $this->storageDir().DIRECTORY_SEPARATOR.$this->slug($areaId).'.jsonl';
    }

    /**
     * @param  array<string,mixed>  $input
     * @return array<string,mixed>
     */
    public function project(array $input = []): array
    {
        $areaId = $this->areaId($input);
        $recordHandoff = (bool) ($input['record_active_handoff'] ?? false);
        $readiness = is_array($input['readiness_report'] ?? null)
            ? $input['readiness_report']
            : $this->readiness->assess($input + ['area_id' => $areaId]);

        $readinessStatus = (string) ($readiness['status'] ?? 'unknown');
        $packets = [];
        $status = match ($readinessStatus) {
            AreaStewardshipPromotionReadinessService::STATUS_READY_FOR_ACTIVE_HANDOFF => self::STATUS_READY,
            AreaStewardshipPromotionReadinessService::STATUS_READY_FOR_OPERATOR_REVIEW => self::STATUS_AWAITING_OPERATOR_ACCEPTANCE,
            default => self::STATUS_BLOCKED,
        };

        if ($status === self::STATUS_READY) {
            $packet = $this->handoffPacket($areaId, $readiness);
            if ($recordHandoff) {
                $packet = $this->recordPacket($areaId, $packet);
            }
            $packets[] = $packet;
        }

        return $this->finalize([
            'schema_version' => self::REPORT_SCHEMA,
            'status' => $status,
            'ap_contract' => 'AP-743',
            'area_id' => $areaId,
            'stack' => 'Atlas Software Company Stewardship Stack',
            'layer' => 'Area Stewardship Layer',
            'source_ap_contracts' => ['AP-730', 'AP-731', 'AP-732'],
            'target_owner' => 'Atlas Area Stewardship Layer',
            'target_owner_doc' => 'docs/engineering-knowledge-base/atlas-area-stewardship-layer.md',
            'record_active_handoff_requested' => $recordHandoff,
            'readiness_status' => $readinessStatus,
            'readiness_report_hash' => (string) ($readiness['report_hash'] ?? ''),
            'blockers' => $this->reportBlockers($status, $readiness),
            'active_handoff_count' => count($packets),
            'active_handoff_packets' => $packets,
            'next_actions' => $this->nextActions($status),
            'claim_policy' => $this->claimPolicy($recordHandoff),
        ]);
    }

    /**
     * @param  array<string,mixed>  $readiness
     * @return array<string,mixed>
     */
    private function handoffPacket(string $areaId, array $readiness): array
    {
        $area = is_array($readiness['area_stewardship'] ?? null) ? $readiness['area_stewardship'] : [];
        $targetId = (string) ($readiness['target_id'] ?? $areaId);
        $targetHash = (string) ($readiness['target_hash'] ?? '');
        $readinessHash = (string) ($readiness['report_hash'] ?? MissionCanonicalHash::sha256($readiness));

        $packet = [
            'schema_version' => self::PACKET_SCHEMA,
            'handoff_packet_id' => 'ashp_'.substr(MissionCanonicalHash::sha256([$areaId, $targetId, $targetHash, $readinessHash]), 0, 22),
            'handoff_status' => 'ready_for_area_stewardship_active_mode',
            'handoff_storage_status' => 'projected',
            'area_id' => $areaId,
            'target_type' => (string) ($readiness['target_type'] ?? 'area_stewardship'),
            'target_id' => $targetId,
            'target_hash' => $targetHash,
            'source_readiness_report_hash' => $readinessHash,
            'operator_acceptance' => is_array($readiness['operator_acceptance'] ?? null) ? $readiness['operator_acceptance'] : [],
            'area_stewardship_contract' => $area,
            'active_mode_envelope' => $this->activeModeEnvelope($area),
            'required_gate_sequence' => [
                'ap730_area_stewardship_projection_ready',
                'ap731_operator_accept_receipt_present',
                'ap732_ready_for_active_handoff',
                'product_mode_cockpit_review',
                'area_focus_loop_evidence_pack_required',
                'self_directed_evolution_spec_gate_for_gaps',
                'atlas_dev_or_forge_owner_gates_for_execution',
                'evidence_and_reality_outcome_gates_for_claims',
                'operator_review_for_merge_deploy_secrets_or_destructive_actions',
            ],
            'handoff_boundary' => [
                'starts_active_loop' => false,
                'creates_branch' => false,
                'invokes_dev' => false,
                'invokes_forge' => false,
                'mutates_target_repo' => false,
                'operator_review_required_before_execution' => true,
            ],
            'blockers' => [],
            'claim_policy' => $this->packetClaimPolicy(),
        ];

        $packet['packet_hash'] = 'sha256:'.MissionCanonicalHash::sha256($this->stablePacket($packet));

        return $packet;
    }

    /**
     * @param  array<string,mixed>  $area
     * @return array<string,mixed>
     */
    private function activeModeEnvelope(array $area): array
    {
        $policy = is_array($area['dev_forge_policy'] ?? null) ? $area['dev_forge_policy'] : [];
        $inbox = is_array($area['operator_inbox'] ?? null) ? $area['operator_inbox'] : [];

        return [
            'schema_version' => 'atlas.area_stewardship.active_mode_envelope.v1',
            'stewardship_mode' => 'active',
            'activation_type' => 'handoff_packet_only',
            'area_id' => (string) ($area['area_id'] ?? StewardshipEvolutionReadModelService::DEFAULT_AREA_ID),
            'area_name' => (string) ($area['area_name'] ?? ''),
            'health_model' => is_array($area['health_model'] ?? null) ? $area['health_model'] : [],
            'roadmap_candidates' => array_values(array_filter((array) ($area['roadmap_candidates'] ?? []), 'is_array')),
            'routing_policy' => [
                'small_local_work' => (string) ($policy['small_local_work'] ?? 'atlas_dev'),
                'cross_system_or_long_horizon_work' => (string) ($policy['cross_system_or_long_horizon_work'] ?? 'forge'),
                'gap_or_spec_work' => (string) ($policy['gap_or_spec_work'] ?? 'self_directed_evolution'),
                'high_risk_or_sensitive_work' => (string) ($policy['high_risk_or_sensitive_work'] ?? 'operator_review'),
            ],
            'operator_inbox' => [
                'destination' => (string) ($inbox['destination'] ?? 'morning_inbox'),
                'auto_approval' => false,
                'decision_options' => ['accept', 'reject', 'defer', 'request_changes'],
            ],
            'budgets' => [
                'dev_capacity_units' => 2,
                'forge_capacity_units' => 1,
                'self_directed_evolution_capacity_units' => 1,
                'wip_limit' => 3,
            ],
            'safety_policy' => [
                'merge_without_operator' => false,
                'deploy_without_operator' => false,
                'secret_access' => false,
                'destructive_change' => false,
                'autoimplementation_allowed' => false,
            ],
        ];
    }

    /**
     * @param  array<string,mixed>  $readiness
     * @return list<string>
     */
    private function reportBlockers(string $status, array $readiness): array
    {
        if ($status === self::STATUS_READY) {
            return [];
        }

        $blockers = array_values(array_filter((array) ($readiness['blockers'] ?? []), 'is_string'));
        if ($status === self::STATUS_AWAITING_OPERATOR_ACCEPTANCE && $blockers === []) {
            return ['operator_accept_decision_missing'];
        }

        return $blockers === [] ? ['ap732_readiness_not_ready'] : $blockers;
    }

    /**
     * @return list<string>
     */
    private function nextActions(string $status): array
    {
        return match ($status) {
            self::STATUS_READY => [
                'Review the AP-743 active handoff packet in Product Mode/Cockpit before operating the area as active stewardship.',
                'Record the handoff only when the operator wants a durable AP-743 boundary packet.',
                'Route all future execution through Area Focus, Self-Directed Evolution, Atlas Dev, Forge and Evidence owners.',
            ],
            self::STATUS_AWAITING_OPERATOR_ACCEPTANCE => [
                'Record an AP-731 accept decision for target_type=area_stewardship before creating an active handoff packet.',
            ],
            default => [
                'Repair AP-732 readiness blockers before preparing an active Area Stewardship handoff.',
            ],
        };
    }

    /**
     * @param  array<string,mixed>  $packet
     * @return array<string,mixed>
     */
    private function recordPacket(string $areaId, array $packet): array
    {
        $path = $this->packetFilePath($areaId);
        $existing = $this->findPacket($path, (string) ($packet['handoff_packet_id'] ?? ''));
        if ($existing !== null) {
            return array_merge($existing, ['handoff_storage_status' => 'existing']);
        }

        $record = array_merge($packet, [
            'handoff_storage_status' => 'recorded',
            'recorded_at' => $this->now(),
        ]);

        $this->appendJsonl($path, $record);

        return $record;
    }

    /**
     * @return array<string,mixed>|null
     */
    private function findPacket(string $path, string $packetId): ?array
    {
        if (! is_file($path) || $packetId === '') {
            return null;
        }

        foreach (file($path, FILE_IGNORE_NEW_LINES | FILE_SKIP_EMPTY_LINES) ?: [] as $line) {
            $decoded = json_decode($line, true);
            if (is_array($decoded) && (string) ($decoded['handoff_packet_id'] ?? '') === $packetId) {
                return $decoded;
            }
        }

        return null;
    }

    /**
     * @param  array<string,mixed>  $payload
     */
    private function appendJsonl(string $path, array $payload): void
    {
        File::ensureDirectoryExists(dirname($path));

        $fp = fopen($path, 'ab');
        if ($fp === false) {
            throw new \RuntimeException("Could not open {$path} for writing.");
        }

        try {
            if (flock($fp, LOCK_EX)) {
                fwrite($fp, json_encode($payload, JSON_THROW_ON_ERROR | JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE).PHP_EOL);
                fflush($fp);
                flock($fp, LOCK_UN);
            }
        } finally {
            fclose($fp);
        }
    }

    /**
     * @return array<string,bool|string>
     */
    private function claimPolicy(bool $recordHandoff): array
    {
        return [
            'records_active_handoff_packet_when_requested' => $recordHandoff,
            'persistence' => 'jsonl_append_only',
            'read_only_over_repo' => true,
            'mutates_target_repo' => false,
            'provider_invoked' => false,
            'dev_invoked' => false,
            'forge_invoked' => false,
            'opens_branch' => false,
            'creates_executor' => false,
            'creates_runtime' => false,
            'creates_new_os' => false,
            'merges' => false,
            'deploys' => false,
            'touches_secrets' => false,
            'auto_promotion' => false,
            'operator_review_required' => true,
        ];
    }

    /**
     * @return array<string,bool|string>
     */
    private function packetClaimPolicy(): array
    {
        return [
            'handoff_packet_only' => true,
            'active_loop_started' => false,
            'provider_invoked' => false,
            'dev_invoked' => false,
            'forge_invoked' => false,
            'branch_created' => false,
            'mutates_target_repo' => false,
            'autoimplementation_allowed' => false,
            'operator_review_required' => true,
        ];
    }

    /**
     * @param  array<string,mixed>  $payload
     * @return array<string,mixed>
     */
    private function finalize(array $payload): array
    {
        $hashPayload = $payload;
        unset($hashPayload['generated_at'], $hashPayload['handoff_hash']);

        $payload['handoff_hash'] = 'sha256:'.MissionCanonicalHash::sha256($hashPayload);
        $payload['generated_at'] = $this->now();

        return $payload;
    }

    /**
     * @param  array<string,mixed>  $packet
     * @return array<string,mixed>
     */
    private function stablePacket(array $packet): array
    {
        unset($packet['packet_hash'], $packet['recorded_at'], $packet['handoff_storage_status']);
        ksort($packet);

        return $packet;
    }

    /**
     * @param  array<string,mixed>  $input
     */
    private function areaId(array $input): string
    {
        $value = trim((string) ($input['area_id'] ?? $input['area'] ?? ''));

        return $value !== '' ? $this->slug($value) : StewardshipEvolutionReadModelService::DEFAULT_AREA_ID;
    }

    private function slug(string $value): string
    {
        $slug = preg_replace('/[^a-z0-9_]+/', '_', strtolower(trim($value))) ?? '';
        $slug = trim($slug, '_');

        return $slug !== '' ? $slug : 'unknown';
    }

    private function now(): string
    {
        return (new DateTimeImmutable('now', new DateTimeZone('UTC')))->format(DateTimeInterface::ATOM);
    }
}

