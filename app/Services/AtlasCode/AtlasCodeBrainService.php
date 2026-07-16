<?php

declare(strict_types=1);

namespace App\Services\AtlasCode;

use App\Services\Ai\AtlasOpenBrainService;
use App\Services\Engineering\EngineeringKnowledgeBaseService;
use Throwable;

/**
 * Atlas Código · a cauda longa vai ao cérebro (ACOS).
 *
 * O que só o Atlas pode fazer: cruzar git + ledger + canon + decisão. Um
 * cliente de git responde "o que mudou"; nenhum responde "por que esta linha
 * existe" — isso exige memória governada. É por aqui que a pílula alcança
 * essa camada.
 *
 * TRÊS LEIS, e a terceira é a que mais importa:
 *
 * 1. Provider-safe: o Open Brain devolve top-K curado, auditado, sem vazar
 *    prompt/trace. Este service não redige resposta com modelo — ele CITA o
 *    que o cérebro conhece. Retrieval não alucina; geração alucina.
 *
 * 2. Fail-open: cérebro fora do ar não vira erro na cara do operador nem,
 *    pior, resposta inventada. Vira ausência dita.
 *
 * 3. PISO DE RELEVÂNCIA. Medido em 15/07/2026: perguntando "por que existe a
 *    regra de trabalhar só na main?" o cérebro devolveu, no topo, "Atlas AI
 *    Skill System v1" (score 0.317) — e o doc que responde a pergunta,
 *    `atlas-local-main-only-rule.md`, existe no repo e NÃO foi indexado no
 *    índice semântico. Mostrar aquelas fontes como resposta seria o pior tipo
 *    de ruído: confiante, plausível e inútil. Abaixo do piso, o Atlas diz que
 *    não tem fonte boa — e essa é a resposta honesta até o índice cobrir o
 *    canon do Código.
 */
final class AtlasCodeBrainService
{
    public const SOURCE = 'brain';

    /**
     * Abaixo disto a fonte não sustenta uma afirmação.
     *
     * Não é um número mágico: com o índice de hoje NENHUMA fonte passa deste
     * piso para pergunta de código, e é exatamente por isso que ele existe.
     * Quando o canon do Código entrar no índice, a pílula acende sozinha —
     * sem mudar uma linha aqui.
     */
    private const RELEVANCE_FLOOR = 0.45;

    private const MAX_SOURCES = 3;

    public function __construct(
        private readonly ?AtlasOpenBrainService $brain = null,
        private readonly ?EngineeringKnowledgeBaseService $knowledge = null,
    ) {}

    /**
     * @return array{answered:bool, answer:string, evidence:array<int,array<string,string>>, source:string}
     */
    public function consult(string $question, string $workspacePath): array
    {
        $refs = $this->contextRefs($question, $workspacePath);

        if ($refs === null) {
            return [
                'answered' => false,
                'answer' => 'o cérebro não respondeu agora — e eu não vou inventar por ele.',
                'evidence' => [],
                'source' => self::SOURCE,
            ];
        }

        // As duas metades do cérebro, somadas: o índice semântico (vault) e o
        // canon de engenharia (825 docs ativos). Até 15/07/2026 o pack só via a
        // primeira — por construção, o Atlas era cego para os documentos que
        // governam o próprio código.
        $relevant = array_merge($this->aboveFloor($refs), $this->canonRefs($question));

        // As duas metades LEEM os mesmos documentos por caminhos diferentes, e
        // o mesmo doc chegava duas vezes: "o cérebro conhece 6 fontes" com 3
        // documentos — contagem dobrada é a tela inflando a própria erudição.
        // Dedup por caminho (fallback: rótulo), primeira ocorrência vence.
        $vistos = [];
        $relevant = array_values(array_filter($relevant, function (array $ref) use (&$vistos): bool {
            $chave = is_string($ref['path'] ?? null) && $ref['path'] !== '' ? $ref['path'] : $this->label($ref);
            if (isset($vistos[$chave])) {
                return false;
            }
            $vistos[$chave] = true;

            return true;
        }));

        if ($relevant === []) {
            return [
                'answered' => false,
                'answer' => 'essa pergunta precisa de julgamento, e o cérebro não tem fonte boa sobre isso ainda.',
                'evidence' => [],
                'source' => self::SOURCE,
            ];
        }

        $evidence = [];
        foreach ($relevant as $ref) {
            $evidence[] = array_filter([
                'kind' => 'canon',
                'ref' => $this->label($ref),
                'canon' => is_string($ref['path'] ?? null) ? $ref['path'] : null,
            ], static fn (mixed $value): bool => $value !== null);
        }

        $count = count($relevant);
        $noun = $count === 1 ? 'fonte' : 'fontes';

        // Retrieval, não geração: o Atlas diz o que CONHECE, e o operador lê a
        // fonte. Uma frase redigida por modelo aqui seria plausível e
        // inconferível — o oposto de proveniência.
        return [
            'answered' => true,
            // "não RECONHECI como filtro", nunca "não É um filtro": a frase
            // antiga afirmava sobre a PERGUNTA o que só se sabe sobre o
            // roteador — "what changed today?" É o filtro do dia, em inglês, e
            // era negado com confiança. Falar de si é honesto; falar da
            // pergunta é chute com voz de autoridade.
            'answer' => "não reconheci a pergunta como um filtro do grafo, mas o cérebro conhece {$count} {$noun} sobre isso.",
            'evidence' => $evidence,
            'source' => self::SOURCE,
        ];
    }

