<?php

namespace App\Console\Commands;

use App\Models\AiScheduledTask;
use App\Services\Ai\Scheduling\LongRunningWorkReadModel;
use App\Services\Ai\Scheduling\ScheduleParser;
use Illuminate\Console\Command;
use Illuminate\Support\Collection;
use App\Services\Ai\Support\DatabaseTableAvailability;
use App\Support\YesNo;
use App\Console\Concerns\EmitsCanonicalJson;

class AtlasAiLongRunningWorkDeclareBaselineCommand extends Command
{
    use EmitsCanonicalJson;

    protected $signature = 'atlas:ai:long-running-work-declare-baseline
        {--apply : Persist disabled baseline schedule declarations}
        {--workspace= : Workspace path used only for a hash in the baseline contract}
        {--json : Print machine-readable JSON}';

    protected $description = 'Declare disabled governed Structure Mother long-running work baseline schedules without dispatching jobs.';

    private const SCHEDULE = 'every 1d';

    public function handle(ScheduleParser $parser, LongRunningWorkReadModel $readModel): int
    {
        if (! DatabaseTableAvailability::has('ai_scheduled_tasks')) {
            return $this->render([
                'ok' => false,
                'status' => 'storage_unavailable',
                'error' => 'Tabela ai_scheduled_tasks ainda nao existe. Rode migrations.',
            ], self::FAILURE);
        }

        $parsed = $parser->parse(self::SCHEDULE);
        $workspace = $this->workspace();
        $families = $readModel->baselineFamilies();
        $contract = $this->baselineContract($workspace, $families);
        $existing = $this->existingTasks($families);

        $payload = [
            'ok' => true,
            'status' => (bool) $this->option('apply') ? 'applied' : 'dry_run',
            'writes' => (bool) $this->option('apply'),
            'dispatches_jobs' => false,
            'schedule_mutation_allowed_by_report' => false,
            'baseline_schedule' => [
                'family_count' => count($families),
                'schedule' => self::SCHEDULE,
                'kind' => (string) $parsed['kind'],
                'enabled' => false,
                'next_run_at' => null,
                'target_platform' => 'local',
                'workspace_hash' => hash('sha256', realpath($workspace) ?: $workspace),
                'contract_hash' => hash('sha256', json_encode($contract, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE) ?: ''),
                'families' => $families,
            ],
            'existing_task_count' => $existing->count(),
            'scheduled_tasks' => [],
        ];

        if (! (bool) $this->option('apply')) {
            return $this->render($payload);
        }

        $payload['scheduled_tasks'] = collect($families)
            ->map(fn (string $family): array => $this->taskPayload($this->upsertTask(
                task: $existing->get($family),
                parsed: $parsed,
                contract: $contract,
                family: $family,
            )))
            ->values()
            ->all();

        return $this->render($payload);
    }

    /**
     * @param  array<int,string>  $families
     * @return Collection<string,AiScheduledTask>
     */
    private function existingTasks(array $families)
    {
        return AiScheduledTask::query()
            ->whereIn('title', collect($families)->map(fn (string $family): string => $this->title($family))->all())
            ->where('schedule', self::SCHEDULE)
            ->get()
            ->keyBy(fn (AiScheduledTask $task): string => (string) data_get($task->metadata, 'baseline_schedule_declaration.family', $this->familyFromTitle($task->title)));
    }

    /**
     * @param  array<string,mixed>  $parsed
     * @param  array<string,mixed>  $contract
     */
    private function upsertTask(?AiScheduledTask $task, array $parsed, array $contract, string $family): AiScheduledTask
    {
        $declaration = $this->baselineScheduleDeclaration($family, $contract);
        $attributes = [
            'title' => $this->title($family),
            'prompt' => $this->prompt($family),
            'schedule' => self::SCHEDULE,
            'kind' => (string) $parsed['kind'],
            'skill_ids' => [],
            'target_platform' => 'local',
            'target_device_id' => null,
            'workspace' => null,
            'enabled' => false,
            'next_run_at' => null,
            'repeat_remaining' => null,
            'context_from_task_ids' => [],
            'wrap_response' => true,
            'metadata' => [
                'schema_version' => 'atlas.long_running_work.baseline_schedule.v1',
                'created_by' => 'atlas_structure_mother_baseline',
                'declared_at' => now()->toJSON(),
                'baseline_schedule_declaration' => $declaration,
                'schedule_parsed' => [
                    'kind' => (string) $parsed['kind'],
                    'schedule' => self::SCHEDULE,
                    'interval_minutes' => (int) ($parsed['interval_minutes'] ?? 1440),
                    'next_run_at' => null,
                ],
                'baseline_contract' => $contract,
                'baseline_contract_hash' => hash('sha256', json_encode($contract, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE) ?: ''),
                'dispatch_authorized_by_declaration' => false,
                'schedule_mutation_authorized_by_report' => false,
                'operator_review_required_for_escalation' => true,
                'raw_prompt_contains_secret' => false,
            ],
        ];

        if ($task === null) {
            return AiScheduledTask::query()->create($attributes);
        }

        $task->update([
            ...$attributes,
            'metadata' => array_merge($task->metadata ?? [], $attributes['metadata'], [
                'redeclaration_count' => (int) data_get($task->metadata, 'redeclaration_count', 0) + 1,
            ]),
        ]);

        return $task->refresh();
    }

