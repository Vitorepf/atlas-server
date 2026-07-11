<?php

declare(strict_types=1);

namespace App\Services\Ai\EngineeringKernel;

use App\Models\AiEngineeringCompanyRoleRun;
use App\Models\AiRealExecutionTestRun;
use InvalidArgumentException;

final class ReadOnlyFinalCertifier
{
    public const DOMAIN = 'atlas.engineering_kernel.read_only_final_certifier.v1';

    /** @param list<AiEngineeringCompanyRoleRun> $prior @return array<string,mixed> */
    public function certify(ExecutionOrder $order, AiRealExecutionTestRun $test, array $prior): array
    {
        if (! app(ReadOnlyQualityCourt::class)->priorReceiptsValid($order, $test, $prior)) {
            throw new InvalidArgumentException('read_only_final_certifier_prior_receipts_invalid');
        }
        $payload = ['role' => 'final_certification', 'status' => 'pass', 'reason' => 'all_21_prior_receipts_verified',
            'order_hash' => $order->canonicalHash(), 'evidence_hash' => (string) $test->test_hash,
            'justification' => 'all_21_prior_receipts_verified', 'applicability_rule' => 'requires_21_prior_receipts',
            'signer_context' => self::DOMAIN];
        $payload['signature'] = $this->signature($payload);

        return $payload;
    }

    /** @param array<string,mixed> $disposition */
    public function dispositionValid(ExecutionOrder $order, AiRealExecutionTestRun $test, array $disposition): bool
    {
        $unsigned = $disposition;
        $signature = (string) ($unsigned['signature'] ?? '');
        unset($unsigned['signature']);

        return ($unsigned['role'] ?? null) === 'final_certification' && ($unsigned['order_hash'] ?? null) === $order->canonicalHash()
            && ($unsigned['evidence_hash'] ?? null) === $test->test_hash && ($unsigned['signer_context'] ?? null) === self::DOMAIN
            && hash_equals($signature, $this->signature($unsigned));
    }

    /** @param array<string,mixed> $payload */
    private function signature(array $payload): string
    {
        $key = (string) config('app.key');
        $decoded = str_starts_with($key, 'base64:') ? base64_decode(substr($key, 7), true) : $key;

        return hash_hmac('sha256', CanonicalKernelPayload::hash($payload), hash_hmac('sha256', self::DOMAIN, is_string($decoded) ? $decoded : '', true));
    }
}
