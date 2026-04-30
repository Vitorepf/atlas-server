<?php

namespace App\Services\Semantic;

use App\Models\Capture;
use App\Models\SemanticCurationProposal;
use App\Models\SemanticNote;
use App\Services\AuditLogService;
use App\Services\CaptureDestinationService;
use App\Support\Metadata;
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
                'raw_source' => $text,
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
            ],
            'privacy' => $this->privacyFromProposal($proposal),
            'refs' => [
                'proposal_id' => $proposal->id,
                'semantic_note_id' => $result['note']->id,
            ],
        ]);

        $captureId = data_get($proposal->source_refs, 'capture_id');
        $capture = is_string($captureId) ? Capture::query()->find($captureId) : null;
        if ($capture) {
            $this->destinations->linkAcceptedNote($capture, $result['note'], $proposal);
        }

        return $result['note'];
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
