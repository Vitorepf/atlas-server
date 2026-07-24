<?php

declare(strict_types=1);

namespace App\Console\Commands;

use App\Services\Ai\Cognition\AtlasCognitionScoreCardService;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\File;
use Throwable;
use App\Support\UtcIsoTimestamp;

final class AtlasCognitionVerifyClaimsCommand extends Command
{
    private const MARKER_START = '<!-- atlas:acos-scorecard-claims:start -->';

    private const MARKER_END = '<!-- atlas:acos-scorecard-claims:end -->';

    protected $signature = 'atlas:cognition:scorecard:verify-claims
        {--write : Rewrite doc stamps from the live scorecard}
        {--json : Emit machine-readable JSON}
        {--acos-doc= : Override the ACOS doc path (defaults to the canonical doc)}
        {--partials-doc= : Override the ACOS pipeline partials doc path (defaults to the canonical doc)}';

    protected $description = 'Verify ACOS doc scorecard claim stamps against live runtime. --write refreshes stamps; exit=0 immediately after --write is tautological, the real guarantee is continuous watchdog PIP-08.';

    public function handle(AtlasCognitionScoreCardService $scorecard): int
    {
        try {
            $liveReport = $scorecard->build();
            $liveStamp = $this->liveStamp($liveReport);
            $docs = $this->docPaths();

            if ((bool) $this->option('write')) {
                foreach ($docs as $path) {
                    $this->writeStamp($path, $liveStamp);
                }

                $payload = [
                    'ok' => true,
                    'action' => 'acos-scorecard-claims-write',
                    'written' => array_map(fn (string $path): string => $this->relativePath($path), $docs),
                    'live' => $liveStamp,
                    'diffs' => [],
                    'note' => 'exit=0 right after --write is tautological; the real guarantee is continuous watchdog (PIP-08).',
                ];

                $this->emit($payload);

                return 0;
            }

            $diffs = [];
            foreach ($docs as $path) {
                $stamp = $this->readStamp($path);
                $diffs = array_merge($diffs, $this->diffStamp($path, $stamp, $liveStamp));
            }

            $payload = [
                'ok' => $diffs === [],
                'action' => 'acos-scorecard-claims-verify',
                'docs' => array_map(fn (string $path): string => $this->relativePath($path), $docs),
                'live' => $liveStamp,
                'diffs' => $diffs,
                'note' => 'Docs are stamped mirrors only; AtlasCognitionScoreCardService::build() is the source of score truth.',
            ];

            $this->emit($payload);

            return $diffs === [] ? 0 : 1;
        } catch (Throwable $e) {
            $this->error('[atlas:cognition:scorecard:verify-claims] '.$e->getMessage());

            return 1;
        }
    }

    /**
     * @return array<string,mixed>
     */
    private function liveStamp(array $report): array
    {
        $partials = [];
        foreach ((array) ($report['subsystems'] ?? []) as $row) {
            if (! is_array($row)) {
                continue;
            }
            if (($row['pipeline_status'] ?? null) === AtlasCognitionScoreCardService::STATUS_PARTIAL) {
                $acronym = (string) ($row['acronym'] ?? '');
                if ($acronym !== '') {
                    $partials[] = $acronym;
                }
            }
        }

        $partials = $this->sortedUnique($partials);

        return [
            'schema' => 'atlas.acos.scorecard_claims.v1',
            'source' => 'AtlasCognitionScoreCardService::build()',
            'overall_score' => (float) data_get($report, 'score.overall_out_of_10', 0.0),
            'pipeline_score' => (float) data_get($report, 'score.dimensions.pipeline.score_out_of_10', 0.0),
            'scorecard_hash' => (string) ($report['scorecard_hash'] ?? ''),
            'partial_facets' => $partials,
            'partial_facet_count' => count($partials),
            'updated_at' => UtcIsoTimestamp::now(),
            'note' => 'Doc stamp mirror only; runtime scorecard is authoritative.',
        ];
    }

    /**
     * @return list<string>
     */
    private function docPaths(): array
    {
        return [
            $this->absolutePath((string) ($this->option('acos-doc') ?: 'docs/engineering-knowledge-base/atlas-cognition-operating-system.md')),
            $this->absolutePath((string) ($this->option('partials-doc') ?: 'docs/engineering-knowledge-base/atlas-cognition-operating-system-pipeline-partials.md')),
        ];
    }

    private function absolutePath(string $path): string
    {
        return str_starts_with($path, DIRECTORY_SEPARATOR) ? $path : base_path($path);
    }

    /**
     * @return array<string,mixed>|null
     */
    private function readStamp(string $path): ?array
    {
        if (! File::exists($path)) {
            return [
                '__error' => 'doc_missing',
            ];
        }

        $contents = File::get($path);
        $pattern = '/'.preg_quote(self::MARKER_START, '/').'(.*?)'.preg_quote(self::MARKER_END, '/').'/s';
        if (preg_match($pattern, $contents, $matches) !== 1) {
            return [
                '__error' => 'marker_missing',
            ];
        }

        $json = $this->stripFence(trim((string) $matches[1]));

        try {
            $decoded = json_decode($json, true, 512, JSON_THROW_ON_ERROR);
        } catch (Throwable $e) {
            return [
                '__error' => 'invalid_json',
                'message' => $e->getMessage(),
            ];
        }

        return is_array($decoded) ? $decoded : [
            '__error' => 'invalid_stamp',
        ];
    }

