<?php

namespace App\Services\Ai\DomainRuntime;

use App\Models\AiDomainCapability;
use App\Models\AiDomainManifest;
use Illuminate\Database\Eloquent\Collection;
use Illuminate\Support\Str;

class DomainCapabilityCatalogService
{
    public const REQUIRED_FIELDS = [
        'capability_id',
        'input_schema',
        'output_schema',
        'allowed_tools',
        'required_gates',
        'evidence_required',
    ];

    public const RISK_LEVELS = ['low', 'medium', 'high', 'critical'];

    /**
     * @param  array<string,mixed>  $attributes
     */
    public function register(AiDomainManifest $manifest, array $attributes): AiDomainCapability
    {
        foreach (self::REQUIRED_FIELDS as $field) {
            if (! array_key_exists($field, $attributes) || $attributes[$field] === null || $attributes[$field] === []) {
                throw DomainRuntimeException::manifestMissingField($manifest->domain_id, "capability.{$field}");
            }
        }

        $capabilityId = (string) $attributes['capability_id'];
        if ($manifest->capabilities()->where('capability_id', $capabilityId)->exists()) {
            throw DomainRuntimeException::capabilityExists($manifest->domain_id, $capabilityId);
        }

        $riskLevel = (string) ($attributes['risk_level'] ?? 'low');
        if (! in_array($riskLevel, self::RISK_LEVELS, true)) {
            throw new \InvalidArgumentException("capability risk_level [{$riskLevel}] not allowed.");
        }

        $maturityLevel = (int) ($attributes['maturity_level'] ?? 1);
        if ($maturityLevel < 1 || $maturityLevel > 5) {
            throw DomainRuntimeException::invalidMaturityStage($maturityLevel);
        }
        if ($riskLevel === 'critical' && $maturityLevel < DomainSeedManifests::STAGE_DEPARTMENT) {
            throw new \InvalidArgumentException("critical capability [{$capabilityId}] requires maturity_level >= Department (3).");
        }

        return AiDomainCapability::query()->create([
            'uuid' => (string) Str::uuid(),
            'domain_manifest_id' => $manifest->id,
            'capability_id' => $capabilityId,
            'name' => (string) ($attributes['name'] ?? Str::headline($capabilityId)),
            'description' => $attributes['description'] ?? null,
            'input_schema' => $attributes['input_schema'],
            'output_schema' => $attributes['output_schema'],
            'allowed_tools' => $attributes['allowed_tools'],
            'risk_level' => $riskLevel,
            'required_gates' => $attributes['required_gates'],
            'evidence_required' => $attributes['evidence_required'],
            'maturity_level' => $maturityLevel,
            'status' => (string) ($attributes['status'] ?? 'available'),
        ]);
    }

    /**
     * @return Collection<int,AiDomainCapability>
     */
    public function listForDomain(AiDomainManifest $manifest): Collection
    {
        return $manifest->capabilities()->orderBy('capability_id')->get();
    }

    /**
     * @return Collection<int,AiDomainCapability>
     */
    public function listForDomainId(string $domainId): Collection
    {
        $manifest = AiDomainManifest::query()->where('domain_id', $domainId)->first();
        if (! $manifest) {
            return new Collection;
        }

        return $this->listForDomain($manifest);
    }
}
