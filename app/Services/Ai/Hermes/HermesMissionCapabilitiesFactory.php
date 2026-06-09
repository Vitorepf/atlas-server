<?php

namespace App\Services\Ai\Hermes;

use Illuminate\Support\Str;

/**
 * Builds the canonical capability REQUEST for a Hermes Executive Mission.
 *
 * This factory is the single place that reads what the operator/Atlas asked the
 * Hermes runtime to use (toolsets, skills, MCP servers, hooks, delegation,
 * checkpoints, context references and the high-level feature flags) and folds
 * it into an `atlas.hermes.mission_capabilities.v1` payload. It is intent only:
 * it never decides what Hermes is *allowed* to do — that gate belongs to
 * {@see HermesCapabilityInvocationBuilder}, which diffs this request against the
 * probed capability manifest and the operator policy before emitting any CLI
 * token. The factory also folds the compatibility `payload.hermes.toolsets` CSV into
 * the structured `toolsets` list so older callers keep working unchanged.
 */
class HermesMissionCapabilitiesFactory
{
    /**
     * @param  array<string,mixed>  $payload
     * @param  array<string,mixed>  $provider
     * @return array<string,mixed>
     */
    public function build(array $payload, array $provider): array
    {
        $caps = $this->capabilitiesObject($payload);

        $toolsets = $this->mergeToolsets(
            $this->stringList($caps['toolsets'] ?? null, 24, 80),
            $this->fallbackToolsets($payload, $provider),
        );

        $capabilities = [
            'schema_version' => 'atlas.hermes.mission_capabilities.v1',
            'toolsets' => $toolsets,
            'skills' => $this->stringList($caps['skills'] ?? null, 24, 80),
            'mcp' => $this->stringList($caps['mcp'] ?? null, 24, 80),
            'delegation' => $this->delegation($caps['delegation'] ?? null),
            'hooks' => $this->hooks($caps['hooks'] ?? null),
            'checkpoints' => $this->bool($caps['checkpoints'] ?? null),
            'context_references' => $this->contextReferences($caps['context_references'] ?? null),
            'browser' => $this->bool($caps['browser'] ?? null),
            'code_execution' => $this->bool($caps['code_execution'] ?? null),
            'vision' => $this->bool($caps['vision'] ?? null),
            'image_gen' => $this->bool($caps['image_gen'] ?? null),
            'voice' => $this->bool($caps['voice'] ?? null),
        ];

        $capabilities['requested_capability_ids'] = $this->requestedIds($capabilities);

        return $capabilities;
    }

    /**
     * @param  array<string,mixed>  $caps
     * @return array<int,string>
     */
    private function requestedIds(array $caps): array
    {
        $ids = [];

        foreach ($caps['toolsets'] as $name) {
            $ids[] = 'toolset:'.$name;
        }

        foreach ($caps['skills'] as $name) {
            $ids[] = 'skill:'.$name;
        }

        foreach ($caps['mcp'] as $name) {
            $ids[] = 'mcp_server:'.$name;
        }

        foreach (array_keys($caps['hooks']) as $hook) {
            $ids[] = 'hook:'.$hook;
        }

        if (($caps['delegation']['supported'] ?? false) === true) {
            $ids[] = 'delegation:supported';
        }

        if ($caps['checkpoints'] === true) {
            $ids[] = 'flag:checkpoints';
        }

        foreach ($caps['context_references'] as $reference) {
            $ids[] = 'context_ref:'.$reference['value'];
        }

        if ($caps['browser'] === true) {
            $ids[] = 'toolset:browser';
        }

        if ($caps['code_execution'] === true) {
            $ids[] = 'toolset:code_execution';
        }

        if ($caps['vision'] === true) {
            $ids[] = 'feature:vision';
        }

        if ($caps['image_gen'] === true) {
            $ids[] = 'feature:image_gen';
        }

        if ($caps['voice'] === true) {
            $ids[] = 'feature:voice';
        }

        return array_values(array_unique($ids));
    }

    /**
     * @param  array<string,mixed>  $payload
     * @return array<string,mixed>
     */
    private function capabilitiesObject(array $payload): array
    {
        $caps = data_get($payload, 'hermes.capabilities');

        return is_array($caps) ? $caps : [];
    }

