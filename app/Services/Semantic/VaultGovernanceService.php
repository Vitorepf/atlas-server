<?php

namespace App\Services\Semantic;

use App\Models\SemanticNote;
use App\Models\SemanticNoteActivation;
use App\Models\VaultHealthSnapshot;
use App\Support\Metadata;

class VaultGovernanceService
{
    public function snapshot(): VaultHealthSnapshot
    {
        $date = now()->toDateString();
        $total = SemanticNote::query()->whereNull('deleted_at')->count();
        $active = SemanticNote::query()->whereNull('deleted_at')->whereIn('status', ['active', 'testing', 'validated'])->count();
        $inbox = SemanticNote::query()->whereNull('deleted_at')->where('status', 'inbox')->count();
        $invalid = SemanticNote::query()->whereNull('deleted_at')->where('status', 'invalid')->count();
        $stale = SemanticNote::query()
            ->whereNull('deleted_at')
            ->whereIn('status', ['active', 'testing', 'validated'])
            ->where(function ($query): void {
                $query->whereNull('last_activated_at')->orWhere('last_activated_at', '<', now()->subMonths(6));
            })
            ->count();
        $withoutTriggers = SemanticNote::query()
            ->whereNull('deleted_at')
            ->whereIn('status', ['active', 'testing', 'validated'])
            ->whereRaw('jsonb_array_length(trigger_signals) = 0')
            ->count();
        $withoutLinks = SemanticNote::query()
            ->whereNull('semantic_notes.deleted_at')
            ->whereDoesntHave('sourceLinks')
            ->whereDoesntHave('targetLinks')
            ->count();
        $activations7d = SemanticNoteActivation::query()
            ->where('created_at', '>=', now()->subDays(7))
            ->count();
        $useful7d = SemanticNoteActivation::query()
            ->where('created_at', '>=', now()->subDays(7))
            ->where('usefulness_score', '>=', 4)
            ->count();
        $activatedNotes7d = SemanticNoteActivation::query()
            ->where('created_at', '>=', now()->subDays(7))
            ->distinct('note_id')
            ->count('note_id');
        $acted7d = SemanticNoteActivation::query()
            ->where('created_at', '>=', now()->subDays(7))
            ->whereNotNull('acted_at')
            ->count();
        $utilityRate = $activations7d > 0 ? round($useful7d / $activations7d, 3) : 0.0;
        $coverageRate = $active > 0 ? round($activatedNotes7d / $active, 3) : 0.0;
        $responseRate = $activations7d > 0 ? round($acted7d / $activations7d, 3) : 0.0;
        $cognitiveReturn = [
            'version' => 'cron-v1',
            'score' => $this->cognitiveReturnScore(
                active: $active,
                stale: $stale,
                withoutTriggers: $withoutTriggers,
                activations7d: $activations7d,
                useful7d: $useful7d,
                utilityRate: $utilityRate,
                coverageRate: $coverageRate,
                responseRate: $responseRate,
            ),
            'label' => $this->cognitiveReturnLabel($activations7d, $useful7d, $utilityRate),
            'useful_activations_7d' => $useful7d,
            'activations_7d' => $activations7d,
            'utility_rate_7d' => $utilityRate,
            'response_rate_7d' => $responseRate,
            'active_note_coverage_7d' => $coverageRate,
            'activated_notes_7d' => $activatedNotes7d,
            'notes_per_useful_activation_7d' => $useful7d > 0 ? round(max(1, $active) / $useful7d, 2) : null,
            'interpretation' => $this->cognitiveReturnInterpretation($activations7d, $useful7d, $utilityRate),
        ];

        [$state, $recommendations] = $this->stateAndRecommendations(
            total: $total,
            active: $active,
            inbox: $inbox,
            invalid: $invalid,
            stale: $stale,
            withoutTriggers: $withoutTriggers,
            withoutLinks: $withoutLinks,
            activations7d: $activations7d,
            useful7d: $useful7d,
        );

        return VaultHealthSnapshot::updateOrCreate(
            ['snapshot_date' => $date],
            [
                'total_notes' => $total,
                'active_notes' => $active,
                'inbox_notes' => $inbox,
                'invalid_notes' => $invalid,
                'stale_notes' => $stale,
                'notes_without_triggers' => $withoutTriggers,
                'notes_without_links' => $withoutLinks,
                'activations_7d' => $activations7d,
                'useful_activations_7d' => $useful7d,
                'health_state' => $state,
                'recommendations' => Metadata::forStorage($recommendations),
                'metadata' => Metadata::forStorage([
                    'engine' => 'vault-governance-v2',
                    'cognitive_return' => $cognitiveReturn,
                ]),
            ],
        );
    }

