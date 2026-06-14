<?php

declare(strict_types=1);

namespace App\Services\Ai\AutonomousEvolution\Discovery;

/**
 * PLAN-HARDENING REVIEW — the agentic half of "plan impeccably before you spend": a panel of reviewers
 * ATTACKS the plan looking for why it would FAIL, BEFORE a single implementation token is spent. This
 * is the cheap step that most lowers P(failure) — finding a fatal decomposition flaw in review costs a
 * few tokens; finding it after a multi-node implementation wastes the whole run.
 *
 * Each reviewer carries a distinct LENS (the correlated-blind-spot defense, same as the value panel):
 *   - DECOMPOSITION    is the split into nodes correct + complete, or does a step assume work no node does?
 *   - VERIFIABILITY    can each node's acceptance ACTUALLY be checked, or is a "test" unrunnable/circular?
 *   - INTEGRATION      will the assembled nodes integrate, or does step 3 break step 1 (whole > parts)?
 *   - HIDDEN-COUPLING  does a node silently depend on a file outside its scope (an undeclared edge)?
 *   - SCOPE-CREEP      is the plan trying to do MORE than the objective (risking a sprawling, fragile change)?
 *
 * THE DECISION (deterministic, pure — the panel never free-forms it):
 *   - any CRITICAL would-block finding  => REPLAN   (a fatal flaw — fix the plan, do not spend on it);
 *   - a MAJORITY of reviewers flag high/critical risk => HARDEN  (refine the plan with the findings, re-review);
 *   - otherwise => IMPLEMENT (the plan survived the attack — now spend the expensive step on it).
 *
 * GROUNDED: a finding is only counted if it is SPECIFIC (a non-empty summary; if it names a node, that
 * node must exist) — a vague "this might be bad" cannot block or harden. The reviewers find WHAT could
 * fail; this aggregator decides; the frozen out-of-process stack still certifies the RESULT after
 * implementation. Pure: no provider, no DB, no mutation (the live reviewers run behind a seam).
 */
final class AtlasLoopPlanHardeningReview
{
    public const IMPLEMENT = 'implement';

    public const HARDEN = 'harden';

    public const REPLAN = 'replan';

    /**
     * @param  array<string,mixed>  $plan
     * @param  list<array<string,mixed>>  $findings  each: {lens, node_id?, severity (critical|high|medium|low),
     *     would_block:bool, summary}
     * @param  int  $reviewerCount  how many reviewers were convened (for the majority threshold)
     * @return array{decision:string, blocking:list<array<string,mixed>>, high_or_critical:int,
     *               considered:int, dropped:int}
     */
    public function assess(array $plan, array $findings, int $reviewerCount): array
    {
        $declaredNodes = [];
        foreach (array_values((array) ($plan['nodes'] ?? [])) as $node) {
            if (is_array($node)) {
                $id = trim((string) ($node['id'] ?? ''));
                if ($id !== '') {
                    $declaredNodes[$id] = true;
                }
            }
        }

        $considered = [];
        $dropped = 0;
        foreach ($findings as $f) {
            if (! is_array($f)) {
                $dropped++;

                continue;
            }
            $summary = trim((string) ($f['summary'] ?? ''));
            $nodeId = trim((string) ($f['node_id'] ?? ''));
            // GROUNDING: a finding must be specific. Vague (no summary) or one that points at a node
            // that does not exist cannot block/harden the plan — only real findings count.
            if ($summary === '' || ($nodeId !== '' && ! isset($declaredNodes[$nodeId]))) {
                $dropped++;

                continue;
            }
            $considered[] = [
                'lens' => (string) ($f['lens'] ?? 'unknown'),
                'node_id' => $nodeId !== '' ? $nodeId : null,
                'severity' => $this->normSeverity((string) ($f['severity'] ?? 'low')),
                'would_block' => (bool) ($f['would_block'] ?? false),
                'summary' => mb_substr($summary, 0, 200),
            ];
        }

        $blocking = array_values(array_filter(
            $considered,
            static fn (array $f): bool => $f['would_block'] && $f['severity'] === 'critical',
        ));
        $highOrCritical = count(array_filter(
            $considered,
            static fn (array $f): bool => in_array($f['severity'], ['critical', 'high'], true),
        ));

        $majority = max(1, (int) ceil(max(1, $reviewerCount) / 2));
        $decision = match (true) {
            $blocking !== [] => self::REPLAN,           // a fatal flaw — never spend on a doomed plan
            $highOrCritical >= $majority => self::HARDEN, // most reviewers see real risk — refine + re-review
            default => self::IMPLEMENT,                   // survived the attack — spend the expensive step
        };

        return [
            'decision' => $decision,
            'blocking' => $blocking,
            'high_or_critical' => $highOrCritical,
            'considered' => count($considered),
            'dropped' => $dropped,
        ];
    }

    private function normSeverity(string $s): string
    {
        $s = mb_strtolower(trim($s));

        return in_array($s, ['critical', 'high', 'medium', 'low'], true) ? $s : 'low';
    }
}
