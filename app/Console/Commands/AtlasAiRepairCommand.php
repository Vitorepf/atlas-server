<?php

namespace App\Console\Commands;

use App\Services\Ai\Kernel\Evidence\AtlasEvidenceLedger;
use App\Services\Ai\Kernel\Failure\FailureClassification;
use App\Services\Ai\Kernel\Failure\FailureDomain;
use App\Services\Ai\Kernel\Repair\AtlasRepairOrchestrator;
use App\Services\Ai\Kernel\Repair\RepairPolicy;
use App\Services\Ai\Kernel\Repair\RepairRequest;
use App\Services\Ai\Kernel\Repair\RepairStrategy;
use App\Services\Ai\Support\AiStringListNormalizer;
use Illuminate\Console\Command;

class AtlasAiRepairCommand extends Command
{
    protected $signature = 'atlas:ai:repair
        {--envelope=repair_scaffold : Envelope id that owns the failure}
        {--receipt= : Decision receipt id associated with the failure}
        {--failure=provider.timeout : FailureDomain value}
        {--source=cli : Failure classification source}
        {--signal=* : Failure signals}
        {--attempt=0 : Current repair attempt count}
        {--max-attempts=1 : Maximum repair attempts allowed by policy}
        {--policy-enabled=1 : Whether the repair policy is enabled}
        {--strategy=* : Allowed repair strategy; repeatable. Defaults to all strategies}
        {--evidence=* : Evidence references available to the repair planner}
        {--attempt-repair : Return governed repair attempt result instead of plan only}
        {--json : Print machine-readable JSON}';

    protected $description = 'Inspect the Atlas AI repair loop contract without executing repair actions.';

    public function handle(AtlasRepairOrchestrator $repair, AtlasEvidenceLedger $ledger): int
    {
        $request = new RepairRequest(
            envelopeId: (string) $this->option('envelope'),
            receiptId: $this->stringOption('receipt'),
            failure: new FailureClassification(
                domain: FailureDomain::tryFrom((string) $this->option('failure')) ?? FailureDomain::Unknown,
                source: (string) $this->option('source'),
                signals: AiStringListNormalizer::nonEmptyStrings($this->option('signal')),
                metadata: [
                    'surface' => 'atlas_cli',
                    'command' => 'atlas:ai:repair',
                ],
            ),
            policy: new RepairPolicy(
                enabled: $this->truthy($this->option('policy-enabled')),
                maxAttempts: max(0, (int) $this->option('max-attempts')),
                allowedStrategies: $this->strategies(),
            ),
            currentAttempt: max(0, (int) $this->option('attempt')),
            evidenceRefs: AiStringListNormalizer::nonEmptyStrings($this->option('evidence')),
            dryRun: true,
            metadata: [
                'surface' => 'atlas_cli',
                'command' => 'atlas:ai:repair',
                'contract_foundation_only' => true,
            ],
        );

        if ((bool) $this->option('attempt-repair')) {
            $result = $repair->attempt($request);
            $ledgerEvent = $ledger->recordRepairDecision($result->decision, $this->ledgerContext($request));
            $completedLedgerEvent = $ledger->recordRepairResult($result, $this->ledgerContext($request, [
                'causation_id' => data_get($result->decision->evidencePayload, 'decision_hash'),
            ]));
            $payload = [
                'schema_version' => 1,
                'status' => 'attempted_scaffold',
                'repair' => $result->toArray(),
                'evidence_ledger' => $this->ledgerEventPayload($ledgerEvent) + [
                    'completed' => $this->ledgerEventPayload($completedLedgerEvent),
                ],
                'compliance' => $repair->complianceReport(),
            ];
        } else {
            $decision = $repair->plan($request);
            $ledgerEvent = $ledger->recordRepairDecision($decision, $this->ledgerContext($request));
            $payload = [
                'schema_version' => 1,
                'status' => 'planned_scaffold',
                'request' => $request->toArray(),
                'repair' => $decision->toArray(),
                'evidence_ledger' => $this->ledgerEventPayload($ledgerEvent),
                'compliance' => $repair->complianceReport(),
            ];
        }

        if ((bool) $this->option('json')) {
            $this->line(json_encode($payload, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE));

            return self::SUCCESS;
        }

        $repairPayload = (array) ($payload['repair'] ?? []);
        $decision = (array) ($repairPayload['decision'] ?? $repairPayload);
        $attempt = (array) ($repairPayload['attempt'] ?? []);

        $this->components->twoColumnDetail('<fg=bright-blue;options=bold>Atlas AI Repair Loop</>', (string) $payload['status']);
        $this->components->twoColumnDetail('Envelope', $request->envelopeId);
        $this->components->twoColumnDetail('Failure', $request->failure->domain->value);
        $this->components->twoColumnDetail('Decision', (string) ($decision['status'] ?? '-'));
        $this->components->twoColumnDetail('Strategy', (string) ($decision['strategy'] ?? '-'));
        $this->components->twoColumnDetail('Dry run', $request->dryRun ? 'yes' : 'no');
        $this->components->twoColumnDetail('Repair execution', data_get($repairPayload, 'executed', false) ? 'attempted' : 'disabled');
        $this->components->twoColumnDetail('Compliance', data_get($payload, 'compliance.ok') ? 'ok' : 'failed');

        if ($attempt !== []) {
            $this->components->twoColumnDetail('Attempt number', (string) ($attempt['attempt_number'] ?? '-'));
            $this->components->twoColumnDetail('Attempt executed', ($attempt['executed'] ?? false) ? 'yes' : 'no');
        }

        $reasons = (array) ($attempt['reasons'] ?? $decision['reasons'] ?? []);
        if ($reasons !== []) {
            $this->line('');
            $this->table(['reason'], array_map(fn (string $reason): array => [$reason], $reasons));
        }

        return self::SUCCESS;
    }

    private function stringOption(string $key): ?string
    {
        $value = $this->option($key);

        return is_string($value) && $value !== '' ? $value : null;
    }

    /**
     * @return array<int,string>
     */
    private function strategies(): array
    {
        $strategies = AiStringListNormalizer::nonEmptyStrings($this->option('strategy'));

        if ($strategies === []) {
            return RepairStrategy::values();
        }

        return array_values(array_filter(
            $strategies,
            fn (string $strategy): bool => in_array($strategy, RepairStrategy::values(), true),
        ));
    }

    private function truthy(mixed $value): bool
    {
        if (is_bool($value)) {
            return $value;
        }

        return in_array(strtolower((string) $value), ['1', 'true', 'yes', 'on', 'enabled'], true);
    }

    /**
     * @return array<string,mixed>
     */
    private function ledgerContext(RepairRequest $request, array $overrides = []): array
    {
        return array_merge([
            'tenant_id' => 'default',
            'operator_id' => 'cli',
            'envelope_id' => $request->envelopeId,
            'receipt_id' => $request->receiptId,
            'correlation_id' => $request->envelopeId,
            'emitter_stage' => 'atlas_cli.repair',
            'emitter_version' => 'atlas_cli.repair.v1',
        ], $overrides);
    }

    /**
     * @return array<string,mixed>
     */
    private function ledgerEventPayload(mixed $event): array
    {
        return [
            'recorded' => $event !== null,
            'event_id' => data_get($event, 'event_id'),
            'event_type' => data_get($event, 'event_type'),
            'emitter_stage' => data_get($event, 'emitter_stage'),
            'payload_hash' => data_get($event, 'payload_hash'),
        ];
    }
}
