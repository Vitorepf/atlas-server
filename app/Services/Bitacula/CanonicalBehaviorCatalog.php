<?php

namespace App\Services\Bitacula;

class CanonicalBehaviorCatalog
{
    public const NORMALIZER_VERSION = 'canonical_catalog_v2';

    public function all(): array
    {
        return array_map(
            fn (array $factor): array => $this->publicFactor($factor),
            $this->factors(),
        );
    }

    public function normalize(string $text, int $limit = 5): array
    {
        $normalized = $this->normalizeText($text);
        if ($normalized === '') {
            return [
                'input_text' => $text,
                'normalized_text' => $normalized,
                'normalizer' => self::NORMALIZER_VERSION,
                'suggestions' => [],
            ];
        }

        $suggestions = [
            ...$this->matchCaffeine($text, $normalized),
            ...$this->matchFactorAliases($text, $normalized),
        ];

        $byId = [];
        foreach ($suggestions as $suggestion) {
            $id = $suggestion['id'];
            if (! isset($byId[$id]) || $suggestion['confidence'] > $byId[$id]['confidence']) {
                $byId[$id] = $suggestion;
            }
        }

        $suggestions = array_values($byId);
        usort($suggestions, fn (array $a, array $b): int => $b['confidence'] <=> $a['confidence']);

        return [
            'input_text' => $text,
            'normalized_text' => $normalized,
            'normalizer' => self::NORMALIZER_VERSION,
            'suggestions' => array_slice($suggestions, 0, max(1, min($limit, 12))),
        ];
    }

    public function bestSuggestion(string $text): ?array
    {
        $result = $this->normalize($text, 1);

        return $result['suggestions'][0] ?? null;
    }

    public function behaviorPayload(array $suggestion): array
    {
        return [
            'name' => $suggestion['label'],
            'category' => $suggestion['category'],
            'input_type' => 'yes_no',
            'question_text' => $suggestion['question_text'],
            'default_value' => 'no',
            'parent_factor' => $suggestion['parent_factor'],
            'factor_condition' => $suggestion['factor_condition'],
            'target_outcomes' => $suggestion['target_outcomes'],
            'expected_lag' => $suggestion['expected_lag'],
            'expected_direction' => $suggestion['expected_direction'],
            'granularity_level' => $suggestion['granularity_level'],
            'sensitivity_level' => $suggestion['sensitivity_level'],
            'derived_from' => [
                'normalizer' => self::NORMALIZER_VERSION,
                'canonical_factor' => $suggestion['id'],
                'matched_text' => $suggestion['matched_text'],
                'match_reason' => $suggestion['match_reason'],
                'confidence' => $suggestion['confidence'],
            ],
            'operator_confirmed' => true,
            'created_by' => 'operator',
        ];
    }

    private function matchCaffeine(string $text, string $normalized): array
    {
        if (! $this->hasAny($normalized, $this->caffeineTerms())) {
            return [];
        }

        $hours = $this->extractClockHours($normalized);
        if ($this->hasLateCaffeineTiming($normalized, $hours)) {
            return [
                $this->suggestion(
                    id: 'caffeine_late',
                    text: $text,
                    confidence: $this->hasClockAfter($hours, 14) ? 0.96 : 0.91,
                    reason: $this->hasClockAfter($hours, 14) ? 'caffeine_alias+clock_after_14h' : 'caffeine_alias+semantic_late_timing',
                ),
            ];
        }

        if ($this->hasMorningTiming($normalized, $hours)) {
            return [
                $this->suggestion(
                    id: 'caffeine_morning',
                    text: $text,
                    confidence: 0.82,
                    reason: 'caffeine_alias+morning_timing',
                ),
            ];
        }

        return [
            $this->suggestion(
                id: 'caffeine_any',
                text: $text,
                confidence: 0.62,
                reason: 'caffeine_alias_without_condition',
            ),
        ];
    }

