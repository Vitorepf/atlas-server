<?php

declare(strict_types=1);

namespace App\Services\Ai\AutonomousEvolution\V4;

final class AtlasLoopV4SelfArchitectureSentinel
{
    /**
     * @param  list<array<string,mixed>>  $acceptedProposals
     * @param  list<mixed>  $forbiddenSelfTargets
     * @return array{healthy:bool,alerts:list<array<string,string>>,fingerprint:string}
     */
    public function audit(array $acceptedProposals, array $forbiddenSelfTargets): array
    {
        $proposals = $this->normalizedProposals($acceptedProposals);
        $forbidden = $this->normalizedList($forbiddenSelfTargets);
        $alerts = [
            ...$this->monocultureAlerts($proposals),
            ...$this->proximityCreepAlerts($proposals, $forbidden),
            ...$this->rationaleDecayAlerts($proposals),
        ];

        return [
            'healthy' => $alerts === [],
            'alerts' => $alerts,
            'fingerprint' => sha1(json_encode([
                'proposals' => $proposals,
                'forbidden_self_targets' => $forbidden,
            ], JSON_UNESCAPED_SLASHES)),
        ];
    }

    /**
     * @param  list<array<string,mixed>>  $proposals
     * @return list<array{kind:string,target_path:string,rationale:string}>
     */
    private function normalizedProposals(array $proposals): array
    {
        $normalized = array_map(static fn (array $proposal): array => [
            'kind' => trim((string) ($proposal['kind'] ?? '')),
            'target_path' => ltrim(trim((string) ($proposal['target_path'] ?? '')), '/'),
            'rationale' => trim((string) ($proposal['rationale'] ?? '')),
        ], $proposals);

        usort($normalized, static fn (array $left, array $right): int => [
            $left['kind'],
            $left['target_path'],
            $left['rationale'],
        ] <=> [
            $right['kind'],
            $right['target_path'],
            $right['rationale'],
        ]);

        return array_values($normalized);
    }

    /**
     * @param  list<mixed>  $values
     * @return list<string>
     */
    private function normalizedList(array $values): array
    {
        $normalized = array_values(array_filter(array_map(
            static fn (mixed $value): string => ltrim(trim((string) $value), '/'),
            $values,
        ), static fn (string $value): bool => $value !== ''));
        $normalized = array_values(array_unique($normalized));
        sort($normalized, SORT_STRING);

        return $normalized;
    }

    /**
     * @param  list<array{kind:string,target_path:string,rationale:string}>  $proposals
     * @return list<array<string,string>>
     */
    private function monocultureAlerts(array $proposals): array
    {
        $window = count($proposals);
        if ($window < 5) {
            return [];
        }

        $counts = [];
        foreach ($proposals as $proposal) {
            $kind = $proposal['kind'];
            $counts[$kind] = ($counts[$kind] ?? 0) + 1;
        }
        ksort($counts, SORT_STRING);

        foreach ($counts as $kind => $count) {
            if (($count / $window) > 0.8) {
                return [[
                    'kind' => 'monoculture',
                    'dominant_kind' => $kind,
                    'count' => (string) $count,
                    'window' => (string) $window,
                ]];
            }
        }

        return [];
    }

    /**
     * @param  list<array{kind:string,target_path:string,rationale:string}>  $proposals
     * @param  list<string>  $forbiddenSelfTargets
     * @return list<array<string,string>>
     */
    private function proximityCreepAlerts(array $proposals, array $forbiddenSelfTargets): array
    {
        $targetPaths = array_values(array_unique(array_column($proposals, 'target_path')));
        sort($targetPaths, SORT_STRING);

        $alerts = [];
        foreach ($targetPaths as $targetPath) {
            $targetDir = $this->normalizedDir($targetPath);
            if ($targetDir === null) {
                continue;
            }

            foreach ($forbiddenSelfTargets as $forbidden) {
                $forbiddenDir = $this->normalizedDir($forbidden);
                if ($forbiddenDir === null) {
                    continue;
                }

                if ($forbiddenDir === $targetDir || str_starts_with($forbiddenDir, $targetDir.'/')) {
                    $alerts[] = [
                        'kind' => 'proximity_creep',
                        'target_path' => $targetPath,
                        'target_dir' => $targetDir,
                        'forbidden_dir' => $forbiddenDir,
                    ];
                    break;
                }
            }
        }

        return $alerts;
    }

    private function normalizedDir(string $path): ?string
    {
        $dir = ltrim(trim(dirname($path)), '/');

        return $dir === '' || $dir === '.' ? null : $dir;
    }

    /**
     * @param  list<array{kind:string,target_path:string,rationale:string}>  $proposals
     * @return list<array<string,string>>
     */
    private function rationaleDecayAlerts(array $proposals): array
    {
        if ($proposals === []) {
            return [];
        }

        $lengths = array_map(static fn (array $proposal): int => strlen($proposal['rationale']), $proposals);
        sort($lengths, SORT_NUMERIC);
        $middle = intdiv(count($lengths), 2);
        $median = count($lengths) % 2 === 1
            ? (float) $lengths[$middle]
            : ($lengths[$middle - 1] + $lengths[$middle]) / 2;

        if ($median >= 80) {
            return [];
        }

        return [[
            'kind' => 'rationale_decay',
            'median' => $this->decimalString($median),
        ]];
    }

    private function decimalString(float $value): string
    {
        return rtrim(rtrim(number_format($value, 1, '.', ''), '0'), '.');
    }
}
