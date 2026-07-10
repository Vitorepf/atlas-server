<?php

declare(strict_types=1);

namespace App\Services\Ai\Context;

/**
 * Applies RRF fusion candidate order onto AOBG pack sections.
 *
 * Fusion refs match AtlasRetrievalFusionService:
 * - code/memory: item id (or memory content_hash fallback)
 * - reality: implode('>', chain node ids)
 */
final class AtlasFusionInjectionApplier
{
    /**
     * @param  array<string,mixed>  $pack
     * @return array<string,mixed>
     */
    public function apply(array $pack): array
    {
        $candidates = array_values((array) data_get($pack, 'retrieval_fusion.candidates', []));
        if ($candidates === []) {
            if (! isset($pack['retrieval_fusion']) || ! is_array($pack['retrieval_fusion'])) {
                $pack['retrieval_fusion'] = [];
            }
            $pack['retrieval_fusion']['applied_to_sections'] = false;

            return $pack;
        }

        $orderBySource = [
            'code' => [],
            'memory' => [],
            'reality' => [],
        ];
        foreach ($candidates as $candidate) {
            if (! is_array($candidate)) {
                continue;
            }
            $source = (string) ($candidate['source'] ?? '');
            $ref = trim((string) ($candidate['ref'] ?? ''));
            if ($ref === '' || ! isset($orderBySource[$source])) {
                continue;
            }
            $orderBySource[$source][] = $ref;
        }

        $pack['code_graph'] = $this->reorderById((array) ($pack['code_graph'] ?? []), $orderBySource['code']);
        $pack['memory'] = $this->reorderMemory((array) ($pack['memory'] ?? []), $orderBySource['memory']);
        $pack['reality_graph_paths'] = $this->reorderReality(
            (array) ($pack['reality_graph_paths'] ?? []),
            $orderBySource['reality'],
        );
        if (! isset($pack['retrieval_fusion']) || ! is_array($pack['retrieval_fusion'])) {
            $pack['retrieval_fusion'] = [];
        }
        $pack['retrieval_fusion']['applied_to_sections'] = true;

        return $pack;
    }

    /**
     * @param  list<array<string,mixed>|mixed>  $items
     * @param  list<string>  $preferredRefs
     * @return list<array<string,mixed>>
     */
    private function reorderById(array $items, array $preferredRefs): array
    {
        return $this->reorder($items, $preferredRefs, static function (array $item): string {
            return trim((string) ($item['id'] ?? ''));
        });
    }

    /**
     * @param  list<array<string,mixed>|mixed>  $items
     * @param  list<string>  $preferredRefs
     * @return list<array<string,mixed>>
     */
    private function reorderMemory(array $items, array $preferredRefs): array
    {
        return $this->reorder($items, $preferredRefs, static function (array $item): string {
            $ref = trim((string) ($item['id'] ?? $item['content_hash'] ?? ''));
            if ($ref !== '') {
                return $ref;
            }
            $title = trim((string) ($item['title'] ?? ''));

            return $title !== '' ? hash('sha256', $title) : '';
        });
    }

    /**
     * @param  list<array<string,mixed>|mixed>  $paths
     * @param  list<string>  $preferredRefs
     * @return list<array<string,mixed>>
     */
    private function reorderReality(array $paths, array $preferredRefs): array
    {
        return $this->reorder($paths, $preferredRefs, static function (array $path): string {
            $chain = array_values(array_filter((array) ($path['chain'] ?? []), 'is_array'));
            $ids = array_values(array_filter(array_map(
                static fn (array $node): string => trim((string) ($node['id'] ?? '')),
                $chain,
            )));

            return $ids === [] ? '' : implode('>', $ids);
        });
    }

    /**
     * @param  list<array<string,mixed>|mixed>  $items
     * @param  list<string>  $preferredRefs
     * @param  callable(array<string,mixed>): string  $refOf
     * @return list<array<string,mixed>>
     */
    private function reorder(array $items, array $preferredRefs, callable $refOf): array
    {
        $normalized = [];
        foreach ($items as $item) {
            if (is_array($item)) {
                $normalized[] = $item;
            }
        }
        if ($preferredRefs === [] || $normalized === []) {
            return $normalized;
        }

        $byRef = [];
        foreach ($normalized as $item) {
            $ref = $refOf($item);
            if ($ref !== '' && ! isset($byRef[$ref])) {
                $byRef[$ref] = $item;
            }
        }

        $ordered = [];
        $seen = [];
        foreach ($preferredRefs as $ref) {
            if (isset($byRef[$ref]) && ! isset($seen[$ref])) {
                $ordered[] = $byRef[$ref];
                $seen[$ref] = true;
            }
        }
        foreach ($normalized as $item) {
            $ref = $refOf($item);
            if ($ref !== '' && isset($seen[$ref])) {
                continue;
            }
            $ordered[] = $item;
            if ($ref !== '') {
                $seen[$ref] = true;
            }
        }

        return $ordered;
    }
}
