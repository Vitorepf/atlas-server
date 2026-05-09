<?php

namespace App\Services\Ai\Cognitive\PersonalWorkedExample\Sources;

use App\Models\AtlasLedgerEvent;
use Carbon\CarbonImmutable;

class FeynmanSessionSourceProvider
{
    /**
     * @return array<int,array<string,mixed>>
     */
    public function candidates(int $days = 90, int $limit = 50): array
    {
        return AtlasLedgerEvent::query()
            ->where('occurred_at', '>=', CarbonImmutable::now()->subDays(max(1, $days)))
            ->orderByDesc('occurred_at')
            ->limit(max(1, min(500, $limit * 4)))
            ->get()
            ->map(fn (AtlasLedgerEvent $event): array => $this->candidateFrom($event))
            ->filter(fn (array $candidate): bool => ($candidate['source_type'] ?? null) === 'feynman_session')
            ->take(max(1, $limit))
            ->values()
            ->all();
    }

    /**
     * @return array<string,mixed>
     */
    private function candidateFrom(AtlasLedgerEvent $event): array
    {
        $source = (array) data_get((array) $event->payload, 'personal_worked_example', $event->payload);
        if (($source['source_type'] ?? null) !== 'feynman_session') {
            return ['source_type' => 'ignored'];
        }

        $topic = (string) ($source['topic'] ?? 'feynman.explanation');

        return [
            'schema_version' => 'atlas.cognitive.personal_worked_example_candidate.v1',
            'source_type' => 'feynman_session',
            'source_ref' => (string) ($source['source_ref'] ?? $event->event_id),
            'topic' => $topic,
            'domain' => (string) ($source['domain'] ?? 'learning'),
            'title' => (string) ($source['title'] ?? 'Personal Feynman Example: '.$topic),
            'problem_context' => (string) ($source['problem_context'] ?? 'High-scoring Feynman explanation from operator history.'),
            'raw_steps' => (array) ($source['steps'] ?? []),
            'source_metadata' => array_merge((array) ($source['source_metadata'] ?? []), ['event_id' => $event->event_id]),
            'quality_signals' => [
                'feynman_score' => (float) ($source['feynman_score'] ?? 0),
            ],
            'privacy_class' => (int) ($source['privacy_class'] ?? 1),
            'content' => (string) ($source['content'] ?? $source['problem_context'] ?? ''),
        ];
    }
}
