<?php

declare(strict_types=1);

namespace App\Services\Ai\AutonomousEvolution;

use App\Services\Ai\Support\DatabaseTableAvailability;
use Illuminate\Support\Facades\DB;
use Throwable;

/**
 * REAL-WORK CAMPAIGN SCORECARD · C0 — the honest ruler.
 *
 * The funnel ({@see AtlasLoopFunnelService}) answers "WHERE did each unit of work stop?" (stage). It is
 * silent on the question the canonical Loop definition actually cares about: "is the campaign producing
 * REAL evolution, or just proxy/cosmetic faxina?" (docs/loop-canonical-definition.md + memory
 * loop-not-proxy-cleanup-feedback). A campaign can run a perfect funnel — discovered→…→merged — while
 * every certified diff is a behaviour-preserving refactor or a whitespace edit. That is the Goodhart hole
 * this service closes.
 *
 * It reads each task's payload (the SPEC the loop committed to) and classifies it into exactly one of four
 * mutually-exclusive buckets:
 *   - REAL WORK     — bug_fix / feature / verification with a CONCRETE acceptance contract (red_required,
 *                     revert_recheck + commands, characterization with acceptance). Behaviour-CHANGING.
 *   - PROXY REFACTOR— a behaviour-PRESERVING refactor (revert stays green, no red, no bug/feature signal).
 *                     Useful sometimes, but proxy when it dominates a short campaign.
 *   - COSMETIC      — explicit cosmetic flag, cosmetic objective_kind, an advisory pattern refused as
 *                     cosmetic/trivial/negligible/proxy, or format/whitespace/comment-only text with no
 *                     real acceptance. The thing the loop must NEVER call evolution.
 *   - UNKNOWN       — insufficient payload to classify. Counted, never hidden.
 *
 * The verdict is a CLAIM POLICY: a campaign may only claim "produced real work" when there is at least one
 * real-work task AND zero cosmetic AND proxy does not dominate AND nothing is unclassifiable. The policy is
 * deliberately conservative (it would rather refuse a borderline campaign than let one launder proxy work
 * as evolution). A `true` claim is NOT a 24h-autonomy proof — only "this slice produced real work by task
 * classification". Read-only, fail-safe (any DB hiccup yields a well-formed `unavailable` scorecard, never
 * a crash), zero provider spend.
 */
final class AtlasLoopRealWorkScorecardService
{
    public const SCHEMA_VERSION = 'atlas.loop.real_work_scorecard.v1';

    public const TASKS_TABLE = 'atlas_loop_tasks';

    public const BUCKET_REAL = 'real_work';

    public const BUCKET_PROXY = 'proxy_refactor';

    public const BUCKET_COSMETIC = 'cosmetic';

    public const BUCKET_UNKNOWN = 'unknown';

    public const REAL_KIND_BUG_FIX = 'bug_fix';

    public const REAL_KIND_FEATURE = 'feature';

    public const REAL_KIND_VERIFICATION = 'verification';

    /** Per-task audit detail is bounded so a huge campaign cannot bloat the payload. */
    private const BREAKDOWN_CAP = 200;

    public function __construct(
        // `?? new` accessors are the mandatory backstop — `?Type $x = null` is NOT auto-injected by the
        // container, and these read-only collaborators must always resolve even when constructed by hand.
        private readonly ?AtlasLoopFunnelService $funnel = null,
        private readonly ?AtlasLoopObservabilityDigest $observability = null,
    ) {}

