<?php

declare(strict_types=1);

namespace App\Services\Ai\AutonomousEvolution\Metrics;

use DateInterval;
use DateTimeImmutable;
use DateTimeZone;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

final class AtlasLoopSupplyLaneGenuineYield
{
    private const SCHEMA = 'atlas.loop.supply_lane_genuine_yield.v1';

    private const WINDOW_DAYS = 30;

    private const SUCCESS_STATUSES = ['certified', 'closed_won', 'merged'];

    /**
     * @return array{
     *   schema:string,
     *   window_days:int,
     *   lanes:list<array{lane:string,originated:int,genuine_delivered:int,yield:float,sample_dead_targets:list<string>}>,
     *   overall:array{originated:int,genuine_delivered:int,yield:float},
     *   computed_at:string
     * }
     */
    public function measure(?DateTimeImmutable $now = null): array
    {
        $now ??= new DateTimeImmutable('now', new DateTimeZone('UTC'));
        $now = $now->setTimezone(new DateTimeZone('UTC'));
        $cutoff = $now->sub(new DateInterval('P'.self::WINDOW_DAYS.'D'));
        $cutoffStr = $cutoff->format('Y-m-d H:i:s');

        $genuineDelivered = $this->genuineDeliveryIdentifierSet($cutoffStr);
        $lanes = [];
        $overallOriginated = 0;
        $overallDelivered = 0;

        foreach ($this->originations($cutoffStr) as $origin) {
            $lane = $origin['lane'];
            $lanes[$lane] ??= [
                'lane' => $lane,
                'originated' => 0,
                'genuine_delivered' => 0,
                'yield' => 0.0,
                'sample_dead_targets' => [],
            ];

            $lanes[$lane]['originated']++;
            $overallOriginated++;

            if ($this->originMatchesDelivery($origin['identifiers'], $genuineDelivered)) {
                $lanes[$lane]['genuine_delivered']++;
                $overallDelivered++;
                continue;
            }

            if (count($lanes[$lane]['sample_dead_targets']) < 5) {
                $lanes[$lane]['sample_dead_targets'][] = $origin['sample_target'];
            }
        }

        foreach ($lanes as &$lane) {
            $lane['yield'] = $this->ratio((int) $lane['genuine_delivered'], (int) $lane['originated']);
        }
        unset($lane);

        $laneRows = array_values($lanes);
        usort($laneRows, static fn (array $a, array $b): int => ($b['originated'] <=> $a['originated']) ?: strcmp($a['lane'], $b['lane']));

        return [
            'schema' => self::SCHEMA,
            'window_days' => self::WINDOW_DAYS,
            'lanes' => $laneRows,
            'overall' => [
                'originated' => $overallOriginated,
                'genuine_delivered' => $overallDelivered,
                'yield' => $this->ratio($overallDelivered, $overallOriginated),
            ],
            'computed_at' => $now->format(DateTimeImmutable::ATOM),
        ];
    }

    /**
     * @return list<array{lane:string,identifiers:list<string>,sample_target:string}>
     */
    private function originations(string $cutoffStr): array
    {
        if (! Schema::hasTable('atlas_loop_origination_outcomes')) {
            return [];
        }

        $columns = Schema::getColumnListing('atlas_loop_origination_outcomes');
        $selected = array_values(array_intersect(['payload', 'target_path', 'proposal_id', 'shape_token', 'created_at'], $columns));
        if ($selected === []) {
            return [];
        }

        $rows = DB::table('atlas_loop_origination_outcomes')
            ->where('created_at', '>=', $cutoffStr)
            ->get($selected);

        $origins = [];
        foreach ($rows as $row) {
            $payload = $this->decodePayload($row->payload ?? null);
            $identifiers = $this->identifiersFrom($payload, [$row->target_path ?? null]);
            $sampleTarget = $this->firstNonEmpty($identifiers) ?? 'unknown';

            $origins[] = [
                'lane' => $this->laneFrom($payload),
                'identifiers' => $identifiers,
                'sample_target' => $sampleTarget,
            ];
        }

        return $origins;
    }

