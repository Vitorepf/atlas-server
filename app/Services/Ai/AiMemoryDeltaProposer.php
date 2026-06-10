<?php

namespace App\Services\Ai;

use App\Models\AiMemoryDelta;
use App\Models\AiQualityEvaluation;
use App\Models\AiSessionState;
use App\Models\AiTrace;
use App\Models\Capture;
use App\Services\Ai\Support\DatabaseTableAvailability;
use Illuminate\Support\Str;

class AiMemoryDeltaProposer
{
    /**
     * @return array<int,AiMemoryDelta>
     */
    public function proposeForWorkspace(string $workspace, int $limit = 5): array
    {
        if (! DatabaseTableAvailability::has('ai_memory_deltas')
            || ! DatabaseTableAvailability::has('ai_traces')
            || ! DatabaseTableAvailability::has('ai_session_states')) {
            return [];
        }

        $workspace = realpath($workspace) ?: $workspace;
        $trace = AiTrace::query()
            ->where(function ($query) use ($workspace): void {
                $query->where('metadata->context_pack->surface->workspace', $workspace)
                    ->orWhere('metadata->dev_execution_plan->workspace', $workspace);
            })
            ->latest()
            ->first();

        $state = AiSessionState::query()
            ->when($trace?->thread_id, fn ($query) => $query->where('thread_id', $trace->thread_id))
            ->latest('updated_at')
            ->first();

        $proposals = [];
        foreach (collect($state?->decisions ?? [])->take($limit) as $decision) {
            $text = is_array($decision) ? (string) ($decision['text'] ?? '') : (string) $decision;
            if ($text !== '') {
                $proposals[] = $this->firstOrCreate($workspace, 'process', $text, $trace?->id, $trace?->session_id, [
                    ['kind' => 'session_state', 'ref' => (string) $state?->id, 'excerpt' => Str::limit($text, 500)],
                ]);
            }
        }

        if (count($proposals) < $limit && $trace) {
            $plan = data_get($trace->metadata, 'dev_execution_plan');
            if (is_array($plan) && ($plan['objective'] ?? null)) {
                $claim = 'Neste workspace, o Atlas deve preservar o objetivo tecnico recente: '.Str::limit((string) $plan['objective'], 220, '');
                $proposals[] = $this->firstOrCreate($workspace, 'process', $claim, $trace->id, $trace->session_id, [
                    ['kind' => 'trace', 'ref' => $trace->id, 'excerpt' => Str::limit((string) $trace->operator_input, 500)],
                ]);
            }
        }

        if (count($proposals) < $limit && $trace && DatabaseTableAvailability::has('ai_quality_evaluations')) {
            $evaluation = AiQualityEvaluation::query()
                ->where('trace_id', $trace->id)
                ->where('status', '!=', 'passed')
                ->latest()
                ->first();

            if ($evaluation) {
                $flags = collect($evaluation->flags)->pluck('code')->filter()->implode(', ');
                $claim = 'Quando houver flags de qualidade neste workspace, o Atlas deve reparar antes de declarar conclusao. Flags recentes: '.($flags ?: $evaluation->status).'.';
                $proposals[] = $this->firstOrCreate($workspace, 'error_pattern', $claim, $trace->id, $trace->session_id, [
                    ['kind' => 'quality_evaluation', 'ref' => $evaluation->id, 'excerpt' => $flags ?: $evaluation->status],
                ]);
            }
        }

        return array_values(array_filter($proposals));
    }