    private function stripFence(string $text): string
    {
        $text = preg_replace('/^```(?:json)?\s*/', '', $text) ?? $text;
        $text = preg_replace('/\s*```$/', '', $text) ?? $text;

        return trim($text);
    }

    /**
     * @param  array<string,mixed>|null  $stamp
     * @param  array<string,mixed>  $live
     * @return list<array<string,mixed>>
     */
    private function diffStamp(string $path, ?array $stamp, array $live): array
    {
        $doc = $this->relativePath($path);
        if ($stamp === null || isset($stamp['__error'])) {
            return [[
                'path' => $doc,
                'field' => 'stamp',
                'expected' => 'valid '.self::MARKER_START.' JSON stamp',
                'actual' => (string) ($stamp['__error'] ?? 'missing'),
                'message' => (string) ($stamp['message'] ?? ''),
            ]];
        }

        $diffs = [];
        foreach (['overall_score', 'pipeline_score'] as $field) {
            $expected = (float) $live[$field];
            $actual = (float) ($stamp[$field] ?? -1);
            if (abs($expected - $actual) > 0.0001) {
                $diffs[] = [
                    'path' => $doc,
                    'field' => $field,
                    'expected' => $expected,
                    'actual' => $stamp[$field] ?? null,
                ];
            }
        }

        if ((string) ($stamp['scorecard_hash'] ?? '') !== (string) $live['scorecard_hash']) {
            $diffs[] = [
                'path' => $doc,
                'field' => 'scorecard_hash',
                'expected' => $live['scorecard_hash'],
                'actual' => $stamp['scorecard_hash'] ?? null,
            ];
        }

        $expectedPartials = $this->sortedUnique((array) $live['partial_facets']);
        $actualPartials = $this->sortedUnique((array) ($stamp['partial_facets'] ?? []));
        $missing = array_values(array_diff($expectedPartials, $actualPartials));
        $extra = array_values(array_diff($actualPartials, $expectedPartials));
        if ($missing !== [] || $extra !== []) {
            $diffs[] = [
                'path' => $doc,
                'field' => 'partial_facets',
                'missing_from_stamp' => $missing,
                'extra_in_stamp' => $extra,
            ];
        }

        return $diffs;
    }

    /**
     * @param  array<string,mixed>  $stamp
     */
    private function writeStamp(string $path, array $stamp): void
    {
        File::ensureDirectoryExists(dirname($path));

        $json = json_encode($stamp, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE | JSON_THROW_ON_ERROR);
        $block = self::MARKER_START."\n".$json."\n".self::MARKER_END;
        $section = "## ACOS scorecard claim stamp\n\n".$block."\n";

        $contents = File::exists($path) ? File::get($path) : '';
        $pattern = '/'.preg_quote(self::MARKER_START, '/').'.*?'.preg_quote(self::MARKER_END, '/').'/s';

        if (preg_match($pattern, $contents) === 1) {
            File::put($path, preg_replace($pattern, $block, $contents, 1) ?? $contents);

            return;
        }

        $prefix = rtrim($contents);
        File::put($path, ($prefix === '' ? '' : $prefix."\n\n").$section);
    }

    /**
     * @param  list<mixed>  $values
     * @return list<string>
     */
    private function sortedUnique(array $values): array
    {
        $strings = array_values(array_unique(array_filter(array_map(
            static fn (mixed $value): string => (string) $value,
            $values,
        ), static fn (string $value): bool => $value !== '')));
        sort($strings, SORT_STRING);

        return $strings;
    }

    /**
     * @param  array<string,mixed>  $payload
     */
    private function emit(array $payload): void
    {
        if ((bool) $this->option('json')) {
            $this->line(json_encode($payload, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE | JSON_THROW_ON_ERROR));

            return;
        }

        if (($payload['ok'] ?? false) === true) {
            $this->info('[atlas:cognition:scorecard:verify-claims] OK');
            if (isset($payload['note'])) {
                $this->line((string) $payload['note']);
            }

            return;
        }

        $this->error('[atlas:cognition:scorecard:verify-claims] doc/runtime drift detected');
        foreach ((array) ($payload['diffs'] ?? []) as $diff) {
            if (! is_array($diff)) {
                continue;
            }
            $this->line('- '.$this->formatDiff($diff));
        }
    }

    /**
     * @param  array<string,mixed>  $diff
     */
    private function formatDiff(array $diff): string
    {
        if (($diff['field'] ?? null) === 'partial_facets') {
            return sprintf(
                '%s partial_facets missing_from_stamp=[%s] extra_in_stamp=[%s]',
                (string) ($diff['path'] ?? ''),
                implode(', ', (array) ($diff['missing_from_stamp'] ?? [])),
                implode(', ', (array) ($diff['extra_in_stamp'] ?? [])),
            );
        }

        return sprintf(
            '%s %s expected=%s actual=%s',
            (string) ($diff['path'] ?? ''),
            (string) ($diff['field'] ?? ''),
            $this->stringify($diff['expected'] ?? null),
            $this->stringify($diff['actual'] ?? null),
        );
    }

    private function stringify(mixed $value): string
    {
        if (is_scalar($value) || $value === null) {
            return (string) $value;
        }

        return json_encode($value, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE | JSON_THROW_ON_ERROR);
    }

    private function relativePath(string $path): string
    {
        $base = rtrim(base_path(), DIRECTORY_SEPARATOR).DIRECTORY_SEPARATOR;

        return str_starts_with($path, $base) ? substr($path, strlen($base)) : $path;
    }
}
