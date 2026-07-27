<?php

namespace App\Console\Commands;

use App\Services\Ai\Learning\Failure\BayesianFailureTracker;
use App\Services\Ai\Learning\Failure\FailureRecurrenceMetricService;
use App\Services\Ai\Learning\Failure\FailureRepetitionAlerter;
use App\Services\Ai\Learning\Failure\FailureSignatureRepository;
use App\Services\Ai\Learning\Failure\SuiteRedTriage;
use App\Services\Ai\Learning\Failure\WeeklyRedCountSnapshotStore;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\File;
use App\Console\Concerns\EmitsCanonicalJson;
use App\Console\Commands\Concerns\ReadsNonEmptyStringOption;

class AtlasFailureCommand extends Command
{
    use ReadsNonEmptyStringOption;

    use EmitsCanonicalJson;

    protected $signature = 'atlas:failure
        {action=recent : record|recent|diversity|signature|alerts|ack|review|recurrence|red-triage}
        {subject? : Message, signature id, alert id, or review subject}
        {--domain=programming : Domain for record/recent/diversity}
        {--days=14 : Window in days}
        {--provider= : Provider filter for recurrence mode}
        {--message= : Failure message for record mode}
        {--test-report= : Optional JSON test report for review-mode suite triage}
        {--record-snapshot : Persist the REAL weekly red-count snapshot for this domain/week from the triaged report (L5-3 trend source-of-truth)}
        {--write-report : Persist review payload as a JSON receipt}
        {--report-path= : Path for --write-report (defaults to storage/app/atlas/evidence/fable-l5-3-failure-review.json)}
        {--status=open : Alert status for alerts mode: open|acknowledged|resolved|suppressed|all}
        {--reflection= : Operator reflection for ack mode}
        {--json : Print machine-readable JSON}';

    protected $description = 'Classify, inspect and review Atlas cognitive failure signatures.';

    public function __construct(private readonly SuiteRedTriage $triageHelper)
    {
        parent::__construct();
    }