    /**
     * @return array<string,mixed> the canonical {@see self::SCHEMA_VERSION} payload.
     */
    public function scorecard(?string $campaignId = null): array
    {
        $campaignId = ($campaignId !== null && trim($campaignId) !== '') ? trim($campaignId) : null;

        if (! DatabaseTableAvailability::has(self::TASKS_TABLE)) {
            return $this->unavailable($campaignId, 'tasks_table_missing');
        }

        try {
            $rows = $this->loadTasks($campaignId);
        } catch (Throwable $e) {
            return $this->unavailable($campaignId, 'tasks_query_failed');
        }

        $counts = $this->emptyCounts();
        $pattern = ['driver' => 0, 'advisory' => 0, 'missing' => 0];
        $patternsSelected = [];
        $breakdown = [];

        foreach ($rows as $row) {
            $counts['tasks_total']++;

            // All payload-shape-dependent work is computed BEFORE any counting, so a malformed/off-nominal
            // row throws here and the catch degrades it to a single UNKNOWN + pattern-missing row — keeping
            // both partitions exact and honouring the never-crash contract (defence in depth; the scalar-safe
            // accessors already prevent the known Array-to-string hazards).
            try {
                $payload = $this->decodePayload($row->payload ?? null);
                $objectiveText = $this->scalarString($row->objective ?? '');
                $classification = $this->classify($payload, $objectiveText);
                $mode = $this->patternMode($payload);
                $selected = $this->patternSelected($payload);
                $objectiveKind = $this->objectiveKind($payload);
            } catch (Throwable) {
                $counts['unknown_tasks']++;
                $pattern['missing']++;

                continue;
            }

            switch ($classification['bucket']) {
                case self::BUCKET_REAL:
                    $counts['real_work_tasks']++;
                    $counts[$this->realKindCounter($classification['real_kind'])]++;
                    break;
                case self::BUCKET_PROXY:
                    $counts['proxy_refactor_tasks']++;
                    break;
                case self::BUCKET_COSMETIC:
                    $counts['cosmetic_tasks']++;
                    break;
                default:
                    $counts['unknown_tasks']++;
                    break;
            }

            // Pattern signals — present-but-undriven (no `pattern.mode`) counts as missing, so
            // driver + advisory + missing always partitions tasks_total.
            if ($mode === 'driver') {
                $pattern['driver']++;
            } elseif ($mode === 'advisory') {
                $pattern['advisory']++;
            } else {
                $pattern['missing']++;
            }
            if ($selected !== null) {
                $patternsSelected[$selected] = true;
            }

            if (count($breakdown) < self::BREAKDOWN_CAP) {
                $breakdown[] = [
                    'task_id' => $this->scalarString($row->id ?? ''),
                    'objective_kind' => $objectiveKind,
                    'bucket' => $classification['bucket'],
                    'real_kind' => $classification['real_kind'],
                    'reasons' => $classification['reasons'],
                ];
            }
        }

        $patternsSelectedList = array_keys($patternsSelected);
        sort($patternsSelectedList);

        $claimPolicy = $this->claimPolicy($counts);

        return [
            'schema_version' => self::SCHEMA_VERSION,
            'status' => 'ok',
            'campaign_id' => $campaignId,
            'generated_at' => now()->toIso8601String(),
            'tasks_total' => $counts['tasks_total'],
            'real_work_tasks' => $counts['real_work_tasks'],
            'bug_fix_tasks' => $counts['bug_fix_tasks'],
            'feature_tasks' => $counts['feature_tasks'],
            'verification_tasks' => $counts['verification_tasks'],
            'proxy_refactor_tasks' => $counts['proxy_refactor_tasks'],
            'cosmetic_tasks' => $counts['cosmetic_tasks'],
            'unknown_tasks' => $counts['unknown_tasks'],
            'real_work_ratio' => $this->ratio($counts['real_work_tasks'], $counts['tasks_total']),
            'proxy_ratio' => $this->ratio($counts['proxy_refactor_tasks'], $counts['tasks_total']),
            'cosmetic_ratio' => $this->ratio($counts['cosmetic_tasks'], $counts['tasks_total']),
            'pattern_signals' => [
                'pattern_driver_tasks' => $pattern['driver'],
                'pattern_advisory_tasks' => $pattern['advisory'],
                'pattern_missing_tasks' => $pattern['missing'],
                'patterns_selected' => $patternsSelectedList,
            ],
            'claim_policy' => $claimPolicy,
            'breakdown' => $breakdown,
            'breakdown_capped' => $counts['tasks_total'] > count($breakdown),
            'funnel' => $this->funnelSection($campaignId),
            'observability' => $this->observabilitySection($campaignId),
            'verdict' => $this->verdict($counts, $claimPolicy),
        ];
    }

