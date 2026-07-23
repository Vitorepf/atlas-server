<?php

declare(strict_types=1);

namespace App\Services\Ai\SelfConstruction\ExternalBrain;

/**
 * Pure prompt-contract regression suite — validates a candidate external-brain prompt TEXT against the
 * core contract clauses BEFORE it is shipped, so a prompt regression is caught by a test instead of by
 * operator suspicion weeks later.
 *
 * Checked clauses (regex presence on the prompt text, case-insensitive):
 *   persistence_contract          — keeps running to the target/quota, does not stop early
 *   no_premature_stop             — does not stop on the first no_proposal
 *   macro_task_creation           — creates macro/high-value tasks, not just micro-edits
 *   implementability_verification — verifies a candidate is actually implementable before proposing it
 *   anti_provider_human_dependency — never depends on a human/operator/external provider as runtime owner
 *   dedup_anti_template           — dedupes against prior proposals / avoids templated repeats
 *
 * Plus DEFECT checks (not presence requirements — a risky phrase fails unless mitigated):
 *   quota_farm_risk           — rewards raw proposal/task COUNT without value/diversity/evidence.
 *   comfortable_queue_stop_risk — allows stopping/pausing on comfortable queue depth without also
 *                                 requiring verified target exhaustion.
 *   proxy_task_risk           — permits proxy/cosmetic tasks (renaming, formatting churn, no-behavior
 *                                 refactors) as valid progress without requiring genuine/substantive impact.
 *   vague_evidence_risk       — permits vague, speculative, or low-evidence origination without
 *                                 requiring concrete/verified evidence.
 *
 * OUTPUT: { schema, pass:bool, score:float, failed_clauses:list<string>, required_patch_notes:list<string>,
 *   detected_strengths:list<string>, quota_farm_risk:bool,
 *   defect_risk_summary:{quota_farm:bool, stop_condition:bool, proxy_task:bool, vague_evidence:bool} }
 *
 * Pure: string-matching only — no provider calls, no I/O.
 */
final class AtlasExternalBrainPromptContractRegressionSuite
{
    public const SCHEMA = 'atlas.self_construction.external_brain.prompt_contract_regression_suite.v1';

    /** clause_key => [pattern, patch_note] */
    private const REQUIRED_CLAUSES = [
        'persistence_contract' => [
            '/(keep running|run until|until (the )?target|do not stop (too )?early|continue until (the )?(target|quota))/i',
            'Add explicit persistence language: keep running until the target/quota is reached, do not stop early.',
        ],
        'no_premature_stop' => [
            '/no_proposal.{0,80}(do not stop|never stop|keep (trying|going|persisting))|(do not stop|never stop|keep (trying|going|persisting)).{0,80}no_proposal/is',
            'State explicitly that a single no_proposal cycle must not end the run — keep trying.',
        ],
        'macro_task_creation' => [
            '/(macro[- ]task|high-value task|big[- ]impact task)/i',
            'Add a clause directing the brain to create macro/high-value tasks, not only micro-edits.',
        ],
        'implementability_verification' => [
            '/(verify implementability|concrete file|implementable|existing file delta)/i',
            'Add a clause requiring the brain to verify each candidate is concretely implementable before proposing it.',
        ],
        'anti_provider_human_dependency' => [
            '/(no human|no operator|without human|atlas-native|never (require|depend on) (a )?human)/i',
            'Add an explicit anti-dependency clause: never require human/operator/external-provider as the runtime owner.',
        ],
        'dedup_anti_template' => [
            '/(dedup|duplicate|anti-template|not a template|avoid templated)/i',
            'Add a dedup/anti-template clause so repeated or templated proposals are rejected.',
        ],
        'escalation_beyond_local_candidates' => [
            '/(escalat\w*|expand\w*).{0,80}(research|second pass|architecture|simplif\w*)|(research|second pass|architecture|simplif\w*).{0,80}(escalat\w*|expand\w*)/is',
            'Add an explicit escalation clause: when local candidates dry up, escalate to research, a second pass, architecture work, or simplification instead of stopping.',
        ],
    ];

    private const QUOTA_FARM_RAW_COUNT_PATTERN = '/(as many (tasks|proposals) as possible|maximize (the )?(task|proposal) (count|volume)|raw (task )?count|task volume alone)/i';

    private const QUOTA_FARM_VALUE_MITIGATION_PATTERN = '/(value|diversity|evidence)/i';

    private const QUOTA_FARM_PATCH_NOTE = 'Raw-count language rewards quota-farming. Require value, diversity, or evidence alongside any count target.';

    private const COMFORTABLE_QUEUE_STOP_PATTERN = '/(stop|pause|halt|end the run).{0,60}(queue (is |depth (is )?)?(sufficient|comfortable|full|healthy|enough)|enough (tasks|work) (queued|in the queue))|(queue (is |depth (is )?)?(sufficient|comfortable|full|healthy|enough)|enough (tasks|work) (queued|in the queue)).{0,60}(stop|pause|halt|end the run)/is';

    private const COMFORTABLE_QUEUE_STOP_PATCH_NOTE = 'Prompt tells the brain to stop/pause when queue depth looks comfortable. A comfortable queue is never a stop condition — require explicit verified target exhaustion instead.';

    private const TARGET_EXHAUSTION_MITIGATION_PATTERN = '/(verified? target exhaustion|confirm\w* (the )?target (is |has been )?exhausted|exhaustively verified|target (is |has been )?(fully |)exhausted (and |,)?verified|verified (that )?(the )?target (is |has been )?exhausted)/i';

