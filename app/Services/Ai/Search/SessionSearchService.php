<?php

namespace App\Services\Ai\Search;

use App\Models\AiMessage;
use App\Models\AiThread;
use App\Services\Ai\Context\RetrievalRankInput;
use App\Support\AtlasSecurity;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;

class SessionSearchService
{
    private const EXCERPT_LIMIT = 30000;

    public function __construct(private readonly RetrievalRankInput $input) {}

    /**
     * @return array<int,SearchResult>
     */
    public function search(string $workspace, string $query, int $topN = 3, bool $summarize = false): array
    {
        $workspace = $this->resolveWorkspace($workspace);
        $query = trim($query);
        if ($query === '') {
            return [];
        }

        $topN = $this->input->sessionTopN($topN);
        $results = DB::getDriverName() === 'pgsql'
            ? $this->postgresSearch($workspace, $query, $topN)
            : $this->fallbackSearch($workspace, $query, $topN);

        if (! $summarize) {
            return $results;
        }

        return array_map(fn (SearchResult $result): SearchResult => $this->summarized($result, $query), $results);
    }

    /**
     * @return array<int,SearchResult>
     */
    private function postgresSearch(string $workspace, string $query, int $topN): array
    {
        $tsQuery = $this->toTsQuery($query);
        if ($tsQuery === '') {
            return $this->fallbackSearch($workspace, $query, $topN);
        }

        try {
            $rankExpression = "ts_rank(m.content_tsv, to_tsquery('simple', '{$this->escapedTsQueryLiteral($tsQuery)}'))";
            $rows = DB::table('ai_messages as m')
                ->join('ai_threads as t', 't.id', '=', 'm.thread_id')
                ->where('t.workspace', $workspace)
                ->where('m.status', '!=', 'redacted')
                ->whereRaw("m.content_tsv @@ to_tsquery('simple', ?)", [$tsQuery])
                ->groupBy('t.id', 't.title', 't.last_message_at')
                ->orderByDesc('rank')
                ->limit($topN)
                ->get([
                    't.id as thread_id',
                    't.title as thread_title',
                    't.last_message_at',
                    DB::raw("MAX({$rankExpression}) as rank"),
                    DB::raw('MIN(m.position) as match_position'),
                ]);
        } catch (\Throwable) {
            return $this->fallbackSearch($workspace, $query, $topN);
        }

        return $rows
            ->map(fn (object $row): SearchResult => $this->resultFromRow($row, $query, 'postgres_tsvector'))
            ->values()
            ->all();
    }

    /**
     * @return array<int,SearchResult>
     */
    private function fallbackSearch(string $workspace, string $query, int $topN): array
    {
        $parsed = $this->parseQuery($query);
        $required = array_values(array_filter([...$parsed['terms'], ...$parsed['phrases']]));
        $optional = $parsed['or_terms'];
        $negative = $parsed['not_terms'];
        $prefixes = $parsed['prefixes'];

        $messages = AiMessage::query()
            ->select('ai_messages.*')
            ->join('ai_threads', 'ai_threads.id', '=', 'ai_messages.thread_id')
            ->where('ai_threads.workspace', $workspace)
            ->where('ai_messages.status', '!=', 'redacted')
            ->orderByDesc('ai_messages.occurred_at')
            ->limit(1500)
            ->get()
            ->filter(fn (AiMessage $message): bool => $this->matchesFallback((string) $message->content, $required, $optional, $negative, $prefixes));

        $grouped = $messages
            ->groupBy('thread_id')
            ->map(function (Collection $threadMessages): array {
                $threadMessages = $threadMessages->values();
                $rank = $threadMessages->sum(fn (AiMessage $message): float => (float) data_get($message->metadata, 'search_rank', 1.0));
                $first = $threadMessages->sortBy('position')->first();

                return [
                    'thread_id' => (string) $threadMessages->first()->thread_id,
                    'match_position' => (int) ($first?->position ?? 1),
                    'rank' => $rank,
                ];
            })
            ->sortByDesc('rank')
            ->take($topN)
            ->values();

        return $grouped
            ->map(function (array $row) use ($query): ?SearchResult {
                $thread = AiThread::query()->find($row['thread_id']);
                if (! $thread) {
                    return null;
                }

                return new SearchResult(
                    threadId: $thread->id,
                    threadTitle: (string) $thread->title,
                    lastMessageAt: $thread->last_message_at,
                    excerpt: $this->excerptForThread($thread->id, (int) $row['match_position']),
                    rank: round((float) $row['rank'], 6),
                    matchPosition: (int) $row['match_position'],
                    source: 'fallback_like',
                    metadata: [
                        'query' => $query,
                        'workspace' => $thread->workspace,
                    ],
                );
            })
            ->filter()
            ->values()
            ->all();
    }

