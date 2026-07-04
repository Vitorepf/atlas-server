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
 *   4. task_families (list<string>) is ALWAYS the union of existing + incoming valid families —
 *      a lower-priority registerWithMerge call never removes a family the client already had,
 *      it only ever adds new valid ones.
 *
 * VALIDATION (register / registerWithMerge / config-seeded entries): a preference entry is
 * rejected outright — the store is left unchanged — when any known key carries a malformed
 * shape: max_files/max_loc/tier/source/priority given as a non-scalar (array), or task_families
 * given as anything other than a list<string>. inspect()/all() only ever return the whitelisted,
 * normalized keys — raw/unknown submitted keys never leak through.
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
     * Returns false and leaves the store unchanged when $prefs is malformed.
     */
    public function register(string $clientId, array $prefs): bool
    {
        $clientId = trim($clientId);
        if ($clientId === '') {
            return false;
        }
        $validation = $this->validate($prefs);
        if (! $validation['valid']) {
            return false;
        }
        $this->declared[$clientId] = $this->normalizeProfile($prefs);

        return true;
    }

    /**
     * Register with deterministic conflict resolution:
     *   - higher priority wins
     *   - same priority → more recent registered_at wins
     *   - same priority + same timestamp → lower source alphabetically wins
     *   - task_families is always the union of existing + incoming, regardless of which side
     *     wins the scalar fields.
     * Returns false and leaves the store unchanged when $prefs is malformed.
     */
    public function registerWithMerge(string $clientId, array $prefs): bool
    {
        $clientId = trim($clientId);
        if ($clientId === '') {
            return false;
        }
        $validation = $this->validate($prefs);
        if (! $validation['valid']) {
            return false;
        }
        $incoming = $this->normalizeProfile($prefs);

        if (! isset($this->declared[$clientId])) {
            $this->declared[$clientId] = $incoming;

            return true;
        }

        $existing = $this->declared[$clientId];
        $inPri = self::PRIORITY_ORDER[$incoming['priority']] ?? 1;
        $exPri = self::PRIORITY_ORDER[$existing['priority']] ?? 1;

        $winner = $existing;
        if ($inPri > $exPri) {
            $winner = $incoming;
        } elseif ($inPri === $exPri) {
            if ((int) $incoming['registered_at'] > (int) $existing['registered_at']) {
                $winner = $incoming;
            } elseif ((int) $incoming['registered_at'] === (int) $existing['registered_at']
                && (string) $incoming['source'] < (string) $existing['source']) {
                $winner = $incoming;
            }
        }
        // existing higher priority → winner stays $existing

        $winner['task_families'] = array_values(array_unique(array_merge(
            (array) $existing['task_families'],
            (array) $incoming['task_families'],
        )));
        sort($winner['task_families'], SORT_STRING);

        $this->declared[$clientId] = $winner;

        return true;
    }

    /**
     * Validates a raw preference submission BEFORE normalization. A known key carrying the
     * wrong shape (max_files/max_loc/tier/source/priority as an array, or task_families as
     * anything other than a list<string>) rejects the whole submission.
     *
     * @param  array<string,mixed>  $prefs
     * @return array{valid:bool, reasons:list<string>}
     */
    public function validate(array $prefs): array
    {
        $reasons = [];

        foreach (['max_files', 'max_loc', 'tier', 'source', 'priority'] as $scalarKey) {
            if (array_key_exists($scalarKey, $prefs) && is_array($prefs[$scalarKey])) {
                $reasons[] = "{$scalarKey}_must_be_scalar";
            }
        }

        if (array_key_exists('task_families', $prefs)) {
            $taskFamilies = $prefs['task_families'];
            $isValidList = is_array($taskFamilies)
                && array_is_list($taskFamilies)
                && array_reduce($taskFamilies, static fn (bool $carry, mixed $v): bool => $carry && is_string($v), true);
            if (! $isValidList) {
                $reasons[] = 'task_families_must_be_a_list_of_strings';
            }
        }

        return [
            'valid' => $reasons === [],
            'reasons' => $reasons,
        ];
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

        $profile['provenance'] = [
            'source' => $profile['source'],
            'priority' => $profile['priority'],
            'registered_at' => $profile['registered_at'],
        ];

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
            if ($clientId === '' || ! is_array($prefs) || ! $this->validate($prefs)['valid']) {
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

        $taskFamilies = array_key_exists('task_families', $prefs) && is_array($prefs['task_families'])
            ? array_values(array_map('strval', $prefs['task_families']))
            : [];

        return [
            'max_files' => max(1, (int) ($prefs['max_files'] ?? self::DEFAULT_PROFILE['max_files'])),
            'max_loc' => max(1, (int) ($prefs['max_loc'] ?? self::DEFAULT_PROFILE['max_loc'])),
            'tier' => (string) ($prefs['tier'] ?? self::DEFAULT_PROFILE['tier']),
            'source' => (string) ($prefs['source'] ?? 'declared'),
            'task_family' => (string) ($prefs['task_family'] ?? ''),
            'task_families' => $taskFamilies,
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

    /**
     * Outcome-calibrated preference profile: preferences weighted by verified outcomes.
     *
     * Increases preference only from verified successes with runnable evidence.
     * Decays preference on recent give_back, poison or retry-loop outcomes.
     *
     * @param  string  $clientId
     * @param  list<array{outcome?:string, task_family?:string, evidence?:list<string>}>  $recentOutcomes
     * @return array{worker_preferences:array<string,mixed>, decayed_preferences:array<string,mixed>, confidence:float, evidence_refs:list<string>}
     */
    public function outcomeCalibratedProfile(string $clientId, array $recentOutcomes): array
    {
        $baseProfile = $this->inspect($clientId);
        $evidenceRefs = [];
        $preferenceScore = 0.0;
        $decayCount = 0;
        $successCount = 0;

        foreach ($recentOutcomes as $outcome) {
            $outcomeType = (string) ($outcome['outcome'] ?? '');
            $evidence = (array) ($outcome['evidence'] ?? []);

            switch ($outcomeType) {
                case 'success':
                case 'resolved':
                    // Only count as verified success if runnable evidence is present
                    if ($evidence !== []) {
                        $preferenceScore += 1.0;
                        $successCount++;
                        foreach ($evidence as $ref) {
                            $evidenceRefs[] = $ref;
                        }
                    }
                    break;

                case 'give_back':
                case 'poison':
                case 'poison_detected':
                case 'retry_loop':
                    $preferenceScore -= 1.5; // Decay faster than success builds
                    $decayCount++;
                    break;
            }
        }

        $totalOutcomes = $successCount + $decayCount;
        $confidence = $totalOutcomes > 0 ? round(min(1.0, $totalOutcomes / 10), 4) : 0.0;

        // Compute decayed preferences
        $decayedPreferences = $baseProfile;
        if ($preferenceScore < 0) {
            // Negative score: decay all preference values
            $decayedPreferences['max_files'] = max(1, (int) $baseProfile['max_files'] / 2);
            $decayedPreferences['max_loc'] = max(1, (int) $baseProfile['max_loc'] / 2);
            $decayedPreferences['tier'] = 'decayed';
        } elseif ($preferenceScore > 0) {
            // Positive score: boost preferences slightly
            $decayedPreferences['max_files'] = (int) $baseProfile['max_files'] + (int) min(3, $preferenceScore);
            $decayedPreferences['max_loc'] = (int) $baseProfile['max_loc'] + (int) min(100, $preferenceScore * 20);
            $decayedPreferences['tier'] = $successCount >= 3 ? 'calibrated_high' : 'calibrated';
        }

        return [
            'worker_preferences' => $baseProfile,
            'decayed_preferences' => $decayedPreferences,
            'confidence' => $confidence,
            'evidence_refs' => array_unique($evidenceRefs),
        ];
    }
}
