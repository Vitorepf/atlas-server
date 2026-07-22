<?php

namespace App\Services\Ai\Cognitive\SRL;

use App\Services\Ai\Kernel\Evidence\AtlasEvidenceLedger;
use App\Services\Ai\Kernel\Evidence\LedgerEventType;
use App\Services\Ai\Support\DatabaseTableAvailability;
use Illuminate\Support\Facades\DB;

class SRLPreferenceService
{
    public const SCHEMA_VERSION = 'atlas.cognitive.srl_preferences.v1';

    public function __construct(
        private readonly AtlasEvidenceLedger $ledger,
    ) {}

    /**
     * @return array<string,mixed>
     */
    public function set(bool $enabled, ?string $domain = null): array
    {
        if (! $this->tableReady()) {
            return ['schema_version' => self::SCHEMA_VERSION, 'status' => 'table_missing'];
        }

        $scope = $domain ? 'domain' : 'global';
        $normalizedDomain = $domain ? $this->domain($domain) : null;
        $config = $this->defaultPhaseConfig();

        DB::table('srl_preferences')->updateOrInsert(
            ['scope' => $scope, 'domain' => $normalizedDomain],
            [
                'enabled' => $enabled,
                'phase_config' => json_encode($config, JSON_THROW_ON_ERROR),
                'updated_at' => now(),
                'created_at' => now(),
            ],
        );

        $preference = $this->resolve($normalizedDomain);
        $this->ledger->record(LedgerEventType::SrlOverlayToggled, [
            'schema_version' => 'atlas.cognitive.srl_overlay_toggled.v1',
            'scope' => $scope,
            'domain' => $normalizedDomain,
            'enabled' => $enabled,
            'phase_config' => $config,
        ], [
            'tenant_id' => 'default',
            'operator_id' => 'atlas_srl_cli',
            'envelope_id' => 'srl_preference:'.($normalizedDomain ?: 'global'),
            'correlation_id' => 'srl_preference:'.($normalizedDomain ?: 'global'),
            'emitter_stage' => 'atlas.cognitive.srl.preference',
            'emitter_version' => self::SCHEMA_VERSION,
        ]);

        return $preference;
    }

    /**
     * @return array<string,mixed>
     */
    public function resolve(?string $domain = null): array
    {
        if (! $this->tableReady()) {
            return ['schema_version' => self::SCHEMA_VERSION, 'status' => 'table_missing', 'enabled' => false];
        }

        $normalizedDomain = $domain ? $this->domain($domain) : null;
        $domainRow = $normalizedDomain ? DB::table('srl_preferences')->where('scope', 'domain')->where('domain', $normalizedDomain)->first() : null;
        $globalRow = DB::table('srl_preferences')->where('scope', 'global')->whereNull('domain')->first();
        $row = $domainRow ?: $globalRow;

        return [
            'schema_version' => self::SCHEMA_VERSION,
            'status' => 'resolved',
            'scope' => $domainRow ? 'domain' : 'global',
            'domain' => $normalizedDomain,
            'enabled' => (bool) ($row->enabled ?? false),
            'phase_config' => $this->jsonArray($row->phase_config ?? null) ?: $this->defaultPhaseConfig(),
        ];
    }

    /**
     * @return array<int,array<string,mixed>>
     */
    public function all(): array
    {
        if (! $this->tableReady()) {
            return [];
        }

        return DB::table('srl_preferences')
            ->orderBy('scope')
            ->orderBy('domain')
            ->get()
            ->map(fn (object $row): array => [
                'schema_version' => self::SCHEMA_VERSION,
                'scope' => (string) $row->scope,
                'domain' => $row->domain,
                'enabled' => (bool) $row->enabled,
                'phase_config' => $this->jsonArray($row->phase_config),
            ])
            ->values()
            ->all();
    }

    public function tableReady(): bool
    {
        return DatabaseTableAvailability::has('srl_preferences');
    }

    /**
     * @return array<string,int>
     */
    private function defaultPhaseConfig(): array
    {
        return [
            'forethought_seconds_max' => 30,
            'performance_interval_min' => 30,
            'reflection_seconds_max' => 60,
        ];
    }

    private function domain(string $domain): string
    {
        return str($domain)->lower()->replaceMatches('/[^a-z0-9_]+/', '_')->limit(64, '')->toString();
    }

    /**
     * @return array<mixed>
     */
    private function jsonArray(mixed $value): array
    {
        if (is_string($value)) {
            $decoded = json_decode($value, true);

            return is_array($decoded) ? $decoded : [];
        }

        return is_array($value) ? $value : [];
    }
}
