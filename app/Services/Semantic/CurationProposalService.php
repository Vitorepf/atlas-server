<?php

namespace App\Services\Semantic;

use App\Models\AiMemoryDelta;
use App\Models\Capture;
use App\Models\SemanticCurationProposal;
use App\Models\SemanticNote;
use App\Services\Ai\AiMemoryDeltaProposer;
use App\Services\Ai\AtlasMemoryDeltaPromotionService;
use App\Services\Ai\AtlasVerbatimMemoryService;
use App\Services\AuditLogService;
use App\Services\CaptureDestinationService;
use App\Support\Metadata;
use Illuminate\Support\Facades\Schema;
use Illuminate\Support\Str;

class CurationProposalService
{
    public function __construct(
        private readonly VaultFileStore $vault,
        private readonly FrontmatterParser $parser,
        private readonly SemanticNoteIndexer $indexer,
        private readonly CaptureSemanticClarifier $clarifier,
        private readonly SemanticLinkService $links,
        private readonly AuditLogService $audit,
        private readonly CaptureDestinationService $destinations,
        private readonly AiMemoryDeltaProposer $memoryDeltas,
        private readonly AtlasMemoryDeltaPromotionService $memoryPromoter,
        private readonly AtlasVerbatimMemoryService $verbatim,
    ) {}

    public function scanRecentCaptures(?string $since = null): array
    {
        $query = Capture::query()
            ->whereNull('deleted_at')
            ->whereNotNull('content_text')
            ->whereRaw("TRIM(COALESCE(content_text, '')) <> ''")
            ->orderByDesc('captured_at')
            ->limit(100);

        if ($since) {
            $query->where('captured_at', '>=', $since);
        } else {
            $query->where('captured_at', '>=', now()->subDay());
        }

        $created = 0;
        $skipped = 0;
        foreach ($query->get() as $capture) {
            $capture = $this->clarifier->handleReady($capture, 'semantic_scan');

            if (! $this->clarifier->shouldPropose($capture)) {
                $skipped++;

                continue;
            }

            if ($this->createFromCapture($capture)) {
                $created++;
            } else {
                $skipped++;
            }
        }

        return compact('created', 'skipped');
    }

    public function findForCapture(Capture $capture): ?SemanticCurationProposal
    {
        return SemanticCurationProposal::query()
            ->where('source_type', 'capture')
            ->orderByDesc('created_at')
            ->get()
            ->first(function (SemanticCurationProposal $proposal) use ($capture): bool {
                return ($proposal->source_refs['capture_id'] ?? null) === $capture->id;
            });
    }

