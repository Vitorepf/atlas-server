<?php

declare(strict_types=1);

namespace App\Services\Ai\AutonomousEvolution\Discovery\Cortex\ApiDiff;

final class AtlasCortexApiBreakingChangeReporter
{
    /**
     * @param  list<array<string,mixed>>  $diff
     * @param  iterable<array<string,mixed>>  $consumerCandidates
     * @return list<array<string,mixed>>
     */
    public function report(array $diff, string $targetFqcn, iterable $consumerCandidates): array
    {
        $targets = [];
        foreach ($diff as $row) {
            if (! is_array($row)) {
                continue;
            }

            $classification = (string) ($row['classification'] ?? '');
            if (! in_array($classification, ['removed', 'changed'], true)) {
                continue;
            }

            $methodName = (string) ($row['method_name'] ?? '');
            if ($methodName === '') {
                continue;
            }

            $targets[$methodName] = $classification;
        }

        if ($targets === []) {
            return [];
        }

        $rows = [];
        foreach ($consumerCandidates as $candidate) {
            if (! is_array($candidate)) {
                continue;
            }

            $consumerFqcn = (string) ($candidate['consumer_fqcn'] ?? '');
            $consumerFile = (string) ($candidate['consumer_file'] ?? '');
            if ($consumerFqcn === '' || $consumerFile === '' || ! is_file($consumerFile)) {
                continue;
            }

            $lines = file($consumerFile, FILE_IGNORE_NEW_LINES);
            if (! is_array($lines)) {
                continue;
            }

            foreach ($lines as $index => $line) {
                foreach ($targets as $methodName => $classification) {
                    if (! $this->referencesMethod($line, $targetFqcn, $methodName)) {
                        continue;
                    }

                    $rows[] = [
                        'consumer_fqcn' => $consumerFqcn,
                        'consumer_file' => $consumerFile,
                        'consumer_line' => $index + 1,
                        'target_method' => $methodName,
                        'change_kind' => $classification,
                        'evidence_snippet' => trim($line),
                    ];
                }
            }
        }

        usort($rows, static function (array $left, array $right): int {
            $byFqcn = strcmp((string) $left['consumer_fqcn'], (string) $right['consumer_fqcn']);
            if ($byFqcn !== 0) {
                return $byFqcn;
            }

            $byLine = ((int) $left['consumer_line']) <=> ((int) $right['consumer_line']);
            if ($byLine !== 0) {
                return $byLine;
            }

            return strcmp((string) $left['target_method'], (string) $right['target_method']);
        });

        return $rows;
    }

    private function referencesMethod(string $line, string $targetFqcn, string $methodName): bool
    {
        $patterns = [
            '/->'.preg_quote($methodName, '/').'\s*\(/',
            '/::'.preg_quote($methodName, '/').'\s*\(/',
            '/[\'"]'.preg_quote($methodName, '/').'[\'"]/',
            '/[\'"]'.preg_quote($targetFqcn, '/').'::'.preg_quote($methodName, '/').'[\'"]/',
        ];

        foreach ($patterns as $pattern) {
            if (preg_match($pattern, $line) === 1) {
                return true;
            }
        }

        return false;
    }
}