    private function matchFactorAliases(string $text, string $normalized): array
    {
        $matches = [];

        foreach ($this->factors() as $factor) {
            if (str_starts_with($factor['id'], 'caffeine_')) {
                continue;
            }

            if ($this->hasAny($normalized, $factor['aliases'] ?? [])) {
                $matches[] = $this->suggestion(
                    id: $factor['id'],
                    text: $text,
                    confidence: $factor['confidence'] ?? 0.78,
                    reason: 'alias_match',
                );
            }
        }

        return $matches;
    }

    private function suggestion(string $id, string $text, float $confidence, string $reason): array
    {
        $factor = $this->factorById($id);

        return [
            ...$this->publicFactor($factor),
            'confidence' => $confidence,
            'matched_text' => $text,
            'match_reason' => $reason,
            'operator_action' => 'confirm',
            'payload' => $this->behaviorPayload([
                ...$this->publicFactor($factor),
                'confidence' => $confidence,
                'matched_text' => $text,
                'match_reason' => $reason,
            ]),
        ];
    }

    private function publicFactor(array $factor): array
    {
        return [
            'id' => $factor['id'],
            'label' => $factor['label'],
            'category' => $factor['category'],
            'parent_factor' => $factor['parent_factor'],
            'factor_condition' => $factor['factor_condition'],
            'question_text' => $factor['question_text'],
            'target_outcomes' => $factor['target_outcomes'],
            'expected_lag' => $factor['expected_lag'],
            'expected_direction' => $factor['expected_direction'],
            'granularity_level' => $factor['granularity_level'],
            'sensitivity_level' => $factor['sensitivity_level'],
            'guidance' => $factor['guidance'] ?? null,
            'aliases' => $factor['aliases'] ?? [],
        ];
    }

    private function factorById(string $id): array
    {
        foreach ($this->factors() as $factor) {
            if ($factor['id'] === $id) {
                return $factor;
            }
        }

        throw new \InvalidArgumentException("Unknown Bitacula factor: {$id}");
    }

    private function hasLateCaffeineTiming(string $value, array $hours): bool
    {
        return $this->hasClockAfter($hours, 14)
            || $this->hasAny($value, [
                'tarde',
                'fim da tarde',
                'noite',
                'depois do almoco',
                'pos almoco',
                'apos almoco',
                'depois do almoço',
                'pós almoço',
                'após almoço',
            ]);
    }

    private function hasMorningTiming(string $value, array $hours): bool
    {
        foreach ($hours as $hour) {
            if ($hour >= 4 && $hour < 12) {
                return true;
            }
        }

        return $this->hasAny($value, [
            'manha',
            'manhã',
            'cedo',
            'acordar',
            'ao acordar',
            'cafe da manha',
            'café da manhã',
        ]);
    }

    private function hasClockAfter(array $hours, int $threshold): bool
    {
        foreach ($hours as $hour) {
            if ($hour >= $threshold) {
                return true;
            }
        }

        return false;
    }

    private function extractClockHours(string $value): array
    {
        $hours = [];
        $patterns = [
            '/\b(?:apos as|apos das?|apos de|apos|pos as|pos das?|pos|depois das?|depois de|a partir das?|a partir de|por volta das?|perto das?|la pelas?|as)\s+(\d{1,2})(?:\s+(\d{2}))?\s*(?:h|horas?)?\b/u',
            '/\b(\d{1,2})\s*(?:h|horas?)\b/u',
        ];

        foreach ($patterns as $pattern) {
            if (preg_match_all($pattern, $value, $matches, PREG_SET_ORDER)) {
                foreach ($matches as $match) {
                    $hour = (int) $match[1];
                    $minute = isset($match[2]) && $match[2] !== '' ? (int) $match[2] : 0;

                    if ($hour >= 0 && $hour <= 23 && $minute >= 0 && $minute <= 59) {
                        $hours[$hour] = $hour;
                    }
                }
            }
        }

        return array_values($hours);
    }

