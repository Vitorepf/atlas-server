<?php

declare(strict_types=1);

namespace App\Services\AtlasCode;

use App\Models\AtlasProject;

/**
 * Atlas Code Provider Operating Room read-model.
 *
 * Canon: docs/engineering-knowledge-base/atlas-code-adaptive-provider-operating-room-v1.md
 *
 * Schema: atlas.code.provider_operating_room.v1
 *
 * Aggregates everything the operator needs to govern a Programming Obra
 * across providers in a single read-model:
 *   - obra header (id, title, workspace)
 *   - provider_board: bootstrap role assignments + governance flags
 *   - work_packets: drafts, ready, exported
 *   - observed_sessions: every interactive provider run for this Obra
 *   - safety_summary: subscription-only, headless blocked, workspace ok
 *   - attention: the single next human action this Obra needs
 *
 * Read-only. Mutations live in dedicated endpoints (create packet, open
 * session, import result, decide).
 */
final class AtlasCodeProviderOperatingRoomService
{
    public const SCHEMA_VERSION = 'atlas.code.provider_operating_room.v1';

    public function __construct(
        private readonly AtlasCodeProviderGovernanceService $governance,
        private readonly AtlasCodeWorkPacketService $packets,
        private readonly AtlasCodeObservedSessionService $sessions,
        private readonly AtlasCodeWorkspaceProfileService $profiles
    ) {}

    /**
     * @return array<string, mixed>
     */
    public function snapshotForObra(AtlasProject $obra): array
    {
        $obraId = (string) $obra->getKey();
        $governance = $this->governance->snapshot();
        $workspaceSlug = (string) (data_get($obra->metadata, 'workspace_slug') ?? '');
        $workspacePath = (string) (data_get($obra->metadata, 'workspace_path') ?? '');
        if ($workspaceSlug !== '' && $workspacePath === '') {
            $profile = $this->profiles->findBySlug($workspaceSlug);
            $workspacePath = (string) ($profile['workspace_path'] ?? '');
        }
        $workspaceExists = $workspacePath !== '' && @is_dir($workspacePath);

        $packets = $this->packets->listForObra($obraId);
        $sessions = $this->sessions->listForObra($obraId);

        $packetsByStatus = [
            'draft' => [],
            'ready' => [],
            'exported' => [],
        ];
        foreach ($packets as $packet) {
            $status = (string) $packet['status'];
            if ($status === 'ready' && $packet['exported_at'] !== null) {
                $packetsByStatus['exported'][] = $packet;
            } elseif (isset($packetsByStatus[$status])) {
                $packetsByStatus[$status][] = $packet;
            }
        }

        $sessionsByState = [];
        foreach ($sessions as $session) {
            $state = (string) $session['state'];
            $sessionsByState[$state][] = $session;
        }

        $providerBoard = $this->buildProviderBoard($governance, $sessions);
        $attention = $this->buildAttentionItem($obra, $packets, $sessions, $workspaceExists, $workspacePath, $governance);
        $safety = $this->buildSafetySummary($governance, $workspaceExists, $workspacePath);

        return [
            'schema_version' => self::SCHEMA_VERSION,
            'generated_at' => now()->toJSON(),
            'obra' => [
                'id' => $obraId,
                'title' => (string) ($obra->title ?? ''),
                'workspace_slug' => $workspaceSlug !== '' ? $workspaceSlug : null,
                'workspace_path' => $workspacePath !== '' ? $workspacePath : null,
                'workspace_path_exists' => $workspaceExists,
                'status' => (string) ($obra->status ?? 'active'),
            ],
            'governance' => $governance,
            'provider_board' => $providerBoard,
            'work_packets' => [
                'all' => $packets,
                'by_status' => $packetsByStatus,
                'counts' => [
                    'total' => count($packets),
                    'draft' => count($packetsByStatus['draft']),
                    'ready' => count($packetsByStatus['ready']),
                    'exported' => count($packetsByStatus['exported']),
                ],
            ],
            'observed_sessions' => [
                'all' => $sessions,
                'by_state' => $sessionsByState,
                'counts' => [
                    'total' => count($sessions),
                    'waiting_operator' => count($sessionsByState['waiting_operator'] ?? []),
                    'running' => count($sessionsByState['running'] ?? []),
                    'waiting_result_import' => count($sessionsByState['waiting_result_import'] ?? []),
                    'imported' => count($sessionsByState['imported'] ?? []),
                    'review_required' => count($sessionsByState['review_required'] ?? []),
                    'accepted' => count($sessionsByState['accepted'] ?? []),
                    'rejected' => count($sessionsByState['rejected'] ?? []),
                    'repair_required' => count($sessionsByState['repair_required'] ?? []),
                    'blocked' => count($sessionsByState['blocked'] ?? []),
                ],
            ],
            'attention' => $attention,
            'safety_summary' => $safety,
            'allowed_actions' => $this->allowedActions($obra, $workspaceExists, $packets, $sessions),
        ];
    }

