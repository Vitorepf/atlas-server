<?php

declare(strict_types=1);

namespace App\Services\Ai\AcosMax;

use App\Services\Ai\Support\AiValueNormalizer;

/**
 * TETO-10 (§3144-3147) turns review debt into a terminal-first review product:
 * grouped by decision/family, ordered by predicted-revert value, and carrying
 * evidence, diff reference, and a ready reverse command per reversible item.
 */
final class Teto10PredictedRevertReviewDigest
{
    public const SCHEMA_VERSION = 'atlas.acos.teto10.predicted_revert_review_digest.v1';

    public const BAND_RANK = [
        'high' => 0,
        'sweet' => 1,
        'low' => 2,
        'unknown' => 3,
    ];

    public const DEFAULT_LIMIT = 50;

    public const HARD_LIMIT_CAP = 200;

    /**
     * @param  list<array<string,mixed>>  $items
     * @return array<string,mixed>
     */
    public static function compose(array $items, int $limit = self::DEFAULT_LIMIT): array
    {
        $limit = max(1, min(self::HARD_LIMIT_CAP, $limit));
        $normalised = [];
        foreach ($items as $index => $item) {
            $normalised[] = self::normaliseItem($item, $index);
        }

        usort($normalised, self::itemSorter(...));
        $shown = array_slice($normalised, 0, $limit);

        $groups = self::groups($shown);
        $bandCounts = ['high' => 0, 'sweet' => 0, 'low' => 0, 'unknown' => 0];
        foreach ($normalised as $item) {
            $bandCounts[self::band($item['predicted_revert_band'])]++;
        }

        return [
            'schema_version' => self::SCHEMA_VERSION,
            'status' => $normalised === [] ? 'empty' : 'ok',
            'item_count' => count($normalised),
            'shown_item_count' => count($shown),
            'group_count' => count($groups),
            'cap' => $limit,
            'band_order' => ['high', 'sweet', 'low', 'unknown'],
            'band_counts' => $bandCounts,
            'groups' => $groups,
            'pending_flips' => self::flaggedSection($shown, 'pending_flip', 'flip_ref'),
            'batched_asks' => self::flaggedSection($shown, 'batched_ask', 'ask_ref'),
            'source' => [
                'slice' => 'TETO-10',
                'frontier_plan_section' => '3144-3147',
                'markdown_cli_only' => true,
                'ui_created' => false,
                'reorders_by_predicted_revert_band' => true,
                'manual_review_when_reverse_missing' => true,
            ],
        ];
    }

    /**
     * @param  array<string,mixed>  $digest
     */
    public static function renderMarkdown(array $digest): string
    {
        $lines = [
            '# ACOS TETO-10 predicted-revert review digest',
            '',
            '- schema: '.self::inline(self::string($digest['schema_version'] ?? self::SCHEMA_VERSION) ?: self::SCHEMA_VERSION),
            '- status: '.self::plain(self::string($digest['status'] ?? 'unknown') ?: 'unknown'),
            '- items: '.self::string($digest['shown_item_count'] ?? 0).'/'.self::string($digest['item_count'] ?? 0),
            '- order: high -> sweet -> low -> unknown',
            '- surface: markdown/CLI only',
            '',
        ];

        $currentBand = null;
        foreach (AiValueNormalizer::arrayOrEmpty($digest['groups'] ?? null) as $group) {
            if (! is_array($group)) {
                continue;
            }
            $band = self::band($group['highest_predicted_revert_band'] ?? 'unknown');
            if ($band !== $currentBand) {
                $lines[] = '## Predicted revert: '.$band;
                $lines[] = '';
                $currentBand = $band;
            }

            $lines[] = '### '.self::plain(self::string($group['group_key'] ?? 'family:unknown') ?: 'family:unknown');
            $decisionId = self::string($group['decision_id'] ?? '');
            $family = self::string($group['family'] ?? '');
            if ($decisionId !== '' || $family !== '') {
                $lines[] = '- lineage: decision_id='.($decisionId !== '' ? self::plain($decisionId) : 'none')
                    .' family='.($family !== '' ? self::plain($family) : 'unknown');
            }

            foreach (AiValueNormalizer::arrayOrEmpty($group['items'] ?? null) as $item) {
                if (! is_array($item)) {
                    continue;
                }
                $lines[] = '- '.self::plain(self::string($item['id'] ?? 'item') ?: 'item').': '.self::plain(self::string($item['title'] ?? 'untitled') ?: 'untitled');
                $lines[] = '  - band: '.self::plain(self::band($item['predicted_revert_band'] ?? 'unknown'));
                $lines[] = '  - evidence: '.self::plain(implode(', ', array_map(self::string(...), AiValueNormalizer::arrayOrEmpty($item['evidence_refs'] ?? null))));
                $lines[] = '  - diff-ref: '.self::plain(self::string($item['diff_ref'] ?? 'manual_review') ?: 'manual_review');
                $lines[] = '  - reverse: '.self::inline(self::string($item['reverse_command'] ?? 'manual_review') ?: 'manual_review');
                $lines[] = '  - review-mode: '.self::plain(self::string($item['review_mode'] ?? 'manual_review') ?: 'manual_review');
            }
            $lines[] = '';
        }

        $lines = array_merge($lines, self::renderFlaggedSection('Pending flips', AiValueNormalizer::arrayOrEmpty($digest['pending_flips'] ?? null)));
        $lines = array_merge($lines, self::renderFlaggedSection('Batched asks', AiValueNormalizer::arrayOrEmpty($digest['batched_asks'] ?? null)));

        return rtrim(implode(PHP_EOL, $lines)).PHP_EOL;
    }