    public function createFromCapture(Capture $capture, array $overrides = []): ?SemanticCurationProposal
    {
        $existing = $this->findForCapture($capture);

        if ($existing || ! $capture->content_text) {
            return null;
        }

        $capture = $this->clarifier->handleReady($capture, 'curation_proposal');
        if (! ($overrides['force'] ?? false) && ! $this->clarifier->shouldPropose($capture)) {
            return null;
        }

        $text = trim($capture->content_text);
        $clarification = $this->clarifier->resultFor($capture) ?? [];
        $destination = $clarification['possible_destination'] ?? [];
        $density = $clarification['density'] ?? [];
        $privacy = is_array(data_get($capture->metadata, 'privacy'))
            ? data_get($capture->metadata, 'privacy')
            : [
                'domain' => $capture->domain,
                'sensitivity' => data_get($capture->metadata, 'sensitivity', 'normal'),
            ];
        $cognitiveQuarantine = $this->proposalCognitiveQuarantine($capture);
        $contentIntelligence = $this->proposalContentIntelligence($capture);

        $title = $overrides['title']
            ?? ($destination['title'] ?? null)
            ?? $this->titleFromText($text);
        $type = $overrides['type']
            ?? ($clarification['suggested_type'] ?? null)
            ?? $this->inferType($text);
        $path = $this->pathForType($type, $title);
        $summary = $clarification['main_thesis']
            ?? Str::limit(preg_replace('/\s+/', ' ', $text) ?? $text, 240, '');
        $noteKey = 'note_'.substr(hash('sha256', $capture->id.':'.$text), 0, 16);
        $template = $this->livingTemplate($capture, $text, $clarification, $title, $type);
        $frontmatter = [
            'id' => $noteKey,
            'type' => $type,
            'title' => $title,
            'status' => $type === 'hypothesis' ? 'testing' : 'active',
            'confidence' => 'low',
            'maturity' => 'seed',
            'domains' => [$capture->domain],
            'summary' => $summary,
            'thesis' => $template['thesis'],
            'own_interpretation' => $template['own_interpretation'],
            'evidence_origin' => $template['evidence_origin'],
            'next_action' => $template['next_action'],
            'raw_source' => $template['raw_source'],
            'privacy' => $privacy,
            'when_to_use' => $template['when_to_use'],
            'trigger_signals' => $clarification['future_triggers'] ?? $this->inferTriggers($text, $capture->domain),
            'do_not_use_when' => $template['when_not_to_use'],
            'practice_prompt' => $type === 'practice' ? $summary : null,
            'source_type' => 'capture',
            'source_refs' => ['capture_id' => $capture->id, 'capture_client_id' => $capture->client_id],
            'postgres_refs' => ['captures' => [$capture->id]],
            'cognitive_quarantine' => $cognitiveQuarantine,
            'content_intelligence' => $contentIntelligence,
            'ratification' => [
                'required' => true,
                'proposed_by' => 'atlas_aclarador',
                'ratified_by_operator' => false,
            ],
            'semantic_clarification' => [
                'main_thesis' => $clarification['main_thesis'] ?? null,
                'atomic_ideas' => $clarification['atomic_ideas'] ?? [],
                'tension_or_question' => $clarification['tension_or_question'] ?? null,
                'authorship_question' => $clarification['authorship_question'] ?? null,
                'density' => $density,
            ],
            'created_at' => now()->toJSON(),
            'updated_at' => now()->toJSON(),
        ];

        $proposal = SemanticCurationProposal::create([
            'source_type' => 'capture',
            'source_refs' => Metadata::forStorage(['capture_id' => $capture->id, 'capture_client_id' => $capture->client_id]),
            'proposed_note_type' => $type,
            'proposed_title' => $title,
            'proposed_summary' => $summary,
            'proposed_path' => $path,
            'proposed_frontmatter' => Metadata::forStorage($frontmatter),
            'proposed_body' => $this->bodyFromCapture($capture, $text, $clarification, $template),
            'score' => isset($density['score']) ? round((float) $density['score'], 3) : $this->scoreText($text),
            'reason' => $overrides['reason']
                ?? ($destination['reason'] ?? 'Captura aclarada semanticamente e pronta para revisao humana.'),
            'metadata' => Metadata::forStorage([
                'created_by' => 'curation-proposal-v2',
                'proposal_template' => 'living-curation-v1',
                'ratification_required' => true,
                'ratified_by_operator' => false,
                'semantic_clarification' => $clarification,
                'privacy' => $privacy,
                'cognitive_quarantine' => $cognitiveQuarantine,
                'content_intelligence' => $contentIntelligence,
                ...($overrides['metadata'] ?? []),
            ]),
        ]);

        $this->audit->record('curation_proposal_created', [
            'subject_type' => 'semantic_curation_proposal',
            'subject_id' => $proposal->id,
            'summary' => "Proposta de curadoria criada: {$title}.",
            'evidence' => [
                'capture_id' => $capture->id,
                'main_thesis' => $summary,
                'suggested_type' => $type,
                'density' => $density,
                'reason' => $proposal->reason,
                'raw_source' => $this->redactedSourceEvidence($text),
                'cognitive_quarantine' => $cognitiveQuarantine,
                'content_intelligence' => $contentIntelligence,
            ],
            'privacy' => $privacy,
            'refs' => [
                'capture_id' => $capture->id,
                'proposal_id' => $proposal->id,
            ],
        ]);

        return $proposal;
    }

