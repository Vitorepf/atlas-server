<?php

namespace App\Console\Commands;

use App\Services\Ai\Cognitive\PredictiveFailure\PredictiveFailureFlow;
use Illuminate\Console\Command;

class AtlasPredictCommand extends Command
{
    protected $signature = 'atlas:predict
        {action=failure : failure|history|metrics|resolve}
        {subject? : Knowledge node/topic or insertion id}
        {--domain=learning : Domain for prediction}
        {--outcome= : Outcome for resolve: success|partial|failure|abandoned|skipped}
        {--signature= : Optional actual failure signature key}
        {--window=60 : Metrics/history window in days}
        {--load=normal : Cognitive load level}
        {--json : Print machine-readable JSON}';

    protected $description = 'Run governed cognitive predictive failure insertion and calibration reports.';

    public function handle(PredictiveFailureFlow $flow): int
    {
        $action = trim((string) $this->argument('action'));
        $subject = trim((string) ($this->argument('subject') ?? ''));
        $domain = trim((string) $this->option('domain')) ?: 'learning';
        $window = (int) $this->option('window');

        if ($action === 'failure' && $subject === '') {
            return $this->render([
                'schema_version' => 'atlas.predict.command.v1',
                'status' => 'invalid_input',
                'reason' => 'predictive_failure_subject_required',
                'flow' => 'learning.predictive_failure_insertion',
                'governance' => PredictiveFailureFlow::governanceContract(),
            ], self::FAILURE);
        }

        if ($action === 'resolve' && (int) $subject <= 0) {
            return $this->render([
                'schema_version' => 'atlas.predict.command.v1',
                'status' => 'invalid_input',
                'reason' => 'predictive_failure_insertion_id_required',
                'flow' => 'learning.predictive_failure_insertion',
                'governance' => PredictiveFailureFlow::governanceContract(),
            ], self::FAILURE);
        }

        $payload = match ($action) {
            'history' => $flow->history($domain, $window),
            'metrics' => $flow->metrics($domain, $window),
            'resolve' => $flow->resolve((int) $subject, trim((string) $this->option('outcome')), $this->actualSignature()),
            'failure' => $flow->insert($subject, $domain, ['level' => trim((string) $this->option('load')) ?: 'normal']),
            default => ['schema_version' => 'atlas.predict.command.v1', 'status' => 'invalid_input', 'reason' => 'unknown_predict_action'],
        };

        $exit = in_array($payload['status'] ?? null, ['blocked', 'missing', 'invalid_input'], true)
            ? self::FAILURE
            : self::SUCCESS;

        return $this->render($payload, $exit);
    }

    /**
     * @return array<string,mixed>|null
     */
    private function actualSignature(): ?array
    {
        $signature = trim((string) ($this->option('signature') ?? ''));

        return $signature !== '' ? ['signature_key' => $signature] : null;
    }

    /**
     * @param  array<string,mixed>  $payload
     */
    private function render(array $payload, int $exit): int
    {
        if ((bool) $this->option('json')) {
            $this->line(json_encode($payload, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE));

            return $exit;
        }

        $this->components->twoColumnDetail('Atlas Predict', (string) ($payload['status'] ?? 'unknown'));
        $this->components->twoColumnDetail('Flow', (string) ($payload['flow'] ?? 'learning.predictive_failure_insertion'));
        $this->components->twoColumnDetail('Next', (string) ($payload['next_action'] ?? 'n/a'));

        return $exit;
    }
}