    /**
     * @param  array<string, mixed>  $governance
     * @param  array<int, array<string, mixed>>  $sessions
     * @return array<int, array<string, mixed>>
     */
    private function buildProviderBoard(array $governance, array $sessions): array
    {
        $bootstrap = (array) ($governance['bootstrap_role_assignments'] ?? []);
        $providersById = [];
        foreach ((array) ($governance['providers'] ?? []) as $p) {
            $providersById[(string) ($p['id'] ?? '')] = $p;
        }
        $sessionsByProvider = [];
        foreach ($sessions as $session) {
            $providerId = (string) ($session['provider_id'] ?? '');
            $sessionsByProvider[$providerId][] = $session;
        }

        $board = [];
        foreach ($bootstrap as $roleSlot => $providerId) {
            $provider = $providersById[(string) $providerId] ?? null;
            if ($provider === null) {
                continue;
            }
            $providerSessions = $sessionsByProvider[(string) $providerId] ?? [];
            $latest = $providerSessions !== [] ? $providerSessions[0] : null;
            $board[] = [
                'role_slot' => (string) $roleSlot,
                'provider_id' => (string) $providerId,
                'provider_name' => (string) ($provider['name'] ?? $providerId),
                'invocation_mode' => (string) ($provider['invocation_mode'] ?? 'interactive_observed'),
                'family' => (string) ($provider['family'] ?? ''),
                'binary_hint' => $provider['binary_hint'] ?? null,
                'assignment_source' => 'bootstrap',
                'subscription_status' => (string) ($provider['subscription_status'] ?? 'allowed'),
                'confidence' => 'low_bootstrap',
                'latest_session' => $latest === null ? null : [
                    'id' => (string) $latest['id'],
                    'state' => (string) $latest['state'],
                    'work_packet_id' => (string) $latest['work_packet_id'],
                    'updated_at' => (string) $latest['updated_at'],
                ],
                'active_session_count' => count(array_filter(
                    $providerSessions,
                    static fn (array $s): bool => in_array((string) $s['state'], ['waiting_operator', 'running', 'waiting_result_import', 'imported', 'review_required'], true)
                )),
            ];
        }

        return $board;
    }

