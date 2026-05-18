<?php

declare(strict_types=1);

namespace App\Services\Ai\Vox\Execution;

/**
 * Centralised hard-veto regex list.
 *
 * Source of truth for "this MUST NOT be executed by any Vox V3 executor —
 * not via shell, not via provider CLI, not via terminal_propose preview".
 * Mirrors the patterns the VoxIntentExtractor uses to flag R4 risk, but is
 * a separate constant set because the extractor's job is classification
 * (R4 may include grey-zone phrases) while the gate's job is binary block.
 *
 * The patterns are kept conservative and explicit. Any new entry must be
 * justified in a PR comment AND covered by a feature test in
 * tests/Feature/Ai/Vox/.
 */
final class VoxHardVetoList
{
    /**
     * Each item: { label, pattern }. Patterns are PCRE with the `i` and `u`
     * modifiers so accented PT-BR input is matched the same as plain ASCII.
     *
     * @return list<array{label: string, pattern: string}>
     */
    public static function patterns(): array
    {
        return [
            ['label' => 'rm_rf',              'pattern' => '/\brm\s+-[a-z]*r[a-z]*f[a-z]*\b/iu'],
            ['label' => 'rm_fr',              'pattern' => '/\brm\s+-[a-z]*f[a-z]*r[a-z]*\b/iu'],
            ['label' => 'sudo',               'pattern' => '/\bsudo\b/iu'],
            ['label' => 'dd_if',              'pattern' => '/\bdd\s+if=/iu'],
            ['label' => 'mkfs',               'pattern' => '/\bmkfs\b/iu'],
            ['label' => 'git_push_force',     'pattern' => '/\bgit\s+push\s+(?:--force|-f|--force-with-lease)\b/iu'],
            ['label' => 'git_reset_hard',     'pattern' => '/\bgit\s+reset\s+--hard\b/iu'],
            ['label' => 'drop_database',      'pattern' => '/\bdrop\s+database\b/iu'],
            ['label' => 'truncate_table',     'pattern' => '/\btruncate(?:\s+table)?\b/iu'],
            ['label' => 'curl_pipe_shell',    'pattern' => '/\bcurl\b[^\n]*\|\s*(?:sh|bash|zsh|fish)\b/iu'],
            ['label' => 'wget_pipe_shell',    'pattern' => '/\bwget\b[^\n]*\|\s*(?:sh|bash|zsh|fish)\b/iu'],
            ['label' => 'deploy_auto',        'pattern' => '/\b(?:auto[-_\s]?deploy|deploy[-_\s]?auto)\b/iu'],
            ['label' => 'force_push',         'pattern' => '/\bforce[\s\-]push\b/iu'],
            ['label' => 'system_path_rm',     'pattern' => '/\brm\b[^\n]*\b\/(?:bin|sbin|etc|usr|var|System|Library)\b/iu'],
        ];
    }

    /**
     * Returns the first matching label, or null if none match. We keep the
     * lookup deterministic (first-pattern-wins) so error messages are stable
     * for tests.
     */
    public static function firstViolation(string $text): ?string
    {
        if ($text === '') {
            return null;
        }
        foreach (self::patterns() as $rule) {
            if (preg_match($rule['pattern'], $text) === 1) {
                return $rule['label'];
            }
        }

        return null;
    }
}
