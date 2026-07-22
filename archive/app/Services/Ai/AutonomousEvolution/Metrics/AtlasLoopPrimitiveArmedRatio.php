<?php

declare(strict_types=1);

namespace App\Services\Ai\AutonomousEvolution\Metrics;

use DateInterval;
use DateTimeImmutable;
use DateTimeZone;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use Illuminate\Support\Facades\Storage;
use RecursiveDirectoryIterator;
use RecursiveIteratorIterator;
use Throwable;

final class AtlasLoopPrimitiveArmedRatio
{
    private const SCHEMA = 'atlas.loop.primitive_armed_ratio.v1';

    private const WINDOW_DAYS = 60;

    /**
     * @return array{
     *   schema:string,
     *   built:int,
     *   armed:int,
     *   ratio:float,
     *   unarmed_sample:list<string>,
     *   window_days:int,
     *   computed_at:string
     * }
     */
    public function measure(?DateTimeImmutable $now = null): array
    {
        $now ??= new DateTimeImmutable('now', new DateTimeZone('UTC'));
        $built = $this->discoverBuiltPrimitives(base_path('app/Services/Ai/AutonomousEvolution'));
        $armed = $this->discoverArmed($built, $now);

        return $this->buildMetric($built, $armed, $now);
    }

    /**
     * @return list<string>
     */
    private function discoverBuiltPrimitives(string $root): array
    {
        if (! is_dir($root)) {
            return [];
        }

        $built = [];
        $iterator = new RecursiveIteratorIterator(new RecursiveDirectoryIterator($root));
        foreach ($iterator as $file) {
            if (! $file->isFile() || $file->getExtension() !== 'php') {
                continue;
            }

            $contents = @file_get_contents($file->getPathname());
            if (! is_string($contents)) {
                continue;
            }

            if (preg_match('/namespace\s+([^;]+);/m', $contents, $namespace) !== 1) {
                continue;
            }
            if (preg_match('/final\s+class\s+([A-Za-z_][A-Za-z0-9_]*)/m', $contents, $class) !== 1) {
                continue;
            }

            $name = $class[1];
            if (! str_starts_with($name, 'AtlasLoop') && ! str_ends_with($name, 'Service')) {
                continue;
            }

            $built[] = trim($namespace[1]).'\\'.$name;
        }

        $built = array_values(array_unique($built));
        sort($built);

        return $built;
    }

    /**
     * @param  list<string>  $built
     * @return list<string>
     */
    private function discoverArmed(array $built, DateTimeImmutable $now): array
    {
        $cutoff = $now->sub(new DateInterval('P'.self::WINDOW_DAYS.'D'));
        $evidence = array_merge(
            $this->databaseEvidence('atlas_loop_origination_outcomes', $cutoff),
            $this->databaseEvidence('atlas_loop_delivery_contracts', $cutoff),
            $this->projectionOutcomeEvidence($cutoff)
        );

        $armed = [];
        foreach ($built as $fqcn) {
            $short = class_basename($fqcn);
            foreach ($evidence as $text) {
                if ($text === '') {
                    continue;
                }
                if (str_contains($text, $fqcn) || str_contains($text, $short)) {
                    $armed[] = $fqcn;
                    break;
                }
            }
        }

        $armed = array_values(array_unique($armed));
        sort($armed);

        return $armed;
    }

    /**
     * @return list<string>
     */
    private function databaseEvidence(string $table, DateTimeImmutable $cutoff): array
    {
        if (! Schema::hasTable($table)) {
            return [];
        }

        $columns = Schema::getColumnListing($table);
        $selected = array_values(array_intersect(
            ['payload', 'target_path', 'changed_symbols', 'consumers', 'commit_sha', 'proposal_id', 'shape_token'],
            $columns
        ));
        if ($selected === []) {
            return [];
        }

        $rows = DB::table($table)
            ->where('created_at', '>=', $cutoff->format('Y-m-d H:i:s'))
            ->get($selected);

        $evidence = [];
        foreach ($rows as $row) {
            $parts = [];
            foreach ($selected as $column) {
                $value = $row->{$column} ?? null;
                if (is_string($value)) {
                    $parts[] = $value;
                } elseif (is_array($value)) {
                    $parts[] = json_encode($value, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE) ?: '';
                } elseif ($value !== null) {
                    $parts[] = (string) json_encode($value, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE);
                }
            }
            $evidence[] = implode(' ', array_filter($parts, static fn (string $part): bool => $part !== ''));
        }

        return $evidence;
    }

    /**
     * @return list<string>
     */
    private function projectionOutcomeEvidence(DateTimeImmutable $cutoff): array
    {
        try {
            $disk = Storage::disk('local');
            $evidence = [];
            foreach ($disk->files('atlas/loop/projection-outcomes') as $path) {
                $timestamp = $disk->lastModified($path);
                if ($timestamp < $cutoff->getTimestamp()) {
                    continue;
                }
                $decoded = json_decode((string) $disk->get($path), true);
                $target = is_array($decoded) ? ($decoded['target'] ?? '') : '';
                if (is_string($target) && $target !== '') {
                    $evidence[] = $target;
                }
            }

            return $evidence;
        } catch (Throwable) {
            return [];
        }
    }

    /**
     * @param  list<string>  $built
     * @param  list<string>  $armed
     * @return array{
     *   schema:string,
     *   built:int,
     *   armed:int,
     *   ratio:float,
     *   unarmed_sample:list<string>,
     *   window_days:int,
     *   computed_at:string
     * }
     */
    private function buildMetric(array $built, array $armed, DateTimeImmutable $now): array
    {
        $armedLookup = array_fill_keys($armed, true);
        $unarmed = array_values(array_filter($built, static fn (string $fqcn): bool => ! isset($armedLookup[$fqcn])));

        return [
            'schema' => self::SCHEMA,
            'built' => count($built),
            'armed' => count($armed),
            'ratio' => count($built) === 0 ? 0.0 : (float) (count($armed) / count($built)),
            'unarmed_sample' => array_slice($unarmed, 0, 20),
            'window_days' => self::WINDOW_DAYS,
            'computed_at' => $now->setTimezone(new DateTimeZone('UTC'))->format(DATE_ATOM),
        ];
    }
}
