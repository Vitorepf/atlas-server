<?php

declare(strict_types=1);

namespace App\Services\Ai\AobgWorkspaceOnboarding;

/**
 * Leaf helpers shared across the AOBG workspace-onboarding façade and its family
 * sections (star topology). Depends on nothing but PHP + the filesystem — every
 * section may lean on this leaf, and this leaf leans on no section.
 */
class AobgWorkspaceOnboardingSupport
{
    /**
     * @param  array<string,mixed>  $profile
     */
    public function profilePath(array $profile): ?string
    {
        foreach (['workspace_path', 'repo_root'] as $key) {
            $path = trim((string) ($profile[$key] ?? ''));
            if ($path !== '') {
                return rtrim(realpath($path) ?: $path, DIRECTORY_SEPARATOR);
            }
        }

        return null;
    }

    /**
     * @param  array<string,mixed>  $payload
     * @param  array<int,string>  $path
     */
    public function stringFromArray(array $payload, array $path): ?string
    {
        $value = $payload;
        foreach ($path as $segment) {
            if (! is_array($value) || ! array_key_exists($segment, $value)) {
                return null;
            }
            $value = $value[$segment];
        }

        return is_string($value) && trim($value) !== '' ? trim($value) : null;
    }
}
