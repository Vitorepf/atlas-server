<?php

namespace App\Http\Controllers;

use App\Services\Ai\Kernel\Evidence\AtlasEvidenceLedger;
use App\Services\Ai\Kernel\Repair\AtlasRepairOrchestrator;
use App\Services\Ai\Kernel\Repair\RepairRequest;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

class AtlasAiRepairController extends Controller
{
    public function __invoke(Request $request, AtlasRepairOrchestrator $repair, AtlasEvidenceLedger $ledger): JsonResponse
    {
        $data = $request->validate([
            'envelope_id' => ['required', 'string', 'max:160'],
            'receipt_id' => ['nullable', 'string', 'max:160'],
            'failure_domain' => ['required_without:failure_classification', 'nullable', 'string', 'max:120'],
            'source' => ['nullable', 'string', 'max:120'],
            'signals' => ['nullable', 'array'],
            'signals.*' => ['string', 'max:400'],
            'failure_classification' => ['nullable', 'array'],
            'failure_classification.failure_domain' => ['nullable', 'string', 'max:120'],
            'failure_classification.domain' => ['nullable', 'string', 'max:120'],
            'failure_classification.source' => ['nullable', 'string', 'max:120'],
            'failure_classification.signals' => ['nullable', 'array'],
            'failure_classification.signals.*' => ['string', 'max:400'],
            'failure_classification.confidence' => ['nullable', 'numeric', 'min:0', 'max:1'],
            'failure_classification.metadata' => ['nullable', 'array'],
            'current_attempt' => ['nullable', 'integer', 'min:0'],
            'policy' => ['nullable', 'array'],
            'policy.enabled' => ['nullable', 'boolean'],
            'policy.max_attempts' => ['nullable', 'integer', 'min:0'],
            'policy.allowed_strategies' => ['nullable', 'array'],
            'policy.allowed_strategies.*' => ['string', 'max:120'],
            'policy.requires_evidence_for_heavy_repair' => ['nullable', 'boolean'],
            'policy.heavy_strategies' => ['nullable', 'array'],
            'policy.heavy_strategies.*' => ['string', 'max:120'],
            'policy.metadata' => ['nullable', 'array'],
            'evidence_refs' => ['nullable', 'array'],
            'evidence_refs.*' => ['string', 'max:400'],
            'metadata' => ['nullable', 'array'],
            'attempt' => ['nullable', 'boolean'],
        ]);

        $failureClassification = is_array($data['failure_classification'] ?? null)
            ? $data['failure_classification']
            : [
                'failure_domain' => $data['failure_domain'] ?? null,
                'source' => $data['source'] ?? 'api',
                'signals' => $data['signals'] ?? [],
            ];

        $repairRequest = RepairRequest::fromArray([
            'envelope_id' => $data['envelope_id'],
            'receipt_id' => $data['receipt_id'] ?? null,
            'failure_classification' => $failureClassification,
            'policy' => is_array($data['policy'] ?? null) ? $data['policy'] : [],
            'current_attempt' => $data['current_attempt'] ?? 0,
            'evidence_refs' => $data['evidence_refs'] ?? [],
            'dry_run' => true,
            'metadata' => array_merge(
                is_array($data['metadata'] ?? null) ? $data['metadata'] : [],
                [
                    'surface' => 'atlas_api',
                    'endpoint' => 'POST /ai/repair',
                    'contract_foundation_only' => true,
                ],
            ),
        ]);

        if ((bool) ($data['attempt'] ?? false)) {
            $result = $repair->attempt($repairRequest);
            $ledgerEvent = $ledger->recordRepairDecision($result->decision, $this->ledgerContext($repairRequest));
            $completedLedgerEvent = $ledger->recordRepairResult($result, $this->ledgerContext($repairRequest, [
                'causation_id' => data_get($result->decision->evidencePayload, 'decision_hash'),
            ]));
            $payload = [
                'schema_version' => 1,
                'status' => 'attempted_scaffold',
                'repair' => $result->toArray(),
                'evidence_ledger' => $this->ledgerEventPayload($ledgerEvent) + [
                    'completed' => $this->ledgerEventPayload($completedLedgerEvent),
                ],
                'compliance' => $repair->complianceReport(),
            ];
        } else {
            $decision = $repair->plan($repairRequest);
            $ledgerEvent = $ledger->recordRepairDecision($decision, $this->ledgerContext($repairRequest));
            $payload = [
                'schema_version' => 1,
                'status' => 'planned_scaffold',
                'request' => $repairRequest->toArray(),
                'repair' => $decision->toArray(),
                'evidence_ledger' => $this->ledgerEventPayload($ledgerEvent),
                'compliance' => $repair->complianceReport(),
            ];
        }

        return response()->json($payload);
    }

    /**
     * @return array<string,mixed>
     */
    private function ledgerContext(RepairRequest $request, array $overrides = []): array
    {
        return array_merge([
            'tenant_id' => data_get($request->metadata, 'operator.tenant_id', 'default'),
            'operator_id' => data_get($request->metadata, 'operator.operator_id', 'api'),
            'envelope_id' => $request->envelopeId,
            'receipt_id' => $request->receiptId,
            'correlation_id' => $request->envelopeId,
            'emitter_stage' => 'atlas_api.repair',
            'emitter_version' => 'atlas_api.repair.v1',
        ], $overrides);
    }

    /**
     * @return array<string,mixed>
     */
    private function ledgerEventPayload(mixed $event): array
    {
        return [
            'recorded' => $event !== null,
            'event_id' => data_get($event, 'event_id'),
            'event_type' => data_get($event, 'event_type'),
            'emitter_stage' => data_get($event, 'emitter_stage'),
            'payload_hash' => data_get($event, 'payload_hash'),
        ];
    }
}