    private function resultFromRow(object $row, string $query, string $source): SearchResult
    {
        return new SearchResult(
            threadId: (string) $row->thread_id,
            threadTitle: (string) $row->thread_title,
            lastMessageAt: $row->last_message_at ? new \DateTimeImmutable((string) $row->last_message_at) : null,
            excerpt: $this->excerptForThread((string) $row->thread_id, (int) $row->match_position),
            rank: round((float) $row->rank, 6),
            matchPosition: (int) $row->match_position,
            source: $source,
            metadata: ['query' => $query],
        );
    }

    /**
     * @return array{terms:array<int,string>,phrases:array<int,string>,or_terms:array<int,string>,not_terms:array<int,string>,prefixes:array<int,string>}
     */
    private function parseQuery(string $query): array
    {
        preg_match_all('/"([^"]+)"|(\bOR\b|\bNOT\b)|([^\s]+)/i', $query, $matches, PREG_SET_ORDER);

        $terms = [];
        $phrases = [];
        $orTerms = [];
        $notTerms = [];
        $prefixes = [];
        $mode = 'and';

        foreach ($matches as $match) {
            if (($match[2] ?? '') !== '') {
                $operator = Str::upper($match[2]);
                $mode = $operator === 'OR' ? 'or' : 'not';

                continue;
            }

            $raw = (string) (($match[1] ?? '') !== '' ? $match[1] : ($match[3] ?? ''));
            $term = $this->normalizeTerm($raw);
            if ($term === '') {
                continue;
            }

            if (str_ends_with($raw, '*')) {
                $prefixes[] = rtrim($term, '*');
                $mode = 'and';

                continue;
            }

            if (($match[1] ?? '') !== '') {
                $phrases[] = $term;
                $mode = 'and';

                continue;
            }

            if ($mode === 'or') {
                $orTerms[] = $term;
            } elseif ($mode === 'not') {
                $notTerms[] = $term;
            } else {
                $terms[] = $term;
            }

            $mode = 'and';
        }

        return [
            'terms' => array_values(array_unique($terms)),
            'phrases' => array_values(array_unique($phrases)),
            'or_terms' => array_values(array_unique($orTerms)),
            'not_terms' => array_values(array_unique($notTerms)),
            'prefixes' => array_values(array_unique($prefixes)),
        ];
    }

    private function toTsQuery(string $query): string
    {
        $parsed = $this->parseQuery($query);
        $parts = [];

        foreach ($parsed['terms'] as $term) {
            $parts[] = $this->tsLexeme($term);
        }

        foreach ($parsed['phrases'] as $phrase) {
            $phraseParts = collect(preg_split('/\s+/', $phrase) ?: [])
                ->map(fn (string $part): string => $this->tsLexeme($part))
                ->filter()
                ->values()
                ->all();
            if ($phraseParts !== []) {
                $parts[] = implode(' <-> ', $phraseParts);
            }
        }

        foreach ($parsed['prefixes'] as $prefix) {
            $lexeme = $this->tsLexeme($prefix);
            if ($lexeme !== '') {
                $parts[] = $lexeme.':*';
            }
        }

        $queryString = implode(' & ', array_filter($parts));

        foreach ($parsed['or_terms'] as $orTerm) {
            $lexeme = $this->tsLexeme($orTerm);
            if ($lexeme !== '') {
                $queryString = $queryString === '' ? $lexeme : '('.$queryString.') | '.$lexeme;
            }
        }

        foreach ($parsed['not_terms'] as $notTerm) {
            $lexeme = $this->tsLexeme($notTerm);
            if ($lexeme !== '') {
                $queryString = $queryString === '' ? '!'.$lexeme : $queryString.' & !'.$lexeme;
            }
        }

        return $queryString;
    }

