<?php

declare(strict_types=1);

namespace App\Services\Ai\SelfConstruction\TaskFabric;

/**
 * Detects contradictory acceptance criteria before a task spec is enqueued.
 *
 * CONFLICT KINDS (in order of severity):
 *   mutually_exclusive_behavior — two criteria directly contradict each other for the same condition
 *   out_of_scope_file           — a criterion references a .php file outside allowed_files
 *   schema_missing              — a criterion references a class/schema not present in scope_in files
 *   weak_runnable_proof         — no test command, or command cannot prove the stated behaviour
 *
 * CONFLICT LEVELS:
 *   blocking — spec is unsatisfiable; enqueue must be refused
 *   warning  — potential issue that does not make the spec impossible
 *   none     — no conflicts detected
 *
 * Complementary positive+negative criteria (e.g. "accepts X when Y" / "rejects X when Z")
 * that target different conditions are NOT treated as mutually exclusive.
 *
 * INPUT:
 *   acceptance_criteria: list<string>
 *   allowed_files:       list<string>   — file paths the task is permitted to touch
 *   scope_in:            list<string>   — class/schema names or file basenames available to the task
 *   test_commands:       list<string>   — runnable proof commands
 *
 * OUTPUT:
 *   { schema, conflict_level:'none'|'warning'|'blocking', conflicts:list<{kind,detail}>, reasons:list<string> }
 *
 * PURE / DETERMINISTIC. No I/O.
 */
final class AtlasTaskFabricAcceptanceConflictDetector
{
    public const SCHEMA = 'atlas.task_fabric.acceptance_conflict_detector.v1';

    public const LEVEL_NONE     = 'none';
    public const LEVEL_WARNING  = 'warning';
    public const LEVEL_BLOCKING = 'blocking';

    public const KIND_MUTUALLY_EXCLUSIVE  = 'mutually_exclusive_behavior';
    public const KIND_OUT_OF_SCOPE_FILE   = 'out_of_scope_file';
    public const KIND_SCHEMA_MISSING      = 'schema_missing';
    public const KIND_WEAK_RUNNABLE_PROOF = 'weak_runnable_proof';

    /** Negation prefixes that flip the meaning of a behaviour statement. */
    private const NEGATION_PREFIXES = ['does not', 'cannot', 'never', 'must not', 'should not', 'rejects', 'refuses', 'fails', 'must never'];

    /** Test-runner keywords that indicate a meaningful runnable proof. */
    private const TEST_RUNNER_KEYWORDS = ['phpunit', 'artisan test', 'artisan:test', 'pest', 'php artisan test'];

    /**
     * @param  array<string,mixed>  $spec
     * @return array<string,mixed>
     */
    public function detect(array $spec): array
    {
        $criteria     = array_values(array_map('strval', (array) ($spec['acceptance_criteria'] ?? [])));
        $allowedFiles = array_values(array_map('strval', (array) ($spec['allowed_files'] ?? [])));
        $scopeIn      = array_values(array_map('strval', (array) ($spec['scope_in'] ?? [])));
        $testCommands = array_values(array_map('strval', (array) ($spec['test_commands'] ?? [])));

        $conflicts = [];

        $this->detectMutuallyExclusiveBehavior($criteria, $conflicts);
        $this->detectOutOfScopeFiles($criteria, $allowedFiles, $conflicts);
        $this->detectSchemaMissing($criteria, $scopeIn, $conflicts);
        $this->detectWeakRunnableProof($testCommands, $conflicts);

        $level   = $this->resolveLevel($conflicts);
        $reasons = array_map(static fn (array $c): string => $c['kind'].': '.$c['detail'], $conflicts);

        return [
            'schema'         => self::SCHEMA,
            'conflict_level' => $level,
            'conflicts'      => $conflicts,
            'reasons'        => array_values($reasons),
        ];
    }

    // ── mutually exclusive behaviour ─────────────────────────────────────────

    /**
     * @param  list<string>          $criteria
     * @param  list<array<string,string>>  $conflicts  (out)
     */
    private function detectMutuallyExclusiveBehavior(array $criteria, array &$conflicts): void
    {
        // Build (positive_statement, negative_statement) pairs.
        // A positive statement "returns X" is contradicted by "does not return X" etc.
        $positives = [];  // normalised_verb_object => [original_criterion]
        $negatives = [];  // normalised_verb_object => [original_criterion]

        foreach ($criteria as $criterion) {
            $lower = strtolower($criterion);

            $isNegative = false;
            $stripped   = $lower;
            foreach (self::NEGATION_PREFIXES as $prefix) {
                if (str_contains($lower, $prefix)) {
                    $isNegative = true;
                    $stripped   = str_replace($prefix, '', $lower);
                    break;
                }
            }

            // Extract the verb-object fingerprint: first verb + next significant token.
            $fingerprint = $this->extractFingerprint($stripped);
            if ($fingerprint === '') {
                continue;
            }

            if ($isNegative) {
                $negatives[$fingerprint][] = $criterion;
            } else {
                $positives[$fingerprint][] = $criterion;
            }
        }

        // A conflict exists when the SAME fingerprint appears in BOTH positives and negatives.
        foreach ($positives as $fp => $posCriteria) {
            if (! isset($negatives[$fp])) {
                continue;
            }
            foreach ($posCriteria as $pos) {
                foreach ($negatives[$fp] as $neg) {
                    $conflicts[] = [
                        'kind'   => self::KIND_MUTUALLY_EXCLUSIVE,
                        'detail' => sprintf('"%s" contradicts "%s"', $pos, $neg),
                    ];
                }
            }
        }
    }