    /**
     * @return array<string,true>
     */
    private function genuineDeliveryIdentifierSet(string $cutoffStr): array
    {
        if (! Schema::hasTable('atlas_loop_delivery_contracts')) {
            return [];
        }

        $columns = Schema::getColumnListing('atlas_loop_delivery_contracts');
        $selected = array_values(array_intersect(['payload', 'target_path', 'status', 'outcome', 'created_at'], $columns));
        if ($selected === []) {
            return [];
        }

        $rows = DB::table('atlas_loop_delivery_contracts')
            ->where('created_at', '>=', $cutoffStr)
            ->get($selected);

        $identifiers = [];
        foreach ($rows as $row) {
            $payload = $this->decodePayload($row->payload ?? null);
            if (! $this->isSuccessfulDelivery($row, $payload) || $this->isRefactorOnly($payload)) {
                continue;
            }

            foreach ($this->identifiersFrom($payload, [$row->target_path ?? null]) as $identifier) {
                $identifiers[$identifier] = true;
            }
        }

        return $identifiers;
    }

    /**
     * @param  list<string>  $originIdentifiers
     * @param  array<string,true>  $deliveryIdentifiers
     */
    private function originMatchesDelivery(array $originIdentifiers, array $deliveryIdentifiers): bool
    {
        foreach ($originIdentifiers as $identifier) {
            if (isset($deliveryIdentifiers[$identifier])) {
                return true;
            }
        }

        return false;
    }

    private function isSuccessfulDelivery(object $row, array $payload): bool
    {
        $status = $this->normaliseScalar($row->status ?? ($payload['status'] ?? null));
        if ($status !== '' && in_array($status, self::SUCCESS_STATUSES, true)) {
            return true;
        }

        return $this->normaliseScalar($row->outcome ?? ($payload['outcome'] ?? null)) === 'delivered';
    }

    private function isRefactorOnly(array $payload): bool
    {
        return $this->payloadFlagTrue($payload, 'refactor_only')
            || $this->payloadFlagTrue($payload, 'behavior_preserved');
    }

    private function laneFrom(array $payload): string
    {
        foreach (['supply_lane', 'lane', 'source'] as $key) {
            $lane = $this->normaliseScalar($payload[$key] ?? null);
            if ($lane !== '') {
                return $lane;
            }
        }

        return 'unknown';
    }

    /**
     * @param  list<mixed>  $extra
     * @return list<string>
     */
    private function identifiersFrom(array $payload, array $extra = []): array
    {
        $values = [];
        foreach (['target', 'target_path', 'target_symbol', 'identifier', 'path'] as $key) {
            $values[] = $payload[$key] ?? null;
        }
        array_push($values, ...$this->nestedIdentifierValues($payload), ...$extra);

        return array_values(array_unique(array_filter(
            array_map(fn (mixed $value): string => $this->normaliseScalar($value), $values),
            static fn (string $value): bool => $value !== '',
        )));
    }

    private function decodePayload(mixed $payload): array
    {
        if (is_array($payload)) {
            return $payload;
        }
        if (! is_string($payload) || trim($payload) === '') {
            return [];
        }

        $decoded = json_decode($payload, true);

        return is_array($decoded) ? $decoded : [];
    }

    /**
     * @return list<mixed>
     */
    private function nestedIdentifierValues(array $payload): array
    {
        $out = [];
        foreach ($payload as $key => $value) {
            if (is_array($value)) {
                array_push($out, ...$this->nestedIdentifierValues($value));
                continue;
            }
            if (is_scalar($value) && in_array((string) $key, ['target', 'target_path', 'target_symbol', 'identifier', 'path'], true)) {
                $out[] = $value;
            }
        }

        return $out;
    }

    private function payloadFlagTrue(array $payload, string $needle): bool
    {
        foreach ($payload as $key => $value) {
            if ((string) $key === $needle && $this->truthy($value)) {
                return true;
            }
            if (is_array($value) && $this->payloadFlagTrue($value, $needle)) {
                return true;
            }
        }

        return false;
    }

    private function truthy(mixed $value): bool
    {
        if (is_bool($value)) {
            return $value;
        }
        if (is_numeric($value)) {
            return (int) $value === 1;
        }

        return in_array(strtolower(trim((string) $value)), ['1', 'true', 'yes', 'on'], true);
    }

    private function normaliseScalar(mixed $value): string
    {
        if (! is_scalar($value)) {
            return '';
        }

        return trim((string) $value);
    }

    /**
     * @param  list<string>  $values
     */
    private function firstNonEmpty(array $values): ?string
    {
        foreach ($values as $value) {
            if ($value !== '') {
                return $value;
            }
        }

        return null;
    }

    private function ratio(int $delivered, int $originated): float
    {
        return $originated <= 0 ? 0.0 : round($delivered / $originated, 3);
    }
}
