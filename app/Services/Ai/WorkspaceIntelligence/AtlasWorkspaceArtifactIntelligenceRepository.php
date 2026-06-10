<?php

declare(strict_types=1);

namespace App\Services\Ai\WorkspaceIntelligence;

use App\Models\AtlasWorkspaceArtifactGraphSnapshot;
use App\Models\AtlasWorkspaceArtifactLakeEntry;
use App\Models\AtlasWorkspaceArtifactRetirementProposal;
use App\Models\AtlasWorkspaceArtifactTimelineEvent;
use App\Services\Ai\Support\DatabaseTableAvailability;
use Illuminate\Support\Carbon;
use Illuminate\Support\Str;

final class AtlasWorkspaceArtifactIntelligenceRepository
{
    /**
     * @return array<string,mixed>
     */
    public function listProviderSafe(string $workspaceId, ?string $artifactType = null, int $limit = 20): array
    {
        if (! DatabaseTableAvailability::has('atlas_workspace_artifact_lake_entries')) {
            return $this->blocked('artifact_lake_table_missing', $workspaceId);
        }

        $query = AtlasWorkspaceArtifactLakeEntry::query()
            ->where('workspace_id', $workspaceId)
            ->latest('captured_at')
            ->limit(max(1, min($limit, 50)));

        if (is_string($artifactType) && trim($artifactType) !== '') {
            $query->where('artifact_type', trim($artifactType));
        }

        $items = $query->get()->map(static fn (AtlasWorkspaceArtifactLakeEntry $entry): array => [
            'artifact_id' => (string) $entry->id,
            'artifact_hash' => (string) $entry->artifact_hash,
            'runtime_hash' => (string) $entry->runtime_hash,
            'artifact_type' => (string) $entry->artifact_type,
            'status' => (string) $entry->status,
            'consumer' => $entry->consumer,
            'source_hash_count' => count((array) $entry->source_hashes),
            'quality_score' => (float) $entry->quality_score,
            'captured_at' => $entry->captured_at?->toISOString(),
            'body_available' => true,
        ])->values()->all();

        return [
            'schema_version' => 'atlas.workspace_artifact_lake_index.v1',
            'status' => 'ready',
            'workspace_id' => $workspaceId,
            'artifact_type' => $artifactType,
            'count' => count($items),
            'artifacts' => $items,
            'source_policy' => $this->sourcePolicy(),
            'claim_policy' => $this->claimPolicy(),
        ];
    }

    /**
     * @return array<string,mixed>
     */
    public function inspectProviderSafe(string $workspaceId, string $artifact): array
    {
        if (! DatabaseTableAvailability::has('atlas_workspace_artifact_lake_entries')) {
            return $this->blocked('artifact_lake_table_missing', $workspaceId);
        }

        $needle = trim($artifact);
        if ($needle === '') {
            return $this->blocked('artifact_id_or_hash_required', $workspaceId);
        }

        $entry = AtlasWorkspaceArtifactLakeEntry::query()
            ->where('workspace_id', $workspaceId)
            ->where(static function ($query) use ($needle): void {
                $query->where('id', $needle)
                    ->orWhere('artifact_hash', $needle)
                    ->orWhere('runtime_hash', $needle);
            })
            ->latest('captured_at')
            ->first();

        if (! $entry instanceof AtlasWorkspaceArtifactLakeEntry) {
            return $this->blocked('artifact_not_found_in_workspace', $workspaceId, [
                'artifact' => $needle,
            ]);
        }

        return [
            'schema_version' => 'atlas.workspace_artifact_lake_entry.v1',
            'status' => 'ready',
            'workspace_id' => $workspaceId,
            'artifact' => [
                'artifact_id' => (string) $entry->id,
                'artifact_hash' => (string) $entry->artifact_hash,
                'runtime_hash' => (string) $entry->runtime_hash,
                'artifact_type' => (string) $entry->artifact_type,
                'status' => (string) $entry->status,
                'consumer' => $entry->consumer,
                'source_hashes' => (array) $entry->source_hashes,
                'quality_score' => (float) $entry->quality_score,
                'captured_at' => $entry->captured_at?->toISOString(),
                'body' => (array) $entry->body,
            ],
            'replay_contract' => [
                'workspace_scope_required' => true,
                'raw_conversation_replay_allowed' => false,
                'provider_prompt_allowed' => (bool) data_get($entry->body, 'fusion_pack.handoff_context.allowed_for_provider_prompt', false),
                'recommended_consumers' => (array) data_get($entry->body, 'fusion_pack.handoff_context.recommended_consumers', []),
            ],
            'source_policy' => $this->sourcePolicy(),
            'claim_policy' => $this->claimPolicy(),
        ];
    }

