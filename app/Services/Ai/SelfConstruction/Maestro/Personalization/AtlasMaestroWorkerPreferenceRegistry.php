<?php

declare(strict_types=1);

namespace App\Services\Ai\SelfConstruction\Maestro\Personalization;

/**
 * Pure read-model registry of DECLARED worker preferences (never learned, never inferred).
 *
 * Keyed by opaque `client_id`. Each entry stores:
 *   {max_files, max_loc, tier, source, task_family, priority, registered_at, ttl_seconds}
 *
 * Unknown ids return a neutral DEFAULT_PROFILE byte-identically.
 * Expired entries (registered_at + ttl_seconds < now) are treated as absent.
 * NEVER branches on platform / provider name; NEVER mutates the queue.
 *
 * Conflict resolution (registerWithMerge):
 *   1. Higher categorical priority wins (high > medium > low).
 *   2. Same priority → more recent registered_at wins.
 *   3. Same priority + same timestamp → lower source string alphabetically wins (deterministic).
 */
final class AtlasMaestroWorkerPreferenceRegistry
{
    public const DEFAULT_PROFILE = [
        'max_files' => 2,
        'max_loc' => 200,
        'tier' => 'neutral',
    ];

    /** @var array<string,int> */
    private const PRIORITY_ORDER = ['low' => 0, 'medium' => 1, 'high' => 2];

    /** @var array<string, array<string,mixed>> */
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

    /**
     * Always-override registration (existing behaviour, no conflict resolution).
     */
    public function register(string $clientId, array $prefs): void
    {
        $clientId = trim($clientId);
        if ($clientId === '') {
            return;
        }
        $this->declared[$clientId] = $this->normalizeProfile($prefs);
    }

    /**
     * Register with deterministic conflict resolution:
     *   - higher priority wins
     *   - same priority → more recent registered_at wins
     *   - same priority + same timestamp → lower source alphabetically wins
     */
    public function registerWithMerge(string $clientId, array $prefs): void
    {
        $clientId = trim($clientId);
        if ($clientId === '') {
            return;
        }
        $incoming = $this->normalizeProfile($prefs);

        if (! isset($this->declared[$clientId])) {
            $this->declared[$clientId] = $incoming;

            return;
        }

        $existing = $this->declared[$clientId];
        $inPri = self::PRIORITY_ORDER[$incoming['priority']] ?? 1;
        $exPri = self::PRIORITY_ORDER[$existing['priority']] ?? 1;

        if ($inPri > $exPri) {
            $this->declared[$clientId] = $incoming;
        } elseif ($inPri === $exPri) {
            if ((int) $incoming['registered_at'] > (int) $existing['registered_at']) {
                $this->declared[$clientId] = $incoming;
            } elseif ((int) $incoming['registered_at'] === (int) $existing['registered_at']
                && (string) $incoming['source'] < (string) $existing['source']) {
                $this->declared[$clientId] = $incoming;
            }
        }
        // existing higher priority → keep existing, do nothing
    }

    /**
     * @return array<string,mixed>
     */
    public function inspect(string $clientId): array
    {
        if (! isset($this->declared[$clientId])) {
            return self::DEFAULT_PROFILE;
        }
        $profile = $this->declared[$clientId];
        if ($this->isExpired($profile)) {
            return self::DEFAULT_PROFILE;
        }

        return $profile;
    }

    /**
     * @return array<string, array<string,mixed>>
     */
    public function all(): array
    {
        $now = time();
        $out = array_filter(
            $this->declared,
            fn (array $p): bool => ! $this->isExpiredAt($p, $now),
        );
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
     * @return array<string, array<string,mixed>>
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
     * @return array<string,mixed>
     */
    private function normalizeProfile(array $prefs): array
    {
        $priority = (string) ($prefs['priority'] ?? 'medium');
        if (! array_key_exists($priority, self::PRIORITY_ORDER)) {
            $priority = 'medium';
        }

        return [
            'max_files' => max(1, (int) ($prefs['max_files'] ?? self::DEFAULT_PROFILE['max_files'])),
            'max_loc' => max(1, (int) ($prefs['max_loc'] ?? self::DEFAULT_PROFILE['max_loc'])),
            'tier' => (string) ($prefs['tier'] ?? self::DEFAULT_PROFILE['tier']),
            'source' => (string) ($prefs['source'] ?? 'declared'),
            'task_family' => (string) ($prefs['task_family'] ?? ''),
            'priority' => $priority,
            'registered_at' => array_key_exists('registered_at', $prefs) ? (int) $prefs['registered_at'] : time(),
            'ttl_seconds' => isset($prefs['ttl_seconds']) ? (int) $prefs['ttl_seconds'] : null,
        ];
    }

    private function isExpired(array $profile): bool
    {
        return $this->isExpiredAt($profile, time());
    }

    private function isExpiredAt(array $profile, int $now): bool
    {
        $ttl = $profile['ttl_seconds'] ?? null;

        return $ttl !== null && (int) ($profile['registered_at'] ?? 0) + (int) $ttl < $now;
    }
}
