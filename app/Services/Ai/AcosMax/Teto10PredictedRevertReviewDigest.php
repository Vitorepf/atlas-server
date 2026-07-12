<?php

declare(strict_types=1);

namespace App\Services\Ai\AcosMax;

/**
 * TETO-10 (§3144-3147) turns review debt into a terminal-first review product:
 * grouped by decision/family, ordered by predicted-revert value, and carrying
 * evidence, diff reference, and a ready reverse command per reversible item.
 */
final class Teto10PredictedRevertReviewDigest
{
    public const SCHEMA_VERSION = 'atlas.acos.teto10.predicted_revert_review_digest.v1';

    private const BAND_RANK = [
        'high' => 0,
        'sweet' => 1,
        'low' => 2,
        'unknown' => 3,
    ];

    /**
     * @param  list<array<string,mixed>>  $items
     * @return array<string,mixed>
     */
    public static function compose(array $items, int $limit = 50): array
    {
        $limit = max(1, min(200, $limit));
        $normalised = [];
        foreach ($items as $index => $item) {
            $normalised[] = self::normaliseItem($item, $index);
        }

        usort($normalised, self::itemSorter(...));
        $shown = array_slice($normalised, 0, $limit);

        $groups = self::groups($shown);
        $bandCounts = ['high' => 0, 'sweet' => 0, 'low' => 0, 'unknown' => 0];
        foreach ($normalised as $item) {
            $bandCounts[(string) $item['predicted_revert_band']]++;
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
            '- schema: '.self::inline((string) ($digest['schema_version'] ?? self::SCHEMA_VERSION)),
            '- status: '.self::plain((string) ($digest['status'] ?? 'unknown')),
            '- items: '.(string) ($digest['shown_item_count'] ?? 0).'/'.(string) ($digest['item_count'] ?? 0),
            '- order: high -> sweet -> low -> unknown',
            '- surface: markdown/CLI only',
            '',
        ];

        $currentBand = null;
        foreach ((array) ($digest['groups'] ?? []) as $group) {
            if (! is_array($group)) {
                continue;
            }
            $band = (string) ($group['highest_predicted_revert_band'] ?? 'unknown');
            if ($band !== $currentBand) {
                $lines[] = '## Predicted revert: '.$band;
                $lines[] = '';
                $currentBand = $band;
            }

            $lines[] = '### '.self::plain((string) ($group['group_key'] ?? 'family:unknown'));
            $decisionId = (string) ($group['decision_id'] ?? '');
            $family = (string) ($group['family'] ?? '');
            if ($decisionId !== '' || $family !== '') {
                $lines[] = '- lineage: decision_id='.($decisionId !== '' ? self::plain($decisionId) : 'none')
                    .' family='.($family !== '' ? self::plain($family) : 'unknown');
            }

            foreach ((array) ($group['items'] ?? []) as $item) {
                if (! is_array($item)) {
                    continue;
                }
                $lines[] = '- '.self::plain((string) ($item['id'] ?? 'item')).': '.self::plain((string) ($item['title'] ?? 'untitled'));
                $lines[] = '  - band: '.self::plain((string) ($item['predicted_revert_band'] ?? 'unknown'));
                $lines[] = '  - evidence: '.self::plain(implode(', ', array_map('strval', (array) ($item['evidence_refs'] ?? []))));
                $lines[] = '  - diff-ref: '.self::plain((string) ($item['diff_ref'] ?? 'manual_review'));
                $lines[] = '  - reverse: '.self::inline((string) ($item['reverse_command'] ?? 'manual_review'));
                $lines[] = '  - review-mode: '.self::plain((string) ($item['review_mode'] ?? 'manual_review'));
            }
            $lines[] = '';
        }

        $lines = array_merge($lines, self::renderFlaggedSection('Pending flips', (array) ($digest['pending_flips'] ?? [])));
        $lines = array_merge($lines, self::renderFlaggedSection('Batched asks', (array) ($digest['batched_asks'] ?? [])));

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
            'pending_flip' => (bool) ($item['pending_flip'] ?? false),
            'flip_ref' => self::firstString($item, ['flip_ref', 'flip_id'], ''),
            'batched_ask' => (bool) ($item['batched_ask'] ?? false),
            'ask_ref' => self::firstString($item, ['ask_ref', 'ask_id'], ''),
        ];
    }

    /**
     * @param  array<string,mixed>  $a
     * @param  array<string,mixed>  $b
     */
    private static function itemSorter(array $a, array $b): int
    {
        return ((int) $a['band_rank'] <=> (int) $b['band_rank'])
            ?: ((string) $a['group_key'] <=> (string) $b['group_key'])
            ?: ((string) $a['id'] <=> (string) $b['id']);
    }

    /**
     * @param  list<array<string,mixed>>  $items
     * @return list<array<string,mixed>>
     */
    private static function groups(array $items): array
    {
        $groups = [];
        foreach ($items as $item) {
            $key = (string) $item['group_key'];
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
            if ((int) $item['band_rank'] < (int) $groups[$key]['highest_band_rank']) {
                $groups[$key]['highest_band_rank'] = $item['band_rank'];
                $groups[$key]['highest_predicted_revert_band'] = $item['predicted_revert_band'];
            }
        }

        uasort($groups, static fn (array $a, array $b): int => ((int) $a['highest_band_rank'] <=> (int) $b['highest_band_rank'])
            ?: ((string) $a['group_key'] <=> (string) $b['group_key']));

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
        $items = (array) ($section['items'] ?? []);
        if ($items === []) {
            $lines[] = '- none';
            $lines[] = '';

            return $lines;
        }

        foreach ($items as $item) {
            if (! is_array($item)) {
                continue;
            }
            $ref = (string) ($item['flip_ref'] ?? $item['ask_ref'] ?? 'unlabelled');
            $lines[] = '- '.self::plain((string) ($item['id'] ?? 'item')).' '.self::inline($ref)
                .' reverse: '.self::inline((string) ($item['reverse_command'] ?? 'manual_review'));
        }
        $lines[] = '';

        return $lines;
    }

    private static function band(mixed $value): string
    {
        $band = strtolower(trim((string) $value));

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
        if (is_scalar($value)) {
            return trim((string) $value);
        }

        return '';
    }

    private static function plain(string $value): string
    {
        return str_replace(["\r", "\n"], ' ', trim($value));
    }

    private static function inline(string $value): string
    {
        return '`'.str_replace('`', "'", self::plain($value)).'`';
    }
}