    /**
     * Classify ONE task by its committed payload. Mutually-exclusive buckets, cosmetic-FIRST so a cosmetic
     * signal can never be laundered behind a real-looking objective_kind (the conservative, anti-Goodhart
     * direction: prefer a false refusal over a false "real work" claim).
     *
     * @param  array<string,mixed>  $payload
     * @return array{bucket:string, real_kind:?string, reasons:list<string>}
     */
    private function classify(array $payload, string $objectiveText): array
    {
        if ($payload === []) {
            return $this->bucket(self::BUCKET_UNKNOWN, null, ['no_payload']);
        }

        $kind = $this->objectiveKind($payload);
        $redRequired = $this->isTrue(data_get($payload, 'acceptance.red_required'));
        $revertRecheck = $this->isTrue(data_get($payload, 'revert_recheck'))
            || $this->isTrue(data_get($payload, 'acceptance.revert_recheck'));
        $concreteAcceptance = $this->hasConcreteAcceptance($payload, $redRequired);
        // A feature KIND is a proper `feature` / `feature_*` / `feature-*` token — NOT any string that merely
        // starts with the 7 chars "feature" (which would swallow `featured` / `featureless_refactor`). There
        // is deliberately NO bare `feature` payload flag: the spec's real-work signal is the objective_kind,
        // and a self-asserted boolean with no kind is exactly a laundering vector the honesty gate forbids.
        $featureKind = $kind === 'feature' || str_starts_with($kind, 'feature_') || str_starts_with($kind, 'feature-');
        $failureOrBugSignal = $redRequired
            || str_contains($kind, 'bug')
            || $this->present(data_get($payload, 'failure_handle'))
            || $this->present(data_get($payload, 'bug_handle'))
            || $this->present(data_get($payload, 'red_handle'));

        // ── 1. COSMETIC (hard blocker — checked before any real-looking signal) ──────────────────────────
        if ($this->cosmeticSignal($payload, $objectiveText, $kind, $concreteAcceptance, $redRequired)) {
            return $this->bucket(self::BUCKET_COSMETIC, null, ['cosmetic_signal']);
        }

        // ── 2. REAL WORK (behaviour-changing with a concrete contract) ──────────────────────────────────
        // Feature, like verification/characterization, requires a CONCRETE acceptance contract (consistent
        // with this class's invariant): an empty `feature` stub with no runnable acceptance is NOT proven
        // real work — it falls through to UNKNOWN, which refuses the claim.
        if ($featureKind && $concreteAcceptance) {
            return $this->bucket(self::BUCKET_REAL, self::REAL_KIND_FEATURE, ['feature+concrete_acceptance:'.$kind]);
        }
        if ($kind === 'bug_fix') {
            return $this->bucket(self::BUCKET_REAL, self::REAL_KIND_BUG_FIX, ['objective_kind=bug_fix']);
        }
        if ($kind === 'characterization_test' && $concreteAcceptance) {
            return $this->bucket(self::BUCKET_REAL, self::REAL_KIND_VERIFICATION, ['characterization_test+concrete_acceptance']);
        }
        if ($kind === 'verification' && $concreteAcceptance) {
            return $this->bucket(self::BUCKET_REAL, self::REAL_KIND_VERIFICATION, ['verification+concrete_acceptance']);
        }
        if ($redRequired) {
            return $this->bucket(self::BUCKET_REAL, self::REAL_KIND_BUG_FIX, ['acceptance.red_required=true']);
        }
        if ($revertRecheck && $concreteAcceptance) {
            return $this->bucket(self::BUCKET_REAL, self::REAL_KIND_BUG_FIX, ['revert_recheck+concrete_acceptance']);
        }

        // ── 3. PROXY REFACTOR (behaviour-preserving — no red, no revert, no bug/feature signal) ──────────
        if (str_starts_with($kind, 'refactor') && ! $revertRecheck && ! $redRequired && ! $failureOrBugSignal && ! $featureKind) {
            return $this->bucket(self::BUCKET_PROXY, null, ['refactor_behavior_preserving:'.$kind]);
        }

        // ── 4. UNKNOWN ──────────────────────────────────────────────────────────────────────────────────
        return $this->bucket(self::BUCKET_UNKNOWN, null, ['insufficient_signal'.($kind !== '' ? ':'.$kind : '')]);
    }

    /**
     * @param  array<string,mixed>  $payload
     */
    private function cosmeticSignal(array $payload, string $objectiveText, string $kind, bool $concreteAcceptance, bool $redRequired): bool
    {
        if ($this->isTrue(data_get($payload, 'cosmetic'))) {
            return true;
        }
        if ($kind === 'cosmetic') {
            return true;
        }
        if ($this->patternIndicatesCosmetic($payload)) {
            return true;
        }

        if (! $this->textLooksCosmetic($objectiveText)) {
            return false;
        }

        // The objective text is explicitly format/whitespace/comment-only. A verified-RED failing command
        // (red_required) proves real behaviour change and overrides the text. Otherwise the text is a
        // cosmetic signal when there is no concrete acceptance contract at all, OR when the kind is a
        // behaviour-preserving refactor (a frozen-sibling command does NOT make whitespace work real —
        // closing the no-op/sibling-command laundering vector for cosmetic refactors).
        if ($redRequired) {
            return false;
        }

        return ! $concreteAcceptance || str_starts_with($kind, 'refactor');
    }

