<?php

declare(strict_types=1);

namespace App\Services\Ai\WorkspaceIntelligence;

final class AtlasWorkspaceArtifactWorkroomService
{
    public const SCHEMA_VERSION = 'atlas.workspace_artifact_workroom.v1';

    /**
     * @param  array<string,mixed>  $report
     * @param  array<string,mixed>|null  $awairOverride
     * @return array<string,mixed>
     */
    public function build(array $report, ?array $awairOverride = null, ?string $artifact = null): array
    {
        if (($report['status'] ?? null) !== 'ready') {
            return $this->blocked('workspace_intelligence_not_ready', $report, $artifact);
        }

        $awair = $awairOverride ?? (array) ($report['awair'] ?? []);
        if (($awair['status'] ?? null) !== 'ready') {
            return $this->blocked('artifact_intelligence_not_ready', $report, $artifact, [
                'awair_status' => $awair['status'] ?? null,
                'awair_blockers' => $awair['blockers'] ?? [],
            ]);
        }

        $artifacts = collect((array) data_get($report, 'awaf.artifacts', []))
            ->filter(static fn (mixed $item): bool => is_array($item))
            ->values();

        $selected = $this->selectArtifact($artifacts->all(), $artifact);
        if ($selected === null) {
            return $this->blocked('artifact_not_found', $report, $artifact);
        }

        $artifactHash = (string) ($selected['artifact_hash'] ?? '');
        $graphNode = $this->graphNodeFor($awair, $artifactHash);
        $quality = $this->qualityFor($awair, $artifactHash);
        $workspace = (array) ($report['workspace'] ?? []);
        $workspaceId = (string) ($workspace['workspace_id'] ?? data_get($awair, 'workspace_id', 'unknown'));
        $artifactType = (string) ($selected['artifact_type'] ?? 'unknown');
        $sourceHashes = array_values(array_filter((array) ($selected['source_hashes'] ?? []), 'is_string'));
        $consumer = (string) ($graphNode['consumer'] ?? $this->defaultConsumerFor($artifactType));
        $route = $this->routeFor($artifactType, $consumer, $awair);
        $timeline = $this->timelineFor($awair, $artifactHash, $artifactType);
        $diffs = $this->diffsFor($artifactHash, $artifactType, $awair);
        $humanPacket = $this->humanPacket($selected, $workspaceId, $consumer, $quality, $route);
        $agentPacket = $this->agentPacket($report, $selected, $consumer, $route);
        $replayPoint = $this->replayPoint($report, $awair, $selected, $timeline);

        $payload = [
            'schema_version' => self::SCHEMA_VERSION,
            'status' => 'ready',
            'family' => 'AWAOL',
            'workspace_id' => $workspaceId,
            'artifact_id' => $artifactHash,
            'artifact_hash' => $artifactHash,
            'artifact_type' => $artifactType,
            'consumer' => $consumer,
            'quality_score' => (float) ($quality['quality_score'] ?? 0.0),
            'human_packet' => $humanPacket,
            'agent_packet' => $agentPacket,
            'timeline' => $timeline,
            'diffs' => $diffs,
            'routes' => [$route],
            'replay_point' => $replayPoint,
            'source_policy' => $this->sourcePolicy(),
            'claim_policy' => $this->claimPolicy(),
        ];

        $payload['workroom_hash'] = $this->hash($payload);

        return $payload;
    }

