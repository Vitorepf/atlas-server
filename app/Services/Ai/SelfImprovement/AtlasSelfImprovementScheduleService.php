<?php

namespace App\Services\Ai\SelfImprovement;

use Carbon\CarbonImmutable;
use App\Support\CanonicalValue;

class AtlasSelfImprovementScheduleService
{
    public function __construct(
        private readonly AtlasSelfImprovementInput $input,
    ) {}

    /**
     * @return array{schema_version:int,status:string,plan_hash:string,plan_hash_algorithm:string,enabled:bool,schedulable:bool,scheduler_registration:array{status:string,registered_command_count:int,skipped_reason:?string},time:string,timezone:string,next_run_at:?string,configured_flows:array<int,string>,invalid_flows:array<int,string>,defaulted:bool,flows:array<int,string>,commands:array<int,array{flow:string,command:string,time:string,cadence:string,week_day:?int,next_run_at:?string}>,count:int,cadence_counts:array<string,int>,emit:bool,health:array{status:string,issues:array<int,string>,actions:array<int,string>}}
     */
    public function schedulePlan(): array
    {
        $configuredFlows = $this->configuredFlows();
        $time = $this->time();
        $timezone = $this->timezone();
        $normalizedFlows = [];
        $invalidFlows = [];

        foreach ($configuredFlows as $configuredFlow) {
            $normalizedFlow = $this->normalizeFlow($configuredFlow);

            if ($normalizedFlow === null) {
                if (trim($configuredFlow) !== '') {
                    $invalidFlows[] = trim($configuredFlow);
                }

                continue;
            }

            $normalizedFlows[] = $normalizedFlow;
        }

        $flows = array_values(array_unique($normalizedFlows));
        $defaulted = false;

        if ($flows === []) {
            $flows = $this->defaultFlows();
            $defaulted = true;
        }

        $commands = collect($flows)
            ->map(fn (string $flow): array => [
                'flow' => $flow,
                'command' => $this->commandForFlow($flow),
                'time' => $time,
                'cadence' => $this->cadenceForFlow($flow),
                'week_day' => $this->weekDayForFlow($flow),
                'next_run_at' => $this->nextRunAtForCommand(
                    time: $time,
                    timezone: $timezone,
                    cadence: $this->cadenceForFlow($flow),
                    weekDay: $this->weekDayForFlow($flow),
                ),
            ])
            ->values()
            ->all();

        $plan = [
            'schema_version' => 1,
            'status' => 'ok',
            'enabled' => (bool) config('atlas_ai.self_improvement.enabled', false),
            'time' => $time,
            'timezone' => $timezone,
            'next_run_at' => $this->nextRunAt($time, $timezone),
            'configured_flows' => $configuredFlows,
            'invalid_flows' => array_values(array_unique($invalidFlows)),
            'defaulted' => $defaulted,
            'flows' => $flows,
            'commands' => $commands,
            'count' => count($commands),
            'cadence_counts' => $this->cadenceCounts($commands),
            'emit' => (bool) config('atlas_ai.self_improvement.emit', false),
        ];

        $plan['health'] = $this->healthForPlan($plan);
        $plan['schedulable'] = $this->isSchedulable($plan);
        $plan['scheduler_registration'] = $this->schedulerRegistrationForPlan($plan);
        $plan['plan_hash_algorithm'] = 'sha256';
        $plan['plan_hash'] = $this->planHash($plan);

        return $plan;
    }

    /**
     * @return array<int,array{flow:string,command:string,time:string,timezone:string,plan_hash:string,cadence:string,week_day:?int,next_run_at:?string}>
     */
    public function scheduledCommands(): array
    {
        $plan = $this->schedulePlan();

        if (! $plan['schedulable']) {
            return [];
        }

        return collect($plan['commands'])
            ->map(fn (array $command): array => [
                'flow' => $command['flow'],
                'command' => $command['command'],
                'time' => $command['time'],
                'timezone' => $plan['timezone'],
                'plan_hash' => $plan['plan_hash'],
                'cadence' => $command['cadence'],
                'week_day' => $command['week_day'],
                'next_run_at' => $command['next_run_at'],
            ])
            ->values()
            ->all();
    }