    /**
     * @param  array<string,mixed>  $payload
     */
    private function patternIndicatesCosmetic(array $payload): bool
    {
        $pattern = data_get($payload, 'pattern');
        if (! is_array($pattern)) {
            return false;
        }
        if ($this->isTrue($pattern['cosmetic'] ?? null) || $this->isTrue(data_get($pattern, 'spec.trigger_schema.cosmetic'))) {
            return true;
        }

        // An advisory/driver selection that REFUSED with a cosmetic/trivial/negligible/proxy reason is a
        // first-class cosmetic signal about the task itself (the selector's anti-cosmetic gate fired).
        $selected = $pattern['selected'] ?? null;
        $rejected = $this->isTrue($pattern['rejected'] ?? null) || $selected === null || $selected === '';
        $reasonRaw = $pattern['reason'] ?? '';
        $reason = is_scalar($reasonRaw) ? strtolower((string) $reasonRaw) : '';

        return $rejected && $reason !== '' && preg_match('/cosmetic|trivial|negligible|proxy/', $reason) === 1;
    }

    private function textLooksCosmetic(string $text): bool
    {
        if (trim($text) === '') {
            return false;
        }

        return preg_match(
            '/\b(whitespace|formatting|format[- ]only|comment[- ]only|reformat(ting)?|cosmetic|typo|typos|indentation|spacing|lint[- ]?only|trailing\s+whitespace)\b/i',
            $text
        ) === 1;
    }

    /**
     * A concrete acceptance contract = a runnable command (or red_required, which implies a failing one).
     *
     * @param  array<string,mixed>  $payload
     */
    private function hasConcreteAcceptance(array $payload, bool $redRequired): bool
    {
        if ($redRequired) {
            return true; // a verified-RED failing command is the strongest concrete contract.
        }
        $commands = data_get($payload, 'acceptance.commands');
        if (is_array($commands)) {
            foreach ($commands as $c) {
                if ($this->isRunnableCommand($c)) {
                    return true;
                }
            }
        }
        foreach (['acceptance.command', 'acceptance_command', 'acceptance.command_line'] as $path) {
            if ($this->isRunnableCommand(data_get($payload, $path))) {
                return true;
            }
        }

        return false;
    }

    /**
     * A command counts as a concrete acceptance contract only if it invokes a recognised test/build runner.
     *
     * This is a fail-CLOSED ALLOW-LIST, not a no-op blocklist: a blocklist is a losing game (`:`, `true`,
     * `true && true`, `:; true`, `exit  0`, `sleep 0`, `pwd`, `cat /dev/null`, `return 0`, bare `echo` … are
     * endless no-op variants, and the loop's own data already contains literal `true` placeholders). Instead,
     * a command is "runnable" ONLY when it matches a known runner shape; everything else is treated as NOT a
     * concrete contract, so a degenerate/fake acceptance can never flip on the concrete-acceptance flag and
     * launder cosmetic/proxy work into a real-work claim. The scorecard only string-classifies (never
     * executes), so it judges the SHAPE of the command, not its exit code. Calibrated against the loop's real
     * acceptance shapes: the dominant `php <path>.php` (running a generated/frozen test file) and
     * `./vendor/bin/phpunit …`. Over-rejecting an unrecognised command is the safe direction (it refuses the
     * claim) — the conservative posture the C0 honesty gate requires.
     */
    private function isRunnableCommand(mixed $command): bool
    {
        if (! is_string($command)) {
            return false;
        }
        $cmd = strtolower(trim($command));
        if ($cmd === '' || str_starts_with($cmd, '#')) {
            return false;
        }

        // The loop's dominant acceptance shape: `php <path>.php` (run a generated/frozen test file).
        if (preg_match('/\bphp\s+\S+\.php\b/', $cmd) === 1) {
            return true;
        }

        // A recognised test/build runner anywhere in the command. `vendor/bin/` covers phpunit/pest/pint/
        // phpstan/psalm/rector; `bin/atlas` is the project CLI. Bare no-op binaries match none of these.
        return preg_match(
            '#\b(phpunit|pest|artisan\s+test|pytest|go\s+test|cargo\s+test|dotnet\s+test|rspec|jest|vitest|phpstan|psalm|rector|pint|gradle|mvn|composer\s+(test|run)|(npm|yarn|pnpm)\s+(test|run)|make)\b|vendor/bin/|(^|\s)\.?/?bin/atlas\b#',
            $cmd
        ) === 1;
    }

