<?php

declare(strict_types=1);

namespace App\Services\Ai\SoftwareCompanyStewardship\AreaFocusLoop;

/**
 * Builds a corpus of operator engineering decisions from review gates,
 * cockpit decisions, AEMOR outcomes and override receipts.
 *
 * The model is only allowed to observe operator judgment. Provider taste
 * (unlabeled provider output) is ignored, and any decision that was not
 * authored by the operator is rejected. An empty corpus is honest about
 * having insufficient evidence instead of pretending to have learned.
 */
final class L9OperatorDecisionCorpusBuilder
{
    private const SCHEMA_VERSION = 'atlas.loop.l9.operator_decision_corpus.v1';

    private const STATUS_READY = 'corpus_ready';

    private const STATUS_INSUFFICIENT = 'insufficient_evidence';

    private const REASON_UNLABELED_PROVIDER_OUTPUT = 'unlabeled_provider_output';

    private const REASON_NON_OPERATOR_DECISION = 'non_operator_decision';

    private const REASON_UNLABELED_OPERATOR_DECISION = 'unlabeled_operator_decision';

    /**
     * @param  array<int, array<string, mixed>>  $events
     * @return array{
     *     schema_version: string,
     *     status: string,
     *     decision_count: int,
     *     labeled_examples: list<string>,
     *     rationale_refs: list<string>,
     *     override_refs: list<string>,
     *     rejected_examples: list<array{example_id: string, reason: string}>
     * }
     */
    public function build(array $events): array
    {
        $labeledExamples = [];
        $rationaleRefs = [];
        $overrideRefs = [];
        $rejectedExamples = [];

        $index = 0;
        foreach ($events as $event) {
            $exampleId = $this->exampleId($event, $index);
            $index++;

            $isOperator = $this->isOperatorAuthored($event);
            $hasLabel = $this->hasOperatorLabel($event);

            if (! $hasLabel && ! $isOperator) {
                $rejectedExamples[] = [
                    'example_id' => $exampleId,
                    'reason' => self::REASON_UNLABELED_PROVIDER_OUTPUT,
                ];

                continue;
            }

            if (! $isOperator) {
                $rejectedExamples[] = [
                    'example_id' => $exampleId,
                    'reason' => self::REASON_NON_OPERATOR_DECISION,
                ];

                continue;
            }

            if (! $hasLabel) {
                $rejectedExamples[] = [
                    'example_id' => $exampleId,
                    'reason' => self::REASON_UNLABELED_OPERATOR_DECISION,
                ];

                continue;
            }

            $labeledExamples[] = $exampleId;

            $rationaleRef = $this->stringField($event, 'rationale_ref');
            if ($rationaleRef !== '') {
                $rationaleRefs[] = $rationaleRef;
            }

            $overrideRef = $this->overrideRef($event);
            if ($overrideRef !== '') {
                $overrideRefs[] = $overrideRef;
            }
        }

        $decisionCount = count($labeledExamples);

        return [
            'schema_version' => self::SCHEMA_VERSION,
            'status' => $decisionCount === 0 ? self::STATUS_INSUFFICIENT : self::STATUS_READY,
            'decision_count' => $decisionCount,
            'labeled_examples' => $labeledExamples,
            'rationale_refs' => $rationaleRefs,
            'override_refs' => $overrideRefs,
            'rejected_examples' => $rejectedExamples,
        ];
    }

    /**
     * @param  array<string, mixed>  $event
     */
    private function exampleId(array $event, int $index): string
    {
        $id = $this->stringField($event, 'id');
        if ($id !== '') {
            return $id;
        }

        $id = $this->stringField($event, 'example_id');
        if ($id !== '') {
            return $id;
        }

        return 'event_'.$index;
    }

    /**
     * @param  array<string, mixed>  $event
     */
    private function isOperatorAuthored(array $event): bool
    {
        if (($event['is_operator'] ?? null) === true) {
            return true;
        }

        if (($event['is_provider'] ?? null) === true) {
            return false;
        }

        $author = strtolower($this->firstStringField($event, ['author', 'decided_by', 'source']));

        return $author === 'operator';
    }

    /**
     * @param  array<string, mixed>  $event
     */
    private function hasOperatorLabel(array $event): bool
    {
        return $this->firstStringField($event, ['label', 'decision', 'operator_label']) !== '';
    }

    /**
     * @param  array<string, mixed>  $event
     */
    private function overrideRef(array $event): string
    {
        $ref = $this->stringField($event, 'override_ref');
        if ($ref !== '') {
            return $ref;
        }

        if (($event['is_override'] ?? null) === true) {
            $exampleId = $this->stringField($event, 'id');
            if ($exampleId !== '') {
                return $exampleId;
            }

            return $this->stringField($event, 'example_id');
        }

        return '';
    }

    /**
     * @param  array<string, mixed>  $event
     * @param  list<string>  $keys
     */
    private function firstStringField(array $event, array $keys): string
    {
        foreach ($keys as $key) {
            $value = $this->stringField($event, $key);
            if ($value !== '') {
                return $value;
            }
        }

        return '';
    }

    /**
     * @param  array<string, mixed>  $event
     */
    private function stringField(array $event, string $key): string
    {
        $value = $event[$key] ?? null;

        if (is_string($value)) {
            return trim($value);
        }

        if (is_int($value) || is_float($value)) {
            return trim((string) $value);
        }

        return '';
    }
}
