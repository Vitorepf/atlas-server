<?php

namespace App\Services\Ai\OperatorIntelligence;

use App\Services\Ai\OperatorIntelligence\Support\OperatorLearningDetectSupport;
use Illuminate\Support\Str;

class OperatorLearningSignalDetector
{
    /**
     * @param  array<string,mixed>  $context
     * @return array<string,mixed>|null
     */
    public function detect(string $input, array $context = []): ?array
    {
        $claim = $this->matchingSentence($input);
        if ($claim === null) {
            return null;
        }

        $normalized = $this->normalizeForMatch($claim);
        $kind = $this->signalKind($normalized);
        $taxonomy = $this->taxonomy($normalized, $kind);
        $confidence = $this->confidence($normalized, $kind);
        $privacy = $this->privacyClass($normalized);
        $risk = $this->riskLevel($normalized, $privacy);
        $scope = $this->scopeType($normalized);

        return [
            'operator_id' => (string) ($context['operator_id'] ?? config('atlas_operator_intelligence.default_operator_id', 'default')),
            'claim' => $claim,
            'raw_excerpt' => $claim,
            'taxonomy_item_id' => $taxonomy,
            'signal_kind' => $kind,
            'privacy_class' => $privacy,
            'risk_level' => $risk,
            'confidence' => $confidence,
            'scope_type' => $scope,
            'profile_key' => $this->profileKey($normalized, $taxonomy, $kind),
            'effect' => $this->effect($taxonomy, $kind),
            'metadata' => [
                'detector' => 'operator_learning_signal_detector.v1',
                'matched_pattern_family' => $this->matchedFamily($normalized),
                'raw_text_persisted' => false,
                // L3-9 #8: this passive regex detector emits hardcoded confidence
                // (0.91/0.92) that clears the 0.85 auto-apply floor. It must NEVER carry
                // auto-apply provenance it did not earn. We STAMP a non-auto-apply
                // provenance UNCONDITIONALLY — never copy `auto_apply_provenance` from the
                // (spoofable) caller context — so a caller cannot forge `comprehension`/
                // `manual_operator` to push a passive signal past the gate. The gate stays
                // the structural authority; this closes the producer-side spoof hole.
                'auto_apply_provenance' => OperatorLearningGate::PASSIVE_DETECTOR_PROVENANCE,
            ],
        ];
    }

    private function matchingSentence(string $input): ?string
    {
        $input = trim(preg_replace('/\s+/', ' ', $input) ?? '');
        if ($input === '' || mb_strlen($input) < 12) {
            return null;
        }

        foreach ($this->sentences($input) as $sentence) {
            $normalized = $this->normalizeForMatch($sentence);
            if ($this->isExplicitLearningSignal($normalized)) {
                return Str::limit(trim($sentence), 1000, '');
            }
        }

        $normalized = $this->normalizeForMatch($input);
        if ($this->isExplicitLearningSignal($normalized)) {
            return Str::limit($input, 1000, '');
        }

        return null;
    }

    /**
     * @return array<int,string>
     */
    private function sentences(string $input): array
    {
        return OperatorLearningDetectSupport::sentences($input);
    }

    private function isExplicitLearningSignal(string $normalized): bool
    {
        if ($this->matchedFamily($normalized) !== 'none') {
            return true;
        }

        return false;
    }

    private function matchedFamily(string $normalized): string
    {
        $families = [
            'preference' => [
                '/\b(eu\s+)?prefiro\b/u',
                '/\bminha preferencia\b/u',
                '/\b(eu\s+)?gosto\b/u',
                '/\b(eu\s+)?nao gosto\b/u',
            ],
            'memory_request' => [
                '/\blembre( se)?\b/u',
                '/\bguarde\b/u',
                '/\baprenda\b/u',
                '/\bmemorize\b/u',
            ],
            'recurring_instruction' => [
                '/\bda proxima vez\b/u',
                '/\bde agora em diante\b/u',
                '/\bsempre que\b/u',
                '/\bquando eu pedir\b/u',
                '/\bquando eu falar\b/u',
            ],
            'boundary' => [
                '/\b(nunca|jamais)\b/u',
                '/\bnao faca mais\b/u',
                '/\bpara de\b/u',
                '/\bpare de\b/u',
                '/\bnao quero que (voce|o atlas)\b/u',
                '/\bnao mexa\b/u',
            ],
        ];

        foreach ($families as $family => $patterns) {
            foreach ($patterns as $pattern) {
                if (preg_match($pattern, $normalized) === 1) {
                    return $family;
                }
            }
        }

        return 'none';
    }