    /**
     * @param  array<int,string>  $required
     * @param  array<int,string>  $optional
     * @param  array<int,string>  $negative
     * @param  array<int,string>  $prefixes
     */
    private function matchesFallback(string $content, array $required, array $optional, array $negative, array $prefixes): bool
    {
        $haystack = $this->normalizeTerm($content);
        if ($haystack === '') {
            return false;
        }

        foreach ($negative as $term) {
            if ($term !== '' && str_contains($haystack, $term)) {
                return false;
            }
        }

        $score = 0.0;
        $requiredMatch = true;
        foreach ($required as $term) {
            if ($term !== '' && ! str_contains($haystack, $term)) {
                $requiredMatch = false;
            }
            $score += 2.0;
        }

        $optionalMatch = $optional !== [] && collect($optional)->contains(fn (string $term): bool => str_contains($haystack, $term));
        $score += count(array_filter($optional, fn (string $term): bool => str_contains($haystack, $term)));

        $prefixMatch = true;
        foreach ($prefixes as $prefix) {
            if ($prefix === '' || ! preg_match('/\b'.preg_quote($prefix, '/').'\w*/', $haystack)) {
                $prefixMatch = false;
            }
            $score += 1.5;
        }

        if ($optional !== []) {
            return ($requiredMatch && $prefixMatch && ($required !== [] || $prefixes !== [])) || $optionalMatch;
        }

        return $score > 0 && $requiredMatch && $prefixMatch;
    }

    private function excerptForThread(string $threadId, int $centerPosition): string
    {
        $messages = AiMessage::query()
            ->where('thread_id', $threadId)
            ->whereIn('role', ['user', 'assistant', 'summary'])
            ->where('status', '!=', 'redacted')
            ->orderBy('position')
            ->get()
            ->map(fn (AiMessage $message): array => [
                'position' => $message->position,
                'text' => $this->messageLine($message),
            ])
            ->values();

        if ($messages->isEmpty()) {
            return '';
        }

        $centerIndex = max(0, $messages->search(fn (array $message): bool => (int) $message['position'] >= $centerPosition));
        $selected = [$centerIndex => $messages[$centerIndex]['text']];
        $length = mb_strlen($messages[$centerIndex]['text']);
        $left = $centerIndex - 1;
        $right = $centerIndex + 1;

        while (($left >= 0 || $right < $messages->count()) && $length < self::EXCERPT_LIMIT) {
            if ($left >= 0) {
                $candidate = $messages[$left]['text'];
                $length += mb_strlen($candidate) + 2;
                if ($length <= self::EXCERPT_LIMIT) {
                    $selected[$left] = $candidate;
                }
                $left--;
            }

            if ($right < $messages->count()) {
                $candidate = $messages[$right]['text'];
                $length += mb_strlen($candidate) + 2;
                if ($length <= self::EXCERPT_LIMIT) {
                    $selected[$right] = $candidate;
                }
                $right++;
            }
        }

        ksort($selected);

        return AtlasSecurity::redactString(implode("\n\n", $selected));
    }

    private function messageLine(AiMessage $message): string
    {
        $time = $message->occurred_at?->format('Y-m-d H:i') ?: $message->created_at?->format('Y-m-d H:i') ?: 'sem-data';
        $provider = $message->provider ? " provider={$message->provider}" : '';

        return "[position {$message->position} | {$time} | {$message->role}{$provider}]\n".trim($message->content);
    }

    private function summarized(SearchResult $result, string $query): SearchResult
    {
        $sentences = collect(preg_split('/(?<=[.!?])\s+|\n{2,}/', $result->excerpt) ?: [])
            ->map(fn (string $sentence): string => trim($sentence))
            ->filter()
            ->take(8)
            ->values();

        $summary = implode(' ', $sentences->all());
        if (mb_strlen($summary) > 1800) {
            $summary = Str::limit($summary, 1800, '...');
        }

        return new SearchResult(
            threadId: $result->threadId,
            threadTitle: $result->threadTitle,
            lastMessageAt: $result->lastMessageAt,
            excerpt: "Resumo local para query [{$query}]: ".$summary,
            rank: $result->rank,
            matchPosition: $result->matchPosition,
            source: $result->source,
            metadata: array_merge($result->metadata, ['summarized' => true, 'summary_mode' => 'local_extractive']),
        );
    }

    private function normalizeTerm(string $value): string
    {
        return Str::of($value)
            ->lower()
            ->ascii()
            ->replaceMatches('/[^\pL\pN_*]+/u', ' ')
            ->squish()
            ->value();
    }

    private function tsLexeme(string $value): string
    {
        return preg_replace('/[^a-z0-9_]+/', '', $this->normalizeTerm($value)) ?: '';
    }

    private function escapedTsQueryLiteral(string $value): string
    {
        return str_replace("'", "''", $value);
    }

    private function resolveWorkspace(string $workspace): string
    {
        $resolved = realpath($workspace);

        return $resolved && is_dir($resolved) ? $resolved : $workspace;
    }
}
