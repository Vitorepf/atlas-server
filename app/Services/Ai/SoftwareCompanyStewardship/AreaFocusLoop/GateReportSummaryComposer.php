<?php

declare(strict_types=1);

namespace App\Services\Ai\SoftwareCompanyStewardship\AreaFocusLoop;

final class GateReportSummaryComposer
{
    private const SCHEMA_VERSION = 'atlas.aaeos.gate_report_summary.v1';

    private const STATUS_PASS = 'pass';

    private const STATUS_WARN = 'warn';

    private const STATUS_BLOCK = 'block';

    /**
     * Direct, non-subtractive bucket tally of gate rows.
     *
     * Each canonical bucket counts ONLY rows whose ['status'] is exactly the
     * matching canonical token (case-sensitive). Anything else — missing,
     * non-string, empty, or a non-canonical token — falls into a visible
     * 'other' bucket so malformed rows can never be silently absorbed into
     * pass via subtraction. The invariant total === pass+warn+block+other
     * therefore holds by construction.
     *
     * @param  array<int|string, mixed>  $gateRows
     * @return array{
     *     schema_version: string,
     *     total: int,
     *     pass: int,
     *     warn: int,
     *     block: int,
     *     other: int,
     *     other_statuses: list<string>
     * }
     */
    public function summarize(array $gateRows): array
    {
        $total = 0;
        $pass = 0;
        $warn = 0;
        $block = 0;
        $other = 0;
        $otherStatuses = [];

        foreach ($gateRows as $row) {
            $total++;

            $status = is_array($row) ? ($row['status'] ?? null) : null;

            if ($status === self::STATUS_PASS) {
                $pass++;

                continue;
            }

            if ($status === self::STATUS_WARN) {
                $warn++;

                continue;
            }

            if ($status === self::STATUS_BLOCK) {
                $block++;

                continue;
            }

            $other++;
            $otherStatuses[] = $this->offendingStatus($status);
        }

        $otherStatuses = AreaFocusStringListNormalizer::uniqueStringValues($otherStatuses);
        sort($otherStatuses, SORT_STRING);

        return [
            'schema_version' => self::SCHEMA_VERSION,
            'total' => $total,
            'pass' => $pass,
            'warn' => $warn,
            'block' => $block,
            'other' => $other,
            'other_statuses' => $otherStatuses,
        ];
    }

    /**
     * Render an offending raw status as a string so it stays visible.
     *
     * Missing (null) and any non-scalar collapse to the empty string; scalars
     * are cast directly. Booleans are normalised to stable tokens rather than
     * PHP's lossy '' / '1' cast.
     */
    private function offendingStatus(mixed $status): string
    {
        if ($status === null) {
            return '';
        }

        if (is_bool($status)) {
            return $status ? 'true' : 'false';
        }

        if (is_string($status)) {
            return $status;
        }

        if (is_int($status) || is_float($status)) {
            return (string) $status;
        }

        return '';
    }
}