    public function persist(array $report): ?AtlasWorkspaceArtifactGraphSnapshot
    {
        if (
            ! DatabaseTableAvailability::has('atlas_workspace_artifact_lake_entries')
            || ! DatabaseTableAvailability::has('atlas_workspace_artifact_graph_snapshots')
        ) {
            return null;
        }

        $runtimeHash = (string) ($report['runtime_hash'] ?? '');
        if ($runtimeHash === '') {
            return null;
        }

        $awair = (array) ($report['awair'] ?? []);
        $workspaceId = (string) data_get($awair, 'workspace_id', data_get($report, 'workspace.workspace_id', 'unknown'));
        $capturedAt = Carbon::now();
        $nodesByHash = collect((array) data_get($awair, 'artifact_graph.nodes', []))
            ->filter(static fn (mixed $node): bool => is_array($node))
            ->keyBy(fn (array $node): string => (string) ($node['id'] ?? ''));
        $qualityByHash = collect((array) data_get($awair, 'artifact_quality_governor.quality', []))
            ->filter(static fn (mixed $quality): bool => is_array($quality))
            ->keyBy(fn (array $quality): string => (string) ($quality['artifact_hash'] ?? ''));

        AtlasWorkspaceArtifactLakeEntry::query()
            ->where('runtime_hash', $runtimeHash)
            ->delete();

        foreach ((array) data_get($report, 'awaf.artifacts', []) as $artifact) {
            if (! is_array($artifact)) {
                continue;
            }
            $artifactHash = (string) ($artifact['artifact_hash'] ?? '');
            if ($artifactHash === '') {
                continue;
            }

            $node = (array) ($nodesByHash->get($artifactHash) ?? []);
            $quality = (array) ($qualityByHash->get($artifactHash) ?? []);

            AtlasWorkspaceArtifactLakeEntry::query()->create([
                'workspace_id' => (string) ($artifact['workspace_id'] ?? $workspaceId),
                'runtime_hash' => $runtimeHash,
                'artifact_hash' => $artifactHash,
                'artifact_type' => (string) ($artifact['artifact_type'] ?? 'unknown'),
                'status' => (string) ($artifact['status'] ?? 'unknown'),
                'consumer' => ($node['consumer'] ?? null) !== null ? (string) $node['consumer'] : null,
                'source_hashes' => (array) ($artifact['source_hashes'] ?? []),
                'body' => (array) ($artifact['body'] ?? []),
                'quality_score' => (float) ($quality['quality_score'] ?? 0.0),
                'captured_at' => $capturedAt,
            ]);
        }

        return AtlasWorkspaceArtifactGraphSnapshot::query()->updateOrCreate(
            ['runtime_hash' => $runtimeHash],
            [
                'workspace_id' => $workspaceId,
                'artifact_intelligence_hash' => (string) ($awair['artifact_intelligence_hash'] ?? ''),
                'status' => (string) ($awair['status'] ?? 'blocked'),
                'lake_hash' => data_get($awair, 'artifact_lake.lake_hash'),
                'graph_hash' => data_get($awair, 'artifact_graph.graph_hash'),
                'artifact_count' => (int) data_get($awair, 'artifact_lake.artifact_count', 0),
                'node_count' => count((array) data_get($awair, 'artifact_graph.nodes', [])),
                'edge_count' => count((array) data_get($awair, 'artifact_graph.edges', [])),
                'replay_ready' => (bool) data_get($awair, 'artifact_replay.replay_ready', false),
                'simulation_decision' => (string) data_get($awair, 'artifact_simulation.decision', 'blocked'),
                'nodes' => (array) data_get($awair, 'artifact_graph.nodes', []),
                'edges' => (array) data_get($awair, 'artifact_graph.edges', []),
                'payload' => $awair,
                'captured_at' => $capturedAt,
            ],
        );
    }

