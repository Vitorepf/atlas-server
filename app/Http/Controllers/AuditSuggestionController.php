<?php

namespace App\Http\Controllers;

use App\Models\AiTrace;
use App\Models\AuditEvent;
use App\Models\Capture;
use App\Models\SemanticCurationProposal;
use App\Models\SemanticNoteActivation;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\Schema;
use Illuminate\Support\Str;

class AuditSuggestionController extends Controller
{
    public function __invoke(Request $request): JsonResponse
    {
        $limit = max(3, min(30, (int) $request->integer('limit', 12)));

        $proposals = Schema::hasTable('semantic_curation_proposals')
            ? SemanticCurationProposal::query()
                ->orderByDesc('created_at')
                ->limit($limit)
                ->get()
            : collect();
        $captures = $this->capturesFor($proposals);
        $activations = Schema::hasTable('semantic_note_activations')
            ? SemanticNoteActivation::query()
                ->with('note')
                ->orderByDesc('created_at')
                ->limit($limit)
                ->get()
            : collect();
        $traces = Schema::hasTable('ai_traces')
            ? AiTrace::query()
                ->with('job')
                ->orderByDesc('created_at')
                ->limit($limit)
                ->get()
            : collect();
        $auditEvents = Schema::hasTable('audit_events')
            ? AuditEvent::query()->orderByDesc('occurred_at')->limit($limit)->get()
            : collect();

        $items = collect()
            ->merge($auditEvents->map(fn (AuditEvent $event): array => $this->auditEventItem($event))->all())
            ->merge($proposals->map(fn (SemanticCurationProposal $proposal): array => $this->proposalItem($proposal, $captures))->all())
            ->merge($activations->map(fn (SemanticNoteActivation $activation): array => $this->activationItem($activation))->all())
            ->merge($traces->map(fn (AiTrace $trace): array => $this->traceItem($trace))->all())
            ->sortByDesc('created_at')
            ->take($limit)
            ->values()
            ->all();

        return response()->json([
            'items' => $items,
            'generated_at' => now()->toJSON(),
        ]);
    }

    private function auditEventItem(AuditEvent $event): array
    {
        return [
            'id' => 'audit_event:'.$event->id,
            'type' => $event->event_type,
            'title' => $this->auditTitle($event),
            'status' => $event->severity,
            'created_at' => $event->occurred_at?->toJSON(),
            'why' => $event->summary,
            'evidence' => $event->evidence ?? [],
            'privacy' => $event->privacy ?? [],
            'raw_refs' => [
                ...($event->refs ?? []),
                'audit_event_id' => $event->id,
                'subject_type' => $event->subject_type,
                'subject_id' => $event->subject_id,
            ],
        ];
    }

    private function auditTitle(AuditEvent $event): string
    {
        $subject = $event->subject_type ? str($event->subject_type)->replace('_', ' ')->title()->toString() : 'Atlas';

        return $subject.' · '.str($event->event_type)->replace('_', ' ')->lower()->toString();
    }

    /**
     * @param  Collection<int, SemanticCurationProposal>  $proposals
     * @return Collection<string, Capture>
     */
    private function capturesFor(Collection $proposals): Collection
    {
        $ids = $proposals
            ->map(fn (SemanticCurationProposal $proposal): mixed => data_get($proposal->source_refs, 'capture_id'))
            ->filter(fn (mixed $id): bool => is_string($id) && $id !== '')
            ->values();

        if ($ids->isEmpty() || ! Schema::hasTable('captures')) {
            return collect();
        }

        return Capture::withTrashed()
            ->whereIn('id', $ids)
            ->get()
            ->keyBy('id');
    }

