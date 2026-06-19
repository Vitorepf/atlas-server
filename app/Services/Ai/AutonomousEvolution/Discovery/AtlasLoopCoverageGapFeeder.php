<?php

declare(strict_types=1);

namespace App\Services\Ai\AutonomousEvolution\Discovery;

use App\Services\Ai\AutonomousEvolution\Persistence\AtlasLoopStore;
use Illuminate\Support\Facades\DB;

/**
 * The input side of the auto-characterization-test lane: turns a coverage gap (a target file +
 * sibling test + the surviving DECISION operator the refactor's mutation gate flagged) into a
 * grindable `characterization_test` task.
 *
 * The task INVERTS a refactor's allow/frozen contract: the provider may edit ONLY the sibling TEST
 * (allowed_globs), while the production TARGET is FROZEN — the lane strengthens coverage, it never
 * touches production code. The grinder routes this objective_kind to the mutant-killed verifier
 * (gateCharacterizationTestProposals), so a kept proposal is one whose new test PASSES on correct
 * code AND FAILS once the SAME operator's mutant is applied. Conversion rises by closing the gap,
 * never by lowering the mutation bar.
 */
final class AtlasLoopCoverageGapFeeder
{
    public function __construct(private readonly AtlasLoopStore $store) {}

    /**
     * Enqueue ONE characterization-test task for a coverage gap to the given (live) campaign.
     *
     * @param  array{target_file?:string, decision_operator?:string, mutation_id?:string, sibling_test?:?string}  $gap
     * @return string|null  the task id, or null when the gap is unactionable / the store loses a dedup race
     */
    public function feedGap(string $campaignId, array $gap, ?string $provider = null, bool $allowNewSibling = false): ?string
    {
        $target = trim((string) ($gap['target_file'] ?? ''));
        $sibling = trim((string) ($gap['sibling_test'] ?? ''));
        $operator = trim((string) ($gap['decision_operator'] ?? ''));
        $newSibling = false;
        if ($sibling === '' && $allowNewSibling && $target !== '') {
            $sibling = $this->suggestSiblingTestPath($target);
            $newSibling = $sibling !== '';
        }
        // A characterization task with no sibling test to strengthen — or no target/operator to pin —
        // is unactionable; never enqueue a task the verifier can only reject.
        if ($target === '' || $sibling === '' || $operator === '') {
            return null;
        }

        $command = './vendor/bin/phpunit '.escapeshellarg($sibling);
        $timeout = max(60, (int) config('atlas.loop.characterization_timeout_seconds', 180));
        $acceptance = [
            'commands' => [$command],
            // Provider edits the TEST; production target + config are FROZEN (coverage-only change).
            'allowed_globs' => [$sibling],
            'frozen_globs' => [$target, 'phpunit.xml', 'phpunit.xml.dist', 'composer.json'],
            // Additive coverage is behavior-preserving: the sibling test is green before AND after the
            // new assertions, so diff-earned (revert -> RED) can never apply. The real anti-fake proof
            // is the downstream mutant-kill verifier. (The framework materializer exempts the
            // characterization_test objective_kind from forcing revert_recheck on; these explicit keys
            // document the intent — they do not, alone, override the materializer.)
            'metric_kind' => 'gate',
            'revert_recheck' => false,
            'timeout_seconds' => $timeout,
        ];

        $payload = [
            'materializer' => 'framework',
            'objective_kind' => 'characterization_test',
            'characterization_target' => $target,
            'characterization_sibling_test' => $sibling,
            'characterization_operator' => $operator,
            'characterization_mutation_id' => (string) ($gap['mutation_id'] ?? ''),
            'characterization_mode' => $newSibling ? 'create_new_sibling' : 'strengthen_existing_sibling',
            'characterization_timeout_seconds' => $timeout,
            'target_relative_path' => $target,
            'target_repo_path' => $target,
            'acceptance' => $acceptance,
            'allowed_files' => [$sibling],
            'validation_commands' => [$command],
        ];
        if ($provider !== null && $provider !== '') {
            $payload['provider'] = $provider;
        }

        // BOUNDED RETRY: the provider's characterization output is variable (the SAME gap certified in
        // isolation but its sibling test survived the mutant in a soak grind). A re-detected gap means
        // the prior attempt(s) did NOT close it, so give the provider another shot — up to a cap — by
        // SALTING the dedupe with the attempt count (a fresh task, not a no-op). Past the cap, stop
        // hammering the same gap (it is provider-hard; a human or Forge should take it).
        $attempt = $this->priorAttempts($campaignId, $target, $operator);
        $maxAttempts = max(1, (int) config('atlas.loop.characterization_max_attempts_per_gap', 3));
        if ($attempt >= $maxAttempts) {
            return null; // exhausted: do not re-enqueue a gap the provider keeps failing
        }
        $payload['characterization_attempt'] = $attempt;

        $acceptanceHash = hash('sha256', json_encode([
            'objective_kind' => 'characterization_test',
            'target' => $target,
            'sibling' => $sibling,
            'operator' => $operator,
            'mutation_id' => (string) ($gap['mutation_id'] ?? ''),
            'attempt' => $attempt, // salt so each retry is a distinct dedupe key, not a no-op
        ], JSON_THROW_ON_ERROR));

        $task = $this->store->enqueueTask(
            $campaignId,
            $this->objectiveText($target, $sibling, $operator, $newSibling),
            $payload,
            'coverage_gap_characterization',
            $target,
            8, // high-ish priority: each closes a refactor that is otherwise stuck
            false,
            $acceptanceHash,
        );

        return $task?->getKey() !== null ? (string) $task->getKey() : null;
    }

