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
        {--test-report= : Optional JSON test report for review-mode suite triage}
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
        $testSuiteTriage = $this->testSuiteTriage($this->stringOption('test-report'));

        return $this->render([
            'schema_version' => 'atlas.cognitive.failure_review.v1',
            'status' => 'planned',
            'mode' => 'review',
            'domain' => $domain,
            'days' => $days,
            'flow' => 'learning.failure_review',
            'diversity' => $tracker->compute($domain, $days),
            'open_alerts' => $signatures->alerts('open'),
            'test_suite_triage' => $testSuiteTriage,
            'rules' => [
                'plan_only_until_operator_acceptance' => true,
                'does_not_auto_correct_behavior' => true,
                'repeated_failure_requires_reflection_or_targeted_practice' => true,
                'test_suite_triage_is_read_only' => true,
                'environmental_quarantine_requires_operator_review' => true,
            ],
        ]);
    }

    /**
     * @return array<string,mixed>
     */
    private function testSuiteTriage(?string $path): array
    {
        if ($path === null) {
            return [
                'schema_version' => 'atlas.cognitive.test_suite_triage.v1',
                'status' => 'not_supplied',
                'read_only' => true,
            ];
        }

        if (! is_file($path)) {
            return [
                'schema_version' => 'atlas.cognitive.test_suite_triage.v1',
                'status' => 'blocked',
                'reason' => 'test_report_missing',
                'path' => $path,
                'read_only' => true,
            ];
        }

        $decoded = json_decode((string) file_get_contents($path), true);
        if (! is_array($decoded)) {
            return [
                'schema_version' => 'atlas.cognitive.test_suite_triage.v1',
                'status' => 'blocked',
                'reason' => 'test_report_json_invalid',
                'path' => $path,
                'read_only' => true,
            ];
        }

        $tests = $this->testRows($decoded);
        $items = [];
        foreach ($tests as $test) {
            $status = mb_strtolower((string) ($test['status'] ?? ''));
            if (! in_array($status, ['failed', 'failure', 'error'], true)) {
                continue;
            }
            $message = (string) ($test['message'] ?? $test['error'] ?? $test['output'] ?? '');
            $classification = $this->classifyTestFailure($message);
            $items[] = [
                'test' => (string) ($test['name'] ?? $test['test'] ?? $test['file'] ?? 'unknown_test'),
                'classification' => $classification['classification'],
                'confidence' => $classification['confidence'],
                'reason' => $classification['reason'],
                'recommended_action' => $classification['recommended_action'],
                'message_excerpt' => mb_substr($message, 0, 220),
            ];
        }

        $environmental = count(array_filter($items, static fn (array $item): bool => $item['classification'] === 'environmental'));
        $real = count(array_filter($items, static fn (array $item): bool => $item['classification'] === 'real_failure'));
        $unknown = count(array_filter($items, static fn (array $item): bool => $item['classification'] === 'unknown'));

        return [
            'schema_version' => 'atlas.cognitive.test_suite_triage.v1',
            'status' => 'triaged',
            'path' => $path,
            'read_only' => true,
            'total_tests_seen' => count($tests),
            'failed_tests_seen' => count($items),
            'counts' => [
                'environmental' => $environmental,
                'real_failure' => $real,
                'unknown' => $unknown,
            ],
            'items' => $items,
            'trend' => $this->suiteTrend((array) ($decoded['history'] ?? [])),
            'claim_policy' => [
                'auto_corrects_tests' => false,
                'auto_quarantines_tests' => false,
                'operator_review_required_for_quarantine' => true,
                'fix_forward_required_for_real_failures' => true,
            ],
        ];
    }

    /**
     * @return list<array<string,mixed>>
     */
    private function testRows(array $decoded): array
    {
        $rows = $decoded['tests'] ?? $decoded['failures'] ?? $decoded;
        if (! is_array($rows)) {
            return [];
        }

        $out = [];
        foreach ($rows as $row) {
            if (is_array($row)) {
                $out[] = $row;
            }
        }

        return $out;
    }

    /**
     * @return array{classification:string,confidence:float,reason:string,recommended_action:string}
     */
    private function classifyTestFailure(string $message): array
    {
        $lower = mb_strtolower($message);
        $environmentalNeedles = [
            'connection refused',
            'sqlstate',
            'database is locked',
            'redis',
            'timed out',
            'timeout',
            'permission denied',
            'no such file or directory',
            'docker',
            'chromium',
            'browser',
            'port already in use',
        ];
        foreach ($environmentalNeedles as $needle) {
            if (str_contains($lower, $needle)) {
                return [
                    'classification' => 'environmental',
                    'confidence' => 0.82,
                    'reason' => 'matched_environmental_signal:'.$needle,
                    'recommended_action' => 'document_quarantine_candidate_and_rerun_after_environment_repair',
                ];
            }
        }

        $realNeedles = [
            'failed asserting',
            'typeerror',
            'parse error',
            'undefined method',
            'undefined function',
            'expected',
            'actual',
            'assertsame',
        ];
        foreach ($realNeedles as $needle) {
            if (str_contains($lower, $needle)) {
                return [
                    'classification' => 'real_failure',
                    'confidence' => 0.78,
                    'reason' => 'matched_real_failure_signal:'.$needle,
                    'recommended_action' => 'fix_forward_code_or_test_contract_then_rerun_targeted_suite',
                ];
            }
        }

        return [
            'classification' => 'unknown',
            'confidence' => 0.4,
            'reason' => 'no_known_signal_matched',
            'recommended_action' => 'manual_triage_required_before_quarantine_or_fix_claim',
        ];
    }

    /**
     * @return array<string,mixed>
     */
    private function suiteTrend(array $history): array
    {
        $points = [];
        foreach ($history as $row) {
            if (! is_array($row)) {
                continue;
            }
            $failed = $row['failed'] ?? $row['failed_tests'] ?? null;
            if (is_numeric($failed)) {
                $points[] = [
                    'label' => (string) ($row['week'] ?? $row['date'] ?? count($points) + 1),
                    'failed' => (int) $failed,
                ];
            }
        }

        if (count($points) < 2) {
            return [
                'status' => 'insufficient_history',
                'points' => $points,
                'weekly_reds_decreasing' => false,
            ];
        }

        $first = (int) $points[0]['failed'];
        $last = (int) $points[count($points) - 1]['failed'];

        return [
            'status' => $last < $first ? 'decreasing' : ($last > $first ? 'increasing' : 'flat'),
            'points' => $points,
            'weekly_reds_decreasing' => $last < $first,
            'delta' => $last - $first,
        ];
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
