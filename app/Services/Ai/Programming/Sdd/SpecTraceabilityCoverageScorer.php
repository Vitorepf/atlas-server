<?php

declare(strict_types=1);

namespace App\Services\Ai\Programming\Sdd;


/**
 * @unwired-until 2026-08-05 (Obra #7 W2: capability testada aguardando consumidor; ver triagem 2026-07-06)
 */
final class SpecTraceabilityCoverageScorer
{
    private const SCHEMA_VERSION = 'atlas.sdd.spec_traceability_coverage.v1';

    private const LINK_REQUIREMENT_WITHOUT_ACCEPTANCE = 'requirement_without_acceptance';

    private const LINK_ACCEPTANCE_WITHOUT_TEST = 'acceptance_without_test';

    private const LINK_TEST_WITHOUT_EVIDENCE = 'test_without_evidence';

    /**
     * @param  list<array{requirement_id?: string, critical?: bool, has_acceptance?: bool, has_task?: bool, has_test?: bool, has_evidence?: bool}>  $requirements
     * @return array{
     *     schema_version: string,
     *     total: int,
     *     fully_covered: int,
     *     coverage_ratio: float,
     *     critical_total: int,
     *     critical_with_evidence: int,
     *     per_requirement: list<array{requirement_id: string, critical: bool, chain_depth: int, fully_covered: bool, broken_links: list<string>}>,
     *     broken_links_summary: array<string, int>,
     *     required_questions: array{requirement_has_proving_test: bool, acceptance_has_implementing_file_or_task: bool, evidence_proves_gate: bool, code_changed_without_spec: bool, spec_changed_without_test: bool, spec_changed_without_evidence: bool},
     *     enterprise_complete: bool
     * }
     */
    public function score(array $requirements): array
    {
        $total = count($requirements);
        $fullyCovered = 0;
        $criticalTotal = 0;
        $criticalWithEvidence = 0;
        $perRequirement = [];
        $brokenLinksSummary = [
            self::LINK_REQUIREMENT_WITHOUT_ACCEPTANCE => 0,
            self::LINK_ACCEPTANCE_WITHOUT_TEST => 0,
            self::LINK_TEST_WITHOUT_EVIDENCE => 0,
        ];

        foreach ($requirements as $row) {
            $isCritical = $this->flag($row, 'critical');
            $chainDepth = $this->chainDepth($row);
            $isFullyCovered = $chainDepth === 4;
            $brokenLinks = $this->brokenLinksFor($row);

            if ($isFullyCovered) {
                $fullyCovered++;
            }

            if ($isCritical) {
                $criticalTotal++;

                if ($this->flag($row, 'has_evidence')) {
                    $criticalWithEvidence++;
                }
            }

            foreach ($brokenLinks as $link) {
                $brokenLinksSummary[$link]++;
            }

            $perRequirement[] = [
                'requirement_id' => $this->requirementId($row),
                'critical' => $isCritical,
                'chain_depth' => $chainDepth,
                'fully_covered' => $isFullyCovered,
                'broken_links' => $brokenLinks,
            ];
        }

        $coverageRatio = $total === 0
            ? 0.0
            : round($fullyCovered / $total, 4);

        $enterpriseComplete = $criticalTotal > 0
            && $criticalWithEvidence === $criticalTotal;

        return [
            'schema_version' => self::SCHEMA_VERSION,
            'total' => $total,
            'fully_covered' => $fullyCovered,
            'coverage_ratio' => $coverageRatio,
            'critical_total' => $criticalTotal,
            'critical_with_evidence' => $criticalWithEvidence,
            'per_requirement' => $perRequirement,
            'broken_links_summary' => $brokenLinksSummary,
            'required_questions' => $this->answersToRequiredQuestions($requirements),
            'enterprise_complete' => $enterpriseComplete,
        ];
    }

