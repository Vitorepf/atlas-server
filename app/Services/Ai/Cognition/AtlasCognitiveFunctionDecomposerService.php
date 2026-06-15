<?php

declare(strict_types=1);

namespace App\Services\Ai\Cognition;

use App\Services\Ai\Support\AppendOnlyJsonlStore;
use DateTimeImmutable;
use DateTimeInterface;
use DateTimeZone;

/**
 * Atlas Cognitive Function Decomposer Service — Patamar 4 F5.
 *
 * Decompose um pedido humano natural + contexto em uma tupla canônica de 6
 * funções cognitivas com pesos 0.0–1.0:
 *
 *   reasoning · retrieval · generation · code · vision · audit
 *
 * Resultado alimenta o Cognitive Function Atlas (probe de gaps) e os
 * roteadores de fluxo (Mission Mode, Hyperflow, SDD/BDD, Programming
 * Governance) — não substitui nenhum deles; entrega o vetor cognitivo
 * que os outros já assumem como dado.
 *
 * Heurística pura + rules + opcional metadata.framework override.
 * Determinístico para o mesmo input. Append-only JSONL receipt com
 * `decomposition_hash` sha256.
 *
 * Authority doc: docs/engineering-knowledge-base/atlas-cognitive-function-decomposer.md
 *
 * Schemas:
 *   - atlas.cognitive_function.decomposition.v1
 *
 * Invariants:
 *   - 6 funções canon, ordem fixa.
 *   - Pesos sempre em [0.0, 1.0].
 *   - Soma normalizada para 1.0 (probability distribution).
 *   - Dominant function = arg max(weights).
 *   - claim_policy provider-safe.
 *   - Append-only JSONL.
 */
final class AtlasCognitiveFunctionDecomposerService
{
    public const SCHEMA = 'atlas.cognitive_function.decomposition.v1';

    public const FUNCTIONS = [
        'reasoning',
        'retrieval',
        'generation',
        'code',
        'vision',
        'audit',
    ];

    /**
     * Keyword rules (case-insensitive, accent-stripped) — heuristic only.
     * Operator can extend via PR; doc lists them.
     *
     * @var array<string, array<int,string>>
     */
    private const RULES = [
        'reasoning' => [
            'porque', 'por que', 'analise', 'analisa', 'explique', 'pense', 'pondere',
            'decida', 'decisao', 'compare', 'avalie', 'logica', 'estrategia',
            'raciocine', 'investigue', 'why', 'reason',
        ],
        'retrieval' => [
            'busque', 'procure', 'encontre', 'pesquise', 'mostre', 'liste',
            'recupere', 'lookup', 'qual e', 'quais sao', 'cite', 'cadastr',
            'documenta', 'memoria', 'search', 'find', 'show',
        ],
        'generation' => [
            'escreva', 'redija', 'crie', 'componha', 'rascunhe', 'gere',
            'sintetize', 'resuma', 'transforme', 'reescreva', 'continue',
            'narre', 'descreva', 'compose', 'write', 'draft', 'summarize',
        ],
        'code' => [
            'codigo', 'codifique', 'implemente', 'refatore', 'debug', 'teste',
            'compile', 'execute', 'rode', 'rodar', 'php', 'typescript', 'react',
            'componente', 'servico', 'classe', 'funcao', 'controller', 'cli',
            'artisan', 'migration', 'composer', 'npm', 'phpunit', 'pest',
            'patch', 'pull request', 'pr ', ' pr,', 'merge', 'git ',
            'code', 'function', 'class', 'service', 'refactor', 'test', 'build',
        ],
        'vision' => [
            'imagem', 'foto', 'screenshot', 'visualize', 'design', 'layout',
            'mockup', 'figma', 'png', 'jpg', 'svg', 'tela', 'ui ', 'ux ',
            'cor ', 'paleta', 'visual', 'screenshot', 'image', 'render',
        ],
        'audit' => [
            'audite', 'audita', 'audit', 'verifique', 'valide', 'cheque',
            'inspecione', 'governance', 'invariant', 'kernel', 'cartografia',
            'doc', 'documento', 'compliance', 'evidence', 'evidencia',
            'integrity', 'tamper', 'sha256', 'hash ', 'verify',
        ],
    ];

    private ?string $logPathOverride = null;

    private readonly CognitiveContextNudgeApplier $nudges;

    public function __construct(?CognitiveContextNudgeApplier $nudges = null)
    {
        $this->nudges = $nudges ?? new CognitiveContextNudgeApplier();
    }

    public function setLogPathForTesting(?string $path): void
    {
        $this->logPathOverride = $path;
    }

    public function logPath(): string
    {
        if ($this->logPathOverride !== null) {
            return $this->logPathOverride;
        }
        $base = function_exists('storage_path')
            ? storage_path('atlas/cognition')
            : sys_get_temp_dir().'/atlas/cognition';

        return $base.DIRECTORY_SEPARATOR.'function_decompositions.jsonl';
    }

