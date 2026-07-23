<?php

declare(strict_types=1);

namespace App\Services\Ai\SelfConstruction\ExternalBrain;

/**
 * Routes disagreement between engines into verification tasks instead of
 * averaging incompatible answers.
 *
 * Pure: no I/O, no side effects.
 */
final class AtlasExternalBrainCrossEngineConsensusDisagreementRouter
{
    public const SCHEMA = 'atlas.external_brain.cross_engine_consensus_disagreement_router.v1';

    public const VERDICT_ALIGNED = 'aligned';
    public const VERDICT_DISAGREEMENT = 'disagreement';

    /**
     * @param  array<string, mixed>  $input
     * @return array<string, mixed>
     */
    public function route(array $input): array
    {
        $recommendations = is_array($input['recommendations'] ?? null) ? $input['recommendations'] : [];
        $originatorId = (string) ($input['originator_id'] ?? '');
        $roundId = (string) ($input['round_id'] ?? '');
        $topic = (string) ($input['topic'] ?? '');

        $answers = [];
        $engines = [];
        foreach ($recommendations as $rec) {
            if (! is_array($rec)) {
                continue;
            }

            $engine = (string) ($rec['engine'] ?? '');
            $answer = (string) ($rec['answer'] ?? '');
            if ($engine === '' || $answer === '') {
                continue;
            }

            $engines[] = $engine;
            $answers[$engine] = $answer;
        }

        $uniqueAnswers = array_values(array_unique($answers));
        $disagreement = count($uniqueAnswers) > 1;

        $verdict = $disagreement ? self::VERDICT_DISAGREEMENT : self::VERDICT_ALIGNED;

        $verificationTasks = [];
        if ($disagreement) {
            foreach ($uniqueAnswers as $index => $answer) {
                $supportingEngines = array_keys(array_filter($answers, static fn (string $a): bool => $a === $answer));
                $verificationTasks[] = [
                    'task_id' => 'verify-'.($index + 1),
                    'topic' => $topic,
                    'answer_under_test' => $answer,
                    'supporting_engines' => $supportingEngines,
                    'opposing_engines' => array_values(array_diff($engines, $supportingEngines)),
                    'kind' => 'cross_engine_verification',
                ];
            }
        }

        return [
            'schema_version' => self::SCHEMA,
            'originator_id' => $originatorId,
            'round_id' => $roundId,
            'topic' => $topic,
            'verdict' => $verdict,
            'engine_count' => count($engines),
            'unique_answer_count' => count($uniqueAnswers),
            'disagreement' => $disagreement,
            'verification_tasks' => $verificationTasks,
            'verification_task_count' => count($verificationTasks),
        ];
    }
}
