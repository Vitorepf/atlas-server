<?php

declare(strict_types=1);

namespace App\Console\Commands;

use App\Console\Commands\Concerns\ReadsNonEmptyStringOption;
use App\Console\Concerns\EmitsCanonicalJson;
use App\Models\AtlasProject;
use App\Services\Ai\Programming\AtlasForgeProviderCapacityService;
use App\Services\Ai\Programming\AtlasForgeProviderFailureMemoryService;
use App\Services\Ai\Programming\AtlasForgeProviderFallbackPolicyService;
use Illuminate\Console\Command;
use Illuminate\Support\Str;
use InvalidArgumentException;
use Throwable;

/**
 * Atlas Forge Provider Failure Record CLI.
 *
 * Records a governed failure event into the failure memory of an Obra. Used
 * by operators (and tests) to seed the cooldown/quota/auth signals that
 * Provider Topology + Continuum Certification consume.
 *
 * Hard rules:
 *   - NEVER calls an external provider;
 *   - NEVER spends a token;
 *   - Fails closed without --obra in --strict;
 *   - Rejects unknown failure types;
 *   - Cooldown comes from the canonical policy table.
 *
 * Doc: docs/engineering-knowledge-base/atlas-forge-provider-capacity-continuity-v1.md
 */
final class AtlasForgeProviderFailureRecordCommand extends Command
{
    use ReadsNonEmptyStringOption;

    use EmitsCanonicalJson;

    protected $signature = 'atlas:forge:provider-failure-record
        {--obra= : UUID de Obra (obrigatorio em --strict)}
        {--provider= : Provider runtime canonico: claude_cli|codex_cli|gemini_cli|claude_codex|atlas-local}
        {--model= : Modelo opcional para auditoria (ex: claude-opus-4-7)}
        {--role= : Papel canonico opcional (primary_builder, critical_reviewer, ...)}
        {--failure= : Tipo de falha canonico (rate_limit|quota_exhausted|auth_failed|timeout|context_limit|model_unavailable|provider_error|insufficient_capability|provider_capacity_exhausted)}
        {--reason= : Motivo legivel opcional}
        {--json : Emite JSON canonico do evento + memory + capacity}
        {--strict : Exit non-zero quando falta obra/provider/failure ou failure invalida}';

    protected $description = 'Registra um evento de falha de provider em uma Obra (failure memory canonica). Read-only contra provider externo.';

