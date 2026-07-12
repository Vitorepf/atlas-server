<?php

declare(strict_types=1);

namespace App\Services\Ai\Compaction;

use Illuminate\Support\Str;

final class DeterministicLocalL2SummaryRewriter implements LocalL2SummaryRewriter
{
    public function rewrite(string $l1Summary, array $context = []): array
    {
        $lines = preg_split('/\R/u', trim($l1Summary)) ?: [];
        $kept = [];
        foreach ($lines as $line) {
            $line = trim($line);
            if ($line === '') {
                continue;
            }

            if ($this->isHighSignalLine($line)) {
                $kept[] = $this->compactLine($line);
            }
        }

        if ($kept === []) {
            $kept[] = $this->compactLine($l1Summary);
        }

        $summary = implode("\n", array_values(array_unique($kept)));

        return [
            'summary' => trim($summary),
            'runtime' => 'deterministic-local-l2',
            'metadata' => [
                'local_only' => true,
                'provider_invoked' => false,
                'strategy' => 'high_signal_line_compaction',
            ],
        ];
    }

    private function isHighSignalLine(string $line): bool
    {
        if (str_contains($line, ':')) {
            return true;
        }

        return Str::contains(mb_strtolower($line), [
            'decis',
            'pend',
            'open loop',
            'proximo',
            'próximo',
            'must',
            'mk-',
            'decision',
            'blocker',
            'retained',
        ]);
    }

    private function compactLine(string $line): string
    {
        $line = trim((string) preg_replace('/\s+/u', ' ', $line));

        return Str::limit($line, 720, '...');
    }
}
