<?php

namespace App\Services\Engineering;

use App\Models\AtlasEngineeringRun;

class EngineeringRunScoringService
{
    /**
     * @param  array<string,mixed>  $contract
     * @param  array<string,mixed>  $blueprint
     * @return array<string,mixed>
     */
    public function score(AtlasEngineeringRun $run, array $contract, array $blueprint): array
    {
        $run->loadMissing(['attempts', 'controlResults', 'testRuns', 'patchArtifacts', 'reviewFindings']);
        $controls = $run->controlResults;
        $tests = $run->testRuns;
        $patch = $run->patchArtifacts->first();
        $latestAttempt = $run->attempts->sortByDesc('attempt_number')->first();
        $latestAttemptFailed = $latestAttempt && in_array((string) $latestAttempt->status, ['failed', 'timed_out', 'cancelled'], true);
        $openReviewFindings = $run->reviewFindings->where('status', 'open')->values();
        $blockingReviewFindings = $openReviewFindings
            ->filter(fn ($finding): bool => in_array((string) $finding->severity, ['p0', 'p1'], true))
            ->values();
        $majorReviewFindings = $openReviewFindings
            ->filter(fn ($finding): bool => (string) $finding->severity === 'p2')
            ->values();
        $requiredControlFailures = $controls
            ->filter(fn ($result): bool => in_array($result->status, ['failed', 'blocked'], true)
                && (bool) data_get($result->metadata, 'required', $this->isRequiredControlSlug((string) $result->control_slug)))
            ->values();
        $failedRequiredTests = $tests
            ->filter(fn ($testRun): bool => $testRun->status === 'failed')
            ->values();
        $requiredSkipped = $controls
            ->filter(fn ($result): bool => $result->status === 'skipped'
                && (bool) data_get($result->metadata, 'required', $this->isRequiredControlSlug((string) $result->control_slug)))
            ->values();
        $riskFlags = array_values((array) ($patch?->risk_flags_json ?? []));
        $hasChangedFiles = $patch && count((array) $patch->changed_files_json) > 0;

        $acceptanceScore = $this->acceptanceScore($blueprint, $controls);
        $testScore = $tests->isEmpty()
            ? 10
            : ($failedRequiredTests->isEmpty() ? 30 : 0);
        $reviewScore = match (true) {
            $blockingReviewFindings->isNotEmpty() || $requiredControlFailures->isNotEmpty() => 0,
            $majorReviewFindings->isNotEmpty() => 10,
            default => 20,
        };
        $controlScore = $requiredControlFailures->isEmpty() && $requiredSkipped->isEmpty() ? 10 : 0;
        $scopeScore = $this->scopeScore($patch);
        $evidenceScore = $tests->isNotEmpty() || $controls->isNotEmpty() ? 5 : 0;
        $score = min(100, max(0, $acceptanceScore + $testScore + $reviewScore + $controlScore + $scopeScore + $evidenceScore));

        $decision = match (true) {
            in_array('possible_secret_in_diff', $riskFlags, true) => 'unsafe',
            $blockingReviewFindings->contains(fn ($finding): bool => (string) $finding->severity === 'p0') => 'blocked',
            $requiredControlFailures->contains(fn ($result): bool => $result->status === 'blocked') => 'blocked',
            $blockingReviewFindings->isNotEmpty() => 'unresolved',
            $latestAttemptFailed => 'unresolved',
            $failedRequiredTests->isNotEmpty() || $requiredControlFailures->isNotEmpty() => 'unresolved',
            $requiredSkipped->isNotEmpty() || $majorReviewFindings->isNotEmpty() => 'partial',
            ! $hasChangedFiles => 'partial',
            $score >= 85 => 'resolved',
            $score >= 60 => 'partial',
            default => 'unresolved',
        };

        return [
            'score' => $score,
            'decision' => $decision,
            'components' => [
                'acceptance_criteria' => $acceptanceScore,
                'test_matrix' => $testScore,
                'review' => $reviewScore,
                'required_controls' => $controlScore,
                'scope' => $scopeScore,
                'evidence' => $evidenceScore,
            ],
            'blocking_reasons' => $this->blockingReasons($requiredControlFailures, $failedRequiredTests, $requiredSkipped, $riskFlags, $openReviewFindings, $latestAttempt),
            'generated_at' => now()->toJSON(),
        ];
    }

    private function acceptanceScore(array $blueprint, mixed $controls): int
    {
        $criteria = (array) ($blueprint['acceptance_matrix'] ?? []);
        if ($criteria === []) {
            return 30;
        }

        $hasAcceptanceControl = $controls->contains(fn ($result): bool => in_array((string) $result->control_slug, ['engineering_task_contract', 'engineering_blueprint_snapshot'], true)
            && $result->status === 'passed');

        return $hasAcceptanceControl ? 30 : 15;
    }

    private function scopeScore(mixed $patch): int
    {
        if (! $patch) {
            return 0;
        }

        $changed = (array) $patch->changed_files_json;
        $risks = (array) $patch->risk_flags_json;

        if ($changed === []) {
            return 0;
        }

        if (in_array('large_diff_surface', $risks, true)) {
            return 2;
        }

        return count($changed) <= 20 ? 5 : 3;
    }

    private function isRequiredControlSlug(string $slug): bool
    {
        return in_array($slug, [
            'engineering_task_contract',
            'engineering_blueprint_snapshot',
            'git_status_snapshot',
            'patch_artifact',
            'primary_test_command',
        ], true);
    }

    /**
     * @return array<int,string>
     */
    private function blockingReasons(mixed $controlFailures, mixed $testFailures, mixed $skipped, array $riskFlags, mixed $reviewFindings, mixed $latestAttempt): array
    {
        $reasons = [];

        foreach ($riskFlags as $flag) {
            if ($flag === 'possible_secret_in_diff') {
                $reasons[] = 'Diff pode conter segredo.';
            }
        }

        foreach ($controlFailures as $failure) {
            $reasons[] = 'Controle falhou: '.$failure->control_slug;
        }

        foreach ($testFailures as $failure) {
            $reasons[] = 'Teste falhou: '.$failure->command;
        }

        foreach ($skipped as $skip) {
            $reasons[] = 'Controle requerido nao executado: '.$skip->control_slug;
        }

        foreach ($reviewFindings as $finding) {
            $severity = strtoupper((string) $finding->severity);
            $title = trim((string) $finding->title);
            $reasons[] = "Review finding aberto {$severity}: {$title}";
        }

        if ($latestAttempt && in_array((string) $latestAttempt->status, ['failed', 'timed_out', 'cancelled'], true)) {
            $reasons[] = 'Attempt final nao completou: #'.$latestAttempt->attempt_number.' status '.$latestAttempt->status;
        }

        return array_values(array_unique($reasons));
    }
}
