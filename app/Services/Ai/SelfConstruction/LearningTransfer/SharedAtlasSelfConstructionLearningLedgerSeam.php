<?php

declare(strict_types=1);

namespace App\Services\Ai\SelfConstruction\LearningTransfer;

final class SharedAtlasSelfConstructionLearningLedgerSeam
{
    /**
     * @param  list<array<string,mixed>>  $rows
     * @return array<string,mixed>|null
     */
    public static function findLedgerRowByHash(array $rows, string $hashField, string $hash): ?array
    {
        foreach ($rows as $row) {
            if ((string) ($row[$hashField] ?? '') === $hash) {
                return $row;
            }
        }

        return null;
    }

    /**
     * @param  mixed  $value
     * @return mixed
     */
    public static function sortLedgerPayloadRecursively(mixed $value): mixed
    {
        if (! is_array($value)) {
            return $value;
        }
        if (array_is_list($value)) {
            return array_map(fn (mixed $v): mixed => self::sortLedgerPayloadRecursively($v), $value);
        }
        ksort($value, SORT_STRING);
        $out = [];
        foreach ($value as $k => $v) {
            $out[$k] = self::sortLedgerPayloadRecursively($v);
        }

        return $out;
    }
}
