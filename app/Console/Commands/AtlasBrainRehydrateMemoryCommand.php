<?php

declare(strict_types=1);

namespace App\Console\Commands;

use App\Models\AtlasMemoryEntry;
use App\Services\Ai\Memory\AtlasMemoryRegistryService;
use Illuminate\Console\Command;
use Throwable;

/**
 * D1 (Obra #18 Frente D) — `atlas:brain:rehydrate-memory`.
 *
 * Re-hydrates stub memory rows from a curated map: stub → real body (o quê +
 * PORQUÊ + quando se aplica + evidence ref), a real priority, and the wiper
 * truncation marker removed. Writes through {@see AtlasMemoryRegistryService::curate()},
 * so every re-hydration is SIS8 journal-first — REVERSIBLE by atlas:brain:replay.
 *
 * The map is DATA, not code: entries keyed by memory id carry the authored body
 * + priority. The command is the mechanism; the map is the content.
 *
 * ponytail: matches by id (the map is generated from a live dump). If a row is
 * absent (already re-hydrated elsewhere / archived) it is skipped, never minted —
 * re-hydration never creates memory, only enriches what exists.
 */
class AtlasBrainRehydrateMemoryCommand extends Command
{
    protected $signature = 'atlas:brain:rehydrate-memory
        {map : path to the re-hydration JSON map ({entries:[{id,body,summary?,title?,priority,confidence?,evidence_refs?}]})}
        {--apply : write the re-hydrations (default: dry-run)}
        {--json : machine-readable output}';

    protected $description = 'Re-hydrate stub memories to real bodies (o quê+porquê+evidência), reversibly (D1).';

    public function handle(AtlasMemoryRegistryService $registry): int
    {
        $mapPath = (string) $this->argument('map');
        $abs = str_starts_with($mapPath, '/') ? $mapPath : base_path($mapPath);
        if (! is_file($abs)) {
            return $this->bail("map not found: {$mapPath}");
        }

        try {
            $map = json_decode((string) file_get_contents($abs), true, 512, JSON_THROW_ON_ERROR);
        } catch (Throwable $e) {
            return $this->bail('map is not valid JSON: '.$e->getMessage());
        }

        $entries = is_array($map['entries'] ?? null) ? $map['entries'] : [];
        $apply = (bool) $this->option('apply');
        $applied = 0;
        $skipped = 0;
        $invalidEvidenceRefs = 0;
        $truncatedAfter = 0;

        foreach ($entries as $entry) {
            $id = trim((string) ($entry['id'] ?? ''));
            $body = trim((string) ($entry['body'] ?? ''));
            if ($id === '' || $body === '') {
                $skipped++;

                continue;
            }

            $row = AtlasMemoryEntry::query()->find($id);
            if ($row === null) {
                $skipped++;

                continue;
            }

            $evidenceRefs = $this->evidenceRefs($entry);
            $invalidRefs = array_values(array_filter(
                $evidenceRefs,
                fn (string $ref): bool => ! $this->evidenceRefResolves($ref),
            ));
            if ($invalidRefs !== []) {
                $invalidEvidenceRefs += count($invalidRefs);
                $skipped++;

                continue;
            }

            if ($apply) {
                $attributes = ['body' => $body];
                if (isset($entry['title']) && trim((string) $entry['title']) !== '') {
                    $attributes['title'] = trim((string) $entry['title']);
                }
                if (isset($entry['summary']) && trim((string) $entry['summary']) !== '') {
                    $attributes['summary'] = trim((string) $entry['summary']);
                }
                if (isset($entry['priority'])) {
                    $attributes['priority'] = (int) $entry['priority'];
                }
                if (isset($entry['confidence'])) {
                    $attributes['confidence'] = (float) $entry['confidence'];
                }
                if ($evidenceRefs !== []) {
                    $metadata = (array) ($row->metadata ?? []);
                    $metadata['rehydration_evidence_refs'] = $evidenceRefs;
                    $attributes['metadata'] = $metadata;
                }
                $registry->curate($row, $attributes);
            }

            if ($this->looksTruncated($body)) {
                $truncatedAfter++;
            }
            $applied++;
        }

        return $this->report([
            'ok' => true,
            'apply' => $apply,
            'entries_in_map' => count($entries),
            'rehydrated' => $applied,
            'skipped' => $skipped,
            'invalid_evidence_refs' => $invalidEvidenceRefs,
            'bodies_still_truncated' => $truncatedAfter,
        ]);
    }

    private function looksTruncated(string $body): bool
    {
        // The wiper restore marker only — a literal "..." is a legitimate CLI
        // placeholder (e.g. atlas:ai:place-feature "..."), not a truncation.
        return str_contains($body, "\u{2026}")
            || str_contains($body, 'corpo pode estar truncado');
    }

    /**
     * @param  array<string,mixed>  $entry
     * @return list<string>
     */
    private function evidenceRefs(array $entry): array
    {
        return array_values(array_unique(array_filter(array_map(
            static fn (mixed $ref): string => is_string($ref) ? trim($ref) : '',
            (array) ($entry['evidence_refs'] ?? []),
        ), static fn (string $ref): bool => $ref !== '')));
    }

    private function evidenceRefResolves(string $ref): bool
    {
        if (! str_starts_with($ref, 'docs/engineering-knowledge-base/') || str_contains($ref, '..')) {
            return false;
        }

        return is_file(base_path($ref));
    }

    /**
     * @param  array<string,mixed>  $payload
     */
    private function report(array $payload, int $exit = self::SUCCESS): int
    {
        if ($this->option('json')) {
            $this->line((string) json_encode($payload, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE));

            return $exit;
        }
        foreach ($payload as $key => $value) {
            $this->line(sprintf('  %-24s %s', $key, is_scalar($value) ? var_export($value, true) : json_encode($value)));
        }

        return $exit;
    }

    private function bail(string $message): int
    {
        $this->error($message);

        return self::FAILURE;
    }
}
