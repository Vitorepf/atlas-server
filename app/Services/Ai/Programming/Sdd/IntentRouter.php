<?php

namespace App\Services\Ai\Programming\Sdd;

use App\Models\AtlasOperation;
use App\Services\Ai\Programming\Governance\ProgrammingScopeMode;
use App\Services\Ai\Programming\Governance\ProgrammingWorkItemClassifier;
use App\Services\Ai\Programming\Sdd\Enums\ConfidenceClass;
use App\Services\Ai\Programming\Sdd\Pipeline\Intent;
use App\Services\Ai\Programming\Sdd\Pipeline\SddPipelineOperationEnvelope as OperationEnvelope;

/**
 * IntentRouter classifies an OperationEnvelope into an Intent record and
 * persists the operation row. Reuses the existing classifier so the SDD pipe
 * stays consistent with the Programming Governance flow.
 *
 * @see docs/engineering-knowledge-base/spec-operating-system/data-model-and-services.md (intentRouter)
 */
class IntentRouter
{
    public function __construct(
        private readonly ProgrammingWorkItemClassifier $classifier,
    ) {}

    public function route(OperationEnvelope $envelope): Intent
    {
        $classification = $this->classifier->classify(
            $envelope->rawInput,
            $envelope->hints,
        );

        $confidenceClass = $this->resolveConfidence($envelope, $classification);
        $harnessRequired = $this->resolveHarnessRequirement($classification);

        return new Intent(
            type: (string) $classification['intent_type'],
            domain: $this->resolveDomain($classification, $envelope),
            riskLevel: (string) $classification['risk_level'],
            confidenceClass: $confidenceClass,
            harnessRequired: $harnessRequired,
            signals: $this->flattenSignals($classification['signals'] ?? []),
            metadata: [
                'scope_mode' => $classification['scope_mode'],
                'classification_signals' => $classification['signals'] ?? [],
            ],
        );
    }

    public function persist(OperationEnvelope $envelope, Intent $intent): AtlasOperation
    {
        return AtlasOperation::query()->create([
            'tenant_id' => $envelope->tenantId,
            'user_id' => $envelope->userId,
            'project_id' => $envelope->projectId,
            'work_item_id' => $envelope->workItemId,
            'raw_input' => $envelope->rawInput,
            'interpreted_intent' => $intent->type.' / '.$intent->domain,
            'domain' => $intent->domain,
            'status' => 'routed',
            'risk_level' => $intent->riskLevel,
            'confidence_class' => $intent->confidenceClass->value,
            'routing_metadata_json' => array_merge(
                $intent->metadata,
                ['signals' => $intent->signals, 'envelope' => $envelope->toArray()],
            ),
        ]);
    }

    /**
     * @param  array<string,mixed>  $classification
     */
    private function resolveDomain(array $classification, OperationEnvelope $envelope): string
    {
        if (isset($envelope->context['domain']) && is_string($envelope->context['domain'])) {
            return $envelope->context['domain'];
        }

        return match ((string) $classification['intent_type']) {
            'docs' => 'documentation',
            'migration' => 'database',
            'test' => 'qa',
            'cartography' => 'cartography',
            'self_construction' => 'self-construction',
            'architecture' => 'architecture',
            default => 'programming',
        };
    }

    /**
     * @param  array<string,mixed>  $classification
     */
    private function resolveConfidence(OperationEnvelope $envelope, array $classification): ConfidenceClass
    {
        if (trim($envelope->rawInput) === '') {
            return ConfidenceClass::BlockingAmbiguity;
        }
        $structuralSignals = (array) data_get($classification, 'signals.structural_matches', []);
        $highRiskSignals = (array) data_get($classification, 'signals.high_risk_matches', []);

        if (in_array($classification['intent_type'] ?? null, ['other'], true) && $structuralSignals === []) {
            return ConfidenceClass::Hypothesis;
        }
        if ($highRiskSignals !== [] && $structuralSignals !== []) {
            return ConfidenceClass::ConfirmedFact;
        }
        if ($structuralSignals !== [] || ($classification['scope_mode'] ?? null) === ProgrammingScopeMode::Structural->value) {
            return ConfidenceClass::StrongInference;
        }

        return ConfidenceClass::ConfirmedFact;
    }

    /**
     * @param  array<string,mixed>  $classification
     */
    private function resolveHarnessRequirement(array $classification): bool
    {
        $risk = (string) ($classification['risk_level'] ?? 'medium');
        if (in_array($risk, ['high', 'critical'], true)) {
            return true;
        }

        return ($classification['scope_mode'] ?? null) === ProgrammingScopeMode::Structural->value;
    }

    /**
     * @param  array<string,mixed>  $signals
     * @return list<string>
     */
    private function flattenSignals(array $signals): array
    {
        $flat = [];
        foreach ($signals as $bucket) {
            if (! is_array($bucket)) {
                continue;
            }
            foreach ($bucket as $value) {
                if (is_string($value) && trim($value) !== '') {
                    $flat[] = $value;
                }
            }
        }

        return array_values(array_unique($flat));
    }
}
