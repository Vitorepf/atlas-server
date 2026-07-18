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
    public const FIELD_CONTROLLER = 'controller';
    public const FIELD_IMPLEMENTE = 'implemente';
    public const FIELD_INSPECIONE = 'inspecione';
    public const FIELD_INTEGRITY = 'integrity';
    public const FIELD_GOVERNANCE = 'governance';
    public const FIELD_KERNEL = 'kernel';
    public const FIELD_INVARIANT = 'invariant';
    public const FIELD_LAYOUT = 'layout';
    public const FIELD_LISTE = 'liste';
    public const FIELD_LOGICA = 'logica';
    public const FIELD_LOOKUP = 'lookup';
    public const FIELD_ESTRATEGIA = 'estrategia';
    public const FIELD_INVESTIGUE = 'investigue';
    public const FIELD_MEMORIA = 'memoria';
    public const FIELD_MERGE = 'merge';
    public const FIELD_MIGRATION = 'migration';
    public const FIELD_MOCKUP = 'mockup';
    public const FIELD_COMPOSER = 'composer';
    public const FIELD_NARRE = 'narre';
    public const FIELD_NOW = 'now';
    public const FIELD_PALETA = 'paleta';
    public const FIELD_PATCH = 'patch';
    public const FIELD_PENSE = 'pense';
    public const FIELD_DESCREVA = 'descreva';
    public const FIELD_NPM = 'npm';
    public const FIELD_PESQUISE = 'pesquise';
    public const FIELD_PEST = 'pest';
    public const FIELD_PHP = 'php';
    public const FIELD_PNG = 'png';
    public const FIELD_JPG = 'jpg';
    public const FIELD_MOSTRE = 'mostre';
    public const FIELD_PHPUNIT = 'phpunit';
    public const FIELD_PONDERE = 'pondere';
    public const FIELD_PROCURE = 'procure';
    public const FIELD_RACIOCINE = 'raciocine';
    public const FIELD_ENCONTRE = 'encontre';
    public const FIELD_RASCUNHE = 'rascunhe';
    public const FIELD_RECUPERE = 'recupere';
    public const FIELD_REDIJA = 'redija';
    public const FIELD_REFACTOR = 'refactor';
    public const FIELD_REFATORE = 'refatore';
    public const FIELD_RENDER = 'render';
    public const FIELD_RODE = 'rode';
    public const FIELD_CRIE = 'crie';
    public const FIELD_GERE = 'gere';
    public const FIELD_REESCREVA = 'reescreva';
    public const FIELD_RESUMA = 'resuma';
    public const FIELD_RODAR = 'rodar';
    public const FIELD_SEARCH = 'search';
    public const FIELD_SERVICO = 'servico';
    public const FIELD_SHOW = 'show';
    public const FIELD_STORAGE_PATH = 'storage_path';
    public const FIELD_SUMMARIZE = 'summarize';
    public const FIELD_SINTETIZE = 'sintetize';
    public const FIELD_SVG = 'svg';
    public const FIELD_TAMPER = 'tamper';
    public const FIELD_TEST = 'test';
    public const FIELD_TRANSFORME = 'transforme';
    public const FIELD_TYPESCRIPT = 'typescript';
    public const FIELD_VALIDE = 'valide';
    public const FIELD_VERIFY = 'verify';
    public const FIELD_VISUAL = 'visual';
    public const FIELD_VISUALIZE = 'visualize';
    public const FIELD_CHEQUE = 'cheque';
    public const FIELD_REACT = 'react';
    public const FIELD_TELA = 'tela';
    public const FIELD_WHY = 'why';
    public const FIELD_WRITE = 'write';
    public const FIELD_DRAFT = 'draft';
    public const FIELD_AUDITE = 'audite';
    public const FIELD_BUSQUE = 'busque';
    public const FIELD_CODIGO = 'codigo';
    public const FIELD_ESCREVA = 'escreva';
    public const FIELD_IMAGEM = 'imagem';
    public const FIELD_PORQUE = 'porque';
    public const FIELD_SERVICE = 'service';
    public const FIELD_TESTE = 'teste';
    public const FIELD_VERIFIQUE = 'verifique';
    public const FIELD_UTC = 'UTC';
    public const FIELD_FUNCTION_DECOMPOSITIONS_JSONL = 'function_decompositions.jsonl';
    public const FLOAT_0_5 = 0.5;
    public const FLOAT_0_15 = 0.15;
    public const FLOAT_0_2 = 0.2;
    public const FLOAT_0_05 = 0.05;

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
            self::FIELD_PORQUE, 'por que', self::FIELD_ANALISE, self::FIELD_ANALISA, self::FIELD_EXPLIQUE, self::FIELD_PENSE, self::FIELD_PONDERE,
            self::FIELD_DECIDA, self::FIELD_DECISAO, self::FIELD_COMPARE, self::FIELD_AVALIE, self::FIELD_LOGICA, self::FIELD_ESTRATEGIA,
            self::FIELD_RACIOCINE, self::FIELD_INVESTIGUE, self::FIELD_WHY, 'reason',
        ],
        self::FIELD_RETRIEVAL => [
            self::FIELD_BUSQUE, self::FIELD_PROCURE, self::FIELD_ENCONTRE, self::FIELD_PESQUISE, self::FIELD_MOSTRE, self::FIELD_LISTE,
            self::FIELD_RECUPERE, self::FIELD_LOOKUP, 'qual e', 'quais sao', self::FIELD_CITE, self::FIELD_CADASTR,
            self::FIELD_DOCUMENTA, self::FIELD_MEMORIA, self::FIELD_SEARCH, self::FIELD_FIND, self::FIELD_SHOW,
        ],
        self::FIELD_GENERATION => [
            self::FIELD_ESCREVA, self::FIELD_REDIJA, self::FIELD_CRIE, self::FIELD_COMPONHA, self::FIELD_RASCUNHE, self::FIELD_GERE,
            self::FIELD_SINTETIZE, self::FIELD_RESUMA, self::FIELD_TRANSFORME, self::FIELD_REESCREVA, self::FIELD_CONTINUE,
            self::FIELD_NARRE, self::FIELD_DESCREVA, self::FIELD_COMPOSE, self::FIELD_WRITE, self::FIELD_DRAFT, self::FIELD_SUMMARIZE,
        ],
        self::FIELD_CODE => [
            self::FIELD_CODIGO, self::FIELD_CODIFIQUE, self::FIELD_IMPLEMENTE, self::FIELD_REFATORE, 'debug', self::FIELD_TESTE,
            self::FIELD_COMPILE, self::FIELD_EXECUTE, self::FIELD_RODE, self::FIELD_RODAR, self::FIELD_PHP, self::FIELD_TYPESCRIPT, self::FIELD_REACT,
            self::FIELD_COMPONENTE, self::FIELD_SERVICO, self::FIELD_CLASSE, self::FIELD_FUNCAO, self::FIELD_CONTROLLER, self::FIELD_CLI,
            self::FIELD_ARTISAN, self::FIELD_MIGRATION, self::FIELD_COMPOSER, self::FIELD_NPM, self::FIELD_PHPUNIT, self::FIELD_PEST,
            self::FIELD_PATCH, 'pull request', 'pr ', ' pr,', self::FIELD_MERGE, 'git ',
            'code', 'function', 'class', self::FIELD_SERVICE, self::FIELD_REFACTOR, self::FIELD_TEST, self::FIELD_BUILD,
        ],
        self::FIELD_VISION => [
            self::FIELD_IMAGEM, self::FIELD_FOTO, self::FIELD_SCREENSHOT, self::FIELD_VISUALIZE, self::FIELD_DESIGN, self::FIELD_LAYOUT,
            self::FIELD_MOCKUP, self::FIELD_FIGMA, self::FIELD_PNG, self::FIELD_JPG, self::FIELD_SVG, self::FIELD_TELA, 'ui ', 'ux ',
            'cor ', self::FIELD_PALETA, self::FIELD_VISUAL, self::FIELD_SCREENSHOT, self::FIELD_IMAGE, self::FIELD_RENDER,
        ],
        self::FIELD_AUDIT => [
            self::FIELD_AUDITE, self::FIELD_AUDITA, 'audit', self::FIELD_VERIFIQUE, self::FIELD_VALIDE, self::FIELD_CHEQUE,
            self::FIELD_INSPECIONE, self::FIELD_GOVERNANCE, self::FIELD_INVARIANT, self::FIELD_KERNEL, self::FIELD_CARTOGRAFIA,
            self::FIELD_DOC, self::FIELD_DOCUMENTO, self::FIELD_COMPLIANCE, self::FIELD_EVIDENCE, self::FIELD_EVIDENCIA,
            self::FIELD_INTEGRITY, self::FIELD_TAMPER, self::FIELD_SHA256, 'hash ', self::FIELD_VERIFY,
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
        $base = function_exists(self::FIELD_STORAGE_PATH)
            ? storage_path('atlas/cognition')
            : sys_get_temp_dir().'/atlas/cognition';

        return $base.DIRECTORY_SEPARATOR.self::FIELD_FUNCTION_DECOMPOSITIONS_JSONL;
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
            $weights = [self::FIELD_REASONING => self::FLOAT_0_5, self::FIELD_RETRIEVAL => self::FLOAT_0_2, self::FIELD_GENERATION => self::FLOAT_0_15, self::FIELD_CODE => self::FLOAT_0_05, self::FIELD_VISION => self::FLOAT_0_05, self::FIELD_AUDIT => self::FLOAT_0_05];

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

        $generatedAt = (new DateTimeImmutable(self::FIELD_NOW, new DateTimeZone(self::FIELD_UTC)))->format(DateTimeInterface::ATOM);
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