    /**
     * @param  array<string,mixed>  $context
     */
    public function proposeForCapture(Capture $capture, array $context = []): ?AiMemoryDelta
    {
        if (! DatabaseTableAvailability::has('ai_memory_deltas')) {
            return null;
        }

        $text = trim((string) $capture->content_text);
        if ($text === '') {
            return null;
        }

        $quarantine = is_array(data_get($capture->metadata, 'cognitive_quarantine'))
            ? data_get($capture->metadata, 'cognitive_quarantine')
            : [];
        $privacy = is_array(data_get($capture->metadata, 'privacy'))
            ? data_get($capture->metadata, 'privacy')
            : [];
        $externalAllowed = data_get($privacy, 'external_ai_allowed');
        $providerSafe = is_bool($externalAllowed) ? $externalAllowed : true;
        $claim = $providerSafe
            ? 'Capture candidate: '.Str::limit($text, 420, '')
            : 'Private capture candidate requires manual review before memory promotion.';
        $contentHash = is_string($quarantine['content_hash'] ?? null)
            ? $quarantine['content_hash']
            : hash('sha256', $text);
        $immuneAudit = is_array($quarantine['immune_audit'] ?? null)
            ? $quarantine['immune_audit']
            : [
                'schema_version' => 'atlas.capture.cognitive_immune_audit.v1',
                'status' => 'legacy_missing',
                'audit_hash' => hash('sha256', implode('|', [
                    'legacy_missing_cognitive_immune_audit',
                    $capture->id,
                    $contentHash,
                ])),
                'noise_gate' => [
                    'status' => 'not_recorded_legacy_capture',
                ],
                'memory_promotion_allowed_now' => false,
                'context_export_allowed_now' => false,
            ];
        $proposalId = is_scalar($context['proposal_id'] ?? null) ? (string) $context['proposal_id'] : null;

        return AiMemoryDelta::query()->firstOrCreate([
            'scope' => 'capture:'.$capture->id,
            'status' => 'pending',
        ], [
            'source_workspace' => null,
            'type' => $this->captureDeltaType($capture, $context),
            'claim' => $claim,
            'evidence' => [[
                'kind' => 'capture_quarantine',
                'ref' => $capture->id,
                'capture_client_id' => $capture->client_id,
                'proposal_id' => $proposalId,
                'content_hash' => $contentHash,
                'privacy_class' => $privacy['sensitivity'] ?? data_get($capture->metadata, 'sensitivity', 'normal'),
                'provider_safe' => $providerSafe,
                'promotion_status' => $quarantine['promotion_status'] ?? 'unclassified',
                'immune_audit_schema_version' => $immuneAudit['schema_version'] ?? null,
                'immune_audit_status' => $immuneAudit['status'] ?? null,
                'immune_audit_hash' => $immuneAudit['audit_hash'] ?? null,
                'immune_noise_gate_status' => data_get($immuneAudit, 'noise_gate.status'),
                'immune_memory_promotion_allowed_now' => $immuneAudit['memory_promotion_allowed_now'] ?? false,
                'immune_context_export_allowed_now' => $immuneAudit['context_export_allowed_now'] ?? false,
            ]],
            'confidence' => $providerSafe ? 0.64 : 0.4,
            'valid_from' => now(),
            'valid_until' => now()->addDays(90),
            'use_when' => [
                'operator accepts the capture as reusable memory',
                'capture quarantine review passes privacy and utility gates',
            ],
            'do_not_use_when' => [
                'capture remains unreviewed',
                'privacy review blocks provider-safe or memory use',
                'operator rejects the curation proposal',
            ],
            'requires_confirmation' => true,
        ]);
    }

    /**
     * @param  array<int,array<string,string>>  $evidence
     */
    private function firstOrCreate(string $workspace, string $type, string $claim, ?string $traceId, ?string $sessionId, array $evidence): AiMemoryDelta
    {
        return AiMemoryDelta::query()->firstOrCreate([
            'claim' => $claim,
            'scope' => 'workspace:'.$workspace,
            'status' => 'pending',
        ], [
            'source_trace_id' => $traceId,
            'source_session_id' => $sessionId,
            'source_workspace' => $workspace,
            'type' => $type,
            'evidence' => $evidence,
            'confidence' => 0.72,
            'valid_from' => now(),
            'valid_until' => now()->addDays(90),
            'use_when' => ['workspace atual for relevante', 'tarefa tocar no mesmo fluxo'],
            'do_not_use_when' => ['o operador corrigir ou rejeitar esta memoria'],
            'requires_confirmation' => true,
        ]);
    }

    /**
     * @param  array<string,mixed>  $context
     */
    private function captureDeltaType(Capture $capture, array $context): string
    {
        $suggested = (string) ($context['memory_type'] ?? data_get($capture->metadata, 'semantic_clarification.result.suggested_type', 'technical_context'));

        return match ($suggested) {
            'decision', 'preference', 'feedback', 'issue', 'resolution', 'strategic_insight' => $suggested,
            'hypothesis', 'synthesis', 'note', 'technical_context' => 'technical_context',
            default => 'technical_context',
        };
    }
}
