<?php

declare(strict_types=1);

namespace App\Console\Commands;

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
    private function resolveJsonOption(string $key): ?array
    {
        $raw = $this->option($key);
        if (! is_string($raw) || trim($raw) === '') {
            return null;
        }
        $raw = trim($raw);
        if (str_starts_with($raw, '@')) {
            $path = substr($raw, 1);
            if (! is_file($path)) {
                return null;
            }
            $raw = (string) file_get_contents($path);
        }
        try {
            $decoded = json_decode($raw, true, 512, JSON_THROW_ON_ERROR);
        } catch (Throwable) {
            return null;
        }

        return is_array($decoded) ? $decoded : null;
    }

    private function stringOption(string $key): ?string
    {
        $value = $this->option($key);
        if (! is_string($value)) {
            return null;
        }
        $value = trim($value);

        return $value === '' ? null : $value;
    }

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