    /**
     * @param  array<string,mixed>  $payload
     * @param  array<string,mixed>  $provider
     * @return array<int,string>
     */
    private function fallbackToolsets(array $payload, array $provider): array
    {
        $fallback = data_get($payload, 'hermes.toolsets') ?: ($provider['toolsets'] ?? null);

        return $this->stringList($fallback, 24, 80);
    }

    /**
     * @param  array<int,string>  $primary
     * @param  array<int,string>  $fallback
     * @return array<int,string>
     */
    private function mergeToolsets(array $primary, array $fallback): array
    {
        return collect(array_merge($primary, $fallback))
            ->filter(fn (string $name): bool => $name !== '')
            ->unique()
            ->values()
            ->all();
    }

    /**
     * @return array<string,mixed>
     */
    private function delegation(mixed $value): array
    {
        if (! is_array($value)) {
            return [];
        }

        $delegation = [
            'supported' => $this->bool($value['supported'] ?? null),
        ];

        $maxDepth = $value['max_depth'] ?? null;
        if (is_numeric($maxDepth) && (int) $maxDepth > 0) {
            $delegation['max_depth'] = (int) $maxDepth;
        }

        $agents = $this->stringList($value['agents'] ?? null, 24, 80);
        if ($agents !== []) {
            $delegation['agents'] = $agents;
        }

        return $delegation;
    }

    /**
     * @return array<string,bool>
     */
    private function hooks(mixed $value): array
    {
        if (! is_array($value)) {
            return [];
        }

        $hooks = [];
        foreach ($value as $key => $enabled) {
            $name = $this->string($key, 60);
            if ($name === null) {
                continue;
            }

            $hooks[$name] = $this->bool($enabled);
        }

        return $hooks;
    }

    /**
     * @return array<int,array<string,mixed>>
     */
    private function contextReferences(mixed $value): array
    {
        $items = is_array($value) ? array_values($value) : [];

        return collect(array_slice($items, 0, 24))
            ->map(fn (mixed $item): ?array => $this->contextReference($item))
            ->filter()
            ->unique(fn (array $reference): string => $reference['type'].'|'.$reference['value'].'|'.($reference['line_range'] ?? ''))
            ->values()
            ->all();
    }

    /**
     * @return array<string,mixed>|null
     */
    private function contextReference(mixed $item): ?array
    {
        if (is_string($item)) {
            $value = $this->string($item, 1000);

            return $value === null ? null : ['type' => 'file', 'value' => $value];
        }

        if (! is_array($item)) {
            return null;
        }

        $value = $this->string($item['value'] ?? $item['path'] ?? null, 1000);
        if ($value === null) {
            return null;
        }

        $reference = [
            'type' => $this->referenceType($item['type'] ?? null),
            'value' => $value,
        ];

        $lineRange = $this->string($item['line_range'] ?? null, 40);
        if ($lineRange !== null) {
            $reference['line_range'] = $lineRange;
        }

        return $reference;
    }

    private function referenceType(mixed $value): string
    {
        $type = $this->string($value, 40);

        return in_array($type, ['file', 'directory', 'symbol', 'url'], true) ? $type : 'file';
    }

    /**
     * @return array<int,string>
     */
    private function stringList(mixed $value, int $limit, int $itemLimit): array
    {
        $items = is_array($value) ? $value : (is_string($value) ? preg_split('/\s*,\s*/', $value) ?: [] : []);

        return collect(array_slice($items, 0, $limit))
            ->map(fn (mixed $item): ?string => $this->string($item, $itemLimit))
            ->filter()
            ->unique()
            ->values()
            ->all();
    }

    private function bool(mixed $value): bool
    {
        if (is_bool($value)) {
            return $value;
        }

        if (is_string($value)) {
            return in_array(strtolower(trim($value)), ['1', 'true', 'yes', 'on'], true);
        }

        return is_numeric($value) && (int) $value === 1;
    }

    private function string(mixed $value, int $limit): ?string
    {
        if (! is_string($value) && ! is_numeric($value)) {
            return null;
        }

        $value = trim((string) $value);

        return $value === '' ? null : Str::limit($value, $limit, '');
    }
}