    public function accept(SemanticCurationProposal $proposal, array $edits = []): SemanticNote
    {
        $frontmatter = [
            ...($proposal->proposed_frontmatter ?? []),
            ...($edits['frontmatter_edits'] ?? []),
        ];
        $frontmatter['updated_at'] = now()->toJSON();
        $frontmatter['status'] = $frontmatter['status'] ?? ($proposal->proposed_note_type === 'hypothesis' ? 'testing' : 'active');
        $existingRatification = is_array($frontmatter['ratification'] ?? null)
            ? $frontmatter['ratification']
            : [];
        $frontmatter['ratification'] = [
            ...$existingRatification,
            'required' => false,
            'ratified_by_operator' => true,
            'ratified_by' => 'vitor',
            'ratified_at' => now()->toJSON(),
        ];
        $body = $edits['body_edits'] ?? $proposal->proposed_body ?? '';
        $path = $edits['path'] ?? $proposal->proposed_path ?? $this->pathForType($proposal->proposed_note_type, $proposal->proposed_title);
        $markdown = $this->parser->build($frontmatter, $body);
        $actualPath = $this->vault->writeDraft($path, $markdown);
        $result = $this->indexer->indexFile($actualPath);
        $linkStats = $this->links->suggestFor($result['note']);

        $memoryPromotion = null;
        $verbatimPromotion = null;
        $proposal->update([
            'status' => isset($edits['frontmatter_edits']) || isset($edits['body_edits']) ? 'edited' : 'accepted',
            'resolved_at' => now(),
            'metadata' => Metadata::forStorage([
                ...($proposal->metadata ?? []),
                'accepted_path' => $actualPath,
                'semantic_note_id' => $result['note']->id,
                'ratified_by_operator' => true,
                'ratified_by' => 'vitor',
                'ratified_at' => now()->toJSON(),
                'link_suggestions' => $linkStats,
            ]),
        ]);

        if ((bool) ($edits['promote_to_memory'] ?? false)) {
            $memoryPromotion = $this->promoteRatifiedProposalMemory($proposal->refresh(), $result['note'], $edits);
        }
        if ((bool) ($edits['promote_to_verbatim'] ?? false)) {
            $verbatimPromotion = $this->promoteRatifiedProposalVerbatim($proposal->refresh(), $result['note'], $edits);
        }

        $this->audit->record('curation_proposal_ratified', [
            'subject_type' => 'semantic_curation_proposal',
            'subject_id' => $proposal->id,
            'actor_type' => 'operator',
            'actor_id' => 'vitor',
            'summary' => "Proposta ratificada como nota viva: {$result['note']->title}.",
            'evidence' => [
                'note_id' => $result['note']->id,
                'path' => $actualPath,
                'link_suggestions' => $linkStats,
                'memory_promotion' => $memoryPromotion,
                'verbatim_promotion' => $verbatimPromotion,
            ],
            'privacy' => $this->privacyFromProposal($proposal),
            'refs' => [
                'proposal_id' => $proposal->id,
                'semantic_note_id' => $result['note']->id,
                'memory_entry_id' => $memoryPromotion['memory_entry_id'] ?? null,
                'verbatim_memory_id' => $verbatimPromotion['verbatim_memory_id'] ?? null,
            ],
        ]);

        $captureId = data_get($proposal->source_refs, 'capture_id');
        $capture = is_string($captureId) ? Capture::query()->find($captureId) : null;
        if ($capture) {
            $this->destinations->linkAcceptedNote($capture, $result['note'], $proposal);
        }

        return $result['note'];
    }

    /**
     * @param  array<string,mixed>  $edits
     * @return array<string,mixed>
     */
    private function promoteRatifiedProposalVerbatim(SemanticCurationProposal $proposal, SemanticNote $note, array $edits): array
    {
        $capture = $this->captureForProposal($proposal);
        $text = is_string($edits['verbatim_text'] ?? null) && trim((string) $edits['verbatim_text']) !== ''
            ? trim((string) $edits['verbatim_text'])
            : trim((string) ($capture?->content_text ?: $proposal->proposed_body ?: ''));
        if ($text === '') {
            throw new \RuntimeException('Nao ha texto exato para promover ao Verbatim Store.');
        }

        $receipt = $this->verbatimPromotionReceipt($proposal, $note, $capture, $edits, $text);
        $memory = $this->verbatim->record([
            'verbatim_type' => $edits['verbatim_type'] ?? 'evidence',
            'scope_type' => $edits['verbatim_scope_type'] ?? ($edits['scope_type'] ?? 'global'),
            'scope_id' => $edits['verbatim_scope_id'] ?? ($edits['scope_id'] ?? null),
            'title' => $edits['verbatim_title'] ?? 'Evidencia ratificada: '.$proposal->proposed_title,
            'verbatim_text' => $text,
            'summary' => $edits['verbatim_summary'] ?? $proposal->proposed_summary,
            'privacy_class' => $edits['verbatim_privacy_class'] ?? $this->verbatimPrivacyClass($proposal),
            'external_ai_allowed' => $edits['verbatim_external_ai_allowed'] ?? false,
            'source_type' => $capture ? 'capture' : 'semantic_curation_proposal',
            'source_id' => $capture?->id ?? $proposal->id,
            'source_label' => 'Semantic curation proposal',
            'tags' => ['semantic_curation_proposal', 'ratified_capture', 'semantic_note:'.$note->type],
            'metadata' => [
                'promotion_receipt' => $receipt,
                'semantic_note_id' => $note->id,
                'semantic_note_path' => $note->path,
                'capture_id' => $capture?->id,
            ],
        ]);

        $payload = [
            ...$receipt,
            'verbatim_memory_id' => $memory->id,
            'memory_entry_id' => $memory->memory_entry_id,
            'verbatim_type' => $memory->verbatim_type,
            'privacy_class' => $memory->privacy_class,
            'external_ai_allowed' => $memory->external_ai_allowed,
            'redaction_status' => $memory->redaction_status,
            'status' => 'promoted',
        ];

        $proposal->update([
            'metadata' => Metadata::forStorage([
                ...($proposal->metadata ?? []),
                'verbatim_promotion' => $payload,
            ]),
        ]);

        if ($capture) {
            $metadata = is_array($capture->metadata) ? $capture->metadata : [];
            $metadata['verbatim_promotion'] = $payload;
            $capture->forceFill(['metadata' => Metadata::forStorage($metadata)])->save();
        }

        return $payload;
    }

