<?php

namespace App\Services\Semantic;

use App\Models\CognitiveGameRun;
use App\Models\SemanticNote;
use App\Support\Metadata;
use Illuminate\Support\Collection;

class CognitiveGameService
{
    public function today(): ?CognitiveGameRun
    {
        return CognitiveGameRun::query()
            ->whereDate('created_at', now()->toDateString())
            ->latest()
            ->first();
    }

    public function start(string $gameKey = 'recall', array $noteIds = []): CognitiveGameRun
    {
        $notes = $this->selectNotes($noteIds);
        $title = $this->titleFor($gameKey);

        $run = CognitiveGameRun::create([
            'game_key' => $gameKey,
            'title' => $title,
            'input_note_ids' => Metadata::forStorage($notes->pluck('id')->values()->all()),
            'prompt' => $this->promptFor($gameKey, $notes),
            'metadata' => Metadata::forStorage(['engine' => 'cognitive-game-v1']),
        ]);

        $notes->each(fn (SemanticNote $note) => $note->forceFill(['last_practiced_at' => now()])->save());

        return $run;
    }

    public function answer(CognitiveGameRun $run, string $answer, ?int $durationSeconds = null): CognitiveGameRun
    {
        $answer = trim($answer);
        $score = min(1.0, max(0.15, mb_strlen($answer) / 900));
        $feedback = match (true) {
            mb_strlen($answer) < 120 => 'Resposta curta. Boa para inicio, mas ainda precisa de exemplo concreto para virar treino real.',
            mb_strlen($answer) < 420 => 'Resposta util. Proxima repeticao deve forcar aplicacao em uma situacao real recente.',
            default => 'Resposta densa. Marcar como candidata para sintese se este padrao se repetir.',
        };

        $run->update([
            'operator_answer' => $answer,
            'atlas_feedback' => $feedback,
            'score' => round($score, 3),
            'duration_seconds' => $durationSeconds,
        ]);

        return $run->refresh();
    }

    /**
     * @param  array<int, string>  $noteIds
     * @return Collection<int, SemanticNote>
     */
    private function selectNotes(array $noteIds): Collection
    {
        if ($noteIds !== []) {
            return SemanticNote::query()
                ->whereIn('id', $noteIds)
                ->whereNull('deleted_at')
                ->limit(3)
                ->get();
        }

        return SemanticNote::query()
            ->whereNull('deleted_at')
            ->whereIn('status', ['active', 'testing', 'validated'])
            ->orderByRaw('last_practiced_at IS NULL DESC')
            ->orderBy('last_practiced_at')
            ->orderByDesc('updated_at')
            ->limit(2)
            ->get();
    }

    private function titleFor(string $gameKey): string
    {
        return match ($gameKey) {
            'forced_connection' => 'Conexao forcada',
            'adversarial' => 'Hipotese adversarial',
            'blind_application' => 'Aplicacao cega',
            'synthesis' => 'Sintese forcada',
            default => 'Recall semantico',
        };
    }

    /**
     * @param  Collection<int, SemanticNote>  $notes
     */
    private function promptFor(string $gameKey, Collection $notes): string
    {
        if ($notes->isEmpty()) {
            return 'Crie uma nota ativa no vault antes de rodar jogos cognitivos.';
        }

        $titles = $notes->pluck('title')->implode(' + ');

        return match ($gameKey) {
            'forced_connection' => "Conecte estes conceitos e explique onde a conexao e util na vida real: {$titles}.",
            'adversarial' => "Ataque a tese desta nota como se ela estivesse errada. Depois salve o que sobreviveu: {$titles}.",
            'blind_application' => "Aplique este modelo a uma situacao real da ultima semana, sem reler a nota antes: {$titles}.",
            'synthesis' => "Produza uma sintese nova que una estas notas sem virar resumo: {$titles}.",
            default => "Reconstrua de memoria a ideia central, quando usar e um exemplo real: {$titles}.",
        };
    }
}
