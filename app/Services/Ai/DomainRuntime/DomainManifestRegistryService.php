<?php

namespace App\Services\Ai\DomainRuntime;

use App\Models\AiDomainManifest;
use App\Services\Ai\Mission\MissionCanonicalHash;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;

class DomainManifestRegistryService
{
    public const ALLOWED_STATUS = ['scaffold', 'active', 'deprecated', 'blocked'];

    public const REQUIRED_FIELDS = [
        'charter',
        'ontology',
        'departments',
        'flow_profiles',
        'tools_allowed',
        'evidence_schema',
        'quality_gates',
        'handoff_rules',
        'delivery_types',
        'metrics',
        'forbidden_actions',
    ];

    public function __construct(private readonly DomainCapabilityCatalogService $capabilityCatalog) {}

    /**
     * @param  array<string,mixed>  $attributes
     */
    public function register(array $attributes): AiDomainManifest
    {
        $domainId = (string) ($attributes['domain_id'] ?? '');
        if ($domainId === '') {
            throw DomainRuntimeException::manifestMissingField('?', 'domain_id');
        }
        if (! preg_match('/^[a-z0-9][a-z0-9_]{1,79}$/', $domainId)) {
            throw new \InvalidArgumentException("domain_id [{$domainId}] must be lowercase ascii (a-z, 0-9, _).");
        }

        foreach (self::REQUIRED_FIELDS as $field) {
            if (! array_key_exists($field, $attributes) || $attributes[$field] === null || $attributes[$field] === []) {
                throw DomainRuntimeException::manifestMissingField($domainId, $field);
            }
        }

        $status = (string) ($attributes['status'] ?? 'scaffold');
        if (! in_array($status, self::ALLOWED_STATUS, true)) {
            throw new \InvalidArgumentException("manifest status [{$status}] not allowed (use one of: ".implode(',', self::ALLOWED_STATUS).')');
        }

        $maturityStage = (int) ($attributes['maturity_stage'] ?? 1);
        if ($maturityStage < 1 || $maturityStage > 5) {
            throw DomainRuntimeException::invalidMaturityStage($maturityStage);
        }

        if (AiDomainManifest::query()->where('domain_id', $domainId)->exists()) {
            throw DomainRuntimeException::duplicateDomain($domainId);
        }

        $hashInput = array_intersect_key($attributes, array_flip([
            'domain_id', 'charter', 'ontology', 'departments', 'flow_profiles',
            'tools_allowed', 'policy_profile', 'memory_scope', 'evidence_schema',
            'quality_gates', 'handoff_rules', 'delivery_types', 'metrics',
            'forbidden_actions', 'maturity_stage',
        ]));
        $manifestHash = MissionCanonicalHash::sha256($hashInput);

        $capabilities = (array) ($attributes['capabilities'] ?? []);

        return DB::transaction(function () use ($attributes, $domainId, $status, $maturityStage, $manifestHash, $capabilities): AiDomainManifest {
            $manifest = AiDomainManifest::query()->create([
                'uuid' => (string) Str::uuid(),
                'domain_id' => $domainId,
                'name' => (string) ($attributes['name'] ?? Str::headline($domainId)),
                'status' => $status,
                'charter' => $attributes['charter'],
                'ontology' => $attributes['ontology'],
                'departments' => $attributes['departments'],
                'flow_profiles' => $attributes['flow_profiles'],
                'tools_allowed' => $attributes['tools_allowed'],
                'policy_profile' => $attributes['policy_profile'] ?? null,
                'memory_scope' => $attributes['memory_scope'] ?? null,
                'evidence_schema' => $attributes['evidence_schema'],
                'quality_gates' => $attributes['quality_gates'],
                'handoff_rules' => $attributes['handoff_rules'],
                'delivery_types' => $attributes['delivery_types'],
                'metrics' => $attributes['metrics'],
                'forbidden_actions' => $attributes['forbidden_actions'],
                'maturity_stage' => $maturityStage,
                'owner' => $attributes['owner'] ?? null,
                'manifest_hash' => $manifestHash,
            ]);

            foreach ($capabilities as $capability) {
                $this->capabilityCatalog->register($manifest, $capability);
            }

            return $manifest->refresh();
        });
    }

    public function findByDomainId(string $domainId): ?AiDomainManifest
    {
        return AiDomainManifest::query()->where('domain_id', $domainId)->first();
    }

    /**
     * @return Collection<int,AiDomainManifest>
     */
    public function all(): Collection
    {
        return AiDomainManifest::query()->orderBy('domain_id')->get();
    }

    /**
     * @param  array<int,array<string,mixed>>  $manifests
     * @return array<string,mixed>
     */
    public function seedDefaults(array $manifests): array
    {
        $created = [];
        $skipped = [];

        foreach ($manifests as $attributes) {
            $domainId = (string) ($attributes['domain_id'] ?? '');
            if ($domainId === '') {
                $skipped[] = ['domain_id' => '?', 'reason' => 'missing domain_id'];

                continue;
            }
            if (AiDomainManifest::query()->where('domain_id', $domainId)->exists()) {
                $skipped[] = ['domain_id' => $domainId, 'reason' => 'already_seeded'];

                continue;
            }
            $manifest = $this->register($attributes);
            $created[] = [
                'domain_id' => $manifest->domain_id,
                'manifest_id' => $manifest->id,
                'maturity_stage' => $manifest->maturity_stage,
                'capabilities' => $manifest->capabilities()->count(),
            ];
        }

        return [
            'ok' => true,
            'summary' => [
                'created' => count($created),
                'skipped' => count($skipped),
                'total_after' => AiDomainManifest::query()->count(),
            ],
            'created' => $created,
            'skipped' => $skipped,
        ];
    }
}