    /**
     * @param  array<int, array<string,mixed>>  $refs
     * @return array<int, array<string,mixed>>
     */
    public function aboveFloor(array $refs): array
    {
        $scored = array_values(array_filter($refs, static function (mixed $ref): bool {
            return is_array($ref)
                && is_numeric($ref['score'] ?? null)
                && (float) $ref['score'] >= self::RELEVANCE_FLOOR;
        }));

        usort($scored, static fn (array $a, array $b): int => (float) $b['score'] <=> (float) $a['score']);

        return array_slice($scored, 0, self::MAX_SOURCES);
    }

    public function label(array $ref): string
    {
        foreach (['title', 'slug', 'path'] as $key) {
            if (is_string($ref[$key] ?? null) && trim($ref[$key]) !== '') {
                return trim($ref[$key]);
            }
        }

        return 'fonte sem título';
    }

    /**
     * O canon de engenharia — a metade do cérebro que o pack não enxergava.
     *
     * @return array<int, array<string,mixed>>
     */
    private function canonRefs(string $question): array
    {
        try {
            $found = ($this->knowledge ?? app(EngineeringKnowledgeBaseService::class))->search($question, 3);
        } catch (Throwable) {
            // KB indisponível não derruba a pergunta nem vira invenção: o que o
            // índice semântico achou continua valendo.
            return [];
        }

        return $this->withinMarginOfTheBest($found);
    }

    /**
     * Só o que sustenta a afirmação fica.
     *
     * Medido: "por que existe a regra da main?" traz o canon certo a 0.883 e um
     * backlog que cita as três palavras de passagem a 0.600. Listar os dois faz
     * a frase dizer "o cérebro conhece 2 fontes" — dando ao segundo o mesmo
     * peso do primeiro. Fonte fraca ao lado da forte não soma: dilui.
     *
     * @param  array<int, array<string,mixed>>  $refs  Já ordenados por score.
     * @return array<int, array<string,mixed>>
     */
    public function withinMarginOfTheBest(array $refs, float $margin = 0.7): array
    {
        $best = (float) ($refs[0]['score'] ?? 0);
        if ($best <= 0.0) {
            return [];
        }

        return array_values(array_filter(
            $refs,
            static fn (array $ref): bool => (float) ($ref['score'] ?? 0) >= $best * $margin
        ));
    }

    /**
     * @return array<int, array<string,mixed>>|null  null = o cérebro não falou.
     */
    private function contextRefs(string $question, string $workspacePath): ?array
    {
        try {
            $pack = ($this->brain ?? app(AtlasOpenBrainService::class))->contextPack([
                'objective' => $question,
                'workspace' => $workspacePath,
                'task_type' => 'review',
                'agent' => 'atlas-code',
                'intent' => 'code_question',
            ], 'api');

            $refs = $pack['context_refs'] ?? null;

            return is_array($refs) ? array_values(array_filter($refs, 'is_array')) : [];
        } catch (Throwable) {
            // Cérebro indisponível é ausência, nunca invenção.
            return null;
        }
    }
}