    private const PROXY_TASK_RISK_PATTERN = '/(proxy (task|metric)s?|cosmetic (change|edit|refactor)s?|renaming[- ]only|formatting[- ]only churn|refactor\w* with no behavior change|micro[- ]edit(s)? (counts?|count) as (progress|success|value))/i';

    private const PROXY_TASK_MITIGATION_PATTERN = '/(genuine value|real (impact|value)|substantive|behavior[- ]changing|non[- ]cosmetic|exponential)/i';

    private const PROXY_TASK_PATCH_NOTE = 'Prompt permits proxy/cosmetic tasks (renaming, formatting churn, no-behavior-change refactors) as valid progress. Require genuine, substantive, behavior-changing impact instead of proxy metrics.';

    private const VAGUE_EVIDENCE_RISK_PATTERN = '/(vague (reasoning|evidence)|low[- ]evidence (origination|proposal)|speculat\w* (candidate|proposal)s? (is|are) (fine|acceptable|ok)|no evidence (is |)(required|needed)|guess\w* (is|are) (fine|acceptable))/i';

    private const VAGUE_EVIDENCE_MITIGATION_PATTERN = '/(concrete evidence|verified evidence|require\w* evidence|substantiat\w*|verified? target exhaustion)/i';

    private const VAGUE_EVIDENCE_PATCH_NOTE = 'Prompt permits vague, speculative, or low-evidence origination. Require concrete, verified evidence before any candidate is proposed.';

    /**
     * @return array{schema:string, pass:bool, score:float, failed_clauses:list<string>, required_patch_notes:list<string>, detected_strengths:list<string>, quota_farm_risk:bool}
     */
    public function validate(string $prompt): array
    {
        $failedClauses = [];
        $patchNotes = [];
        $detectedStrengths = [];
        $totalChecks = count(self::REQUIRED_CLAUSES) + 4; // +4: quota-farm, comfortable-queue-stop, proxy-task, vague-evidence
        $passedChecks = 0;

        foreach (self::REQUIRED_CLAUSES as $key => [$pattern, $note]) {
            if (preg_match($pattern, $prompt) === 1) {
                $passedChecks++;
                $detectedStrengths[] = 'has_'.$key;
            } else {
                $failedClauses[] = 'missing_'.$key;
                $patchNotes[] = $note;
            }
        }

        $hasQuotaFarmRisk = preg_match(self::QUOTA_FARM_RAW_COUNT_PATTERN, $prompt) === 1
            && preg_match(self::QUOTA_FARM_VALUE_MITIGATION_PATTERN, $prompt) !== 1;
        if ($hasQuotaFarmRisk) {
            $failedClauses[] = 'quota_farm_risk';
            $patchNotes[] = self::QUOTA_FARM_PATCH_NOTE;
        } else {
            $passedChecks++;
            $detectedStrengths[] = 'no_quota_farm_risk';
        }

        $hasComfortableQueueStopRisk = preg_match(self::COMFORTABLE_QUEUE_STOP_PATTERN, $prompt) === 1
            && preg_match(self::TARGET_EXHAUSTION_MITIGATION_PATTERN, $prompt) !== 1;
        if ($hasComfortableQueueStopRisk) {
            $failedClauses[] = 'comfortable_queue_stop_risk';
            $patchNotes[] = self::COMFORTABLE_QUEUE_STOP_PATCH_NOTE;
        } else {
            $passedChecks++;
            $detectedStrengths[] = 'no_comfortable_queue_stop_risk';
        }

        $hasProxyTaskRisk = preg_match(self::PROXY_TASK_RISK_PATTERN, $prompt) === 1
            && preg_match(self::PROXY_TASK_MITIGATION_PATTERN, $prompt) !== 1;
        if ($hasProxyTaskRisk) {
            $failedClauses[] = 'proxy_task_risk';
            $patchNotes[] = self::PROXY_TASK_PATCH_NOTE;
        } else {
            $passedChecks++;
            $detectedStrengths[] = 'no_proxy_task_risk';
        }

        $hasVagueEvidenceRisk = preg_match(self::VAGUE_EVIDENCE_RISK_PATTERN, $prompt) === 1
            && preg_match(self::VAGUE_EVIDENCE_MITIGATION_PATTERN, $prompt) !== 1;
        if ($hasVagueEvidenceRisk) {
            $failedClauses[] = 'vague_evidence_risk';
            $patchNotes[] = self::VAGUE_EVIDENCE_PATCH_NOTE;
        } else {
            $passedChecks++;
            $detectedStrengths[] = 'no_vague_evidence_risk';
        }

        return [
            'schema' => self::SCHEMA,
            'pass' => $failedClauses === [],
            'score' => $totalChecks > 0 ? round($passedChecks / $totalChecks, 4) : 0.0,
            'failed_clauses' => $failedClauses,
            'required_patch_notes' => $patchNotes,
            'detected_strengths' => $detectedStrengths,
            'quota_farm_risk' => $hasQuotaFarmRisk,
            'defect_risk_summary' => [
                'quota_farm' => $hasQuotaFarmRisk,
                'stop_condition' => $hasComfortableQueueStopRisk,
                'proxy_task' => $hasProxyTaskRisk,
                'vague_evidence' => $hasVagueEvidenceRisk,
            ],
        ];
    }
}