    /**
     * Decompose a natural-language input into the 6-axis cognitive function
     * tuple. `context` may carry framework/role/privacy_class — currently
     * `framework=cartography_audit|kernel_vault|programming_*` nudges
     * specific axes.
     *
     * @param  array<string,mixed>  $context
     * @return array<string,mixed>
     */
    public function decompose(string $input, array $context = []): array
    {
        $input = trim($input);
        $normalized = $this->normalize($input);
        $weights = array_fill_keys(self::FUNCTIONS, 0.0);

        if ($input === '') {
            // Empty input — neutral distribution + audit lean (something is wrong).
            $weights['audit'] = 1.0;

            return $this->envelope($input, $context, $weights, ['reason' => 'empty_input']);
        }

        // Score rules.
        $hits = array_fill_keys(self::FUNCTIONS, 0);
        foreach (self::RULES as $axis => $keywords) {
            foreach ($keywords as $kw) {
                $needle = $this->normalize($kw);
                if ($needle === '') {
                    continue;
                }
                if (str_contains($normalized, $needle)) {
                    $hits[$axis]++;
                }
            }
        }

        // Framework + role nudges (relocated to CognitiveContextNudgeApplier).
        $hits = $this->nudges->applyNudges($hits, $context);

        $totalHits = array_sum($hits);
        if ($totalHits === 0) {
            // No signals — neutral but lean toward reasoning (default cognitive default).
            $weights = ['reasoning' => 0.5, 'retrieval' => 0.2, 'generation' => 0.15, 'code' => 0.05, 'vision' => 0.05, 'audit' => 0.05];

            return $this->envelope($input, $context, $weights, ['reason' => 'no_keyword_signal', 'hits' => $hits]);
        }

        foreach (self::FUNCTIONS as $axis) {
            $weights[$axis] = $hits[$axis] / $totalHits;
        }

        return $this->envelope($input, $context, $weights, ['hits' => $hits]);
    }

    /**
     * @return list<array<string,mixed>>
     */
    public function listDecompositions(int $tail = 50): array
    {
        $all = AppendOnlyJsonlStore::read($this->logPath());
        if ($tail <= 0) {
            return $all;
        }

        return array_slice($all, -$tail);
    }

    public function lastDecomposition(): ?array
    {
        $all = AppendOnlyJsonlStore::read($this->logPath());

        return $all === [] ? null : $all[count($all) - 1];
    }

    /**
     * @return array<string,bool>
     */
    public function claimPolicy(): array
    {
        return [
            'benchmark_claim_allowed' => false,
            'rivals_claim_allowed' => false,
            'superiority_claim_allowed' => false,
            'external_rivals_certification_touched' => false,
            'cognitive_immune_law_enforced' => true,
            'provider_safe_only_enforced' => true,
        ];
    }

    // ---------- internals ----------

    /**
     * @param  array<string,mixed>  $context
     * @param  array<string,float>  $weights
     * @param  array<string,mixed>  $debug
     * @return array<string,mixed>
     */
    private function envelope(string $input, array $context, array $weights, array $debug): array
    {
        // Normalize to sum exactly 1.0 (numeric stability).
        $sum = array_sum($weights);
        if ($sum > 0) {
            foreach ($weights as $k => $v) {
                $weights[$k] = round($v / $sum, 4);
            }
        }
        // Dominant function.
        $dominant = 'reasoning';
        $top = -1.0;
        foreach (self::FUNCTIONS as $axis) {
            if (($weights[$axis] ?? 0) > $top) {
                $top = $weights[$axis];
                $dominant = $axis;
            }
        }

        $generatedAt = (new DateTimeImmutable('now', new DateTimeZone('UTC')))->format(DateTimeInterface::ATOM);
        $envelope = [
            'schema_version' => self::SCHEMA,
            'generated_at' => $generatedAt,
            'input_length' => mb_strlen($input),
            'input_preview' => mb_substr($input, 0, 120),
            'context' => [
                'role' => $context['role'] ?? null,
                'framework' => $context['framework'] ?? null,
                'privacy_class' => $context['privacy_class'] ?? null,
            ],
            'weights' => $weights,
            'dominant_function' => $dominant,
            'debug' => $debug,
            'claim_policy' => $this->claimPolicy(),
        ];
        $envelope['decomposition_hash'] = 'sha256:'.hash('sha256', json_encode([
            'schema' => self::SCHEMA,
            'input' => $input,
            'weights' => $weights,
            'dominant' => $dominant,
            'context' => $envelope['context'],
        ], JSON_THROW_ON_ERROR));

        AppendOnlyJsonlStore::append($this->logPath(), $envelope);

        return $envelope;
    }

    private function normalize(string $s): string
    {
        $s = mb_strtolower($s);
        // Strip accents.
        $accent = ['á', 'à', 'â', 'ã', 'ä', 'é', 'è', 'ê', 'ë', 'í', 'ì', 'î', 'ï', 'ó', 'ò', 'ô', 'õ', 'ö', 'ú', 'ù', 'û', 'ü', 'ç', 'ñ'];
        $plain = ['a', 'a', 'a', 'a', 'a', 'e', 'e', 'e', 'e', 'i', 'i', 'i', 'i', 'o', 'o', 'o', 'o', 'o', 'u', 'u', 'u', 'u', 'c', 'n'];

        return str_replace($accent, $plain, $s);
    }

}
