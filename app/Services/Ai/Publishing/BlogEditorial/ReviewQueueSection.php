<?php

declare(strict_types=1);

namespace App\Services\Ai\Publishing\BlogEditorial;

final class ReviewQueueSection
{
    /**
     * @param  array<string,mixed>  $candidate
     * @return array<string,mixed>
     */
    public function reviewQueueEntry(array $candidate): array
    {
        return [
            'status' => 'accepted_for_review',
            'accepted_at' => now()->toJSON(),
            'title' => (string) ($candidate['title'] ?? ''),
            'slug' => (string) ($candidate['slug'] ?? ''),
            'collection' => (string) ($candidate['collection'] ?? ''),
            'series' => (string) ($candidate['series'] ?? ''),
            'complexity_level' => (string) ($candidate['complexity_level'] ?? ''),
            'main_question' => (string) ($candidate['main_question'] ?? ''),
            'suggested_after_slug' => (string) ($candidate['suggested_after_slug'] ?? ''),
            'source_type' => (string) ($candidate['source_type'] ?? ''),
            'source_ref' => (string) ($candidate['source_ref'] ?? ''),
            'topics' => array_values(array_filter((array) ($candidate['topics'] ?? []), 'is_string')),
            'why' => (string) ($candidate['why'] ?? ''),
        ];
    }

    /**
     * @param  array<string,mixed>  $entry
     */
    public function reviewQueueSnippet(array $entry): string
    {
        $lines = [
            '  - status: "'.$this->escapeYamlString((string) $entry['status']).'"',
            '    accepted_at: "'.$this->escapeYamlString((string) $entry['accepted_at']).'"',
            '    title: "'.$this->escapeYamlString((string) $entry['title']).'"',
            '    slug: "'.$this->escapeYamlString((string) $entry['slug']).'"',
            '    collection: "'.$this->escapeYamlString((string) $entry['collection']).'"',
            '    series: "'.$this->escapeYamlString((string) $entry['series']).'"',
            '    complexity_level: "'.$this->escapeYamlString((string) $entry['complexity_level']).'"',
            '    main_question: "'.$this->escapeYamlString((string) $entry['main_question']).'"',
            '    suggested_after_slug: "'.$this->escapeYamlString((string) $entry['suggested_after_slug']).'"',
            '    source_type: "'.$this->escapeYamlString((string) $entry['source_type']).'"',
            '    source_ref: "'.$this->escapeYamlString((string) $entry['source_ref']).'"',
            '    topics:',
        ];

        foreach ((array) $entry['topics'] as $topic) {
            $lines[] = '      - "'.$this->escapeYamlString((string) $topic).'"';
        }

        $lines[] = '    why: "'.$this->escapeYamlString((string) $entry['why']).'"';

        return implode("\n", $lines)."\n";
    }

    /**
     * @return array<string,mixed>|null
     */
    public function queuedCandidateEntry(string $reviewQueuePath, string $candidateSlug): ?array
    {
        foreach ($this->queuedCandidateEntries($reviewQueuePath) as $entry) {
            if ((string) ($entry['slug'] ?? '') === $candidateSlug) {
                return $entry;
            }
        }

        return null;
    }

    /**
     * @return array<int,array<string,mixed>>
     */
    public function queuedCandidateEntries(string $reviewQueuePath): array
    {
        $raw = (string) file_get_contents($reviewQueuePath);
        $blocks = preg_split('/(?=^  - status:)/m', $raw) ?: [];
        $entries = [];

        foreach ($blocks as $block) {
            $entry = [];
            $lines = preg_split('/\R/', $block) ?: [];
            $readingTopics = false;

            foreach ($lines as $line) {
                if (preg_match('/^\s{4}([a-z_]+):\s*"(.*)"\s*$/', $line, $matches)) {
                    $entry[$matches[1]] = $this->unescapeYamlString($matches[2]);
                    $readingTopics = false;
                    continue;
                }

                if (preg_match('/^\s{4}topics:\s*$/', $line)) {
                    $entry['topics'] = [];
                    $readingTopics = true;
                    continue;
                }

                if ($readingTopics && preg_match('/^\s{6}-\s*"(.*)"\s*$/', $line, $matches)) {
                    $entry['topics'][] = $this->unescapeYamlString($matches[1]);
                    continue;
                }
            }

            if (isset($entry['slug'])) {
                $entry['topics'] = array_values(array_filter((array) ($entry['topics'] ?? []), 'is_string'));
                $entries[] = $entry;
            }
        }

        return $entries;
    }

    /**
     * @param  array{week:int,theme:string,goal:string,post:array<string,mixed>}  $week
     */
    public function promotionWeekSnippet(array $week): string
    {
        $post = $week['post'];
        $lines = [
            '  - week: '.$week['week'],
            '    theme: "'.$this->escapeYamlString($week['theme']).'"',
            '    goal: "'.$this->escapeYamlString($week['goal']).'"',
            '    posts:',
            '      - order: '.((int) $post['order']),
            '        title: "'.$this->escapeYamlString((string) $post['title']).'"',
            '        slug: "'.$this->escapeYamlString((string) $post['slug']).'"',
            '        type: "'.$this->escapeYamlString((string) $post['type']).'"',
            '        complexity_level: "'.$this->escapeYamlString((string) $post['complexity_level']).'"',
            '        collection: "'.$this->escapeYamlString((string) $post['collection']).'"',
            '        series: "'.$this->escapeYamlString((string) $post['series']).'"',
            '        reader_level: "'.$this->escapeYamlString((string) $post['reader_level']).'"',
            '        goal: "'.$this->escapeYamlString((string) $post['goal']).'"',
            '        main_question: "'.$this->escapeYamlString((string) $post['main_question']).'"',
        ];

        $prerequisites = array_values(array_filter((array) $post['prerequisites'], 'is_string'));
        if ($prerequisites === []) {
            $lines[] = '        prerequisites: []';
        } else {
            $lines[] = '        prerequisites:';
            foreach ($prerequisites as $prerequisite) {
                $lines[] = '          - "'.$this->escapeYamlString($prerequisite).'"';
            }
        }

        $lines[] = '        next_reading: []';
        $lines[] = '        topics:';
        foreach (array_values(array_filter((array) $post['topics'], 'is_string')) as $topic) {
            $lines[] = '          - "'.$this->escapeYamlString($topic).'"';
        }

        return implode("\n", $lines)."\n";
    }

    public function readerLevelForComplexity(string $complexity): string
    {
        return in_array($complexity, ['L3', 'L4', 'L5'], true) ? 'intermediate' : 'beginner';
    }

    public function escapeYamlString(string $value): string
    {
        return str_replace(['\\', '"'], ['\\\\', '\\"'], $value);
    }

    public function unescapeYamlString(string $value): string
    {
        return str_replace(['\\"', '\\\\'], ['"', '\\'], $value);
    }
}