    /**
     * @param  array<string,mixed>  $workroom
     * @return array<string,mixed>
     */
    public function retirementProposal(array $workroom, ?string $reason): array
    {
        $blockers = [];
        if (($workroom['status'] ?? null) !== 'ready') {
            $blockers[] = 'artifact_workroom_not_ready';
        }
        if ($reason === null) {
            $blockers[] = 'retirement_reason_required';
        }

        $payload = [
            'schema_version' => 'atlas.workspace_artifact_retirement_proposal.v1',
            'status' => $blockers === [] ? 'ready' : 'blocked',
            'workspace_id' => $workroom['workspace_id'] ?? null,
            'artifact_hash' => $workroom['artifact_hash'] ?? null,
            'artifact_type' => $workroom['artifact_type'] ?? null,
            'reason' => $reason,
            'replacement_required' => in_array((string) ($workroom['artifact_type'] ?? ''), ['task_packet', 'context_pack', 'test_plan', 'risk_sheet'], true),
            'blockers' => $blockers,
            'source_policy' => $workroom['source_policy'] ?? $this->sourcePolicy(),
            'claim_policy' => [
                'read_only' => true,
                'artifact_deleted' => false,
                'artifact_hidden_from_active_path' => false,
                'retirement_queue_persisted' => true,
            ],
        ];
        $payload['proposal_hash'] = $this->hash($payload);

        return $payload;
    }

    /**
     * @param  array<int,array<string,mixed>>  $artifacts
     * @return array<string,mixed>|null
     */
    private function selectArtifact(array $artifacts, ?string $artifact): ?array
    {
        $needle = trim((string) $artifact);
        if ($needle !== '') {
            foreach ($artifacts as $item) {
                if (
                    hash_equals((string) ($item['artifact_hash'] ?? ''), $needle)
                    || (string) ($item['artifact_type'] ?? '') === $needle
                ) {
                    return $item;
                }
            }
        }

        foreach (['task_packet', 'context_pack', 'handoff_packet'] as $preferred) {
            foreach ($artifacts as $item) {
                if (($item['artifact_type'] ?? null) === $preferred) {
                    return $item;
                }
            }
        }

        return $artifacts[0] ?? null;
    }

    /**
     * @param  array<string,mixed>  $awair
     * @return array<string,mixed>
     */
    private function graphNodeFor(array $awair, string $artifactHash): array
    {
        foreach ((array) data_get($awair, 'artifact_graph.nodes', []) as $node) {
            if (is_array($node) && hash_equals((string) ($node['id'] ?? ''), $artifactHash)) {
                return $node;
            }
        }

        return [];
    }

    /**
     * @param  array<string,mixed>  $awair
     * @return array<string,mixed>
     */
    private function qualityFor(array $awair, string $artifactHash): array
    {
        foreach ((array) data_get($awair, 'artifact_quality_governor.quality', []) as $quality) {
            if (is_array($quality) && hash_equals((string) ($quality['artifact_hash'] ?? ''), $artifactHash)) {
                return $quality;
            }
        }

        return [];
    }

    private function defaultConsumerFor(string $artifactType): string
    {
        return match ($artifactType) {
            'workspace_brief', 'execution_plan', 'outcome_record' => 'atlas_forge',
            'failure_capsule' => 'repair_loop',
            'workspace_runbook' => 'cartography_and_human',
            default => 'atlas_dev',
        };
    }

    /**
     * @param  array<string,mixed>  $awair
     * @return array<string,mixed>
     */
    private function routeFor(string $artifactType, string $consumer, array $awair): array
    {
        $simulationDecision = (string) data_get($awair, 'artifact_simulation.decision', 'blocked');
        $blockers = (array) data_get($awair, 'artifact_simulation.blockers', []);
        $decision = $simulationDecision === 'ready' ? 'ready' : 'blocked';

        $target = match ($consumer) {
            'atlas_forge' => 'forge',
            'repair_loop' => 'repair',
            'cartography_and_human' => 'human_review',
            default => 'dev',
        };

        if (in_array($artifactType, ['execution_plan', 'workspace_runbook'], true)) {
            $target = 'forge';
        }

        return [
            'schema_version' => 'atlas.workspace_artifact_route.v1',
            'decision' => $decision,
            'target' => $target,
            'consumer' => $consumer,
            'reason' => $decision === 'ready'
                ? 'artifact_has_ready_simulation_and_quality_gate'
                : 'artifact_simulation_blocked',
            'blockers' => $blockers,
            'requires_replay_point' => true,
            'requires_quality_gate' => true,
        ];
    }

