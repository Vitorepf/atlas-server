<?php

declare(strict_types=1);

namespace App\Services\Ai\OpenBrainContextPack;

use App\Models\AtlasMemoryEntry;
use App\Services\Ai\Context\AtlasContextRuntime;
use App\Services\Ai\Mcp\AtlasMcpTierService;
use App\Services\Ai\Memory\AtlasHybridMemoryRetrievalService;
use App\Services\Ai\Obra\AtlasDeterministicBriefService;
use App\Services\Ai\Obra\AtlasObraStateService;
use App\Services\Ai\ValueObjects\AiTaskRequest;
use Illuminate\Support\Facades\DB;
use Throwable;

/**
 * GOD-DEBULK split of {@see \App\Services\Ai\AtlasOpenBrainContextPackService}.
 * Verbatim runtimecompose family extracted from the AOBG context-pack
 * façade; behavior-preserving (private helpers -> Support; public API stays on the façade).
 */
final class RuntimeComposeSection
{
    public function __construct(
        private readonly Support $support,
        private readonly AtlasHybridMemoryRetrievalService $memory,
    ) {}

    /**
     * Thin adapter: attach executor-bound compose without replacing AOBG fused pack.
     *
     * @param  array<string,mixed>  $pack
     * @param  array<string,mixed>  $opts
     * @return array<string,mixed>
     */
    public function attachRuntimeCompose(array $pack, string $task, array $opts, string $workspaceId): array
    {
        try {
            $runtime = app(AtlasContextRuntime::class);
            $taskRequest = AiTaskRequest::fromInput($task, [
                'agent_slug' => 'aobg',
                'provider' => 'local',
                'source_type' => 'aobg_context_pack',
                'payload' => [
                    'workspace' => $workspaceId,
                    'changed_files' => $this->support->stringList($opts['changed_files'] ?? []),
                ],
            ], ['agent' => 'aobg', 'intent' => 'context_pack']);
            $contract = $runtime->compose($task, $taskRequest, [
                'workspace' => (string) ($opts['workspace'] ?? $opts['cwd'] ?? base_path()),
                'flow_id' => (string) ($opts['flow_id'] ?? 'atlas_aobg'),
                'changed_files' => $this->support->stringList($opts['changed_files'] ?? []),
            ]);
            $pack['runtime_compose'] = method_exists($contract, 'toArray') ? $contract->toArray() : ['schema' => 'composed'];
            $pack['runtime_compose_status'] = 'ok';
        } catch (Throwable $e) {
            $pack['runtime_compose'] = null;
            $pack['runtime_compose_status'] = 'degraded';
            $pack['runtime_compose_error'] = $e->getMessage();
        }

        return $pack;
    }

    /**
     * WO-17-T1 — the ACTIVE obra's resumption facts, or {present:false} when no obra
     * is active. Fuses two brains: the on-disk obra state (last session + drift) and
     * refutations relevant to the task (admission-time matcher, query-aware since T0.2).
     * (B2e removed a decorative long-horizon-continuity arm that had no producer and so
     * was always null.) Fail-open on every arm — resumption must never break the pack.
     *
     * @return array<string,mixed>
     */
    public function retomadaSection(string $workspaceId, string $task): array
    {
        try {
            $state = app(AtlasObraStateService::class);
            $id = $state->currentId();
            if ($id === null) {
                return ['present' => false];
            }

            $obra = $state->read($id) ?? ['obra_id' => $id];
            $sessions = array_values((array) ($obra['sessions'] ?? []));
            $last = $sessions === [] ? null : (array) end($sessions);

            $lastHead = trim((string) ($obra['head'] ?? ($last['head'] ?? '')));
            $nowHead = $state->gitHead();
            $drift = ($lastHead !== '' && $nowHead !== '' && $lastHead !== $nowHead)
                ? "main avançou desde sua última sessão (era {$lastHead}, agora {$nowHead})"
                : 'sem drift de main desde a última sessão';

            // B2e (fechamento ACOS): the obra-scoped long-horizon continuity arm was
            // DECORATIVE — no producer emits an 'obra' continuation pack, so
            // latestContinuityFor('obra', …) was always null. Removed to kill the nominal
            // lie; the on-disk obra state below already carries phase, session, drift,
            // pendencies and decisions — everything the retomada section renders.
            return [
                'present' => true,
                'obra_id' => $id,
                'phase' => $obra['phase'] ?? null,
                'last_session' => $last,
                'drift' => $drift,
                'pendencies' => array_values((array) ($obra['pendencies'] ?? [])),
                'decisions' => array_values((array) ($obra['decisions'] ?? [])),
                'refutacoes' => $this->refutationMatches($task, $workspaceId),
            ];
        } catch (Throwable) {
            return ['present' => false];
        }
    }

