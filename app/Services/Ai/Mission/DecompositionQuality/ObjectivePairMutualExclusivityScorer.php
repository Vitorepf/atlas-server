<?php

declare(strict_types=1);

namespace App\Services\Ai\Mission\DecompositionQuality;

final class ObjectivePairMutualExclusivityScorer
{
    private const SCHEMA_VERSION = 'atlas.aaeos.objective_pair_exclusivity.v1';

    private const MIN_TERM_LENGTH = 4;

    /**
     * Lower jaccard boundary (inclusive) at which a pair is graded overlapping.
     */
    private const OVERLAPPING_THRESHOLD = 0.5;

    /**
     * Inlined conjunction / preposition stopwords (length >= MIN_TERM_LENGTH).
     *
     * @var list<string>
     */
    private const CONJUNCTION_STOPWORDS = [
        'para',
        'como',
        'porque',
        'quando',
        'enquanto',
        'entre',
        'sobre',
        'conforme',
        'contudo',
        'porem',
        'ainda',
        'with',
        'from',
        'that',
        'into',
        'then',
    ];

    /**
     * Inlined action-verb stopwords (length >= MIN_TERM_LENGTH).
     *
     * @var list<string>
     */
    private const ACTION_VERB_STOPWORDS = [
        'criar',
        'gerar',
        'fazer',
        'montar',
        'construir',
        'desenvolver',
        'implementar',
        'configurar',
        'ajustar',
        'atualizar',
        'remover',
        'adicionar',
        'definir',
        'calcular',
        'processar',
        'validar',
        'enviar',
        'receber',
        'exibir',
        'mostrar',
        'listar',
        'buscar',
        'salvar',
        'deletar',
        'editar',
        'create',
        'build',
        'generate',
        'update',
        'remove',
    ];

    /**
     * @param array{title?: string, description?: string} $objectiveA
     * @param array{title?: string, description?: string} $objectiveB
     *
     * @return array{
     *     schema_version: string,
     *     exclusivity: float,
     *     classification: string,
     *     jaccard: float,
     *     shared_terms: list<string>,
     *     overlap_count: int
     * }
     */
    public function score(array $objectiveA, array $objectiveB): array
    {
        $termsA = $this->tokenize($objectiveA);
        $termsB = $this->tokenize($objectiveB);

        $shared = array_values(array_intersect($termsA, $termsB));
        sort($shared);

        $union = array_values(array_unique(array_merge($termsA, $termsB)));

        $unionCount = count($union);
        $overlapCount = count($shared);

        $jaccard = $unionCount === 0 ? 0.0 : (float) $overlapCount / (float) $unionCount;
        $exclusivity = round(1.0 - $jaccard, 2);

        return [
            'schema_version' => self::SCHEMA_VERSION,
            'exclusivity' => $exclusivity,
            'classification' => $this->classify($jaccard),
            'jaccard' => $jaccard,
            'shared_terms' => $shared,
            'overlap_count' => $overlapCount,
        ];
    }

    /**
     * @param array{title?: string, description?: string} $objective
     *
     * @return list<string>
     */
    private function tokenize(array $objective): array
    {
        $title = isset($objective['title']) ? (string) $objective['title'] : '';
        $description = isset($objective['description']) ? (string) $objective['description'] : '';

        $raw = preg_split('/[^\p{L}\p{N}]+/u', mb_strtolower($title.' '.$description), -1, PREG_SPLIT_NO_EMPTY);

        if ($raw === false) {
            return [];
        }

        $kept = [];

        foreach ($raw as $word) {
            if (mb_strlen($word) < self::MIN_TERM_LENGTH) {
                continue;
            }

            if (in_array($word, self::CONJUNCTION_STOPWORDS, true)) {
                continue;
            }

            if (in_array($word, self::ACTION_VERB_STOPWORDS, true)) {
                continue;
            }

            $kept[$word] = true;
        }

        return array_keys($kept);
    }

    private function classify(float $jaccard): string
    {
        if ($jaccard >= 1.0) {
            return 'duplicate';
        }

        if ($jaccard >= self::OVERLAPPING_THRESHOLD) {
            return 'overlapping';
        }

        if ($jaccard > 0.0) {
            return 'mostly_distinct';
        }

        return 'disjoint';
    }
}
