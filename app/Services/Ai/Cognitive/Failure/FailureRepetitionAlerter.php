<?php

namespace App\Services\Ai\Cognitive\Failure;

use App\Services\Ai\Kernel\Evidence\AtlasEvidenceLedger;
use App\Services\Ai\Kernel\Evidence\LedgerEventType;
use App\Services\Ai\Kernel\Slo\KernelSloProbe;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

class FailureRepetitionAlerter
{
    public const SCHEMA_VERSION = 'atlas.cognitive.failure_repetition_alert_runtime.v1';

    public function __construct(
        private readonly AtlasEvidenceLedger $ledger,
        private readonly KernelSloProbe $slo,
    ) {}

    /**
     * @param  array<string,mixed>  $signature
     * @return array<string,mixed>
     */
    public function evaluate(array $signature, int $threshold = 3, int $windowDays = 30): array
    {
        return $this->slo->measure('cognitive.failure.alert', function () use ($signature, $threshold, $windowDays): array {
            if (! $this->tableReady()) {
                return ['schema_version' => self::SCHEMA_VERSION, 'status' => 'table_missing'];
            }

            $signatureKey = (string) ($signature['signature_key'] ?? '');
            $domain = (string) ($signature['domain'] ?? 'general');
            if ($signatureKey === '') {
                return ['schema_version' => self::SCHEMA_VERSION, 'status' => 'invalid', 'reason' => 'signature_key_required'];
            }

            $rows = DB::table('failure_signatures')
                ->where('signature_key', $signatureKey)
                ->where('recorded_at', '>=', now()->subDays(max(1, $windowDays))->toJSON())
                ->orderBy('recorded_at')
                ->get();
            $count = $rows->count();

            if ($count < $threshold) {
                return [
                    'schema_version' => self::SCHEMA_VERSION,
                    'status' => 'not_triggered',
                    'signature_key' => $signatureKey,
                    'repetition_count' => $count,
                    'threshold' => $threshold,
                ];
            }

            $severity = $this->severity($count, $rows->where('recorded_at', '>=', now()->subDays(7)->toJSON())->count());
            DB::table('failure_repetition_alerts')->updateOrInsert(
                ['signature_key' => $signatureKey, 'alert_status' => 'open'],
                [
                    'domain' => $domain,
                    'repetition_count' => $count,
                    'severity' => $severity,
                    'first_occurrence_at' => $rows->first()->recorded_at,
                    'latest_occurrence_at' => $rows->last()->recorded_at,
                    'updated_at' => now(),
                    'created_at' => now(),
                ],
            );

            $alert = DB::table('failure_repetition_alerts')
                ->where('signature_key', $signatureKey)
                ->where('alert_status', 'open')
                ->first();
            $payload = [
                'schema_version' => 'atlas.cognitive.failure_repetition_alert.v1',
                'status' => 'triggered',
                'id' => (int) ($alert->id ?? 0),
                'signature_key' => $signatureKey,
                'domain' => $domain,
                'repetition_count' => $count,
                'severity' => $severity,
                'threshold' => $threshold,
                'window_days' => $windowDays,
                'recommended_action' => 'open_learning_failure_review',
            ];

            $this->ledger->record(LedgerEventType::FailureRepetitionAlert, $payload, [
                'tenant_id' => 'default',
                'operator_id' => 'atlas_failure_tracker',
                'envelope_id' => 'failure_alert:'.$signatureKey,
                'correlation_id' => $signatureKey,
                'emitter_stage' => 'atlas.cognitive.failure_repetition',
                'emitter_version' => self::SCHEMA_VERSION,
            ]);

            return $payload;
        }, [
            'domain' => (string) ($signature['domain'] ?? 'general'),
        ]);
    }

    private function severity(int $count, int $sevenDayCount): string
    {
        if ($count >= 8 || $sevenDayCount >= 3) {
            return 'critical';
        }

        if ($count >= 5) {
            return 'high';
        }

        return 'warning';
    }

    private function tableReady(): bool
    {
        return Schema::hasTable('failure_signatures') && Schema::hasTable('failure_repetition_alerts');
    }
}
