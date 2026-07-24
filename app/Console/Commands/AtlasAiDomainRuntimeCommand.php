<?php

namespace App\Console\Commands;

use App\Console\Commands\Concerns\ReadsNonEmptyStringOption;
use App\Console\Concerns\EmitsCanonicalJson;
use App\Models\AiDomainManifest;
use App\Services\Ai\DomainRuntime\DomainCapabilityCatalogService;
use App\Services\Ai\DomainRuntime\DomainHandoffService;
use App\Services\Ai\DomainRuntime\DomainManifestRegistryService;
use App\Services\Ai\DomainRuntime\DomainMaturityAssessmentService;
use App\Services\Ai\DomainRuntime\DomainRuntimeControlPlaneService;
use App\Services\Ai\DomainRuntime\DomainRuntimeException;
use App\Services\Ai\DomainRuntime\DomainRuntimeReadinessService;
use App\Services\Ai\DomainRuntime\DomainRuntimeSelectionService;
use App\Services\Ai\DomainRuntime\DomainSeedManifests;
use Illuminate\Console\Command;
use Throwable;
use App\Support\YesNo;

class AtlasAiDomainRuntimeCommand extends Command
{
    use ReadsNonEmptyStringOption;

    use EmitsCanonicalJson;

    protected $signature = 'atlas:ai:domain-runtime
        {positional? : Optional positional action (alternative to --action)}
        {--action=readiness : readiness, seed-defaults, list, select, handoff, control-plane, assess-maturity, show}
        {--objective= : Objective text for select action}
        {--hint= : Optional hint domain for select}
        {--from= : Source domain for handoff}
        {--to= : Target domain for handoff}
        {--reason= : Reason for handoff}
        {--context= : Inline JSON string of context_pack for handoff}
        {--expected= : Inline JSON string of expected_output for handoff}
        {--domain= : Domain id for show/assess-maturity}
        {--stage= : Target maturity stage (1-5) for assess-maturity}
        {--json : Machine-readable JSON output}';

    protected $description = 'Atlas Domain Runtime: register/inspect manifests, capabilities, selection, handoff, maturity and control-plane snapshot.';

    public function handle(
        DomainManifestRegistryService $registry,
        DomainCapabilityCatalogService $catalog,
        DomainRuntimeSelectionService $selection,
        DomainHandoffService $handoff,
        DomainMaturityAssessmentService $maturity,
        DomainRuntimeControlPlaneService $controlPlane,
        DomainRuntimeReadinessService $readiness,
    ): int {
        $positional = $this->argument('positional');
        $action = is_string($positional) && trim($positional) !== ''
            ? trim($positional)
            : (string) $this->option('action');

        try {
            return match ($action) {
                'readiness' => $this->renderReadiness($readiness),
                'seed-defaults' => $this->renderSeedDefaults($registry),
                'list' => $this->renderList($registry, $catalog),
                'select' => $this->renderSelect($selection),
                'handoff' => $this->renderHandoff($handoff),
                'control-plane' => $this->renderControlPlane($controlPlane),
                'assess-maturity' => $this->renderAssessMaturity($registry, $maturity),
                'show' => $this->renderShow($registry, $catalog),
                default => $this->invalidAction($action),
            };
        } catch (DomainRuntimeException $e) {
            return $this->renderError('domain_runtime_exception', $e->getMessage());
        } catch (\InvalidArgumentException $e) {
            return $this->renderError('invalid_argument', $e->getMessage());
        } catch (Throwable $e) {
            return $this->renderError('exception', $e->getMessage(), $e::class);
        }
    }

    private function renderReadiness(DomainRuntimeReadinessService $readiness): int
    {
        $payload = $readiness->report();
        $this->emit($payload, function () use ($payload): void {
            $this->components->twoColumnDetail('schema', (string) $payload['schema']);
            $this->components->twoColumnDetail('ok', YesNo::trueFalse($payload['ok']));
            $this->components->twoColumnDetail('passed', (string) $payload['summary']['passed']);
            $this->components->twoColumnDetail('failed', (string) $payload['summary']['failed']);
        });

        return $payload['ok'] ? self::SUCCESS : self::FAILURE;
    }

