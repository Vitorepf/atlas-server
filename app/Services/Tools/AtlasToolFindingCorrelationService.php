<?php

namespace App\Services\Tools;

use App\Models\AtlasToolFinding;
use App\Models\AtlasToolRun;
use Illuminate\Database\Eloquent\Collection as EloquentCollection;
use Illuminate\Support\Collection;
use Illuminate\Support\Str;

class AtlasToolFindingCorrelationService
{
    public function __construct(private readonly AtlasToolFindingWaiverService $waivers) {}

    /**
     * @param  EloquentCollection<int,AtlasToolRun>|Collection<int,AtlasToolRun>  $runs
     * @return array<string,mixed>
     */
    public function correlate(EloquentCollection|Collection $runs): array
    {
        $groups = [];

        foreach ($runs as $run) {
            foreach ($run->findings as $finding) {
                if (! (bool) $finding->blocks_resolved || $this->waivers->isWaived($finding)) {
                    continue;
                }

                $key = $this->correlationKey($run, $finding);
                if ($key === null) {
                    continue;
                }

                $groups[$key][] = [
                    'run' => $run,
                    'finding' => $finding,
                    'authority_group' => $this->authorityGroup($run),
                    'authority_role' => $this->authorityRole($run),
                ];
            }
        }

        $correlations = [];
        $suppressedFindingIds = [];

        foreach ($groups as $key => $items) {
            if (count($items) < 2) {
                continue;
            }

            usort($items, fn (array $left, array $right): int => $this->rank($left) <=> $this->rank($right));

            $authoritative = $items[0];
            $duplicates = array_slice($items, 1);
            foreach ($duplicates as $duplicate) {
                $suppressedFindingIds[] = (string) $duplicate['finding']->id;
            }

            $finding = $authoritative['finding'];
            $run = $authoritative['run'];
            $correlations[] = [
                'correlation_key' => $key,
                'authority_group' => $authoritative['authority_group'],
                'authoritative_tool_slug' => $run->tool_slug,
                'authoritative_finding_id' => $finding->id,
                'duplicate_finding_ids' => collect($duplicates)->map(fn (array $item): string => (string) $item['finding']->id)->values()->all(),
                'tool_slugs' => collect($items)->map(fn (array $item): string => (string) $item['run']->tool_slug)->unique()->values()->all(),
                'finding_count' => count($items),
                'severity' => $finding->severity,
                'file_path' => $finding->file_path,
                'line' => $finding->line,
                'title' => $finding->title,
            ];
        }

        return [
            'correlations' => $correlations,
            'suppressed_finding_ids' => array_values(array_unique($suppressedFindingIds)),
        ];
    }

    private function correlationKey(AtlasToolRun $run, AtlasToolFinding $finding): ?string
    {
        $authorityGroup = $this->authorityGroup($run);
        $filePath = is_string($finding->file_path) ? trim($finding->file_path) : '';
        $line = is_numeric($finding->line) ? (string) ((int) $finding->line) : '';
        $fingerprint = is_string($finding->fingerprint) ? trim($finding->fingerprint) : '';

        if ($filePath === '' || $line === '') {
            return $fingerprint !== ''
                ? hash('sha256', implode('|', [$authorityGroup, 'fingerprint', $fingerprint]))
                : null;
        }

        $title = Str::of((string) ($finding->title ?: $finding->message ?: 'finding'))
            ->lower()
            ->replaceMatches('/[^a-z0-9]+/', ' ')
            ->squish()
            ->limit(120, '')
            ->toString();

        return hash('sha256', implode('|', [$authorityGroup, $filePath, $line, $title]));
    }

    private function authorityGroup(AtlasToolRun $run): string
    {
        return (string) (
            $run->tool?->authority_group
            ?? data_get($run->policy_decision_json, 'authority_group')
            ?? data_get($run->metadata_json, 'authority_group')
            ?? $run->tool?->category
            ?? $run->tool_slug
        );
    }

    private function authorityRole(AtlasToolRun $run): string
    {
        return (string) (
            $run->tool?->authority_role
            ?? data_get($run->policy_decision_json, 'authority_role')
            ?? data_get($run->metadata_json, 'authority_role')
            ?? 'primary'
        );
    }

    /**
     * @param  array<string,mixed>  $item
     */
    private function rank(array $item): int
    {
        $role = match ((string) $item['authority_role']) {
            'primary' => 0,
            'primary_or_complementary' => 1,
            'complementary' => 2,
            'fallback' => 3,
            'executor' => 4,
            default => 5,
        };

        $severity = match ((string) $item['finding']->severity) {
            'critical' => 0,
            'high' => 1,
            'medium' => 2,
            'low' => 3,
            default => 4,
        };

        return ($role * 10) + $severity;
    }
}
