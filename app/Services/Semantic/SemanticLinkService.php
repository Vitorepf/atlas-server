<?php

namespace App\Services\Semantic;

use App\Models\SemanticNote;
use App\Models\SemanticNoteLink;
use App\Support\Metadata;
use Illuminate\Support\Str;

class SemanticLinkService
{
    /**
     * @return array{created:int, skipped:int}
     */
    public function suggestFor(SemanticNote $note, int $limit = 5): array
    {
        $note = $note->refresh();
        $existingPairs = $this->existingPairs($note);
        $created = 0;
        $skipped = 0;

        $candidates = SemanticNote::query()
            ->whereNull('deleted_at')
            ->where('id', '!=', $note->id)
            ->whereNot('status', 'invalid')
            ->latest('updated_at')
            ->limit(250)
            ->get()
            ->map(fn (SemanticNote $candidate): array => $this->scoreCandidate($note, $candidate))
            ->filter(fn (array $candidate): bool => $candidate['confidence'] >= 0.46)
            ->sortByDesc('confidence')
            ->take($limit)
            ->values();

        foreach ($candidates as $candidate) {
            /** @var SemanticNote $target */
            $target = $candidate['note'];
            $pairKey = $this->pairKey($note->id, $target->id);

            if (isset($existingPairs[$pairKey])) {
                $skipped++;

                continue;
            }

            SemanticNoteLink::query()->create([
                'source_note_id' => $note->id,
                'target_note_id' => $target->id,
                'link_type' => $candidate['link_type'],
                'explanation' => $candidate['explanation'],
                'created_by' => 'atlas_suggestion',
                'confidence' => $candidate['confidence'],
                'confirmed_by_operator' => false,
                'metadata' => Metadata::forStorage([
                    'engine' => 'semantic-linker-v1',
                    'matched_domains' => $candidate['matched_domains'],
                    'matched_triggers' => $candidate['matched_triggers'],
                    'matched_terms' => $candidate['matched_terms'],
                    'ratification_required' => true,
                ]),
            ]);

            $created++;
        }

        return compact('created', 'skipped');
    }

    /**
     * @return array<string, bool>
     */
    private function existingPairs(SemanticNote $note): array
    {
        return SemanticNoteLink::query()
            ->where('source_note_id', $note->id)
            ->orWhere('target_note_id', $note->id)
            ->get(['source_note_id', 'target_note_id'])
            ->mapWithKeys(fn (SemanticNoteLink $link): array => [
                $this->pairKey($link->source_note_id, $link->target_note_id) => true,
            ])
            ->all();
    }

    /**
     * @return array{
     *   note:SemanticNote,
     *   confidence:float,
     *   link_type:string,
     *   explanation:string,
     *   matched_domains:array<int, string>,
     *   matched_triggers:array<int, string>,
     *   matched_terms:array<int, string>
     * }
     */
    private function scoreCandidate(SemanticNote $source, SemanticNote $target): array
    {
        $domains = $this->intersection($source->domains ?? [], $target->domains ?? []);
        $triggers = $this->intersection($source->trigger_signals ?? [], $target->trigger_signals ?? []);
        $terms = $this->intersection($this->tokens($this->noteText($source)), $this->tokens($this->noteText($target)));

        $confidence = 0.12
            + min(0.24, count($domains) * 0.12)
            + min(0.30, count($triggers) * 0.10)
            + min(0.24, count($terms) * 0.04);

        if ($source->type === $target->type) {
            $confidence += 0.06;
        }
        if ($source->type === 'hypothesis' && in_array($target->type, ['principle', 'mental_model'], true)) {
            $confidence += 0.08;
        }
        if ($source->type === 'practice' && in_array($target->type, ['principle', 'decision_identity'], true)) {
            $confidence += 0.08;
        }

        $confidence = round(min(0.93, $confidence), 3);
        $linkType = $this->linkType($source, $target, $domains, $triggers, $terms);

        return [
            'note' => $target,
            'confidence' => $confidence,
            'link_type' => $linkType,
            'explanation' => $this->explanation($source, $target, $linkType, $domains, $triggers, $terms),
            'matched_domains' => $domains,
            'matched_triggers' => $triggers,
            'matched_terms' => array_slice($terms, 0, 8),
        ];
    }

    private function linkType(SemanticNote $source, SemanticNote $target, array $domains, array $triggers, array $terms): string
    {
        if ($source->type === 'hypothesis' && in_array($target->type, ['principle', 'mental_model'], true)) {
            return 'tension';
        }
        if ($triggers !== []) {
            return 'applies_to';
        }
        if ($source->type === $target->type || count($terms) >= 4) {
            return 'similar_to';
        }
        if ($domains !== []) {
            return 'extends';
        }

        return 'similar_to';
    }

    private function explanation(SemanticNote $source, SemanticNote $target, string $linkType, array $domains, array $triggers, array $terms): string
    {
        $reasons = [];

        if ($domains !== []) {
            $reasons[] = 'dominios: '.implode(', ', $domains);
        }
        if ($triggers !== []) {
            $reasons[] = 'gatilhos: '.implode(', ', array_slice($triggers, 0, 5));
        }
        if ($terms !== []) {
            $reasons[] = 'termos: '.implode(', ', array_slice($terms, 0, 6));
        }

        $basis = $reasons !== [] ? implode('; ', $reasons) : 'proximidade semantica geral';

        return "Link sugerido como {$linkType}: '{$source->title}' conversa com '{$target->title}' por {$basis}. Vitor deve confirmar se a relacao e real antes de tratar como conhecimento forte.";
    }

    /**
     * @param  array<int, mixed>  $a
     * @param  array<int, mixed>  $b
     * @return array<int, string>
     */
    private function intersection(array $a, array $b): array
    {
        $left = collect($a)->filter()->map(fn (mixed $item): string => Str::lower(trim((string) $item)))->values();
        $right = collect($b)->filter()->map(fn (mixed $item): string => Str::lower(trim((string) $item)))->values();

        return $left->intersect($right)->unique()->values()->all();
    }

    /**
     * @return array<int, string>
     */
    private function tokens(string $text): array
    {
        preg_match_all('/[\pL\pN]{4,}/u', Str::lower($text), $matches);

        return collect($matches[0] ?? [])
            ->reject(fn (string $token): bool => in_array($token, ['para', 'como', 'quando', 'sobre', 'esta', 'este', 'essa', 'esse', 'mais', 'muito'], true))
            ->unique()
            ->values()
            ->all();
    }

    private function noteText(SemanticNote $note): string
    {
        return implode(' ', array_filter([
            $note->title,
            $note->summary,
            $note->body_excerpt,
            implode(' ', $note->when_to_use ?? []),
            implode(' ', $note->trigger_signals ?? []),
        ]));
    }

    private function pairKey(string $a, string $b): string
    {
        $ids = [$a, $b];
        sort($ids);

        return implode(':', $ids);
    }
}