    /**
     * @param  array<string,mixed>  $edits
     * @return array<string,mixed>
     */
    private function promoteRatifiedProposalMemory(SemanticCurationProposal $proposal, SemanticNote $note, array $edits): array
    {
        $capture = $this->captureForProposal($proposal);
        $delta = $this->deltaForProposal($proposal, $capture);
        if (! $delta && $capture) {
            $delta = $this->memoryDeltas->proposeForCapture($capture, [
                'proposal_id' => $proposal->id,
                'memory_type' => $edits['memory_type'] ?? $this->memoryTypeForProposal($proposal),
            ]);
        }

        if (! $delta) {
            throw new \RuntimeException('Nao ha memory delta para promover esta proposta ratificada.');
        }

        if ($delta->status === 'pending') {
            $delta->forceFill(['status' => 'accepted'])->save();
        }

        $receipt = $this->promotionReceipt($proposal, $note, $delta, $capture, $edits);
        $entry = $this->memoryPromoter->promote($delta->refresh(), [
            'memory_type' => $edits['memory_type'] ?? $this->memoryTypeForProposal($proposal),
            'scope_type' => $edits['scope_type'] ?? 'global',
            'scope_id' => $edits['scope_id'] ?? null,
            'title' => 'Memoria ratificada: '.$proposal->proposed_title,
            'summary' => $proposal->proposed_summary,
            'promoted_by' => $edits['promoted_by'] ?? 'vitor',
            'metadata' => [
                'promotion_receipt' => $receipt,
                'semantic_note_id' => $note->id,
                'semantic_note_path' => $note->path,
                'curation_proposal_id' => $proposal->id,
                'capture_id' => $capture?->id,
            ],
            'tags' => [
                'semantic_curation_proposal',
                'ratified_capture',
                'semantic_note:'.$note->type,
            ],
        ]);

        $payload = [
            ...$receipt,
            'memory_entry_id' => $entry->id,
            'memory_delta_id' => $delta->id,
            'memory_type' => $entry->memory_type,
            'scope_type' => $entry->scope_type,
            'scope_id' => $entry->scope_id,
            'status' => 'promoted',
        ];

        $proposal->update([
            'metadata' => Metadata::forStorage([
                ...($proposal->metadata ?? []),
                'memory_promotion' => $payload,
            ]),
        ]);

        if ($capture) {
            $this->markCaptureMemoryPromoted($capture, $payload);
        }

        return $payload;
    }

    private function captureForProposal(SemanticCurationProposal $proposal): ?Capture
    {
        $captureId = data_get($proposal->source_refs, 'capture_id');

        return is_string($captureId) ? Capture::query()->find($captureId) : null;
    }

