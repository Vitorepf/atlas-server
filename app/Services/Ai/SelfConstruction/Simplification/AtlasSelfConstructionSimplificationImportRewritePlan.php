<?php

declare(strict_types=1);

namespace App\Services\Ai\SelfConstruction\Simplification;

/**
 * Pure import/symbol rewrite planner. Converts a safe consolidation decision
 * (old symbol → new symbol) into concrete file-level rewrite steps. Never
 * rewrites blindly: a consumer outside allowed_files, an ambiguous alias, or
 * an identical before/after symbol all mark the plan unsafe instead of
 * silently emitting a step.
 *
 * INPUT:
 *   {
 *     target_symbol:               string  (fully-qualified old symbol)
 *     replacement_symbol:          string  (fully-qualified new symbol)
 *     allowed_files:                list<string>
 *     consumers?:                  list<{file:string, alias?:string}>
 *     config_string_occurrences?:  list<{file:string, before:string, after:string}>
 *   }
 *
 * OUTPUT:
 *   { schema, unsafe, blockers, steps: list<{file, before, after, reason, verification_hint}> }
 *
 * A step's reason is 'class_import_rewrite' for consumer entries and
 * 'config_string_rewrite' for config_string_occurrences entries. Steps are
 * ordered by file then reason for determinism.
 *
 * Pure: no I/O, no side effects — it only compiles the plan, never writes files.
 */
final class AtlasSelfConstructionSimplificationImportRewritePlan
{
    public const SCHEMA = 'atlas.self_construction.simplification_import_rewrite_plan.v1';

    public const REASON_CLASS_IMPORT_REWRITE = 'class_import_rewrite';

    public const REASON_CONFIG_STRING_REWRITE = 'config_string_rewrite';

    /**
     * @param  array<string,mixed>  $input
     * @return array<string,mixed>
     */
    public function plan(array $input): array
    {
        $targetSymbol = trim((string) ($input['target_symbol'] ?? ''));
        $replacementSymbol = trim((string) ($input['replacement_symbol'] ?? ''));
        $allowedFiles = array_values(array_unique(array_map('strval', (array) ($input['allowed_files'] ?? []))));
        $consumers = is_array($input['consumers'] ?? null) ? $input['consumers'] : [];
        $configOccurrences = is_array($input['config_string_occurrences'] ?? null) ? $input['config_string_occurrences'] : [];

        $blockers = [];

        if ($targetSymbol === '' || $replacementSymbol === '') {
            $blockers[] = 'missing_target_or_replacement_symbol';
        } elseif ($targetSymbol === $replacementSymbol) {
            $blockers[] = 'before_and_after_symbols_identical';
        }

        $targetBasename = $this->basename($targetSymbol);

        $steps = [];

        foreach ($consumers as $consumer) {
            if (! is_array($consumer)) {
                continue;
            }
            $file = trim((string) ($consumer['file'] ?? ''));
            if ($file === '') {
                continue;
            }
            $alias = trim((string) ($consumer['alias'] ?? ''));
            $isDynamicReference = (bool) ($consumer['dynamic_reference'] ?? false);

            if (! in_array($file, $allowedFiles, true)) {
                $blockers[] = 'consumer_outside_allowed_files:'.$file;

                continue;
            }
            if ($isDynamicReference) {
                $blockers[] = 'dynamic_class_reference:'.$file;

                continue;
            }
            if ($alias !== '' && $alias !== $targetBasename) {
                $blockers[] = 'ambiguous_alias:'.$file.':'.$alias;

                continue;
            }

            $steps[] = [
                'file' => $file,
                'before' => $targetSymbol,
                'after' => $replacementSymbol,
                'reason' => self::REASON_CLASS_IMPORT_REWRITE,
                'verification_hint' => 'php -l '.$file,
            ];
        }

        foreach ($configOccurrences as $occurrence) {
            if (! is_array($occurrence)) {
                continue;
            }
            $file = trim((string) ($occurrence['file'] ?? ''));
            $before = (string) ($occurrence['before'] ?? '');
            $after = (string) ($occurrence['after'] ?? '');
            if ($file === '' || $before === '') {
                continue;
            }

            if (! in_array($file, $allowedFiles, true)) {
                $blockers[] = 'consumer_outside_allowed_files:'.$file;

                continue;
            }
            if ($before === $after) {
                $blockers[] = 'before_and_after_symbols_identical:'.$file;

                continue;
            }

            $steps[] = [
                'file' => $file,
                'before' => $before,
                'after' => $after,
                'reason' => self::REASON_CONFIG_STRING_REWRITE,
                'verification_hint' => 'php -l '.$file,
            ];
        }

        usort($steps, static function (array $a, array $b): int {
            $cmp = strcmp($a['file'], $b['file']);

            return $cmp !== 0 ? $cmp : strcmp($a['reason'], $b['reason']);
        });

        $blockers = array_values(array_unique($blockers));
        sort($blockers, SORT_STRING);

        $touchedFiles = array_values(array_unique(array_column($steps, 'file')));
        sort($touchedFiles, SORT_STRING);

        $explicitTestTargets = array_values(array_unique(array_map('strval', (array) ($input['test_targets'] ?? []))));
        $testTargets = $explicitTestTargets !== []
            ? $explicitTestTargets
            : array_values(array_filter($touchedFiles, static fn (string $f): bool => str_ends_with($f, 'Test.php')));
        sort($testTargets, SORT_STRING);

        $planHash = 'import_rewrite_'.substr(hash('sha256', (string) json_encode([
            'old_fqcn' => $targetSymbol,
            'new_fqcn' => $replacementSymbol,
            'steps' => $steps,
            'blockers' => $blockers,
        ], JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE)), 0, 32);

        return [
            'schema' => self::SCHEMA,
            'old_fqcn' => $targetSymbol,
            'new_fqcn' => $replacementSymbol,
            'unsafe' => $blockers !== [],
            'blockers' => $blockers,
            'unsafe_rewrite' => $blockers,
            'steps' => $steps,
            'touched_files' => $touchedFiles,
            'test_targets' => $testTargets,
            'plan_hash' => $planHash,
        ];
    }

    private function basename(string $fqcn): string
    {
        $parts = explode('\\', $fqcn);

        return end($parts);
    }
}