    public function handle(
        AtlasForgeProviderFailureMemoryService $memory,
        AtlasForgeProviderCapacityService $capacity,
    ): int {
        $obraId = $this->stringOption('obra');
        $provider = $this->stringOption('provider');
        $failureType = $this->stringOption('failure');
        $strict = (bool) $this->option('strict');

        if ($obraId === null) {
            return $this->emit([
                'schema_version' => AtlasForgeProviderFailureMemoryService::EVENT_SCHEMA_VERSION,
                'status' => 'blocked',
                'blocker' => 'obra_required',
                'next_action' => 'provide_obra_id',
                'external_provider_call' => false,
            ], $strict ? self::FAILURE : self::SUCCESS);
        }

        if (! Str::isUuid($obraId)) {
            return $this->emit([
                'schema_version' => AtlasForgeProviderFailureMemoryService::EVENT_SCHEMA_VERSION,
                'status' => 'blocked',
                'blocker' => 'obra_invalid_uuid',
                'next_action' => 'provide_existing_obra_id',
                'external_provider_call' => false,
            ], $strict ? self::FAILURE : self::SUCCESS);
        }

        try {
            $project = AtlasProject::query()->whereKey($obraId)->first();
        } catch (Throwable $e) {
            return $this->emit([
                'schema_version' => AtlasForgeProviderFailureMemoryService::EVENT_SCHEMA_VERSION,
                'status' => 'blocked',
                'blocker' => 'obra_lookup_failed',
                'reason' => $e->getMessage(),
                'external_provider_call' => false,
            ], self::FAILURE);
        }

        if ($project === null) {
            return $this->emit([
                'schema_version' => AtlasForgeProviderFailureMemoryService::EVENT_SCHEMA_VERSION,
                'status' => 'blocked',
                'blocker' => 'obra_not_found',
                'next_action' => 'provide_existing_obra_id',
                'external_provider_call' => false,
            ], $strict ? self::FAILURE : self::SUCCESS);
        }

        if ($provider === null) {
            return $this->emit([
                'schema_version' => AtlasForgeProviderFailureMemoryService::EVENT_SCHEMA_VERSION,
                'status' => 'blocked',
                'blocker' => 'provider_required',
                'external_provider_call' => false,
            ], $strict ? self::FAILURE : self::SUCCESS);
        }

        if ($failureType === null
            || ! in_array($failureType, AtlasForgeProviderFallbackPolicyService::KNOWN_FAILURES, true)
        ) {
            return $this->emit([
                'schema_version' => AtlasForgeProviderFailureMemoryService::EVENT_SCHEMA_VERSION,
                'status' => 'blocked',
                'blocker' => 'unknown_failure_type',
                'failure_provided' => $failureType,
                'known_failures' => AtlasForgeProviderFallbackPolicyService::KNOWN_FAILURES,
                'external_provider_call' => false,
            ], $strict ? self::FAILURE : self::SUCCESS);
        }

        try {
            $event = $memory->record($project, [
                'provider' => $provider,
                'model' => $this->stringOption('model'),
                'role' => $this->stringOption('role'),
                'failure_type' => $failureType,
                'reason' => $this->stringOption('reason'),
            ]);
        } catch (InvalidArgumentException $e) {
            return $this->emit([
                'schema_version' => AtlasForgeProviderFailureMemoryService::EVENT_SCHEMA_VERSION,
                'status' => 'blocked',
                'blocker' => $e->getMessage(),
                'external_provider_call' => false,
            ], $strict ? self::FAILURE : self::SUCCESS);
        }

        $project->refresh();
        $updatedCapacity = $capacity->snapshot(['obra_id' => $obraId]);
        $updatedMemory = $memory->snapshot($project);

        return $this->emit([
            'schema_version' => AtlasForgeProviderFailureMemoryService::EVENT_SCHEMA_VERSION,
            'status' => 'recorded',
            'event' => $event,
            'capacity_snapshot' => $updatedCapacity,
            'failure_memory' => $updatedMemory,
            'external_provider_call' => false,
            'separated_from' => 'external_rivals_certification',
        ], self::SUCCESS);
    }

    /**
     * @param  array<string,mixed>  $payload
     */
    private function emit(array $payload, int $exitCode): int
    {
        if ((bool) $this->option('json')) {
            $this->line($this->encode($payload));
        } else {
            $this->renderHuman($payload);
        }

        return $exitCode;
    }

    /**
     * @param  array<string,mixed>  $payload
     */
    private function renderHuman(array $payload): void
    {
        $this->components->twoColumnDetail(
            '<fg=bright-blue;options=bold>Atlas Forge Provider Failure Record</>',
            (string) ($payload['schema_version'] ?? ''),
        );
        $this->components->twoColumnDetail('Status', (string) ($payload['status'] ?? ''));
        if (isset($payload['blocker'])) {
            $this->components->twoColumnDetail('Blocker', (string) $payload['blocker']);
        }
        $event = is_array($payload['event'] ?? null) ? $payload['event'] : null;
        if ($event !== null) {
            $this->components->twoColumnDetail('Event ID', (string) ($event['event_id'] ?? '—'));
            $this->components->twoColumnDetail('Provider', (string) ($event['provider'] ?? '—'));
            $this->components->twoColumnDetail('Failure type', (string) ($event['failure_type'] ?? '—'));
            $this->components->twoColumnDetail('Cooldown until', (string) ($event['cooldown_until'] ?? '—'));
        }
    }


}