    private function deltaForProposal(SemanticCurationProposal $proposal, ?Capture $capture): ?AiMemoryDelta
    {
        if (! Schema::hasTable('ai_memory_deltas')) {
            return null;
        }

        if ($capture) {
            $delta = AiMemoryDelta::query()
                ->where('scope', 'capture:'.$capture->id)
                ->whereIn('status', ['pending', 'accepted', 'promoted'])
                ->latest('updated_at')
                ->first();

            if ($delta) {
                return $delta;
            }
        }

        return AiMemoryDelta::query()
            ->whereIn('status', ['pending', 'accepted', 'promoted'])
            ->latest('updated_at')
            ->get()
            ->first(function (AiMemoryDelta $delta) use ($proposal): bool {
                return collect($delta->evidence ?? [])
                    ->contains(fn (mixed $item): bool => is_array($item) && ($item['proposal_id'] ?? null) === $proposal->id);
            });
    }

    /**
     * @param  array<string,mixed>  $edits
     * @return array<string,mixed>
     */
    private function promotionReceipt(SemanticCurationProposal $proposal, SemanticNote $note, AiMemoryDelta $delta, ?Capture $capture, array $edits): array
    {
        $quarantine = is_array(data_get($proposal->metadata, 'cognitive_quarantine'))
            ? data_get($proposal->metadata, 'cognitive_quarantine')
            : [];

        $receipt = [
            'schema_version' => 'atlas.memory.promotion_receipt.v1',
            'source' => 'semantic_curation_proposal',
            'human_gate' => 'semantic_curation_proposal_accept',
            'ratified_by_operator' => true,
            'promoted_by' => is_scalar($edits['promoted_by'] ?? null) ? (string) $edits['promoted_by'] : 'vitor',
            'proposal_id' => $proposal->id,
            'semantic_note_id' => $note->id,
            'semantic_note_path' => $note->path,
            'capture_id' => $capture?->id,
            'capture_client_id' => $capture?->client_id,
            'memory_delta_id' => $delta->id,
            'content_hash' => is_scalar($quarantine['content_hash'] ?? null) ? (string) $quarantine['content_hash'] : $note->content_hash,
            'privacy' => $this->privacyFromProposal($proposal),
            'quarantine_schema_version' => is_scalar($quarantine['schema_version'] ?? null) ? (string) $quarantine['schema_version'] : null,
            'promoted_at' => now()->toJSON(),
        ];

        return $this->withReceiptHash($receipt, [
            'schema_version',
            'source',
            'human_gate',
            'ratified_by_operator',
            'promoted_by',
            'proposal_id',
            'semantic_note_id',
            'semantic_note_path',
            'capture_id',
            'capture_client_id',
            'memory_delta_id',
            'content_hash',
            'privacy',
            'quarantine_schema_version',
        ]);
    }

    /**
     * @param  array<string,mixed>  $edits
     * @return array<string,mixed>
     */
    private function verbatimPromotionReceipt(SemanticCurationProposal $proposal, SemanticNote $note, ?Capture $capture, array $edits, string $text): array
    {
        $quarantine = is_array(data_get($proposal->metadata, 'cognitive_quarantine'))
            ? data_get($proposal->metadata, 'cognitive_quarantine')
            : [];

        $receipt = [
            'schema_version' => 'atlas.verbatim_memory.promotion_receipt.v1',
            'source' => 'semantic_curation_proposal',
            'human_gate' => 'semantic_curation_proposal_accept',
            'ratified_by_operator' => true,
            'promoted_by' => is_scalar($edits['promoted_by'] ?? null) ? (string) $edits['promoted_by'] : 'vitor',
            'proposal_id' => $proposal->id,
            'semantic_note_id' => $note->id,
            'semantic_note_path' => $note->path,
            'capture_id' => $capture?->id,
            'capture_client_id' => $capture?->client_id,
            'content_hash' => hash('sha256', $text),
            'privacy' => $this->privacyFromProposal($proposal),
            'quarantine_schema_version' => is_scalar($quarantine['schema_version'] ?? null) ? (string) $quarantine['schema_version'] : null,
            'promoted_at' => now()->toJSON(),
        ];

        return $this->withReceiptHash($receipt, [
            'schema_version',
            'source',
            'human_gate',
            'ratified_by_operator',
            'promoted_by',
            'proposal_id',
            'semantic_note_id',
            'semantic_note_path',
            'capture_id',
            'capture_client_id',
            'content_hash',
            'privacy',
            'quarantine_schema_version',
        ]);
    }