    private function hasAny(string $value, array $needles): bool
    {
        $haystack = " {$value} ";

        foreach ($needles as $needle) {
            $normalizedNeedle = $this->normalizeText($needle);
            if ($normalizedNeedle !== '' && str_contains($haystack, " {$normalizedNeedle} ")) {
                return true;
            }
        }

        return false;
    }

    private function normalizeText(string $value): string
    {
        $withoutAccents = iconv('UTF-8', 'ASCII//TRANSLIT//IGNORE', $value);
        $withoutAccents = $withoutAccents === false ? $value : $withoutAccents;
        $lower = mb_strtolower($withoutAccents, 'UTF-8');
        $spaced = preg_replace('/[^a-z0-9]+/u', ' ', $lower) ?? '';

        return trim(preg_replace('/\s+/u', ' ', $spaced) ?? '');
    }

    private function caffeineTerms(): array
    {
        return [
            'cafe',
            'cafezinho',
            'cafeina',
            'espresso',
            'expresso',
            'capuccino',
            'cappuccino',
            'pre treino',
            'pre-treino',
            'pre workout',
            'preworkout',
            'mate',
            'cha mate',
            'erva mate',
            'terere',
            'tereré',
            'chimarrao',
            'chimarrão',
            'guarana',
            'guaraná',
            'energetico',
            'energético',
            'red bull',
            'monster',
            'cha preto',
            'chá preto',
            'cha verde',
            'chá verde',
        ];
    }