    /**
     * @return array{schema_version:int,status:string,plan_hash:string,plan_hash_algorithm:string,enabled:bool,schedulable:bool,scheduler_registration:array{status:string,registered_command_count:int,skipped_reason:?string},time:string,timezone:string,next_run_at:?string,health:array{status:string,issues:array<int,string>,actions:array<int,string>},flow_count:int,cadence_counts:array<string,int>,invalid_flow_count:int,defaulted:bool,emit:bool}
     */
    public function scheduleHealth(): array
    {
        $plan = $this->schedulePlan();

        return [
            'schema_version' => 1,
            'status' => 'ok',
            'plan_hash' => $plan['plan_hash'],
            'plan_hash_algorithm' => $plan['plan_hash_algorithm'],
            'enabled' => $plan['enabled'],
            'schedulable' => $plan['schedulable'],
            'scheduler_registration' => $plan['scheduler_registration'],
            'time' => $plan['time'],
            'timezone' => $plan['timezone'],
            'next_run_at' => $plan['next_run_at'],
            'health' => $plan['health'],
            'flow_count' => $plan['count'],
            'cadence_counts' => $plan['cadence_counts'],
            'invalid_flow_count' => count($plan['invalid_flows']),
            'defaulted' => $plan['defaulted'],
            'emit' => $plan['emit'],
        ];
    }

    /**
     * @return array<int,string>
     */
    public function defaultFlows(): array
    {
        return [
            'nightly_review',
            'weekly_architecture_audit',
            'repair_loop_review',
            'kernel_pipeline_review',
            'agent_behavior_review',
            'provider_release_review',
            'voice_realtime_review',
        ];
    }

    /**
     * @return array<int,string>
     */
    private function configuredFlows(): array
    {
        $flows = config('atlas_ai.self_improvement.flows', $this->defaultFlows());

        if (is_string($flows)) {
            $flows = explode(',', $flows);
        }

        if (! is_array($flows)) {
            return $this->defaultFlows();
        }

        return array_values(array_map(
            static fn (string $flow): string => trim($flow),
            array_filter($flows, 'is_string'),
        ));
    }

    private function normalizeFlow(string $flow): ?string
    {
        $flow = trim($flow);
        if ($flow === '') {
            return null;
        }

        if (str_starts_with($flow, 'self_improvement.')) {
            $flow = substr($flow, strlen('self_improvement.'));
        }

        return in_array('self_improvement.'.$flow, AtlasSelfImprovementOrchestrator::SUPPORTED_FLOWS, true)
            ? $flow
            : null;
    }

    private function commandForFlow(string $flow): string
    {
        $command = 'atlas:ai:self-improve'
            .' --flow='.$flow
            .' --hours='.$this->hours()
            .' --limit='.$this->limit()
            .' --json';

        if ((bool) config('atlas_ai.self_improvement.emit', false)) {
            $command .= ' --emit';
        }

        return $command;
    }

    private function cadenceForFlow(string $flow): string
    {
        return $flow === 'weekly_architecture_audit' ? 'weekly' : 'daily';
    }

    private function weekDayForFlow(string $flow): ?int
    {
        return $this->cadenceForFlow($flow) === 'weekly' ? 1 : null;
    }

    /**
     * @param  array<int,array{cadence:string}>  $commands
     * @return array<string,int>
     */
    private function cadenceCounts(array $commands): array
    {
        return collect($commands)
            ->countBy(fn (array $command): string => $command['cadence'])
            ->all();
    }

    private function hours(): int
    {
        return $this->input->reviewWindowHours(config('atlas_ai.self_improvement.hours', AtlasSelfImprovementRuntime::DEFAULT_REVIEW_WINDOW_HOURS));
    }

    private function limit(): int
    {
        return $this->input->findingsLimit(config('atlas_ai.self_improvement.limit', AtlasSelfImprovementInput::DEFAULT_FINDINGS_LIMIT));
    }

    /**
     * @param  array{enabled:bool,time:string,timezone:string,count:int}  $plan
     */
    private function isSchedulable(array $plan): bool
    {
        return $plan['enabled']
            && $plan['count'] > 0
            && $this->isValidTime($plan['time'])
            && $this->isValidTimezone($plan['timezone']);
    }

    /**
     * @param  array{enabled:bool,schedulable:bool,time:string,timezone:string,count:int,health:array{issues:array<int,string>}}  $plan
     * @return array{status:string,registered_command_count:int,skipped_reason:?string}
     */
    private function schedulerRegistrationForPlan(array $plan): array
    {
        if ($plan['schedulable']) {
            return [
                'status' => 'registered',
                'registered_command_count' => $plan['count'],
                'skipped_reason' => null,
            ];
        }

        $issues = $plan['health']['issues'];

        return [
            'status' => 'skipped',
            'registered_command_count' => 0,
            'skipped_reason' => $issues[0] ?? 'not_schedulable',
        ];
    }

