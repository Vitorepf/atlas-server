<?php

namespace App\Services;

use App\Jobs\ProcessAudioTranscription;
use App\Models\Capture;
use App\Models\TranscriptionJob;
use App\Services\Semantic\ActivationEngine;
use App\Services\Semantic\CaptureSemanticClarifier;
use App\Services\Semantic\CurationProposalService;
use App\Support\Metadata;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use Illuminate\Support\Facades\Storage;
use Illuminate\Validation\ValidationException;
use Throwable;

class CaptureService
{
    public function __construct(
        private readonly CaptureFileStorage $files,
        private readonly CapturePrivacyService $privacy,
        private readonly AuditLogService $audit,
        private readonly CaptureDestinationService $destinations,
        private readonly CaptureSemanticClarifier $clarifier,
        private readonly CurationProposalService $curation,
        private readonly ActivationEngine $activations,
    ) {}

    public function create(array $data, ?UploadedFile $file = null): array
    {
        $existing = Capture::withTrashed()
            ->where('client_id', $data['client_id'])
            ->first();

        if ($existing) {
            if ($this->isReadyForSemanticCuration($existing)) {
                $existing = $this->clarifyProposeAndActivate($existing, 'capture_replayed');
            }

            return ['capture' => $existing->refresh(), 'created' => false];
        }

        $storedFile = null;

        try {
            if ($file) {
                $storedFile = $this->files->store($file, $data['kind']);
            }

            $result = DB::transaction(function () use ($data, $storedFile): array {
                $domain = $data['domain'] ?? app(AtlasDomainRegistry::class)->defaultSlug();
                $metadata = $this->privacy->normalizeMetadata($data['metadata'] ?? [], $domain, $data['kind']);
                $capture = Capture::create([
                    'client_id' => $data['client_id'],
                    'kind' => $data['kind'],
                    'domain' => $domain,
                    'content_text' => $data['content_text'] ?? null,
                    'content_file_path' => $storedFile['relative_path'] ?? null,
                    'content_duration_ms' => $data['content_duration_ms'] ?? null,
                    'content_size_bytes' => $storedFile['size_bytes'] ?? null,
                    'content_sha256' => $storedFile['sha256'] ?? null,
                    'content_mime_type' => $storedFile['mime_type'] ?? null,
                    'transcription_status' => $data['kind'] === 'audio' ? 'pending' : 'na',
                    'captured_at' => $data['captured_at'],
                    'captured_timezone' => $data['captured_timezone'],
                    'captured_lat' => $data['captured_lat'] ?? null,
                    'captured_lng' => $data['captured_lng'] ?? null,
                    'pre_capture_digital_context' => Metadata::forStorage($data['pre_capture_digital_context'] ?? []),
                    'metadata' => Metadata::forStorage($metadata),
                ]);

                $transcriptionJob = null;

                if ($capture->kind === 'audio') {
                    $transcriptionJob = TranscriptionJob::create([
                        'capture_id' => $capture->id,
                        'status' => 'queued',
                    ]);

                    if (config('atlas.transcription.enabled')) {
                        ProcessAudioTranscription::dispatch($transcriptionJob->id)
                            ->onQueue('transcription')
                            ->afterCommit();
                    }
                }

                if ($this->isReadyForSemanticCuration($capture)) {
                    $capture = $this->clarifyProposeAndActivate($capture, 'capture_created');
                }

                $this->audit->record('capture_created', [
                    'subject_type' => 'capture',
                    'subject_id' => $capture->id,
                    'summary' => "Captura {$capture->kind} criada no dominio {$capture->domain}.",
                    'evidence' => [
                        'kind' => $capture->kind,
                        'domain' => $capture->domain,
                        'content_text' => $capture->content_text,
                        'transcription_status' => $capture->transcription_status,
                        'content_file_path' => $capture->content_file_path,
                    ],
                    'privacy' => $this->capturePrivacy($capture),
                    'refs' => [
                        'capture_id' => $capture->id,
                        'capture_client_id' => $capture->client_id,
                    ],
                ]);

                return [
                    'capture' => $capture->refresh(),
                    'transcription_job' => $transcriptionJob,
                    'created' => true,
                ];
            });
        } catch (Throwable $throwable) {
            $this->files->deleteIfCreated($storedFile);
            throw $throwable;
        }

        return $result;
    }

