<?php

declare(strict_types=1);

namespace App\Models;

/**
 * Log append-only de events processados (in-memory para o fixture).
 *
 * Em produção isso seria uma tabela; aqui é um singleton estático para
 * manter o fixture pequeno e determinístico. O arm pode reescrever para
 * usar a interface real (database) na implementação se quiser, desde que
 * mantenha a API expostas (recordIfNew + wasProcessed) para o test.
 */
final class ReceivedEvent
{
    /** @var array<string,bool> */
    private static array $processed = [];

    public static function wasProcessed(string $eventId): bool
    {
        return isset(self::$processed[$eventId]);
    }

    public static function recordIfNew(string $eventId): bool
    {
        if (isset(self::$processed[$eventId])) {
            return false;
        }
        self::$processed[$eventId] = true;

        return true;
    }

    public static function reset(): void
    {
        self::$processed = [];
    }
}
