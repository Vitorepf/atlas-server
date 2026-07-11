<?php

declare(strict_types=1);

namespace App\Services\Ai\Compaction;

use App\Models\AiSessionState;
use App\Services\Ai\LongHorizon\AtlasLongHorizonCanon;
use Illuminate\Support\Str;

/**
 * Pure extractor: derives long-horizon must_keep_items from live session
 * state without LLM calls. Implements the filter from
 * atlas-long-horizon-intelligence-layer.md (~327):
 * decision, blocker, dod, risk_critical.
 */
final class CompactionMustKeepExtractor
{
    public const MUST_KEEP_SOURCE_CALLER = 'caller';

    public const MUST_KEEP_SOURCE_EXTRACTED = 'extracted';

    public const MUST_KEEP_SOURCE_NONE = 'none';

    public const COVERAGE_STATUS_VERIFIED = 'verified';

    public const COVERAGE_STATUS_VACUOUS = 'vacuous';

    /** @var list<string> */
    public const LIVE_SESSION_SCOPE_TYPES = [
        AtlasLongHorizonCanon::SCOPE_TYPE_THREAD,
        AtlasLongHorizonCanon::SCOPE_TYPE_DEV_SESSION,
        AtlasLongHorizonCanon::SCOPE_TYPE_DEV_RUN,
        AtlasLongHorizonCanon::SCOPE_TYPE_DEV_WORKSTREAM,
    ];

    /**
     * @return list<array{id:string,kind:string,digest:?string,payload:mixed}>
     */
    public function extract(?AiSessionState $state): array
    {
        if ($state === null) {
            return [];
        }

        $items = [];

        foreach (array_values($state->decisions ?? []) as $index => $entry) {
            $mapped = $this->mapStateItem($entry, 'decision', 'decision', $index);
            if ($mapped !== null) {
                $items[] = $mapped;
            }
        }

        foreach (array_values($state->open_loops ?? []) as $index => $entry) {
            $mapped = $this->mapStateItem($entry, 'blocker', 'open_loop', $index);
            if ($mapped !== null) {
                $items[] = $mapped;
            }
        }

        foreach (array_values($state->next_steps ?? []) as $index => $entry) {
            $mapped = $this->mapStateItem($entry, 'dod', 'next_step', $index);
            if ($mapped !== null) {
                $items[] = $mapped;
            }
        }

        foreach (array_values($state->constraints ?? []) as $index => $entry) {
            $mapped = $this->mapStateItem($entry, 'risk_critical', 'constraint', $index);
            if ($mapped !== null) {
                $items[] = $mapped;
            }
        }

        return $items;
    }

    public static function scopeHasLiveSessionState(string $scopeType): bool
    {
        return in_array($scopeType, self::LIVE_SESSION_SCOPE_TYPES, true);
    }

    /**
     * @param  list<array{id:string,kind:string,digest:?string,payload:mixed}>  $callerItems
     * @param  list<array{id:string,kind:string,digest:?string,payload:mixed}>  $extractedItems
     */
    public function resolveMustKeepSource(
        string $scopeType,
        array $callerItems,
        array $extractedItems,
    ): string {
        if (! self::scopeHasLiveSessionState($scopeType)) {
            return $callerItems === [] ? self::MUST_KEEP_SOURCE_NONE : self::MUST_KEEP_SOURCE_CALLER;
        }

        if ($callerItems === [] && $extractedItems === []) {
            return self::MUST_KEEP_SOURCE_NONE;
        }

        if ($callerItems === []) {
            return self::MUST_KEEP_SOURCE_EXTRACTED;
        }

        return self::MUST_KEEP_SOURCE_CALLER;
    }

    /**
     * @param  list<array{id:string,kind:string,digest:?string,payload:mixed}>  $callerItems
     * @param  list<array{id:string,kind:string,digest:?string,payload:mixed}>  $extractedItems
     * @return list<array{id:string,kind:string,digest:?string,payload:mixed}>
     */
    public function mergeMustKeepItems(array $callerItems, array $extractedItems): array
    {
        $byId = [];

        foreach ($extractedItems as $item) {
            $byId[$item['id']] = $item;
        }

        foreach ($callerItems as $item) {
            $byId[$item['id']] = $item;
        }

        return array_values($byId);
    }

    /**
     * @return array{id:string,kind:string,digest:?string,payload:mixed}|null
     */
    private function mapStateItem(mixed $entry, string $kind, string $prefix, int $index): ?array
    {
        if (! is_array($entry)) {
            return null;
        }

        $text = trim((string) ($entry['text'] ?? $entry['value'] ?? ''));
        if ($text === '') {
            return null;
        }

        $id = isset($entry['id']) && is_string($entry['id']) && trim($entry['id']) !== ''
            ? trim($entry['id'])
            : "extracted:{$prefix}:{$index}";

        return [
            'id' => $id,
            'kind' => $kind,
            'digest' => Str::limit($text, 280, '...'),
            'payload' => $entry,
        ];
    }
}
