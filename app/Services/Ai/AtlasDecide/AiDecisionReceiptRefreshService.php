<?php

namespace App\Services\Ai\AtlasDecide;

use App\Models\AiJob;
use App\Services\Ai\AtlasDecideService;
use Carbon\CarbonImmutable;
use Illuminate\Support\Facades\DB;

class AiDecisionReceiptRefreshService
{
    public function __construct(
        private readonly AtlasDecideService $decide,
    ) {}

    public function canRefreshExpiredBeforeProviderCall(AiJob $job): bool
    {
        $receipt = $this->receiptV2($job);
        if ($receipt === []) {
            return false;
        }

        if (! is_string(data_get($receipt, 'receipt_hash')) || trim((string) data_get($receipt, 'receipt_hash')) === '') {
            return false;
        }

        if ((bool) data_get($receipt, 'dry_run', false)) {
            return false;
        }

        if ($this->providerWasCalled($job)) {
            return false;
        }

        if ($this->isTooOldToRefresh($job)) {
            return false;
        }

        return true;
    }

    public function refreshExpiredBeforeProviderCall(AiJob $job, string $reason = 'expired_before_provider_call'): ?AiJob
    {
        if (! $this->canRefreshExpiredBeforeProviderCall($job)) {
            return null;
        }

        $newReceipt = $this->decide->receiptForTrace($this->optionsForJob($job), (string) $job->provider, $job->model);
        if (! is_array(data_get($newReceipt, 'receipt_v2'))) {
            return null;
        }

        return DB::transaction(function () use ($job, $newReceipt, $reason): AiJob {
            $job = AiJob::query()->lockForUpdate()->with('trace')->findOrFail($job->id);

            if (! $this->canRefreshExpiredBeforeProviderCall($job)) {
                return $job->refresh();
            }

            $oldReceipt = $this->receiptV2($job);
            $refreshedAt = now()->toISOString();
            $refreshMetadata = array_merge(
                is_array(data_get($job->metadata, 'decision_receipt_refresh')) ? data_get($job->metadata, 'decision_receipt_refresh') : [],
                [
                    'status' => 'refreshed',
                    'reason' => $reason,
                    'refreshed_at' => $refreshedAt,
                    'old_receipt_id' => data_get($oldReceipt, 'receipt_id'),
                    'old_expires_at' => data_get($oldReceipt, 'expires_at'),
                    'new_receipt_id' => data_get($newReceipt, 'receipt_v2.receipt_id'),
                    'new_expires_at' => data_get($newReceipt, 'receipt_v2.expires_at'),
                ],
            );

            $payload = is_array($job->payload) ? $job->payload : [];
            $metadata = is_array($job->metadata) ? $job->metadata : [];
            $payload['decision_receipt'] = $newReceipt;
            $metadata['decision_receipt'] = $newReceipt;
            $metadata['decision_receipt_refresh'] = $refreshMetadata;

            $job->forceFill([
                'payload' => $payload,
                'metadata' => $metadata,
                'error_code' => null,
                'error_message' => null,
            ])->save();

            if ($job->trace) {
                $traceMetadata = is_array($job->trace->metadata) ? $job->trace->metadata : [];
                $traceMetadata['decision_receipt'] = $newReceipt;
                $traceMetadata['decision_receipt_refresh'] = $refreshMetadata;
                $job->trace->forceFill([
                    'metadata' => $traceMetadata,
                ])->save();
            }

            return $job->refresh();
        });
    }

    /**
     * @return array<string,mixed>
     */
    private function optionsForJob(AiJob $job): array
    {
        $payload = is_array($job->payload) ? $job->payload : [];
        unset($payload['decision_receipt']);

        return [
            'input_text' => (string) $job->input_text,
            'provider' => $job->provider,
            'model' => $job->model,
            'source_type' => $job->trace?->source_type,
            'workspace' => data_get($payload, 'workspace'),
            'payload' => $payload,
        ];
    }

    /**
     * @return array<string,mixed>
     */
    private function receiptV2(AiJob $job): array
    {
        $receipt = data_get($job->metadata, 'decision_receipt.receipt_v2');
        if (is_array($receipt)) {
            return $receipt;
        }

        $receipt = data_get($job->payload, 'decision_receipt.receipt_v2');

        return is_array($receipt) ? $receipt : [];
    }

    private function providerWasCalled(AiJob $job): bool
    {
        return $job->attemptHistory()
            ->whereNotIn('error_code', [
                'decision_receipt_expired',
                'decision_receipt_dry_run',
                'decision_receipt_hash_mismatch',
                'decision_receipt_invalid',
                'decision_receipt_model_mismatch',
                'decision_receipt_provider_mismatch',
            ])
            ->exists();
    }

    private function isTooOldToRefresh(AiJob $job): bool
    {
        $windowSeconds = max(60, (int) config('atlas.ai.decision_receipt_refresh_window_seconds', 21600));
        $createdAt = $job->created_at instanceof CarbonImmutable
            ? $job->created_at
            : (function () use ($job) {
                try {
                    return CarbonImmutable::parse($job->created_at ?? now());
                } catch (\Throwable) {
                    return CarbonImmutable::now();
                }
            })();

        return CarbonImmutable::now()->greaterThan($createdAt->addSeconds($windowSeconds));
    }
}
