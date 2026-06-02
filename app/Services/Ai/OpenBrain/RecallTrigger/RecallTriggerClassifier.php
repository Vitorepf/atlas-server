<?php

declare(strict_types=1);

namespace App\Services\Ai\OpenBrain\RecallTrigger;

final class RecallTriggerClassifier
{
    private const ACTION_RECALL = 'recall';

    private const ACTION_CONTEXT_PACK = 'context_pack';

    private const ACTION_MAINTENANCE_STATUS = 'maintenance_status';

    private const ACTION_NONE = 'none';

    /**
     * Risk levels that force a full context-pack pull before implementing.
     *
     * @var list<string>
     */
    private const ELEVATED_RISK_LEVELS = ['high', 'secret', 'sensitive'];

    /**
     * Substrings that signal a memory-health / staleness intent in the task description.
     *
     * @var list<string>
     */
    private const MAINTENANCE_SIGNALS = ['maintenance', 'manutencao', 'stale', 'kb health', 'memory status'];

    /**
     * Classify the recall trigger for a task using ordered branches.
     *
     * @param  array{
     *     description?: string,
     *     touches_decision_keywords?: bool,
     *     touches_multi_module?: bool,
     *     is_rename_or_typo?: bool,
     *     is_meta_question?: bool,
     *     risk_level?: string
     * }  $taskSignals
     * @return array{action: string, mandatory: bool, reasons: list<string>}
     */
    public function classify(array $taskSignals): array
    {
        $description = $this->stringValue($taskSignals, 'description');
        $trimmedDescription = trim($description);

        $touchesDecisionKeywords = $this->boolValue($taskSignals, 'touches_decision_keywords');
        $touchesMultiModule = $this->boolValue($taskSignals, 'touches_multi_module');
        $isRenameOrTypo = $this->boolValue($taskSignals, 'is_rename_or_typo');
        $isMetaQuestion = $this->boolValue($taskSignals, 'is_meta_question');
        $riskLevel = strtolower(trim($this->stringValue($taskSignals, 'risk_level')));

        // Branch 1: legitimate exception wins first, even against decision keywords (contradiction case).
        if ($isRenameOrTypo || $isMetaQuestion) {
            return $this->result(self::ACTION_NONE, false, []);
        }

        // Branch 2: multi-module reach or elevated risk forces a context pack.
        $riskIsElevated = in_array($riskLevel, self::ELEVATED_RISK_LEVELS, true);
        if ($touchesMultiModule || $riskIsElevated) {
            $reasons = [];
            if ($touchesMultiModule) {
                $reasons[] = 'touches_multi_module';
            }
            if ($riskIsElevated) {
                $reasons[] = 'elevated_risk_level:'.$riskLevel;
            }

            return $this->result(self::ACTION_CONTEXT_PACK, true, $reasons);
        }

        // Branch 3: decision keywords force a mandatory recall.
        if ($touchesDecisionKeywords) {
            return $this->result(self::ACTION_RECALL, true, ['touches_decision_keywords']);
        }

        // Branch 4: memory-health / staleness intent in the description -> maintenance status.
        $maintenanceSignal = $this->matchedMaintenanceSignal($trimmedDescription);
        if ($maintenanceSignal !== null) {
            return $this->result(self::ACTION_MAINTENANCE_STATUS, false, ['maintenance_signal:'.$maintenanceSignal]);
        }

        // Branch 5: a meaningful description with no other signal -> advisory recall.
        if ($trimmedDescription !== '') {
            return $this->result(self::ACTION_RECALL, false, ['meaningful_description']);
        }

        // Branch 6: empty / whitespace-only description -> nothing to do.
        return $this->result(self::ACTION_NONE, false, []);
    }

    private function matchedMaintenanceSignal(string $trimmedDescription): ?string
    {
        if ($trimmedDescription === '') {
            return null;
        }

        $haystack = strtolower($trimmedDescription);
        foreach (self::MAINTENANCE_SIGNALS as $signal) {
            if (str_contains($haystack, $signal)) {
                return $signal;
            }
        }

        return null;
    }

    /**
     * @param  list<string>  $reasons
     * @return array{action: string, mandatory: bool, reasons: list<string>}
     */
    private function result(string $action, bool $mandatory, array $reasons): array
    {
        return [
            'action' => $action,
            'mandatory' => $mandatory,
            'reasons' => $reasons,
        ];
    }

    private function stringValue(array $payload, string $key): string
    {
        $value = $payload[$key] ?? '';

        return is_string($value) ? $value : '';
    }

    private function boolValue(array $payload, string $key): bool
    {
        return ($payload[$key] ?? false) === true;
    }
}