    public function latest(string $workspaceId): ?AtlasWorkspaceArtifactGraphSnapshot
    {
        if (! DatabaseTableAvailability::has('atlas_workspace_artifact_graph_snapshots')) {
            return null;
        }

        return AtlasWorkspaceArtifactGraphSnapshot::query()
            ->where('workspace_id', $workspaceId)
            ->latest('captured_at')
            ->first();
    }

    /**
     * @param  array<string,mixed>  $workroom
     * @param  array<string,mixed>  $payload
     * @return array<string,mixed>
     */
    public function recordTimelineEvent(array $workroom, string $eventType, string $eventStatus, array $payload = []): array
    {
        if (! DatabaseTableAvailability::has('atlas_workspace_artifact_timeline_events')) {
            return $this->blocked('artifact_timeline_table_missing', (string) ($workroom['workspace_id'] ?? 'unknown'));
        }

        $safePayload = $this->safeTimelinePayload($payload);
        $eventHash = $this->hash([
            'workspace_id' => $workroom['workspace_id'] ?? null,
            'artifact_hash' => $workroom['artifact_hash'] ?? null,
            'artifact_type' => $workroom['artifact_type'] ?? null,
            'event_type' => $eventType,
            'event_status' => $eventStatus,
            'route_target' => data_get($safePayload, 'route.target'),
            'consumer' => $workroom['consumer'] ?? null,
            'payload' => $safePayload,
        ]);

        $event = AtlasWorkspaceArtifactTimelineEvent::query()->updateOrCreate(
            ['event_hash' => $eventHash],
            [
                'workspace_id' => (string) ($workroom['workspace_id'] ?? 'unknown'),
                'artifact_hash' => (string) ($workroom['artifact_hash'] ?? ''),
                'artifact_type' => (string) ($workroom['artifact_type'] ?? 'unknown'),
                'event_type' => $eventType,
                'event_status' => $eventStatus,
                'route_target' => data_get($safePayload, 'route.target'),
                'consumer' => ($workroom['consumer'] ?? null) !== null ? (string) $workroom['consumer'] : null,
                'payload' => $safePayload,
                'occurred_at' => Carbon::now(),
            ],
        );

        return [
            'schema_version' => 'atlas.workspace_artifact_timeline_event.v1',
            'status' => 'ready',
            'workspace_id' => (string) $event->workspace_id,
            'artifact_hash' => (string) $event->artifact_hash,
            'artifact_type' => (string) $event->artifact_type,
            'event_type' => (string) $event->event_type,
            'event_status' => (string) $event->event_status,
            'route_target' => $event->route_target,
            'consumer' => $event->consumer,
            'event_hash' => (string) $event->event_hash,
            'occurred_at' => $event->occurred_at?->toISOString(),
            'source_policy' => $this->sourcePolicy(),
            'claim_policy' => $this->claimPolicy(),
        ];
    }

    /**
     * @return array<string,mixed>
     */
    public function timelineProviderSafe(string $workspaceId, ?string $artifact = null, int $limit = 30): array
    {
        if (! DatabaseTableAvailability::has('atlas_workspace_artifact_timeline_events')) {
            return $this->blocked('artifact_timeline_table_missing', $workspaceId);
        }

        $query = AtlasWorkspaceArtifactTimelineEvent::query()
            ->where('workspace_id', $workspaceId)
            ->latest('occurred_at')
            ->limit(max(1, min($limit, 100)));

        if (is_string($artifact) && trim($artifact) !== '') {
            $needle = trim($artifact);
            $query->where(static function ($builder) use ($needle): void {
                $builder->where('artifact_hash', $needle)
                    ->orWhere('artifact_type', $needle);
            });
        }

        $events = $query->get()->map(static fn (AtlasWorkspaceArtifactTimelineEvent $event): array => [
            'event_id' => (string) $event->id,
            'artifact_hash' => (string) $event->artifact_hash,
            'artifact_type' => (string) $event->artifact_type,
            'event_type' => (string) $event->event_type,
            'event_status' => (string) $event->event_status,
            'route_target' => $event->route_target,
            'consumer' => $event->consumer,
            'event_hash' => (string) $event->event_hash,
            'occurred_at' => $event->occurred_at?->toISOString(),
        ])->values()->all();

        return [
            'schema_version' => 'atlas.workspace_artifact_timeline.v1',
            'status' => 'ready',
            'workspace_id' => $workspaceId,
            'artifact' => $artifact,
            'count' => count($events),
            'events' => $events,
            'source_policy' => $this->sourcePolicy(),
            'claim_policy' => $this->claimPolicy(),
        ];
    }

