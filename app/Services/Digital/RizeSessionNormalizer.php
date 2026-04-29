<?php

namespace App\Services\Digital;

use App\Models\DigitalCategoryMapping;
use App\Support\Metadata;
use Carbon\CarbonImmutable;
use Illuminate\Support\Arr;

class RizeSessionNormalizer
{
    public function normalize(array $payload, ?string $sourceEventId = null, string $origin = 'rize-api-v1'): ?array
    {
        $data = Arr::get($payload, 'data', Arr::get($payload, 'payload', $payload));
        if (! is_array($data)) {
            return null;
        }

        $sourceEventId ??= $this->firstString($data, ['id', 'uuid', 'event_id', 'eventId']);
        $startedAt = $this->firstDate($data, [
            'started_at',
            'startedAt',
            'start_time',
            'startTime',
            'started',
            'start',
            'from',
            'begin',
            'timestamp',
        ]);
        $endedAt = $this->firstDate($data, [
            'ended_at',
            'endedAt',
            'end_time',
            'endTime',
            'ended',
            'end',
            'to',
            'finish',
        ]);

        $durationSeconds = $this->durationSeconds($data);

        if ($startedAt && ! $endedAt && $durationSeconds !== null) {
            $endedAt = $startedAt->addSeconds((int) round($durationSeconds));
        }

        if (! $startedAt || ! $endedAt) {
            return null;
        }

        $durationSeconds ??= max(0, $endedAt->getTimestamp() - $startedAt->getTimestamp());
        $sourceIdentifier = $this->sourceIdentifier($data);
        $sourceName = $this->sourceName($data, $sourceIdentifier);
        $sourceKind = $this->sourceKindFor($data, $sourceIdentifier);
        $mapping = $this->mappingFor($sourceIdentifier, $sourceKind, $startedAt);

        return [
            'client_id' => $this->uuidFromHash('rize-session:'.($sourceEventId ?: json_encode([$sourceIdentifier, $startedAt->toJSON(), $endedAt->toJSON()]))),
            'source' => 'rize',
            'source_event_id' => $sourceEventId,
            'source_identifier' => $sourceIdentifier,
            'source_name' => $sourceName,
            'source_kind' => $sourceKind,
            'category_class_at_time' => $mapping?->category_class,
            'category_label_at_time' => $mapping?->category_label ?? $this->rizeCategoryLabel($data),
            'intentionality' => $mapping?->intentionality ?? 'unknown',
            'started_at' => $startedAt,
            'ended_at' => $endedAt,
            'duration_seconds' => (int) round($durationSeconds),
            'recorded_timezone' => config('services.rize.timezone', config('app.timezone', 'UTC')),
            'focus_mode_active' => $this->firstString($data, ['focus_mode', 'focusMode', 'mode', 'focusSession.name']),
            'project_name' => $this->firstString($data, ['project_name', 'projectName', 'project.name', 'project']),
            'task_name' => $this->firstString($data, ['task_name', 'taskName', 'task.name', 'task']),
            'url_domain' => $this->urlDomain($data),
            'productivity_score' => $this->firstNumber($data, ['productivity_score', 'productivityScore', 'score', 'productivity']),
            'raw_payload' => Metadata::forStorage($payload),
            'metadata' => Metadata::forStorage([
                'normalized_by' => $origin,
                'imported_at' => now()->toJSON(),
            ]),
        ];
    }

    private function mappingFor(string $sourceIdentifier, string $sourceKind, CarbonImmutable $at): ?DigitalCategoryMapping
    {
        return DigitalCategoryMapping::query()
            ->where('source_identifier', $sourceIdentifier)
            ->where(function ($query) use ($sourceKind): void {
                $query->where('source_kind', $sourceKind)->orWhere('source_kind', 'unknown');
            })
            ->where('valid_from', '<=', $at)
            ->where(function ($query) use ($at): void {
                $query->whereNull('valid_until')->orWhere('valid_until', '>', $at);
            })
            ->orderByRaw('CASE WHEN source_kind = ? THEN 0 ELSE 1 END', [$sourceKind])
            ->orderByDesc('valid_from')
            ->first();
    }

    private function sourceIdentifier(array $data): string
    {
        return $this->firstString($data, [
            'source_identifier',
            'sourceIdentifier',
            'app_bundle_id',
            'appBundleId',
            'bundle_id',
            'bundleId',
            'app.bundleId',
            'application.bundleId',
            'app.id',
            'application.id',
            'domain',
            'url_domain',
            'urlDomain',
            'website.domain',
            'site.domain',
            'url',
            'app_name',
            'appName',
            'application.name',
            'name',
            'title',
            'project.name',
            'project',
            'category.name',
            'category',
        ]) ?? 'unknown';
    }

