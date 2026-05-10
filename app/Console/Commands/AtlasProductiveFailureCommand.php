<?php

namespace App\Console\Commands;

use App\Services\Ai\Cognitive\ProductiveFailure\ProductiveFailureFlow;
use Illuminate\Console\Command;

class AtlasProductiveFailureCommand extends Command
{
    protected $signature = 'atlas:productive-failure
        {actionOrTopic? : Topic, or action: status|history|transfer-tests|resume|attempt|compare|articulate|complete}
        {subject? : Session id for phase actions}
        {--domain=learning : Domain for the productive failure session}
        {--dreyfus-stage=2 : Dreyfus stage target 1..5}
        {--prediction= : Operator prediction for phase 1}
        {--attempt= : Operator solution attempt for phase 1}
        {--time-spent=0 : Minutes spent before recording attempt}
        {--confidence=3 : Pre-comparison confidence 1..5}
        {--reality= : Validated reality for phase 2}
        {--delta= : Explicit prediction error delta for phase 2}
        {--insight= : Model update / key insight for phase 3}
        {--why-failed= : Why the initial attempt failed}
        {--principle= : Transferable principle extracted in phase 3}
        {--window=30 : History window in days}
        {--due-only : For transfer-tests, return only proposals scheduled up to today}
        {--json : Print machine-readable JSON}';

    protected $description = 'Run governed Cognitive Productive Failure sessions for calibrated prediction-error learning.';

    public function handle(ProductiveFailureFlow $flow): int
    {
        $actionOrTopic = trim((string) ($this->argument('actionOrTopic') ?? ''));
        $subject = trim((string) ($this->argument('subject') ?? ''));
        $domain = trim((string) $this->option('domain')) ?: 'learning';

        $payload = match ($actionOrTopic) {
            'status', 'resume' => $flow->status($this->sessionId($subject)),
            'history' => $flow->history($domain, (int) $this->option('window')),
            'transfer-tests' => $flow->transferTests($domain, (int) $this->option('window'), (bool) $this->option('due-only')),
            'attempt' => $flow->recordAttempt($this->sessionId($subject), [
                'operator_prediction' => $this->option('prediction'),
                'solution_attempt' => $this->option('attempt'),
                'time_spent_min' => (int) $this->option('time-spent'),
                'confidence_pre' => (int) $this->option('confidence'),
            ]),
            'compare' => $flow->recordComparison(
                $this->sessionId($subject),
                $this->nullableOption('reality'),
                $this->nullableOption('delta'),
            ),
            'articulate' => $flow->recordArticulation($this->sessionId($subject), [
                'model_update' => $this->option('insight'),
                'key_insight' => $this->option('insight'),
                'why_attempt_failed' => $this->option('why-failed'),
                'principle_extracted' => $this->option('principle'),
            ]),
            'complete' => $flow->complete($this->sessionId($subject)),
            default => $flow->start(
                topic: $actionOrTopic,
                domain: $domain,
                dreyfusStage: (int) $this->option('dreyfus-stage'),
            ),
        };

        $exit = in_array($payload['status'] ?? null, ['blocked', 'missing', 'invalid_input'], true)
            ? self::FAILURE
            : self::SUCCESS;

        return $this->render($payload, $exit);
    }

    private function sessionId(string $subject): int
    {
        return max(0, (int) $subject);
    }

    private function nullableOption(string $name): ?string
    {
        $value = trim((string) ($this->option($name) ?? ''));

        return $value !== '' ? $value : null;
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

        $this->components->twoColumnDetail('Atlas Productive Failure', (string) ($payload['status'] ?? 'unknown'));
        $this->components->twoColumnDetail('Flow', (string) ($payload['flow'] ?? 'learning.productive_failure'));
        $this->components->twoColumnDetail('Session', (string) data_get($payload, 'session.id', 'n/a'));
        $this->components->twoColumnDetail('Next', (string) ($payload['next_action'] ?? 'n/a'));

        return $exit;
    }
}