    /**
     * @param  array<string,mixed>  $proposal
     * @return array<string,mixed>
     */
    public function persistRetirementProposal(array $proposal): array
    {
        if (! DatabaseTableAvailability::has('atlas_workspace_artifact_retirement_proposals')) {
            return $this->blocked('artifact_retirement_table_missing', (string) ($proposal['workspace_id'] ?? 'unknown'));
        }

        if (($proposal['status'] ?? null) !== 'ready') {
            return $proposal;
        }

        $safePayload = $this->safeTimelinePayload($proposal);
        $proposalHash = (string) ($proposal['proposal_hash'] ?? $this->hash($safePayload));
        $stored = AtlasWorkspaceArtifactRetirementProposal::query()->updateOrCreate(
            ['proposal_hash' => $proposalHash],
            [
                'workspace_id' => (string) ($proposal['workspace_id'] ?? 'unknown'),
                'artifact_hash' => (string) ($proposal['artifact_hash'] ?? ''),
                'artifact_type' => (string) ($proposal['artifact_type'] ?? 'unknown'),
                'reason' => (string) ($proposal['reason'] ?? 'unspecified'),
                'status' => 'proposed',
                'replacement_required' => (bool) ($proposal['replacement_required'] ?? false),
                'payload' => $safePayload,
                'proposed_at' => Carbon::now(),
            ],
        );

        return $proposal + [
            'persisted_retirement_proposal_id' => (string) $stored->id,
            'persisted_status' => (string) $stored->status,
        ];
    }

    /**
     * @return array<string,mixed>
     */
    public function retirementQueueProviderSafe(string $workspaceId, ?string $artifact = null, int $limit = 30): array
    {
        if (! DatabaseTableAvailability::has('atlas_workspace_artifact_retirement_proposals')) {
            return $this->blocked('artifact_retirement_table_missing', $workspaceId);
        }

        $query = AtlasWorkspaceArtifactRetirementProposal::query()
            ->where('workspace_id', $workspaceId)
            ->latest('proposed_at')
            ->limit(max(1, min($limit, 100)));

        if (is_string($artifact) && trim($artifact) !== '') {
            $needle = trim($artifact);
            $query->where(static function ($builder) use ($needle): void {
                $builder->where('artifact_hash', $needle)
                    ->orWhere('artifact_type', $needle)
                    ->orWhere('proposal_hash', $needle);
                if (Str::isUuid($needle)) {
                    $builder->orWhere('id', $needle);
                }
            });
        }

        $items = $query->get()->map(static fn (AtlasWorkspaceArtifactRetirementProposal $proposal): array => [
            'proposal_id' => (string) $proposal->id,
            'proposal_hash' => (string) $proposal->proposal_hash,
            'artifact_hash' => (string) $proposal->artifact_hash,
            'artifact_type' => (string) $proposal->artifact_type,
            'reason' => (string) $proposal->reason,
            'status' => (string) $proposal->status,
            'replacement_required' => (bool) $proposal->replacement_required,
            'proposed_at' => $proposal->proposed_at?->toISOString(),
        ])->values()->all();

        return [
            'schema_version' => 'atlas.workspace_artifact_retirement_queue.v1',
            'status' => 'ready',
            'workspace_id' => $workspaceId,
            'artifact' => $artifact,
            'count' => count($items),
            'proposals' => $items,
            'source_policy' => $this->sourcePolicy(),
            'claim_policy' => $this->retirementClaimPolicy(readOnly: true),
        ];
    }

