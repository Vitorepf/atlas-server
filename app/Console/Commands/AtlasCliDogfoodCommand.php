<?php

namespace App\Console\Commands;

use App\Support\StringOrNull;
use App\Services\Ai\Cli\AtlasCliDogfoodService;
use Illuminate\Console\Command;

class AtlasCliDogfoodCommand extends Command
{
    protected $signature = 'atlas:cli:dogfood
        {action=report : start, record, run, report or reset}
        {--workspace= : Workspace path. Defaults to current directory}
        {--scenario= : Scenario key, for example dev_task or provider_handoff}
        {--provider= : Provider used during the session}
        {--result=passed : passed, failed, blocked, needs_review or running}
        {--duration-minutes= : Session duration in minutes}
        {--notes= : Short operator note}
        {--days=3 : Observation window for report}
        {--release-version=v2.0.0 : Release version used by the smoke release check}
        {--stop-on-failure : Stop dogfood run after the first failed scenario}
        {--real : Require non-smoke dogfood evidence in report}
        {--strict : Return failure when the report is not product-ready}
        {--confirm-reset : Required with reset}
        {--json : Print machine-readable JSON}';

    protected $description = 'Track real Atlas CLI dogfooding sessions and final-product usage coverage.';

    public function handle(AtlasCliDogfoodService $dogfood): int
    {
        $action = (string) $this->argument('action');
        $payload = match ($action) {
            'start' => $dogfood->start(
                workspace: $this->workspace(),
                scenario: $this->stringOption('scenario'),
                provider: $this->stringOption('provider'),
                notes: $this->stringOption('notes'),
            ),
            'record' => $dogfood->record(
                workspace: $this->workspace(),
                scenario: $this->stringOption('scenario'),
                provider: $this->stringOption('provider'),
                result: (string) $this->option('result'),
                durationMinutes: $this->durationMinutes(),
                notes: $this->stringOption('notes'),
            ),
            'report' => $dogfood->report(
                workspace: $this->workspace(),
                days: (int) $this->option('days'),
                requireReal: (bool) $this->option('real') || (bool) $this->option('strict'),
            ),
            'run' => $dogfood->runSmoke(
                workspace: $this->workspace(),
                releaseVersion: (string) $this->option('release-version'),
                stopOnFailure: (bool) $this->option('stop-on-failure'),
            ),
            'reset' => (bool) $this->option('confirm-reset')
                ? $dogfood->reset()
                : ['ok' => false, 'status' => 'reset_blocked', 'message' => 'Use --confirm-reset para apagar o log de dogfooding.'],
            default => ['ok' => false, 'status' => 'invalid_action', 'message' => 'Acao invalida. Use start, record, run, report ou reset.'],
        };

        if ((bool) $this->option('json')) {
            $this->line(json_encode($payload, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE));

            return $this->exitCode($action, $payload);
        }

        $this->render($payload);

        return $this->exitCode($action, $payload);
    }

    /**
     * @param  array<string,mixed>  $payload
     */
    private function render(array $payload): void
    {
        $this->newLine();
        $this->components->twoColumnDetail('<fg=bright-blue;options=bold>Atlas CLI Dogfood</>', (string) ($payload['status'] ?? 'unknown'));

        if (isset($payload['path'])) {
            $this->components->twoColumnDetail('Log', (string) $payload['path']);
        }

        if (isset($payload['message'])) {
            $this->warn((string) $payload['message']);
        }

        if (isset($payload['event']) && is_array($payload['event'])) {
            $this->table(
                ['campo', 'valor'],
                collect($payload['event'])->map(fn (mixed $value, string $key): array => [
                    $key,
                    is_scalar($value) || $value === null ? (string) $value : json_encode($value),
                ])->values()->all(),
            );
        }

        if (isset($payload['gates']) && is_array($payload['gates'])) {
            $this->components->twoColumnDetail('Workspace', (string) ($payload['workspace'] ?? '-'));
            $this->components->twoColumnDetail('Dias observados', (string) count((array) ($payload['observed_days'] ?? [])));
            $this->components->twoColumnDetail('Uso real obrigatorio', ($payload['requires_real_usage'] ?? false) ? 'sim' : 'nao');
            $this->components->twoColumnDetail('Pendentes', implode(', ', (array) ($payload['missing_scenarios'] ?? [])) ?: 'nenhum');
            if (($payload['requires_real_usage'] ?? false) === true) {
                $this->components->twoColumnDetail('Pendentes reais', implode(', ', (array) ($payload['missing_real_scenarios'] ?? [])) ?: 'nenhum');
            }
            $this->table(
                ['gate', 'status', 'detalhe'],
                collect($payload['gates'])->map(fn (array $gate): array => [
                    $gate['name'] ?? '-',
                    $gate['status'] ?? '-',
                    $gate['detail'] ?? '-',
                ])->all(),
            );
        }

        if (isset($payload['results']) && is_array($payload['results'])) {
            $this->components->twoColumnDetail('Profile', (string) ($payload['profile'] ?? 'smoke'));
            $this->table(
                ['scenario', 'status', 'exit', 'min'],
                collect($payload['results'])->map(fn (array $result): array => [
                    $result['scenario'] ?? '-',
                    $result['status'] ?? '-',
                    (string) ($result['exit_code'] ?? '-'),
                    (string) ($result['duration_minutes'] ?? '-'),
                ])->all(),
            );
        }
    }

    /**
     * @param  array<string,mixed>  $payload
     */
    private function exitCode(string $action, array $payload): int
    {
        if ($action === 'report') {
            return (bool) $this->option('strict') && ($payload['status'] ?? null) !== 'passed'
                ? self::FAILURE
                : self::SUCCESS;
        }

        if (($payload['ok'] ?? false) === false && ! in_array($action, ['record', 'start'], true)) {
            return self::FAILURE;
        }

        return self::SUCCESS;
    }

    private function durationMinutes(): ?int
    {
        $value = $this->option('duration-minutes');

        return is_numeric($value) ? (int) $value : null;
    }

    private function stringOption(string $key): ?string
    {
        $value = $this->option($key);

        return StringOrNull::trimmed($value);
    }

    private function workspace(): string
    {
        $workspace = (string) ($this->option('workspace') ?: getcwd() ?: config('atlas.ai.workdir'));
        $resolved = realpath($workspace);

        return $resolved && is_dir($resolved) ? $resolved : $workspace;
    }
}
