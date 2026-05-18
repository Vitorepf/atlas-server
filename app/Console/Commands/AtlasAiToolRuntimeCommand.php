<?php

namespace App\Console\Commands;

use App\Models\AiToolDefinition;
use App\Services\Ai\ToolRuntime\ToolCapabilityCatalogService;
use App\Services\Ai\ToolRuntime\ToolDefinitionRegistryService;
use App\Services\Ai\ToolRuntime\ToolHealthService;
use App\Services\Ai\ToolRuntime\ToolInvocationService;
use App\Services\Ai\ToolRuntime\ToolPlanningService;
use App\Services\Ai\ToolRuntime\ToolReceiptService;
use App\Services\Ai\ToolRuntime\ToolRuntimeControlPlaneService;
use App\Services\Ai\ToolRuntime\ToolRuntimeException;
use App\Services\Ai\ToolRuntime\ToolRuntimeReadinessService;
use App\Services\Ai\ToolRuntime\ToolSeedDefinitions;
use App\Services\Ai\ToolRuntime\ToolValidationService;
use Illuminate\Console\Command;
use Throwable;

class AtlasAiToolRuntimeCommand extends Command
{
    protected $signature = 'atlas:ai:tool-runtime
        {positional? : Optional positional action (alternative to --action)}
        {--action=readiness : readiness, seed-defaults, list, plan, doctor, smoke, control-plane, validate, show}
        {--objective= : Objective text for plan/smoke}
        {--tool= : tool_id for show/validate/invoke}
        {--type=schema : Validation type for validate (schema/smoke/fixture)}
        {--json : Machine-readable JSON output}';

    protected $description = 'Atlas Tool Runtime: registry, capability catalog, planning, policy bridge, invocation, receipts, health and validation.';

    public function handle(
        ToolDefinitionRegistryService $registry,
        ToolCapabilityCatalogService $catalog,
        ToolPlanningService $planning,
        ToolInvocationService $invocations,
        ToolHealthService $health,
        ToolValidationService $validation,
        ToolReceiptService $receipts,
        ToolRuntimeReadinessService $readiness,
        ToolRuntimeControlPlaneService $controlPlane,
    ): int {
        $positional = $this->argument('positional');
        $action = is_string($positional) && trim($positional) !== ''
            ? trim($positional)
            : (string) $this->option('action');

        try {
            return match ($action) {
                'readiness' => $this->renderReadiness($readiness),
                'seed-defaults' => $this->renderSeedDefaults($registry),
                'list' => $this->renderList($registry),
                'plan' => $this->renderPlan($planning),
                'doctor' => $this->renderDoctor($health),
                'smoke' => $this->renderSmoke($registry, $planning, $invocations, $health, $validation, $controlPlane),
                'control-plane' => $this->renderControlPlane($controlPlane),
                'validate' => $this->renderValidate($registry, $validation),
                'show' => $this->renderShow($registry, $catalog),
                default => $this->invalidAction($action),
            };
        } catch (ToolRuntimeException $e) {
            return $this->renderError('tool_runtime_exception', $e->getMessage());
        } catch (\InvalidArgumentException $e) {
            return $this->renderError('invalid_argument', $e->getMessage());
        } catch (Throwable $e) {
            return $this->renderError('exception', $e->getMessage(), $e::class);
        }
    }

    private function renderReadiness(ToolRuntimeReadinessService $readiness): int
    {
        $payload = $readiness->report();
        $this->emit($payload, function () use ($payload): void {
            $this->components->twoColumnDetail('schema', (string) $payload['schema']);
            $this->components->twoColumnDetail('ok', $payload['ok'] ? 'true' : 'false');
            $this->components->twoColumnDetail('passed', (string) $payload['summary']['passed']);
            $this->components->twoColumnDetail('failed', (string) $payload['summary']['failed']);
        });

        return $payload['ok'] ? self::SUCCESS : self::FAILURE;
    }

    private function renderSeedDefaults(ToolDefinitionRegistryService $registry): int
    {
        $payload = $registry->seedDefaults(ToolSeedDefinitions::all());
        $this->emit($payload, function () use ($payload): void {
            $this->components->twoColumnDetail('created', (string) $payload['summary']['created']);
            $this->components->twoColumnDetail('skipped', (string) $payload['summary']['skipped']);
            $this->components->twoColumnDetail('total_after', (string) $payload['summary']['total_after']);
            $this->components->twoColumnDetail('registry_hash', substr((string) ($payload['registry_hash'] ?? ''), 0, 16));
        });

        return self::SUCCESS;
    }

