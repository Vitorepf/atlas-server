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

    public const BAND_HIGH = 'high';

    public const BAND_SWEET = 'sweet';

    public const BAND_LOW = 'low';

    public const BAND_UNKNOWN = 'unknown';

    public const STATUS_OK = 'ok';

    public const STATUS_EMPTY = 'empty';


    public const FIELD_GROUP_KEY = 'group_key';

    public const FIELD_DECISION_ID = 'decision_id';

    public const FIELD_FAMILY = 'family';

    public const FIELD_PREDICTED_REVERT_BAND = 'predicted_revert_band';

    public const FIELD_BAND_RANK = 'band_rank';

    public const FIELD_HIGHEST_BAND_RANK = 'highest_band_rank';

    public const FIELD_ITEMS = 'items';

    public const FIELD_REVERSE_COMMAND = 'reverse_command';

    public const FIELD_STATUS = 'status';

    public const FIELD_SCHEMA_VERSION = 'schema_version';

    public const FIELD_LIMIT = 'limit';

    public const FIELD_GROUPS = 'groups';

    public const BAND_RANK = [
        self::BAND_HIGH => 0,
        self::BAND_SWEET => 1,
        self::BAND_LOW => self::INT_2,
        self::BAND_UNKNOWN => self::INT_3,
    ];

    public const DEFAULT_LIMIT = 50;

    public const HARD_LIMIT_CAP = 200;
    public const FIELD_ITEM_COUNT = 'item_count';
    public const FIELD_TITLE = 'title';
    public const FIELD_SHOWN_ITEM_COUNT = 'shown_item_count';
    public const FIELD_GROUP_COUNT = 'group_count';
    public const FIELD_CAP = 'cap';
    public const FIELD_BAND_ORDER = 'band_order';
    public const FIELD_BAND_COUNTS = 'band_counts';
    public const FIELD_PENDING_FLIPS = 'pending_flips';
    public const FIELD_EVIDENCE_REFS = 'evidence_refs';
    public const FIELD_HIGHEST_PREDICTED_REVERT_BAND = 'highest_predicted_revert_band';
    public const FIELD_BATCHED_ASKS = 'batched_asks';
    public const FIELD_DIFF_REF = 'diff_ref';
    public const FIELD_REVIEW_MODE = 'review_mode';
    public const FIELD_PENDING_FLIP = 'pending_flip';
    public const FIELD_ASK_REF = 'ask_ref';
    public const FIELD_BATCHED_ASK = 'batched_ask';
    public const FIELD_FLIP_REF = 'flip_ref';
    public const FIELD_SLICE = 'slice';
    public const FIELD_FRONTIER_PLAN_SECTION = 'frontier_plan_section';
    public const FIELD_MARKDOWN_CLI_ONLY = 'markdown_cli_only';
    public const FIELD_UI_CREATED = 'ui_created';
    public const FIELD_ID = 'id';
    public const FIELD_SOURCE = 'source';
    public const FIELD_COUNT = 'count';
    public const FIELD_EVIDENCE_REF = 'evidence_ref';
    public const FIELD_MANUAL_REVIEW_WHEN_REVERSE_MISSING = 'manual_review_when_reverse_missing';
    public const FIELD_MISSING_EVIDENCE = 'missing_evidence';
    public const FIELD_REORDERS_BY_PREDICTED_REVERT_BAND = 'reorders_by_predicted_revert_band';
    public const FIELD_MANUAL_REVIEW = 'manual_review';
    public const FIELD_ITEM = 'item';
    public const FIELD_UNLABELLED = 'unlabelled';
    public const FIELD_UNTITLED = 'untitled';
    public const FIELD_DIFF = 'diff';
    public const FIELD_REVERSE_HANDLE = 'reverse_handle';
    public const FIELD_SUMMARY = 'summary';
    public const FIELD_ASK_ID = 'ask_id';
    public const FIELD_DESCRIPTION = 'description';
    public const FIELD_FLIP_ID = 'flip_id';
    public const FIELD_NONE = 'none';
    public const FIELD_PATCH_REF = 'patch_ref';
    public const FIELD_REVERSIBLE = 'reversible';
    public const FIELD_ROLLBACK_COMMAND = 'rollback_command';
    public const FIELD_TETO_10 = 'TETO-10';
    public const INT_3 = 3;
    public const INT_2 = 2;

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
        $bandCounts = [self::BAND_HIGH => 0, self::BAND_SWEET => 0, self::BAND_LOW => 0, self::BAND_UNKNOWN => 0];
        foreach ($normalised as $item) {
            $bandCounts[self::band($item[self::FIELD_PREDICTED_REVERT_BAND])]++;
        }

        return [
            self::FIELD_SCHEMA_VERSION => self::SCHEMA_VERSION,
            self::FIELD_STATUS => $normalised === [] ? self::STATUS_EMPTY : self::STATUS_OK,
            self::FIELD_ITEM_COUNT => count($normalised),
            self::FIELD_SHOWN_ITEM_COUNT => count($shown),
            self::FIELD_GROUP_COUNT => count($groups),
            self::FIELD_CAP => $limit,
            self::FIELD_BAND_ORDER => [self::BAND_HIGH, self::BAND_SWEET, self::BAND_LOW, self::BAND_UNKNOWN],
            self::FIELD_BAND_COUNTS => $bandCounts,
            self::FIELD_GROUPS => $groups,
            self::FIELD_PENDING_FLIPS => self::flaggedSection($shown, 'pending_flip', 'flip_ref'),
            self::FIELD_BATCHED_ASKS => self::flaggedSection($shown, 'batched_ask', 'ask_ref'),
            self::FIELD_SOURCE => [
                self::FIELD_SLICE => self::FIELD_TETO_10,
                self::FIELD_FRONTIER_PLAN_SECTION => '3144-3147',
                self::FIELD_MARKDOWN_CLI_ONLY => true,
                self::FIELD_UI_CREATED => false,
                self::FIELD_REORDERS_BY_PREDICTED_REVERT_BAND => true,
                self::FIELD_MANUAL_REVIEW_WHEN_REVERSE_MISSING => true,
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
            '- schema: '.self::inline(self::string($digest[self::FIELD_SCHEMA_VERSION] ?? self::SCHEMA_VERSION) ?: self::SCHEMA_VERSION),
            '- status: '.self::plain(self::string($digest[self::FIELD_STATUS] ?? self::BAND_UNKNOWN) ?: self::BAND_UNKNOWN),
            '- items: '.self::string($digest[self::FIELD_SHOWN_ITEM_COUNT] ?? 0).'/'.self::string($digest[self::FIELD_ITEM_COUNT] ?? 0),
            '- order: high -> sweet -> low -> unknown',
            '- surface: markdown/CLI only',
            '',
        ];

        $currentBand = null;
        foreach (AiValueNormalizer::arrayOrEmpty($digest[self::FIELD_GROUPS] ?? null) as $group) {
            if (! is_array($group)) {
                continue;
            }
            $band = self::band($group[self::FIELD_HIGHEST_PREDICTED_REVERT_BAND] ?? self::BAND_UNKNOWN);
            if ($band !== $currentBand) {
                $lines[] = '## Predicted revert: '.$band;
                $lines[] = '';
                $currentBand = $band;
            }

            $lines[] = '### '.self::plain(self::string($group[self::FIELD_GROUP_KEY] ?? 'family:unknown') ?: 'family:unknown');
            $decisionId = self::string($group[self::FIELD_DECISION_ID] ?? '');
            $family = self::string($group[self::FIELD_FAMILY] ?? '');
            if ($decisionId !== '' || $family !== '') {
                $lines[] = '- lineage: decision_id='.($decisionId !== '' ? self::plain($decisionId) : self::FIELD_NONE)
                    .' family='.($family !== '' ? self::plain($family) : self::BAND_UNKNOWN);
            }

            foreach (AiValueNormalizer::arrayOrEmpty($group[self::FIELD_ITEMS] ?? null) as $item) {
                if (! is_array($item)) {
                    continue;
                }
                $lines[] = '- '.self::plain(self::string($item[self::FIELD_ID] ?? self::FIELD_ITEM) ?: 'item').': '.self::plain(self::string($item[self::FIELD_TITLE] ?? self::FIELD_UNTITLED) ?: 'untitled');
                $lines[] = '  - band: '.self::plain(self::band($item[self::FIELD_PREDICTED_REVERT_BAND] ?? self::BAND_UNKNOWN));
                $lines[] = '  - evidence: '.self::plain(implode(', ', array_map(self::string(...), AiValueNormalizer::arrayOrEmpty($item[self::FIELD_EVIDENCE_REFS] ?? null))));
                $lines[] = '  - diff-ref: '.self::plain(self::string($item[self::FIELD_DIFF_REF] ?? self::FIELD_MANUAL_REVIEW) ?: 'manual_review');
                $lines[] = '  - reverse: '.self::inline(self::string($item[self::FIELD_REVERSE_COMMAND] ?? self::FIELD_MANUAL_REVIEW) ?: 'manual_review');
                $lines[] = '  - review-mode: '.self::plain(self::string($item[self::FIELD_REVIEW_MODE] ?? self::FIELD_MANUAL_REVIEW) ?: 'manual_review');
            }
            $lines[] = '';
        }

        $lines = array_merge($lines, self::renderFlaggedSection('Pending flips', AiValueNormalizer::arrayOrEmpty($digest[self::FIELD_PENDING_FLIPS] ?? null)));
        $lines = array_merge($lines, self::renderFlaggedSection('Batched asks', AiValueNormalizer::arrayOrEmpty($digest[self::FIELD_BATCHED_ASKS] ?? null)));

        return rtrim(implode(PHP_EOL, $lines)).PHP_EOL;
    }

    /**
     * @param  array<string,mixed>  $item
     * @return array<string,mixed>
     */
    private static function normaliseItem(array $item, int $index): array
    {
        $id = self::string($item[self::FIELD_ID] ?? null);
        $decisionId = self::string($item[self::FIELD_DECISION_ID] ?? null);
        $family = self::string($item[self::FIELD_FAMILY] ?? null);
        $band = self::band($item[self::FIELD_PREDICTED_REVERT_BAND] ?? null);
        $reverse = self::reverseCommand($item);

        return [
            self::FIELD_ID => $id !== '' ? $id : 'item-'.($index + 1),
            self::FIELD_TITLE => self::firstString($item, ['title', self::FIELD_SUMMARY, self::FIELD_DESCRIPTION], 'Untitled review item'),
            self::FIELD_DECISION_ID => $decisionId !== '' ? $decisionId : null,
            self::FIELD_FAMILY => $family !== '' ? $family : self::BAND_UNKNOWN,
            self::FIELD_GROUP_KEY => $decisionId !== '' ? 'decision:'.$decisionId : 'family:'.($family !== '' ? $family : self::BAND_UNKNOWN),
            self::FIELD_PREDICTED_REVERT_BAND => $band,
            self::FIELD_BAND_RANK => self::BAND_RANK[$band],
            self::FIELD_EVIDENCE_REFS => self::evidenceRefs($item),
            self::FIELD_DIFF_REF => self::firstString($item, ['diff_ref', self::FIELD_DIFF, self::FIELD_PATCH_REF], 'manual_review'),
            self::FIELD_REVERSE_COMMAND => $reverse,
            self::FIELD_REVIEW_MODE => $reverse === self::FIELD_MANUAL_REVIEW ? self::FIELD_MANUAL_REVIEW : self::FIELD_REVERSIBLE,
            self::FIELD_PENDING_FLIP => (AiValueNormalizer::boolOrNull($item[self::FIELD_PENDING_FLIP] ?? null) ?? false),
            self::FIELD_FLIP_REF => self::firstString($item, ['flip_ref', self::FIELD_FLIP_ID], ''),
            self::FIELD_BATCHED_ASK => (AiValueNormalizer::boolOrNull($item[self::FIELD_BATCHED_ASK] ?? null) ?? false),
            self::FIELD_ASK_REF => self::firstString($item, ['ask_ref', self::FIELD_ASK_ID], ''),
        ];
    }

    /**
     * @param  array<string,mixed>  $a
     * @param  array<string,mixed>  $b
     */
    private static function itemSorter(array $a, array $b): int
    {
        return ((int) (AiValueNormalizer::finiteFloatOrNull($a[self::FIELD_BAND_RANK] ?? null) ?? 0) <=> (int) (AiValueNormalizer::finiteFloatOrNull($b[self::FIELD_BAND_RANK] ?? null) ?? 0))
            ?: ((AiValueNormalizer::trimmedScalarStringOrNull($a[self::FIELD_GROUP_KEY] ?? null) ?? '') <=> (AiValueNormalizer::trimmedScalarStringOrNull($b[self::FIELD_GROUP_KEY] ?? null) ?? ''))
            ?: ((AiValueNormalizer::trimmedScalarStringOrNull($a[self::FIELD_ID] ?? null) ?? '') <=> (AiValueNormalizer::trimmedScalarStringOrNull($b[self::FIELD_ID] ?? null) ?? ''));
    }

    /**
     * @param  list<array<string,mixed>>  $items
     * @return list<array<string,mixed>>
     */
    private static function groups(array $items): array
    {
        $groups = [];
        foreach ($items as $item) {
            $key = AiValueNormalizer::trimmedScalarStringOrNull($item[self::FIELD_GROUP_KEY] ?? null) ?? '';
            $groups[$key] ??= [
                self::FIELD_GROUP_KEY => $key,
                self::FIELD_DECISION_ID => $item[self::FIELD_DECISION_ID],
                self::FIELD_FAMILY => $item[self::FIELD_FAMILY],
                self::FIELD_HIGHEST_PREDICTED_REVERT_BAND => $item[self::FIELD_PREDICTED_REVERT_BAND],
                self::FIELD_HIGHEST_BAND_RANK => $item[self::FIELD_BAND_RANK],
                self::FIELD_ITEM_COUNT => 0,
                self::FIELD_ITEMS => [],
            ];
            $groups[$key][self::FIELD_ITEM_COUNT]++;
            $groups[$key][self::FIELD_ITEMS][] = $item;
            if ((int) (AiValueNormalizer::finiteFloatOrNull($item[self::FIELD_BAND_RANK] ?? null) ?? 0) < (int) $groups[$key][self::FIELD_HIGHEST_BAND_RANK]) {
                $groups[$key][self::FIELD_HIGHEST_BAND_RANK] = $item[self::FIELD_BAND_RANK];
                $groups[$key][self::FIELD_HIGHEST_PREDICTED_REVERT_BAND] = $item[self::FIELD_PREDICTED_REVERT_BAND];
            }
        }

        uasort($groups, static fn (array $a, array $b): int => ((int) (AiValueNormalizer::finiteFloatOrNull($a[self::FIELD_HIGHEST_BAND_RANK] ?? null) ?? 0) <=> (int) (AiValueNormalizer::finiteFloatOrNull($b[self::FIELD_HIGHEST_BAND_RANK] ?? null) ?? 0))
            ?: ((AiValueNormalizer::trimmedScalarStringOrNull($a[self::FIELD_GROUP_KEY] ?? null) ?? '') <=> (AiValueNormalizer::trimmedScalarStringOrNull($b[self::FIELD_GROUP_KEY] ?? null) ?? '')));

        return array_values(array_map(static function (array $group): array {
            unset($group[self::FIELD_HIGHEST_BAND_RANK]);

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
                self::FIELD_ID => $item[self::FIELD_ID],
                self::FIELD_TITLE => $item[self::FIELD_TITLE],
                self::FIELD_DECISION_ID => $item[self::FIELD_DECISION_ID],
                self::FIELD_FAMILY => $item[self::FIELD_FAMILY],
                $refKey => $item[$refKey] !== '' ? $item[$refKey] : 'unlabelled',
                self::FIELD_REVERSE_COMMAND => $item[self::FIELD_REVERSE_COMMAND],
            ];
        }

        return [self::FIELD_COUNT => count($flagged), self::FIELD_ITEMS => $flagged];
    }

    /**
     * @param  array<string,mixed>  $section
     * @return list<string>
     */
    private static function renderFlaggedSection(string $title, array $section): array
    {
        $lines = ['## '.$title];
        $items = AiValueNormalizer::arrayOrEmpty($section[self::FIELD_ITEMS] ?? null);
        if ($items === []) {
            $lines[] = '- none';
            $lines[] = '';

            return $lines;
        }

        foreach ($items as $item) {
            if (! is_array($item)) {
                continue;
            }
            $ref = self::string($item[self::FIELD_FLIP_REF] ?? $item[self::FIELD_ASK_REF] ?? self::FIELD_UNLABELLED) ?: 'unlabelled';
            $lines[] = '- '.self::plain(self::string($item[self::FIELD_ID] ?? self::FIELD_ITEM) ?: 'item').' '.self::inline($ref)
                .' reverse: '.self::inline(self::string($item[self::FIELD_REVERSE_COMMAND] ?? self::FIELD_MANUAL_REVIEW) ?: 'manual_review');
        }
        $lines[] = '';

        return $lines;
    }

    private static function band(mixed $value): string
    {
        $band = AiValueNormalizer::lowerTrimmedString($value);

        return array_key_exists($band, self::BAND_RANK) ? $band : self::BAND_UNKNOWN;
    }

    /**
     * @param  array<string,mixed>  $item
     * @return list<string>
     */
    private static function evidenceRefs(array $item): array
    {
        $refs = [];
        $rawRefs = $item[self::FIELD_EVIDENCE_REFS] ?? null;
        if (is_array($rawRefs)) {
            foreach ($rawRefs as $ref) {
                $value = self::string($ref);
                if ($value !== '') {
                    $refs[] = $value;
                }
            }
        }

        $single = self::string($item[self::FIELD_EVIDENCE_REF] ?? null);
        if ($single !== '') {
            $refs[] = $single;
        }

        return array_values(array_unique($refs)) ?: [self::FIELD_MISSING_EVIDENCE];
    }

    /**
     * @param  array<string,mixed>  $item
     */
    private static function reverseCommand(array $item): string
    {
        $command = self::firstString($item, ['reverse_command', self::FIELD_REVERSE_HANDLE, self::FIELD_ROLLBACK_COMMAND], '');
        if ($command === '' || $command === self::FIELD_MANUAL_REVIEW) {
            return self::FIELD_MANUAL_REVIEW;
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
