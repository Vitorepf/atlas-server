<?php

declare(strict_types=1);

namespace App\Console\Commands;

use App\Console\Commands\Concerns\ReadsNonEmptyStringOption;
use App\Console\Commands\Concerns\ResolvesSilentJsonOption;
use App\Services\Ai\SelfImprovement\AtlasSelfImprovementDeltaScorecardService;
use Illuminate\Console\Command;
use Throwable;

/**
 * Atlas Self-Improvement Before/After Delta Scorecard CLI.
 *
 * Reads two snapshot blobs (--before / --after) and emits the canonical
 * 13-metric delta scorecard. Read-model only.
 *
 * Doc: docs/engineering-knowledge-base/atlas-self-improvement-governance-ladder.md
 */
final class AtlasSelfImprovementBeforeAfterCommand extends Command
{
    use ReadsNonEmptyStringOption;

    use ResolvesSilentJsonOption;

    protected $signature = 'atlas:self-improvement:before-after
        {--before= : Inline JSON or @path with the before snapshot scores}
        {--after= : Inline JSON or @path with the after snapshot scores}
        {--proposal-id= : Optional proposal_id to correlate}
        {--expected-power-gain= : Optional expected_power_gain text}
        {--human-review-required : Force promote_with_review unless delta is large}
        {--json : Emit canonical JSON}
        {--strict : Exit non-zero unless recommendation is promote/promote_with_review}';

    protected $description = 'Atlas Self-Improvement Before/After Delta Scorecard (13 metrics, weights total 100). Read-model.';

    public function handle(AtlasSelfImprovementDeltaScorecardService $service): int
    {
        $before = $this->resolveJsonOption('before') ?? [];
        $after = $this->resolveJsonOption('after') ?? [];

        $context = [
            'proposal_id' => $this->stringOption('proposal-id'),
            'expected_power_gain' => $this->stringOption('expected-power-gain'),
            'human_review_required' => (bool) $this->option('human-review-required'),
        ];

        $report = $service->compute($before, $after, $context);
        $this->emit($report);

        if (! (bool) $this->option('strict')) {
            return self::SUCCESS;
        }

        return in_array(
            $report['recommendation'],
            [
                AtlasSelfImprovementDeltaScorecardService::RECOMMEND_PROMOTE,
                AtlasSelfImprovementDeltaScorecardService::RECOMMEND_PROMOTE_WITH_REVIEW,
            ],
            true,
        ) ? self::SUCCESS : self::FAILURE;
    }

    /**
     * @return array<string,mixed>|null
     */


    /**
     * @param  array<string,mixed>  $payload
     */
    private function emit(array $payload): void
    {
        if ((bool) $this->option('json')) {
            $this->line((string) json_encode(
                $payload,
                JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE,
            ));

            return;
        }
        $this->components->twoColumnDetail('schema_version', (string) ($payload['schema_version'] ?? '—'));
        $this->components->twoColumnDetail('recommendation', (string) ($payload['recommendation'] ?? '—'));
        $this->components->twoColumnDetail('weighted_delta', (string) ($payload['weighted_delta'] ?? '—'));
        $this->components->twoColumnDetail('hard_regression', $payload['hard_regression_detected'] ? 'yes' : 'no');
    }
}
