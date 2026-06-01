<?php

declare(strict_types=1);

namespace App\Services\Ai\SoftwareCompanyStewardship\SelfExpanding;

/**
 * Mechanizes the Self-Construction anti-duplication law: before Atlas builds a
 * new primitive it must prove no existing primitive already covers the
 * requested capability. Pure decision rule — every field is computed from the
 * inputs, with no I/O, persistence or non-determinism.
 */
final class SelfConstructionBuildVsReuseDecider
{
    private const SCHEMA_VERSION = 'atlas.self_construction.build_vs_reuse.v1';

    private const VERDICT_REUSE = 'reuse';

    private const VERDICT_ADAPTER_REQUIRED = 'adapter_required';

    private const VERDICT_BUILD_NEW = 'build_new';

    /**
     * @param  array{capability?: string, new_surface?: ?string, consumed_primitives?: list<string>}  $request
     * @param  list<array{id?: string, capability?: string, owner_doc?: string}>  $existingPrimitives
     * @return array{
     *     schema_version: string,
     *     verdict: string,
     *     matched_primitive: ?string,
     *     reuse_owner_doc: ?string,
     *     overlap_keywords: list<string>,
     *     reason: string
     * }
     */
    public function decide(array $request, array $existingPrimitives): array
    {
        $capabilityKeywords = $this->keywords((string) ($request['capability'] ?? ''));
        $consumed = $this->consumedIds($request);
        $surface = $this->surface($request);

        $best = $this->bestMatch($capabilityKeywords, $existingPrimitives);

        // Rule 1 / 2: the requested capability keyword-overlaps an existing primitive.
        if ($best !== null) {
            $surfaceCovered = $surface === null
                || $this->surfaceCovered($surface, $existingPrimitives);

            if ($surface !== null && ! $surfaceCovered) {
                return $this->result(
                    self::VERDICT_ADAPTER_REQUIRED,
                    $best['id'],
                    $best['owner_doc'],
                    $best['overlap_keywords'],
                    sprintf(
                        'capability overlaps primitive %s but adds uncovered surface "%s"; build an adapter',
                        $best['id'],
                        $surface,
                    ),
                );
            }

            return $this->result(
                self::VERDICT_REUSE,
                $best['id'],
                $best['owner_doc'],
                $best['overlap_keywords'],
                sprintf('reuse existing primitive %s; capability already covered', $best['id']),
            );
        }

        // Rule 3: honest zero overlap and no consumption claim unlocks a fresh build.
        if ($consumed === []) {
            return $this->result(
                self::VERDICT_BUILD_NEW,
                null,
                null,
                [],
                'no overlap with any primitive and no consumed primitives; build new',
            );
        }

        // Rule 4: zero overlap yet a consumption claim. A dishonest empty-overlap
        // plus phantom consumption can never unlock build_new.
        $consumedMatch = $this->firstConsumedPrimitive($consumed, $existingPrimitives);

        if ($consumedMatch !== null) {
            return $this->result(
                self::VERDICT_REUSE,
                $consumedMatch['id'],
                $consumedMatch['owner_doc'],
                [],
                sprintf('consumed primitive %s already exists; reuse it', $consumedMatch['id']),
            );
        }

        return $this->result(
            self::VERDICT_ADAPTER_REQUIRED,
            null,
            null,
            [],
            'phantom consumption claimed without keyword overlap; build an adapter, not a new primitive',
        );
    }

    /**
     * Best-matching primitive by normalized keyword-overlap count. A looser
     * match (fewer overlapping keywords) loses; ties keep the first by input
     * order so the decision is deterministic.
     *
     * @param  list<string>  $capabilityKeywords
     * @param  list<array{id?: string, capability?: string, owner_doc?: string}>  $existingPrimitives
     * @return array{id: string, owner_doc: string, overlap_keywords: list<string>, count: int}|null
     */
    private function bestMatch(array $capabilityKeywords, array $existingPrimitives): ?array
    {
        $best = null;

        foreach ($existingPrimitives as $primitive) {
            $primitiveKeywords = $this->keywords((string) ($primitive['capability'] ?? ''));
            $overlap = $this->overlap($capabilityKeywords, $primitiveKeywords);
            $count = count($overlap);

            if ($count === 0) {
                continue;
            }

            if ($best === null || $count > $best['count']) {
                $best = [
                    'id' => (string) ($primitive['id'] ?? ''),
                    'owner_doc' => (string) ($primitive['owner_doc'] ?? ''),
                    'overlap_keywords' => $overlap,
                    'count' => $count,
                ];
            }
        }

        return $best;
    }

