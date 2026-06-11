<?php

namespace App\Console\Commands;

use App\Services\Ai\Cognitive\Failure\BayesianFailureTracker;
use App\Services\Ai\Cognitive\Failure\FailureRecurrenceMetricService;
use App\Services\Ai\Cognitive\Failure\FailureRepetitionAlerter;
use App\Services\Ai\Cognitive\Failure\FailureSignatureRepository;
use Illuminate\Console\Command;

class AtlasFailureCommand extends Command
{
    protected $signature = 'atlas:failure
        {action=recent : record|recent|diversity|signature|alerts|ack|review|recurrence}
        {subject? : Message, signature id, alert id, or review subject}
        {--domain=programming : Domain for record/recent/diversity}
        {--days=14 : Window in days}
        {--provider= : Provider filter for recurrence mode}
        {--message= : Failure message for record mode}
        {--status=open : Alert status for alerts mode: open|acknowledged|resolved|suppressed|all}
        {--reflection= : Operator reflection for ack mode}
        {--json : Print machine-readable JSON}';

    protected $description = 'Classify, inspect and review Atlas cognitive failure signatures.';

    public function handle(
        FailureSignatureRepository $signatures,
        FailureRepetitionAlerter $alerter,
        BayesianFailureTracker $tracker,
        FailureRecurrenceMetricService $recurrence,
    ): int {
        $action = trim((string) $this->argument('action')) ?: 'recent';
        $subject = trim((string) ($this->argument('subject') ?? ''));
        $domain = $this->stringOption('domain') ?: 'programming';
        $days = max(1, (int) $this->option('days'));

        return match ($action) {
            'record' => $this->record($signatures, $alerter, $subject, $domain),
            'recent' => $this->render([
                'schema_version' => 'atlas.cognitive.failure_cli.v1',
                'status' => 'ok',
                'mode' => 'recent',
                'domain' => $domain,
                'days' => $days,
                'signatures' => $signatures->recent($domain, $days),
            ]),
            'diversity' => $this->render($tracker->compute($domain, $days)),
            'signature' => $this->render([
                'schema_version' => 'atlas.cognitive.failure_cli.v1',
                'status' => 'ok',
                'mode' => 'signature',
                'signature' => $signatures->find((int) $subject),
            ], $subject !== '' ? self::SUCCESS : self::FAILURE),
            'alerts' => $this->render([
                'schema_version' => 'atlas.cognitive.failure_cli.v1',
                'status' => 'ok',
                'mode' => 'alerts',
                'alerts' => $signatures->alerts($this->stringOption('status') ?: 'open'),
            ]),
            'ack' => $this->render([
                'schema_version' => 'atlas.cognitive.failure_cli.v1',
                'status' => 'acknowledged',
                'mode' => 'ack',
                'alert' => $signatures->acknowledge((int) $subject, $this->stringOption('reflection') ?: ''),
            ], $subject !== '' ? self::SUCCESS : self::FAILURE),
            'review' => $this->review($signatures, $tracker, $domain, $days),
            // AP-819 F3 — recorrência no outcome cru (ai_job_attempts), não no corpus.
            'recurrence' => $this->render($recurrence->compute($days, $this->stringOption('provider') ?: null)),
            default => $this->render([
                'schema_version' => 'atlas.cognitive.failure_cli.v1',
                'status' => 'invalid_action',
                'supported_actions' => ['record', 'recent', 'diversity', 'signature', 'alerts', 'ack', 'review', 'recurrence'],
            ], self::FAILURE),
        };
    }

    private function record(FailureSignatureRepository $signatures, FailureRepetitionAlerter $alerter, string $subject, string $domain): int
    {
        $message = $this->stringOption('message') ?: $subject;
        if ($message === '') {
            return $this->render(['status' => 'invalid_input', 'reason' => 'message_required'], self::FAILURE);
        }

        $signature = $signatures->record([
            'domain' => $domain,
            'message' => $message,
            'event_type' => 'manual_failure',
            'envelope_id' => 'failure-cli:'.substr(hash('sha1', $message.now()->toJSON()), 0, 20),
        ]);
        $alert = ($signature['status'] ?? null) === 'table_missing' ? null : $alerter->evaluate($signature);

        return $this->render([
            'schema_version' => 'atlas.cognitive.failure_cli.v1',
            'status' => 'recorded',
            'mode' => 'record',
            'signature' => $signature,
            'alert' => $alert,
        ]);
    }

    private function review(FailureSignatureRepository $signatures, BayesianFailureTracker $tracker, string $domain, int $days): int
    {
        return $this->render([
            'schema_version' => 'atlas.cognitive.failure_review.v1',
            'status' => 'planned',
            'mode' => 'review',
            'domain' => $domain,
            'days' => $days,
            'flow' => 'learning.failure_review',
            'diversity' => $tracker->compute($domain, $days),
            'open_alerts' => $signatures->alerts('open'),
            'rules' => [
                'plan_only_until_operator_acceptance' => true,
                'does_not_auto_correct_behavior' => true,
                'repeated_failure_requires_reflection_or_targeted_practice' => true,
            ],
        ]);
    }

    private function render(array $payload, int $exit = self::SUCCESS): int
    {
        if ((bool) $this->option('json')) {
            $this->line(json_encode($payload, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE));

            return $exit;
        }

        $this->components->twoColumnDetail('Atlas Failure', (string) ($payload['status'] ?? 'unknown'));
        $this->components->twoColumnDetail('Mode', (string) ($payload['mode'] ?? data_get($payload, 'schema_version', 'n/a')));

        return $exit;
    }

    private function stringOption(string $key): ?string
    {
        $value = trim((string) ($this->option($key) ?? ''));

        return $value !== '' ? $value : null;
    }
}