    /**
     * Compute the single next human action this Obra needs. Canon principle:
     * "Atlas trabalha em paralelo. O humano decide em série." Atencao routes
     * one decision at a time.
     *
     * @param  array<int, array<string, mixed>>  $packets
     * @param  array<int, array<string, mixed>>  $sessions
     * @param  array<string, mixed>  $governance
     * @return array<string, mixed>
     */
    private function buildAttentionItem(
        AtlasProject $obra,
        array $packets,
        array $sessions,
        bool $workspaceExists,
        string $workspacePath,
        array $governance
    ): array {
        // Priority order:
        // 1. workspace_path missing → operator must configure project
        // 2. session in review_required → human acceptance
        // 3. session in repair_required → operator must reroute / fix packet
        // 4. session in waiting_result_import → operator must import report
        // 5. session in running → just informational, no decision
        // 6. session in waiting_operator → operator must open terminal + run claude
        // 7. no packets at all → operator must create first packet
        // 8. packets ready but no sessions → operator can open observed session
        // 9. otherwise → idle

        if (! $workspaceExists) {
            return [
                'kind' => 'workspace_path_missing',
                'severity' => 'high',
                'human_question' => 'Configure o workspace_path do Projeto antes de abrir um provider.',
                'why_now' => 'Sem workspace resolvido, Atlas não consegue garantir scope guard nem rodar comandos seguros.',
                'recommended_action' => 'configure_workspace',
                'allowed_actions' => ['configure_workspace'],
                'target_session_id' => null,
                'target_packet_id' => null,
            ];
        }

        // Look for highest-priority session state.
        $byState = [];
        foreach ($sessions as $session) {
            $byState[(string) $session['state']][] = $session;
        }
        $pickFirst = static fn (string $state) => isset($byState[$state]) && $byState[$state] !== [] ? $byState[$state][0] : null;

        if (($s = $pickFirst('review_required')) !== null) {
            return [
                'kind' => 'review_required',
                'severity' => 'high',
                'human_question' => 'Importou o resultado. Aceita, rejeita, pede repair ou bloqueia?',
                'why_now' => 'Completion só nasce de aceite humano. Avalie diff, relatório e critérios de aceite.',
                'recommended_action' => 'open_session_review',
                'allowed_actions' => ['accept', 'reject', 'request_repair', 'block'],
                'target_session_id' => (string) $s['id'],
                'target_packet_id' => (string) $s['work_packet_id'],
            ];
        }
        if (($s = $pickFirst('waiting_result_import')) !== null) {
            return [
                'kind' => 'waiting_result_import',
                'severity' => 'medium',
                'human_question' => 'O provider terminou? Cole o relatório + diff para Atlas validar.',
                'why_now' => 'Atlas só pode rodar gates depois da importação.',
                'recommended_action' => 'import_result',
                'allowed_actions' => ['import_result', 'block'],
                'target_session_id' => (string) $s['id'],
                'target_packet_id' => (string) $s['work_packet_id'],
            ];
        }
        if (($s = $pickFirst('repair_required')) !== null) {
            return [
                'kind' => 'repair_required',
                'severity' => 'medium',
                'human_question' => 'Reabra a sessão para repair ou reescreva o packet com novos critérios.',
                'why_now' => 'A entrega anterior falhou aceite — decida reroute ou bloqueio.',
                'recommended_action' => 'reopen_session',
                'allowed_actions' => ['reopen_session', 'block'],
                'target_session_id' => (string) $s['id'],
                'target_packet_id' => (string) $s['work_packet_id'],
            ];
        }
        if (($s = $pickFirst('running')) !== null) {
            return [
                'kind' => 'running_informational',
                'severity' => 'low',
                'human_question' => 'Provider rodando. Acompanhe e marque "pronto para importar" quando terminar.',
                'why_now' => 'Estado informativo, nenhuma decisão pendente agora.',
                'recommended_action' => 'mark_waiting_import',
                'allowed_actions' => ['mark_waiting_import', 'block'],
                'target_session_id' => (string) $s['id'],
                'target_packet_id' => (string) $s['work_packet_id'],
            ];
        }
        if (($s = $pickFirst('waiting_operator')) !== null) {
            return [
                'kind' => 'waiting_operator',
                'severity' => 'medium',
                'human_question' => 'Abra o terminal no workspace e rode o provider com o prompt copy-safe.',
                'why_now' => 'Atlas já preparou packet + prompt. Falta o gesto humano de iniciar o provider.',
                'recommended_action' => 'open_terminal_and_run',
                'allowed_actions' => ['mark_running', 'block'],
                'target_session_id' => (string) $s['id'],
                'target_packet_id' => (string) $s['work_packet_id'],
            ];
        }
        if ($packets === []) {
            return [
                'kind' => 'no_packets',
                'severity' => 'medium',
                'human_question' => 'Crie o primeiro Work Packet para esta Obra antes de abrir um provider.',
                'why_now' => 'Packet é a unidade governada que define escopo, allowed_files e aceite.',
                'recommended_action' => 'create_work_packet',
                'allowed_actions' => ['create_work_packet'],
                'target_session_id' => null,
                'target_packet_id' => null,
            ];
        }
        $readyPackets = array_filter($packets, static fn (array $p): bool => $p['status'] === 'ready');
        if ($readyPackets !== [] && $sessions === []) {
            $first = array_values($readyPackets)[0];

            return [
                'kind' => 'ready_to_open_provider',
                'severity' => 'low',
                'human_question' => 'Há packet pronto. Abra Claude Code observado (ou outro provider) para executar.',
                'why_now' => 'Packet declara objetivo, allowed_files e aceite — Atlas pode preparar a sessão.',
                'recommended_action' => 'open_observed_provider',
                'allowed_actions' => ['open_observed_provider'],
                'target_session_id' => null,
                'target_packet_id' => (string) $first['id'],
            ];
        }

        return [
            'kind' => 'idle',
            'severity' => 'none',
            'human_question' => 'Nenhuma decisão pendente neste momento.',
            'why_now' => 'Todas as sessões observadas estão fechadas ou aguardando ação não-humana.',
            'recommended_action' => null,
            'allowed_actions' => [],
            'target_session_id' => null,
            'target_packet_id' => null,
        ];
    }

