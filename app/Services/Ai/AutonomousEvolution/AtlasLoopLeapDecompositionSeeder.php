<?php

declare(strict_types=1);

namespace App\Services\Ai\AutonomousEvolution;

final class AtlasLoopLeapDecompositionSeeder
{
    public function __construct(
        private readonly ?AtlasLoopDecompositionShapePrior $shapePrior = null,
        private readonly ?AtlasLoopHarnessGuard $guard = null,
    ) {
    }

    /**
     * @param  array<string,mixed>  $ambitionLeap
     * @param  array<string,mixed>  $riskVerdict
     * @return array{task_packets:list<array<string,mixed>>,refuse_reason:?string,shape_prior:array<string,mixed>}
     */
    public function seed(array $ambitionLeap, array $riskVerdict): array
    {
        $emptyPrior = ['verdict' => 'unknown', 'lower_bound' => 0.0, 'rate' => 0.0, 'n' => 0, 'certified' => 0, 'reason' => 'not_assessed'];
        if (($riskVerdict['status'] ?? null) !== 'pass') {
            return $this->refuse('risk_verdict_reject', $emptyPrior);
        }

        $leapId = trim((string) ($ambitionLeap['leap_id'] ?? ''));
        if ($leapId === '') {
            return $this->refuse('missing_leap_id', $emptyPrior);
        }

        $prior = $this->shapePrior($ambitionLeap);
        $targets = $this->candidateTargets($ambitionLeap);
        if ($targets === []) {
            return $this->refuse('missing_loop_target', $prior);
        }

        foreach ($targets as $target) {
            if (! $this->isLoopScope($target) || ($this->guard ?? new AtlasLoopHarnessGuard)->isForbiddenSelfTarget($target)) {
                return $this->refuse('forbidden_or_non_loop_target', $prior);
            }
        }

        $packets = [];
        foreach (array_slice($targets, 0, $this->waveWidth($prior)) as $target) {
            $packets[] = $this->packet($ambitionLeap, $target);
        }

        return [
            'task_packets' => $packets,
            'refuse_reason' => null,
            'shape_prior' => $prior,
        ];
    }

    /**
     * @param  array<string,mixed>  $prior
     * @return array{task_packets:list<never>,refuse_reason:string,shape_prior:array<string,mixed>}
     */
    private function refuse(string $reason, array $prior): array
    {
        return [
            'task_packets' => [],
            'refuse_reason' => $reason,
            'shape_prior' => $prior,
        ];
    }

    /**
     * @param  array<string,mixed>  $leap
     * @return array<string,mixed>
     */
    private function shapePrior(array $leap): array
    {
        $history = is_array($leap['shape_history'] ?? null) ? $leap['shape_history'] : [];

        return ($this->shapePrior ?? new AtlasLoopDecompositionShapePrior)->assess(
            (int) ($history['certified'] ?? 0),
            (int) ($history['total'] ?? 0),
        );
    }

    /**
     * @param  array<string,mixed>  $leap
     * @return list<string>
     */
    private function candidateTargets(array $leap): array
    {
        $paths = [
            $leap['target_path'] ?? null,
            $leap['consumer_path'] ?? null,
            ...((array) ($leap['seed_targets'] ?? [])),
        ];

        $targets = array_values(array_filter(array_map(
            static fn (mixed $path): string => ltrim(trim((string) $path), '/'),
            $paths,
        ), static fn (string $path): bool => $path !== ''));

        return array_values(array_unique($targets));
    }

    /** @param array<string,mixed> $prior */
    private function waveWidth(array $prior): int
    {
        return match ((string) ($prior['verdict'] ?? 'unknown')) {
            'ok' => 3,
            'suspect' => 1,
            default => 2,
        };
    }

    private function isLoopScope(string $path): bool
    {
        return str_starts_with($path, 'app/Services/Ai/AutonomousEvolution/');
    }

    /**
     * @param  array<string,mixed>  $leap
     * @return array<string,mixed>
     */
    private function packet(array $leap, string $target): array
    {
        $leapId = trim((string) $leap['leap_id']);
        $gapId = trim((string) ($leap['gap_id'] ?? ''));
        $base = pathinfo($target, PATHINFO_FILENAME);
        $test = 'tests/Unit/Ai/AutonomousEvolution/'.$base.'Test.php';

        return [
            'task_packet_id' => 'ambition-leap-'.substr(sha1($leapId.'|'.$target), 0, 16),
            'objective' => 'Implement a concrete ambition-leap slice for '.$target.' grounded in '.$leapId.'.',
            'allowed_files' => [$target, $test],
            'acceptance_criteria' => [
                'The slice changes runtime capability for the leap target instead of cosmetic cleanup.',
                'The implementation is covered by a focused test and preserves the canonical Loop anti-proxy contract.',
            ],
            'required_evidence' => [
                'unit_test:'.$test,
            ],
            'provenance' => [
                'leap_id' => $leapId,
                'gap_id' => $gapId,
                'grounded_evidence_refs' => array_values((array) ($leap['grounded_evidence_refs'] ?? [])),
            ],
        ];
    }
}
