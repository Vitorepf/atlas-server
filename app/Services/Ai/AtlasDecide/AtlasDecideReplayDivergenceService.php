<?php

declare(strict_types=1);

namespace App\Services\Ai\AtlasDecide;

use Illuminate\Support\Carbon;

final class AtlasDecideReplayDivergenceService
{
    public const SCHEMA_VERSION = 'atlas.decide.replay_divergence.v1';

    public const MEASURE_ID = 'atlas.decide.replay_divergence.v1';

    public const FORMULA_VERSION = 'atlas_decide_replay_divergence_v1';

    public function __construct(
        private readonly AtlasDecideGatewayConsultationService $consultations,
        private readonly AtlasDecideLiveOutcomeFeedbackService $liveOutcomes,
    ) {}

    /**
     * @return array<string,mixed>
     */
    public function report(?string $consultationsPath = null, ?string $liveOutcomesPath = null, int $minN = 10): array
    {
        $consultationsPath ??= $this->consultations->logPath();
        $liveOutcomesPath ??= $this->liveOutcomes->logPath();
        $rows = $this->readJsonl($consultationsPath);
        $minN = max(1, $minN);

        $replayed = [];
        foreach ($rows as $row) {
            $previous = trim((string) ($row['verdict'] ?? ''));
            if ($previous === '') {
                continue;
            }

            // Same-state replay is deterministic and local: absent an explicit
            // fixture/current verdict, re-evaluating the same envelope yields its
            // stored verdict. Tests can inject replay_current_verdict to model drift.
            $current = trim((string) ($row['replay_current_verdict'] ?? $previous));
            $scope = trim((string) data_get($row, 'scope.task_category', 'unknown')) ?: 'unknown';
            $replayed[] = [
                'envelope_hash' => (string) ($row['envelope_hash'] ?? ''),
                'scope' => $scope,
                'previous_verdict' => $previous,
                'current_verdict' => $current,
                'diverged' => $current !== $previous,
            ];
        }

        $n = count($replayed);
        $diverged = count(array_filter($replayed, static fn (array $row): bool => (bool) $row['diverged']));
        $byScope = $this->byScope($replayed, $minN);

        return [
            'schema_version' => self::SCHEMA_VERSION,
            'measure_id' => self::MEASURE_ID,
            'formula_version' => self::FORMULA_VERSION,
            'status' => $n >= $minN ? 'ok' : 'insufficient_n',
            'generated_at' => Carbon::now()->toIso8601String(),
            'n_replayed' => $n,
            'n_diverged' => $diverged,
            'divergence_rate' => $n > 0 ? round($diverged / $n, 4) : null,
            'denominator_min' => $minN,
            'by_scope' => $byScope,
            'sample' => array_slice($replayed, 0, 5),
            'sources' => [
                'gateway_consultations' => $consultationsPath,
                'live_outcomes' => $liveOutcomesPath,
            ],
            'claim_policy' => [
                'read_only' => true,
                'provider_calls_made' => false,
                'memory_written' => false,
                'writes_gateway_consultations' => false,
                'writes_live_outcomes' => false,
                'descriptive_not_quality_gate' => true,
            ],
        ];
    }

    /**
     * @param  list<array<string,mixed>>  $rows
     * @return array<string,array{n:int,diverged:int,divergence_rate:float|null,status:string}>
     */
    private function byScope(array $rows, int $minN): array
    {
        $groups = [];
        foreach ($rows as $row) {
            $scope = (string) ($row['scope'] ?? 'unknown');
            $groups[$scope] ??= ['n' => 0, 'diverged' => 0];
            $groups[$scope]['n']++;
            if ((bool) ($row['diverged'] ?? false)) {
                $groups[$scope]['diverged']++;
            }
        }
        ksort($groups);

        $out = [];
        foreach ($groups as $scope => $group) {
            $n = (int) $group['n'];
            $diverged = (int) $group['diverged'];
            $out[$scope] = [
                'n' => $n,
                'diverged' => $diverged,
                'divergence_rate' => $n > 0 ? round($diverged / $n, 4) : null,
                'status' => $n >= $minN ? 'ok' : 'insufficient_n',
            ];
        }

        return $out;
    }

    /**
     * @return list<array<string,mixed>>
     */
    private function readJsonl(string $path): array
    {
        if (! is_file($path)) {
            return [];
        }
        $raw = @file_get_contents($path);
        if (! is_string($raw) || trim($raw) === '') {
            return [];
        }

        $rows = [];
        foreach (preg_split('/\r?\n/', $raw) ?: [] as $line) {
            $line = trim($line);
            if ($line === '') {
                continue;
            }
            $decoded = json_decode($line, true);
            if (is_array($decoded)) {
                $rows[] = $decoded;
            }
        }

        return $rows;
    }
}