    /**
     * The requested surface is "covered" when every one of its normalized
     * tokens already appears in some existing primitive's capability.
     *
     * @param  list<array{id?: string, capability?: string, owner_doc?: string}>  $existingPrimitives
     */
    private function surfaceCovered(string $surface, array $existingPrimitives): bool
    {
        $surfaceKeywords = $this->keywords($surface);

        if ($surfaceKeywords === []) {
            return true;
        }

        $union = [];

        foreach ($existingPrimitives as $primitive) {
            foreach ($this->keywords((string) ($primitive['capability'] ?? '')) as $keyword) {
                $union[$keyword] = true;
            }
        }

        foreach ($surfaceKeywords as $keyword) {
            if (! isset($union[$keyword])) {
                return false;
            }
        }

        return true;
    }

    /**
     * @param  list<string>  $consumed
     * @param  list<array{id?: string, capability?: string, owner_doc?: string}>  $existingPrimitives
     * @return array{id: string, owner_doc: string}|null
     */
    private function firstConsumedPrimitive(array $consumed, array $existingPrimitives): ?array
    {
        foreach ($consumed as $id) {
            foreach ($existingPrimitives as $primitive) {
                if ((string) ($primitive['id'] ?? '') === $id) {
                    return [
                        'id' => (string) ($primitive['id'] ?? ''),
                        'owner_doc' => (string) ($primitive['owner_doc'] ?? ''),
                    ];
                }
            }
        }

        return null;
    }

    /**
     * @param  array{new_surface?: ?string}  $request
     */
    private function surface(array $request): ?string
    {
        $surface = $request['new_surface'] ?? null;

        if (! is_string($surface)) {
            return null;
        }

        $surface = trim($surface);

        return $surface === '' ? null : $surface;
    }

    /**
     * @param  array{consumed_primitives?: list<string>}  $request
     * @return list<string>
     */
    private function consumedIds(array $request): array
    {
        $consumed = $request['consumed_primitives'] ?? [];

        if (! is_array($consumed)) {
            return [];
        }

        $ids = [];

        foreach ($consumed as $id) {
            if (! is_string($id)) {
                continue;
            }

            $id = trim($id);

            if ($id !== '') {
                $ids[] = $id;
            }
        }

        return $ids;
    }

    /**
     * Normalized keyword set: lowercased alphanumeric tokens, de-duplicated,
     * preserving first-seen order.
     *
     * @return list<string>
     */
    private function keywords(string $text): array
    {
        $tokens = preg_split('/[^a-z0-9]+/', strtolower($text), -1, PREG_SPLIT_NO_EMPTY);

        if ($tokens === false) {
            return [];
        }

        $unique = [];

        foreach ($tokens as $token) {
            $unique[$token] = true;
        }

        return array_keys($unique);
    }

    /**
     * Intersection of two keyword sets, ordered by the first set.
     *
     * @param  list<string>  $left
     * @param  list<string>  $right
     * @return list<string>
     */
    private function overlap(array $left, array $right): array
    {
        $rightSet = array_fill_keys($right, true);
        $shared = [];

        foreach ($left as $keyword) {
            if (isset($rightSet[$keyword])) {
                $shared[] = $keyword;
            }
        }

        return $shared;
    }

    /**
     * @param  list<string>  $overlapKeywords
     * @return array{
     *     schema_version: string,
     *     verdict: string,
     *     matched_primitive: ?string,
     *     reuse_owner_doc: ?string,
     *     overlap_keywords: list<string>,
     *     reason: string
     * }
     */
    private function result(
        string $verdict,
        ?string $matchedPrimitive,
        ?string $reuseOwnerDoc,
        array $overlapKeywords,
        string $reason,
    ): array {
        return [
            'schema_version' => self::SCHEMA_VERSION,
            'verdict' => $verdict,
            'matched_primitive' => $matchedPrimitive,
            'reuse_owner_doc' => $reuseOwnerDoc,
            'overlap_keywords' => $overlapKeywords,
            'reason' => $reason,
        ];
    }
}
