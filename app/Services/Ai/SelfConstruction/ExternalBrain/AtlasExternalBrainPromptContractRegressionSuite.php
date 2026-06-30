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
 * Plus one DEFECT check (not a presence requirement):
 *   quota_farm_risk — the prompt rewards raw proposal/task COUNT without also requiring
 *                     value/diversity/evidence — a quota-farming incentive.
 *
 * OUTPUT: { schema, pass:bool, score:float, failed_clauses:list<string>, required_patch_notes:list<string> }
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
    ];

    private const QUOTA_FARM_RAW_COUNT_PATTERN = '/(as many (tasks|proposals) as possible|maximize (the )?(task|proposal) (count|volume)|raw (task )?count|task volume alone)/i';

    private const QUOTA_FARM_VALUE_MITIGATION_PATTERN = '/(value|diversity|evidence)/i';

    private const QUOTA_FARM_PATCH_NOTE = 'Raw-count language rewards quota-farming. Require value, diversity, or evidence alongside any count target.';

    /**
     * @return array{schema:string, pass:bool, score:float, failed_clauses:list<string>, required_patch_notes:list<string>, detected_strengths:list<string>, quota_farm_risk:bool}
     */
    public function validate(string $prompt): array
    {
        $failedClauses = [];
        $patchNotes = [];
        $detectedStrengths = [];
        $totalChecks = count(self::REQUIRED_CLAUSES) + 1; // +1 for the quota-farm defect check
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

        return [
            'schema' => self::SCHEMA,
            'pass' => $failedClauses === [],
            'score' => $totalChecks > 0 ? round($passedChecks / $totalChecks, 4) : 0.0,
            'failed_clauses' => $failedClauses,
            'required_patch_notes' => $patchNotes,
            'detected_strengths' => $detectedStrengths,
            'quota_farm_risk' => $hasQuotaFarmRisk,
        ];
    }
}
