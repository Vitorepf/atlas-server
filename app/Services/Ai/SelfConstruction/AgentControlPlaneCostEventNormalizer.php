<?php

namespace App\Services\Ai\SelfConstruction;



use App\Services\Ai\SelfConstruction\Concerns\RecursivelyKsortsArrays;
use App\Services\Ai\SelfConstruction\Support\KsortsArraysByReference;
/**
 * Normalizes operator/synthetic cost events without reading provider billing
 * APIs or writing cost ledgers.
 */
final class AgentControlPlaneCostEventNormalizer
{
    use RecursivelyKsortsArrays { recursivelyKsort as ksortRecursive; }

    use KsortsArraysByReference;

    public const ALLOWED_CURRENCIES = ['BRL', 'EUR', 'GBP', 'USD'];

    /**
     * @param  list<array<string, mixed>>  $events
     * @return array<string, mixed>
     */
    public function normalize(array $events): array
    {
        $normalized = [];
        $violations = [];
        $seen = [];

        foreach ($events as $index => $event) {
            $idempotencyKey = $this->scalarString($event['idempotency_key'] ?? '');
            $currency = strtoupper($this->scalarString($event['currency'] ?? 'USD'));
            $amount = $this->numericAmount($event['amount_minor'] ?? null);
            $taskPacketId = $this->scalarString($event['task_packet_id'] ?? '');
            $runId = $this->scalarString($event['run_id'] ?? '');
            $agentId = $this->scalarString($event['agent_id'] ?? '');
            $source = $this->scalarString($event['source'] ?? 'manual_cost_event_writer');

            if ($idempotencyKey === '') {
                $violations[] = $this->violation('missing_idempotency_key', $index, 'Cost event requires an idempotency key.');
            } elseif (isset($seen[$idempotencyKey])) {
                $violations[] = $this->violation('duplicate_idempotency_key', $index, 'Cost event idempotency key must be unique.');
            }
            $seen[$idempotencyKey] = true;

            if ($taskPacketId === '') {
                $violations[] = $this->violation('missing_task_packet_id', $index, 'Cost event requires a task packet id.');
            }
            if ($runId === '') {
                $violations[] = $this->violation('missing_run_id', $index, 'Cost event requires a run id.');
            }
            if ($agentId === '') {
                $violations[] = $this->violation('missing_agent_id', $index, 'Cost event requires an agent id.');
            }
            if (! in_array($currency, self::ALLOWED_CURRENCIES, true)) {
                $violations[] = $this->violation('invalid_currency', $index, 'Cost event currency is not in the allowed currency set.');
            }
            if ($amount < 0) {
                $violations[] = $this->violation('negative_cost_amount', $index, 'Cost event amount must not be negative.');
            }
            if ((bool) ($event['token_spend_claimed'] ?? false)) {
                $violations[] = $this->violation('token_spend_claimed', $index, 'Certification cannot accept token spend claims.');
            }
            if ((bool) ($event['provider_billing_api_source'] ?? false)) {
                $violations[] = $this->violation('provider_billing_api_source_claimed', $index, 'Certification cannot read or depend on provider billing APIs.');
            }

            $normalized[] = [
                'idempotency_key' => $idempotencyKey,
                'task_packet_id' => $taskPacketId,
                'run_id' => $runId,
                'agent_id' => $agentId,
                'source' => $source,
                'currency' => $currency,
                'amount_minor' => $amount,
                'provider' => $this->scalarString($event['provider'] ?? 'manual'),
                'model' => $this->scalarString($event['model'] ?? 'unknown'),
                'token_spend_claimed' => false,
                'provider_billing_api_source' => false,
            ];
        }

        usort($normalized, static fn (array $a, array $b): int => [$a['task_packet_id'], $a['run_id'], $a['idempotency_key']] <=> [$b['task_packet_id'], $b['run_id'], $b['idempotency_key']]);

        return [
            'status' => $violations === [] ? 'normalized' : 'normalization_blocked',
            'event_count' => count($normalized),
            'normalized_cost_events' => $normalized,
            'violations' => $violations,
            'violation_count' => count($violations),
            'normalized_cost_events_hash' => $this->stableHash($normalized),
            'cost_events_write_allowed' => false,
            'provider_billing_api_read_allowed' => false,
            'token_spend_allowed' => false,
        ];
    }

    private function violation(string $code, int $index, string $message): array
    {
        return ['code' => $code, 'event_index' => $index, 'message' => $message];
    }

    private function numericAmount(mixed $value): int
    {
        if (is_int($value)) {
            return $value;
        }
        if (is_float($value)) {
            return (int) round($value);
        }
        if (is_string($value) && is_numeric($value)) {
            return (int) $value;
        }

        return 0;
    }

    private function scalarString(mixed $value): string
    {
        if (is_scalar($value)) {
            return trim((string) $value);
        }

        return '';
    }

    private function stableHash(array $payload): string
    {
        return hash('sha256', (string) json_encode($this->ksortRecursiveByReference($payload), JSON_THROW_ON_ERROR | JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE));
    }

}
