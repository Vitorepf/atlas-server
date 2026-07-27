<?php

declare(strict_types=1);

namespace App\Services\Ai\Support;

/**
 * Extraido pela limpeza-bruta 05/07 (censo de metodos duplicados): readAll() era
 * clonado byte a byte em 3 classes. Copia divergente permanece local.
 */
trait ReadsJsonlLedgerEntries
{
    private function readAll(): array
    {
        if (! is_file($this->ledgerPath)) {
            return [];
        }
        $rows = [];
        $handle = @fopen($this->ledgerPath, 'r');
        if ($handle === false) {
            return [];
        }
        while (($line = fgets($handle)) !== false) {
            $line = trim($line);
            if ($line === '') {
                continue;
            }
            $decoded = json_decode($line, true);
            if (is_array($decoded)) {
                $rows[] = $decoded;
            }
        }
        fclose($handle);

        return $rows;
    }
}
