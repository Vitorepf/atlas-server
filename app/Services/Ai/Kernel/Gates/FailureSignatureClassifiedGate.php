<?php

namespace App\Services\Ai\Kernel\Gates;

use App\Models\AtlasLedgerEvent;
use App\Services\Ai\Cognitive\Failure\FailureSignatureClassifier;
use App\Services\Ai\Kernel\Slo\KernelSloProbe;

class FailureSignatureClassifiedGate
{
    private const CATEGORIES = ['technical', 'decision', 'communication', 'attention', 'knowledge_gap', 'process', 'safety'];

    public function __construct(
        private readonly FailureSignatureClassifier $classifier,
        private readonly KernelSloProbe $slo,
    ) {}

    /**
     * @param  AtlasLedgerEvent|array<string,mixed>|string  $failure
     * @return array<string,mixed>
     */
    public function evaluate(AtlasLedgerEvent|array|string $failure): array
    {
        return $this->slo->measure('cognitive.failure.gate', function () use ($failure): array {
            $signature = $this->classifier->classify($failure);
            $category = (string) ($signature['category'] ?? '');

            if (! in_array($category, self::CATEGORIES, true)) {
                return [
                    'schema_version' => 'atlas.gate.failure_signature_classified.v1',
                    'gate' => 'failure_signature_classified',
                    'status' => 'blocked',
                    'reason' => 'failure_signature_invalid_category',
                    'signature' => $signature,
                ];
            }

            return [
                'schema_version' => 'atlas.gate.failure_signature_classified.v1',
                'gate' => 'failure_signature_classified',
                'status' => 'passed',
                'reason' => 'failure_signature_classified',
                'signature' => $signature,
            ];
        }, ['domain' => 'cognitive']);
    }
}
