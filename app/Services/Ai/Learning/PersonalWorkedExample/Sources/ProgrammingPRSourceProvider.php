<?php

namespace App\Services\Ai\Cognitive\PersonalWorkedExample\Sources;

use App\Models\AtlasLedgerEvent;
use Carbon\CarbonImmutable;

class ProgrammingPRSourceProvider
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
            ->filter(fn (array $candidate): bool => ($candidate['source_type'] ?? null) === 'programming_pr')
            ->take(max(1, $limit))
            ->values()
            ->all();
    }

    /**
     * @return array<string,mixed>
     */
    private function candidateFrom(AtlasLedgerEvent $event): array
    {
        $payload = (array) $event->payload;
        $source = (array) data_get($payload, 'personal_worked_example', $payload);

        if (($source['source_type'] ?? null) !== 'programming_pr') {
            return ['source_type' => 'ignored'];
        }

        $topic = (string) ($source['topic'] ?? data_get($source, 'source_metadata.topic', 'programming.pattern'));

        return [
            'schema_version' => 'atlas.cognitive.personal_worked_example_candidate.v1',
            'source_type' => 'programming_pr',
            'source_ref' => (string) ($source['source_ref'] ?? $event->event_id),
            'topic' => $topic,
            'domain' => (string) ($source['domain'] ?? 'programming'),
            'title' => (string) ($source['title'] ?? 'Personal PR Example: '.$topic),
            'problem_context' => (string) ($source['problem_context'] ?? $source['commit_explanation'] ?? 'Personal programming PR extracted from Atlas Ledger.'),
            'raw_steps' => (array) ($source['steps'] ?? []),
            'source_metadata' => array_merge((array) ($source['source_metadata'] ?? []), [
                'event_id' => $event->event_id,
                'commit_explanation' => $source['commit_explanation'] ?? null,
            ]),
            'quality_signals' => [
                'tests_passed' => (bool) ($source['tests_passed'] ?? false),
                'no_regression_30d' => (bool) ($source['no_regression_30d'] ?? false),
                'commit_explanation' => (string) ($source['commit_explanation'] ?? ''),
            ],
            'privacy_class' => (int) ($source['privacy_class'] ?? 2),
            'content' => (string) ($source['content'] ?? $source['commit_explanation'] ?? ''),
        ];
    }
}
