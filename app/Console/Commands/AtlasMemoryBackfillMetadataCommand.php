<?php

declare(strict_types=1);

namespace App\Console\Commands;

use App\Models\AtlasMemoryEntry;
use App\Services\Ai\Memory\AtlasMemoryRegistryService;
use App\Services\Engineering\CodeGraph\CrossDomainTaxonomyMap;
use Illuminate\Console\Command;

final class AtlasMemoryBackfillMetadataCommand extends Command
{
    private const SOURCE = 'atlas:memory:backfill-metadata';

    protected $signature = 'atlas:memory:backfill-metadata
        {--apply : write metadata updates through AtlasMemoryRegistryService::curate()}
        {--json : machine-readable output}';

    protected $description = 'Backfill memory metadata paths/domains from cited, resolvable content (dry-run by default).';

    public function handle(AtlasMemoryRegistryService $registry, CrossDomainTaxonomyMap $taxonomy): int
    {
        $apply = (bool) $this->option('apply');
        $scanned = 0;
        $wouldUpdate = 0;
        $updated = 0;
        $planned = [];

        AtlasMemoryEntry::query()
            ->active()
            ->orderBy('id')
            ->chunkById(100, function ($entries) use ($apply, $registry, $taxonomy, &$scanned, &$wouldUpdate, &$updated, &$planned): void {
                foreach ($entries as $entry) {
                    $scanned++;
                    $metadata = (array) ($entry->metadata ?? []);

                    $paths = $this->mergedStrings(
                        (array) ($metadata['paths'] ?? []),
                        $this->extractExistingRepoPaths($this->entryText($entry)),
                    );
                    $domains = $this->mergedStrings(
                        (array) ($metadata['domains'] ?? []),
                        $this->extractDomains($entry, $taxonomy),
                    );

                    if ($paths === (array) ($metadata['paths'] ?? [])
                        && $domains === (array) ($metadata['domains'] ?? [])
                        && ($metadata['backfill_source'] ?? null) === self::SOURCE) {
                        continue;
                    }
                    if ($paths === [] && $domains === []) {
                        continue;
                    }

                    $wouldUpdate++;
                    $next = array_merge($metadata, [
                        'backfill_source' => self::SOURCE,
                        'backfilled_at' => now()->toJSON(),
                    ]);
                    if ($paths !== []) {
                        $next['paths'] = $paths;
                    }
                    if ($domains !== []) {
                        $next['domains'] = $domains;
                    }

                    $planned[] = [
                        'id' => (string) $entry->id,
                        'paths' => $paths,
                        'domains' => $domains,
                    ];

                    if ($apply) {
                        $registry->curate($entry, [
                            'metadata' => $next,
                            'curation_note' => self::SOURCE,
                        ]);
                        $updated++;
                    }
                }
            });

        return $this->report([
            'ok' => true,
            'apply' => $apply,
            'scanned' => $scanned,
            'would_update' => $wouldUpdate,
            'updated' => $updated,
            'planned' => $planned,
        ]);
    }

    private function entryText(AtlasMemoryEntry $entry): string
    {
        return implode("\n", array_filter([
            $entry->title,
            $entry->summary,
            $entry->body,
            $entry->redacted_title,
            $entry->redacted_summary,
            $entry->redacted_body,
            implode(' ', array_filter((array) ($entry->tags ?? []), 'is_string')),
            json_encode((array) ($entry->metadata ?? []), JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE),
        ], static fn (mixed $value): bool => is_string($value) && trim($value) !== ''));
    }

    /**
     * @return list<string>
     */
    private function extractExistingRepoPaths(string $text): array
    {
        preg_match_all(
            '~(?<![\pL\pN_])(?:app|tests|docs|config|routes|database|resources|scripts)/[A-Za-z0-9_./-]+~u',
            $text,
            $matches,
        );

        $paths = [];
        foreach ($matches[0] ?? [] as $match) {
            $path = rtrim($match, ".,;:!?)]}'\"`");
            if ($path === '' || str_contains($path, '..')) {
                continue;
            }
            if (file_exists(base_path($path))) {
                $paths[$path] = true;
            }
        }

        return array_slice(array_keys($paths), 0, 10);
    }

    /**
     * @return list<string>
     */
    private function extractDomains(AtlasMemoryEntry $entry, CrossDomainTaxonomyMap $taxonomy): array
    {
        $raw = [];
        foreach ((array) ($entry->tags ?? []) as $tag) {
            if (is_string($tag)) {
                $raw[] = $tag;
            }
        }
        $metadata = (array) ($entry->metadata ?? []);
        foreach (['domain', 'domains'] as $field) {
            foreach ((array) ($metadata[$field] ?? []) as $value) {
                if (is_string($value)) {
                    $raw[] = $value;
                }
            }
            if (is_string($metadata[$field] ?? null)) {
                $raw[] = (string) $metadata[$field];
            }
        }
        foreach ($this->domainTokens($this->entryText($entry)) as $token) {
            $raw[] = $token;
        }

        $resolved = [];
        foreach ($raw as $candidate) {
            $canonical = $taxonomy->canonical($candidate);
            if ($canonical !== null) {
                $resolved[$canonical] = true;
            }
        }

        return array_slice(array_keys($resolved), 0, 5);
    }

    /**
     * @return list<string>
     */
    private function domainTokens(string $text): array
    {
        $tokens = preg_split('/[^a-z0-9_]+/i', strtolower($text)) ?: [];

        return array_values(array_unique(array_filter($tokens, static fn (string $token): bool => strlen($token) >= 3)));
    }

    /**
     * @param  array<int,mixed>  $existing
     * @param  list<string>  $discovered
     * @return list<string>
     */
    private function mergedStrings(array $existing, array $discovered): array
    {
        $merged = [];
        foreach (array_merge($existing, $discovered) as $value) {
            if (is_string($value) && trim($value) !== '') {
                $merged[trim($value)] = true;
            }
        }

        return array_values(array_keys($merged));
    }

    /**
     * @param  array<string,mixed>  $payload
     */
    private function report(array $payload): int
    {
        if ($this->option('json')) {
            $this->line((string) json_encode($payload, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE));

            return self::SUCCESS;
        }

        foreach ($payload as $key => $value) {
            $this->line(sprintf('  %-16s %s', $key, is_scalar($value) ? var_export($value, true) : json_encode($value)));
        }

        return self::SUCCESS;
    }
}
