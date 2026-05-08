<?php

namespace App\Services\Ai\Kernel\Architecture;

final class AtlasApNumberRegistryAudit
{
    private const SCHEMA_VERSION = 'atlas.ap_number_registry_audit.v1';

    /**
     * @return array<string,mixed>
     */
    public function audit(?string $docsApPath = null): array
    {
        $docsApPath ??= base_path('docs/ap');
        $files = glob(rtrim($docsApPath, DIRECTORY_SEPARATOR).DIRECTORY_SEPARATOR.'AP-*.md') ?: [];
        sort($files);

        $entries = [];
        $numbers = [];
        $slugs = [];
        $malformed = [];

        foreach ($files as $file) {
            $basename = basename($file);
            if (! preg_match('/^AP-(\d+)-([a-z0-9][a-z0-9-]*)\.md$/', $basename, $matches)) {
                $malformed[] = [
                    'path' => $this->relativePath($file),
                    'filename' => $basename,
                    'reason' => 'filename_must_match_AP_number_slug_md',
                ];

                continue;
            }

            $number = (int) $matches[1];
            $slug = $matches[2];
            $entry = [
                'ap' => 'AP-'.$number,
                'number' => $number,
                'slug' => $slug,
                'filename' => $basename,
                'path' => $this->relativePath($file),
                'title' => $this->title($file),
            ];

            $entries[] = $entry;
            $numbers[$number][] = $entry;
            $slugs[$slug][] = $entry;
        }

        $duplicateNumbers = $this->duplicates($numbers);
        $duplicateSlugs = $this->duplicates($slugs);
        $usedNumbers = array_keys($numbers);
        sort($usedNumbers);

        return [
            'schema_version' => self::SCHEMA_VERSION,
            'status' => $duplicateNumbers === [] && $duplicateSlugs === [] && $malformed === [] ? 'ok' : 'attention',
            'mode' => 'read_only_audit',
            'authority' => 'documentation_number_registry_only_no_file_writes',
            'docs_ap_path' => $this->relativePath($docsApPath),
            'ap_count' => count($entries),
            'duplicate_number_count' => count($duplicateNumbers),
            'duplicate_slug_count' => count($duplicateSlugs),
            'malformed_count' => count($malformed),
            'duplicate_numbers' => $duplicateNumbers,
            'duplicate_slugs' => $duplicateSlugs,
            'malformed_files' => $malformed,
            'number_ranges' => $this->numberRanges($usedNumbers),
            'next_suggested_number' => $usedNumbers === [] ? 1 : max($usedNumbers) + 1,
            'entries' => $entries,
            'guardrails' => [
                'writes_files' => false,
                'renumbers_files' => false,
                'changes_static_scanner' => false,
                'blocks_on_duplicate_numbers' => true,
                'blocks_on_malformed_filenames' => true,
            ],
        ];
    }

    /**
     * @param  array<int|string,array<int,array<string,mixed>>>  $groups
     * @return array<int,array<string,mixed>>
     */
    private function duplicates(array $groups): array
    {
        $duplicates = [];

        foreach ($groups as $key => $entries) {
            if (count($entries) < 2) {
                continue;
            }

            $duplicates[] = [
                'key' => (string) $key,
                'count' => count($entries),
                'files' => array_map(
                    fn (array $entry): string => (string) $entry['path'],
                    $entries,
                ),
            ];
        }

        return $duplicates;
    }

    /**
     * @param  array<int,int>  $numbers
     * @return array<int,array{start:int,end:int,count:int}>
     */
    private function numberRanges(array $numbers): array
    {
        if ($numbers === []) {
            return [];
        }

        $ranges = [];
        $start = $numbers[0];
        $end = $numbers[0];

        foreach (array_slice($numbers, 1) as $number) {
            if ($number === $end + 1) {
                $end = $number;

                continue;
            }

            $ranges[] = ['start' => $start, 'end' => $end, 'count' => $end - $start + 1];
            $start = $number;
            $end = $number;
        }

        $ranges[] = ['start' => $start, 'end' => $end, 'count' => $end - $start + 1];

        return $ranges;
    }

    private function title(string $file): ?string
    {
        $handle = @fopen($file, 'r');
        if ($handle === false) {
            return null;
        }

        try {
            while (($line = fgets($handle)) !== false) {
                $line = trim($line);
                if ($line === '') {
                    continue;
                }
                if (str_starts_with($line, 'title:')) {
                    return trim(substr($line, strlen('title:')));
                }
                if (str_starts_with($line, '# ')) {
                    return trim(substr($line, 2));
                }
            }
        } finally {
            fclose($handle);
        }

        return null;
    }

    private function relativePath(string $path): string
    {
        $base = rtrim(base_path(), DIRECTORY_SEPARATOR).DIRECTORY_SEPARATOR;
        $normalized = str_replace('\\', '/', $path);
        $normalizedBase = str_replace('\\', '/', $base);

        return str_starts_with($normalized, $normalizedBase)
            ? substr($normalized, strlen($normalizedBase))
            : $normalized;
    }
}