    /**
     * The claim policy — the whole point of C0. Named blockers, conservative ALLOW.
     *
     * @param  array<string,int>  $counts
     * @return array<string,mixed>
     */
    private function claimPolicy(array $counts): array
    {
        $blockers = [];

        if ($counts['tasks_total'] === 0) {
            $blockers[] = 'no_tasks_observed';
        } else {
            if ($counts['real_work_tasks'] === 0) {
                $blockers[] = 'no_real_work_tasks';
            }
            if ($counts['cosmetic_tasks'] > 0) {
                $blockers[] = 'cosmetic_work_observed';
            }
            if ($counts['proxy_refactor_tasks'] > 0 && $counts['proxy_refactor_tasks'] >= $counts['real_work_tasks']) {
                $blockers[] = 'proxy_refactor_dominates';
            }
            if ($counts['unknown_tasks'] > 0) {
                $blockers[] = 'unknown_work_observed';
            }
        }

        return [
            'loop_real_work_claim_allowed' => $blockers === [],
            'blockers' => $blockers,
            'rule' => 'Allowed only when: tasks_total>0 AND real_work_tasks>=1 AND cosmetic_tasks=0 AND '
                .'proxy_refactor_tasks<real_work_tasks AND unknown_tasks=0.',
            'caveat' => 'A true claim means only "this campaign slice produced real work by task '
                .'classification". It does NOT prove 24h unattended autonomy, merge quality, or that the '
                .'Atlas became measurably more capable.',
        ];
    }

    /**
     * @param  array<string,int>  $counts
     * @param  array<string,mixed>  $claimPolicy
     */
    private function verdict(array $counts, array $claimPolicy): string
    {
        if ($counts['tasks_total'] === 0) {
            return 'no tasks observed — nothing to classify (claim refused).';
        }

        $mix = sprintf(
            '%d real (%d bug_fix / %d feature / %d verification), %d proxy, %d cosmetic, %d unknown of %d tasks',
            $counts['real_work_tasks'],
            $counts['bug_fix_tasks'],
            $counts['feature_tasks'],
            $counts['verification_tasks'],
            $counts['proxy_refactor_tasks'],
            $counts['cosmetic_tasks'],
            $counts['unknown_tasks'],
            $counts['tasks_total'],
        );

        if ($claimPolicy['loop_real_work_claim_allowed'] === true) {
            return 'real-work claim ALLOWED for this slice: '.$mix.'.';
        }

        return 'real-work claim REFUSED ('.implode(', ', $claimPolicy['blockers']).'): '.$mix.'.';
    }

    /**
     * @return array<int,object>
     */
    private function loadTasks(?string $campaignId): array
    {
        $q = DB::table(self::TASKS_TABLE)->select('id', 'campaign_id', 'objective', 'payload');
        if ($campaignId !== null) {
            $q->where('campaign_id', $campaignId);
        }

        return $q->get()->all();
    }

    /**
     * @return array<string,mixed>
     */
    private function decodePayload(mixed $payload): array
    {
        if (is_array($payload)) {
            return $payload;
        }
        if (is_string($payload) && trim($payload) !== '') {
            $decoded = json_decode($payload, true);

            return is_array($decoded) ? $decoded : [];
        }

        return [];
    }

    /**
     * @param  array<string,mixed>  $payload
     */
    private function objectiveKind(array $payload): string
    {
        $v = data_get($payload, 'objective_kind', '');

        return is_scalar($v) ? strtolower(trim((string) $v)) : '';
    }

    /**
     * @param  array<string,mixed>  $payload
     */
    private function patternMode(array $payload): string
    {
        $v = data_get($payload, 'pattern.mode', '');

        return is_scalar($v) ? strtolower(trim((string) $v)) : '';
    }

    private function scalarString(mixed $v): string
    {
        return is_scalar($v) ? (string) $v : '';
    }

