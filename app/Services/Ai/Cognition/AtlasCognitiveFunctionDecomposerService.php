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
    public const FIELD_CLAIM_POLICY = 'claim_policy';
    public const FIELD_DEBUG = 'debug';
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
    public const FIELD_FRAMEWORK = 'framework';
    public const FIELD_PRIVACY_CLASS = 'privacy_class';
    public const FIELD_ROLE = 'role';
    public const FIELD_INPUT_LENGTH = 'input_length';
    public const FIELD_INPUT_PREVIEW = 'input_preview';
    public const FIELD_DECOMPOSITION_HASH = 'decomposition_hash';
    public const FIELD_DOMINANT = 'dominant';
    public const FIELD_DOMINANT_FUNCTION = 'dominant_function';
    public const FIELD_GENERATED_AT = 'generated_at';
    public const FIELD_INPUT = 'input';
    public const FIELD_SCHEMA = 'schema';
    public const FIELD_SCHEMA_VERSION = 'schema_version';
    public const FIELD_ANALISE = 'analise';
    public const FIELD_ARTISAN = 'artisan';
    public const FIELD_ANALISA = 'analisa';
    public const FIELD_AUDITA = 'audita';
    public const FIELD_SHA256 = 'sha256';
    public const FIELD_AVALIE = 'avalie';
    public const FIELD_BUILD = 'build';
    public const FIELD_CARTOGRAFIA = 'cartografia';
    public const FIELD_CITE = 'cite';
    public const FIELD_CLASSE = 'classe';
    public const FIELD_CADASTR = 'cadastr';
    public const FIELD_CLI = 'cli';
    public const FIELD_CODIFIQUE = 'codifique';
    public const FIELD_COMPLIANCE = 'compliance';
    public const FIELD_COMPILE = 'compile';
    public const FIELD_COMPONHA = 'componha';
    public const FIELD_COMPONENTE = 'componente';
    public const FIELD_CONTINUE = 'continue';
    public const FIELD_COMPOSE = 'compose';
    public const FIELD_DECISAO = 'decisao';
    public const FIELD_COMPARE = 'compare';
    public const FIELD_DOC = 'doc';
    public const FIELD_DOCUMENTA = 'documenta';
    public const FIELD_DOCUMENTO = 'documento';
    public const FIELD_EVIDENCE = 'evidence';
    public const FIELD_EXECUTE = 'execute';
    public const FIELD_EVIDENCIA = 'evidencia';
    public const FIELD_EXPLIQUE = 'explique';
    public const FIELD_DECIDA = 'decida';
    public const FIELD_FIGMA = 'figma';
    public const FIELD_FIND = 'find';
    public const FIELD_FOTO = 'foto';
    public const FIELD_SCREENSHOT = 'screenshot';
    public const FIELD_DESIGN = 'design';
    public const FIELD_FUNCAO = 'funcao';
    public const FIELD_IMAGE = 'image';

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
            'porque', 'por que', self::FIELD_ANALISE, self::FIELD_ANALISA, self::FIELD_EXPLIQUE, 'pense', 'pondere',
            self::FIELD_DECIDA, self::FIELD_DECISAO, self::FIELD_COMPARE, self::FIELD_AVALIE, 'logica', 'estrategia',
            'raciocine', 'investigue', 'why', 'reason',
        ],
        self::FIELD_RETRIEVAL => [
            'busque', 'procure', 'encontre', 'pesquise', 'mostre', 'liste',
            'recupere', 'lookup', 'qual e', 'quais sao', self::FIELD_CITE, self::FIELD_CADASTR,
            self::FIELD_DOCUMENTA, 'memoria', 'search', self::FIELD_FIND, 'show',
        ],
        self::FIELD_GENERATION => [
            'escreva', 'redija', 'crie', self::FIELD_COMPONHA, 'rascunhe', 'gere',
            'sintetize', 'resuma', 'transforme', 'reescreva', self::FIELD_CONTINUE,
            'narre', 'descreva', self::FIELD_COMPOSE, 'write', 'draft', 'summarize',
        ],
        self::FIELD_CODE => [
            'codigo', self::FIELD_CODIFIQUE, 'implemente', 'refatore', 'debug', 'teste',
            self::FIELD_COMPILE, self::FIELD_EXECUTE, 'rode', 'rodar', 'php', 'typescript', 'react',
            self::FIELD_COMPONENTE, 'servico', self::FIELD_CLASSE, self::FIELD_FUNCAO, 'controller', self::FIELD_CLI,
            self::FIELD_ARTISAN, 'migration', 'composer', 'npm', 'phpunit', 'pest',
            'patch', 'pull request', 'pr ', ' pr,', 'merge', 'git ',
            'code', 'function', 'class', 'service', 'refactor', 'test', self::FIELD_BUILD,
        ],
        self::FIELD_VISION => [
            'imagem', self::FIELD_FOTO, self::FIELD_SCREENSHOT, 'visualize', self::FIELD_DESIGN, 'layout',
            'mockup', self::FIELD_FIGMA, 'png', 'jpg', 'svg', 'tela', 'ui ', 'ux ',
            'cor ', 'paleta', 'visual', self::FIELD_SCREENSHOT, self::FIELD_IMAGE, 'render',
        ],
        self::FIELD_AUDIT => [
            'audite', self::FIELD_AUDITA, 'audit', 'verifique', 'valide', 'cheque',
            'inspecione', 'governance', 'invariant', 'kernel', self::FIELD_CARTOGRAFIA,
            self::FIELD_DOC, self::FIELD_DOCUMENTO, self::FIELD_COMPLIANCE, self::FIELD_EVIDENCE, self::FIELD_EVIDENCIA,
            'integrity', 'tamper', self::FIELD_SHA256, 'hash ', 'verify',
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
            self::FIELD_SCHEMA_VERSION => self::SCHEMA,
            self::FIELD_GENERATED_AT => $generatedAt,
            self::FIELD_INPUT_LENGTH => mb_strlen($input),
            self::FIELD_INPUT_PREVIEW => mb_substr($input, 0, 120),
            self::FIELD_CONTEXT => [
                self::FIELD_ROLE => $context[self::FIELD_ROLE] ?? null,
                self::FIELD_FRAMEWORK => $context[self::FIELD_FRAMEWORK] ?? null,
                self::FIELD_PRIVACY_CLASS => $context[self::FIELD_PRIVACY_CLASS] ?? null,
            ],
            self::FIELD_WEIGHTS => $weights,
            self::FIELD_DOMINANT_FUNCTION => $dominant,
            self::FIELD_DEBUG => $debug,
            self::FIELD_CLAIM_POLICY => $this->claimPolicy(),
        ];
        $envelope[self::FIELD_DECOMPOSITION_HASH] = 'sha256:'.hash(self::FIELD_SHA256, json_encode([
            self::FIELD_SCHEMA => self::SCHEMA,
            self::FIELD_INPUT => $input,
            self::FIELD_WEIGHTS => $weights,
            self::FIELD_DOMINANT => $dominant,
            self::FIELD_CONTEXT => $envelope[self::FIELD_CONTEXT],
        ], JSON_THROW_ON_ERROR));

        AppendOnlyJsonlStore::append($this->logPath(), $envelope);

        return $envelope;
    }

}