    /**
     * @param  Collection<string, Capture>  $captures
     */
    private function proposalItem(SemanticCurationProposal $proposal, Collection $captures): array
    {
        $captureId = data_get($proposal->source_refs, 'capture_id');
        $capture = is_string($captureId) ? $captures->get($captureId) : null;
        $clarification = data_get($proposal->metadata, 'semantic_clarification', []);
        $density = is_array(data_get($clarification, 'density')) ? data_get($clarification, 'density') : [];

        return [
            'id' => 'proposal:'.$proposal->id,
            'type' => 'curation_proposal',
            'title' => $proposal->proposed_title,
            'status' => $proposal->status,
            'created_at' => $proposal->created_at?->toJSON(),
            'why' => $proposal->reason,
            'evidence' => [
                'source' => $capture ? 'capture:'.$capture->id : $proposal->source_type,
                'domain' => $capture?->domain,
                'kind' => $capture?->kind,
                'main_thesis' => data_get($clarification, 'main_thesis'),
                'suggested_type' => $proposal->proposed_note_type,
                'density' => [
                    'score' => data_get($density, 'score', $proposal->score),
                    'label' => data_get($density, 'label'),
                    'drivers' => data_get($density, 'drivers', []),
                ],
                'authorship_question' => data_get($clarification, 'authorship_question'),
                'raw_excerpt' => $capture ? Str::limit((string) $capture->content_text, 220) : null,
            ],
            'privacy' => data_get($proposal->metadata, 'privacy', data_get($capture?->metadata, 'privacy', [])),
            'raw_refs' => $proposal->source_refs,
        ];
    }

    private function activationItem(SemanticNoteActivation $activation): array
    {
        $payload = is_array($activation->context_payload) ? $activation->context_payload : [];
        $metadata = is_array($activation->metadata) ? $activation->metadata : [];

        return [
            'id' => 'activation:'.$activation->id,
            'type' => 'semantic_activation',
            'title' => $activation->note?->title ?? 'Ativacao sem nota',
            'status' => $activation->feedback_action ?? ($activation->dismissed_at ? 'dismissed' : 'pending'),
            'created_at' => $activation->created_at?->toJSON(),
            'why' => $activation->prompt,
            'evidence' => [
                'context_type' => $activation->context_type,
                'activation_type' => $activation->activation_type,
                'matched_signals' => data_get($payload, 'matched_signals', []),
                'signals' => array_slice((array) data_get($payload, 'signals', []), 0, 12),
                'score' => data_get($payload, 'score', data_get($metadata, 'relevance_score')),
                'fatigue_policy' => data_get($payload, 'fatigue_policy', data_get($metadata, 'fatigue_policy')),
                'note_maturity' => $activation->note?->maturity,
                'note_usefulness_avg' => $activation->note?->usefulness_avg,
            ],
            'privacy' => [
                'domain' => data_get($payload, 'domain'),
                'sensitivity' => data_get($payload, 'sensitivity'),
            ],
            'raw_refs' => [
                'activation_id' => $activation->id,
                'note_id' => $activation->note_id,
                'capture_id' => data_get($payload, 'capture_id'),
            ],
        ];
    }

    private function traceItem(AiTrace $trace): array
    {
        return [
            'id' => 'ai_trace:'.$trace->id,
            'type' => 'ai_audit',
            'title' => $trace->agent_slug.' · '.$trace->status,
            'status' => $trace->status,
            'created_at' => $trace->created_at?->toJSON(),
            'why' => $trace->intent ?: 'Execucao de IA registrada pelo Atlas.',
            'evidence' => [
                'source_type' => $trace->source_type,
                'source_id' => $trace->source_id,
                'provider' => $trace->provider,
                'model' => $trace->model,
                'prompt_hash' => $trace->prompt_hash,
                'response_hash' => $trace->response_hash,
                'job_status' => $trace->job?->status,
                'job_error' => $trace->job?->error_message,
                'latency_ms' => $trace->latency_ms,
            ],
            'privacy' => data_get($trace->metadata, 'privacy', []),
            'raw_refs' => [
                'trace_id' => $trace->id,
                'job_id' => $trace->job?->id,
            ],
        ];
    }
}