    private function factors(): array
    {
        return [
            [
                'id' => 'caffeine_late',
                'label' => 'Cafeína após 14h',
                'category' => 'substancias',
                'parent_factor' => 'caffeine',
                'factor_condition' => 'after_14h',
                'question_text' => 'Ontem teve cafeína depois das 14h?',
                'target_outcomes' => ['sleep', 'hrv', 'resting_heart_rate', 'anxiety'],
                'expected_lag' => 'same_night_next_morning',
                'expected_direction' => 'negative',
                'granularity_level' => 'binary',
                'sensitivity_level' => 'normal',
                'aliases' => [],
            ],
            [
                'id' => 'caffeine_morning',
                'label' => 'Cafeína pela manhã',
                'category' => 'substancias',
                'parent_factor' => 'caffeine',
                'factor_condition' => 'morning',
                'question_text' => 'Ontem teve cafeína pela manhã?',
                'target_outcomes' => ['focus', 'anxiety', 'appetite', 'reflux'],
                'expected_lag' => 'same_day',
                'expected_direction' => 'mixed',
                'granularity_level' => 'binary',
                'sensitivity_level' => 'normal',
                'guidance' => 'Use só se houver hipótese sobre foco, ansiedade, apetite ou refluxo. Se for diário, trate como baseline.',
                'aliases' => [],
            ],
            [
                'id' => 'caffeine_any',
                'label' => 'Cafeína',
                'category' => 'substancias',
                'parent_factor' => 'caffeine',
                'factor_condition' => 'any',
                'question_text' => 'Ontem teve cafeína?',
                'target_outcomes' => ['focus', 'sleep', 'anxiety'],
                'expected_lag' => 'same_day_or_same_night',
                'expected_direction' => 'mixed',
                'granularity_level' => 'intensity',
                'sensitivity_level' => 'normal',
                'guidance' => 'Fator amplo. Melhor especificar horário se a hipótese for sono ou ansiedade.',
                'aliases' => [],
            ],
            [
                'id' => 'alcohol_any',
                'label' => 'Álcool',
                'category' => 'substancias',
                'parent_factor' => 'alcohol',
                'factor_condition' => 'any',
                'question_text' => 'Ontem teve álcool?',
                'target_outcomes' => ['sleep', 'hrv', 'resting_heart_rate', 'mood'],
                'expected_lag' => 'same_night_next_morning',
                'expected_direction' => 'negative',
                'granularity_level' => 'binary',
                'sensitivity_level' => 'sensitive',
                'confidence' => 0.92,
                'aliases' => ['alcool', 'álcool', 'cerveja', 'vinho', 'drink', 'whisky', 'vodka'],
            ],
            [
                'id' => 'late_heavy_meal',
                'label' => 'Jantar pesado tarde',
                'category' => 'alimentacao',
                'parent_factor' => 'meal',
                'factor_condition' => 'heavy_late',
                'question_text' => 'Ontem teve jantar pesado ou tarde?',
                'target_outcomes' => ['sleep', 'hrv', 'reflux', 'energy'],
                'expected_lag' => 'same_night_next_morning',
                'expected_direction' => 'negative',
                'granularity_level' => 'binary',
                'sensitivity_level' => 'normal',
                'confidence' => 0.9,
                'aliases' => ['jantar pesado', 'refeicao tarde', 'refeição tarde', 'comida pesada', 'jantar tarde', 'comer tarde', '21h', '22h'],
            ],
            [
                'id' => 'screen_in_bed',
                'label' => 'Tela na cama',
                'category' => 'digital',
                'parent_factor' => 'screen',
                'factor_condition' => 'in_bed',
                'question_text' => 'Ontem teve tela na cama?',
                'target_outcomes' => ['sleep', 'mood', 'focus'],
                'expected_lag' => 'same_night_next_morning',
                'expected_direction' => 'negative',
                'granularity_level' => 'binary',
                'sensitivity_level' => 'normal',
                'confidence' => 0.9,
                'aliases' => ['tela na cama', 'celular na cama', 'iphone na cama', 'scroll na cama', 'doomscroll noite'],
            ],
            [
                'id' => 'intense_training_evening',
                'label' => 'Treino intenso à noite',
                'category' => 'treino_movimento',
                'parent_factor' => 'training',
                'factor_condition' => 'intense_evening',
                'question_text' => 'Ontem teve treino intenso à noite?',
                'target_outcomes' => ['sleep', 'hrv', 'resting_heart_rate', 'energy'],
                'expected_lag' => 'same_night_next_morning',
                'expected_direction' => 'mixed',
                'granularity_level' => 'binary',
                'sensitivity_level' => 'normal',
                'confidence' => 0.86,
                'aliases' => ['treino intenso noite', 'treino pesado noite', 'treino a noite', 'cardio noite'],
            ],
            [
                'id' => 'relational_conflict',
                'label' => 'Conversa difícil',
                'category' => 'relacional',
                'parent_factor' => 'relational_stress',
                'factor_condition' => 'difficult_conversation',
                'question_text' => 'Ontem teve conversa difícil ou conflito?',
                'target_outcomes' => ['sleep', 'hrv', 'mood', 'focus'],
                'expected_lag' => 'same_day_next_morning',
                'expected_direction' => 'negative',
                'granularity_level' => 'binary',
                'sensitivity_level' => 'relational',
                'confidence' => 0.88,
                'aliases' => ['conflito', 'briga', 'discussao', 'discussão', 'conversa dificil', 'conversa difícil', 'tensao com', 'tensão com'],
            ],
            [
                'id' => 'social_event',
                'label' => 'Socialização relevante',
                'category' => 'relacional',
                'parent_factor' => 'social_contact',
                'factor_condition' => 'meaningful',
                'question_text' => 'Ontem teve socialização relevante?',
                'target_outcomes' => ['mood', 'energy', 'sleep'],
                'expected_lag' => 'same_day_next_morning',
                'expected_direction' => 'mixed',
                'granularity_level' => 'binary',
                'sensitivity_level' => 'relational',
                'confidence' => 0.72,
                'aliases' => ['social', 'amigos', 'familia', 'família', 'evento', 'jantar com', 'encontro'],
            ],
            [
                'id' => 'algorithmic_input_morning',
                'label' => 'Input algorítmico pela manhã',
                'category' => 'digital',
                'parent_factor' => 'algorithmic_input',
                'factor_condition' => 'morning',
                'question_text' => 'Ontem teve input algorítmico antes do trabalho?',
                'target_outcomes' => ['focus', 'mood', 'application_ratio'],
                'expected_lag' => 'same_day',
                'expected_direction' => 'negative',
                'granularity_level' => 'binary',
                'sensitivity_level' => 'normal',
                'confidence' => 0.88,
                'aliases' => ['youtube manha', 'youtube manhã', 'rede social manha', 'rede social manhã', 'instagram manha', 'instagram manhã', 'tiktok manha', 'tiktok manhã', 'input algoritmico', 'input algorítmico'],
            ],
            [
                'id' => 'recovery_protocol',
                'label' => 'Protocolo de recuperação',
                'category' => 'recuperacao',
                'parent_factor' => 'recovery_protocol',
                'factor_condition' => 'any',
                'question_text' => 'Ontem teve protocolo de recuperação relevante?',
                'target_outcomes' => ['hrv', 'energy', 'sleep', 'mood'],
                'expected_lag' => 'same_day_next_morning',
                'expected_direction' => 'positive',
                'granularity_level' => 'binary',
                'sensitivity_level' => 'normal',
                'guidance' => 'Fator amplo. Em experimento, separar protocolo, duração e horário.',
                'confidence' => 0.78,
                'aliases' => ['sauna', 'banho frio', 'banho gelado', 'crioterapia', 'massagem', 'mobilidade', 'alongamento', 'respiracao', 'respiração', 'nsdr', 'yoga nidra', 'meditacao', 'meditação'],
            ],
            [
                'id' => 'travel_or_routine_break',
                'label' => 'Viagem ou quebra de rotina',
                'category' => 'ambiente_rotina',
                'parent_factor' => 'routine',
                'factor_condition' => 'break',
                'question_text' => 'Ontem teve viagem ou quebra forte de rotina?',
                'target_outcomes' => ['sleep', 'hrv', 'energy', 'focus'],
                'expected_lag' => 'same_day_next_morning',
                'expected_direction' => 'mixed',
                'granularity_level' => 'binary',
                'sensitivity_level' => 'normal',
                'confidence' => 0.84,
                'aliases' => ['viagem', 'viajei', 'deslocamento', 'fora da rotina', 'rotina fora'],
            ],
            [
                'id' => 'illness_symptom',
                'label' => 'Doença ou sintoma',
                'category' => 'saude_sintoma',
                'parent_factor' => 'symptom',
                'factor_condition' => 'illness_or_pain',
                'question_text' => 'Ontem teve doença, dor ou sintoma relevante?',
                'target_outcomes' => ['sleep', 'hrv', 'energy', 'mood'],
                'expected_lag' => 'same_day_next_morning',
                'expected_direction' => 'negative',
                'granularity_level' => 'binary',
                'sensitivity_level' => 'medical',
                'confidence' => 0.82,
                'aliases' => ['doente', 'doenca', 'doença', 'dor', 'enxaqueca', 'sintoma', 'febre', 'gripe'],
            ],
            [
                'id' => 'medication_or_supplement',
                'label' => 'Medicação ou suplemento',
                'category' => 'substancias',
                'parent_factor' => 'medication_supplement',
                'factor_condition' => 'any',
                'question_text' => 'Ontem teve medicação ou suplemento relevante?',
                'target_outcomes' => ['sleep', 'hrv', 'energy', 'mood'],
                'expected_lag' => 'same_day_next_morning',
                'expected_direction' => 'mixed',
                'granularity_level' => 'intensity',
                'sensitivity_level' => 'medical',
                'guidance' => 'Fator amplo. Em experimento, separar substância, dose e horário.',
                'confidence' => 0.78,
                'aliases' => ['remedio', 'remédio', 'medicacao', 'medicação', 'medicamento', 'suplemento', 'creatina', 'magnesio', 'magnésio', 'melatonina'],
            ],
        ];
    }
}