    private function sourceName(array $data, string $sourceIdentifier): string
    {
        return $this->firstString($data, [
            'source_name',
            'sourceName',
            'app_name',
            'appName',
            'application.name',
            'app.name',
            'name',
            'title',
            'domain',
            'website.domain',
            'project.name',
            'project',
            'category.name',
            'category',
        ]) ?? $sourceIdentifier;
    }

    private function sourceKindFor(array $data, string $sourceIdentifier): string
    {
        $kind = $this->firstString($data, ['source_kind', 'sourceKind', 'kind', 'type', 'entityType']);

        if (in_array($kind, ['app', 'domain', 'url', 'project', 'category', 'unknown'], true)) {
            return $kind;
        }

        if ($this->firstString($data, ['project.name', 'project']) !== null) {
            return 'project';
        }

        if (filter_var($sourceIdentifier, FILTER_VALIDATE_URL)) {
            return 'url';
        }

        if (str_contains($sourceIdentifier, '.')) {
            return str_contains($sourceIdentifier, '/') ? 'url' : 'domain';
        }

        return 'app';
    }

    private function rizeCategoryLabel(array $data): ?string
    {
        return $this->firstString($data, [
            'category_label',
            'categoryLabel',
            'category.name',
            'category.title',
            'category',
            'activityCategory.name',
            'activityCategory',
        ]);
    }

    private function urlDomain(array $data): ?string
    {
        $domain = $this->firstString($data, ['url_domain', 'urlDomain', 'domain', 'website.domain', 'site.domain']);
        if ($domain) {
            return $domain;
        }

        $url = $this->firstString($data, ['url', 'website.url']);
        if (! $url) {
            return null;
        }

        $host = parse_url($url, PHP_URL_HOST);

        return is_string($host) && $host !== '' ? $host : null;
    }

    private function durationSeconds(array $data): ?float
    {
        $seconds = $this->firstNumber($data, ['duration_seconds', 'durationSeconds', 'duration_sec', 'durationSec', 'seconds']);
        if ($seconds !== null) {
            return $seconds;
        }

        $minutes = $this->firstNumber($data, ['duration_minutes', 'durationMinutes', 'minutes']);
        if ($minutes !== null) {
            return $minutes * 60;
        }

        $milliseconds = $this->firstNumber($data, ['duration_ms', 'durationMs', 'durationMilliseconds', 'milliseconds']);
        if ($milliseconds !== null) {
            return $milliseconds / 1000;
        }

        $ambiguous = $this->firstNumber($data, ['duration']);
        if ($ambiguous === null) {
            return null;
        }

        return $ambiguous > 86400 ? $ambiguous / 1000 : $ambiguous;
    }

    private function firstString(array $payload, array $paths): ?string
    {
        foreach ($paths as $path) {
            $value = Arr::get($payload, $path);
            if (is_string($value) && trim($value) !== '') {
                return trim($value);
            }
        }

        return null;
    }

    private function firstNumber(array $payload, array $paths): ?float
    {
        foreach ($paths as $path) {
            $value = Arr::get($payload, $path);
            if (is_numeric($value)) {
                return (float) $value;
            }
        }

        return null;
    }

    private function firstDate(array $payload, array $paths): ?CarbonImmutable
    {
        foreach ($paths as $path) {
            $value = Arr::get($payload, $path);
            if (! is_string($value) && ! is_numeric($value)) {
                continue;
            }

            try {
                if (is_numeric($value)) {
                    $timestamp = (int) $value;

                    return $timestamp > 9999999999
                        ? CarbonImmutable::createFromTimestampMs($timestamp)
                        : CarbonImmutable::createFromTimestamp($timestamp);
                }

                return CarbonImmutable::parse((string) $value);
            } catch (\Throwable) {
                continue;
            }
        }

        return null;
    }

    private function uuidFromHash(string $value): string
    {
        $hash = md5($value);

        return sprintf(
            '%s-%s-%s-%s-%s',
            substr($hash, 0, 8),
            substr($hash, 8, 4),
            substr($hash, 12, 4),
            substr($hash, 16, 4),
            substr($hash, 20, 12)
        );
    }
}
