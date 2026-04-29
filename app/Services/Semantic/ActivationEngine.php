<?php

namespace App\Services\Semantic;

use App\Models\Checkin;
use App\Models\DigitalActivitySnapshot;
use App\Models\HealthSnapshot;
use App\Models\SemanticNote;
use App\Models\SemanticNoteActivation;
use App\Support\Metadata;
use Illuminate\Support\Collection;

class ActivationEngine
{
    /**
     * @return array{created:int, skipped:int, signals:array<int, string>}
     */
    public function createForContext(string $contextType = 'morning_briefing', array $contextPayload = []): array
    {
        $signals = $this->contextSignals($contextPayload);
        $limit = (int) config('atlas.semantic_memory.activation_daily_limit', 2);
        $createdToday = SemanticNoteActivation::query()
            ->whereDate('created_at', now()->toDateString())
            ->count();

        if ($createdToday >= $limit) {
            return ['created' => 0, 'skipped' => 0, 'signals' => $signals];
        }

        $slots = max(0, $limit - $createdToday);
        $candidates = $this->rankCandidates($signals)->take($slots);
        $created = 0;
        $skipped = 0;

        foreach ($candidates as $candidate) {
            /** @var SemanticNote $note */
            $note = $candidate['note'];

            $exists = SemanticNoteActivation::query()
                ->where('note_id', $note->id)
                ->where('context_type', $contextType)
                ->whereDate('created_at', now()->toDateString())
                ->exists();

            if ($exists) {
                $skipped++;

                continue;
            }

            SemanticNoteActivation::create([
                'note_id' => $note->id,
                'activation_type' => $this->activationType($note),
                'context_type' => $contextType,
                'context_payload' => Metadata::forStorage([
                    ...$contextPayload,
                    'signals' => $signals,
                    'matched_signals' => $candidate['matched_signals'],
                    'score' => $candidate['activation_score'],
                ]),
                'prompt' => $this->promptFor($note),
                'metadata' => Metadata::forStorage(['engine' => 'semantic-activation-v1']),
            ]);

            $note->forceFill([
                'last_activated_at' => now(),
                'activation_count' => $note->activation_count + 1,
            ])->save();

            $created++;
        }

        return compact('created', 'skipped', 'signals');
    }

    /**
     * @return Collection<int, SemanticNoteActivation>
     */
    public function pending(?string $contextType = null, int $limit = 10): Collection
    {
        return SemanticNoteActivation::query()
            ->with('note')
            ->whereNull('dismissed_at')
            ->when($contextType, fn ($query) => $query->where('context_type', $contextType))
            ->orderByRaw('shown_at IS NULL DESC')
            ->orderByDesc('created_at')
            ->limit($limit)
            ->get();
    }

    public function markShown(SemanticNoteActivation $activation): SemanticNoteActivation
    {
        $activation->forceFill(['shown_at' => $activation->shown_at ?? now()])->save();

        return $activation->refresh();
    }

    public function recordFeedback(SemanticNoteActivation $activation, int $score, ?string $feedback = null): SemanticNoteActivation
    {
        $activation->update([
            'acted_at' => now(),
            'usefulness_score' => $score,
            'operator_feedback' => $feedback,
        ]);

        $this->updateNoteUsefulness($activation->note);

        return $activation->refresh();
    }

    public function dismiss(SemanticNoteActivation $activation): SemanticNoteActivation
    {
        $activation->update(['dismissed_at' => now()]);

        return $activation->refresh();
    }

    /**
     * @return array<int, string>
     */
    private function contextSignals(array $payload): array
    {
        $signals = collect($payload['signals'] ?? []);

        $checkin = Checkin::query()->latest('recorded_at')->first();
        if ($checkin) {
            $signals->push(match ($checkin->state) {
                'focused' => 'estado_focado',
                'disperse' => 'estado_disperso',
                'blocked' => 'estado_bloqueado',
                'pause' => 'estado_pausa',
                default => 'estado_'.$checkin->state,
            });
            if ($checkin->energy_level !== null && $checkin->energy_level <= 2) {
                $signals->push('energia_baixa');
            }
            if ($checkin->mood_level !== null && $checkin->mood_level <= 2) {
                $signals->push('mood_baixo');
            }
        }

        $health = HealthSnapshot::query()->latest('snapshot_date')->first();
        if ($health) {
            if ($health->sleep_duration_hours !== null && $health->sleep_duration_hours < 6.0) {
                $signals->push('sono_curto');
            }
            if ($health->readiness_score !== null && $health->readiness_score < 65) {
                $signals->push('prontidao_baixa');
            }
            if ($health->hrv_ms !== null) {
                $signals->push('healthkit_hrv');
            }
        }

        $digital = DigitalActivitySnapshot::query()->latest('snapshot_date')->first();
        if ($digital) {
            if (($digital->algorithmic_input_min ?? 0) >= 45) {
                $signals->push('input_algoritmico_alto');
            }
            if (($digital->deep_work_total_min ?? 0) >= 90) {
                $signals->push('deep_work_alto');
            }
        }

        return $signals
            ->filter(fn ($signal): bool => is_string($signal) && trim($signal) !== '')
            ->map(fn (string $signal): string => trim($signal))
            ->unique()
            ->values()
            ->all();
    }

    /**
     * @param  array<int, string>  $signals
     * @return Collection<int, array{note: SemanticNote, activation_score: int|float, matched_signals: array<int, string>}>
     */
    private function rankCandidates(array $signals): Collection
    {
        return SemanticNote::query()
            ->whereIn('status', ['active', 'testing', 'validated'])
            ->whereNotIn('maturity', ['archived'])
            ->whereNull('deleted_at')
            ->limit(200)
            ->get()
            ->map(function (SemanticNote $note) use ($signals): array {
                $triggers = collect($note->trigger_signals ?? [])->filter()->values();
                $matches = $triggers->intersect($signals)->values()->all();
                $score = count($matches) * 8;

                if ($triggers->isEmpty()) {
                    $score -= 5;
                }
                if ($note->last_activated_at === null) {
                    $score += 3;
                } elseif ($note->last_activated_at->lt(now()->subDays(14))) {
                    $score += 2;
                } else {
                    $score -= 6;
                }
                if (in_array($note->maturity, ['tested', 'principle'], true)) {
                    $score += 2;
                }

                return [
                    'note' => $note,
                    'activation_score' => $score,
                    'matched_signals' => $matches,
                ];
            })
            ->filter(fn (array $candidate): bool => $candidate['activation_score'] > 0)
            ->sortByDesc(fn (array $candidate): int|float => $candidate['activation_score'])
            ->values();
    }

    private function activationType(SemanticNote $note): string
    {
        return match ($note->type) {
            'practice', 'cognitive_game' => 'practice',
            'hypothesis' => 'test',
            'principle' => 'remember',
            default => 'connect',
        };
    }

    private function promptFor(SemanticNote $note): string
    {
        $summary = $note->summary ?: $note->body_excerpt ?: 'Revisar nota semantica.';

        return match ($this->activationType($note)) {
            'practice' => "Treine hoje: {$summary}",
            'test' => "Hipotese viva: {$summary}",
            'remember' => "Lembre este principio no contexto de hoje: {$summary}",
            default => "Conecte isto ao que voce esta vivendo agora: {$summary}",
        };
    }

    private function updateNoteUsefulness(?SemanticNote $note): void
    {
        if (! $note) {
            return;
        }

        $average = $note->activations()
            ->whereNotNull('usefulness_score')
            ->avg('usefulness_score');

        $note->forceFill(['usefulness_avg' => $average ? round((float) $average, 3) : null])->save();
    }
}
