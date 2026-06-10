<?php

declare(strict_types=1);

namespace App\Services\Ai\SoftwareCompanyStewardship\AreaFocusLoop;

use Symfony\Component\Process\Process;

final class AreaFocusBranchRefNormalizer
{
    /**
     * @param  array<string,mixed>  $input
     * @return list<string>
     */
    public static function fromInput(array $input): array
    {
        return self::fromValue($input['branch_refs'] ?? $input['branches'] ?? []);
    }

    /**
     * @return list<string>
     */
    public static function fromValue(mixed $refs): array
    {
        if (is_string($refs)) {
            $refs = preg_split('/[\s,]+/', $refs) ?: [];
        }

        if (! is_array($refs)) {
            return [];
        }

        $normalized = [];
        foreach ($refs as $item) {
            $ref = is_array($item)
                ? trim((string) ($item['branch_ref'] ?? $item['branch'] ?? ''))
                : trim((string) $item);

            if ($ref !== '') {
                $normalized[] = $ref;
            }
        }

        return AreaFocusStringListNormalizer::uniqueStringValues($normalized);
    }

    /**
     * @return list<string>
     */
    public static function localBranches(string $repoRoot, string $prefix): array
    {
        $process = new Process(['git', 'for-each-ref', '--format=%(refname:short)', 'refs/heads'], $repoRoot);
        $process->setTimeout(30);
        $process->run();
        if (! $process->isSuccessful()) {
            return [];
        }

        return array_values(array_filter(
            AreaFocusStringListNormalizer::trimmedLines($process->getOutput()),
            static fn (string $ref): bool => $prefix === '' || str_starts_with($ref, $prefix),
        ));
    }
}