    /**
     * @param  array<string,mixed>  $awair
     * @return array<int,array<string,mixed>>
     */
    private function timelineFor(array $awair, string $artifactHash, string $artifactType): array
    {
        $events = [
            [
                'event' => 'created_from_awaf',
                'artifact_hash' => $artifactHash,
                'label' => 'Artifact criado pelo AWAF',
            ],
        ];

        foreach ((array) data_get($awair, 'artifact_graph.edges', []) as $edge) {
            if (! is_array($edge)) {
                continue;
            }
            if (($edge['from'] ?? null) === $artifactHash || ($edge['to'] ?? null) === $artifactHash) {
                $events[] = [
                    'event' => 'graph_edge',
                    'relation' => (string) ($edge['relation'] ?? 'related'),
                    'from' => (string) ($edge['from'] ?? ''),
                    'to' => (string) ($edge['to'] ?? ''),
                    'label' => $artifactType.' participa do grafo operacional',
                ];
            }
        }

        $events[] = [
            'event' => 'ready_for_route',
            'artifact_hash' => $artifactHash,
            'label' => 'Artifact pronto para roteamento read-only',
        ];

        return $events;
    }

    /**
     * @param  array<string,mixed>  $awair
     * @return array<int,array<string,mixed>>
     */
    private function diffsFor(string $artifactHash, string $artifactType, array $awair): array
    {
        return [
            [
                'schema_version' => 'atlas.workspace_artifact_hash_diff.v1',
                'artifact_hash' => $artifactHash,
                'artifact_type' => $artifactType,
                'against' => 'current_runtime_artifact_graph',
                'graph_hash' => data_get($awair, 'artifact_graph.graph_hash'),
                'state' => 'baseline',
                'human_label' => 'Sem artifact anterior informado; este hash vira baseline de comparacao.',
            ],
        ];
    }

    /**
     * @param  array<string,mixed>  $artifact
     * @param  array<string,mixed>  $quality
     * @param  array<string,mixed>  $route
     * @return array<string,mixed>
     */
    private function humanPacket(array $artifact, string $workspaceId, string $consumer, array $quality, array $route): array
    {
        $artifactType = (string) ($artifact['artifact_type'] ?? 'unknown');

        return [
            'schema_version' => 'atlas.workspace_artifact_human_packet.v1',
            'mode_30s' => [
                'title' => $this->titleFor($artifactType),
                'what_it_is' => $this->whatItIs($artifactType),
                'current_status' => 'ready',
                'safe_next_action' => 'Usar rota '.$route['target'].' somente com replay point e quality gate.',
            ],
            'details' => [
                'workspace_id' => $workspaceId,
                'artifact_type' => $artifactType,
                'consumer' => $consumer,
                'quality_score' => (float) ($quality['quality_score'] ?? 0.0),
                'source_hash_count' => count((array) ($artifact['source_hashes'] ?? [])),
                'risk' => $artifactType === 'risk_sheet' ? 'risk_sheet_controls_execution' : 'declared_by_related_risk_sheet',
                'proof' => 'source_hashes + artifact_hash + quality_governor + simulation',
            ],
        ];
    }

    /**
     * @param  array<string,mixed>  $report
     * @param  array<string,mixed>  $artifact
     * @param  array<string,mixed>  $route
     * @return array<string,mixed>
     */
    private function agentPacket(array $report, array $artifact, string $consumer, array $route): array
    {
        return [
            'schema_version' => 'atlas.workspace_artifact_agent_packet.v1',
            'workspace_id' => data_get($report, 'workspace.workspace_id'),
            'consumer' => $consumer,
            'route_target' => $route['target'] ?? 'dev',
            'artifact_type' => $artifact['artifact_type'] ?? 'unknown',
            'artifact_hash' => $artifact['artifact_hash'] ?? null,
            'allowed_paths' => (array) data_get($report, 'awtr.living_code_map.critical_areas', []),
            'forbidden_paths' => ['outside_workspace_root', 'raw_conversation_archive'],
            'must_keep' => [
                'workspace_id',
                'artifact_hash',
                'source_hashes',
                'test_plan',
                'risk_sheet',
                'done_when',
            ],
            'context_refs' => (array) data_get($report, 'awtr.genome.owner_docs', []),
            'test_plan' => (array) data_get($report, 'awaf.artifacts.4.body.focused_tests', []),
            'done_when' => (array) data_get($report, 'awaf.artifacts.1.body.definition_of_done', []),
            'redaction' => 'provider_safe',
            'raw_conversation_included' => false,
            'artifact_body_included' => false,
        ];
    }