    public function update(Capture $capture, array $data): Capture
    {
        $domain = $data['domain'] ?? $capture->domain;
        $kind = $data['kind'] ?? $capture->kind;
        $metadata = array_key_exists('metadata', $data)
            ? $data['metadata']
            : ($capture->metadata ?? []);

        $data['metadata'] = Metadata::forStorage($this->privacy->normalizeMetadata(
            is_array($metadata) ? $metadata : [],
            $domain,
            $kind,
        ));

        $capture->update($data);
        $capture = $capture->refresh();

        if ($this->isReadyForSemanticCuration($capture)) {
            $capture = $this->clarifyProposeAndActivate($capture, 'capture_updated');
        }

        $this->audit->record('capture_updated', [
            'subject_type' => 'capture',
            'subject_id' => $capture->id,
            'summary' => "Captura {$capture->kind} atualizada.",
            'evidence' => [
                'changed_fields' => array_keys($data),
                'domain' => $capture->domain,
                'content_text' => $capture->content_text,
            ],
            'privacy' => $this->capturePrivacy($capture),
            'refs' => [
                'capture_id' => $capture->id,
                'capture_client_id' => $capture->client_id,
            ],
        ]);

        return $capture;
    }

    public function retryTranscription(Capture $capture): Capture
    {
        if ($capture->kind !== 'audio') {
            throw ValidationException::withMessages([
                'capture' => 'Only audio captures can be transcribed.',
            ]);
        }

        if (! $capture->content_file_path || ! Storage::disk('atlas')->exists($capture->content_file_path)) {
            throw ValidationException::withMessages([
                'file' => 'Capture audio file is missing from Atlas storage.',
            ]);
        }

        if (! config('atlas.transcription.enabled')) {
            throw ValidationException::withMessages([
                'transcription' => 'Audio transcription is disabled on this server.',
            ]);
        }

        return DB::transaction(function () use ($capture): Capture {
            $activeJob = $capture->transcriptionJobs()
                ->whereIn('status', ['queued', 'processing'])
                ->latest()
                ->first();

            if ($activeJob) {
                return $capture->refresh();
            }

            $capture->update([
                'transcription_status' => 'pending',
                'transcription_error' => null,
            ]);

            $job = TranscriptionJob::create([
                'capture_id' => $capture->id,
                'status' => 'queued',
            ]);

            ProcessAudioTranscription::dispatch($job->id)
                ->onQueue('transcription')
                ->afterCommit();

            return $capture->refresh();
        });
    }

    public function clarify(Capture $capture, string $source = 'manual'): Capture
    {
        if (! $this->isReadyForSemanticCuration($capture)) {
            throw ValidationException::withMessages([
                'content_text' => 'Capture needs text or transcription before semantic clarification.',
            ]);
        }

        return $this->clarifyProposeAndActivate($capture, $source);
    }