    /**
     * @param  array<string,mixed>  $receipt
     * @param  array<int,string>  $fields
     * @return array<string,mixed>
     */
    private function withReceiptHash(array $receipt, array $fields): array
    {
        $hashPayload = collect($fields)
            ->mapWithKeys(fn (string $field): array => [$field => $receipt[$field] ?? null])
            ->all();

        return [
            ...$receipt,
            'receipt_hash_algorithm' => 'sha256',
            'receipt_hash_fields' => $fields,
            'receipt_hash' => hash('sha256', json_encode($hashPayload, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE) ?: ''),
        ];
    }

    private function memoryTypeForProposal(SemanticCurationProposal $proposal): string
    {
        return match ($proposal->proposed_note_type) {
            'hypothesis', 'synthesis', 'principle', 'mental_model', 'source_note' => 'technical_context',
            'decision_identity' => 'decision',
            default => 'technical_context',
        };
    }

    private function verbatimPrivacyClass(SemanticCurationProposal $proposal): string
    {
        $class = data_get($this->privacyFromProposal($proposal), 'class')
            ?? data_get($this->privacyFromProposal($proposal), 'privacy_class')
            ?? data_get($this->privacyFromProposal($proposal), 'sensitivity')
            ?? 'normal';

        return in_array($class, ['normal', 'private', 'sensitive', 'secret'], true) ? (string) $class : 'normal';
    }

    /**
     * @param  array<string,mixed>  $promotion
     */
    private function markCaptureMemoryPromoted(Capture $capture, array $promotion): void
    {
        $metadata = is_array($capture->metadata) ? $capture->metadata : [];
        $metadata['memory_promotion'] = $promotion;
        if (is_array($metadata['triage'] ?? null)) {
            $metadata['triage']['memory_delta_status'] = 'promoted';
            $metadata['triage']['promoted_memory_entry_id'] = $promotion['memory_entry_id'] ?? null;
        }

        $capture->forceFill(['metadata' => Metadata::forStorage($metadata)])->save();
    }

    public function dismiss(SemanticCurationProposal $proposal): void
    {
        $proposal->update(['status' => 'dismissed', 'resolved_at' => now()]);
        $this->audit->record('curation_proposal_dismissed', [
            'subject_type' => 'semantic_curation_proposal',
            'subject_id' => $proposal->id,
            'actor_type' => 'operator',
            'actor_id' => 'vitor',
            'summary' => "Proposta descartada: {$proposal->proposed_title}.",
            'evidence' => [
                'status' => 'dismissed',
                'reason' => $proposal->reason,
            ],
            'privacy' => $this->privacyFromProposal($proposal),
            'refs' => ['proposal_id' => $proposal->id],
        ]);
    }

    public function postpone(SemanticCurationProposal $proposal): void
    {
        $proposal->update(['status' => 'postponed', 'shown_at' => now()]);
        $this->audit->record('curation_proposal_postponed', [
            'subject_type' => 'semantic_curation_proposal',
            'subject_id' => $proposal->id,
            'actor_type' => 'operator',
            'actor_id' => 'vitor',
            'summary' => "Proposta adiada: {$proposal->proposed_title}.",
            'evidence' => ['status' => 'postponed'],
            'privacy' => $this->privacyFromProposal($proposal),
            'refs' => ['proposal_id' => $proposal->id],
        ]);
    }

    private function titleFromText(string $text): string
    {
        $firstLine = trim(strtok($text, "\n") ?: $text);
        $firstLine = preg_replace('/^(eu acho que|acho que|percebi que|ideia:)\s+/iu', '', $firstLine) ?? $firstLine;

        return Str::headline(Str::limit($firstLine, 72, ''));
    }

    /**
     * @return array<string,mixed>
     */
    private function proposalCognitiveQuarantine(Capture $capture): array
    {
        $source = is_array(data_get($capture->metadata, 'cognitive_quarantine'))
            ? data_get($capture->metadata, 'cognitive_quarantine')
            : [];

        return [
            ...$source,
            'schema_version' => 'atlas.capture.curation_proposal_quarantine.v1',
            'raw_capture' => false,
            'memory_eligible' => false,
            'context_eligible' => false,
            'constellation_eligible' => false,
            'embedding_allowed' => false,
            'promotion_status' => 'proposal_pending',
            'promotion_target' => 'semantic_curation_proposal',
            'review' => [
                ...(is_array($source['review'] ?? null) ? $source['review'] : []),
                'required' => true,
                'status' => 'pending',
                'reason' => 'semantic_curation_proposal_requires_operator_ratification_before_memory_or_context',
            ],
            'proposal' => [
                'source_type' => 'capture',
                'capture_id' => $capture->id,
                'capture_client_id' => $capture->client_id,
                'created_by' => 'curation-proposal-v2',
            ],
            'updated_at' => now()->toJSON(),
        ];
    }