    /**
     * Admission-time refutation matcher: refutations relevant to the task the session
     * is about to work on ("isto já foi refutado antes"). Query-aware recall (T0.2
     * forwarded the question), refutation_memory only, capped + provider-safe.
     *
     * @return list<array<string,mixed>>
     */
    public function refutationMatches(string $task, string $workspaceId): array
    {
        if (trim($task) === '') {
            return [];
        }
        try {
            $recall = $this->memory->recall(
                $task,
                ['workspace' => $workspaceId],
                ['memory_type' => 'refutation_memory'],
                ['limit' => 3, 'requester' => 'obra_retomada', 'include_verbatim' => false, 'include_semantic' => false, 'include_compounding' => false],
            );

            $matches = [];
            foreach ((array) ($recall['recall'] ?? []) as $row) {
                if (! is_array($row)) {
                    continue;
                }
                $title = trim((string) ($row['title'] ?? ''));
                if ($title === '') {
                    continue;
                }
                $strength = $this->refutationStrengthForRow($row);
                $matches[] = [
                    'title' => $title,
                    'forbidden_context' => true,
                    'refutation_strength' => $strength,
                ];
            }

            return $matches;
        } catch (Throwable) {
            return [];
        }
    }

    /**
     * @param  array<string,mixed>  $row
     * @return array<string,mixed>|null
     */
    public function refutationStrengthForRow(array $row): ?array
    {
        if (is_array($row['refutation_strength'] ?? null)) {
            return $row['refutation_strength'];
        }

        $id = trim((string) ($row['id'] ?? ($row['source_ref_id'] ?? '')));
        if ($id === '') {
            return null;
        }

        try {
            $entry = AtlasMemoryEntry::query()->find($id);
            $strength = $entry instanceof AtlasMemoryEntry
                ? data_get($entry->metadata, 'refutation_strength')
                : null;
            if (! is_array($strength)) {
                $metadata = DB::table('atlas_memory_entries')->where('id', $id)->value('metadata');
                $decoded = is_string($metadata) ? json_decode($metadata, true) : null;
                $strength = is_array($decoded) ? data_get($decoded, 'refutation_strength') : null;
            }
        } catch (Throwable) {
            return null;
        }

        return is_array($strength) ? $strength : null;
    }

    /**
     * WO-17-T2 — the deterministic brief surface. {present:false} when none exists;
     * else a compact projection + staleness (STALE the moment HEAD moved past it, so
     * the pack shows "BRIEF STALE desde X" — never a silent stale brief). Fail-open.
     *
     * @return array<string,mixed>
     */
    public function briefSection(): array
    {
        try {
            $svc = app(AtlasDeterministicBriefService::class);
            $brief = $svc->read();
            if ($brief === null) {
                return ['present' => false];
            }
            $st = $svc->staleness($brief);

            return [
                'present' => true,
                'stale' => (bool) $st['stale'],
                'generated_at' => (string) $st['generated_at'],
                'invariants' => array_slice((array) ($brief['invariants'] ?? []), 0, 3),
                'refutations' => array_slice((array) ($brief['refutations'] ?? []), 0, 3),
                'modules' => array_slice((array) ($brief['modules'] ?? []), 0, 3),
            ];
        } catch (Throwable) {
            return ['present' => false];
        }
    }

    /**
     * @param  array<string,mixed>  $code
     * @param  array<string,mixed>  $reality
     * @param  array<string,mixed>  $memory
     * @return array<string,mixed>
     */
    public function contextHygieneSummary(array $code, array $reality, array $memory): array
    {
        $pathFiltered = (int) data_get($code, 'provenance.path_filtered_count', 0);
        $feedbackDemoted = (int) data_get($code, 'provenance.feedback_demoted_count', 0)
            + (int) data_get($memory, 'provenance.feedback_demoted_count', 0);
        $memoryFiltered = (int) data_get($memory, 'provenance.relevance_filtered_count', 0);
        $sessionEchoFiltered = (int) data_get($reality, 'provenance.session_echo_paths_omitted', 0);
        $docMissionFiltered = (int) data_get($reality, 'provenance.doc_mission_paths_omitted', 0);
        $totalCeilingTrimmed = (int) data_get($code, 'provenance.total_ceiling_trimmed_count', 0)
            + (int) data_get($reality, 'provenance.total_ceiling_trimmed_count', 0)
            + (int) data_get($memory, 'provenance.total_ceiling_trimmed_count', 0);

        return [
            'path_filtered' => $pathFiltered,
            'feedback_demoted' => $feedbackDemoted,
            'memory_relevance_filtered' => $memoryFiltered,
            'session_echo_filtered' => $sessionEchoFiltered,
            'doc_mission_filtered' => $docMissionFiltered,
            'total_ceiling_trimmed' => $totalCeilingTrimmed,
            'total_filtered' => $pathFiltered + $feedbackDemoted + $memoryFiltered + $sessionEchoFiltered + $docMissionFiltered + $totalCeilingTrimmed,
        ];
    }

    /**
     * Obra 7 / OB-03: Absorcao 4 phase-1 tier manifest (discovery → context → detail).
     *
     * @return array<string,mixed>
     */
    public function progressiveDisclosureManifest(): array
    {
        try {
            $tier = app(AtlasMcpTierService::class);

            return [
                'schema_version' => 'atlas.mcp.tier.v1',
                'workflow' => 'search_brief → timeline → get_full',
                'manifest' => $tier->tierManifest(),
                'savings_estimate' => $tier->estimateSavings(5),
            ];
        } catch (Throwable) {
            return [
                'schema_version' => 'atlas.mcp.tier.v1',
                'status' => 'unavailable',
            ];
        }
    }
}
