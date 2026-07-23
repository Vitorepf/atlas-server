<?php

namespace App\Services;

use App\Jobs\ProcessAudioTranscription;
use App\Models\Capture;
use App\Models\TranscriptionJob;
use App\Services\Ai\AgenticEngineeringOs\Maturity\AtlasCognitiveImmuneInputClassifier;
use App\Services\Ai\Memory\AiMemoryDeltaProposer;
use App\Services\Ai\Cognition\CaptureHmacLineageService;
use App\Services\Ai\Cognition\CognitiveImmunePromotionGateEvaluator;
use App\Services\Ai\Cognition\ImmuneSignatureIngestor;
use App\Services\Ai\Cognition\ImmuneVerdictLedger;
use App\Services\Ai\Support\DatabaseTableAvailability;
use App\Services\Semantic\ActivationEngine;
use App\Services\Semantic\CaptureSemanticClarifier;
use App\Services\Semantic\CurationProposalService;
use App\Support\Metadata;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\DB;
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
        private readonly AiMemoryDeltaProposer $memoryDeltas,
        private readonly CognitiveImmunePromotionGateEvaluator $immuneGateEvaluator,
        private readonly AtlasCognitiveImmuneInputClassifier $immuneInputClassifier,
        private readonly ImmuneVerdictLedger $immuneVerdictLedger,
        private readonly CaptureHmacLineageService $captureHmacLineage,
        private readonly ImmuneSignatureIngestor $immuneSignatureIngestor,
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
            $this->recordCaptureReplay($existing->refresh(), $data);

            return ['capture' => $existing->refresh(), 'created' => false];
        }

        $storedFile = null;

        try {
            if ($file) {
                $storedFile = $this->files->store($file, $data['kind']);
            }

            $result = DB::transaction(function () use ($data, $storedFile): array {
                $domain = $data['domain'] ?? app(AtlasDomainRegistry::class)->defaultSlug();
                $metadata = $this->withCognitiveQuarantine(
                    $this->privacy->normalizeMetadata($data['metadata'] ?? [], $domain, $data['kind']),
                    $data,
                    $storedFile,
                    $domain,
                );
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
                        'content_text' => $this->redactedCaptureTextEvidence($capture->content_text),
                        'transcription_status' => $capture->transcription_status,
                        'content_file_path' => $capture->content_file_path,
                        'cognitive_quarantine' => data_get($capture->metadata, 'cognitive_quarantine'),
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

        $data['metadata'] = Metadata::forStorage($this->withCognitiveQuarantine(
            $this->privacy->normalizeMetadata(
                is_array($metadata) ? $metadata : [],
                $domain,
                $kind,
            ),
            [
                ...$data,
                'client_id' => $capture->client_id,
                'kind' => $kind,
                'content_text' => $data['content_text'] ?? $capture->content_text,
                'content_sha256' => $data['content_sha256'] ?? $capture->content_sha256,
                'captured_at' => $data['captured_at'] ?? $capture->captured_at?->toJSON(),
            ],
            null,
            $domain,
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
                'content_text' => $this->redactedCaptureTextEvidence($capture->content_text),
                'cognitive_quarantine' => data_get($capture->metadata, 'cognitive_quarantine'),
            ],
            'privacy' => $this->capturePrivacy($capture),
            'refs' => [
                'capture_id' => $capture->id,
                'capture_client_id' => $capture->client_id,
            ],
        ]);

        return $capture;
    }

    /**
     * @param  array<string,mixed>  $metadata
     * @param  array<string,mixed>  $data
     * @param  array<string,mixed>|null  $storedFile
     * @return array<string,mixed>
     */
    private function withCognitiveQuarantine(array $metadata, array $data, ?array $storedFile, string $domain): array
    {
        $existing = is_array($metadata['cognitive_quarantine'] ?? null) ? $metadata['cognitive_quarantine'] : [];
        $contentHash = $this->captureContentHash($data, $storedFile);
        $now = now()->toJSON();
        $contentIntelligence = $this->contentIntelligenceContract($metadata, $data, $storedFile, $domain, $contentHash, $now);
        $immuneAudit = $this->cognitiveImmuneAudit($contentIntelligence, $data, $domain, $contentHash);
        $immuneAuditV2 = $this->cognitiveImmuneAuditV2($contentIntelligence, $metadata, $data, $domain, $contentHash);

        return [
            ...$metadata,
            'content_intelligence' => $contentIntelligence,
            'cognitive_quarantine' => [
                ...$existing,
                'schema_version' => 'atlas.capture.cognitive_quarantine.v1',
                'raw_capture' => true,
                'memory_eligible' => false,
                'context_eligible' => false,
                'constellation_eligible' => false,
                'embedding_allowed' => false,
                'provider_export_allowed' => false,
                'open_brain_context_allowed' => false,
                'raw_content_exposed' => false,
                'promotion_status' => $existing['promotion_status'] ?? 'unclassified',
                'promotion_target' => $existing['promotion_target'] ?? null,
                'source_type' => 'capture',
                'source_client_id' => is_scalar($data['client_id'] ?? null) ? (string) $data['client_id'] : null,
                'source_kind' => is_scalar($data['kind'] ?? null) ? (string) $data['kind'] : null,
                'source_domain' => $domain,
                'content_hash' => $contentHash,
                'content_intelligence_schema_version' => $contentIntelligence['schema_version'],
                'content_destination_enum' => $contentIntelligence['destination']['enum'],
                'content_quality_score' => $contentIntelligence['quality']['score'],
                'immune_audit' => $immuneAudit,
                'immune_audit_v2' => $immuneAuditV2,
                'lineage' => [
                    'origin' => 'capture_pipeline',
                    'captured_at' => is_scalar($data['captured_at'] ?? null) ? (string) $data['captured_at'] : null,
                    'content_hash' => $contentHash,
                    'hmac_lineage' => $this->buildCaptureHmacLineage($data, $domain, $contentHash, $existing),
                ],
                'review' => [
                    'required' => true,
                    'status' => $existing['review']['status'] ?? 'pending',
                    'reason' => 'raw_capture_quarantined_before_memory_or_context',
                ],
                'updated_at' => $now,
                'created_at' => $existing['created_at'] ?? $now,
            ],
        ];
    }

    /**
     * MAXI-07 — extend capture quarantine lineage with HMAC-chained provenance.
     *
     * @param  array<string,mixed>  $data
     * @param  array<string,mixed>  $existingQuarantine
     * @return array<string,mixed>
     */
    private function buildCaptureHmacLineage(array $data, string $domain, ?string $contentHash, array $existingQuarantine): array
    {
        $existingChain = is_array($existingQuarantine['lineage']['hmac_lineage'] ?? null)
            ? $existingQuarantine['lineage']['hmac_lineage']
            : [];

        $chain = $existingChain;
        $sourceHash = is_scalar($data['source_hash'] ?? null) ? strtolower((string) $data['source_hash']) : null;
        if ($sourceHash !== null && $this->chainNeedsSourceStage($chain)) {
            $packetChain = $this->packetHmacLineageForSourceHash($sourceHash);
            if (is_array($packetChain)) {
                $chain = $packetChain;
            } else {
                $chain = $this->captureHmacLineage->stampStage($chain, CaptureHmacLineageService::STAGE_SOURCE, [
                    'source_hash' => $sourceHash,
                    'origin_uri' => is_scalar($data['origin_uri'] ?? null) ? (string) $data['origin_uri'] : null,
                ]);
            }
        }

        return $this->captureHmacLineage->stampStage($chain, CaptureHmacLineageService::STAGE_CAPTURE, [
            'source_type' => 'capture',
            'client_id' => is_scalar($data['client_id'] ?? null) ? (string) $data['client_id'] : null,
            'kind' => is_scalar($data['kind'] ?? null) ? (string) $data['kind'] : null,
            'domain' => $domain,
            'content_hash' => $contentHash,
        ]);
    }

    /**
     * @param  array<string,mixed>  $chain
     */
    private function chainNeedsSourceStage(array $chain): bool
    {
        foreach ((array) ($chain['stages'] ?? []) as $link) {
            if (is_array($link) && ($link['stage'] ?? null) === CaptureHmacLineageService::STAGE_SOURCE) {
                return false;
            }
        }

        return true;
    }

    /**
     * @return array<string,mixed>|null
     */
    private function packetHmacLineageForSourceHash(string $sourceHash): ?array
    {
        if (! DatabaseTableAvailability::has('atlas_knowledge_source_packets')) {
            return null;
        }

        $packet = \App\Models\AtlasKnowledgeSourcePacket::query()
            ->where('source_hash', $sourceHash)
            ->whereNull('deleted_at')
            ->first();

        if ($packet === null) {
            return null;
        }

        $lineage = is_array($packet->lineage) ? $packet->lineage : [];
        $chain = $lineage['hmac_lineage'] ?? null;

        return is_array($chain) ? $chain : null;
    }

    /**
     * @param  array<string,mixed>  $contentIntelligence
     * @param  array<string,mixed>  $metadata
     * @param  array<string,mixed>  $data
     * @return array<string,mixed>
     */
    private function cognitiveImmuneAuditV2(array $contentIntelligence, array $metadata, array $data, string $domain, ?string $contentHash): array
    {
        $signals = $this->cognitiveImmuneAuditSignals($contentIntelligence, $metadata, $data, $domain, $contentHash);
        $verdict = $this->immuneGateEvaluator->evaluate($signals);

        $payload = [
            'schema_version' => 'atlas.capture.cognitive_immune_audit.v2',
            'status' => 'shadow_evaluated',
            'evaluator_schema_version' => $verdict['schema_version'],
            'gate_statuses' => $verdict['gate_statuses'],
            'promotion_status' => $verdict['promotion_status'],
            'blocking_gate_ids' => $verdict['blocking_gate_ids'],
            'pending_gate_ids' => $verdict['pending_gate_ids'],
            'reasons' => $verdict['reasons'],
            'autonomous_promotion_allowed' => $verdict['autonomous_promotion_allowed'],
            'signals' => $signals,
        ];
        $payload['audit_hash'] = hash('sha256', json_encode([
            'schema_version' => $payload['schema_version'],
            'content_hash' => $contentHash,
            'verdict' => $verdict,
        ], JSON_THROW_ON_ERROR | JSON_UNESCAPED_SLASHES));
        $ledgerRow = $this->immuneVerdictLedger->recordVerdict(
            $contentHash ?? $payload['audit_hash'],
            'capture_pipeline',
            $verdict,
            [
                'expected_block_gate_ids' => $this->expectedImmuneBlockGateIds($signals),
                'decided_at' => now()->toIso8601String(),
            ],
        );
        if (is_array($ledgerRow)) {
            $this->immuneSignatureIngestor->maybeIngestFromVerdict(
                $ledgerRow,
                [
                    'input_class' => (string) data_get($signals, 'signal_sources.input_class', $signals['claim_type'] ?? ''),
                    'matched_signals' => array_values(array_map('strval', (array) data_get($signals, 'signal_sources.matched_signals', []))),
                ],
            );
        }

        return $payload;
    }

    /**
     * @param  array<string,mixed>  $contentIntelligence
     * @param  array<string,mixed>  $metadata
     * @param  array<string,mixed>  $data
     * @return array<string,mixed>
     */
    private function cognitiveImmuneAuditSignals(array $contentIntelligence, array $metadata, array $data, string $domain, ?string $contentHash): array
    {
        $text = is_scalar($data['content_text'] ?? null) ? trim((string) $data['content_text']) : '';
        $classification = $this->immuneInputClassifier->classify($text, $metadata);
        $privacyClass = $this->scalarString(data_get($metadata, 'privacy.sensitivity'))
            ?? $this->scalarString($metadata['sensitivity'] ?? null)
            ?? 'normal';
        $externalAiAllowed = data_get($metadata, 'privacy.external_ai_allowed');
        $externalAiAllowed = is_bool($externalAiAllowed) ? $externalAiAllowed : true;
        $containsSecret = $this->metadataFlag($metadata, 'has_secret_marker')
            || $this->metadataFlag($metadata, 'contains_secret')
            || $privacyClass === 'secret';
        $containsSensitiveUnnecessary = ! $containsSecret
            && (in_array($privacyClass, ['private', 'sensitive'], true)
                || $classification['input_class'] === 'private_sensitive');
        $providerSafe = $externalAiAllowed
            && ! $containsSecret
            && ! $containsSensitiveUnnecessary;
        if ($this->metadataExplicitlyFalse($metadata, 'provider_safe')) {
            $providerSafe = false;
        }

        return [
            'consent_granted' => ! $this->metadataExplicitlyFalse($metadata, 'consent_granted'),
            'privacy_class' => $privacyClass,
            'retention_ok' => true,
            'atomic_claim_present' => $text !== '' && $contentHash !== null,
            'claim_type' => $classification['input_class'],
            'claim_source_present' => $contentHash !== null,
            'future_utility' => (bool) $classification['memory_eligible'] || (float) data_get($contentIntelligence, 'quality.score', 0.0) >= 50.0,
            'novelty' => $contentHash !== null,
            'recurrence_count' => $this->intMetadata($metadata, 'recurrence_count'),
            'provider_safe' => $providerSafe,
            'contains_secret' => $containsSecret,
            'contains_sensitive_unnecessary' => $containsSensitiveUnnecessary,
            'contradicts_newer' => $this->metadataFlag($metadata, 'contradicts_newer')
                || $this->metadataFlag($metadata, 'immune.contradicts_newer'),
            'outcome_validated' => false,
            'scope' => 'domain',
            'promotion_mode_hint' => 'proposal',
            'on_probation' => true,
            'probation_watch_age_days' => $this->intMetadata($metadata, 'probation_watch_age_days'),
            'probation_recall_actor_counts' => (array) data_get($metadata, 'probation_recall_actor_counts', []),
            'probation_negative_feedback_count' => $this->intMetadata($metadata, 'probation_negative_feedback_count'),
            'probation_supervening_contradiction_count' => $this->intMetadata($metadata, 'probation_supervening_contradiction_count'),
            'signal_sources' => [
                'input_classifier_schema_version' => $classification['schema_version'],
                'input_class' => $classification['input_class'],
                'matched_signals' => $classification['matched_signals'],
                'source_domain' => $domain,
            ],
        ];
    }

    /**
     * @param  array<string,mixed>  $signals
     * @return list<string>
     */
    private function expectedImmuneBlockGateIds(array $signals): array
    {
        $expected = [];
        if (($signals['contains_secret'] ?? false) === true
            || ($signals['contains_sensitive_unnecessary'] ?? false) === true
            || (array_key_exists('provider_safe', $signals) && $signals['provider_safe'] === false)) {
            $expected['G3'] = true;
        }
        if (($signals['contradicts_newer'] ?? false) === true) {
            $expected['G4'] = true;
        }

        return array_keys($expected);
    }

    /**
     * @param  array<string,mixed>  $contentIntelligence
     * @param  array<string,mixed>  $data
     * @return array<string,mixed>
     */
    private function cognitiveImmuneAudit(array $contentIntelligence, array $data, string $domain, string $contentHash): array
    {
        $contentType = (string) ($contentIntelligence['content_type'] ?? 'unknown');
        $destination = (string) data_get($contentIntelligence, 'destination.enum', 'unknown');
        $qualityScore = (float) data_get($contentIntelligence, 'quality.score', 0.0);
        $sourceKind = is_scalar($data['kind'] ?? null) ? (string) $data['kind'] : 'unknown';
        $trivial = $qualityScore < 0.35 || in_array($destination, ['discard', 'none'], true);

        return [
            'schema_version' => 'atlas.capture.cognitive_immune_audit.v1',
            'status' => 'quarantined',
            'master_invariant' => 'raw_capture_not_evidence_not_learning_signal_not_memory_not_context_not_decision',
            'raw_capture_is_memory' => false,
            'raw_capture_is_context' => false,
            'raw_capture_is_decision' => false,
            'learning_signal_allowed_now' => false,
            'memory_promotion_allowed_now' => false,
            'context_export_allowed_now' => false,
            'constellation_promotion_allowed_now' => false,
            'embedding_allowed_now' => false,
            'noise_gate' => [
                'status' => $trivial ? 'likely_noise_or_low_signal' : 'candidate_requires_review',
                'content_type' => $contentType,
                'destination' => $destination,
                'quality_score' => $qualityScore,
                'source_kind' => $sourceKind,
                'domain' => $domain,
            ],
            'promotion_gates' => [
                'g0_capture' => 'captured_quarantined',
                'g1_extraction' => 'pending_human_or_semantic_review',
                'g2_signal' => 'pending',
                'g3_safety' => 'provider_export_blocked',
                'g4_contradiction' => 'not_checked',
                'g5_outcome' => 'not_validated',
                'g6_scope' => 'domain_recorded',
                'g7_promotion_mode' => 'proposal_or_block',
                'g8_probation' => 'not_started',
            ],
            'audit_hash' => hash('sha256', implode('|', [
                $contentHash,
                $contentType,
                $destination,
                (string) $qualityScore,
                $domain,
            ])),
        ];
    }

    /**
     * @param  array<string,mixed>  $metadata
     * @param  array<string,mixed>  $data
     * @param  array<string,mixed>|null  $storedFile
     * @return array<string,mixed>
     */
    private function contentIntelligenceContract(array $metadata, array $data, ?array $storedFile, string $domain, ?string $contentHash, string $now): array
    {
        $kind = is_scalar($data['kind'] ?? null) ? (string) $data['kind'] : 'unknown';
        $contentType = $this->contentTypeFor($kind, $storedFile);
        $quality = $this->captureQualityScore($data, $storedFile, $contentHash, $domain);
        $destinationEnum = $this->initialDestinationEnum($metadata);
        $language = $this->scalarString($metadata['language'] ?? data_get($metadata, 'source.language'));

        return [
            'schema_version' => 'atlas.capture.content_intelligence.v1',
            'status' => 'candidate_pending_review',
            'content_type' => $contentType,
            'source_type' => 'operator_capture',
            'source_domain' => $domain,
            'source_business_context' => $domain,
            'business_context_defaulted' => false,
            'blackink_defaulted' => false,
            'destination' => [
                'enum' => $destinationEnum,
                'allowed' => [
                    'semantic_note',
                    'task',
                    'project',
                    'archive',
                    'discard',
                    'atlas_memory_candidate',
                    'weak_archive',
                    'benchmark_case',
                ],
                'decision' => 'proposal_required_before_promotion',
            ],
            'quality' => [
                'score' => $quality['score'],
                'label' => $quality['label'],
                'reasons' => $quality['reasons'],
            ],
            'source_refs' => [
                'extractor_version' => 'atlas.capture.content_intelligence.v1',
                'capture_client_id_hash' => is_scalar($data['client_id'] ?? null) ? hash('sha256', (string) $data['client_id']) : null,
                'content_hash' => $contentHash,
                'file_sha256' => $this->scalarString($storedFile['sha256'] ?? ($data['content_sha256'] ?? null)),
                'mime_type' => $this->scalarString($storedFile['mime_type'] ?? null),
                'language' => $language,
                'captured_at' => $this->scalarString($data['captured_at'] ?? null),
                'captured_timezone' => $this->scalarString($data['captured_timezone'] ?? null),
                'indexed_at' => $now,
            ],
            'dedupe' => [
                'memory_check' => 'pending_review',
                'graph_rag_check' => 'blocked_until_external_graph_runtime_is_approved',
                'duplicate_policy' => 'hash_first_no_raw_content_export',
            ],
            'privacy' => [
                'provider_export_allowed' => false,
                'embedding_allowed' => false,
                'open_brain_context_allowed' => false,
                'memory_write_allowed' => false,
                'raw_content_exposed' => false,
            ],
            'promotion' => [
                'requires_proposal' => true,
                'requires_human_review' => true,
                'automatic_memory_promotion_allowed' => false,
            ],
        ];
    }

    /**
     * @param  array<string,mixed>|null  $storedFile
     */
    private function contentTypeFor(string $kind, ?array $storedFile): string
    {
        $mime = $this->scalarString($storedFile['mime_type'] ?? null);

        if ($mime && str_starts_with($mime, 'audio/')) {
            return 'audio';
        }
        if ($mime && str_starts_with($mime, 'image/')) {
            return 'image';
        }
        if ($mime === 'application/pdf') {
            return 'pdf';
        }

        return match ($kind) {
            'audio' => 'audio',
            'image' => 'image',
            'file' => 'file',
            default => 'text',
        };
    }

    /**
     * @param  array<string,mixed>  $metadata
     */
    private function initialDestinationEnum(array $metadata): string
    {
        $destination = $this->scalarString(data_get($metadata, 'semantic_clarification.result.possible_destination.destination'))
            ?? $this->scalarString(data_get($metadata, 'semantic_clarification.result.possible_destination.kind'))
            ?? $this->scalarString(data_get($metadata, 'triage.destination'));

        return match ($destination) {
            'task' => 'task',
            'project' => 'project',
            'archive' => 'archive',
            'discard' => 'discard',
            default => 'semantic_note',
        };
    }

    /**
     * @param  array<string,mixed>  $data
     * @param  array<string,mixed>|null  $storedFile
     * @return array{score:int,label:string,reasons:array<int,string>}
     */
    private function captureQualityScore(array $data, ?array $storedFile, ?string $contentHash, string $domain): array
    {
        $score = 20;
        $reasons = ['raw_capture_quarantined_before_promotion'];
        $text = is_scalar($data['content_text'] ?? null) ? trim((string) $data['content_text']) : '';

        if ($text !== '') {
            $score += 30;
            $reasons[] = 'text_content_present';
            $length = mb_strlen($text);
            if ($length >= 80) {
                $score += 15;
                $reasons[] = 'sufficient_text_density';
            } elseif ($length < 20) {
                $score -= 10;
                $reasons[] = 'short_text_low_density';
            }
        }

        if ($storedFile !== null) {
            $score += 20;
            $reasons[] = 'file_source_present';
        }

        if ($contentHash) {
            $score += 15;
            $reasons[] = 'content_hash_recorded';
        }

        if ($domain !== '') {
            $score += 10;
            $reasons[] = 'source_domain_declared';
        }

        $score = max(0, min(100, $score));

        return [
            'score' => $score,
            'label' => match (true) {
                $score >= 75 => 'high',
                $score >= 50 => 'medium',
                default => 'low',
            },
            'reasons' => array_values(array_unique($reasons)),
        ];
    }

    private function scalarString(mixed $value): ?string
    {
        if (! is_scalar($value)) {
            return null;
        }

        $value = trim((string) $value);

        return $value === '' ? null : $value;
    }

    /**
     * @param  array<string,mixed>  $metadata
     */
    private function metadataFlag(array $metadata, string $key): bool
    {
        return data_get($metadata, $key) === true;
    }

    /**
     * @param  array<string,mixed>  $metadata
     */
    private function metadataExplicitlyFalse(array $metadata, string $key): bool
    {
        return data_get($metadata, $key) === false;
    }

    /**
     * @param  array<string,mixed>  $metadata
     */
    private function intMetadata(array $metadata, string $key): int
    {
        $value = data_get($metadata, $key, 0);

        return is_int($value) ? max(0, $value) : max(0, (int) $value);
    }

    /**
     * @param  array<string,mixed>  $data
     * @param  array<string,mixed>|null  $storedFile
     */
    private function captureContentHash(array $data, ?array $storedFile): ?string
    {
        if (is_scalar($storedFile['sha256'] ?? null)) {
            return (string) $storedFile['sha256'];
        }

        if (is_scalar($data['content_sha256'] ?? null)) {
            return (string) $data['content_sha256'];
        }

        if (is_scalar($data['content_text'] ?? null) && trim((string) $data['content_text']) !== '') {
            return hash('sha256', (string) $data['content_text']);
        }

        return null;
    }

    /**
     * @return array{redacted:bool,sha256:?string,present:bool}
     */
    private function redactedCaptureTextEvidence(?string $content): array
    {
        $present = is_string($content) && $content !== '';

        return [
            'redacted' => true,
            'sha256' => $present ? hash('sha256', $content) : null,
            'present' => $present,
        ];
    }

    /**
     * @param  array<string,mixed>  $data
     */
    private function recordCaptureReplay(Capture $capture, array $data): void
    {
        $this->audit->record('capture_replayed', [
            'subject_type' => 'capture',
            'subject_id' => $capture->id,
            'summary' => "Captura {$capture->kind} reaproveitada por idempotencia.",
            'evidence' => [
                'schema_version' => 'atlas.capture.ingest_replay_receipt.v1',
                'created' => false,
                'replay_reason' => 'client_id_already_exists',
                'capture_id' => $capture->id,
                'capture_client_id_hash' => is_scalar($data['client_id'] ?? null) ? hash('sha256', (string) $data['client_id']) : null,
                'content_fingerprint' => $this->redactedCaptureTextEvidence($capture->content_text),
                'content_hash' => data_get($capture->metadata, 'cognitive_quarantine.content_hash')
                    ?? $capture->content_sha256
                    ?? (is_string($capture->content_text) && $capture->content_text !== '' ? hash('sha256', $capture->content_text) : null),
                'cognitive_quarantine' => data_get($capture->metadata, 'cognitive_quarantine'),
                'raw_content_exposed' => false,
            ],
            'privacy' => $this->capturePrivacy($capture),
            'refs' => [
                'capture_id' => $capture->id,
                'capture_client_id' => $capture->client_id,
            ],
            'metadata' => [
                'schema_version' => 'atlas.capture.ingest_replay_receipt.v1',
            ],
        ]);
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

                $delta = $this->memoryDeltas->proposeForCapture($capture->refresh(), [
                    'proposal_id' => $proposal?->id,
                    'memory_type' => $action === 'create_hypothesis' ? 'technical_context' : null,
                ]);
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
                'memory_delta_id' => isset($delta) ? $delta?->id : null,
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
            if (isset($delta) && $delta) {
                $metadata['triage']['memory_delta_id'] = $delta->id;
                $metadata['triage']['memory_delta_status'] = $delta->status;
            }
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
                    'memory_delta_id' => isset($delta) ? $delta?->id : null,
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
        $this->attachSemanticCurationReview($capture, $source, $proposal?->id, $proposal?->status);

        $this->activateForCapture($capture, $source, $proposal?->id);

        return $capture->refresh();
    }

    private function attachSemanticCurationReview(Capture $capture, string $source, ?string $proposalId, ?string $proposalStatus): void
    {
        if (! $proposalId) {
            return;
        }

        $metadata = is_array($capture->metadata) ? $capture->metadata : [];
        $cognitiveQuarantine = is_array($metadata['cognitive_quarantine'] ?? null)
            ? $metadata['cognitive_quarantine']
            : [];
        $review = is_array($cognitiveQuarantine['review'] ?? null)
            ? $cognitiveQuarantine['review']
            : [];

        $metadata['semantic_curation'] = [
            'schema_version' => 'atlas.capture.semantic_curation_review.v1',
            'status' => 'proposal_pending',
            'source' => $source,
            'proposal_id' => $proposalId,
            'proposal_status' => $proposalStatus ?? 'pending',
            'human_gate' => 'ratify_or_dismiss',
            'next_action' => 'ratify_proposal',
            'updated_at' => now()->toJSON(),
        ];
        $metadata['cognitive_quarantine'] = [
            ...$cognitiveQuarantine,
            'promotion_status' => 'proposal_pending',
            'proposal' => [
                'proposal_id' => $proposalId,
                'proposal_status' => $proposalStatus ?? 'pending',
            ],
            'review' => [
                ...$review,
                'required' => true,
                'status' => 'pending',
                'reason' => 'semantic_curation_proposal_requires_operator_review',
            ],
            'updated_at' => now()->toJSON(),
        ];

        $capture->update([
            'metadata' => Metadata::forStorage($metadata),
        ]);
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

        if (! DatabaseTableAvailability::has('capture_links')) {
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
        if (DatabaseTableAvailability::has('capture_links')) {
            $capture->load('links');
        }

        return $capture;
    }
}
