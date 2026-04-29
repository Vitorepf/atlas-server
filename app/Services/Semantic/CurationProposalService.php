<?php

namespace App\Services\Semantic;

use App\Models\Capture;
use App\Models\SemanticCurationProposal;
use App\Models\SemanticNote;
use App\Support\Metadata;
use Illuminate\Support\Str;

class CurationProposalService
{
    public function __construct(
        private readonly VaultFileStore $vault,
        private readonly FrontmatterParser $parser,
        private readonly SemanticNoteIndexer $indexer,
    ) {}

    public function scanRecentCaptures(?string $since = null): array
    {
        $minChars = (int) config('atlas.semantic_memory.curation_min_content_chars', 160);
        $query = Capture::query()
            ->whereNull('deleted_at')
            ->whereNotNull('content_text')
            ->whereRaw('length(content_text) >= ?', [$minChars])
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
            if ($this->createFromCapture($capture)) {
                $created++;
            } else {
                $skipped++;
            }
        }

        return compact('created', 'skipped');
    }

    public function createFromCapture(Capture $capture): ?SemanticCurationProposal
    {
        $exists = SemanticCurationProposal::query()
            ->where('source_type', 'capture')
            ->whereRaw('source_refs @> ?::jsonb', [json_encode(['capture_id' => $capture->id])])
            ->exists();

        if ($exists || ! $capture->content_text) {
            return null;
        }

        $text = trim($capture->content_text);
        $title = $this->titleFromText($text);
        $type = $this->inferType($text);
        $path = $this->pathForType($type, $title);
        $summary = Str::limit(preg_replace('/\s+/', ' ', $text) ?? $text, 240, '');
        $noteKey = 'note_'.substr(hash('sha256', $capture->id.':'.$text), 0, 16);
        $frontmatter = [
            'id' => $noteKey,
            'type' => $type,
            'title' => $title,
            'status' => $type === 'hypothesis' ? 'testing' : 'draft',
            'confidence' => 'low',
            'maturity' => 'seed',
            'domains' => [$capture->domain],
            'summary' => $summary,
            'when_to_use' => $this->inferWhenToUse($text, $capture->domain),
            'trigger_signals' => $this->inferTriggers($text, $capture->domain),
            'do_not_use_when' => [],
            'practice_prompt' => $type === 'practice' ? $summary : null,
            'source_type' => 'capture',
            'source_refs' => ['capture_id' => $capture->id, 'capture_client_id' => $capture->client_id],
            'postgres_refs' => ['captures' => [$capture->id]],
            'created_at' => now()->toJSON(),
            'updated_at' => now()->toJSON(),
        ];

        return SemanticCurationProposal::create([
            'source_type' => 'capture',
            'source_refs' => Metadata::forStorage(['capture_id' => $capture->id, 'capture_client_id' => $capture->client_id]),
            'proposed_note_type' => $type,
            'proposed_title' => $title,
            'proposed_summary' => $summary,
            'proposed_path' => $path,
            'proposed_frontmatter' => Metadata::forStorage($frontmatter),
            'proposed_body' => $this->bodyFromCapture($capture, $text),
            'score' => $this->scoreText($text),
            'reason' => 'Captura com densidade semantica suficiente para virar memoria ativa.',
            'metadata' => Metadata::forStorage(['created_by' => 'curation-proposal-v1']),
        ]);
    }

    public function accept(SemanticCurationProposal $proposal, array $edits = []): SemanticNote
    {
        $frontmatter = [
            ...($proposal->proposed_frontmatter ?? []),
            ...($edits['frontmatter_edits'] ?? []),
        ];
        $frontmatter['updated_at'] = now()->toJSON();
        $body = $edits['body_edits'] ?? $proposal->proposed_body ?? '';
        $path = $edits['path'] ?? $proposal->proposed_path ?? $this->pathForType($proposal->proposed_note_type, $proposal->proposed_title);
        $markdown = $this->parser->build($frontmatter, $body);
        $actualPath = $this->vault->writeDraft($path, $markdown);
        $result = $this->indexer->indexFile($actualPath);

        $proposal->update([
            'status' => isset($edits['frontmatter_edits']) || isset($edits['body_edits']) ? 'edited' : 'accepted',
            'resolved_at' => now(),
            'metadata' => Metadata::forStorage([
                ...($proposal->metadata ?? []),
                'accepted_path' => $actualPath,
                'semantic_note_id' => $result['note']->id,
            ]),
        ]);

        return $result['note'];
    }

    public function dismiss(SemanticCurationProposal $proposal): void
    {
        $proposal->update(['status' => 'dismissed', 'resolved_at' => now()]);
    }

    public function postpone(SemanticCurationProposal $proposal): void
    {
        $proposal->update(['status' => 'postponed', 'shown_at' => now()]);
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

    private function bodyFromCapture(Capture $capture, string $text): string
    {
        return "## Ideia central\n\n{$text}\n\n## Origem\n\nCaptura {$capture->kind} de {$capture->captured_at?->toJSON()}.\n\n## Como usar\n\nDefinir apos primeira revisao humana.\n";
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
}