    private function renderSeedDefaults(DomainManifestRegistryService $registry): int
    {
        $payload = $registry->seedDefaults(DomainSeedManifests::all());
        $this->emit($payload, function () use ($payload): void {
            $this->components->twoColumnDetail('created', (string) $payload['summary']['created']);
            $this->components->twoColumnDetail('skipped', (string) $payload['summary']['skipped']);
            $this->components->twoColumnDetail('total_after', (string) $payload['summary']['total_after']);
        });

        return self::SUCCESS;
    }

    private function renderList(DomainManifestRegistryService $registry, DomainCapabilityCatalogService $catalog): int
    {
        $manifests = $registry->all();
        $payload = [
            'ok' => true,
            'action' => 'list',
            'count' => $manifests->count(),
            'domains' => $manifests->map(static fn (AiDomainManifest $m): array => [
                'domain_id' => $m->domain_id,
                'name' => $m->name,
                'status' => $m->status,
                'maturity_stage' => $m->maturity_stage,
                'owner' => $m->owner,
                'capability_count' => $m->capabilities()->count(),
            ])->all(),
        ];
        $this->emit($payload, function () use ($payload): void {
            foreach ($payload['domains'] as $domain) {
                $this->components->twoColumnDetail(
                    (string) $domain['domain_id'],
                    sprintf('stage=%s status=%s capabilities=%s', $domain['maturity_stage'], $domain['status'], $domain['capability_count']),
                );
            }
        });

        return self::SUCCESS;
    }

    private function renderSelect(DomainRuntimeSelectionService $selection): int
    {
        $objective = $this->stringOption('objective');
        if ($objective === null) {
            return $this->failWith('select requires --objective="..."');
        }
        $payload = $selection->select($objective, $this->stringOption('hint'));
        $payload['action'] = 'select';
        $payload['objective'] = $objective;
        $this->emit($payload, function () use ($payload): void {
            $this->components->twoColumnDetail('primary_domain', (string) ($payload['primary_domain'] ?? 'none'));
            $this->components->twoColumnDetail('reason', (string) $payload['reason']);
        });

        return self::SUCCESS;
    }

    private function renderHandoff(DomainHandoffService $handoff): int
    {
        $from = $this->stringOption('from');
        $to = $this->stringOption('to');
        $reason = $this->stringOption('reason');
        if ($from === null || $to === null || $reason === null) {
            return $this->failWith('handoff requires --from=<domain> --to=<domain> --reason="..."');
        }

        $context = $this->decodeJsonOption('context') ?? [
            'summary' => $reason,
            'requested_at' => now()->toISOString(),
        ];
        $expected = $this->decodeJsonOption('expected') ?? ['ack' => true];

        $record = $handoff->emit($from, $to, $reason, [
            'context_pack' => $context,
            'expected_output' => $expected,
            'evidence_refs' => [],
        ]);
        $payload = [
            'ok' => true,
            'action' => 'handoff',
            'handoff' => [
                'id' => $record->id,
                'uuid' => $record->uuid,
                'source_domain_id' => $record->source_domain_id,
                'target_domain_id' => $record->target_domain_id,
                'status' => $record->status,
                'receipt_hash' => $record->receipt_hash,
            ],
        ];
        $this->emit($payload, function () use ($record): void {
            $this->components->twoColumnDetail('handoff', $record->source_domain_id.' -> '.$record->target_domain_id);
            $this->components->twoColumnDetail('receipt_hash', (string) $record->receipt_hash);
        });

        return self::SUCCESS;
    }

    private function renderControlPlane(DomainRuntimeControlPlaneService $controlPlane): int
    {
        $payload = $controlPlane->snapshot();
        $payload['ok'] = true;
        $this->emit($payload, function () use ($payload): void {
            $this->components->twoColumnDetail('manifests', (string) $payload['summary']['manifests']);
            $this->components->twoColumnDetail('capabilities', (string) $payload['summary']['capabilities']);
            $this->components->twoColumnDetail('runtime_records', (string) $payload['summary']['runtime_records']);
            $this->components->twoColumnDetail('handoffs', (string) $payload['summary']['handoffs']);
        });

        return self::SUCCESS;
    }