    private function workspace(): string
    {
        $workspace = $this->option('workspace');
        if (is_string($workspace) && trim($workspace) !== '') {
            return trim($workspace);
        }

        return (string) config('atlas.ai.workdir', dirname(base_path()));
    }

    private function title(string $family): string
    {
        return 'Structure Mother baseline review: '.str_replace('_', ' ', $family);
    }

    private function familyFromTitle(string $title): string
    {
        return str_replace(' ', '_', str_replace('Structure Mother baseline review: ', '', $title));
    }

    private function prompt(string $family): string
    {
        return <<<TXT
Review the Atlas AI Structure Mother long-running work baseline using read-only evidence.

Family: {$family}

Do not dispatch tools, mutate schedules, promote memory, change providers, send notifications, or resolve critical inbox items. Produce a concise evidence summary and stop.
TXT;
    }

    /**
     * @param  array<int,string>  $families
     * @return array<string,mixed>
     */
    private function baselineContract(string $workspace, array $families): array
    {
        return [
            'schema_version' => 'atlas.long_running_work.baseline_schedule_contract.v1',
            'purpose' => 'structure_mother_long_running_work_foundation',
            'minimum_schedule_families' => $families,
            'execution_authority' => [
                'declaration_dispatches_jobs' => false,
                'report_dispatch_allowed' => false,
                'schedule_mutation_allowed_by_report' => false,
                'autonomy_level_ceiling' => 'low',
                'operator_review_required_for_escalation' => true,
                'recursive_schedule_execution_allowed' => false,
                'autonomous_followup_allowed' => false,
            ],
            'workspace_hash' => hash('sha256', realpath($workspace) ?: $workspace),
            'raw_workspace_path_exposed_in_report' => false,
            'raw_output_in_metadata' => false,
            'provider_change_allowed' => false,
            'notification_delivery_allowed' => false,
        ];
    }

    /**
     * @param  array<string,mixed>  $contract
     * @return array<string,mixed>
     */
    private function baselineScheduleDeclaration(string $family, array $contract): array
    {
        $declaration = [
            'schema_version' => 'atlas.long_running_work.baseline_schedule_declaration.v1',
            'family' => $family,
            'schedule' => self::SCHEDULE,
            'dispatch_allowed' => false,
            'requires_operator_enablement' => true,
            'operator_review_required_for_escalation' => true,
            'contract_hash' => hash('sha256', json_encode($contract, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE) ?: ''),
        ];

        return [
            ...$declaration,
            'declaration_hash' => hash('sha256', json_encode($declaration, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE) ?: ''),
        ];
    }

    /**
     * @return array<string,mixed>
     */
    private function taskPayload(AiScheduledTask $task): array
    {
        return [
            'id' => $task->id,
            'title' => $task->title,
            'schedule' => $task->schedule,
            'kind' => $task->kind,
            'enabled' => (bool) $task->enabled,
            'next_run_at' => $task->next_run_at?->toJSON(),
            'target_platform' => $task->target_platform,
            'metadata_schema_version' => data_get($task->metadata, 'schema_version'),
            'baseline_family' => data_get($task->metadata, 'baseline_schedule_declaration.family'),
            'baseline_contract_hash' => data_get($task->metadata, 'baseline_contract_hash'),
            'dispatch_authorized_by_declaration' => data_get($task->metadata, 'dispatch_authorized_by_declaration') === true,
            'requires_operator_enablement' => data_get($task->metadata, 'baseline_schedule_declaration.requires_operator_enablement') === true,
            'operator_review_required_for_escalation' => data_get($task->metadata, 'operator_review_required_for_escalation') === true,
        ];
    }

    /**
     * @param  array<string,mixed>  $payload
     */
    private function render(array $payload, int $exitCode = self::SUCCESS): int
    {
        if ((bool) $this->option('json')) {
            $this->line($this->encode($payload));

            return $exitCode;
        }

        if (($payload['ok'] ?? false) === false) {
            $this->error((string) ($payload['error'] ?? 'Erro desconhecido.'));

            return $exitCode;
        }

        $this->components->twoColumnDetail('<fg=bright-blue;options=bold>Atlas Long-Running Baseline</>', (string) ($payload['status'] ?? 'unknown'));
        $this->components->twoColumnDetail('Writes', YesNo::format($payload['writes'] ?? false));
        $this->components->twoColumnDetail('Dispatches jobs', YesNo::format($payload['dispatches_jobs'] ?? false));
        $this->components->twoColumnDetail('Enabled', data_getYesNo::format($payload, 'baseline_schedule.enabled'));
        $this->components->twoColumnDetail('Schedule', (string) data_get($payload, 'baseline_schedule.schedule', self::SCHEDULE));

        return $exitCode;
    }
}