    /**
     * @return array<string,mixed>
     */
    private function proposalContentIntelligence(Capture $capture): array
    {
        $source = is_array(data_get($capture->metadata, 'content_intelligence'))
            ? data_get($capture->metadata, 'content_intelligence')
            : [];

        return [
            ...$source,
            'schema_version' => 'atlas.capture.content_intelligence.proposal.v1',
            'source_schema_version' => $source['schema_version'] ?? null,
            'status' => 'proposal_pending_review',
            'source_type' => 'capture',
            'capture_id' => $capture->id,
            'capture_client_id_hash' => hash('sha256', $capture->client_id),
            'raw_content_persisted_in_proposal' => false,
            'privacy' => [
                ...(is_array($source['privacy'] ?? null) ? $source['privacy'] : []),
                'provider_export_allowed' => false,
                'embedding_allowed' => false,
                'open_brain_context_allowed' => false,
                'memory_write_allowed' => false,
                'raw_content_exposed' => false,
            ],
            'promotion' => [
                ...(is_array($source['promotion'] ?? null) ? $source['promotion'] : []),
                'requires_human_review' => true,
                'automatic_memory_promotion_allowed' => false,
            ],
            'audit' => [
                'lineage' => 'capture_content_intelligence_to_curation_proposal',
                'projected_at' => now()->toJSON(),
            ],
        ];
    }

    /**
     * @return array{redacted:bool,sha256:string|null,present:bool}
     */
    private function redactedSourceEvidence(string $text): array
    {
        $trimmed = trim($text);

        return [
            'redacted' => true,
            'sha256' => $trimmed !== '' ? hash('sha256', $trimmed) : null,
            'present' => $trimmed !== '',
        ];
    }

    private function inferType(string $text): string
    {
        $lower = mb_strtolower($text);
        if (str_contains($lower, 'hipotese') || str_contains($lower, 'correlacion') || str_contains($lower, 'testar')) {
            return 'hypothesis';
        }
        if (str_contains($lower, 'princípio') || str_contains($lower, 'principio') || str_contains($lower, 'regra')) {
            return 'principle';
        }
        if (str_contains($lower, 'treinar') || str_contains($lower, 'praticar') || str_contains($lower, 'exercício')) {
            return 'practice';
        }

        return 'mental_model';
    }

    private function pathForType(string $type, string $title): string
    {
        $folder = match ($type) {
            'hypothesis' => '04-hipoteses',
            'principle' => '03-principios',
            'practice' => '05-praticas',
            'synthesis' => '08-sinteses',
            'source_note' => '01-acervo/vida',
            default => '02-modelos-mentais',
        };

        return $folder.'/'.Str::slug($title).'.md';
    }

    private function inferWhenToUse(string $text, string $domain): array
    {
        $uses = ["quando estiver lidando com {$domain}"];
        $lower = mb_strtolower($text);
        if (str_contains($lower, 'cliente') || str_contains($lower, 'preço') || str_contains($lower, 'preco')) {
            $uses[] = 'antes de falar com cliente';
        }
        if (str_contains($lower, 'sono') || str_contains($lower, 'cansado')) {
            $uses[] = 'quando sono ou recuperacao estiverem ruins';
        }

        return array_values(array_unique($uses));
    }

    private function inferTriggers(string $text, string $domain): array
    {
        $triggers = ["dominio_{$domain}"];
        $lower = mb_strtolower($text);
        if (str_contains($lower, 'cliente')) {
            $triggers[] = 'call_com_cliente';
        }
        if (str_contains($lower, 'preço') || str_contains($lower, 'preco')) {
            $triggers[] = 'captura_sobre_preco';
        }
        if (str_contains($lower, 'dispers')) {
            $triggers[] = 'estado_disperso';
        }

        return array_values(array_unique($triggers));
    }