    private function renderList(ToolDefinitionRegistryService $registry): int
    {
        $tools = $registry->all();
        $payload = [
            'ok' => true,
            'action' => 'list',
            'count' => $tools->count(),
            'tools' => $tools->map(static fn (AiToolDefinition $t): array => [
                'tool_id' => $t->tool_id,
                'name' => $t->name,
                'tool_type' => $t->tool_type,
                'authority_group' => $t->authority_group,
                'risk_level' => $t->risk_level,
                'status' => $t->status,
                'health_status' => $t->health_status,
                'capability_count' => $t->capabilities()->count(),
            ])->all(),
        ];
        $this->emit($payload, function () use ($payload): void {
            foreach ($payload['tools'] as $tool) {
                $this->components->twoColumnDetail(
                    (string) $tool['tool_id'],
                    sprintf('authority=%s risk=%s health=%s', $tool['authority_group'], $tool['risk_level'], $tool['health_status']),
                );
            }
        });

        return self::SUCCESS;
    }

    private function renderPlan(ToolPlanningService $planning): int
    {
        $objective = $this->stringOption('objective');
        if ($objective === null) {
            return $this->failWith('plan requires --objective="..."');
        }
        $plan = $planning->plan($objective);
        $payload = [
            'ok' => true,
            'action' => 'plan',
            'objective' => $objective,
            'plan' => [
                'id' => $plan->id,
                'uuid' => $plan->uuid,
                'status' => $plan->status,
                'tools_selected' => $plan->tools_selected,
                'rejected_tools' => $plan->rejected_tools,
                'safety_notes' => $plan->safety_notes,
                'receipt_hash' => $plan->receipt_hash,
            ],
        ];
        $this->emit($payload, function () use ($plan): void {
            $this->components->twoColumnDetail('status', (string) $plan->status);
            $this->components->twoColumnDetail('selected', (string) count((array) $plan->tools_selected));
            $this->components->twoColumnDetail('rejected', (string) count((array) $plan->rejected_tools));
        });

        return self::SUCCESS;
    }

    private function renderDoctor(ToolHealthService $health): int
    {
        $payload = $health->doctor();
        $payload['action'] = 'doctor';
        $this->emit($payload, function () use ($payload): void {
            foreach (['healthy', 'degraded', 'failed', 'unknown'] as $key) {
                $this->components->twoColumnDetail($key, (string) ($payload['summary'][$key] ?? 0));
            }
        });

        return $payload['ok'] ? self::SUCCESS : self::FAILURE;
    }

    private function renderSmoke(
        ToolDefinitionRegistryService $registry,
        ToolPlanningService $planning,
        ToolInvocationService $invocations,
        ToolHealthService $health,
        ToolValidationService $validation,
        ToolRuntimeControlPlaneService $controlPlane,
    ): int {
        $registry->seedDefaults(ToolSeedDefinitions::all());
        $objective = $this->stringOption('objective') ?? 'pesquisar docs canonicos atlas para mission foundation';
        $plan = $planning->plan($objective);
        $invocationsReport = [];
        foreach ((array) $plan->tools_selected as $selected) {
            $tool = $registry->findByToolId((string) $selected['tool_id']);
            if ($tool === null) {
                continue;
            }
            $invocation = $invocations->invoke($tool, ['query' => $objective], ['source' => 'meta_5_smoke']);
            $invocationsReport[] = [
                'tool_id' => $tool->tool_id,
                'invocation_status' => $invocation->invocation_status,
                'input_hash' => $invocation->input_hash,
                'output_hash' => $invocation->output_hash,
            ];
        }

        $doctor = $health->doctor();
        $schemaValidations = [];
        foreach (AiToolDefinition::query()->limit(3)->get() as $tool) {
            $run = $validation->validate($tool, 'schema');
            $schemaValidations[] = [
                'tool_id' => $tool->tool_id,
                'status' => $run->status,
                'validation_hash' => $run->validation_hash,
            ];
        }

        $payload = [
            'ok' => $doctor['ok'] && $plan->status !== 'no_match',
            'action' => 'smoke',
            'plan' => [
                'id' => $plan->id,
                'objective' => $plan->objective,
                'status' => $plan->status,
                'tools_selected' => array_column((array) $plan->tools_selected, 'tool_id'),
                'rejected_tools' => array_column((array) $plan->rejected_tools, 'tool_id'),
            ],
            'invocations' => $invocationsReport,
            'doctor' => $doctor['summary'],
            'validations' => $schemaValidations,
            'snapshot_summary' => $controlPlane->snapshot()['summary'],
        ];
        $this->emit($payload, function () use ($payload): void {
            $this->components->twoColumnDetail('plan_status', (string) $payload['plan']['status']);
            $this->components->twoColumnDetail('invocations', (string) count($payload['invocations']));
            $this->components->twoColumnDetail('doctor_healthy', (string) ($payload['doctor']['healthy'] ?? 0));
        });

        return $payload['ok'] ? self::SUCCESS : self::FAILURE;
    }

