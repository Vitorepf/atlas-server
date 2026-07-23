<?php

namespace App\Services\Ai\RealExecution;

final readonly class CandidatePerformancePolicy
{
    public function __construct(
        public string $schemaVersion,
        public string $workload,
        public int $warmup,
        public int $repetitions,
        public float $timeoutSeconds,
        public float $maxRatio,
        public float $wallSlackMs,
        public float $cpuSlackUs,
        public int $rssSlackBytes,
        public float $maxCv,
        public bool $recoveryProbe,
    ) {}

    public static function frozen(): self
    {
        return new self('atlas.performance_policy.kernel_candidate_fixture.v2', 'kernel_candidate_fixture_v1:app/Candidate.php',
            1, 5, 2.0, 1.50, 5.0, 5000.0, 4_194_304, 0.50, true);
    }

    /** @return array<string,int|float|string|bool> */
    public function toArray(): array
    {
        return ['schema_version' => $this->schemaVersion, 'workload' => $this->workload, 'warmup' => $this->warmup,
            'repetitions' => $this->repetitions, 'timeout_seconds' => $this->timeoutSeconds, 'max_ratio' => $this->maxRatio,
            'wall_slack_ms' => $this->wallSlackMs, 'cpu_slack_us' => $this->cpuSlackUs, 'rss_slack_bytes' => $this->rssSlackBytes,
            'max_cv' => $this->maxCv, 'recovery_probe' => $this->recoveryProbe];
    }
}
