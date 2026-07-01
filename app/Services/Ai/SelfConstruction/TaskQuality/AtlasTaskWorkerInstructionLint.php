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
 *   - idle_before_drain — allows the worker to stop before the queue is actually empty
 *   - proxy_green_language — allows declaring success without running real tests/gates
 *   - missing_give_back_protocol — describes a resolve/report step but never mentions give_back
 *
 * Each finding also drives a structured `violations` entry (violation_code, severity, repair_hint).
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

    public const WEAKEN_TESTS_REGEX = '/(skip|delete|remove|comment\s+out|disable|weaken)\s+(the\s+)?(failing\s+)?tests?\b|make\s+the\s+tests?\s+pass\s+without/i';

    public const LOWER_ACCEPTANCE_REGEX = '/(lower|relax|reduce|loosen|weaken)\s+(the\s+)?acceptance(\s+criteria|\s+bar)?/i';

    public const IDLE_BEFORE_DRAIN_REGEX = '/(?<!do not |never |must not |should not |won\'t )(stop\s+(before|prior\s+to)\s+(the\s+)?queue\s+(is\s+)?(dry|empty|drained)|stop\s+at\s+a\s+good\s+point|(?:ok(?:ay)?|fine)\s+to\s+stop\s+early|pause\s+(?:indefinitely|until\s+told))/i';

    public const PROXY_GREEN_REGEX = '/(?<!do not |never |must not |should not |won\'t )(assume\s+(the\s+)?tests?\s+pass(es)?|mark\s+(as\s+)?(done|success(ful)?)\s+without\s+(running\s+)?tests?|declare\s+success\s+without\s+(proof|running\s+tests?)|report\s+success\s+even\s+if\s+(unsure|not\s+verified)|fake\s+(the\s+)?(passing|test)\s+result)/i';

    public const RESOLUTION_STEP_MENTIONED_REGEX = '/outcome\s*=\s*success|report\s+[^.]*outcome|resolve\s+the\s+task/i';

    public const GIVE_BACK_MENTIONED_REGEX = '/give[_\s-]?back/i';

    private const SEVERITY_BY_VIOLATION_CODE = [
        'edit_outside_allowed_files' => 'critical',
        'run_git_manually' => 'critical',
        'idle_before_drain' => 'critical',
        'proxy_green_language' => 'critical',
        'ignore_give_back_when_capability_exists' => 'high',
        'missing_give_back_protocol' => 'high',
        'implement_duplicate_canonical_symbol' => 'high',
        'weaken_tests' => 'high',
        'lower_acceptance_criteria' => 'high',
        'ask_human_for_normal_progress' => 'medium',
    ];

    private const REPAIR_HINT_BY_VIOLATION_CODE = [
        'edit_outside_allowed_files' => 'repair: restrict instructions to allowed_files only',
        'run_git_manually' => 'repair: remove manual git commands; only report --commit may commit',
        'idle_before_drain' => 'repair: remove any language permitting the worker to stop before the queue is empty',
        'proxy_green_language' => 'repair: remove any language permitting declared success without running the real tests/gates',
        'ignore_give_back_when_capability_exists' => 'repair: remove the instruction to ignore or skip give_back',
        'missing_give_back_protocol' => 'repair: add explicit give_back instructions for impossible/duplicate tasks',
        'implement_duplicate_canonical_symbol' => 'repair: reuse the existing canonical symbol instead of reimplementing it',
        'weaken_tests' => 'repair: remove the instruction to skip/delete/weaken failing tests',
        'lower_acceptance_criteria' => 'repair: remove the instruction to relax or lower acceptance criteria',
        'ask_human_for_normal_progress' => 'repair: remove ask-human wording for normal progress (bootstrap/visibility phrasing is fine)',
    ];

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
        if (preg_match(self::WEAKEN_TESTS_REGEX, $text)) {
            $findings[] = 'weaken_tests';
        }
        if (preg_match(self::LOWER_ACCEPTANCE_REGEX, $text)) {
            $findings[] = 'lower_acceptance_criteria';
        }
        if (preg_match(self::IDLE_BEFORE_DRAIN_REGEX, $text)) {
            $findings[] = 'idle_before_drain';
        }
        if (preg_match(self::PROXY_GREEN_REGEX, $text)) {
            $findings[] = 'proxy_green_language';
        }
        // A prompt that describes reporting/resolving a task but never mentions give_back at all
        // leaves the worker with no path for an impossible/duplicate task — distinct from
        // explicitly telling the worker to ignore give_back (caught above).
        if (preg_match(self::RESOLUTION_STEP_MENTIONED_REGEX, $text) && ! preg_match(self::GIVE_BACK_MENTIONED_REGEX, $text)) {
            $findings[] = 'missing_give_back_protocol';
        }

        $dups = is_array($packet['duplicate_canonical_candidates'] ?? null) ? array_values(array_map('strval', $packet['duplicate_canonical_candidates'])) : [];
        foreach ($dups as $sym) {
            if ($sym !== '') {
                $findings[] = 'implement_duplicate_canonical_symbol:'.$sym;
            }
        }

        $findings = array_values(array_unique($findings));
        sort($findings, SORT_STRING);

        $violations = array_map(function (string $finding): array {
            $code = str_contains($finding, ':') ? substr($finding, 0, (int) strpos($finding, ':')) : $finding;

            return [
                'violation_code' => $code,
                'severity' => self::SEVERITY_BY_VIOLATION_CODE[$code] ?? 'medium',
                'repair_hint' => self::REPAIR_HINT_BY_VIOLATION_CODE[$code] ?? 'repair: revise the instruction to remove the flagged phrasing',
            ];
        }, $findings);

        return [
            'schema' => self::SCHEMA,
            'accepted' => $findings === [],
            'findings' => $findings,
            'violations' => $violations,
        ];
    }
}