    private function renderControlPlane(ToolRuntimeControlPlaneService $controlPlane): int
    {
        $payload = $controlPlane->snapshot();
        $payload['ok'] = true;
        $this->emit($payload, function () use ($payload): void {
            foreach ($payload['summary'] as $key => $value) {
                $this->components->twoColumnDetail((string) $key, (string) $value);
            }
            foreach ($payload['bridges'] as $key => $value) {
                $this->components->twoColumnDetail('bridge:'.$key, $value ? 'true' : 'false');
            }
        });

        return self::SUCCESS;
    }

    private function renderValidate(ToolDefinitionRegistryService $registry, ToolValidationService $validation): int
    {
        $toolId = $this->stringOption('tool');
        if ($toolId === null) {
            return $this->failWith('validate requires --tool=<tool_id>');
        }
        $tool = $registry->findByToolId($toolId) ?? throw ToolRuntimeException::unknownTool($toolId);
        $type = $this->stringOption('type') ?? 'schema';
        $run = $validation->validate($tool, $type);
        $payload = [
            'ok' => $run->status === 'passed',
            'action' => 'validate',
            'validation' => [
                'id' => $run->id,
                'tool_id' => $tool->tool_id,
                'validation_type' => $run->validation_type,
                'status' => $run->status,
                'validation_hash' => $run->validation_hash,
            ],
        ];
        $this->emit($payload, function () use ($payload): void {
            $this->components->twoColumnDetail('status', (string) $payload['validation']['status']);
            $this->components->twoColumnDetail('validation_hash', (string) $payload['validation']['validation_hash']);
        });

        return $payload['ok'] ? self::SUCCESS : self::FAILURE;
    }

    private function renderShow(ToolDefinitionRegistryService $registry, ToolCapabilityCatalogService $catalog): int
    {
        $toolId = $this->stringOption('tool');
        if ($toolId === null) {
            return $this->failWith('show requires --tool=<tool_id>');
        }
        $tool = $registry->findByToolId($toolId) ?? throw ToolRuntimeException::unknownTool($toolId);

        $payload = [
            'ok' => true,
            'action' => 'show',
            'tool' => [
                'tool_id' => $tool->tool_id,
                'name' => $tool->name,
                'description' => $tool->description,
                'tool_type' => $tool->tool_type,
                'authority_group' => $tool->authority_group,
                'risk_level' => $tool->risk_level,
                'status' => $tool->status,
                'health_status' => $tool->health_status,
                'input_schema' => $tool->input_schema,
                'output_schema' => $tool->output_schema,
                'side_effects' => $tool->side_effects,
                'evidence_emitted' => $tool->evidence_emitted,
            ],
            'capabilities' => $catalog->listForTool($tool)->map(static fn ($c): array => [
                'capability_id' => $c->capability_id,
                'name' => $c->name,
                'maturity_level' => $c->maturity_level,
                'required_policy_gates' => $c->required_policy_gates,
                'required_evidence' => $c->required_evidence,
            ])->all(),
        ];
        $this->emit($payload, function () use ($payload): void {
            $this->components->twoColumnDetail('tool_id', (string) $payload['tool']['tool_id']);
            $this->components->twoColumnDetail('authority_group', (string) $payload['tool']['authority_group']);
            $this->components->twoColumnDetail('capabilities', (string) count($payload['capabilities']));
        });

        return self::SUCCESS;
    }

    private function renderError(string $error, string $message, ?string $type = null): int
    {
        $payload = ['ok' => false, 'error' => $error, 'message' => $message];
        if ($type !== null) {
            $payload['type'] = $type;
        }
        $this->line($this->encode($payload));

        return self::FAILURE;
    }

    private function failWith(string $message): int
    {
        return $this->renderError('invalid_arguments', $message);
    }

    private function invalidAction(string $action): int
    {
        return $this->failWith("invalid action [{$action}] for atlas:ai:tool-runtime");
    }

    private function stringOption(string $key): ?string
    {
        $value = $this->option($key);

        return is_string($value) && trim($value) !== '' ? trim($value) : null;
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
            $this->line($this->encode($payload));

            return;
        }
        $human();
    }

    /**
     * @param  array<string,mixed>  $payload
     */
    private function encode(array $payload): string
    {
        return json_encode($payload, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE) ?: '{}';
    }
}
