<?php

namespace App\Services\Ai\OperatorIntelligence;

use App\Models\OperatorLearningSignal;

class OperatorSignalCaptureService
{
    public function __construct(
        private readonly OperatorLearningClassifier $classifier,
        private readonly OperatorLearningCandidateService $candidates,
    ) {}

    /**
     * @param  array<string,mixed>  $input
     * @return array<string,mixed>
     */
    public function capture(array $input): array
    {
        $classified = $this->classifier->classify($input);
        $dryRun = (bool) ($input['dry_run'] ?? false);

        if ($dryRun) {
            return [
                'ok' => true,
                'dry_run' => true,
                'signal' => $classified,
                'candidate' => $this->candidates->preview($classified),
            ];
        }

        $signal = OperatorLearningSignal::query()->create($classified);
        $candidate = ($input['create_candidate'] ?? true)
            ? $this->candidates->createFromSignal($signal, $input)
            : null;
        $automation = $candidate ? $this->candidates->autoApplyIfAllowed($candidate) : null;
        $candidate = $candidate?->refresh();

        return [
            'ok' => true,
            'dry_run' => false,
            'signal' => $this->signalPayload($signal),
            'candidate' => $candidate ? $this->candidates->payload($candidate) : null,
            'automation' => $automation,
        ];
    }

    /**
     * @return array<string,mixed>
     */
    private function signalPayload(OperatorLearningSignal $signal): array
    {
        return [
            'id' => $signal->id,
            'operator_id' => $signal->operator_id,
            'taxonomy_item_id' => $signal->taxonomy_item_id,
            'signal_kind' => $signal->signal_kind,
            'source_type' => $signal->source_type,
            'normalized_claim' => $signal->normalized_claim,
            'privacy_class' => $signal->privacy_class,
            'risk_level' => $signal->risk_level,
            'confidence' => $signal->confidence,
            'scope_type' => $signal->scope_type,
            'scope_id' => $signal->scope_id,
            'created_at' => $signal->created_at?->toIso8601String(),
        ];
    }
}