    /**
     * @param  array<string, mixed>  $governance
     * @return array<string, mixed>
     */
    private function buildSafetySummary(array $governance, bool $workspaceExists, string $workspacePath): array
    {
        $blockers = [];
        if (! $workspaceExists) {
            $blockers[] = 'workspace_path_missing_or_unreadable';
        }
        $apiKey = ! empty($governance['api_key_detected']);
        $apiPaygAllowed = ! empty($governance['allow_api_payg']);
        if ($apiKey && ! $apiPaygAllowed) {
            // API key in env but PAYG not explicitly authorized — warning, not blocker.
            $blockers[] = 'api_key_detected_but_allow_api_payg_false';
        }
        $hardBlockReached = ! empty($governance['hard_block_after_reached']);
        $policy = (string) ($governance['effective_policy'] ?? 'test_only');

        // Build a per-label permission table for the UI.
        $labelDecisions = (array) ($governance['label_decisions'] ?? []);
        $perLabel = [];
        foreach ($labelDecisions as $label => $decision) {
            $perLabel[] = [
                'label' => (string) $label,
                'allowed' => (bool) ($decision['allowed'] ?? false),
                'reason' => (string) ($decision['reason'] ?? ''),
                'next_action' => (string) ($decision['next_action'] ?? ''),
                'requires_operator_override' => (bool) ($decision['requires_operator_override'] ?? false),
            ];
        }

        return [
            // Policy state — primary signals.
            'claude_programmatic_policy' => (string) ($governance['claude_programmatic_policy'] ?? 'test_only'),
            'effective_policy' => $policy,
            'hard_block_after' => $governance['hard_block_after'] ?? null,
            'hard_block_after_reached' => $hardBlockReached,
            'programmatic_invocation_allowed' => (bool) ($governance['programmatic_invocation_allowed'] ?? false),
            'productive_headless_allowed' => (bool) ($governance['productive_headless_allowed'] ?? false),
            'interactive_only' => (bool) ($governance['interactive_only'] ?? false),
            'full_block' => (bool) ($governance['full_block'] ?? false),
            'allow_rivals_programmatic' => (bool) ($governance['allow_rivals_programmatic'] ?? false),
            'allow_programmatic_tests' => (bool) ($governance['allow_programmatic_tests'] ?? false),
            'allow_productive_headless' => (bool) ($governance['allow_productive_headless'] ?? false),
            'allow_api_payg' => $apiPaygAllowed,
            'api_key_detected' => $apiKey,
            // Workspace state.
            'workspace_path_resolved' => $workspaceExists,
            'workspace_path' => $workspacePath !== '' ? $workspacePath : null,
            'execution_blocked' => $blockers !== [],
            'blockers' => $blockers,
            // Per-label decisions (the UI uses this to render the policy grid).
            'label_decisions' => $perLabel,
            'allowed_labels' => (array) ($governance['allowed_labels'] ?? []),
            // Hard contract law (always shown to operator).
            'completion_law' => 'Completion só nasce de evidence + gates + aceite humano. Texto do provider não é completion.',
            'never_silent_fallback' => true,
            'never_api_payg_without_explicit_authorization' => ! $apiPaygAllowed,
        ];
    }

    /**
     * @param  array<int, array<string, mixed>>  $packets
     * @param  array<int, array<string, mixed>>  $sessions
     * @return array<int, string>
     */
    private function allowedActions(AtlasProject $obra, bool $workspaceExists, array $packets, array $sessions): array
    {
        if (! $workspaceExists) {
            return ['create_work_packet']; // can draft a packet even without path; running provider blocked.
        }
        $actions = ['create_work_packet'];
        $readyPackets = array_filter($packets, static fn (array $p): bool => $p['status'] === 'ready');
        if ($readyPackets !== []) {
            $actions[] = 'open_observed_provider';
        }

        // Per-session actions are derived from session.state in the UI.
        return $actions;
    }
}