    private function extractFingerprint(string $text): string
    {
        static $stopWords = ['the', 'and', 'or', 'for', 'with', 'when', 'that', 'this', 'its',
            'are', 'was', 'has', 'have', 'been', 'will', 'can', 'may', 'from', 'into',
            'given', 'then', 'also', 'only', 'both', 'any', 'all', 'not'];

        $text  = preg_replace('/[^a-z0-9\s]/', ' ', $text) ?? $text;
        $words = explode(' ', $text);
        $words = array_filter($words, static fn (string $w): bool => strlen($w) >= 4 && ! in_array($w, $stopWords, true));
        // Normalize trailing 's' so "returns"/"return" hash equally.
        $words = array_map(static fn (string $w): string => rtrim($w, 's'), array_values($words));

        return implode('_', array_slice($words, 0, 4));
    }

    // ── out-of-scope file references ─────────────────────────────────────────

    /**
     * @param  list<string>          $criteria
     * @param  list<string>          $allowedFiles
     * @param  list<array<string,string>>  $conflicts  (out)
     */
    private function detectOutOfScopeFiles(array $criteria, array $allowedFiles, array &$conflicts): void
    {
        if ($allowedFiles === []) {
            return;
        }

        foreach ($criteria as $criterion) {
            // Match anything that looks like a PHP file path.
            preg_match_all('/[\w\/\-]+\.php/', $criterion, $matches);
            foreach ($matches[0] as $ref) {
                if (! $this->fileIsAllowed($ref, $allowedFiles)) {
                    $conflicts[] = [
                        'kind'   => self::KIND_OUT_OF_SCOPE_FILE,
                        'detail' => sprintf('file "%s" referenced in criterion is outside allowed_files', $ref),
                    ];
                }
            }
        }
    }

    private function fileIsAllowed(string $ref, array $allowedFiles): bool
    {
        foreach ($allowedFiles as $allowed) {
            if ($allowed === $ref || str_ends_with($allowed, '/'.$ref) || str_ends_with($ref, basename($allowed))) {
                return true;
            }
        }

        return false;
    }

    // ── schema / class missing from scope_in ─────────────────────────────────

    /**
     * @param  list<string>          $criteria
     * @param  list<string>          $scopeIn
     * @param  list<array<string,string>>  $conflicts  (out)
     */
    private function detectSchemaMissing(array $criteria, array $scopeIn, array &$conflicts): void
    {
        if ($scopeIn === []) {
            return;
        }

        // Collect identifiers that look like class names (PascalCase words of >= 5 chars).
        $scopeSet = [];
        foreach ($scopeIn as $item) {
            $scopeSet[basename($item, '.php')] = true;
        }

        foreach ($criteria as $criterion) {
            // Only detect Atlas-prefixed class names to avoid false positives on normal prose.
            preg_match_all('/\bAtlas[A-Z][a-zA-Z]+\b/', $criterion, $matches);
            foreach ($matches[0] as $className) {
                if (! isset($scopeSet[$className])) {
                    $conflicts[] = [
                        'kind'   => self::KIND_SCHEMA_MISSING,
                        'detail' => sprintf('class/schema "%s" referenced in criterion is not in scope_in', $className),
                    ];
                }
            }
        }
    }

    // ── weak runnable proof ──────────────────────────────────────────────────

    /**
     * @param  list<string>          $testCommands
     * @param  list<array<string,string>>  $conflicts  (out)
     */
    private function detectWeakRunnableProof(array $testCommands, array &$conflicts): void
    {
        if ($testCommands === []) {
            $conflicts[] = [
                'kind'   => self::KIND_WEAK_RUNNABLE_PROOF,
                'detail' => 'no test_commands provided; spec has no runnable proof',
            ];

            return;
        }

        foreach ($testCommands as $cmd) {
            $lower = strtolower($cmd);
            $found = false;
            foreach (self::TEST_RUNNER_KEYWORDS as $keyword) {
                if (str_contains($lower, $keyword)) {
                    $found = true;
                    break;
                }
            }
            if (! $found) {
                $conflicts[] = [
                    'kind'   => self::KIND_WEAK_RUNNABLE_PROOF,
                    'detail' => sprintf('command "%s" does not invoke a recognised test runner', $cmd),
                ];
            }
        }
    }

    // ── level resolution ─────────────────────────────────────────────────────

    /** @param  list<array<string,string>>  $conflicts */
    private function resolveLevel(array $conflicts): string
    {
        if ($conflicts === []) {
            return self::LEVEL_NONE;
        }

        $blockingKinds = [self::KIND_MUTUALLY_EXCLUSIVE, self::KIND_OUT_OF_SCOPE_FILE];
        foreach ($conflicts as $c) {
            if (in_array($c['kind'], $blockingKinds, true)) {
                return self::LEVEL_BLOCKING;
            }
        }

        return self::LEVEL_WARNING;
    }
}
