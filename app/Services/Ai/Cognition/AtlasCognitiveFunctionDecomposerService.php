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

    public const REASON_EMPTY_INPUT = 'empty_input';

    public const REASON_NO_KEYWORD_SIGNAL = 'no_keyword_signal';

    public const FIELD_REASONING = 'reasoning';
    public const FIELD_RETRIEVAL = 'retrieval';
    public const FIELD_GENERATION = 'generation';
    public const FIELD_CODE = 'code';
    public const FIELD_VISION = 'vision';
    public const FIELD_AUDIT = 'audit';
    public const FIELD_REASON = 'reason';
    public const FIELD_HITS = 'hits';
    public const FIELD_CONTEXT = 'context';
    public const FIELD_WEIGHTS = 'weights';
    public const FIELD_BENCHMARK_CLAIM_ALLOWED = 'benchmark_claim_allowed';
    public const FIELD_RIVALS_CLAIM_ALLOWED = 'rivals_claim_allowed';
    public const FIELD_SUPERIORITY_CLAIM_ALLOWED = 'superiority_claim_allowed';
    public const FIELD_EXTERNAL_RIVALS_CERTIFICATION_TOUCHED = 'external_rivals_certification_touched';
    public const FIELD_COGNITIVE_IMMUNE_LAW_ENFORCED = 'cognitive_immune_law_enforced';
    public const FIELD_PROVIDER_SAFE_ONLY_ENFORCED = 'provider_safe_only_enforced';

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
    public const RULES = [
        self::FIELD_REASONING => [
            'porque', 'por que', 'analise', 'analisa', 'explique', 'pense', 'pondere',
            'decida', 'decisao', 'compare', 'avalie', 'logica', 'estrategia',
            'raciocine', 'investigue', 'why', 'reason',
        ],
        self::FIELD_RETRIEVAL => [
            'busque', 'procure', 'encontre', 'pesquise', 'mostre', 'liste',
            'recupere', 'lookup', 'qual e', 'quais sao', 'cite', 'cadastr',
            'documenta', 'memoria', 'search', 'find', 'show',
        ],
        self::FIELD_GENERATION => [
            'escreva', 'redija', 'crie', 'componha', 'rascunhe', 'gere',
            'sintetize', 'resuma', 'transforme', 'reescreva', 'continue',
            'narre', 'descreva', 'compose', 'write', 'draft', 'summarize',
        ],
        self::FIELD_CODE => [
            'codigo', 'codifique', 'implemente', 'refatore', 'debug', 'teste',
            'compile', 'execute', 'rode', 'rodar', 'php', 'typescript', 'react',
            'componente', 'servico', 'classe', 'funcao', 'controller', 'cli',
            'artisan', 'migration', 'composer', 'npm', 'phpunit', 'pest',
            'patch', 'pull request', 'pr ', ' pr,', 'merge', 'git ',
            'code', 'function', 'class', 'service', 'refactor', 'test', 'build',
        ],
        self::FIELD_VISION => [
            'imagem', 'foto', 'screenshot', 'visualize', 'design', 'layout',
            'mockup', 'figma', 'png', 'jpg', 'svg', 'tela', 'ui ', 'ux ',
            'cor ', 'paleta', 'visual', 'screenshot', 'image', 'render',
        ],
        self::FIELD_AUDIT => [
            'audite', 'audita', 'audit', 'verifique', 'valide', 'cheque',
            'inspecione', 'governance', 'invariant', 'kernel', 'cartografia',
            'doc', 'documento', 'compliance', 'evidence', 'evidencia',
            'integrity', 'tamper', 'sha256', 'hash ', 'verify',
        ],
    ];

    private ?string $logPathOverride = null;

    private readonly CognitiveContextNudgeApplier $nudges;

    private readonly AtlasCognitiveFunctionDecomposerServiceSupport $support;

    public function __construct(?CognitiveContextNudgeApplier $nudges = null)
    {
        $this->nudges = $nudges ?? new CognitiveContextNudgeApplier();
        $this->support = new AtlasCognitiveFunctionDecomposerServiceSupport();
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
        $normalized = $this->support->normalize($input);
        $weights = array_fill_keys(self::FUNCTIONS, 0.0);

        if ($input === '') {
            // Empty input — neutral distribution + audit lean (something is wrong).
            $weights[self::FIELD_AUDIT] = 1.0;

            return $this->envelope($input, $context, $weights, [self::FIELD_REASON => self::REASON_EMPTY_INPUT]);
        }

        // Score rules.
        $hits = $this->support->scoreRuleHits(self::FUNCTIONS, self::RULES, $normalized);

        // Framework + role nudges (relocated to CognitiveContextNudgeApplier).
        $hits = $this->nudges->applyNudges($hits, $context);

        $totalHits = array_sum($hits);
        if ($totalHits === 0) {
            // No signals — neutral but lean toward reasoning (default cognitive default).
            $weights = [self::FIELD_REASONING => 0.5, self::FIELD_RETRIEVAL => 0.2, self::FIELD_GENERATION => 0.15, self::FIELD_CODE => 0.05, self::FIELD_VISION => 0.05, self::FIELD_AUDIT => 0.05];

            return $this->envelope($input, $context, $weights, [self::FIELD_REASON => self::REASON_NO_KEYWORD_SIGNAL, self::FIELD_HITS => $hits]);
        }

        foreach (self::FUNCTIONS as $axis) {
            $weights[$axis] = $hits[$axis] / $totalHits;
        }

        return $this->envelope($input, $context, $weights, [self::FIELD_HITS => $hits]);
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
            self::FIELD_BENCHMARK_CLAIM_ALLOWED => false,
            self::FIELD_RIVALS_CLAIM_ALLOWED => false,
            self::FIELD_SUPERIORITY_CLAIM_ALLOWED => false,
            self::FIELD_EXTERNAL_RIVALS_CERTIFICATION_TOUCHED => false,
            self::FIELD_COGNITIVE_IMMUNE_LAW_ENFORCED => true,
            self::FIELD_PROVIDER_SAFE_ONLY_ENFORCED => true,
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
            self::FIELD_CONTEXT => [
                'role' => $context['role'] ?? null,
                'framework' => $context['framework'] ?? null,
                'privacy_class' => $context['privacy_class'] ?? null,
            ],
            self::FIELD_WEIGHTS => $weights,
            'dominant_function' => $dominant,
            'debug' => $debug,
            'claim_policy' => $this->claimPolicy(),
        ];
        $envelope['decomposition_hash'] = 'sha256:'.hash('sha256', json_encode([
            'schema' => self::SCHEMA,
            'input' => $input,
            self::FIELD_WEIGHTS => $weights,
            'dominant' => $dominant,
            self::FIELD_CONTEXT => $envelope[self::FIELD_CONTEXT],
        ], JSON_THROW_ON_ERROR));

        AppendOnlyJsonlStore::append($this->logPath(), $envelope);

        return $envelope;
    }

}
