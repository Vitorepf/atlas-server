<?php

namespace App\Services\Ai\Cognitive\SRL;

use App\Services\Ai\Kernel\Evidence\AtlasEvidenceLedger;
use App\Services\Ai\Kernel\Evidence\LedgerEventType;
use App\Services\Ai\Kernel\Slo\KernelSloProbe;
use App\Services\Ai\Support\DatabaseTableAvailability;
use Illuminate\Support\Facades\DB;

class SRLEpisodeRepository
{
    public const SCHEMA_VERSION = 'atlas.cognitive.srl_episode.v1';

    public function __construct(
        private readonly SRLForethoughtCapture $forethought,
        private readonly SRLPerformanceObserver $performance,
        private readonly SRLReflectionCapture $reflection,
        private readonly AtlasEvidenceLedger $ledger,
        private readonly KernelSloProbe $slo,
    ) {}

    /**
     * @param  array<string,mixed>  $input
     * @return array<string,mixed>
     */
    public function start(array $input): array
    {
        return $this->slo->measure('cognitive.srl.forethought', function () use ($input): array {
            if (! $this->tableReady()) {
                return ['schema_version' => self::SCHEMA_VERSION, 'status' => 'table_missing'];
            }

            $forethought = $this->forethought->capture($input);
            $envelopeId = trim((string) ($input['envelope_id'] ?? 'srl:'.str()->ulid()));
            $targetFlow = trim((string) ($input['target_flow'] ?? $input['flow'] ?? 'learning.plan'));
            $domain = trim((string) ($input['domain'] ?? str($targetFlow)->before('.')->toString() ?: 'learning'));
            $id = DB::table('srl_episodes')->insertGetId([
                'envelope_id' => $envelopeId,
                'target_flow' => $targetFlow,
                'domain' => $domain,
                'study_session_id' => $input['study_session_id'] ?? null,
                'forethought' => json_encode($forethought, JSON_THROW_ON_ERROR),
                'performance_observations' => json_encode([], JSON_THROW_ON_ERROR),
                'self_reflection' => null,
                'completion_status' => 'partial_forethought',
                'created_at' => now(),
                'updated_at' => now(),
            ]);

            $episode = $this->find($id);
            $this->ledger->record(LedgerEventType::SrlForethoughtRecorded, [
                'schema_version' => 'atlas.cognitive.srl_forethought_recorded.v1',
                'episode' => $episode,
            ], $this->ledgerContext('atlas.cognitive.srl.forethought', $envelopeId));

            return $episode ?? ['schema_version' => self::SCHEMA_VERSION, 'status' => 'not_found_after_start'];
        }, ['domain' => (string) ($input['domain'] ?? 'learning')]);
    }

    /**
     * @param  array<string,mixed>  $input
     * @return array<string,mixed>
     */
    public function observe(int $id, array $input): array
    {
        return $this->slo->measure('cognitive.srl.performance_observation', function () use ($id, $input): array {
            $episode = $this->find($id);
            if ($episode === null) {
                return ['schema_version' => self::SCHEMA_VERSION, 'status' => 'missing'];
            }

            $observations = (array) ($episode['performance_observations'] ?? []);
            $observations[] = $this->performance->observe($input);
            DB::table('srl_episodes')->where('id', $id)->update([
                'performance_observations' => json_encode($observations, JSON_THROW_ON_ERROR),
                'completion_status' => 'in_progress',
                'updated_at' => now(),
            ]);

            $updated = $this->find($id);
            $this->ledger->record(LedgerEventType::SrlPerformanceObservation, [
                'schema_version' => 'atlas.cognitive.srl_performance_observation.v1',
                'episode_id' => $id,
                'observation' => $observations[array_key_last($observations)],
            ], $this->ledgerContext('atlas.cognitive.srl.performance', (string) $episode['envelope_id']));

            return $updated ?? ['schema_version' => self::SCHEMA_VERSION, 'status' => 'missing_after_observe'];
        }, ['domain' => (string) ($input['domain'] ?? 'learning')]);
    }

    /**
     * @param  array<string,mixed>  $input
     * @return array<string,mixed>
     */
    public function reflect(int $id, array $input): array
    {
        return $this->slo->measure('cognitive.srl.episode_persist', function () use ($id, $input): array {
            $episode = $this->find($id);
            if ($episode === null) {
                return ['schema_version' => self::SCHEMA_VERSION, 'status' => 'missing'];
            }

            $reflection = $this->reflection->capture($input);
            DB::table('srl_episodes')->where('id', $id)->update([
                'self_reflection' => json_encode($reflection, JSON_THROW_ON_ERROR),
                'completion_status' => 'complete',
                'updated_at' => now(),
            ]);

            $updated = $this->find($id);
            $this->ledger->record(LedgerEventType::SrlReflectionRecorded, [
                'schema_version' => 'atlas.cognitive.srl_reflection_recorded.v1',
                'episode_id' => $id,
                'reflection' => $reflection,
            ], $this->ledgerContext('atlas.cognitive.srl.reflection', (string) $episode['envelope_id']));

            return $updated ?? ['schema_version' => self::SCHEMA_VERSION, 'status' => 'missing_after_reflect'];
        }, ['domain' => (string) ($input['domain'] ?? 'learning')]);
    }

    /**
     * @return array<string,mixed>|null
     */
    public function find(int $id): ?array
    {
        if (! $this->tableReady()) {
            return null;
        }

        $row = DB::table('srl_episodes')->where('id', $id)->first();

        return $row ? $this->normalize((array) $row) : null;
    }

    /**
     * @return array<int,array<string,mixed>>
     */
    public function history(?string $domain = null, int $days = 30): array
    {
        if (! $this->tableReady()) {
            return [];
        }

        return DB::table('srl_episodes')
            ->when($domain, fn ($query) => $query->where('domain', $domain))
            ->where('created_at', '>=', now()->subDays(max(1, $days)))
            ->orderByDesc('created_at')
            ->limit(100)
            ->get()
            ->map(fn (object $row): array => $this->normalize((array) $row))
            ->values()
            ->all();
    }

    public function tableReady(): bool
    {
        return DatabaseTableAvailability::has('srl_episodes');
    }

    /**
     * @param  array<string,mixed>  $row
     * @return array<string,mixed>
     */
    private function normalize(array $row): array
    {
        return [
            'schema_version' => self::SCHEMA_VERSION,
            'id' => (int) ($row['id'] ?? 0),
            'envelope_id' => (string) ($row['envelope_id'] ?? ''),
            'target_flow' => (string) ($row['target_flow'] ?? ''),
            'domain' => (string) ($row['domain'] ?? ''),
            'study_session_id' => $row['study_session_id'] ?? null,
            'forethought' => $this->jsonArray($row['forethought'] ?? []),
            'performance_observations' => $this->jsonArray($row['performance_observations'] ?? []),
            'self_reflection' => $this->jsonArray($row['self_reflection'] ?? []),
            'completion_status' => (string) ($row['completion_status'] ?? ''),
        ];
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

    /**
     * @return array<string,mixed>
     */
    private function ledgerContext(string $stage, string $envelopeId): array
    {
        return [
            'tenant_id' => 'default',
            'operator_id' => 'atlas_srl',
            'envelope_id' => $envelopeId,
            'correlation_id' => $envelopeId,
            'emitter_stage' => $stage,
            'emitter_version' => self::SCHEMA_VERSION,
        ];
    }
}
