<?php

declare(strict_types=1);

namespace App\Console\Commands;

use App\Services\Ai\SelfImprovement\AtlasSelfImprovementCapabilityMaturityScoreService;
use Illuminate\Console\Command;
use Throwable;

final class AtlasSelfImprovementMaturityScoreCommand extends Command
{
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
        $this->components->twoColumnDetail('capability', (string) ($payload['capability'] ?? '—'));
        $this->components->twoColumnDetail('achieved_level', (string) ($payload['achieved_level'] ?? '—'));
        $this->components->twoColumnDetail('expected_level', (string) ($payload['expected_level'] ?? '—'));
        $this->components->twoColumnDetail('meets_expected', $payload['meets_expected'] ? 'yes' : 'no');
    }
}
