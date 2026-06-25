<?php

declare(strict_types=1);

namespace App\Services\Ai\SelfConstruction\TaskQuality;

/**
 * Lints worker-facing instruction text inside a task packet. Catches phrasing that would otherwise
 * cause wasted implementation: editing outside allowed_files, running git manually, asking a human
 * for normal progress, implementing a duplicate canonical symbol, or ignoring give_back when the
 * capability already exists.
 *
 * Bootstrap / visibility language (operator dashboard, emergency stop, one-time bootstrap) is
 * EXPLICITLY ALLOWED — only steady-state human-dependency phrasing is flagged.
 *
 * INPUT:
 *   { packet_id, objective, allowed_files:list<string>, worker_instructions:string,
 *     duplicate_canonical_candidates?:list<string> }
 *
 * FINDING CLASSES:
 *   - edit_outside_allowed_files:<hint>
 *   - run_git_manually
 *   - ask_human_for_normal_progress
 *   - implement_duplicate_canonical_symbol:<name>
 *   - ignore_give_back_when_capability_exists
 *
 * INVARIANTS:
 *   - DETERMINISTIC envelope (findings sorted).
 *   - PURE.
 */
final class AtlasTaskWorkerInstructionLint
{
    public const SCHEMA = 'atlas.task_quality.worker_instruction_lint.v1';

    public const FORBIDDEN_GIT_REGEX = '/(?<!do not |never |may not |bootstrap )(git\s+(commit|push|merge|reset|rebase|cherry-pick))/i';

    public const ASK_HUMAN_REGEX = '/ask\s+(the\s+)?(human|operator)|wait\s+for\s+human\s+approval|request\s+manual\s+approval/i';

    /** Bootstrap exception — phrases that legitimately reference one-time bootstrap, visibility, or emergency. */
    public const BOOTSTRAP_EXCEPTION_REGEX = '/bootstrap[- ]only|emergency\s+(stop|halt)|operator\s+(visibility|dashboard|read[- ]?only)|one[- ]?time/i';

    public const IGNORE_GIVE_BACK_REGEX = '/ignore\s+give_back|skip\s+the\s+give[- ]back/i';

    public const OUTSIDE_SCOPE_REGEX = '/edit\s+files?\s+outside\s+allowed_files|modify\s+anywhere|touch\s+other\s+files?/i';

    /**
     * @param  array{packet_id?:string, objective?:string, allowed_files?:list<string>, worker_instructions?:string, duplicate_canonical_candidates?:list<string>}  $packet
     * @return array{schema:string, accepted:bool, findings:list<string>}
     */
    public function lint(array $packet): array
    {
        $findings = [];
        $text = (string) ($packet['worker_instructions'] ?? '').' '.(string) ($packet['objective'] ?? '');
        $bootstrapAllowed = preg_match(self::BOOTSTRAP_EXCEPTION_REGEX, $text) === 1;

        if (preg_match(self::OUTSIDE_SCOPE_REGEX, $text, $m)) {
            $findings[] = 'edit_outside_allowed_files:'.trim($m[0]);
        }
        if (! $bootstrapAllowed && preg_match(self::FORBIDDEN_GIT_REGEX, $text)) {
            $findings[] = 'run_git_manually';
        }
        if (! $bootstrapAllowed && preg_match(self::ASK_HUMAN_REGEX, $text)) {
            $findings[] = 'ask_human_for_normal_progress';
        }
        if (preg_match(self::IGNORE_GIVE_BACK_REGEX, $text)) {
            $findings[] = 'ignore_give_back_when_capability_exists';
        }

        $dups = is_array($packet['duplicate_canonical_candidates'] ?? null) ? array_values(array_map('strval', $packet['duplicate_canonical_candidates'])) : [];
        foreach ($dups as $sym) {
            if ($sym !== '') {
                $findings[] = 'implement_duplicate_canonical_symbol:'.$sym;
            }
        }

        $findings = array_values(array_unique($findings));
        sort($findings, SORT_STRING);

        return [
            'schema' => self::SCHEMA,
            'accepted' => $findings === [],
            'findings' => $findings,
        ];
    }
}