    /**
     * @param  list<array<string,mixed>>  $gaps
     * @return list<string>  enqueued task ids
     */
    public function feed(string $campaignId, array $gaps, ?string $provider = null): array
    {
        $ids = [];
        foreach ($gaps as $gap) {
            $id = $this->feedGap($campaignId, is_array($gap) ? $gap : [], $provider);
            if ($id !== null) {
                $ids[] = $id;
            }
        }

        return $ids;
    }

    /**
     * Completed (done/failed) characterization attempts already made for this exact gap
     * (campaign + target + operator). A re-detected gap with prior completed attempts means none
     * closed it — the count drives bounded retry. An in-flight (pending/claimed/running) attempt is
     * NOT counted: with attempt unchanged the dedupe key is identical, so enqueueTask returns the
     * existing task (never a parallel double-enqueue). Fail-open: a count error never blocks feeding.
     */
    private function priorAttempts(string $campaignId, string $target, string $operator): int
    {
        try {
            return (int) DB::table('atlas_loop_tasks')
                ->where('campaign_id', $campaignId)
                ->where('source', 'coverage_gap_characterization')
                ->where('target_path', $target)
                ->whereIn('status', ['done', 'failed'])
                ->where('payload', 'like', '%"characterization_operator":"'.$operator.'"%')
                ->count();
        } catch (\Throwable) {
            return 0;
        }
    }

    private function objectiveText(string $target, string $sibling, string $operator, bool $newSibling): string
    {
        if ($newSibling) {
            return "Create a CHARACTERIZATION TEST at {$sibling} that pins the existing behaviour of {$target}. "
                ."The target has no convention sibling test and carries an uncovered `{$operator}` decision mutant. "
                ."Add focused assertions that exercise BOTH sides of that decision so flipping it makes the suite red. "
                ."Edit ONLY {$sibling}; do NOT modify {$target} or any other production file — this is a "
                ."coverage-only change. Your change is correct only when {$sibling} passes on the unchanged code.";
        }

        return "Add a CHARACTERIZATION TEST to {$sibling} that pins the existing behaviour of {$target}. "
            ."The test must FAIL if the `{$operator}` decision in {$target} is flipped (the mutation-adequacy "
            ."gate found a `{$operator}` mutant SURVIVING — the current tests do not kill it). Add focused "
            ."assertions that exercise BOTH sides of that decision so flipping it makes the suite red. "
            ."Edit ONLY {$sibling}; do NOT modify {$target} or any other production file — this is a "
            ."coverage-only change. Preserve all existing tests. Your change is correct only when "
            ."{$sibling} still passes on the unchanged code.";
    }

    private function suggestSiblingTestPath(string $target): string
    {
        $target = ltrim(str_replace('\\', '/', $target), '/');
        if ($target === '' || ! str_ends_with($target, '.php')) {
            return '';
        }

        if (str_starts_with($target, 'app/Services/')) {
            return 'tests/Unit/'.substr($target, strlen('app/Services/'), -4).'Test.php';
        }
        if (str_starts_with($target, 'app/')) {
            return 'tests/Unit/'.substr($target, strlen('app/'), -4).'Test.php';
        }

        return 'tests/Unit/'.substr($target, 0, -4).'Test.php';
    }
}
