<?php

namespace App\Services\Ai\DomainRuntime;

use App\Models\AiDomainManifest;
use App\Models\AiDomainRuntimeRecord;
use App\Services\Ai\Mission\MissionCanonicalHash;
use Illuminate\Support\Str;

class DomainRuntimeRecordService
{
    public const STATUS_PLANNED = 'planned';

    public const STATUS_RUNNING = 'running';

    public const STATUS_WAITING_APPROVAL = 'waiting_approval';

    public const STATUS_BLOCKED = 'blocked';

    public const STATUS_COMPLETED = 'completed';

    public const STATUS_FAILED = 'failed';

    public const ALLOWED_STATUS = [
        self::STATUS_PLANNED,
        self::STATUS_RUNNING,
        self::STATUS_WAITING_APPROVAL,
        self::STATUS_BLOCKED,
        self::STATUS_COMPLETED,
        self::STATUS_FAILED,
    ];

    /**
     * @param  array<string,mixed>  $attributes
     */
    public function open(AiDomainManifest $manifest, array $attributes = []): AiDomainRuntimeRecord
    {
        $selectedCapabilities = (array) ($attributes['selected_capabilities'] ?? []);
        $executionPlan = (array) ($attributes['execution_plan'] ?? []);

        $hashInput = [
            'domain_id' => $manifest->domain_id,
            'mission_id' => $attributes['mission_id'] ?? null,
            'work_order_id' => $attributes['work_order_id'] ?? null,
            'selected_capabilities' => $selectedCapabilities,
            'execution_plan' => $executionPlan,
        ];

        return AiDomainRuntimeRecord::query()->create([
            'uuid' => (string) Str::uuid(),
            'mission_id' => $attributes['mission_id'] ?? null,
            'work_order_id' => $attributes['work_order_id'] ?? null,
            'domain_manifest_id' => $manifest->id,
            'domain_id' => $manifest->domain_id,
            'runtime_status' => self::STATUS_PLANNED,
            'selected_capabilities' => $selectedCapabilities,
            'execution_plan' => $executionPlan,
            'evidence_refs' => $attributes['evidence_refs'] ?? null,
            'blockers' => $attributes['blockers'] ?? null,
            'receipt_hash' => MissionCanonicalHash::sha256($hashInput),
        ]);
    }

    /**
     * @param  array<string,mixed>  $context
     */
    public function transition(AiDomainRuntimeRecord $record, string $to, array $context = []): AiDomainRuntimeRecord
    {
        if (! in_array($to, self::ALLOWED_STATUS, true)) {
            throw new \InvalidArgumentException("runtime_status [{$to}] not allowed.");
        }

        $record->runtime_status = $to;
        if (isset($context['blockers'])) {
            $record->blockers = (array) $context['blockers'];
        }
        if (isset($context['evidence_refs'])) {
            $record->evidence_refs = (array) $context['evidence_refs'];
        }
        $record->save();

        return $record->refresh();
    }
}
