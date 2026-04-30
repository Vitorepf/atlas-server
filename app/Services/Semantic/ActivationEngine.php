<?php

namespace App\Services\Semantic;

use App\Models\Checkin;
use App\Models\DigitalActivitySnapshot;
use App\Models\HealthSnapshot;
use App\Models\SemanticNote;
use App\Models\SemanticNoteActivation;
use App\Services\AuditLogService;
use App\Support\Metadata;
use Illuminate\Support\Collection;

class ActivationEngine
{
    public function __construct(private readonly AuditLogService $audit) {}

    /**
     * @return array{created:int, skipped:int, signals:array<int, string>}
     */
    public function createForContext(string $contextType = 'morning_briefing', array $contextPayload = []): array
    {
        $signals = $this->contextSignals($contextType, $contextPayload);
        $limit = (int) config('atlas.semantic_memory.activation_daily_limit', 2);
        $pendingLimit = (int) config('atlas.semantic_memory.activation_pending_limit', 4);
        $createdToday = SemanticNoteActivation::query()
            ->whereDate('created_at', now()->toDateString())
            ->count();

        $pendingCount = SemanticNoteActivation::query()
            ->whereNull('acted_at')
            ->whereNull('dismissed_at')
            ->count();

        if ($createdToday >= $limit || $pendingCount >= $pendingLimit) {
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

            $activation = SemanticNoteActivation::create([
                'note_id' => $note->id,
                'activation_type' => $this->activationType($note),
                'context_type' => $contextType,
                'context_payload' => Metadata::forStorage([
                    ...$contextPayload,
                    'signals' => $signals,
                    'matched_signals' => $candidate['matched_signals'],
                    'score' => $candidate['activation_score'],
                    'fatigue_policy' => $candidate['fatigue_policy'],
                ]),
                'prompt' => $this->promptFor($note),
                'metadata' => Metadata::forStorage([
                    'engine' => 'semantic-activation-v2',
                    'relevance_score' => $candidate['activation_score'],
                    'fatigue_policy' => $candidate['fatigue_policy'],
                ]),
            ]);

            $this->audit->record('semantic_activation_created', [
                'subject_type' => 'semantic_note_activation',
                'subject_id' => $activation->id,
                'summary' => "Memoria ativada: {$note->title}.",
                'evidence' => [
                    'note_id' => $note->id,
                    'note_title' => $note->title,
                    'context_type' => $contextType,
                    'activation_type' => $activation->activation_type,
                    'matched_signals' => $candidate['matched_signals'],
                    'signals' => $signals,
                    'score' => $candidate['activation_score'],
                    'fatigue_policy' => $candidate['fatigue_policy'],
                    'prompt' => $activation->prompt,
                ],
                'privacy' => [
                    'domain' => $contextPayload['domain'] ?? null,
                    'sensitivity' => $contextPayload['sensitivity'] ?? null,
                    'external_ai_allowed' => $contextPayload['external_ai_allowed'] ?? null,
                ],
                'refs' => [
                    'activation_id' => $activation->id,
                    'note_id' => $note->id,
                    'capture_id' => $contextPayload['capture_id'] ?? null,
                ],
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

    public function recordFeedback(
        SemanticNoteActivation $activation,
        int $score,
        ?string $action = null,
        ?string $feedback = null,
    ): SemanticNoteActivation {
        $action ??= $score >= 4 ? 'useful' : ($score <= 2 ? 'not_useful' : null);

        $activation->update([
            'acted_at' => now(),
            'usefulness_score' => $score,
            'feedback_action' => $action,
            'operator_feedback' => $feedback,
        ]);

        $this->updateNoteUsefulness($activation->note);

        $this->audit->record('semantic_activation_feedback_recorded', [
            'subject_type' => 'semantic_note_activation',
            'subject_id' => $activation->id,
            'actor_type' => 'operator',
            'actor_id' => 'vitor',
            'summary' => "Feedback registrado para ativacao: {$action}.",
            'evidence' => [
                'score' => $score,
                'feedback_action' => $action,
                'operator_feedback' => $feedback,
            ],
            'privacy' => $this->privacyFromActivation($activation),
            'refs' => [
                'activation_id' => $activation->id,
                'note_id' => $activation->note_id,
            ],
        ]);

        return $activation->refresh();
    }

    public function dismiss(SemanticNoteActivation $activation): SemanticNoteActivation
    {
        $activation->update([
            'dismissed_at' => now(),
            'feedback_action' => $activation->feedback_action ?? 'dismissed',
        ]);

        $this->audit->record('semantic_activation_dismissed', [
            'subject_type' => 'semantic_note_activation',
            'subject_id' => $activation->id,
            'actor_type' => 'operator',
            'actor_id' => 'vitor',
            'summary' => 'Ativacao dispensada.',
            'evidence' => [
                'feedback_action' => 'dismissed',
            ],
            'privacy' => $this->privacyFromActivation($activation),
            'refs' => [
                'activation_id' => $activation->id,
                'note_id' => $activation->note_id,
            ],
        ]);

        return $activation->refresh();
    }

    /**
     * @return array<int, string>
     */
    private function contextSignals(string $contextType, array $payload): array
    {
        $signals = collect($payload['signals'] ?? []);
        $signals->push($contextType);

        if (! empty($payload['domain'])) {
            $signals->push('dominio_'.$payload['domain']);
            $signals->push((string) $payload['domain']);
        }
        if (! empty($payload['capture_kind'])) {
            $signals->push('captura_'.$payload['capture_kind']);
        }
        if (! empty($payload['suggested_type'])) {
            $signals->push('tipo_'.$payload['suggested_type']);
        }
        foreach ((array) ($payload['future_triggers'] ?? []) as $trigger) {
            $signals->push($trigger);
        }
        if (! empty($payload['project_name'])) {
            $signals->push('projeto_'.str($payload['project_name'])->slug('_')->toString());
            $signals->push('contexto_projeto');
        }
        if (! empty($payload['decision'])) {
            $signals->push('decisao_aberta');
            $signals->push('decisao_'.str($payload['decision'])->slug('_')->toString());
        }
        if (! empty($payload['source_kind'])) {
            $signals->push('rize_'.$payload['source_kind']);
        }
        if (! empty($payload['url_domain'])) {
            $signals->push('url_'.str($payload['url_domain'])->slug('_')->toString());
        }

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
     * @return Collection<int, array{note: SemanticNote, activation_score: int|float, matched_signals: array<int, string>, fatigue_policy: array<string, mixed>}>
     */
    private function rankCandidates(array $signals): Collection
    {
        $cooldownDays = (int) config('atlas.semantic_memory.activation_note_cooldown_days', 7);

        return SemanticNote::query()
            ->whereIn('status', ['active', 'testing', 'validated'])
            ->whereNotIn('maturity', ['archived'])
            ->whereNull('deleted_at')
            ->with(['activations' => fn ($query) => $query->latest('created_at')->limit(8)])
            ->limit(200)
            ->get()
            ->map(function (SemanticNote $note) use ($signals, $cooldownDays): array {
                $triggers = collect($note->trigger_signals ?? [])->filter()->values();
                $matches = $triggers->intersect($signals)->values()->all();
                $recentActivations = $note->activations;
                $lastFeedbackAction = $recentActivations
                    ->first(fn (SemanticNoteActivation $activation): bool => $activation->feedback_action !== null)
                    ?->feedback_action;
                $recentUnhelpful = $recentActivations
                    ->whereIn('feedback_action', ['not_useful', 'too_early'])
                    ->filter(fn (SemanticNoteActivation $activation): bool => $activation->created_at?->gt(now()->subDays(14)) ?? false)
                    ->count();
                $score = count($matches) * 12;
                $fatiguePolicy = [
                    'cooldown_days' => $cooldownDays,
                    'last_feedback_action' => $lastFeedbackAction,
                    'recent_unhelpful' => $recentUnhelpful,
                ];

                if ($triggers->isEmpty()) {
                    $score -= 8;
                }
                if ($note->last_activated_at === null) {
                    $score += 3;
                } elseif ($note->last_activated_at->lt(now()->subDays($cooldownDays))) {
                    $score += 2;
                } else {
                    $score -= 12;
                    $fatiguePolicy['cooldown_active'] = true;
                }
                if (in_array($note->maturity, ['tested', 'principle'], true)) {
                    $score += 2;
                }
                if (($note->usefulness_avg ?? 0) >= 4.2) {
                    $score += 3;
                }
                if (($note->usefulness_avg ?? 5) <= 2.2) {
                    $score -= 8;
                }
                if ($lastFeedbackAction === 'too_early') {
                    $score -= 8;
                }
                if ($lastFeedbackAction === 'too_late') {
                    $score += 4;
                }
                if ($recentUnhelpful >= 2) {
                    $score -= 10;
                }

                return [
                    'note' => $note,
                    'activation_score' => $score,
                    'matched_signals' => $matches,
                    'fatigue_policy' => $fatiguePolicy,
                ];
            })
            ->filter(fn (array $candidate): bool => $candidate['activation_score'] >= 8)
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

    private function privacyFromActivation(SemanticNoteActivation $activation): array
    {
        $payload = is_array($activation->context_payload) ? $activation->context_payload : [];

        return [
            'domain' => $payload['domain'] ?? null,
            'sensitivity' => $payload['sensitivity'] ?? null,
            'external_ai_allowed' => $payload['external_ai_allowed'] ?? null,
        ];
    }
}