    public function triage(Capture $capture, array $data, CurationProposalService $curation): array
    {
        return DB::transaction(function () use ($capture, $data, $curation): array {
            $action = $data['action'];
            $proposal = null;
            $previousDestination = $this->currentDestination($capture);

            if (in_array($action, ['promote', 'create_hypothesis'], true) && ! trim((string) $capture->content_text)) {
                throw ValidationException::withMessages([
                    'content_text' => 'Capture needs text or transcription before promotion.',
                ]);
            }

            if (in_array($action, ['promote', 'create_hypothesis'], true)) {
                $proposal = $curation->findForCapture($capture);
                if (! $proposal) {
                    $proposal = $curation->createFromCapture($capture, [
                        'type' => $action === 'create_hypothesis' ? 'hypothesis' : null,
                        'title' => $data['title'] ?? null,
                        'reason' => $data['reason'] ?? null,
                        'force' => true,
                        'metadata' => [
                            'created_by_triage_action' => $action,
                        ],
                    ]);
                }
            }

            $metadata = $capture->metadata;
            if (! is_array($metadata)) {
                $metadata = (array) $metadata;
            }
            $history = $metadata['triage_history'] ?? [];
            if (! is_array($history)) {
                $history = [];
            }

            $destination = $this->destinations->apply($capture, $action, $data, $proposal);
            $triage = $this->triagePayload($action, $data, $proposal?->id, $destination);
            $this->destinations->retirePrevious($capture, $previousDestination, $destination, $action);
            $historyEntry = [
                'action' => $action,
                'status' => $triage['status'],
                'destination' => $triage['destination'],
                'at' => now()->toJSON(),
                'reason' => $data['reason'] ?? null,
                'proposal_id' => $proposal?->id,
                'target_type' => $destination['target_type'],
                'target_id' => $destination['target_id'],
                'target_title' => $destination['target_title'],
                'previous_destination' => $previousDestination['destination'],
                'previous_target_type' => $previousDestination['target_type'],
                'previous_target_id' => $previousDestination['target_id'],
                'previous_target_title' => $previousDestination['target_title'],
                'changed_destination' => $this->hasDestinationChanged($previousDestination, $triage),
            ];

            $extraMetadata = $data['metadata'] ?? [];
            if (is_array($extraMetadata) && $extraMetadata !== []) {
                $historyEntry['metadata'] = $extraMetadata;
            }

            $metadata['triage'] = $triage;
            $metadata['triage_history'] = array_slice([
                $historyEntry,
                ...$history,
            ], 0, 20);

            $capture->update([
                'metadata' => Metadata::forStorage($metadata),
            ]);

            $this->audit->record('capture_triaged', [
                'subject_type' => 'capture',
                'subject_id' => $capture->id,
                'summary' => "Captura triada: {$action}.",
                'evidence' => [
                    'action' => $action,
                    'triage' => $triage,
                    'proposal_id' => $proposal?->id,
                    'target_type' => $destination['target_type'],
                    'target_id' => $destination['target_id'],
                    'previous_destination' => $previousDestination,
                    'changed_destination' => $historyEntry['changed_destination'],
                ],
                'privacy' => $this->capturePrivacy($capture->refresh()),
                'refs' => [
                    'capture_id' => $capture->id,
                    'proposal_id' => $proposal?->id,
                    'target_id' => $destination['target_id'],
                ],
            ]);

            return [
                'capture' => $this->withDestinationLinks($capture->refresh()),
                'proposal' => $proposal,
            ];
        });
    }