    /**
     * @param  array<string,mixed>  $report
     * @param  array<string,mixed>  $awair
     * @param  array<string,mixed>  $artifact
     * @param  array<int,array<string,mixed>>  $timeline
     * @return array<string,mixed>
     */
    private function replayPoint(array $report, array $awair, array $artifact, array $timeline): array
    {
        return [
            'schema_version' => 'atlas.workspace_artifact_replay_point.v1',
            'status' => data_get($awair, 'artifact_replay.replay_ready') === true ? 'ready' : 'blocked',
            'workspace_id' => data_get($report, 'workspace.workspace_id'),
            'workspace_hash' => data_get($report, 'workspace.workspace_hash'),
            'artifact_hash' => $artifact['artifact_hash'] ?? null,
            'source_hashes' => (array) ($artifact['source_hashes'] ?? []),
            'required_inputs' => (array) data_get($awair, 'artifact_replay.required_inputs', []),
            'raw_conversation_required' => false,
            'timeline_event_count' => count($timeline),
        ];
    }

    private function titleFor(string $artifactType): string
    {
        return str_replace('_', ' ', $artifactType);
    }

    private function whatItIs(string $artifactType): string
    {
        return match ($artifactType) {
            'task_packet' => 'Unidade clara de trabalho para Dev, Forge ou subagente.',
            'context_pack' => 'Contexto minimo verificavel para executar sem reler conversa bruta.',
            'test_plan' => 'Lista de testes e fallback para provar a entrega.',
            'risk_sheet' => 'Mapa de riscos e areas sensiveis antes de mexer.',
            'handoff_packet' => 'Pacote seguro para provider ou subagente.',
            default => 'Artefato operacional do workspace.',
        };
    }

    /**
     * @param  array<string,mixed>  $report
     * @param  array<string,mixed>  $extra
     * @return array<string,mixed>
     */
    private function blocked(string $reason, array $report, ?string $artifact, array $extra = []): array
    {
        return [
            'schema_version' => self::SCHEMA_VERSION,
            'status' => 'blocked',
            'family' => 'AWAOL',
            'workspace_id' => data_get($report, 'workspace.workspace_id'),
            'artifact' => $artifact,
            'blockers' => [$reason],
            'source_policy' => $this->sourcePolicy(),
            'claim_policy' => $this->claimPolicy(),
        ] + $extra;
    }

    /**
     * @return array<string,bool>
     */
    private function sourcePolicy(): array
    {
        return [
            'raw_conversation_returned' => false,
            'full_message_content_returned' => false,
            'artifact_body_returned' => false,
            'workspace_scope_required' => true,
            'hashes_are_authoritative' => true,
        ];
    }

    /**
     * @return array<string,bool>
     */
    private function claimPolicy(): array
    {
        return [
            'read_only' => true,
            'invokes_provider' => false,
            'spends_tokens' => false,
            'cross_workspace_read_allowed' => false,
            'mutative_execution_allowed' => false,
        ];
    }

    /**
     * @param  array<string,mixed>  $payload
     */
    private function hash(array $payload): string
    {
        unset($payload['workroom_hash']);
        ksort($payload);

        return hash('sha256', json_encode($payload, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE | JSON_THROW_ON_ERROR));
    }
}
