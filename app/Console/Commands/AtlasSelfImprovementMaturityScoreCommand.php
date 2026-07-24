<?php

declare(strict_types=1);

namespace App\Console\Commands;

use App\Console\Commands\Concerns\ReadsNonEmptyStringOption;
use App\Console\Commands\Concerns\ResolvesSilentJsonOption;
use App\Services\Ai\SelfImprovement\AtlasSelfImprovementCapabilityMaturityScoreService;
use Illuminate\Console\Command;
use Throwable;
use App\Support\YesNo;

final class AtlasSelfImprovementMaturityScoreCommand extends Command
{
    use ReadsNonEmptyStringOption;

    use ResolvesSilentJsonOption;

    protected $signature = 'atlas:self-improvement:maturity-score
        {--descriptor= : Inline JSON or @path with the capability descriptor}
        {--workspace= : Workspace root override}
        {--json}
        {--strict : Exit non-zero unless capability meets expected_level}';

    protected $description = 'Atlas Self-Improvement Capability Maturity Score (0..10 ladder). Read-model.';

    public function handle(AtlasSelfImprovementCapabilityMaturityScoreService $service): int
    {
        $descriptor = $this->resolveJsonOption('descriptor');
        if ($descriptor === null) {
            $this->components->error('--descriptor is required');

            return self::FAILURE;
        }

        $context = [
            'workspace' => $this->stringOption('workspace') ?? base_path(),
        ];
        $report = $service->score($descriptor, $context);
        $this->emit($report);

        if (! (bool) $this->option('strict')) {
            return self::SUCCESS;
        }

        return ($report['meets_expected'] ?? false) ? self::SUCCESS : self::FAILURE;
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
        $this->components->twoColumnDetail('capability', (string) ($payload['capability'] ?? '—'));
        $this->components->twoColumnDetail('achieved_level', (string) ($payload['achieved_level'] ?? '—'));
        $this->components->twoColumnDetail('expected_level', (string) ($payload['expected_level'] ?? '—'));
        $this->components->twoColumnDetail('meets_expected', YesNo::format($payload['meets_expected']));
    }
}