    /**
     * @param  array{target_type: string|null, target_id: string|null, target_title: string|null, link?: mixed}  $destination
     */
    private function triagePayload(string $action, array $data, ?string $proposalId, array $destination): array
    {
        $now = now()->toJSON();

        $payload = match ($action) {
            'archive' => [
                'status' => 'archived',
                'destination' => 'archive',
                'last_action' => $action,
                'updated_at' => $now,
                'reason' => $data['reason'] ?? null,
            ],
            'snooze' => [
                'status' => 'snoozed',
                'destination' => 'later',
                'last_action' => $action,
                'updated_at' => $now,
                'snoozed_until' => $data['snoozed_until'],
                'reason' => $data['reason'] ?? null,
            ],
            'attach_note' => [
                'status' => 'attached',
                'destination' => 'existing_note',
                'last_action' => $action,
                'updated_at' => $now,
                'note_id' => $data['note_id'] ?? null,
                'note_title' => $destination['target_title'] ?? ($data['note_title'] ?? null),
                'target_type' => $destination['target_type'],
                'target_id' => $destination['target_id'],
                'target_title' => $destination['target_title'],
                'reason' => $data['reason'] ?? null,
            ],
            'create_task' => [
                'status' => 'action_required',
                'destination' => 'task',
                'last_action' => $action,
                'updated_at' => $now,
                'title' => $data['title'] ?? null,
                'priority' => $data['priority'] ?? 'normal',
                'due_at' => $data['due_at'] ?? null,
                'planned_for_date' => $data['planned_for_date'] ?? null,
                'planned_start_at' => $data['planned_start_at'] ?? null,
                'planned_end_at' => $data['planned_end_at'] ?? null,
                'estimated_minutes' => $destination['estimated_minutes'] ?? null,
                'energy_required' => $destination['energy_required'] ?? null,
                'priority_score' => $destination['priority_score'] ?? null,
                'target_type' => $destination['target_type'],
                'target_id' => $destination['target_id'],
                'target_title' => $destination['target_title'],
                'reason' => $data['reason'] ?? null,
            ],
            'create_project' => [
                'status' => 'action_required',
                'destination' => 'project',
                'last_action' => $action,
                'updated_at' => $now,
                'title' => $data['title'] ?? null,
                'goal' => $data['goal'] ?? null,
                'next_action' => $destination['next_action'] ?? ($data['next_action'] ?? null),
                'project_type' => $destination['project_type'] ?? null,
                'active_next_task_id' => $destination['active_next_task_id'] ?? null,
                'active_next_task_title' => $destination['active_next_task_title'] ?? null,
                'target_type' => $destination['target_type'],
                'target_id' => $destination['target_id'],
                'target_title' => $destination['target_title'],
                'reason' => $data['reason'] ?? null,
            ],
            'create_hypothesis' => [
                'status' => 'proposed',
                'destination' => 'hypothesis',
                'last_action' => $action,
                'updated_at' => $now,
                'proposal_id' => $proposalId,
                'proposal_status' => $destination['proposal_status'] ?? 'pending',
                'knowledge_state' => 'proposal_pending',
                'human_gate' => 'ratify_or_dismiss',
                'next_action' => 'ratify_proposal',
                'target_type' => $destination['target_type'],
                'target_id' => $destination['target_id'],
                'target_title' => $destination['target_title'],
                'title' => $data['title'] ?? null,
                'reason' => $data['reason'] ?? null,
            ],
            default => [
                'status' => 'proposed',
                'destination' => 'semantic_note',
                'last_action' => $action,
                'updated_at' => $now,
                'proposal_id' => $proposalId,
                'proposal_status' => $destination['proposal_status'] ?? 'pending',
                'knowledge_state' => 'proposal_pending',
                'human_gate' => 'ratify_or_dismiss',
                'next_action' => 'ratify_proposal',
                'target_type' => $destination['target_type'],
                'target_id' => $destination['target_id'],
                'target_title' => $destination['target_title'],
                'title' => $data['title'] ?? null,
                'reason' => $data['reason'] ?? null,
            ],
        };

        $extraMetadata = $data['metadata'] ?? [];
        if (is_array($extraMetadata) && $extraMetadata !== []) {
            $payload['metadata'] = $extraMetadata;
        }

        return $payload;
    }

    private function isReadyForSemanticCuration(Capture $capture): bool
    {
        if (! trim((string) $capture->content_text)) {
            return false;
        }

        return $capture->kind !== 'audio' || $capture->transcription_status === 'done';
    }

    private function clarifyProposeAndActivate(Capture $capture, string $source): Capture
    {
        $capture = $this->clarifier->handleReady($capture, $source);
        $proposal = $this->curation->createFromCapture($capture);

        $this->activateForCapture($capture, $source, $proposal?->id);

        return $capture->refresh();
    }