    public function latest(): ?VaultHealthSnapshot
    {
        return VaultHealthSnapshot::query()->latest('snapshot_date')->first();
    }

    /**
     * @return array{0:string, 1:array<int, string>}
     */
    private function stateAndRecommendations(
        int $total,
        int $active,
        int $inbox,
        int $invalid,
        int $stale,
        int $withoutTriggers,
        int $withoutLinks,
        int $activations7d,
        int $useful7d,
    ): array {
        $recommendations = [];
        $state = 'healthy';

        if ($invalid > 0) {
            $state = 'needs_attention';
            $recommendations[] = "{$invalid} nota(s) com frontmatter invalido precisam de revisao.";
        }
        if ($inbox > 12) {
            $state = 'inflated';
            $recommendations[] = 'Inbox semantica acima de 12 notas; agendar curadoria antes de adicionar mais.';
        }
        if ($withoutTriggers > max(3, (int) floor($active * 0.25))) {
            $state = 'needs_attention';
            $recommendations[] = 'Muitas notas ativas nao possuem trigger_signals; Atlas nao sabera quando usa-las.';
        }
        if ($stale > max(5, (int) floor($active * 0.35))) {
            $state = 'cold';
            $recommendations[] = 'Parte relevante do vault nao foi ativada em 6 meses; revisar ou arquivar.';
        }
        if ($activations7d > 14 && $useful7d < 3) {
            $state = 'anxious';
            $recommendations[] = 'Ativacoes demais com pouca utilidade; reduzir frequencia de lembrancas.';
        }
        if ($total >= 30 && $active >= 8 && $invalid === 0 && $withoutTriggers <= 3 && $useful7d >= 3) {
            $state = 'mature';
            $recommendations[] = 'Vault maduro: manter governanca semanal e promover hipoteses testadas.';
        }
        if ($recommendations === []) {
            $recommendations[] = 'Vault em estado saudavel para o volume atual.';
        }

        return [$state, $recommendations];
    }

    private function cognitiveReturnScore(
        int $active,
        int $stale,
        int $withoutTriggers,
        int $activations7d,
        int $useful7d,
        float $utilityRate,
        float $coverageRate,
        float $responseRate,
    ): int {
        if ($active === 0) {
            return 0;
        }

        $score = 10
            + min(30, $useful7d * 8)
            + (int) round($utilityRate * 28)
            + (int) round($coverageRate * 18)
            + (int) round($responseRate * 10)
            - min(18, $stale * 2)
            - min(14, $withoutTriggers * 2);

        if ($activations7d > 12 && $utilityRate < 0.25) {
            $score -= 12;
        }

        return max(0, min(100, $score));
    }

    private function cognitiveReturnLabel(int $activations7d, int $useful7d, float $utilityRate): string
    {
        if ($activations7d === 0) {
            return 'sem sinal';
        }
        if ($useful7d >= 3 && $utilityRate >= 0.5) {
            return 'composto';
        }
        if ($useful7d > 0) {
            return 'aquecendo';
        }
        if ($activations7d > 8) {
            return 'ruido';
        }

        return 'frio';
    }

    private function cognitiveReturnInterpretation(int $activations7d, int $useful7d, float $utilityRate): string
    {
        if ($activations7d === 0) {
            return 'Notas ainda nao retornaram no contexto; revisar gatilhos e criar ativacoes pequenas.';
        }
        if ($useful7d === 0) {
            return 'Houve ativacao, mas nenhuma utilidade registrada; reduzir volume ou ajustar sinais.';
        }
        if ($utilityRate >= 0.5) {
            return 'Memoria esta retornando em contexto; manter curadoria e feedback.';
        }

        return 'Existe retorno, mas a precisao ainda precisa melhorar com feedback e links.';
    }
}
