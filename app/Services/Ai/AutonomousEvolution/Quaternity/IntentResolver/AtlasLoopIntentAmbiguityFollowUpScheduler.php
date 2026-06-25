<?php

declare(strict_types=1);

namespace App\Services\Ai\AutonomousEvolution\Quaternity\IntentResolver;

/**
 * FACT-only projector: emits the deterministic "pending clarifications" set as set difference of
 *   proposed_questions  −  ledger_resolved_questions.
 *
 * Sources are injected as callables so the scheduler has no concrete coupling and can be tested:
 *   - intentsSource(): list<array{intent_id, capture_at_utc, ambiguities: list<array{ambiguity_finding_id, question_hash}>}>
 *   - resolvedQuestionHashesSource(): list<string>   (every question_hash with at least one ledger row)
 *
 * NEVER scores, NEVER nudges, NEVER originates ambiguities. Pure set difference over FACT.
 */
final class AtlasLoopIntentAmbiguityFollowUpScheduler
{
    /** @var callable(): list<array<string,mixed>> */
    private $intentsSource;

    /** @var callable(): list<string> */
    private $resolvedQuestionHashesSource;

    public function __construct(callable $intentsSource, callable $resolvedQuestionHashesSource)
    {
        $this->intentsSource = $intentsSource;
        $this->resolvedQuestionHashesSource = $resolvedQuestionHashesSource;
    }

    /**
     * @return list<array<string,mixed>>
     */
    public function pending(): array
    {
        $intents = ($this->intentsSource)();
        $resolved = array_flip(array_values(array_map('strval', ($this->resolvedQuestionHashesSource)())));

        $rows = [];
        foreach ($intents as $intent) {
            if (! is_array($intent)) {
                continue;
            }
            $intentId = (string) ($intent['intent_id'] ?? '');
            $captureAt = (string) ($intent['capture_at_utc'] ?? '');
            foreach ((array) ($intent['ambiguities'] ?? []) as $a) {
                if (! is_array($a)) {
                    continue;
                }
                $questionHash = (string) ($a['question_hash'] ?? '');
                if ($questionHash === '' || isset($resolved[$questionHash])) {
                    continue;
                }
                $rows[] = [
                    'intent_id' => $intentId,
                    'ambiguity_finding_id' => (string) ($a['ambiguity_finding_id'] ?? ''),
                    'question_hash' => $questionHash,
                    'capture_at_utc' => $captureAt,
                ];
            }
        }

        usort($rows, static function (array $a, array $b): int {
            $c = strcmp((string) $a['capture_at_utc'], (string) $b['capture_at_utc']);
            if ($c !== 0) {
                return $c;
            }

            return strcmp((string) $a['ambiguity_finding_id'], (string) $b['ambiguity_finding_id']);
        });

        return $rows;
    }

    /**
     * @return list<array<string,mixed>>
     */
    public function pendingForIntent(string $intentId): array
    {
        return array_values(array_filter($this->pending(), static fn (array $r): bool => (string) $r['intent_id'] === $intentId));
    }

    public function isAwaitingClarification(string $intentId): bool
    {
        return $this->pendingForIntent($intentId) !== [];
    }
}