    private function signalKind(string $normalized): string
    {
        $family = $this->matchedFamily($normalized);
        if ($family === 'boundary') {
            return 'operator_boundary';
        }
        if ($family === 'recurring_instruction' || str_contains($normalized, 'pergunta') || str_contains($normalized, 'status')) {
            return 'collaboration_preference';
        }

        return 'operator_preference';
    }

    private function taxonomy(string $normalized, string $kind): string
    {
        if ($kind === 'operator_boundary') {
            return 'OP-140';
        }

        foreach (['resposta', 'status', 'tom', 'curt', 'objetiv', 'diret', 'detalh', 'explic'] as $needle) {
            if (str_contains($normalized, $needle)) {
                return 'COL-156';
            }
        }

        foreach (['pergunta', 'clarifica', 'aprov', 'autonom', 'bloque'] as $needle) {
            if (str_contains($normalized, $needle)) {
                return 'COL-157';
            }
        }

        foreach (['gosto', 'prefiro', 'sobre mim', 'meu jeito', 'minha forma'] as $needle) {
            if (str_contains($normalized, $needle)) {
                return 'OP-124';
            }
        }

        return 'OP-071';
    }

    private function confidence(string $normalized, string $kind): float
    {
        if ($kind === 'operator_boundary') {
            return 0.92;
        }

        foreach (['prefiro', 'sempre que', 'de agora em diante', 'da proxima vez', 'quando eu pedir'] as $needle) {
            if (str_contains($normalized, $needle)) {
                return 0.91;
            }
        }

        foreach (['lembre', 'guarde', 'aprenda', 'memorize'] as $needle) {
            if (str_contains($normalized, $needle)) {
                return 0.86;
            }
        }

        return 0.78;
    }

    private function privacyClass(string $normalized): string
    {
        foreach (['senha', 'token', 'secret', 'key', 'credential', 'credencial'] as $needle) {
            if (str_contains($normalized, $needle)) {
                return 'secret';
            }
        }

        foreach (['saude', 'familia', 'relacionamento', 'dinheiro', 'documento', 'cpf', 'rg'] as $needle) {
            if (str_contains($normalized, $needle)) {
                return 'sensitive';
            }
        }

        return 'normal';
    }

    private function riskLevel(string $normalized, string $privacy): string
    {
        if (in_array($privacy, ['sensitive', 'secret'], true)) {
            return 'high';
        }

        foreach (['apagar', 'deletar', 'delete', 'publicar', 'enviar', 'comprar', 'vender'] as $needle) {
            if (str_contains($normalized, $needle)) {
                return 'high';
            }
        }

        return 'low';
    }

    private function scopeType(string $normalized): string
    {
        foreach (['neste projeto', 'nesse projeto', 'para este repo', 'para esse repo', 'nesta conversa', 'nessa conversa'] as $needle) {
            if (str_contains($normalized, $needle)) {
                return 'project';
            }
        }

        return 'global';
    }

    private function profileKey(string $normalized, string $taxonomy, string $kind): string
    {
        $prefix = match (true) {
            $kind === 'operator_boundary' => 'boundaries',
            str_starts_with($taxonomy, 'COL-') => 'collaboration',
            default => 'operator',
        };

        $slug = Str::slug(Str::limit($normalized, 80, ''), '_');
        $slug = $slug !== '' ? $slug : strtolower(str_replace('-', '_', $taxonomy));

        return $prefix.'.'.$slug;
    }

    private function effect(string $taxonomy, string $kind): string
    {
        if ($kind === 'operator_boundary') {
            return 'do_not_do';
        }
        if ($taxonomy === 'COL-156') {
            return 'response_style';
        }
        if (str_starts_with($taxonomy, 'COL-')) {
            return 'collaboration_rule';
        }

        return 'context_hint';
    }

    private function normalizeForMatch(string $value): string
    {
        return OperatorLearningDetectSupport::normalizeForMatch($value);
    }
}