    /**
     * @param  array<string,mixed>  $payload
     */
    private function patternSelected(array $payload): ?string
    {
        $selected = data_get($payload, 'pattern.selected');
        if (is_string($selected) && trim($selected) !== '') {
            return trim($selected);
        }

        return null;
    }

    private function realKindCounter(?string $realKind): string
    {
        return match ($realKind) {
            self::REAL_KIND_FEATURE => 'feature_tasks',
            self::REAL_KIND_VERIFICATION => 'verification_tasks',
            default => 'bug_fix_tasks',
        };
    }

    /**
     * @param  list<string>  $reasons
     * @return array{bucket:string, real_kind:?string, reasons:list<string>}
     */
    private function bucket(string $bucket, ?string $realKind, array $reasons): array
    {
        return ['bucket' => $bucket, 'real_kind' => $realKind, 'reasons' => $reasons];
    }

    /**
     * @return array<string,int>
     */
    private function emptyCounts(): array
    {
        return [
            'tasks_total' => 0,
            'real_work_tasks' => 0,
            'bug_fix_tasks' => 0,
            'feature_tasks' => 0,
            'verification_tasks' => 0,
            'proxy_refactor_tasks' => 0,
            'cosmetic_tasks' => 0,
            'unknown_tasks' => 0,
        ];
    }

    private function funnelSection(?string $campaignId): ?array
    {
        try {
            return ($this->funnel ?? new AtlasLoopFunnelService)->snapshot($campaignId);
        } catch (Throwable) {
            return null;
        }
    }

    private function observabilitySection(?string $campaignId): ?array
    {
        if ($campaignId === null) {
            return null;
        }
        try {
            return ($this->observability ?? new AtlasLoopObservabilityDigest)->section($campaignId);
        } catch (Throwable) {
            return null;
        }
    }

    /**
     * Well-formed scorecard for the case where tasks cannot be read at all — claim is structurally refused.
     *
     * @return array<string,mixed>
     */
    private function unavailable(?string $campaignId, string $reason): array
    {
        $counts = $this->emptyCounts();

        return [
            'schema_version' => self::SCHEMA_VERSION,
            'status' => 'unavailable',
            'reason' => $reason,
            'campaign_id' => $campaignId,
            'generated_at' => now()->toIso8601String(),
            'tasks_total' => 0,
            'real_work_tasks' => 0,
            'bug_fix_tasks' => 0,
            'feature_tasks' => 0,
            'verification_tasks' => 0,
            'proxy_refactor_tasks' => 0,
            'cosmetic_tasks' => 0,
            'unknown_tasks' => 0,
            'real_work_ratio' => 0.0,
            'proxy_ratio' => 0.0,
            'cosmetic_ratio' => 0.0,
            'pattern_signals' => [
                'pattern_driver_tasks' => 0,
                'pattern_advisory_tasks' => 0,
                'pattern_missing_tasks' => 0,
                'patterns_selected' => [],
            ],
            'claim_policy' => [
                'loop_real_work_claim_allowed' => false,
                'blockers' => ['scorecard_unavailable'],
                'rule' => 'Allowed only when: tasks_total>0 AND real_work_tasks>=1 AND cosmetic_tasks=0 AND '
                    .'proxy_refactor_tasks<real_work_tasks AND unknown_tasks=0.',
                'caveat' => 'A true claim means only "this campaign slice produced real work by task '
                    .'classification". It does NOT prove 24h unattended autonomy, merge quality, or that the '
                    .'Atlas became measurably more capable.',
            ],
            'breakdown' => [],
            'breakdown_capped' => false,
            'funnel' => null,
            'observability' => null,
            'verdict' => 'scorecard unavailable ('.$reason.') — claim refused.',
        ];
    }

    private function present(mixed $v): bool
    {
        if ($v === null) {
            return false;
        }
        if (is_string($v)) {
            return trim($v) !== '';
        }
        if (is_array($v)) {
            return $v !== [];
        }

        return (bool) $v;
    }

    private function isTrue(mixed $v): bool
    {
        if (is_bool($v)) {
            return $v;
        }
        if (is_int($v)) {
            return $v === 1;
        }
        if (is_string($v)) {
            return in_array(strtolower(trim($v)), ['1', 'true', 'yes'], true);
        }

        return false;
    }

    private function ratio(int $num, int $den): float
    {
        if ($den <= 0) {
            return 0.0;
        }

        return round($num / $den, 4);
    }
}
