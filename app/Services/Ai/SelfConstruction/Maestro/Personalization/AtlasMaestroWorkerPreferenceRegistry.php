<?php

declare(strict_types=1);

namespace App\Services\Ai\SelfConstruction\Maestro\Personalization;

/**
 * Pure read-model registry of DECLARED worker preferences (never learned, never inferred).
 *
 * Keyed by opaque `client_id`. Each entry stores {max_files, max_loc, tier}. Unknown ids return
 * a neutral default profile byte-identically. NEVER branches on platform / provider name.
 */
final class AtlasMaestroWorkerPreferenceRegistry
{
    public const DEFAULT_PROFILE = [
        'max_files' => 2,
        'max_loc' => 200,
        'tier' => 'neutral',
    ];

    /** @var array<string, array{max_files:int,max_loc:int,tier:string}> */
    private array $declared;

    /**
     * @param  array<string, array<string,mixed>>|null  $declared  override (test seam); when null
     *                                                            reads from config('atlas.maestro.personalization.workers').
     */
    public function __construct(?array $declared = null)
    {
        $this->declared = $declared !== null
            ? $this->normalize($declared)
            : $this->normalize($this->loadFromConfig());
    }

    public function register(string $clientId, array $prefs): void
    {
        $clientId = trim($clientId);
        if ($clientId === '') {
            return;
        }
        $this->declared[$clientId] = $this->normalizeProfile($prefs);
    }

    /**
     * @return array{max_files:int,max_loc:int,tier:string}
     */
    public function inspect(string $clientId): array
    {
        return $this->declared[$clientId] ?? self::DEFAULT_PROFILE;
    }

    /**
     * @return array<string, array{max_files:int,max_loc:int,tier:string}>
     */
    public function all(): array
    {
        $out = $this->declared;
        ksort($out, SORT_STRING);

        return $out;
    }

    /**
     * @return array<string, array<string,mixed>>
     */
    private function loadFromConfig(): array
    {
        if (! function_exists('config')) {
            return [];
        }
        $raw = config('atlas.maestro.personalization.workers');

        return is_array($raw) ? $raw : [];
    }

    /**
     * @param  array<string, array<string,mixed>>  $raw
     * @return array<string, array{max_files:int,max_loc:int,tier:string}>
     */
    private function normalize(array $raw): array
    {
        $out = [];
        foreach ($raw as $clientId => $prefs) {
            $clientId = trim((string) $clientId);
            if ($clientId === '' || ! is_array($prefs)) {
                continue;
            }
            $out[$clientId] = $this->normalizeProfile($prefs);
        }

        return $out;
    }

    /**
     * @param  array<string,mixed>  $prefs
     * @return array{max_files:int,max_loc:int,tier:string}
     */
    private function normalizeProfile(array $prefs): array
    {
        return [
            'max_files' => max(1, (int) ($prefs['max_files'] ?? self::DEFAULT_PROFILE['max_files'])),
            'max_loc' => max(1, (int) ($prefs['max_loc'] ?? self::DEFAULT_PROFILE['max_loc'])),
            'tier' => (string) ($prefs['tier'] ?? self::DEFAULT_PROFILE['tier']),
        ];
    }
}