    /**
     * @return array{
     *   title:string,
     *   thesis:string,
     *   context:string,
     *   own_interpretation:string,
     *   evidence_origin:string,
     *   when_to_use:array<int, string>,
     *   when_not_to_use:array<int, string>,
     *   next_action:string,
     *   maturity:string,
     *   raw_source:string
     * }
     */
    private function livingTemplate(Capture $capture, string $text, array $clarification, string $title, string $type): array
    {
        $density = data_get($clarification, 'density.label', 'media');
        $authorshipQuestion = (string) ($clarification['authorship_question'] ?? 'Qual ajuste Vitor precisa fazer para assumir autoria desta nota?');
        $tension = (string) ($clarification['tension_or_question'] ?? 'Tensao ainda precisa ser refinada por Vitor.');

        return [
            'title' => $title,
            'thesis' => (string) ($clarification['main_thesis'] ?? $text),
            'context' => "Captura {$capture->kind} no dominio {$capture->domain}, registrada em {$capture->captured_at?->toJSON()}. Densidade semantica estimada: {$density}.",
            'own_interpretation' => "Atlas interpreta esta captura como {$type}: {$tension}",
            'evidence_origin' => "Origem: captura bruta {$capture->id}. A evidencia aqui e a formulacao original de Vitor, nao uma verdade validada.",
            'when_to_use' => $this->inferWhenToUse($text, $capture->domain),
            'when_not_to_use' => [
                'quando faltar validacao humana da interpretacao',
                'quando a situacao exigir dado factual externo que a captura nao trouxe',
            ],
            'next_action' => $authorshipQuestion,
            'maturity' => 'seed',
            'raw_source' => $text,
        ];
    }

    private function bodyFromCapture(Capture $capture, string $text, array $clarification = [], array $template = []): string
    {
        $atomicIdeas = collect($clarification['atomic_ideas'] ?? [])
            ->filter(fn (mixed $idea): bool => is_string($idea) && trim($idea) !== '')
            ->map(fn (string $idea): string => '- '.$idea)
            ->implode("\n");
        $triggers = collect($clarification['future_triggers'] ?? [])
            ->filter(fn (mixed $trigger): bool => is_string($trigger) && trim($trigger) !== '')
            ->map(fn (string $trigger): string => '- '.$trigger)
            ->implode("\n");
        $whenToUse = collect($template['when_to_use'] ?? [])
            ->filter(fn (mixed $item): bool => is_string($item) && trim($item) !== '')
            ->map(fn (string $item): string => '- '.$item)
            ->implode("\n");
        $whenNotToUse = collect($template['when_not_to_use'] ?? [])
            ->filter(fn (mixed $item): bool => is_string($item) && trim($item) !== '')
            ->map(fn (string $item): string => '- '.$item)
            ->implode("\n");

        return implode("\n\n", array_filter([
            "## Titulo\n\n".($template['title'] ?? $capture->id),
            "## Tese\n\n".($template['thesis'] ?? ($clarification['main_thesis'] ?? $text)),
            "## Contexto\n\n".($template['context'] ?? "Captura {$capture->kind} de {$capture->captured_at?->toJSON()}."),
            "## Interpretacao propria\n\n".($template['own_interpretation'] ?? 'Interpretacao pendente de ratificacao humana.'),
            "## Evidencia / origem\n\n".($template['evidence_origin'] ?? "Captura {$capture->id}."),
            $atomicIdeas ? "## Ideias atomicas\n\n{$atomicIdeas}" : null,
            $whenToUse ? "## Quando usar\n\n{$whenToUse}" : null,
            $whenNotToUse ? "## Quando nao usar\n\n{$whenNotToUse}" : null,
            "## Proxima acao\n\n".($template['next_action'] ?? ($clarification['authorship_question'] ?? 'Vitor ratificar ou descartar.')),
            "## Maturidade\n\n".($template['maturity'] ?? 'seed'),
            $triggers ? "## Gatilhos futuros\n\n{$triggers}" : null,
            "## Fonte bruta\n\n".($template['raw_source'] ?? $text),
        ]))."\n";
    }

    private function scoreText(string $text): float
    {
        $length = mb_strlen($text);
        $score = min(0.9, 0.45 + ($length / 1600));
        foreach (['porque', 'hipotese', 'princípio', 'principio', 'modelo', 'aplicar', 'testar'] as $needle) {
            if (str_contains(mb_strtolower($text), $needle)) {
                $score += 0.04;
            }
        }

        return round(min(0.96, $score), 3);
    }

    private function privacyFromProposal(SemanticCurationProposal $proposal): array
    {
        $privacy = data_get($proposal->metadata, 'privacy');

        return is_array($privacy) ? $privacy : [];
    }
}