    public function handle(
        FailureSignatureRepository $signatures,
        FailureRepetitionAlerter $alerter,
        BayesianFailureTracker $tracker,
        FailureRecurrenceMetricService $recurrence,
        WeeklyRedCountSnapshotStore $snapshots,
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
            'review' => $this->review($signatures, $tracker, $snapshots, $domain, $days),
            // AP-819 F3 — recorrência no outcome cru (ai_job_attempts), não no corpus.
            'recurrence' => $this->render($recurrence->compute($days, $this->stringOption('provider') ?: null)),
            // L5-3 — triagem standalone do lote vermelho mid-refactor (ambiental vs real).
            'red-triage' => $this->redTriage($snapshots, $domain),
            default => $this->render([
                'schema_version' => 'atlas.cognitive.failure_cli.v1',
                'status' => 'invalid_action',
                'supported_actions' => ['record', 'recent', 'diversity', 'signature', 'alerts', 'ack', 'review', 'recurrence', 'red-triage'],
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

    private function review(
        FailureSignatureRepository $signatures,
        BayesianFailureTracker $tracker,
        WeeklyRedCountSnapshotStore $snapshots,
        string $domain,
        int $days,
    ): int {
        $testSuiteTriage = $this->triageHelper->triage($this->stringOption('test-report'));

        // L5-3 — grava o snapshot REAL da semana ANTES de ler o trend, para que um run
        // com --record-snapshot já reflita a contagem real desta semana. O snapshot só
        // é gravado a partir de um relatório triado (status=triaged); jamais de um
        // número fornecido à mão.
        $snapshotRecord = null;
        if ((bool) $this->option('record-snapshot')) {
            $snapshotRecord = $snapshots->recordFromTriage($domain, $testSuiteTriage);
        }

        // O trend que ALIMENTA O GATE vem dos snapshots REAIS persistidos — não do
        // array `history` do relatório (que era forjável). Synthetic-history não passa.
        $realTrend = $snapshots->trend($domain);

        $claimPolicy = $this->l5ThreeClaimPolicy($testSuiteTriage, $realTrend);

        $payload = [
            'schema_version' => 'atlas.cognitive.failure_review.v1',
            'status' => $this->reviewStatus($testSuiteTriage, $claimPolicy),
            'mode' => 'review',
            'domain' => $domain,
            'days' => $days,
            'flow' => 'learning.failure_review',
            'diversity' => $tracker->compute($domain, $days),
            'open_alerts' => $signatures->alerts('open'),
            'test_suite_triage' => $testSuiteTriage,
            'real_weekly_red_trend' => $realTrend,
            'snapshot_record' => $snapshotRecord,
            'claim_policy' => $claimPolicy,
            'rules' => [
                'plan_only_until_operator_acceptance' => true,
                'does_not_auto_correct_behavior' => true,
                'repeated_failure_requires_reflection_or_targeted_practice' => true,
                'test_suite_triage_is_read_only' => true,
                'environmental_quarantine_requires_operator_review' => true,
                'gate_trend_source_is_real_persisted_snapshots' => true,
                'supplied_report_history_is_display_only' => true,
            ],
        ];

        if ((bool) $this->option('write-report')) {
            $path = $this->stringOption('report-path') ?: storage_path('app/atlas/evidence/fable-l5-3-failure-review.json');
            File::ensureDirectoryExists(dirname($path));
            File::put($path, json_encode($payload, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE)."\n");
            $payload['written_report_path'] = $path;
        }

        return $this->render($payload);
    }

    /**
     * @param  array<string,mixed>  $triage
     * @param  array<string,mixed>  $claimPolicy
     */
    private function reviewStatus(array $triage, array $claimPolicy): string
    {
        $status = (string) ($triage['status'] ?? 'not_supplied');
        if ($status === 'not_supplied') {
            return 'planned';
        }
        if ($status === 'blocked') {
            return 'blocked';
        }

        return (bool) ($claimPolicy['l5_3_completion_claim_allowed'] ?? false)
            ? 'suite_healing_trend_proven'
            : 'triage_ready';
    }

    /**
     * @param  array<string,mixed>  $triage
     * @param  array<string,mixed>  $realTrend  trend dos snapshots REAIS persistidos (WeeklyRedCountSnapshotStore)
     * @return array<string,mixed>
     */
    private function l5ThreeClaimPolicy(array $triage, array $realTrend): array
    {
        $status = (string) ($triage['status'] ?? 'not_supplied');
        $counts = (array) ($triage['counts'] ?? []);
        $environmental = (int) ($counts['environmental'] ?? 0);
        $real = (int) ($counts['real_failure'] ?? 0);
        $unknown = (int) ($counts['unknown'] ?? 0);

        // L5-3 anti-Goodhart: o gate só destrava sobre DADO REAL persistido. Exige
        // ≥2 semanas REAIS distintas, estritamente caindo. O `history` do relatório
        // (forjável) NÃO conta mais.
        $realSnapshotCount = (int) ($realTrend['real_snapshot_count'] ?? 0);
        $minRequired = (int) ($realTrend['min_required'] ?? WeeklyRedCountSnapshotStore::MIN_REAL_SNAPSHOTS);
        $decreasing = (bool) ($realTrend['weekly_reds_decreasing'] ?? false);

        $blockers = [];
        if ($status !== 'triaged') {
            $blockers[] = 'test_suite_report_not_triaged';
        }
        if ($realSnapshotCount < $minRequired) {
            $blockers[] = 'insufficient_real_weekly_snapshots';
        }
        if ($realSnapshotCount >= $minRequired && ! $decreasing) {
            $blockers[] = 'real_weekly_red_trend_not_decreasing';
        }
        if ($real > 0) {
            $blockers[] = 'real_failures_remain_fix_forward_required';
        }
        if ($unknown > 0) {
            $blockers[] = 'unknown_failures_require_manual_triage';
        }

        return [
            'l5_3_completion_claim_allowed' => $blockers === [],
            'weekly_reds_decreasing' => $decreasing,
            'trend_source' => 'real_persisted_snapshots',
            'real_weekly_snapshot_count' => $realSnapshotCount,
            'min_real_snapshots_required' => $minRequired,
            'real_failures_remaining' => $real,
            'unknown_failures_remaining' => $unknown,
            'environmental_quarantine_candidates' => $environmental,
            'auto_corrects_tests' => false,
            'auto_quarantines_tests' => false,
            'operator_review_required_for_quarantine' => true,
            'fix_forward_required_for_real_failures' => true,
            'synthetic_history_cannot_pass_gate' => true,
            'blockers' => array_values(array_unique($blockers)),
        ];
    }

    private function redTriage(WeeklyRedCountSnapshotStore $snapshots, string $domain): int
    {
        $triage = $this->triageHelper->triage($this->stringOption('test-report'));

        $snapshotRecord = null;
        if ((bool) $this->option('record-snapshot')) {
            $snapshotRecord = $snapshots->recordFromTriage($domain, $triage);
        }

        return $this->render([
            'schema_version' => 'atlas.cognitive.failure_cli.v1',
            'status' => (string) ($triage['status'] ?? 'not_supplied'),
            'mode' => 'red-triage',
            'domain' => $domain,
            'test_suite_triage' => $triage,
            'real_weekly_red_trend' => $snapshots->trend($domain),
            'snapshot_record' => $snapshotRecord,
            'rules' => [
                'test_suite_triage_is_read_only' => true,
                'environmental_quarantine_requires_operator_review' => true,
                'does_not_auto_correct_behavior' => true,
            ],
        ]);
    }

    private function render(array $payload, int $exit = self::SUCCESS): int
    {
        if ((bool) $this->option('json')) {
            $this->line($this->encode($payload));

            return $exit;
        }

        $this->components->twoColumnDetail('Atlas Failure', (string) ($payload['status'] ?? 'unknown'));
        $this->components->twoColumnDetail('Mode', (string) ($payload['mode'] ?? data_get($payload, 'schema_version', 'n/a')));

        return $exit;
    }

}