    private function renderAssessMaturity(DomainManifestRegistryService $registry, DomainMaturityAssessmentService $maturity): int
    {
        $domainId = $this->stringOption('domain');
        $stageOpt = $this->stringOption('stage');
        if ($domainId === null || $stageOpt === null) {
            return $this->failWith('assess-maturity requires --domain=<domain_id> and --stage=<1-5>');
        }
        $manifest = $registry->findByDomainId($domainId)
            ?? throw DomainRuntimeException::unknownDomain($domainId);

        $assessment = $maturity->assess($manifest, (int) $stageOpt);
        $payload = [
            'ok' => $assessment->status === DomainMaturityAssessmentService::STATUS_PASSED,
            'action' => 'assess-maturity',
            'domain_id' => $domainId,
            'target_stage' => (int) $stageOpt,
            'assessment' => [
                'id' => $assessment->id,
                'status' => $assessment->status,
                'assessment_hash' => $assessment->assessment_hash,
                'missing_requirements' => $assessment->missing_requirements,
            ],
        ];
        $this->emit($payload, function () use ($assessment): void {
            $this->components->twoColumnDetail('status', (string) $assessment->status);
            $this->components->twoColumnDetail('assessment_hash', (string) $assessment->assessment_hash);
        });

        return $payload['ok'] ? self::SUCCESS : self::FAILURE;
    }

    private function renderShow(DomainManifestRegistryService $registry, DomainCapabilityCatalogService $catalog): int
    {
        $domainId = $this->stringOption('domain');
        if ($domainId === null) {
            return $this->failWith('show requires --domain=<domain_id>');
        }
        $manifest = $registry->findByDomainId($domainId)
            ?? throw DomainRuntimeException::unknownDomain($domainId);

        $payload = [
            'ok' => true,
            'action' => 'show',
            'manifest' => [
                'id' => $manifest->id,
                'uuid' => $manifest->uuid,
                'domain_id' => $manifest->domain_id,
                'name' => $manifest->name,
                'status' => $manifest->status,
                'maturity_stage' => $manifest->maturity_stage,
                'owner' => $manifest->owner,
                'manifest_hash' => $manifest->manifest_hash,
                'charter' => $manifest->charter,
                'departments' => $manifest->departments,
                'flow_profiles' => $manifest->flow_profiles,
                'tools_allowed' => $manifest->tools_allowed,
                'quality_gates' => $manifest->quality_gates,
                'handoff_rules' => $manifest->handoff_rules,
                'evidence_schema' => $manifest->evidence_schema,
                'forbidden_actions' => $manifest->forbidden_actions,
            ],
            'capabilities' => $catalog->listForDomain($manifest)->map(static fn ($c): array => [
                'capability_id' => $c->capability_id,
                'risk_level' => $c->risk_level,
                'maturity_level' => $c->maturity_level,
                'status' => $c->status,
            ])->all(),
        ];
        $this->emit($payload, function () use ($payload): void {
            $this->components->twoColumnDetail('domain_id', (string) $payload['manifest']['domain_id']);
            $this->components->twoColumnDetail('status', (string) $payload['manifest']['status']);
            $this->components->twoColumnDetail('maturity_stage', (string) $payload['manifest']['maturity_stage']);
        });

        return self::SUCCESS;
    }

    private function renderError(string $error, string $message, ?string $type = null): int
    {
        $payload = ['ok' => false, 'error' => $error, 'message' => $message];
        if ($type !== null) {
            $payload['type'] = $type;
        }
        $this->line($this->encodeOrEmptyObject($payload));

        return self::FAILURE;
    }

    private function failWith(string $message): int
    {
        return $this->renderError('invalid_arguments', $message);
    }

    private function invalidAction(string $action): int
    {
        return $this->failWith("invalid action [{$action}] for atlas:ai:domain-runtime");
    }


    /**
     * @return array<mixed>|null
     */
    private function decodeJsonOption(string $key): ?array
    {
        $value = $this->stringOption($key);
        if ($value === null) {
            return null;
        }
        $decoded = json_decode($value, true);

        return is_array($decoded) ? $decoded : null;
    }

    private function json(): bool
    {
        return (bool) $this->option('json');
    }

    /**
     * @param  array<string,mixed>  $payload
     */
    private function emit(array $payload, callable $human): void
    {
        if ($this->json()) {
            $this->line($this->encodeOrEmptyObject($payload));

            return;
        }
        $human();
    }

}