    /**
     * @param  array<string,mixed>  $item
     * @return array<string,mixed>
     */
    private static function normaliseItem(array $item, int $index): array
    {
        $id = self::string($item['id'] ?? null);
        $decisionId = self::string($item['decision_id'] ?? null);
        $family = self::string($item['family'] ?? null);
        $band = self::band($item['predicted_revert_band'] ?? null);
        $reverse = self::reverseCommand($item);

        return [
            'id' => $id !== '' ? $id : 'item-'.($index + 1),
            'title' => self::firstString($item, ['title', 'summary', 'description'], 'Untitled review item'),
            'decision_id' => $decisionId !== '' ? $decisionId : null,
            'family' => $family !== '' ? $family : 'unknown',
            'group_key' => $decisionId !== '' ? 'decision:'.$decisionId : 'family:'.($family !== '' ? $family : 'unknown'),
            'predicted_revert_band' => $band,
            'band_rank' => self::BAND_RANK[$band],
            'evidence_refs' => self::evidenceRefs($item),
            'diff_ref' => self::firstString($item, ['diff_ref', 'diff', 'patch_ref'], 'manual_review'),
            'reverse_command' => $reverse,
            'review_mode' => $reverse === 'manual_review' ? 'manual_review' : 'reversible',
            'pending_flip' => (AiValueNormalizer::boolOrNull($item['pending_flip'] ?? null) ?? false),
            'flip_ref' => self::firstString($item, ['flip_ref', 'flip_id'], ''),
            'batched_ask' => (AiValueNormalizer::boolOrNull($item['batched_ask'] ?? null) ?? false),
            'ask_ref' => self::firstString($item, ['ask_ref', 'ask_id'], ''),
        ];
    }

    /**
     * @param  array<string,mixed>  $a
     * @param  array<string,mixed>  $b
     */
    private static function itemSorter(array $a, array $b): int
    {
        return ((int) (AiValueNormalizer::finiteFloatOrNull($a['band_rank'] ?? null) ?? 0) <=> (int) (AiValueNormalizer::finiteFloatOrNull($b['band_rank'] ?? null) ?? 0))
            ?: ((AiValueNormalizer::trimmedScalarStringOrNull($a['group_key'] ?? null) ?? '') <=> (AiValueNormalizer::trimmedScalarStringOrNull($b['group_key'] ?? null) ?? ''))
            ?: ((AiValueNormalizer::trimmedScalarStringOrNull($a['id'] ?? null) ?? '') <=> (AiValueNormalizer::trimmedScalarStringOrNull($b['id'] ?? null) ?? ''));
    }