    /**
     * Walk the requirement -> acceptance -> task -> test -> evidence chain in
     * order, counting present links and stopping at the first gap.
     *
     * @param  array{has_acceptance?: bool, has_task?: bool, has_test?: bool, has_evidence?: bool}  $row
     */
    public function chainDepth(array $row): int
    {
        $depth = 0;

        foreach (['has_acceptance', 'has_task', 'has_test', 'has_evidence'] as $link) {
            if (! $this->flag($row, $link)) {
                break;
            }

            $depth++;
        }

        return $depth;
    }

    /**
     * @param  array{has_acceptance?: bool, has_task?: bool, has_test?: bool, has_evidence?: bool}  $row
     * @return list<string>
     */
    public function brokenLinksFor(array $row): array
    {
        $hasAcceptance = $this->flag($row, 'has_acceptance');
        $hasTest = $this->flag($row, 'has_test');
        $hasEvidence = $this->flag($row, 'has_evidence');

        $broken = [];

        if (! $hasAcceptance) {
            $broken[] = self::LINK_REQUIREMENT_WITHOUT_ACCEPTANCE;
        }

        if ($hasAcceptance && ! $hasTest) {
            $broken[] = self::LINK_ACCEPTANCE_WITHOUT_TEST;
        }

        if ($hasTest && ! $hasEvidence) {
            $broken[] = self::LINK_TEST_WITHOUT_EVIDENCE;
        }

        return $broken;
    }

    /**
     * @param  list<array{has_acceptance?: bool, has_task?: bool, has_test?: bool, has_evidence?: bool}>  $rows
     * @return array{requirement_has_proving_test: bool, acceptance_has_implementing_file_or_task: bool, evidence_proves_gate: bool, code_changed_without_spec: bool, spec_changed_without_test: bool, spec_changed_without_evidence: bool}
     */
    public function answersToRequiredQuestions(array $rows): array
    {
        $requirementHasProvingTest = true;
        $acceptanceHasImplementingFileOrTask = true;
        $evidenceProvesGate = true;
        $codeChangedWithoutSpec = false;
        $specChangedWithoutTest = false;
        $specChangedWithoutEvidence = false;

        foreach ($rows as $row) {
            $hasAcceptance = $this->flag($row, 'has_acceptance');
            $hasTask = $this->flag($row, 'has_task');
            $hasTest = $this->flag($row, 'has_test');
            $hasEvidence = $this->flag($row, 'has_evidence');

            if (! $hasTest) {
                $requirementHasProvingTest = false;
            }

            if ($hasAcceptance && ! $hasTask) {
                $acceptanceHasImplementingFileOrTask = false;
            }

            if ($hasTest && ! $hasEvidence) {
                $evidenceProvesGate = false;
            }

            if ($hasTask && ! $hasAcceptance) {
                $codeChangedWithoutSpec = true;
            }

            if ($hasAcceptance && ! $hasTest) {
                $specChangedWithoutTest = true;
            }

            if ($hasAcceptance && ! $hasEvidence) {
                $specChangedWithoutEvidence = true;
            }
        }

        return [
            'requirement_has_proving_test' => $requirementHasProvingTest,
            'acceptance_has_implementing_file_or_task' => $acceptanceHasImplementingFileOrTask,
            'evidence_proves_gate' => $evidenceProvesGate,
            'code_changed_without_spec' => $codeChangedWithoutSpec,
            'spec_changed_without_test' => $specChangedWithoutTest,
            'spec_changed_without_evidence' => $specChangedWithoutEvidence,
        ];
    }

    /**
     * @param  array<string, mixed>  $row
     */
    private function flag(array $row, string $key): bool
    {
        return ($row[$key] ?? false) === true;
    }

    /**
     * @param  array<string, mixed>  $row
     */
    private function requirementId(array $row): string
    {
        $value = $row['requirement_id'] ?? '';

        return is_string($value) ? $value : (string) $value;
    }
}