    /**
     * @return array<string,mixed>
     */
    public function applyRetirementProposal(string $workspaceId, ?string $proposal, ?string $artifact, ?string $replacementArtifact): array
    {
        if (! DatabaseTableAvailability::has('atlas_workspace_artifact_retirement_proposals')) {
            return $this->blocked('artifact_retirement_table_missing', $workspaceId);
        }

        $record = $this->findRetirementProposal($workspaceId, $proposal, $artifact);
        if (! $record instanceof AtlasWorkspaceArtifactRetirementProposal) {
            return $this->blocked('artifact_retirement_proposal_not_found', $workspaceId);
        }

        if ($record->replacement_required && (! is_string($replacementArtifact) || trim($replacementArtifact) === '')) {
            return [
                'schema_version' => 'atlas.workspace_artifact_retirement_apply.v1',
                'status' => 'blocked',
                'workspace_id' => $workspaceId,
                'proposal_id' => (string) $record->id,
                'proposal_hash' => (string) $record->proposal_hash,
                'artifact_hash' => (string) $record->artifact_hash,
                'artifact_type' => (string) $record->artifact_type,
                'blockers' => ['replacement_artifact_required_before_apply'],
                'source_policy' => $this->sourcePolicy(),
                'claim_policy' => $this->retirementClaimPolicy(readOnly: false),
            ];
        }

        $payload = $this->safeTimelinePayload((array) $record->payload);
        $payload['applied'] = [
            'applied_at' => Carbon::now()->toISOString(),
            'replacement_artifact' => is_string($replacementArtifact) && trim($replacementArtifact) !== '' ? trim($replacementArtifact) : null,
            'artifact_deleted' => false,
            'artifact_hidden_from_active_path' => true,
        ];
        $record->forceFill([
            'status' => 'applied',
            'payload' => $payload,
        ])->save();

        return [
            'schema_version' => 'atlas.workspace_artifact_retirement_apply.v1',
            'status' => 'ready',
            'workspace_id' => $workspaceId,
            'proposal_id' => (string) $record->id,
            'proposal_hash' => (string) $record->proposal_hash,
            'artifact_hash' => (string) $record->artifact_hash,
            'artifact_type' => (string) $record->artifact_type,
            'retirement_status' => (string) $record->status,
            'replacement_artifact' => $payload['applied']['replacement_artifact'],
            'source_policy' => $this->sourcePolicy(),
            'claim_policy' => $this->retirementClaimPolicy(readOnly: false),
        ];
    }

    /**
     * @param  array<string,mixed>  $extra
     * @return array<string,mixed>
     */
    private function blocked(string $reason, string $workspaceId, array $extra = []): array
    {
        return [
            'schema_version' => 'atlas.workspace_artifact_lake_entry.v1',
            'status' => 'blocked',
            'workspace_id' => $workspaceId,
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
        ];
    }

    /**
     * @return array<string,bool>
     */
    private function retirementClaimPolicy(bool $readOnly): array
    {
        return [
            'read_only' => $readOnly,
            'invokes_provider' => false,
            'spends_tokens' => false,
            'artifact_deleted' => false,
            'artifact_body_returned' => false,
            'raw_conversation_returned' => false,
            'cross_workspace_read_allowed' => false,
        ];
    }

    private function findRetirementProposal(string $workspaceId, ?string $proposal, ?string $artifact): ?AtlasWorkspaceArtifactRetirementProposal
    {
        $query = AtlasWorkspaceArtifactRetirementProposal::query()->where('workspace_id', $workspaceId);

        if (is_string($proposal) && trim($proposal) !== '') {
            $needle = trim($proposal);

            return (clone $query)->where(static function ($builder) use ($needle): void {
                $builder->where('proposal_hash', $needle);
                if (Str::isUuid($needle)) {
                    $builder->orWhere('id', $needle);
                }
            })->first();
        }

        if (is_string($artifact) && trim($artifact) !== '') {
            $needle = trim($artifact);

            return $query->where(static function ($builder) use ($needle): void {
                $builder->where('artifact_hash', $needle)->orWhere('artifact_type', $needle);
            })->latest('proposed_at')->first();
        }

        return null;
    }

    /**
     * @param  array<string,mixed>  $payload
     * @return array<string,mixed>
     */
    private function safeTimelinePayload(array $payload): array
    {
        unset($payload['body'], $payload['artifact_body'], $payload['raw_conversation'], $payload['messages']);

        return $payload;
    }

    /**
     * @param  array<string,mixed>  $payload
     */
    private function hash(array $payload): string
    {
        ksort($payload);

        return hash('sha256', json_encode($payload, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE | JSON_THROW_ON_ERROR));
    }
}