    /**
     * @param  list<array<string,mixed>>  $items
     * @return list<array<string,mixed>>
     */
    private static function groups(array $items): array
    {
        $groups = [];
        foreach ($items as $item) {
            $key = AiValueNormalizer::trimmedScalarStringOrNull($item['group_key'] ?? null) ?? '';
            $groups[$key] ??= [
                'group_key' => $key,
                'decision_id' => $item['decision_id'],
                'family' => $item['family'],
                'highest_predicted_revert_band' => $item['predicted_revert_band'],
                'highest_band_rank' => $item['band_rank'],
                'item_count' => 0,
                'items' => [],
            ];
            $groups[$key]['item_count']++;
            $groups[$key]['items'][] = $item;
            if ((int) (AiValueNormalizer::finiteFloatOrNull($item['band_rank'] ?? null) ?? 0) < (int) $groups[$key]['highest_band_rank']) {
                $groups[$key]['highest_band_rank'] = $item['band_rank'];
                $groups[$key]['highest_predicted_revert_band'] = $item['predicted_revert_band'];
            }
        }

        uasort($groups, static fn (array $a, array $b): int => ((int) (AiValueNormalizer::finiteFloatOrNull($a['highest_band_rank'] ?? null) ?? 0) <=> (int) (AiValueNormalizer::finiteFloatOrNull($b['highest_band_rank'] ?? null) ?? 0))
            ?: ((AiValueNormalizer::trimmedScalarStringOrNull($a['group_key'] ?? null) ?? '') <=> (AiValueNormalizer::trimmedScalarStringOrNull($b['group_key'] ?? null) ?? '')));

        return array_values(array_map(static function (array $group): array {
            unset($group['highest_band_rank']);

            return $group;
        }, $groups));
    }

    /**
     * @param  list<array<string,mixed>>  $items
     * @return array{count:int,items:list<array<string,mixed>>}
     */
    private static function flaggedSection(array $items, string $flag, string $refKey): array
    {
        $flagged = [];
        foreach ($items as $item) {
            if (($item[$flag] ?? false) !== true) {
                continue;
            }
            $flagged[] = [
                'id' => $item['id'],
                'title' => $item['title'],
                'decision_id' => $item['decision_id'],
                'family' => $item['family'],
                $refKey => $item[$refKey] !== '' ? $item[$refKey] : 'unlabelled',
                'reverse_command' => $item['reverse_command'],
            ];
        }

        return ['count' => count($flagged), 'items' => $flagged];
    }

    /**
     * @param  array<string,mixed>  $section
     * @return list<string>
     */
    private static function renderFlaggedSection(string $title, array $section): array
    {
        $lines = ['## '.$title];
        $items = AiValueNormalizer::arrayOrEmpty($section['items'] ?? null);
        if ($items === []) {
            $lines[] = '- none';
            $lines[] = '';

            return $lines;
        }

        foreach ($items as $item) {
            if (! is_array($item)) {
                continue;
            }
            $ref = self::string($item['flip_ref'] ?? $item['ask_ref'] ?? 'unlabelled') ?: 'unlabelled';
            $lines[] = '- '.self::plain(self::string($item['id'] ?? 'item') ?: 'item').' '.self::inline($ref)
                .' reverse: '.self::inline(self::string($item['reverse_command'] ?? 'manual_review') ?: 'manual_review');
        }
        $lines[] = '';

        return $lines;
    }

    private static function band(mixed $value): string
    {
        $band = AiValueNormalizer::lowerTrimmedString($value);

        return array_key_exists($band, self::BAND_RANK) ? $band : 'unknown';
    }

    /**
     * @param  array<string,mixed>  $item
     * @return list<string>
     */
    private static function evidenceRefs(array $item): array
    {
        $refs = [];
        $rawRefs = $item['evidence_refs'] ?? null;
        if (is_array($rawRefs)) {
            foreach ($rawRefs as $ref) {
                $value = self::string($ref);
                if ($value !== '') {
                    $refs[] = $value;
                }
            }
        }

        $single = self::string($item['evidence_ref'] ?? null);
        if ($single !== '') {
            $refs[] = $single;
        }

        return array_values(array_unique($refs)) ?: ['missing_evidence'];
    }

    /**
     * @param  array<string,mixed>  $item
     */
    private static function reverseCommand(array $item): string
    {
        $command = self::firstString($item, ['reverse_command', 'reverse_handle', 'rollback_command'], '');
        if ($command === '' || $command === 'manual_review') {
            return 'manual_review';
        }

        return $command;
    }

    /**
     * @param  array<string,mixed>  $item
     * @param  list<string>  $keys
     */
    private static function firstString(array $item, array $keys, string $default): string
    {
        foreach ($keys as $key) {
            $value = self::string($item[$key] ?? null);
            if ($value !== '') {
                return $value;
            }
        }

        return $default;
    }

    private static function string(mixed $value): string
    {
        return AiValueNormalizer::trimmedScalarStringOrNull($value) ?? '';
    }

    private static function plain(string $value): string
    {
        return str_replace(["\r", "\n"], ' ', AiValueNormalizer::trimmedStringOrNull($value) ?? '');
    }

    private static function inline(string $value): string
    {
        return '`'.str_replace('`', "'", self::plain($value)).'`';
    }
}