    /**
     * @param  array<string,mixed>  $plan
     */
    private function planHash(array $plan): string
    {
        return hash('sha256', json_encode(CanonicalValue::canonicalize([
            'schema_version' => $plan['schema_version'] ?? null,
            'enabled' => $plan['enabled'] ?? null,
            'schedulable' => $plan['schedulable'] ?? null,
            'scheduler_registration' => $plan['scheduler_registration'] ?? null,
            'time' => $plan['time'] ?? null,
            'timezone' => $plan['timezone'] ?? null,
            'configured_flows' => $plan['configured_flows'] ?? [],
            'invalid_flows' => $plan['invalid_flows'] ?? [],
            'defaulted' => $plan['defaulted'] ?? false,
            'flows' => $plan['flows'] ?? [],
            'commands' => $this->hashableCommands((array) ($plan['commands'] ?? [])),
            'count' => $plan['count'] ?? 0,
            'cadence_counts' => $plan['cadence_counts'] ?? [],
            'emit' => $plan['emit'] ?? false,
            'health' => [
                'status' => data_get($plan, 'health.status'),
                'issues' => data_get($plan, 'health.issues', []),
            ],
        ]), JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE | JSON_THROW_ON_ERROR));
    }


    /**
     * @param  array<int,array<string,mixed>>  $commands
     * @return array<int,array<string,mixed>>
     */
    private function hashableCommands(array $commands): array
    {
        return array_map(static function (array $command): array {
            unset($command['next_run_at']);

            return $command;
        }, $commands);
    }

    private function time(): string
    {
        return trim((string) config('atlas_ai.self_improvement.time', '02:00'));
    }

    private function timezone(): string
    {
        return trim((string) config('app.timezone', 'UTC'));
    }

    private function isValidTime(string $time): bool
    {
        return preg_match('/^(?:[01]\d|2[0-3]):[0-5]\d$/', $time) === 1;
    }

    private function isValidTimezone(string $timezone): bool
    {
        return in_array($timezone, timezone_identifiers_list(), true);
    }

    private function nextRunAt(string $time, string $timezone): ?string
    {
        return $this->nextRunAtForCommand($time, $timezone, 'daily', null);
    }

    private function nextRunAtForCommand(string $time, string $timezone, string $cadence, ?int $weekDay): ?string
    {
        if (! $this->isValidTime($time) || ! $this->isValidTimezone($timezone)) {
            return null;
        }

        [$hour, $minute] = array_map('intval', explode(':', $time));
        $now = CarbonImmutable::now($timezone);
        $next = $now->setTime($hour, $minute);

        if ($cadence === 'weekly') {
            $targetWeekDay = max(0, min(6, (int) ($weekDay ?? 1)));

            while ((int) $next->dayOfWeek !== $targetWeekDay || $next->lessThanOrEqualTo($now)) {
                $next = $next->addDay();
            }

            return $next->toJSON();
        }

        if ($next->lessThanOrEqualTo($now)) {
            $next = $next->addDay();
        }

        return $next->toJSON();
    }

    /**
     * @param  array{enabled:bool,time:string,invalid_flows:array<int,string>,defaulted:bool,count:int,emit:bool}  $plan
     * @return array{status:string,issues:array<int,string>,actions:array<int,string>}
     */
    private function healthForPlan(array $plan): array
    {
        $issues = [];
        $actions = [];

        if (! $plan['enabled']) {
            $issues[] = 'self_improvement_schedule_disabled';
            $actions[] = 'Enable atlas_ai.self_improvement.enabled when recurring curator reviews should run.';
        }

        if ($plan['invalid_flows'] !== []) {
            $issues[] = 'invalid_self_improvement_flows_configured';
            $actions[] = 'Remove or correct invalid flows: '.implode(', ', $plan['invalid_flows']).'.';
        }

        if (! $this->isValidTime($plan['time'])) {
            $issues[] = 'invalid_self_improvement_schedule_time';
            $actions[] = 'Set atlas_ai.self_improvement.time to HH:MM between 00:00 and 23:59.';
        }

        if (! $this->isValidTimezone($plan['timezone'])) {
            $issues[] = 'invalid_self_improvement_schedule_timezone';
            $actions[] = 'Set app.timezone to a valid IANA timezone such as UTC or America/Sao_Paulo.';
        }

        if ($plan['defaulted']) {
            $issues[] = 'self_improvement_schedule_defaulted';
            $actions[] = 'Set ATLAS_AI_SELF_IMPROVEMENT_FLOWS to at least one supported self_improvement flow.';
        }

        if ($plan['count'] < 1) {
            $issues[] = 'self_improvement_schedule_empty';
            $actions[] = 'Restore the default nightly_review, weekly_architecture_audit, repair_loop_review, kernel_pipeline_review, agent_behavior_review, provider_release_review, and voice_realtime_review schedule.';
        }

        if ($issues === []) {
            return [
                'status' => 'healthy',
                'issues' => [],
                'actions' => [],
            ];
        }

        if (! $plan['enabled'] && count($issues) === 1) {
            return [
                'status' => 'disabled',
                'issues' => $issues,
                'actions' => $actions,
            ];
        }

        return [
            'status' => 'warning',
            'issues' => $issues,
            'actions' => $actions,
        ];
    }
}