    private function activateForCapture(Capture $capture, string $source, ?string $proposalId): void
    {
        try {
            $clarification = $this->clarifier->resultFor($capture) ?? [];
            $this->activations->createForContext('capture_created', [
                'source' => $source,
                'capture_id' => $capture->id,
                'capture_client_id' => $capture->client_id,
                'capture_kind' => $capture->kind,
                'domain' => $capture->domain,
                'sensitivity' => data_get($capture->metadata, 'privacy.sensitivity'),
                'external_ai_allowed' => data_get($capture->metadata, 'privacy.external_ai_allowed'),
                'suggested_type' => $clarification['suggested_type'] ?? null,
                'future_triggers' => $clarification['future_triggers'] ?? [],
                'density_score' => data_get($clarification, 'density.score'),
                'curation_proposal_id' => $proposalId,
            ]);
        } catch (Throwable $throwable) {
            report($throwable);
        }
    }

    private function capturePrivacy(Capture $capture): array
    {
        $privacy = data_get($capture->metadata, 'privacy');

        return is_array($privacy)
            ? $privacy
            : [
                'domain' => $capture->domain,
                'sensitivity' => data_get($capture->metadata, 'sensitivity', 'normal'),
            ];
    }

    /**
     * @return array{destination: string|null, target_type: string|null, target_id: string|null, target_title: string|null}
     */
    private function currentDestination(Capture $capture): array
    {
        $destination = data_get($capture->metadata, 'triage.destination');
        $targetType = data_get($capture->metadata, 'triage.target_type');
        $targetId = data_get($capture->metadata, 'triage.target_id');
        $targetTitle = data_get($capture->metadata, 'triage.target_title');
        $resolvedDestination = is_string($destination) && in_array($destination, [
            'semantic_note',
            'existing_note',
            'task',
            'project',
            'hypothesis',
            'archive',
            'later',
        ], true)
            ? $destination
            : null;

        if (! $resolvedDestination && is_string($targetType)) {
            $resolvedDestination = $this->destinationFromTargetType($targetType);
        }

        if ($resolvedDestination || is_string($targetType) || is_string($targetId) || is_string($targetTitle)) {
            return [
                'destination' => $resolvedDestination,
                'target_type' => is_string($targetType) ? $targetType : null,
                'target_id' => is_string($targetId) ? $targetId : null,
                'target_title' => is_string($targetTitle) ? $targetTitle : null,
            ];
        }

        if (! Schema::hasTable('capture_links')) {
            return [
                'destination' => null,
                'target_type' => null,
                'target_id' => null,
                'target_title' => null,
            ];
        }

        $link = $capture->relationLoaded('links')
            ? $capture->links
                ->where('relation_type', 'triage_destination')
                ->sortByDesc('updated_at')
                ->first()
            : $capture->links()
                ->where('relation_type', 'triage_destination')
                ->orderByDesc('updated_at')
                ->orderByDesc('created_at')
                ->first();

        if (! $link) {
            return [
                'destination' => null,
                'target_type' => null,
                'target_id' => null,
                'target_title' => null,
            ];
        }

        return [
            'destination' => $this->destinationFromTargetType($link->target_type),
            'target_type' => $link->target_type,
            'target_id' => $link->target_id,
            'target_title' => $link->target_title,
        ];
    }

    /**
     * @param  array{destination: string|null, target_type: string|null, target_id: string|null, target_title: string|null}  $previous
     * @param  array<string, mixed>  $triage
     */
    private function hasDestinationChanged(array $previous, array $triage): bool
    {
        if (! $previous['destination'] && ! $previous['target_type'] && ! $previous['target_id']) {
            return false;
        }

        return $previous['destination'] !== ($triage['destination'] ?? null)
            || $previous['target_type'] !== ($triage['target_type'] ?? null)
            || $previous['target_id'] !== ($triage['target_id'] ?? null);
    }

    private function destinationFromTargetType(?string $targetType): ?string
    {
        return match ($targetType) {
            'semantic_note' => 'semantic_note',
            'semantic_curation_proposal' => 'semantic_note',
            'task' => 'task',
            'project' => 'project',
            'hypothesis' => 'hypothesis',
            default => null,
        };
    }

    private function withDestinationLinks(Capture $capture): Capture
    {
        if (Schema::hasTable('capture_links')) {
            $capture->load('links');
        }

        return $capture;
    }
}
