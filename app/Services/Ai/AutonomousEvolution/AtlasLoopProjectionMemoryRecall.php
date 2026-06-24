<?php

declare(strict_types=1);

namespace App\Services\Ai\AutonomousEvolution;

use App\Services\Ai\AtlasMemoryRegistryService;
use App\Services\Ai\Memory\MemoryRecallInput;
use Illuminate\Support\Str;
use Throwable;

final class AtlasLoopProjectionMemoryRecall
{
    private const ALLOWED_KINDS = [
        'decision',
        'learning',
        'technical_context',
        'harness_learning',
    ];

    private const QUERY_KINDS = [
        'decision',
        'harness_learning',
        'technical_context',
    ];

    private const ALLOWED_SCOPES = [
        'global',
        'campaign',
        'workspace',
    ];

    public function __construct(
        private readonly AtlasMemoryRegistryService $registry,
        private readonly MemoryRecallInput $input,
    ) {}

    /**
     * @return list<array{kind:string, scope:string, text:string, source_ref:string}>
     */
    public function forTarget(string $relTarget, string $bindingAxis, int $limit = 6): array
    {
        if (! (bool) config('atlas.loop.projection_memory_recall_enabled', false)) {
            return [];
        }

        $relTarget = trim(str_replace('\\', '/', $relTarget));
        if ($relTarget === '') {
            return [];
        }

        $limit = $this->input->recallLimit($limit);
        $candidateLimit = $this->input->registryCandidateLimit(null, $limit);

        try {
            $candidates = $this->registry->search([
                'kinds' => self::QUERY_KINDS,
                'types' => self::ALLOWED_KINDS,
                'q' => $relTarget,
            ], $candidateLimit)->take($candidateLimit);
        } catch (Throwable) {
            return [];
        }

        $ranked = [];
        $index = 0;
        foreach ($candidates as $candidate) {
            $item = $this->normalizeItem($candidate);
            if ($item === null) {
                $index++;

                continue;
            }

            $ranked[] = [
                'index' => $index,
                'score' => $this->lexicalScore($item['text'], $relTarget, $bindingAxis),
                'item' => $item,
            ];
            $index++;
        }

        usort(
            $ranked,
            static fn (array $a, array $b): int => ($b['score'] <=> $a['score']) ?: ($a['index'] <=> $b['index']),
        );

        return array_values(array_map(
            static fn (array $row): array => $row['item'],
            array_slice($ranked, 0, $limit),
        ));
    }

    /**
     * @return array{kind:string, scope:string, text:string, source_ref:string}|null
     */
    private function normalizeItem(mixed $candidate): ?array
    {
        $kind = $this->field($candidate, ['memory_type', 'kind', 'type']);
        if (! in_array($kind, self::ALLOWED_KINDS, true)) {
            return null;
        }

        $text = $this->text($candidate);
        if ($text === '') {
            return null;
        }

        $scope = $this->scope($candidate);
        $text = Str::limit($text, $this->input->itemChars(), '');

        return [
            'kind' => $kind,
            'scope' => $scope,
            'text' => $text,
            'source_ref' => $this->sourceRef($kind, $scope, $text, $candidate),
        ];
    }

    /**
     * @param  list<string>  $fields
     */
    private function field(mixed $candidate, array $fields): string
    {
        foreach ($fields as $field) {
            $value = data_get($candidate, $field);
            if (is_scalar($value) && trim((string) $value) !== '') {
                return strtolower(trim((string) $value));
            }
        }

        return '';
    }

    private function scope(mixed $candidate): string
    {
        $scope = $this->field($candidate, ['scope_type', 'scope']);
        if (str_contains($scope, ':')) {
            $scope = strstr($scope, ':', true) ?: $scope;
        }

        return in_array($scope, self::ALLOWED_SCOPES, true) ? $scope : 'global';
    }

    private function text(mixed $candidate): string
    {
        foreach (['redacted_summary', 'summary', 'redacted_body', 'body', 'redacted_title', 'title', 'claim'] as $field) {
            $value = data_get($candidate, $field);
            if (is_scalar($value) && trim((string) $value) !== '') {
                return trim(preg_replace('/\s+/', ' ', (string) $value) ?? '');
            }
        }

        return '';
    }

    private function sourceRef(string $kind, string $scope, string $text, mixed $candidate): string
    {
        $sourceType = $this->field($candidate, ['source_type']);
        $sourceLabel = $this->field($candidate, ['source_label']);
        $contentHash = $this->field($candidate, ['content_hash']);
        $safeBasis = implode('|', array_filter([
            $kind,
            $scope,
            $sourceType,
            $sourceLabel,
            $contentHash,
            $text,
        ], static fn (string $value): bool => $value !== ''));

        return 'atlas_memory:'.substr(hash('sha256', $safeBasis), 0, 16);
    }

    private function lexicalScore(string $text, string $relTarget, string $bindingAxis): int
    {
        $haystack = $this->lexical($text);
        $basename = $this->lexical(pathinfo($relTarget, PATHINFO_FILENAME));
        $axis = $this->lexical($bindingAxis);
        $directory = $this->directoryToken($relTarget);
        $score = 0;

        $hasBasename = $basename !== '' && str_contains($haystack, $basename);
        $hasAxis = $axis !== '' && str_contains($haystack, $axis);
        if ($hasBasename) {
            $score += 4;
        }
        if ($hasAxis) {
            $score += 4;
        }
        if ($directory !== '' && str_contains($haystack, $directory)) {
            $score++;
        }
        if ($hasBasename && $hasAxis) {
            $score += 2;
        }

        return $score;
    }

    private function directoryToken(string $relTarget): string
    {
        $dir = trim(dirname($relTarget), './');
        if ($dir === '' || $dir === '.') {
            return '';
        }

        $parts = array_values(array_filter(explode('/', $dir), static fn (string $part): bool => trim($part) !== ''));

        return $this->lexical((string) end($parts));
    }

    private function lexical(string $value): string
    {
        return strtolower(preg_replace('/[^a-z0-9]+/i', '', $value) ?? '');
    }
}
