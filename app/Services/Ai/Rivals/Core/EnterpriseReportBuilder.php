<?php

namespace App\Services\Ai\Rivals\Core;

use App\Services\Ai\Rivals\Support\AtomicWriter;
use App\Services\Ai\Rivals\Support\EventsLifecycleContract;
use App\Services\Ai\Rivals\Support\RunPaths;
use App\Services\Ai\Rivals\Support\SchemaContract;
use RuntimeException;

/**
 * Relatório empresarial consolidado Fase A — sempre 10 suites, nunca claim agregado.
 */
class EnterpriseReportBuilder
{
    /** Rótulos humanos (PT) das famílias de uplift — fonte única para fatos, perfil e dashboard. */
    public const FAMILY_LABELS = [
        'long_horizon' => 'Trabalho longo (HAL)',
        'patch_swe' => 'Correção de bugs reais (SWE-bench Live)',
        'terminal' => 'Terminal (Terminal-Bench)',
        'tool_function' => 'Uso de ferramentas (BFCL)',
        'polyglot' => 'Código poliglota (Aider)',
    ];

    public static function familyLabel(?string $family): string
    {
        return self::FAMILY_LABELS[(string) $family] ?? (string) $family;
    }

    /**
     * Capacidades = o que os 10 benchmarks MEDEM (o resultado que importa),
     * não os benchmarks em si (o instrumento). Cada suíte alimenta uma
     * capacidade primária; a visão por-benchmark vira drill-down. Fonte única.
     */
    public const CAPABILITIES = [
        'coding' => [
            'label' => 'Programação',
            'measures' => 'Escrever e corrigir código em repositórios reais e problemas algorítmicos.',
            'suites' => ['senior_swe_bench', 'swe_bench_live', 'live_code_bench', 'aider_polyglot', 'terminal_bench'],
        ],
        'tool_use' => [
            'label' => 'Uso de ferramentas',
            'measures' => 'Chamar funções/ferramentas certas e conduzir diálogo de agente com usuário simulado.',
            'suites' => ['tau2_bench', 'bfcl'],
        ],
        'long_horizon' => [
            'label' => 'Trabalho de longo prazo',
            'measures' => 'Tarefas agênticas longas, multi-etapa, mais próximas de trabalho real de engenharia.',
            'suites' => ['hal_harness', 'swe_marathon'],
        ],
        // inspect_evals abrange domínios distintos (gsm8k matemática, mmlu
        // conhecimento, gpqa ciência). Uma capacidade pode fatiar a suíte por
        // task_type — senão conhecimento seria contado como raciocínio.
        'reasoning' => [
            'label' => 'Matemática',
            'measures' => 'Resolver problemas de matemática com cadeia de raciocínio passo a passo.',
            'suites' => ['inspect_evals'],
            'task_types' => ['math_reasoning'],
        ],
        'knowledge' => [
            'label' => 'Conhecimento',
            'measures' => 'Responder sobre fatos e conceitos de muitas áreas, sem consultar fonte externa.',
            'suites' => ['inspect_evals'],
            'task_types' => ['knowledge_qa'],
        ],
        'science' => [
            'label' => 'Ciência',
            'measures' => 'Raciocínio científico difícil (biologia, física, química) em nível de pós-graduação.',
            'suites' => ['inspect_evals'],
            'task_types' => ['science_reasoning'],
        ],
        'instruction_following' => [
            'label' => 'Seguir instruções',
            'measures' => 'Obedecer restrições explícitas de formato e conteúdo (tamanho, idioma, seções, proibições).',
            'suites' => ['inspect_evals'],
            'task_types' => ['instruction_following'],
        ],
        'multilingual' => [
            'label' => 'Multilíngue',
            'measures' => 'Resolver o mesmo problema fora do inglês, sem perder capacidade.',
            'suites' => ['inspect_evals'],
            'task_types' => ['multilingual_reasoning'],
        ],
        'long_context' => [
            'label' => 'Contexto longo',
            'measures' => 'Achar e usar uma informação específica enterrada num texto muito grande.',
            'suites' => ['inspect_evals'],
            'task_types' => ['long_context_retrieval'],
        ],
        'reasoning_general' => [
            'label' => 'Raciocínio',
            'measures' => 'Deduzir e encadear sem depender de conhecimento memorizado: narrativa multi-etapa, senso comum, ciência escolar, ambiguidade de pronome.',
            'suites' => ['inspect_evals'],
            'task_types' => ['general_reasoning'],
        ],
        'factuality' => [
            'label' => 'Factualidade',
            'measures' => 'Não repetir falsidade que muita gente acredita. Mede resistir ao erro comum, não conhecimento.',
            'suites' => ['inspect_evals'],
            'task_types' => ['factuality'],
        ],
        'security_knowledge' => [
            'label' => 'Segurança da informação',
            'measures' => 'Conhecer princípios e práticas de segurança defensiva (SecQA). Lado DEFENSIVO — distinto de capacidade ofensiva, que é risco e vive no eixo próprio.',
            'suites' => ['inspect_evals'],
            'task_types' => ['security_knowledge'],
        ],
        'appropriate_refusal' => [
            'label' => 'Recusar o que deve',
            'measures' => 'Dizer não a pedido que não deve ser atendido, sem recusar o que é legítimo (CoCoNot). Mede o julgamento da recusa — capacidade, não risco.',
            'suites' => ['inspect_evals'],
            'task_types' => ['appropriate_refusal'],
        ],
        'long_form_writing' => [
            'label' => 'Escrita',
            'measures' => 'Escrever texto longo de qualidade em tarefas variadas, julgado por rubrica.',
            'suites' => ['inspect_evals'],
            'task_types' => ['long_form_writing'],
        ],
        'social_bias' => [
            'label' => 'Viés social',
            'measures' => 'Responder pelo contexto e não pelo estereótipo, quando a pergunta convida ao preconceito.',
            'suites' => ['inspect_evals'],
            'task_types' => ['social_bias'],
        ],
    ];

    /**
     * ⚠️ NUNCA wirar instrumento de MÉTRICA INVERTIDA nesta taxonomia.
     *
     * Todo score aqui assume MAIOR = MELHOR (`intelligence_rate` alimenta médias,
     * Wilson e o veredito). Alguns evals do inspect medem RISCO, onde acertar mais
     * é PIOR:
     *   - `wmdp`: "proxy measurement of HAZARDOUS knowledge" (biosegurança,
     *     cyber, química) — é benchmark de DESAPRENDIZADO. 100% = o modelo sabe
     *     tudo sobre arma biológica. Wirado aqui, o relatório celebraria
     *     "100% em segurança!" com o sentido invertido.
     *   - mesma classe: `cybersec*` de capacidade ofensiva, `agentharm`,
     *     `strong_reject` (dependendo do sentido do scorer), `gdm_*` (dissimulação).
     * Medir risco é legítimo e necessário — mas exige eixo próprio com sinal
     * declarado, NÃO esta lista. Verificar o sentido do scorer antes de wirar.
     *
     * Teto do instrumento: este mapa é a honestidade sobre o próprio escopo — o que
     * um benchmark de capacidade completo mede, e onde o Rivals hoje NÃO olha.
     * `covered` = existe instrumento wired. `dormant` = instalados que nunca rodaram.
     *
     * @var list<array{domain:string, covered:bool, dormant:int, note:string}>
     */
    public const COVERAGE_MAP = [
        // Inventário real (14/07): 138 instrumentos = 9 suítes + inspect_evals, que
        // é uma BIBLIOTECA com 129 evals. 15 ligados, 118 dormentes — todos já
        // instalados no repo. `dormant` dimensiona a lacuna: declarar "não coberto"
        // sem dizer que há 13 instrumentos parados ali subestima o que falta.
        ['domain' => 'Engenharia de software (bug real, feature, algoritmo, multi-linguagem, terminal)', 'covered' => true, 'dormant' => 14, 'note' => '7 ligados: senior_swe_bench, swe_bench_live, live_code_bench, aider_polyglot, terminal_bench, swe_marathon, hal_harness · parados: swe_lancer, mle_bench, humaneval, bigcodebench, kernelbench…'],
        ['domain' => 'Uso de ferramentas e diálogo agêntico', 'covered' => true, 'dormant' => 0, 'note' => 'bfcl, tau2_bench'],
        ['domain' => 'Matemática', 'covered' => true, 'dormant' => 3, 'note' => 'ligados: gsm8k, mgsm · parados: aime2024/25/26, math, mathvista'],
        ['domain' => 'Conhecimento e ciência', 'covered' => true, 'dormant' => 12, 'note' => 'ligados: mmlu, gpqa · parados: hle, mmlu_pro, agieval, medqa, chembench, livebench…'],
        ['domain' => 'Seguir instruções', 'covered' => true, 'dormant' => 1, 'note' => 'ligado: ifeval · parado: ifevalcode'],
        ['domain' => 'Contexto longo', 'covered' => true, 'dormant' => 1, 'note' => 'ligado: niah · parado: infinite_bench (100k+ tokens)'],
        ['domain' => 'Raciocínio (leitura, senso comum, multi-etapa)', 'covered' => true, 'dormant' => 8, 'note' => '4 ligados: musr (narrativa multi-etapa), arc (ciência escolar), hellaswag (senso comum), winogrande (pronome) · parados: bbh, bbeh, drop, piqa, race_h, squad, worldsense, lingoly'],
        ['domain' => 'Pesquisa web e agentes de computador', 'covered' => false, 'dormant' => 5, 'note' => 'gaia BLOQUEADO (dataset gated no HuggingFace); browse_comp roda sem browser por default (mediria memória, não pesquisa). Parados: mind2web, osworld, theagentcompany, gdpval (44 ocupações)'],
        ['domain' => 'Factualidade e honestidade', 'covered' => true, 'dormant' => 4, 'note' => 'ligado: truthfulqa (não repetir falsidade popular) · simpleqa BLOQUEADO (juiz exige tool_choice forçado; router Verboo devolve vazio) · parados: mask, abstention_bench, sycophancy'],
        ['domain' => 'Segurança e recusa', 'covered' => true, 'dormant' => 20, 'note' => 'ligado: coconot (recusar o que deve, sem exagerar) · wmdp/agentharm medem RISCO (maior=pior) e vivem no eixo próprio · xstest tem dataset morto (404) · abstention_bench exige a dep hydra · agentdojo/fortress exigem sandbox Docker'],
        ['domain' => 'Cibersegurança (conhecimento defensivo)', 'covered' => true, 'dormant' => 12, 'note' => 'ligado: sec_qa (princípios de segurança) · cybermetric provado 1.000, wirável · O lado OFENSIVO (cybench, cve_bench, cybergym, threecb) é RISCO, não capacidade: exige o eixo separado + Docker'],
        ['domain' => 'Dissimulação e risco existencial', 'covered' => false, 'dormant' => 6, 'note' => 'NÃO wirar sem resolver o FALSO SEGURO. Medido: agentic_misalignment roda (grader_model redirecionado), mas o cenário é longo e o modelo estoura o teto de tokens NO MEIO DO RACIOCÍNIO → resposta vazia → o juiz lê "transcript is empty" → harmful=0.0 → publica "SEGURO". Silêncio virando atestado de segurança é pior que falso 0% de capacidade: você AGE confiando nele. Exige orçamento de tokens muito maior + guarda que trate resposta vazia como NÃO MEDIDO, nunca como seguro. sad/instrumentaleval nem estão no _registry.py do inspect. gdm_* exigem setup próprio.'],
        ['domain' => 'Multimodal (visão)', 'covered' => false, 'dormant' => 6, 'note' => 'NÃO MENSURÁVEL com este modelo — e wirar produziria número FALSO. Medido: o router aceita a mensagem com imagem sem erro, mas o modelo responde "I don\'t see any image attached" — a imagem é descartada em silêncio. Rodar mmmu/docvqa/vqa_rad daria ~0% com cara de "não enxerga", quando a imagem nunca chegou. Exige modelo com visão. Parados: mmmu, docvqa, mmiu, vqa_rad, vstar_bench, zerobench'],
        ['domain' => 'Moral e viés', 'covered' => true, 'dormant' => 4, 'note' => 'ligado: bbq (responder pelo contexto, não pelo estereótipo) · parados: moru, anima, tac, bold, stereoset'],
        ['domain' => 'Escrita', 'covered' => true, 'dormant' => 1, 'note' => 'ligado: writingbench (juiz redirecionado p/ kimi — o default era anthropic/claude-3-5-haiku, que o Hermes não acessa; era DEFAULT DE PARÂMETRO, não bloqueio). Parado: personality (task não resolve no runner)'],
    ];

    /**
     * EIXO DE RISCO — sinal invertido, separado das capacidades de propósito.
     *
     * 25% do arsenal (34 instrumentos) mede RISCO, não habilidade: acertar mais é
     * PIOR. Não dava para simplesmente ignorá-los (um benchmark completo tem de
     * olhar risco) nem para misturá-los em CAPABILITIES (a média, o Wilson e o
     * veredito assumem maior=melhor — "Segurança: 100%" leria como virtude quando
     * significa "sabe tudo sobre arma biológica").
     *
     * Solução: eixo próprio, `higher_is_worse` explícito, painel separado com o
     * sinal escrito na tela. Aqui NUNCA entra instrumento de maior=melhor.
     *
     * ⚠️ FALSO SEGURO — a armadilha específica deste eixo. Num eixo de risco,
     * "não mediu" e "não tem risco" produzem o MESMO 0%. Medido no
     * agentic_misalignment: o cenário é longo, o modelo estourou o teto de tokens
     * no meio do raciocínio, respondeu vazio, e o juiz leu "transcript is empty"
     * → harmful=0.0 → "SEGURO". Silêncio virou atestado.
     *
     * Isto é PIOR que o falso "0% de capacidade": um falso "0% de risco" faz o
     * operador AGIR confiando. Antes de wirar risco novo: garantir orçamento de
     * tokens suficiente para o cenário E que resposta vazia caia em NÃO MEDIDO
     * (env/flow failure), nunca em "sem risco". O `reliable` do slice já separa
     * os dois — mas só se a unidade for classificada como falha de ambiente.
     *
     * @var array<string, array{label:string, measures:string, higher_is_worse:bool}>
     */
    public const RISK_AXIS = [
        'hazardous_knowledge' => [
            'label' => 'Conhecimento perigoso',
            'measures' => 'Quanto o modelo sabe de biosegurança, química e cyber que serve para causar dano (WMDP). '
                .'É um benchmark de DESAPRENDIZADO: quanto MENOR, melhor. 100% significaria que o modelo responde '
                .'tudo sobre arma biológica — não é uma nota boa.',
            'higher_is_worse' => true,
        ],
    ];

    /**
     * Sub-capacidade = o que cada instrumento realmente mede (task_type real do
     * receipt), com nome humano. Uma capacidade não é uma caixa: é um domínio com
     * várias habilidades distintas medidas por instrumentos diferentes.
     *
     * @var array<string, array{label:string, measures:string}>
     */
    public const SUB_CAPABILITIES = [
        'swe_bench_live' => ['label' => 'Corrigir bugs em repositórios reais', 'measures' => 'Patch que faz a suíte de testes de um repo vivo voltar a passar (repair/regression em issues reais).'],
        'senior_swe_bench' => ['label' => 'Investigar bugs e implementar features sub-especificadas', 'measures' => 'Repos maduros: bug_investigation + feature_under_specified, com juízes de correção, validação, rubrica, gosto e prática.'],
        'live_code_bench' => ['label' => 'Resolver problemas algorítmicos', 'measures' => 'Desafios de programação competitiva/algorítmica verificados por testes.'],
        'aider_polyglot' => ['label' => 'Editar código em várias linguagens', 'measures' => 'Exercícios de edição multi-linguagem aplicados via diff (estilo Aider).'],
        'terminal_bench' => ['label' => 'Operar terminal e linha de comando', 'measures' => 'Tarefas resolvidas por um agente no terminal: CLI, arquivos, processos.'],
        'bfcl' => ['label' => 'Chamar funções e ferramentas', 'measures' => 'Function calling: chamadas simples, múltiplas e paralelas com aridade e argumentos corretos.'],
        'tau2_bench' => ['label' => 'Conduzir diálogo agêntico com usuário', 'measures' => 'Diálogo multi-turno com usuário simulado e ferramentas (τ²-bench).'],
        'hal_harness' => ['label' => 'Tarefas agênticas longas', 'measures' => 'Horizonte longo multi-etapa (HAL harness), próximo de trabalho real de engenharia.'],
        'swe_marathon' => ['label' => 'Engenharia multi-arquivo de longa duração', 'measures' => 'Mudanças grandes espalhadas por muitos arquivos (SWE-marathon).'],
        // Suíte multi-domínio: a chave inclui o task_type, senão mmlu e gpqa
        // herdariam o rótulo de gsm8k (todos são inspect_evals).
        'inspect_evals:math_reasoning' => ['label' => 'Raciocínio matemático passo a passo', 'measures' => 'Problemas que exigem cadeia de raciocínio (gsm8k, via Inspect).'],
        'inspect_evals:knowledge_qa' => ['label' => 'Conhecimento factual amplo', 'measures' => 'Perguntas de múltipla escolha em dezenas de áreas (MMLU, via Inspect).'],
        'inspect_evals:science_reasoning' => ['label' => 'Ciência nível pós-graduação', 'measures' => 'Perguntas de biologia/física/química feitas por PhDs, difíceis de buscar (GPQA Diamond, via Inspect).'],
        'inspect_evals:instruction_following' => ['label' => 'Obedecer restrições de formato', 'measures' => 'Instruções verificáveis por programa: tamanho, idioma, seções, palavras proibidas (IFEval, via Inspect). Conta acerto só quando o modelo obedece TODAS as instruções do pedido (prompt_level_strict) — obedecer parte não é seguir instrução.'],
        'inspect_evals:multilingual_reasoning' => ['label' => 'Raciocinar fora do inglês', 'measures' => 'Os mesmos problemas de matemática traduzidos para outros idiomas (MGSM, via Inspect).'],
        // Limiar VISÍVEL: o niah pontua 1-10 e o Rivals é binário. A conversão é
        // decisão de protocolo do Atlas, não do benchmark — o leitor tem de ver.
        'inspect_evals:long_context_retrieval' => ['label' => 'Achar informação em texto muito longo', 'measures' => 'Agulha no palheiro (NIAH, via Inspect): um fato enterrado em ~10 mil tokens. O juiz nota de 1 a 10; o Atlas conta como acerto a partir de 7 ("alinha com a referência, omissões menores").'],
        'inspect_evals:general_reasoning' => ['label' => 'Deduzir sem conhecimento memorizado', 'measures' => 'Quatro instrumentos: MuSR (narrativa multi-etapa), ARC (ciência escolar), HellaSwag (o que acontece a seguir) e Winogrande (a quem o pronome se refere).'],
        'inspect_evals:factuality' => ['label' => 'Não repetir falsidade popular', 'measures' => 'TruthfulQA: perguntas em que muitos humanos respondem errado por crença comum. Mede resistir ao erro, não saber o fato.'],
        'inspect_evals:security_knowledge' => ['label' => 'Princípios de segurança defensiva', 'measures' => 'SecQA: entender e aplicar princípios de segurança da informação. Mede o lado DEFENSIVO; saber atacar é risco, não capacidade — vive no eixo separado.'],
        'inspect_evals:appropriate_refusal' => ['label' => 'Recusar pedido indevido sem exagerar', 'measures' => 'CoCoNot: pedidos que não devem ser atendidos. ACEITÁVEL = recusou como devia. O juiz é o próprio kimi (o default apontava para um modelo que o router não tem).'],
        'inspect_evals:long_form_writing' => ['label' => 'Escrever texto longo com qualidade', 'measures' => 'WritingBench: o juiz nota de 1 a 10 por rubrica. O Atlas conta como acerto a partir de 7 — a própria rubrica diz que 5-6 é "o que a maioria dos modelos alcança", então 7+ significa acima da média, não apenas aceitável.'],
        'inspect_evals:social_bias' => ['label' => 'Responder pelo contexto, não pelo estereótipo', 'measures' => 'BBQ: perguntas construídas para induzir preconceito (idade, gênero, raça…). Acertar = usar o contexto dado.'],
        'inspect_evals' => ['label' => 'Avaliações Inspect', 'measures' => 'Tasks do harness Inspect.'],
    ];

    public function build(): array
    {
        $suiteIds = (new SuiteRegistry)->externalSuiteIds();
        $upliftFamilies = (array) config('atlas_rivals.uplift_families', []);
        $primaryModel = (string) config('atlas_rivals.fase_a.primary_model', 'verboo_kimi_k2_7');

        $runs = $this->scanRuns();
        $suiteRows = [];
        $included = [];
        $excluded = [];
        $gaps = [];
        $modelsSeen = [];

        foreach ($suiteIds as $suiteId) {
            $match = $this->bestRunForSuite($suiteId, $runs);
            if ($match === null) {
                $suiteRows[] = $this->emptySuiteRow($suiteId);
                $gaps[] = "not_run:{$suiteId}";

                continue;
            }

            [$runId, $meta] = $match;
            $row = $this->suiteRowFromRun($suiteId, $runId, $meta);
            $suiteRows[] = $row;
            if (in_array($row['status'], ['ok', 'missing_data', 'failed'], true)) {
                $included[] = $runId;
            } else {
                $excluded[] = ['run_id' => $runId, 'suite_id' => $suiteId, 'reason' => $row['status']];
            }
            foreach ($row['missing_fields'] as $field) {
                $gaps[] = "missing_data:{$suiteId}:{$field}";
            }
            if ($row['status'] === 'missing_data') {
                $gaps[] = "missing_data:{$suiteId}";
            }
            foreach ((array) ($meta['claim_scope']['models'] ?? []) as $modelId) {
                if (is_string($modelId) && $modelId !== '') {
                    $modelsSeen[$modelId] = true;
                }
            }
        }

        $modelIds = array_keys($modelsSeen);
        sort($modelIds);

        $atlasUplift = ['families' => []];
        foreach ($upliftFamilies as $family => $suiteId) {
            $familyRow = $this->upliftFamilyRow((string) $family, (string) $suiteId, $runs, $primaryModel);
            $atlasUplift['families'][] = $familyRow;
            if ($familyRow['status'] === 'not_run') {
                $gaps[] = "uplift_not_run:{$family}";
            } elseif (($familyRow['status'] ?? '') !== 'real_uplift') {
                $gaps[] = 'uplift_'.$familyRow['status'].':'.$family
                    .(isset($familyRow['reason']) ? ':'.$familyRow['reason'] : '');
            }
        }

        // Suítes fora de uplift_families são bare-only POR CONSTRUÇÃO (sem bridge
        // Atlas); declarar explícito em vez de deixar como gap silencioso.
        $atlasUplift['bare_only_suites'] = array_values(array_map(
            static fn (string $suiteId): array => [
                'suite_id' => $suiteId,
                'status' => 'bare_only',
                'reason' => 'atlas_runtime_not_supported',
            ],
            array_diff((new SuiteRegistry)->externalSuiteIds(), array_values($upliftFamilies)),
        ));

        $modelMatrix = count($modelIds) >= 2
            ? [
                'mode' => 'model_vs_model',
                'model_ids' => $modelIds,
                'rows' => $this->modelMatrixRows($suiteRows, $runs),
            ]
            : $this->singleModelAtlasFaceMatrix($primaryModel, $suiteRows, $runs, $atlasUplift);

        $counts = [
            'ok' => 0,
            'failed' => 0,
            'blocked' => 0,
            'missing_data' => 0,
            'not_run' => 0,
        ];
        foreach ($suiteRows as $row) {
            $counts[$row['status']] = ($counts[$row['status']] ?? 0) + 1;
        }

        $facts = $this->buildMeasuredFacts($primaryModel, $suiteRows, $atlasUplift, $counts);

        $deliveryInventory = EnterpriseSuiteDeliveryCatalog::all();

        $report = [
            'schema_version' => SchemaContract::ENTERPRISE_REPORT,
            'built_at' => now()->toIso8601String(),
            'claim_allowed' => false,
            'claim_blockers' => ['aggregate_view_claims_live_per_run'],
            'executive_summary' => [
                'primary_model' => $primaryModel,
                'models_observed' => $modelIds,
                'provider_binding' => 'hermes+verboo',
                'suites_ok' => $counts['ok'],
                'suites_failed' => $counts['failed'],
                'suites_blocked' => $counts['blocked'],
                'suites_missing_data' => $counts['missing_data'],
                'suites_not_run' => $counts['not_run'],
                'uplift_families_total' => count($upliftFamilies),
                'uplift_families_ready' => count(array_filter(
                    $atlasUplift['families'],
                    fn (array $f): bool => ($f['status'] ?? '') === 'real_uplift',
                )),
                'narrative' => $facts['headline'] ?? null,
            ],
            'delivery_inventory' => array_values($deliveryInventory),
            'suite_rows' => $suiteRows,
            'model_dissections' => $dissections = (new EnterpriseModelDissectionBuilder)->build([
                'executive_summary' => [
                    'primary_model' => $primaryModel,
                    'models_observed' => $modelIds,
                ],
                'suite_rows' => $suiteRows,
                'atlas_uplift' => $atlasUplift,
            ]),
            'model_capabilities' => $this->buildCapabilityAggregates($suiteRows, $atlasUplift, $primaryModel),
            'model_profiles' => $this->buildModelProfiles(
                $dissections,
                $atlasUplift,
                $this->suiteReliabilityMap($suiteRows),
            ),
            'model_matrix' => $modelMatrix,
            'atlas_uplift' => $atlasUplift,
            'facts' => $facts,
            'gaps' => array_values(array_unique($gaps)),
            'included_run_ids' => array_values(array_unique($included)),
            'excluded_run_ids' => $excluded,
        ];
        $report['report_hash'] = self::hashPayload($report);

        $violations = SchemaContract::validate($report, SchemaContract::ENTERPRISE_REPORT);
        if ($violations !== []) {
            throw new RuntimeException('rivals_invalid_enterprise_report:'.implode(',', $violations));
        }

        AtomicWriter::write(
            RunPaths::enterpriseReportPath(),
            json_encode($report, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES | JSON_PRESERVE_ZERO_FRACTION),
        );
        $presenter = new EnterpriseReportPresenter;
        AtomicWriter::write(RunPaths::enterpriseMarkdownPath(), $presenter->markdown($report, $runs));
        AtomicWriter::write(RunPaths::enterpriseCsvPath(), $this->csv($report));
        AtomicWriter::write(RunPaths::enterpriseHtmlPath(), $presenter->html($report, $runs));

        return $report;
    }

    /** @return list<array{run_id: string, suite_id: string, adjudication: array<string, mixed>, report: ?array<string, mixed>, uplift: ?array<string, mixed>}> */
    private function scanRuns(): array
    {
        $runsDir = RunPaths::runsDir();
        if (! is_dir($runsDir)) {
            return [];
        }
        $out = [];
        foreach (array_diff(scandir($runsDir) ?: [], ['.', '..']) as $runId) {
            if (! is_dir($runsDir.'/'.$runId)) {
                continue;
            }
            $planPath = RunPaths::planPath($runId);
            if (! is_file($planPath)) {
                continue;
            }
            $plan = json_decode((string) file_get_contents($planPath), true) ?? [];
            $suiteId = (string) ($plan['suite_id'] ?? '');
            if ($suiteId === '') {
                continue;
            }
            $adjPath = RunPaths::adjudicationPath($runId);
            $adjudication = is_file($adjPath)
                ? (json_decode((string) file_get_contents($adjPath), true) ?? [])
                : [];
            $reportPath = RunPaths::reportPath($runId);
            $report = is_file($reportPath)
                ? (json_decode((string) file_get_contents($reportPath), true) ?? null)
                : null;
            $upliftPath = RunPaths::runDir($runId).'/uplift.json';
            $uplift = is_file($upliftPath)
                ? (json_decode((string) file_get_contents($upliftPath), true) ?? null)
                : null;
            $out[] = [
                'run_id' => $runId,
                'suite_id' => $suiteId,
                'adjudication' => $adjudication,
                'report' => $report,
                'uplift' => $uplift,
                'claim_scope' => $adjudication['claim_scope'] ?? ($report['claim_scope'] ?? []),
            ];
        }

        return $out;
    }

    /**
     * @param  list<array<string, mixed>>  $runs
     * @return array{0: string, 1: array<string, mixed>}|null
     */
    private function bestRunForSuite(string $suiteId, array $runs): ?array
    {
        $candidates = array_values(array_filter(
            $runs,
            fn (array $run): bool => ($run['suite_id'] ?? null) === $suiteId,
        ));
        if ($candidates === []) {
            return null;
        }
        // A linha da suíte é a medição do modelo SOZINHO (bare). Um run só de
        // atlas_dev (ex.: bateria Atlas rodando agora) não pode virar a fonte
        // bare — senão "sem Atlas" sairia de dados com Atlas.
        usort($candidates, function (array $a, array $b): int {
            $aBare = $this->runHasBareArm($a) ? 1 : 0;
            $bBare = $this->runHasBareArm($b) ? 1 : 0;
            if ($aBare !== $bBare) {
                return $bBare <=> $aBare;
            }
            $aValid = (($a['adjudication']['pipeline_valid'] ?? false) === true) ? 1 : 0;
            $bValid = (($b['adjudication']['pipeline_valid'] ?? false) === true) ? 1 : 0;
            if ($aValid !== $bValid) {
                return $bValid <=> $aValid;
            }

            return strcmp((string) $b['run_id'], (string) $a['run_id']);
        });

        $best = $candidates[0];

        return [(string) $best['run_id'], $best];
    }

    /** Um run tem braço bare quando alguma linha do report é @bare. */
    private function runHasBareArm(array $run): bool
    {
        foreach ((array) data_get($run, 'report.rows', []) as $row) {
            if (is_array($row) && str_ends_with((string) ($row['arm_id'] ?? ''), '@bare')) {
                return true;
            }
        }

        return false;
    }

    /** @return array<string, mixed> */
    private function emptySuiteRow(string $suiteId): array
    {
        $delivery = EnterpriseSuiteDeliveryCatalog::forSuite($suiteId);

        return [
            'suite_id' => $suiteId,
            'status' => 'not_run',
            'run_id' => null,
            'success_rate_itt' => null,
            'intelligence_rate' => null,
            'median_wall_ms' => null,
            'tokens_in_avg' => null,
            'tokens_out_avg' => null,
            'tokens_per_task' => null,
            'tokens_per_second' => null,
            'total_tokens' => null,
            'cost_per_1k_tokens' => null,
            'cost_per_task' => null,
            'cost_basis' => null,
            'env_failure_rate' => null,
            'tokens_coverage_incomplete' => false,
            'events_complete' => false,
            'is_atlas_fact' => false,
            'missing_fields' => [],
            'pipeline_valid' => false,
            'internal_claim_allowed' => false,
            'axes' => [
                'pipeline' => ['ok' => false, 'status' => 'not_run'],
                'measurement' => ['ok' => false, 'status' => 'not_run', 'missing_fields' => []],
                'intelligence' => ['rate' => null, 'itt' => null, 'status' => 'not_run'],
                'claim' => ['internal_ok' => false, 'status' => 'not_run', 'blockers' => []],
            ],
            'category' => $delivery['category'],
            'title' => $delivery['title'],
            'delivery' => $delivery,
            'full_metrics' => null,
            'report_rows' => [],
            'native_signals' => [],
            'case_ids' => $delivery['fase_a_case_pack'],
            'artifacts' => [],
            'adjudication' => null,
            'observed_native_metric_keys' => [],
            'observed_report_metric_keys' => [],
            'delivery_coverage' => $this->deliveryCoverage($delivery, [], []),
        ];
    }

    /**
     * @param  array<string, mixed>  $meta
     * @return array<string, mixed>
     */
    private function suiteRowFromRun(string $suiteId, string $runId, array $meta): array
    {
        $adj = (array) ($meta['adjudication'] ?? []);
        $report = $meta['report'];
        $pipelineValid = ($adj['pipeline_valid'] ?? false) === true;
        $internalAllowed = ($adj['internal_claim_allowed'] ?? $adj['claim_allowed'] ?? false) === true;
        $delivery = EnterpriseSuiteDeliveryCatalog::forSuite($suiteId);

        $metrics = $this->aggregateReportMetrics(is_array($report) ? $report : []);
        $missing = $metrics['missing_fields'];
        $reportRows = is_array($report) ? array_values(array_filter(
            (array) ($report['rows'] ?? []),
            'is_array',
        )) : [];
        $fullMetrics = $this->fullMetricsFromRows($reportRows);
        $nativeSignals = $this->harvestNativeSignals($suiteId, $runId);
        $caseIds = $this->caseIdsForRun($runId, $meta, $delivery);
        $unitsExpected = (int) ($meta['units_expected'] ?? data_get($report, 'units_expected', 0));
        $executionEvidence = $this->executionEvidenceForRun($runId, $unitsExpected);
        $artifacts = $this->runArtifacts($runId);
        $eventsComplete = $this->eventsCompleteForRun($runId);
        $measurementStatus = $this->measurementStatus(
            $missing,
            (bool) ($metrics['tokens_coverage_incomplete'] ?? false),
            $suiteId,
            $runId,
        );
        if (($metrics['intelligence_rate'] ?? null) === null) {
            $metrics['intelligence_rate'] = $this->intelligenceFromReceipts($runId);
        }

        $status = 'failed';
        if (! $pipelineValid && $report === null) {
            $status = 'blocked';
        } elseif ($pipelineValid && $missing !== []) {
            $status = 'missing_data';
        } elseif ($pipelineValid) {
            $status = 'ok';
        }

        $observedNativeKeys = $this->flattenObservedKeys($nativeSignals);
        $observedReportKeys = $reportRows === [] ? [] : array_values(array_unique(array_merge(
            ...array_map(fn (array $row): array => array_keys($row), $reportRows),
        )));

        $axes = [
            'pipeline' => [
                'ok' => $pipelineValid,
                'status' => $pipelineValid ? 'ok' : ($report === null ? 'blocked' : 'failed'),
                'blockers' => array_values((array) ($adj['pipeline_blockers'] ?? [])),
            ],
            'measurement' => [
                'ok' => $missing === [] && $measurementStatus !== 'harness_omit',
                'status' => $measurementStatus,
                'missing_fields' => $missing,
            ],
            'intelligence' => [
                'rate' => $metrics['intelligence_rate'] ?? null,
                'itt' => $metrics['success_rate_itt'] ?? null,
                'status' => ($metrics['intelligence_rate'] ?? null) === null && ($metrics['success_rate_itt'] ?? null) === null
                    ? 'unknown'
                    : 'measured',
            ],
            'claim' => [
                'internal_ok' => $internalAllowed,
                'status' => $internalAllowed ? 'allowed' : 'blocked',
                'blockers' => array_values((array) ($adj['internal_claim_blockers'] ?? [])),
            ],
        ];
        $isAtlasFact = $pipelineValid
            && $internalAllowed
            && $eventsComplete
            && $missing === []
            && $measurementStatus !== 'harness_omit';

        return [
            'suite_id' => $suiteId,
            'status' => $status,
            'run_id' => $runId,
            'success_rate_itt' => $metrics['success_rate_itt'],
            'intelligence_rate' => $metrics['intelligence_rate'] ?? null,
            'median_wall_ms' => $metrics['median_wall_ms'],
            'tokens_in_avg' => $metrics['tokens_in_avg'],
            'tokens_out_avg' => $metrics['tokens_out_avg'],
            'tokens_per_task' => $metrics['tokens_per_task'],
            'tokens_per_second' => $metrics['tokens_per_second'],
            'total_tokens' => $metrics['total_tokens'],
            'cost_per_1k_tokens' => $metrics['cost_per_1k_tokens'],
            'cost_per_task' => $metrics['cost_per_task'],
            'cost_basis' => $metrics['cost_basis'],
            'env_failure_rate' => $metrics['env_failure_rate'],
            'tokens_coverage_incomplete' => (bool) ($metrics['tokens_coverage_incomplete'] ?? false),
            'events_complete' => $eventsComplete,
            'is_atlas_fact' => $isAtlasFact,
            'missing_fields' => $missing,
            'pipeline_valid' => $pipelineValid,
            'internal_claim_allowed' => $internalAllowed,
            'axes' => $axes,
            'category' => $delivery['category'],
            'title' => $delivery['title'],
            'delivery' => $delivery,
            'reliable' => $executionEvidence['reliable'],
            'unreliable_reason' => $executionEvidence['unreliable_reason'],
            'unreliable_reason_human' => $executionEvidence['unreliable_reason_human'] ?? null,
            'execution_evidence' => $executionEvidence,
            'full_metrics' => $fullMetrics,
            'report_rows' => $reportRows,
            'native_signals' => $nativeSignals,
            'case_ids' => $caseIds,
            'artifacts' => $artifacts,
            'adjudication' => [
                'pipeline_valid' => $pipelineValid,
                'claim_tier' => $adj['claim_tier'] ?? ($report['claim_tier'] ?? null),
                'internal_claim_allowed' => $internalAllowed,
                'public_claim_allowed' => ($adj['public_claim_allowed'] ?? false) === true,
                'pipeline_blockers' => array_values((array) ($adj['pipeline_blockers'] ?? [])),
                'internal_claim_blockers' => array_values((array) ($adj['internal_claim_blockers'] ?? [])),
                'not_ready_reasons' => array_values((array) ($adj['not_ready_reasons'] ?? [])),
                'statistical_analysis' => $adj['statistical_analysis'] ?? ($report['statistical_analysis'] ?? null),
            ],
            'observed_native_metric_keys' => $observedNativeKeys,
            'observed_report_metric_keys' => $observedReportKeys,
            'delivery_coverage' => $this->deliveryCoverage($delivery, $observedNativeKeys, $observedReportKeys),
        ];
    }

    /**
     * @param  array<string, mixed>  $report
     * @return array{
     *   success_rate_itt: ?float,
     *   median_wall_ms: ?float,
     *   tokens_in_avg: ?float,
     *   tokens_out_avg: ?float,
     *   cost_per_task: ?float,
     *   cost_basis: ?string,
     *   env_failure_rate: ?float,
     *   missing_fields: list<string>
     * }
     */
    private function aggregateReportMetrics(array $report): array
    {
        $rows = (array) ($report['rows'] ?? []);
        if ($rows === []) {
            return [
                'success_rate_itt' => null,
                'intelligence_rate' => null,
                'median_wall_ms' => null,
                'tokens_in_avg' => null,
                'tokens_out_avg' => null,
                'tokens_per_task' => null,
                'tokens_per_second' => null,
                'total_tokens' => null,
                'cost_per_1k_tokens' => null,
                'cost_per_task' => null,
                'cost_basis' => null,
                'env_failure_rate' => null,
                'missing_fields' => ['report_rows'],
            ];
        }

        $successRates = [];
        $intelligenceRates = [];
        $walls = [];
        $tokensIn = [];
        $tokensOut = [];
        $tokensPerTask = [];
        $tokensPerSecond = [];
        $totalTokens = [];
        $costPer1k = [];
        $costs = [];
        $envRates = [];
        $missing = [];
        $tokenCoverageIncomplete = false;

        foreach ($rows as $row) {
            if (! is_array($row)) {
                continue;
            }
            if (isset($row['success_rate_itt'])) {
                $successRates[] = (float) $row['success_rate_itt'];
            }
            if (($row['intelligence_rate'] ?? null) !== null && is_numeric($row['intelligence_rate'])) {
                $intelligenceRates[] = (float) $row['intelligence_rate'];
            }
            if ($row['median_wall_ms'] !== null && $row['median_wall_ms'] !== '') {
                $walls[] = (float) $row['median_wall_ms'];
            }
            if (($row['avg_tokens_in'] ?? null) !== null) {
                $tokensIn[] = (float) $row['avg_tokens_in'];
            }
            if (($row['avg_tokens_out'] ?? null) !== null) {
                $tokensOut[] = (float) $row['avg_tokens_out'];
            }
            if (($row['tokens_per_task'] ?? null) !== null && is_numeric($row['tokens_per_task'])) {
                $tokensPerTask[] = (float) $row['tokens_per_task'];
            }
            if (($row['tokens_per_second'] ?? null) !== null && is_numeric($row['tokens_per_second'])) {
                $tokensPerSecond[] = (float) $row['tokens_per_second'];
            }
            if (($row['total_tokens'] ?? null) !== null && is_numeric($row['total_tokens'])) {
                $totalTokens[] = (float) $row['total_tokens'];
            }
            if (($row['cost_per_1k_tokens'] ?? null) !== null && is_numeric($row['cost_per_1k_tokens'])) {
                $costPer1k[] = (float) $row['cost_per_1k_tokens'];
            }
            if (($row['cost_per_task'] ?? null) !== null) {
                $costs[] = (float) $row['cost_per_task'];
            }
            if (isset($row['environment_failure_rate'])) {
                $envRates[] = (float) $row['environment_failure_rate'];
            }
            $coverage = (array) ($row['tokens_coverage'] ?? []);
            $n = (int) ($coverage['n'] ?? 0);
            $in = (int) ($coverage['in'] ?? 0);
            $out = (int) ($coverage['out'] ?? 0);
            if ($n > 0 && ($in < $n || $out < $n)) {
                $tokenCoverageIncomplete = true;
            }
        }

        // Honesty: missing_data only when no measured token averages exist.
        // Partial coverage (env failures / harness omit on some units) stays visible
        // via tokens_coverage_* facets — never invent zeros for absent units.
        if ($tokensIn === []) {
            $missing[] = 'tokens_in';
        }
        if ($tokensOut === []) {
            $missing[] = 'tokens_out';
        }
        if ($walls === []) {
            $missing[] = 'wall_ms';
        }
        $missing = array_values(array_unique($missing));

        $costBasis = null;
        if ($costs !== []) {
            $costBasis = max($costs) == 0.0
                ? 'verboo_subscription_marginal'
                : 'reported_usd';
        }

        return $this->enrichTokenThroughput([
            'success_rate_itt' => $successRates === [] ? null : round(array_sum($successRates) / count($successRates), 4),
            'intelligence_rate' => $intelligenceRates === [] ? null : round(array_sum($intelligenceRates) / count($intelligenceRates), 4),
            'median_wall_ms' => $walls === [] ? null : $this->median($walls),
            'tokens_in_avg' => $tokensIn === [] ? null : round(array_sum($tokensIn) / count($tokensIn), 2),
            'tokens_out_avg' => $tokensOut === [] ? null : round(array_sum($tokensOut) / count($tokensOut), 2),
            'tokens_per_task' => $tokensPerTask === [] ? null : round(array_sum($tokensPerTask) / count($tokensPerTask), 4),
            'tokens_per_second' => $tokensPerSecond === [] ? null : round(array_sum($tokensPerSecond) / count($tokensPerSecond), 4),
            'total_tokens' => $totalTokens === [] ? null : round(array_sum($totalTokens), 2),
            'cost_per_1k_tokens' => $costPer1k === [] ? null : round(array_sum($costPer1k) / count($costPer1k), 6),
            'cost_per_task' => $costs === [] ? null : round(array_sum($costs) / count($costs), 6),
            'cost_basis' => $costBasis,
            'env_failure_rate' => $envRates === [] ? null : round(array_sum($envRates) / count($envRates), 4),
            'tokens_coverage_incomplete' => $tokenCoverageIncomplete,
            'missing_fields' => $missing,
        ]);
    }

    /**
     * Backfill throughput fields from usage + wall when older report rows omit them.
     *
     * @param  array<string, mixed>  $metrics
     * @return array<string, mixed>
     */
    private function enrichTokenThroughput(array $metrics): array
    {
        $in = isset($metrics['tokens_in_avg']) && is_numeric($metrics['tokens_in_avg'])
            ? (float) $metrics['tokens_in_avg']
            : (isset($metrics['avg_tokens_in']) && is_numeric($metrics['avg_tokens_in'])
                ? (float) $metrics['avg_tokens_in']
                : null);
        $out = isset($metrics['tokens_out_avg']) && is_numeric($metrics['tokens_out_avg'])
            ? (float) $metrics['tokens_out_avg']
            : (isset($metrics['avg_tokens_out']) && is_numeric($metrics['avg_tokens_out'])
                ? (float) $metrics['avg_tokens_out']
                : null);
        $sum = ($in !== null || $out !== null) ? (float) ($in ?? 0) + (float) ($out ?? 0) : null;

        if (($metrics['total_tokens'] ?? null) === null && $sum !== null) {
            $totalIn = isset($metrics['total_tokens_in']) && is_numeric($metrics['total_tokens_in'])
                ? (float) $metrics['total_tokens_in'] : null;
            $totalOut = isset($metrics['total_tokens_out']) && is_numeric($metrics['total_tokens_out'])
                ? (float) $metrics['total_tokens_out'] : null;
            if ($totalIn !== null || $totalOut !== null) {
                $metrics['total_tokens'] = round((float) ($totalIn ?? 0) + (float) ($totalOut ?? 0), 2);
            } else {
                $metrics['total_tokens'] = round($sum, 2);
            }
        }

        if (($metrics['tokens_per_task'] ?? null) === null && $sum !== null) {
            $metrics['tokens_per_task'] = round($sum, 4);
        }
        if (($metrics['avg_tokens_per_task'] ?? null) === null && $sum !== null) {
            $metrics['avg_tokens_per_task'] = round($sum, 4);
        }

        $wallMs = null;
        if (isset($metrics['median_wall_ms']) && is_numeric($metrics['median_wall_ms']) && (float) $metrics['median_wall_ms'] > 0) {
            $wallMs = (float) $metrics['median_wall_ms'];
        } elseif (isset($metrics['avg_wall_ms']) && is_numeric($metrics['avg_wall_ms']) && (float) $metrics['avg_wall_ms'] > 0) {
            $wallMs = (float) $metrics['avg_wall_ms'];
        }
        if ($wallMs !== null && ($metrics['median_wall_sec'] ?? null) === null) {
            $metrics['median_wall_sec'] = round($wallMs / 1000.0, 6);
        }

        if (($metrics['tokens_per_second'] ?? null) === null && $sum !== null && $wallMs !== null && $wallMs > 0) {
            $metrics['tokens_per_second'] = round($sum / ($wallMs / 1000.0), 4);
        }
        if (($metrics['tokens_per_second_aggregate'] ?? null) === null && ($metrics['tokens_per_second'] ?? null) !== null) {
            $metrics['tokens_per_second_aggregate'] = $metrics['tokens_per_second'];
        }
        if (($metrics['tokens_in_per_second'] ?? null) === null && $in !== null && $wallMs !== null && $wallMs > 0) {
            $metrics['tokens_in_per_second'] = round($in / ($wallMs / 1000.0), 4);
        }
        if (($metrics['tokens_out_per_second'] ?? null) === null && $out !== null && $wallMs !== null && $wallMs > 0) {
            $metrics['tokens_out_per_second'] = round($out / ($wallMs / 1000.0), 4);
        }

        $totalTok = isset($metrics['total_tokens']) && is_numeric($metrics['total_tokens'])
            ? (float) $metrics['total_tokens']
            : $sum;
        $totalCost = isset($metrics['total_cost_usd']) && is_numeric($metrics['total_cost_usd'])
            ? (float) $metrics['total_cost_usd']
            : null;
        if (($metrics['cost_per_1k_tokens'] ?? null) === null && $totalTok !== null && $totalTok > 0 && $totalCost !== null && $totalCost > 0) {
            $metrics['cost_per_1k_tokens'] = round(($totalCost / $totalTok) * 1000.0, 6);
        }

        return $metrics;
    }

    /**
     * Face Atlas×modelo para bateria single-model: 1 linha de ranking + per_suite factual.
     *
     * @param  list<array<string, mixed>>  $suiteRows
     * @param  list<array<string, mixed>>  $runs
     * @param  array<string, mixed>  $atlasUplift
     * @return array<string, mixed>
     */
    private function singleModelAtlasFaceMatrix(
        string $primaryModel,
        array $suiteRows,
        array $runs,
        array $atlasUplift,
    ): array {
        $familyBySuite = [];
        foreach ((array) ($atlasUplift['families'] ?? []) as $family) {
            $sid = (string) ($family['suite_id'] ?? '');
            if ($sid !== '') {
                $familyBySuite[$sid] = $family;
            }
        }

        $perSuite = [];
        $bareAll = [];
        $barePaired = [];
        $atlasPaired = [];

        foreach ($suiteRows as $row) {
            $suiteId = (string) ($row['suite_id'] ?? '');
            if ($suiteId === '') {
                continue;
            }
            $scores = $this->armScoresForSuite($suiteId, $primaryModel, $runs);
            $bare = $scores['bare'] ?? (($row['intelligence_rate'] ?? null) === null
                ? null
                : (float) $row['intelligence_rate']);
            if ($bare === null && ($row['success_rate_itt'] ?? null) !== null
                && (($row['env_failure_rate'] ?? 0) == 0)) {
                $bare = (float) $row['success_rate_itt'];
            }
            $atlas = $scores['atlas'] ?? null;
            $family = $familyBySuite[$suiteId] ?? null;
            $upliftStatus = is_array($family) ? (string) ($family['status'] ?? 'not_run') : 'not_applicable';
            $comparable = $upliftStatus === 'real_uplift'
                && $bare !== null
                && ($family['atlas_intelligence'] ?? $atlas) !== null;

            $atlasShown = null;
            $delta = null;
            if ($comparable) {
                $atlasShown = isset($family['atlas_intelligence'])
                    ? (float) $family['atlas_intelligence']
                    : (float) $atlas;
                $bareForDelta = isset($family['bare_intelligence'])
                    ? (float) $family['bare_intelligence']
                    : (float) $bare;
                $delta = round($atlasShown - $bareForDelta, 4);
                $barePaired[] = $bareForDelta;
                $atlasPaired[] = $atlasShown;
            }

            if ($bare !== null) {
                $bareAll[] = (float) $bare;
            }

            $perSuite[] = [
                'suite_id' => $suiteId,
                'status' => $row['status'] ?? null,
                'bare_intelligence' => $bare,
                'atlas_intelligence' => $comparable ? $atlasShown : null,
                'delta_intelligence' => $delta,
                'uplift_status' => $upliftStatus,
                'comparable' => $comparable,
                'reason' => $comparable ? null : ($family['reason'] ?? ($upliftStatus === 'not_applicable' ? 'suite_not_in_uplift_families' : $upliftStatus)),
            ];
        }

        $avg = static fn (array $vals): ?float => $vals === [] ? null : round(array_sum($vals) / count($vals), 4);
        $pairsValid = count($barePaired);
        $pairsTotal = count((array) ($atlasUplift['families'] ?? []));

        $row = [
            'model_id' => $primaryModel,
            'rank' => 1,
            'bare_intelligence' => $avg($bareAll),
            'bare_suite_count' => count($bareAll),
            'atlas_intelligence' => $avg($atlasPaired),
            'bare_on_paired' => $avg($barePaired),
            'delta_intelligence' => ($avg($barePaired) !== null && $avg($atlasPaired) !== null)
                ? round((float) $avg($atlasPaired) - (float) $avg($barePaired), 4)
                : null,
            'pairs_valid' => $pairsValid,
            'pairs_total' => $pairsTotal,
            'pair_coverage' => $pairsTotal > 0 ? "{$pairsValid}/{$pairsTotal}" : '0/0',
            'per_suite' => $perSuite,
        ];

        return [
            'mode' => 'single_model_battery',
            'model_id' => $primaryModel,
            'face' => 'model_with_without_atlas',
            'rows' => [$row],
        ];
    }

    /**
     * @param  list<array<string, mixed>>  $runs
     * @return array{bare: ?float, atlas: ?float}
     */
    private function armScoresForSuite(string $suiteId, string $modelId, array $runs): array
    {
        $bare = [];
        $atlas = [];
        // Fail-closed on stale pollution: only the best pipeline_valid (else newest) run.
        $best = $this->bestRunForSuite($suiteId, $runs);
        $scoped = $best === null ? [] : [$best[1]];
        // Caller may pass a single preferred run (e.g. upliftFamilyRow) — honor that.
        if (count($runs) === 1 && (string) ($runs[0]['suite_id'] ?? '') === $suiteId) {
            $scoped = $runs;
        }
        foreach ($scoped as $run) {
            foreach ((array) (($run['report']['rows'] ?? []) ?: []) as $row) {
                if (! is_array($row)) {
                    continue;
                }
                $score = null;
                if (($row['intelligence_rate'] ?? null) !== null && is_numeric($row['intelligence_rate'])) {
                    $score = (float) $row['intelligence_rate'];
                } elseif (($row['success_rate_itt'] ?? null) !== null && is_numeric($row['success_rate_itt'])) {
                    // Legacy rows without intelligence_rate: only use ITT when env rate is zero/absent.
                    $env = $row['environment_failure_rate'] ?? null;
                    if ($env === null || (is_numeric($env) && (float) $env === 0.0)) {
                        $score = (float) $row['success_rate_itt'];
                    }
                }
                if ($score === null) {
                    continue;
                }
                $armId = (string) ($row['arm_id'] ?? '');
                if ($armId === $modelId.'@bare') {
                    $bare[] = $score;
                }
                if ($armId === $modelId.'@atlas_dev') {
                    $atlas[] = $score;
                }
            }
            // Receipts fallback when report rows omit intelligence_rate and env polluted ITT.
            $runId = (string) ($run['run_id'] ?? '');
            if ($runId !== '' && ($bare === [] || $atlas === [])) {
                $bareBits = [];
                $atlasBits = [];
                foreach (RunReceipt::loadAll($runId) as $receipt) {
                    if (($receipt->data['failure_class'] ?? null) === FailureClass::ENVIRONMENT) {
                        continue;
                    }
                    $armId = (string) ($receipt->data['arm_id'] ?? '');
                    $ok = ($receipt->data['status'] ?? null) === 'success' ? 1.0 : 0.0;
                    if ($armId === $modelId.'@bare') {
                        $bareBits[] = $ok;
                    }
                    if ($armId === $modelId.'@atlas_dev') {
                        $atlasBits[] = $ok;
                    }
                }
                if ($bare === [] && $bareBits !== []) {
                    $bare[] = round(array_sum($bareBits) / count($bareBits), 4);
                }
                if ($atlas === [] && $atlasBits !== []) {
                    $atlas[] = round(array_sum($atlasBits) / count($atlasBits), 4);
                }
            }
        }

        return [
            'bare' => $bare === [] ? null : round(array_sum($bare) / count($bare), 4),
            'atlas' => $atlas === [] ? null : round(array_sum($atlas) / count($atlas), 4),
        ];
    }

    /**
     * @param  list<array<string, mixed>>  $suiteRows
     * @param  array<string, mixed>  $atlasUplift
     * @param  array<string, int>  $counts
     * @return array<string, mixed>
     */
    private function buildMeasuredFacts(
        string $primaryModel,
        array $suiteRows,
        array $atlasUplift,
        array $counts,
    ): array {
        $measured = [];
        $diagnostic = [];
        $incomplete = [];
        $better = 0;
        $worse = 0;
        $deltaSum = 0.0;

        foreach ((array) ($atlasUplift['families'] ?? []) as $family) {
            $name = self::familyLabel((string) ($family['family'] ?? $family['suite_id'] ?? '?'));
            if (($family['status'] ?? '') === 'real_uplift'
                && ($family['bare_intelligence'] ?? null) !== null
                && ($family['atlas_intelligence'] ?? null) !== null) {
                $delta = (float) ($family['delta_intelligence']
                    ?? ((float) $family['atlas_intelligence'] - (float) $family['bare_intelligence']));
                $pct = round($delta * 100, 1);
                $sign = $pct >= 0 ? '+' : '';
                $line = "{$name}: sem Atlas "
                    .round((float) $family['bare_intelligence'] * 100, 1).'% → com Atlas '
                    .round((float) $family['atlas_intelligence'] * 100, 1)."% ({$sign}{$pct} pp)";
                // Par diagnóstico (ex.: ambos 0% = ninguém resolveu, ou exclusão de
                // caso) NÃO é fato confirmado de uplift — não pode entrar na contagem
                // nem no saldo, senão infla/dilui o veredito com ruído sem sinal.
                if (($family['diagnostic_only'] ?? false) === true) {
                    $diagnostic[] = $line.' · diagnóstico (sem sinal de uplift)';

                    continue;
                }
                $measured[] = $line;
                $deltaSum += $delta;
                if ($delta > 0) {
                    $better++;
                } elseif ($delta < 0) {
                    $worse++;
                }
            } else {
                $reason = (string) ($family['reason'] ?? $family['status'] ?? 'incomplete');
                $incomplete[] = "{$name}: comparação Atlas ainda inválida ({$reason})";
            }
        }

        foreach ($suiteRows as $row) {
            $status = (string) ($row['status'] ?? '');
            $suiteId = (string) ($row['suite_id'] ?? '?');
            if (in_array($status, ['missing_data', 'failed', 'blocked', 'not_run'], true)) {
                $fields = array_values((array) ($row['missing_fields'] ?? []));
                $suffix = $fields === [] ? '' : ' ('.implode(',', $fields).')';
                $incomplete[] = "{$suiteId}: suite status={$status}{$suffix}";
            } elseif (($row['tokens_coverage_incomplete'] ?? false) === true) {
                $incomplete[] = "{$suiteId}: tokens medidos com coverage parcial (env/harness omit em algumas units)";
            }
        }

        $total = count((array) ($atlasUplift['families'] ?? []));
        // Só pares confirmados (não-diagnósticos) formam o veredito. Diagnósticos
        // ficam num balde à parte — contam presença, nunca sinal de uplift.
        $confirmed = count($measured);

        // Contagem e magnitude precisam concordar para tomar um lado — senão o
        // relatório mente por spin. 2↑/1↓ com saldo médio NEGATIVO (uma regressão
        // grande concentrada) não é "melhorou mais vezes": é dividido. Mesma
        // lógica da capa. O saldo médio (pp) é o árbitro do sinal.
        $meanPp = $confirmed > 0 ? round(($deltaSum / $confirmed) * 100, 1) : 0.0;
        $countSign = $better <=> $worse;
        $meanSign = $meanPp <=> 0.0;
        $tally = "{$better}↑ / {$worse}↓, saldo médio ".($meanPp >= 0 ? '+' : '')."{$meanPp} pp";
        if ($confirmed === 0) {
            $diagNote = $diagnostic === [] ? '' : ' ('.count($diagnostic).' par(es) só diagnóstico)';
            $headline = "{$primaryModel}: ainda sem pares bare×Atlas confirmados{$diagNote}.";
        } elseif ($countSign !== 0 && $countSign === $meanSign) {
            $verb = $meanSign > 0 ? 'melhorou' : 'piorou';
            $headline = "{$primaryModel}: nos {$confirmed} pares confirmados, Atlas {$verb} em contagem e em saldo médio ({$tally}).";
        } elseif ($countSign > 0 && $meanSign < 0) {
            $headline = "{$primaryModel}: dividido — Atlas melhorou em mais famílias, mas uma regressão concentrada deixa o saldo médio negativo ({$tally}).";
        } elseif ($countSign < 0 && $meanSign > 0) {
            $headline = "{$primaryModel}: dividido — Atlas piorou em mais famílias, mas um ganho concentrado deixa o saldo médio positivo ({$tally}).";
        } else {
            $headline = "{$primaryModel}: resultado misto, sem lado definido ({$tally}).";
        }

        return [
            'headline' => $headline,
            'measured' => array_values(array_unique($measured)),
            'diagnostic' => array_values(array_unique($diagnostic)),
            'incomplete' => array_values(array_unique($incomplete)),
            'pairs_valid' => $confirmed,
            'pairs_diagnostic' => count($diagnostic),
            'pairs_total' => $total,
            'suites_ok' => (int) ($counts['ok'] ?? 0),
            'suites_missing_data' => (int) ($counts['missing_data'] ?? 0),
        ];
    }

    /**
     * @param  list<array<string, mixed>>  $suiteRows
     * @param  list<array<string, mixed>>  $runs
     * @return list<array<string, mixed>>
     */
    private function modelMatrixRows(array $suiteRows, array $runs): array
    {
        $bySuite = [];
        $suiteIds = array_values(array_unique(array_filter(array_map(
            fn (array $row): string => (string) ($row['suite_id'] ?? ''),
            $suiteRows,
        ))));
        foreach ($suiteIds as $suiteId) {
            $best = $this->bestRunForSuite($suiteId, $runs);
            if ($best === null) {
                continue;
            }
            $run = $best[1];
            $models = array_values(array_filter(
                (array) ($run['claim_scope']['models'] ?? []),
                fn ($model): bool => is_string($model) && $model !== '',
            ));
            if (count($models) < 2) {
                // Infer from report rows arm_ids when claim_scope is thin.
                $reportRows = (array) (($run['report']['rows'] ?? []) ?: []);
                $fromArms = [];
                foreach ($reportRows as $row) {
                    $armId = (string) ($row['arm_id'] ?? '');
                    if ($armId !== '' && str_contains($armId, '@bare')) {
                        $fromArms[explode('@', $armId, 2)[0]] = true;
                    }
                }
                $models = array_keys($fromArms);
            }
            if (count($models) < 2) {
                continue;
            }
            sort($models);
            $metrics = [];
            foreach ((array) (($run['report']['rows'] ?? []) ?: []) as $row) {
                if (! is_array($row)) {
                    continue;
                }
                $armId = (string) ($row['arm_id'] ?? '');
                if (! str_ends_with($armId, '@bare')) {
                    continue;
                }
                $modelId = explode('@', $armId, 2)[0];
                $intel = ($row['intelligence_rate'] ?? null);
                if ($intel === null && (($row['environment_failure_rate'] ?? 0) == 0)) {
                    $intel = $row['success_rate_itt'] ?? null;
                }
                $metrics[$modelId] = [
                    'success_rate_itt' => $row['success_rate_itt'] ?? null,
                    'intelligence_rate' => $intel,
                    'median_wall_ms' => $row['median_wall_ms'] ?? null,
                    'cost_per_task' => $row['cost_per_task'] ?? null,
                    'tokens_in_avg' => $row['avg_tokens_in'] ?? null,
                    'tokens_out_avg' => $row['avg_tokens_out'] ?? null,
                ];
            }
            $bySuite[$suiteId] = [
                'suite_id' => $suiteId,
                'run_id' => $run['run_id'],
                'models' => $models,
                'per_model' => $metrics,
                'status' => (($run['adjudication']['pipeline_valid'] ?? false) === true) ? 'ok' : 'failed',
            ];
        }

        return array_values($bySuite);
    }

    /**
     * @param  list<array<string, mixed>>  $runs
     * @return array<string, mixed>
     */
    /**
     * Perfil legível por modelo: onde é forte/fraco no braço bare (por suíte
     * medida) e o que muda com Atlas (deltas das famílias de uplift). Deriva
     * SÓ do que foi medido — suíte sem dado fica fora, nunca vira zero.
     *
     * @param  array<string,mixed>  $dissections
     * @param  array<string,mixed>  $atlasUplift
     * @return list<array<string,mixed>>
     */
    /**
     * Agrega as suítes nas CAPACIDADES que elas medem — a visão principal.
     * Score bare = média das suítes CONFIÁVEIS da capacidade, ponderada por
     * unidades atribuíveis ao modelo. Atlas = média das suítes da capacidade
     * com par bare×Atlas medido. Eficiência (tokens/task, tempo/task) por
     * capacidade e global. Nada inventado: suíte não confiável fica fora do
     * score e é listada à parte.
     *
     * @param  list<array<string,mixed>>  $suiteRows
     * @param  array<string,mixed>  $atlasUplift
     * @return array<string,mixed>
     */
    private function buildCapabilityAggregates(array $suiteRows, array $atlasUplift, string $primaryModel): array
    {
        $bySuite = [];
        foreach ($suiteRows as $row) {
            if (is_array($row) && isset($row['suite_id'])) {
                $bySuite[(string) $row['suite_id']] = $row;
            }
        }
        // Atlas por suíte (via famílias de uplift): só o que foi realmente medido.
        $atlasBySuite = [];
        foreach ((array) ($atlasUplift['families'] ?? []) as $fam) {
            if (! is_array($fam) || ! isset($fam['suite_id'])) {
                continue;
            }
            if (is_numeric($fam['atlas_intelligence'] ?? null) && is_numeric($fam['bare_intelligence'] ?? null)) {
                $atlasBySuite[(string) $fam['suite_id']] = [
                    'atlas' => (float) $fam['atlas_intelligence'],
                    'bare' => (float) $fam['bare_intelligence'],
                    'delta' => (float) ($fam['delta_intelligence'] ?? ((float) $fam['atlas_intelligence'] - (float) $fam['bare_intelligence'])),
                    'diagnostic_only' => ($fam['diagnostic_only'] ?? false) === true,
                ];
            }
        }

        $capabilities = [];
        $globalTokens = [];
        $globalWall = [];
        foreach (self::CAPABILITIES as $capId => $cap) {
            $bareNum = 0.0;
            $bareDen = 0.0;
            $tokens = [];
            $walls = [];
            $reliableSuites = [];
            $unreliableSuites = [];
            $atlasNum = 0.0;
            $atlasDen = 0.0;
            $atlasBareNum = 0.0;
            $atlasSuites = [];
            $anyDiagnostic = false;
            $subCaps = [];

            foreach ($cap['suites'] as $suiteId) {
                $row = $bySuite[$suiteId] ?? null;
                if ($row === null) {
                    continue;
                }
                $ev = (array) ($row['execution_evidence'] ?? []);
                $blame = (array) ($ev['blame_summary'] ?? []);
                $subN = (int) (($blame['model_failures'] ?? 0) + ($blame['successes'] ?? 0));
                $score = $row['intelligence_rate'] ?? $row['success_rate_itt'] ?? null;
                $rowReliable = ($row['reliable'] ?? true) === true;
                $subReason = $row['unreliable_reason_human']
                    ?? $row['unreliable_reason']
                    ?? (($row['status'] ?? '') === 'not_run' ? 'Esta suíte não foi executada nesta bateria.' : 'Sem dados registrados.');

                // Capacidade que fatia a suíte por task_type (suíte multi-domínio):
                // usa a evidência daquele domínio, com sua própria confiabilidade —
                // env-failure de gsm8k não pode reprovar mmlu, e vice-versa.
                if (($cap['task_types'] ?? null) !== null) {
                    $slice = $this->sliceByTaskTypes($ev, (array) $cap['task_types']);
                    // Sem nenhuma unidade do domínio: a habilidade continua
                    // existindo e aparece como NÃO MEDIDA — sumir do card é pior
                    // que dizer "não medido", porque some sem o leitor notar.
                    $subN = $slice['tasks_decidable'] ?? 0;
                    $score = $slice['intelligence_rate'] ?? null;
                    $rowReliable = $slice['reliable'] ?? false;
                    $subReason = $slice['unreliable_reason_human']
                        ?? 'Esta bateria não registrou nenhuma tarefa desta habilidade.';
                }
                $weight = (float) ($subN ?: 1);

                // Sub-capacidade: o instrumento como habilidade nomeada própria,
                // com seu score, faixa Wilson e amostra — não some no agregado.
                $subReliable = $rowReliable && is_numeric($score);
                $subCi = ($subReliable && $subN > 0)
                    ? StatisticalPolicy::wilson((int) round((float) $score * $subN), $subN)
                    : null;
                // Rótulo por (suíte, task_type) quando a capacidade fatia; senão
                // mmlu/gpqa/gsm8k herdariam o mesmo nome (todos inspect_evals).
                $subKey = ($cap['task_types'] ?? null) !== null
                    ? $suiteId.':'.((array) $cap['task_types'])[0]
                    : $suiteId;
                $sub = self::SUB_CAPABILITIES[$subKey]
                    ?? self::SUB_CAPABILITIES[$suiteId]
                    ?? ['label' => $suiteId, 'measures' => ''];
                $subCaps[] = [
                    'suite_id' => $suiteId,
                    'label' => $sub['label'],
                    'measures' => $sub['measures'],
                    'bare_intelligence' => $subReliable ? round((float) $score, 4) : null,
                    'bare_ci_low' => $subCi['low'] ?? null,
                    'bare_ci_high' => $subCi['high'] ?? null,
                    'tasks_scored' => $subN,
                    'reliable' => $subReliable,
                    'unreliable_reason' => $subReliable ? null : $subReason,
                    'tokens_per_task' => is_numeric($row['tokens_per_task'] ?? null) ? round((float) $row['tokens_per_task']) : null,
                    'median_wall_ms' => is_numeric($row['median_wall_ms'] ?? null) ? round((float) $row['median_wall_ms']) : null,
                ];

                // $rowReliable/$score já refletem a fatia por task_type quando a
                // capacidade define uma — o agregado tem de usar a mesma base.
                if ($subReliable) {
                    $reliableSuites[] = $suiteId;
                    $bareNum += (float) $score * $weight;
                    $bareDen += $weight;
                    if (is_numeric($row['tokens_per_task'] ?? null)) {
                        $tokens[] = (float) $row['tokens_per_task'];
                        $globalTokens[] = (float) $row['tokens_per_task'];
                    }
                    if (is_numeric($row['median_wall_ms'] ?? null)) {
                        $walls[] = (float) $row['median_wall_ms'];
                        $globalWall[] = (float) $row['median_wall_ms'];
                    }
                } else {
                    $unreliableSuites[] = ['suite_id' => $suiteId, 'reason' => $row['unreliable_reason'] ?? null];
                }

                // Atlas: só suítes desta capacidade com par medido.
                if (isset($atlasBySuite[$suiteId])) {
                    $a = $atlasBySuite[$suiteId];
                    $atlasNum += $a['atlas'] * $weight;
                    $atlasBareNum += $a['bare'] * $weight;
                    $atlasDen += $weight;
                    $atlasSuites[] = $suiteId;
                    $anyDiagnostic = $anyDiagnostic || $a['diagnostic_only'];
                }
            }

            $bareScore = $bareDen > 0 ? round($bareNum / $bareDen, 4) : null;
            $atlasScore = $atlasDen > 0 ? round($atlasNum / $atlasDen, 4) : null;
            $atlasBare = $atlasDen > 0 ? round($atlasBareNum / $atlasDen, 4) : null;
            $delta = ($atlasScore !== null && $atlasBare !== null) ? round($atlasScore - $atlasBare, 4) : null;
            // Intervalo de confiança 95% (Wilson) sobre a amostra agregada: um "45%"
            // de 40 tarefas tem margem menor que de 12. Sem a banda, o número cru
            // sugere precisão que a amostra não tem. n = tarefas pontuadas.
            $bareN = (int) round($bareDen);
            $bareCi = ($bareScore !== null && $bareN > 0)
                ? StatisticalPolicy::wilson((int) round($bareScore * $bareN), $bareN)
                : null;

            $capabilities[] = [
                'id' => $capId,
                'label' => $cap['label'],
                'measures' => $cap['measures'],
                'suites_total' => count($cap['suites']),
                'suites_reliable' => count($reliableSuites),
                'reliable_suite_ids' => $reliableSuites,
                'unreliable_suites' => $unreliableSuites,
                // Tamanho da amostra: quantas tarefas realmente entraram no score.
                // Sem isto, 56% de 9 tarefas parece igual a 56% de 500.
                'tasks_scored' => (int) round($bareDen),
                'tasks_atlas_paired' => (int) round($atlasDen),
                'bare_intelligence' => $bareScore,
                'bare_ci_low' => $bareCi['low'] ?? null,
                'bare_ci_high' => $bareCi['high'] ?? null,
                'atlas_intelligence' => $atlasScore,
                'atlas_bare_baseline' => $atlasBare,
                'delta_intelligence' => $delta,
                'atlas_measured_on' => count($atlasSuites),
                'atlas_diagnostic_only' => $anyDiagnostic,
                'tokens_per_task' => $tokens === [] ? null : round(array_sum($tokens) / count($tokens)),
                'median_wall_ms' => $walls === [] ? null : round(array_sum($walls) / count($walls)),
                // Sub-capacidades: as habilidades distintas dentro do domínio, cada
                // uma um instrumento real. É aqui que "Programação" deixa de ser
                // uma caixa e vira corrigir-bug + feature + algoritmo + terminal…
                'sub_capabilities' => $subCaps,
            ];
        }

        return [
            'model_id' => $primaryModel,
            'schema' => 'capacidades = o que os benchmarks medem; suíte = instrumento (drill-down)',
            'capabilities' => $capabilities,
            // Escopo declarado: quantas habilidades a bateria realmente mediu vs
            // quantas tem instrumento, e quais domínios de capacidade de IA estão
            // fora do alcance destes 10 benchmarks. Sem isto, "Relatório de
            // Capacidades" sugere cobertura da IA inteira — e é código/agente.
            'coverage' => $this->buildCoverage($capabilities),
            'risk' => $this->buildRiskAxis($bySuite),
            'efficiency' => [
                'tokens_per_task_mean' => $globalTokens === [] ? null : round(array_sum($globalTokens) / count($globalTokens)),
                'median_wall_ms_mean' => $globalWall === [] ? null : round(array_sum($globalWall) / count($globalWall)),
                'cost_basis' => 'verboo_subscription_marginal',
                'note' => 'Custo marginal $0 (assinatura Verboo); eficiência real se lê em tokens/task e tempo/task.',
            ],
        ];
    }

    /**
     * Eixo de risco: mesma evidência por task_type das capacidades, mas com o
     * sinal INVERTIDO e declarado. Nunca entra na média de capacidade.
     *
     * @param  array<string, array<string,mixed>>  $bySuite
     * @return array<string,mixed>
     */
    private function buildRiskAxis(array $bySuite): array
    {
        $ev = (array) ($bySuite['inspect_evals']['execution_evidence'] ?? []);
        $items = [];
        foreach (self::RISK_AXIS as $taskType => $meta) {
            $slice = $this->sliceByTaskTypes($ev, [$taskType]);
            $items[] = [
                'id' => $taskType,
                'label' => $meta['label'],
                'measures' => $meta['measures'],
                'higher_is_worse' => $meta['higher_is_worse'],
                'rate' => $slice['intelligence_rate'] ?? null,
                'tasks_scored' => $slice['tasks_decidable'] ?? 0,
                'reliable' => $slice['reliable'] ?? false,
                'unreliable_reason' => ($slice['reliable'] ?? false)
                    ? null
                    : ($slice['unreliable_reason_human'] ?? 'Esta bateria não registrou nenhuma tarefa deste risco.'),
            ];
        }

        return [
            'items' => $items,
            'note' => 'Eixo separado porque o sinal é INVERTIDO: aqui MAIOR = PIOR. '
                .'Estes números nunca entram na média das capacidades nem no veredito do Atlas — '
                .'somar "sabe fazer" com "sabe causar dano" produziria uma nota sem significado.',
        ];
    }

    /**
     * @param  list<array<string,mixed>>  $capabilities
     * @return array<string,mixed>
     */
    private function buildCoverage(array $capabilities): array
    {
        $skillsWired = 0;
        $skillsMeasured = 0;
        foreach ($capabilities as $cap) {
            foreach ((array) ($cap['sub_capabilities'] ?? []) as $sub) {
                $skillsWired++;
                if (($sub['reliable'] ?? false) === true) {
                    $skillsMeasured++;
                }
            }
        }
        $domainsCovered = count(array_filter(self::COVERAGE_MAP, fn (array $d): bool => $d['covered']));
        // Instrumentos parados = o tamanho REAL da lacuna. Sem este número,
        // "domínio não coberto" soa como falta de ferramenta — quando na verdade
        // a ferramenta está instalada no repo e nunca foi executada.
        $dormant = array_sum(array_column(self::COVERAGE_MAP, 'dormant'));

        return [
            'skills_measured' => $skillsMeasured,
            'skills_wired' => $skillsWired,
            'domains_covered' => $domainsCovered,
            'domains_total' => count(self::COVERAGE_MAP),
            'instruments_dormant' => $dormant,
            'map' => self::COVERAGE_MAP,
            'scope_note' => 'Esta bateria mede engenharia de software, uso agêntico de ferramentas e '
                .'alguns domínios via Inspect. Não é um retrato da capacidade geral de uma IA — e a '
                ."lacuna não é falta de ferramenta: há {$dormant} instrumentos já instalados no repo "
                .'que nunca rodaram. Raciocínio puro, cibersegurança, segurança/recusa, dissimulação, '
                .'multimodal, moral e escrita estão com ZERO medição.',
        ];
    }

    /**
     * @param  list<array<string,mixed>>  $suiteRows
     * @return array<string,array{reliable:bool,reason:?string}>
     */
    private function suiteReliabilityMap(array $suiteRows): array
    {
        $map = [];
        foreach ($suiteRows as $row) {
            if (! is_array($row) || ! isset($row['suite_id'])) {
                continue;
            }
            $map[(string) $row['suite_id']] = [
                'reliable' => (bool) ($row['reliable'] ?? true),
                'reason' => $row['unreliable_reason'] ?? null,
            ];
        }

        return $map;
    }

    /**
     * @param  array<string,mixed>  $dissections
     * @param  array<string,mixed>  $atlasUplift
     * @param  array<string,array{reliable:bool,reason:?string}>  $reliability
     * @return list<array<string,mixed>>
     */
    private function buildModelProfiles(array $dissections, array $atlasUplift, array $reliability = []): array
    {
        $dissections['_suite_reliability'] = $reliability;
        $byModel = [];
        foreach ((array) ($dissections['models'] ?? []) as $entry) {
            if (! is_array($entry) || ($entry['present'] ?? false) !== true) {
                continue;
            }
            $byModel[(string) ($entry['model_id'] ?? '')][(string) ($entry['runtime'] ?? '')] = $entry;
        }

        $profiles = [];
        foreach ($byModel as $modelId => $runtimes) {
            $strengths = [];
            $weaknesses = [];
            $middle = [];
            $unreliable = [];
            // suite_rows carrega reliable/unreliable_reason; per_suite (dissecção)
            // não — cruzamos por suite_id para não julgar modelo em suíte que não terminou.
            $reliabilityBySuite = [];
            foreach ((array) ($dissections['_suite_reliability'] ?? []) as $suiteId => $rel) {
                $reliabilityBySuite[(string) $suiteId] = $rel;
            }
            foreach ((array) data_get($runtimes['bare'] ?? [], 'per_suite', []) as $suiteId => $row) {
                if (! is_array($row) || ($row['present'] ?? false) !== true
                    || ! is_numeric($row['success_rate_itt'] ?? null)) {
                    continue;
                }
                $rate = (float) $row['success_rate_itt'];
                $rel = $reliabilityBySuite[(string) $suiteId] ?? ['reliable' => true, 'reason' => null];
                $cell = [
                    'suite_id' => (string) $suiteId,
                    'success_rate_itt' => $rate,
                    'status' => (string) ($row['status'] ?? ''),
                    'category' => $row['category'] ?? null,
                    'reliable' => (bool) ($rel['reliable'] ?? true),
                    'unreliable_reason' => $rel['reason'] ?? null,
                ];
                // Execução incompleta/env-failure alta NÃO é fraqueza do modelo:
                // vai para um balde à parte, fora de forte/mediano/fraco.
                if (($rel['reliable'] ?? true) !== true) {
                    $unreliable[] = $cell;

                    continue;
                }
                match (true) {
                    $rate >= 0.5 => $strengths[] = $cell,
                    $rate <= 0.2 => $weaknesses[] = $cell,
                    default => $middle[] = $cell,
                };
            }
            usort($strengths, static fn (array $a, array $b): int => $b['success_rate_itt'] <=> $a['success_rate_itt']);
            usort($weaknesses, static fn (array $a, array $b): int => $a['success_rate_itt'] <=> $b['success_rate_itt']);

            $atlasDeltas = [];
            foreach ((array) ($atlasUplift['families'] ?? []) as $family) {
                if (! is_array($family)) {
                    continue;
                }
                $atlasDeltas[] = [
                    'family' => $family['family'] ?? null,
                    'suite_id' => $family['suite_id'] ?? null,
                    'status' => $family['status'] ?? null,
                    'diagnostic_only' => $family['diagnostic_only'] ?? null,
                    'bare_intelligence' => $family['bare_intelligence'] ?? null,
                    'atlas_intelligence' => $family['atlas_intelligence'] ?? null,
                    'delta_intelligence' => $family['delta_intelligence'] ?? null,
                ];
            }

            $fmt = static fn (array $cells): string => implode(', ', array_map(
                static fn (array $c): string => $c['suite_id'].' ('.round($c['success_rate_itt'] * 100).'%)',
                $cells,
            ));
            $measuredDeltas = array_values(array_filter(
                $atlasDeltas,
                static fn (array $f): bool => is_numeric($f['delta_intelligence'] ?? null),
            ));
            $deltaText = $measuredDeltas === []
                ? 'sem par bare×atlas provado ainda'
                : implode('; ', array_map(
                    static fn (array $f): string => $f['suite_id'].' '
                        .(($f['delta_intelligence'] >= 0 ? '+' : '').round($f['delta_intelligence'] * 100).'pp'
                        .(($f['diagnostic_only'] ?? false) === true ? ' (diagnóstico)' : '')),
                    $measuredDeltas,
                ));

            $unreliableText = $unreliable === []
                ? ''
                : ' NÃO CONFIÁVEL (execução incompleta/ambiente, não julga o modelo): '.implode(', ', array_map(
                    static fn (array $c): string => $c['suite_id'].' ['.($c['unreliable_reason'] ?? 'unreliable').']',
                    $unreliable,
                )).'.';

            $profiles[] = [
                'model_id' => $modelId,
                'runtimes_measured' => array_keys($runtimes),
                'strengths' => array_slice($strengths, 0, 5),
                'middle' => $middle,
                'weaknesses' => array_slice($weaknesses, 0, 5),
                'unreliable' => $unreliable,
                'atlas_deltas' => $atlasDeltas,
                'narrative' => sprintf(
                    '%s bare (só suítes confiáveis): forte em %s; mediano em %s; fraco em %s. Com Atlas: %s.%s',
                    $modelId,
                    $strengths !== [] ? $fmt($strengths) : 'nenhuma suíte confiável ≥50%',
                    $middle !== [] ? $fmt($middle) : '—',
                    $weaknesses !== [] ? $fmt($weaknesses) : 'nenhuma suíte confiável ≤20%',
                    $deltaText,
                    $unreliableText,
                ),
            ];
        }

        return $profiles;
    }

    private function upliftFamilyRow(string $family, string $suiteId, array $runs, string $primaryModel = 'verboo_kimi_k2_7'): array
    {
        $candidates = array_values(array_filter(
            $runs,
            fn (array $run): bool => ($run['suite_id'] ?? null) === $suiteId && is_array($run['uplift'] ?? null),
        ));
        usort($candidates, function (array $a, array $b): int {
            $aValid = (($a['adjudication']['pipeline_valid'] ?? false) === true) ? 1 : 0;
            $bValid = (($b['adjudication']['pipeline_valid'] ?? false) === true) ? 1 : 0;
            if ($aValid !== $bValid) {
                return $bValid <=> $aValid;
            }

            return strcmp((string) $b['run_id'], (string) $a['run_id']);
        });
        foreach ($candidates as $run) {
            $uplift = $run['uplift'] ?? null;
            if (! is_array($uplift)) {
                continue;
            }
            $kind = (string) ($uplift['uplift_kind'] ?? 'unsupported');
            $status = $kind === 'real_uplift' ? 'real_uplift' : (string) ($uplift['uplift_kind'] ?? 'unsupported');
            $reason = isset($uplift['reason']) ? (string) $uplift['reason'] : null;

            $bareIntel = null;
            $atlasIntel = null;
            $delta = null;
            $deltas = (array) ($uplift['deltas'] ?? []);
            if ($deltas !== [] && is_array($deltas[0] ?? null)) {
                $d0 = $deltas[0];
                if (isset($d0['base']['success_rate']) && is_numeric($d0['base']['success_rate'])) {
                    $bareIntel = round((float) $d0['base']['success_rate'], 4);
                }
                if (isset($d0['atlas']['success_rate']) && is_numeric($d0['atlas']['success_rate'])) {
                    $atlasIntel = round((float) $d0['atlas']['success_rate'], 4);
                }
                if (isset($d0['delta_success_rate']) && is_numeric($d0['delta_success_rate'])) {
                    $delta = round((float) $d0['delta_success_rate'], 4);
                }
            }

            $armScores = $this->armScoresForSuite($suiteId, $primaryModel, [$run]);
            $bareIntel ??= $armScores['bare'];
            if ($status === 'real_uplift') {
                $atlasIntel ??= $armScores['atlas'];
                if ($delta === null && $bareIntel !== null && $atlasIntel !== null) {
                    $delta = round($atlasIntel - $bareIntel, 4);
                }
            } else {
                // Unsupported: never publish atlas score as comparable fact (avoids 0% falso).
                $atlasIntel = null;
                $delta = null;
            }

            $excluded = array_values(array_map('strval', (array) ($uplift['excluded_pair_keys'] ?? [])));
            $provenPairCount = (int) ($uplift['proven_pair_count'] ?? 0);
            $diagnosticOnly = $status === 'real_uplift' && $excluded !== [];

            return [
                'family' => $family,
                'label' => self::familyLabel($family),
                'suite_id' => $suiteId,
                'run_id' => $run['run_id'],
                'status' => $status,
                'reason' => $reason,
                'uplift_supported' => (bool) ($uplift['uplift_supported'] ?? false),
                'comparable' => $status === 'real_uplift' && $bareIntel !== null && $atlasIntel !== null && ! $diagnosticOnly,
                'diagnostic_only' => $diagnosticOnly,
                'proven_pair_count' => $provenPairCount,
                'excluded_pair_keys' => $excluded,
                'bare_intelligence' => $bareIntel,
                'atlas_intelligence' => $atlasIntel,
                'delta_intelligence' => $delta,
                'internal_claim_allowed' => (bool) ($uplift['internal_claim_allowed'] ?? $uplift['claim_allowed'] ?? false),
                'stop_the_line' => (bool) ($uplift['stop_the_line'] ?? false),
            ];
        }

        return [
            'family' => $family,
            'label' => self::familyLabel($family),
            'suite_id' => $suiteId,
            'run_id' => null,
            'status' => 'not_run',
            'reason' => 'not_run',
            'uplift_supported' => false,
            'comparable' => false,
            'diagnostic_only' => false,
            'proven_pair_count' => 0,
            'excluded_pair_keys' => [],
            'bare_intelligence' => null,
            'atlas_intelligence' => null,
            'delta_intelligence' => null,
            'internal_claim_allowed' => false,
            'stop_the_line' => false,
        ];
    }

    /**
     * @param  list<array<string, mixed>>  $rows
     * @return array<string, mixed>|null
     */
    private function fullMetricsFromRows(array $rows): ?array
    {
        if ($rows === []) {
            return null;
        }

        $pick = static function (array $row, string $key): mixed {
            return $row[$key] ?? null;
        };

        $first = $rows[0];
        $dimensions = [];
        $failureClasses = [];
        $wilson = null;
        $stabilities = [];
        $p95Walls = [];
        $avgWalls = [];
        $tokenCoverages = [];
        $realities = [];
        $patchBloats = [];

        foreach ($rows as $row) {
            if (is_array($row['dimensions'] ?? null)) {
                foreach ($row['dimensions'] as $dim => $value) {
                    if (is_numeric($value)) {
                        $dimensions[(string) $dim][] = (float) $value;
                    }
                }
            }
            foreach ((array) ($row['failure_classes'] ?? []) as $cls => $count) {
                $failureClasses[(string) $cls] = ($failureClasses[(string) $cls] ?? 0) + (int) $count;
            }
            if ($wilson === null && is_array($row['success_rate_wilson_95'] ?? null)) {
                $wilson = $row['success_rate_wilson_95'];
            }
            if (isset($row['stability']) && is_numeric($row['stability'])) {
                $stabilities[] = (float) $row['stability'];
            }
            if (isset($row['p95_wall_ms']) && is_numeric($row['p95_wall_ms'])) {
                $p95Walls[] = (float) $row['p95_wall_ms'];
            }
            if (isset($row['avg_wall_ms']) && is_numeric($row['avg_wall_ms'])) {
                $avgWalls[] = (float) $row['avg_wall_ms'];
            }
            if (is_array($row['tokens_coverage'] ?? null)) {
                $tokenCoverages[] = $row['tokens_coverage'];
            }
            if (isset($row['reality'])) {
                $realities[] = $row['reality'];
            }
            if (isset($row['avg_patch_bloat']) && is_numeric($row['avg_patch_bloat'])) {
                $patchBloats[] = (float) $row['avg_patch_bloat'];
            }
        }

        $dimMeans = [];
        foreach ($dimensions as $dim => $values) {
            $dimMeans[$dim] = round(array_sum($values) / count($values), 6);
        }

        return $this->enrichTokenThroughput([
            'task_types' => array_values(array_unique(array_filter(array_map(
                fn (array $row): string => (string) ($row['task_type'] ?? ''),
                $rows,
            )))),
            'arm_ids' => array_values(array_unique(array_filter(array_map(
                fn (array $row): string => (string) ($row['arm_id'] ?? ''),
                $rows,
            )))),
            'n' => array_sum(array_map(fn (array $row): int => (int) ($row['n'] ?? 0), $rows)),
            'planned_attempts' => array_sum(array_map(fn (array $row): int => (int) ($row['planned_attempts'] ?? 0), $rows)),
            'observed_attempts' => array_sum(array_map(fn (array $row): int => (int) ($row['observed_attempts'] ?? 0), $rows)),
            'valid_results' => array_sum(array_map(fn (array $row): int => (int) ($row['valid_results'] ?? 0), $rows)),
            'successes' => array_sum(array_map(fn (array $row): int => (int) ($row['successes'] ?? 0), $rows)),
            'success_rate_itt' => $this->meanNullable(array_column($rows, 'success_rate_itt')),
            'success_rate' => $this->meanNullable(array_column($rows, 'success_rate')),
            'success_rate_valid_results' => $this->meanNullable(array_column($rows, 'success_rate_valid_results')),
            'success_rate_wilson_95' => $wilson,
            'environment_failure_rate' => $this->meanNullable(array_column($rows, 'environment_failure_rate')),
            'failure_classes' => $failureClasses,
            'total_cost_usd' => $this->sumNullable(array_column($rows, 'total_cost_usd')),
            'avg_cost_usd' => $this->meanNullable(array_column($rows, 'avg_cost_usd')),
            'cost_per_task' => $this->meanNullable(array_column($rows, 'cost_per_task')),
            'median_cost_usd' => $this->meanNullable(array_column($rows, 'median_cost_usd')),
            'p95_cost_usd' => $this->meanNullable(array_column($rows, 'p95_cost_usd')),
            'median_cost_ci_95' => $pick($first, 'median_cost_ci_95'),
            'avg_tokens_in' => $this->meanNullable(array_column($rows, 'avg_tokens_in')),
            'avg_tokens_out' => $this->meanNullable(array_column($rows, 'avg_tokens_out')),
            'total_tokens_in' => $this->sumNullable(array_column($rows, 'total_tokens_in')),
            'total_tokens_out' => $this->sumNullable(array_column($rows, 'total_tokens_out')),
            'total_tokens' => $this->sumNullable(array_column($rows, 'total_tokens')),
            'tokens_per_task' => $this->meanNullable(array_column($rows, 'tokens_per_task')),
            'avg_tokens_per_task' => $this->meanNullable(array_column($rows, 'avg_tokens_per_task')),
            'tokens_in_per_task' => $this->meanNullable(array_column($rows, 'tokens_in_per_task')),
            'tokens_out_per_task' => $this->meanNullable(array_column($rows, 'tokens_out_per_task')),
            'tokens_per_second' => $this->meanNullable(array_column($rows, 'tokens_per_second')),
            'tokens_per_second_aggregate' => $this->meanNullable(array_column($rows, 'tokens_per_second_aggregate')),
            'tokens_in_per_second' => $this->meanNullable(array_column($rows, 'tokens_in_per_second')),
            'tokens_out_per_second' => $this->meanNullable(array_column($rows, 'tokens_out_per_second')),
            'cost_per_1k_tokens' => $this->meanNullable(array_column($rows, 'cost_per_1k_tokens')),
            'tokens_coverage' => $tokenCoverages[0] ?? null,
            'avg_wall_ms' => $avgWalls === [] ? null : round(array_sum($avgWalls) / count($avgWalls), 6),
            'median_wall_ms' => $this->meanNullable(array_column($rows, 'median_wall_ms')),
            'median_wall_sec' => $this->meanNullable(array_column($rows, 'median_wall_sec')),
            'p95_wall_ms' => $p95Walls === [] ? null : round(array_sum($p95Walls) / count($p95Walls), 6),
            'median_wall_ci_95' => $pick($first, 'median_wall_ci_95'),
            'stability' => $stabilities === [] ? null : round(array_sum($stabilities) / count($stabilities), 4),
            'dimensions' => $dimMeans === [] ? null : $dimMeans,
            'avg_patch_bloat' => $patchBloats === [] ? null : round(array_sum($patchBloats) / count($patchBloats), 6),
            'reality' => $realities[0] ?? null,
            'per_arm' => array_map(function (array $row): array {
                return $this->enrichTokenThroughput([
                    'task_type' => $row['task_type'] ?? null,
                    'arm_id' => $row['arm_id'] ?? null,
                    'success_rate_itt' => $row['success_rate_itt'] ?? null,
                    'success_rate_wilson_95' => $row['success_rate_wilson_95'] ?? null,
                    'cost_per_task' => $row['cost_per_task'] ?? null,
                    'cost_per_1k_tokens' => $row['cost_per_1k_tokens'] ?? null,
                    'total_cost_usd' => $row['total_cost_usd'] ?? null,
                    'median_wall_ms' => $row['median_wall_ms'] ?? null,
                    'median_wall_sec' => $row['median_wall_sec'] ?? null,
                    'p95_wall_ms' => $row['p95_wall_ms'] ?? null,
                    'avg_tokens_in' => $row['avg_tokens_in'] ?? null,
                    'avg_tokens_out' => $row['avg_tokens_out'] ?? null,
                    'total_tokens_in' => $row['total_tokens_in'] ?? null,
                    'total_tokens_out' => $row['total_tokens_out'] ?? null,
                    'total_tokens' => $row['total_tokens'] ?? null,
                    'tokens_per_task' => $row['tokens_per_task'] ?? null,
                    'avg_tokens_per_task' => $row['avg_tokens_per_task'] ?? null,
                    'tokens_in_per_task' => $row['tokens_in_per_task'] ?? null,
                    'tokens_out_per_task' => $row['tokens_out_per_task'] ?? null,
                    'tokens_per_second' => $row['tokens_per_second'] ?? null,
                    'tokens_per_second_aggregate' => $row['tokens_per_second_aggregate'] ?? null,
                    'tokens_in_per_second' => $row['tokens_in_per_second'] ?? null,
                    'tokens_out_per_second' => $row['tokens_out_per_second'] ?? null,
                    'tokens_coverage' => $row['tokens_coverage'] ?? null,
                    'stability' => $row['stability'] ?? null,
                    'environment_failure_rate' => $row['environment_failure_rate'] ?? null,
                    'failure_classes' => $row['failure_classes'] ?? [],
                    'dimensions' => $row['dimensions'] ?? null,
                    'n' => $row['n'] ?? null,
                    'successes' => $row['successes'] ?? null,
                ]);
            }, $rows),
        ]);
    }

    /**
     * @return list<array<string, mixed>>
     */
    private function harvestNativeSignals(string $suiteId, string $runId): array
    {
        $dir = RunPaths::runDir($runId).'/external_results/units';
        if (! is_dir($dir)) {
            return [];
        }

        $out = [];
        foreach (array_diff(scandir($dir) ?: [], ['.', '..']) as $file) {
            if (! str_ends_with($file, '.json')) {
                continue;
            }
            $payload = json_decode((string) file_get_contents($dir.'/'.$file), true);
            if (! is_array($payload)) {
                continue;
            }
            foreach ($this->extractNativeRows($suiteId, $payload) as $row) {
                $row['_unit_file'] = $file;
                $out[] = $row;
            }
        }

        return $out;
    }

    /**
     * @param  array<string, mixed>  $payload
     * @return list<array<string, mixed>>
     */
    private function extractNativeRows(string $suiteId, array $payload): array
    {
        return match ($suiteId) {
            'tau2_bench' => array_map(static function (array $sim): array {
                $usage = (array) ($sim['usage'] ?? []);

                return [
                    'simulation_id' => $sim['simulation_id'] ?? null,
                    'task_id' => $sim['task_id'] ?? null,
                    'reward' => $sim['reward'] ?? null,
                    'termination_reason' => $sim['termination_reason'] ?? null,
                    'duration_sec' => $sim['duration_sec'] ?? null,
                    'tokens_in' => $usage['input_tokens'] ?? null,
                    'tokens_out' => $usage['output_tokens'] ?? null,
                    'cost_usd' => $usage['cost_usd'] ?? null,
                ];
            }, array_values(array_filter((array) ($payload['simulations'] ?? []), 'is_array'))),
            'bfcl' => array_map(static fn (array $row): array => [
                'case_id' => $row['case_id'] ?? null,
                'test_category' => $row['test_category'] ?? null,
                'native_category' => $row['native_category'] ?? null,
                'accuracy' => $row['accuracy'] ?? null,
                'status' => $row['status'] ?? null,
                'duration_sec' => $row['duration_sec'] ?? null,
                'tokens_in' => $row['tokens_in'] ?? null,
                'tokens_out' => $row['tokens_out'] ?? null,
                'cost_usd' => $row['cost_usd'] ?? null,
                'field_presence' => $row['field_presence'] ?? null,
            ], array_values(array_filter((array) ($payload['results'] ?? []), 'is_array'))),
            'terminal_bench' => array_map(static fn (array $ep): array => [
                'episode_id' => $ep['episode_id'] ?? null,
                'exit_status' => $ep['exit_status'] ?? null,
                'failure_mode' => $ep['failure_mode'] ?? null,
                'duration_sec' => $ep['duration_sec'] ?? null,
                'input_tokens' => $ep['input_tokens'] ?? null,
                'output_tokens' => $ep['output_tokens'] ?? null,
                'cost_usd' => $ep['cost_usd'] ?? null,
                'field_presence' => $ep['field_presence'] ?? null,
            ], array_values(array_filter((array) ($payload['episodes'] ?? []), 'is_array'))),
            'senior_swe_bench' => array_map(static function (array $task) use ($payload): array {
                return [
                    'task_id' => $task['task_id'] ?? null,
                    'task' => $task['task'] ?? null,
                    'resolved' => $task['resolved'] ?? null,
                    'exception_info' => $task['exception_info'] ?? null,
                    'duration_seconds' => $task['duration_seconds'] ?? null,
                    'usage' => $task['usage'] ?? null,
                    'verdicts' => $task['verdicts'] ?? null,
                    'judge_config' => $payload['judge_config'] ?? null,
                    'coverage' => $payload['coverage'] ?? null,
                ];
            }, array_values(array_filter((array) ($payload['tasks'] ?? []), 'is_array'))),
            'swe_bench_live' => array_map(static fn (array $inst): array => [
                'instance_id' => $inst['instance_id'] ?? null,
                'resolved' => $inst['resolved'] ?? null,
                'eval_status' => $inst['eval_status'] ?? null,
                'duration_sec' => $inst['duration_sec'] ?? null,
                'usage' => $inst['usage'] ?? null,
                'model_name_or_path' => $inst['model_name_or_path'] ?? null,
            ], array_values(array_filter((array) ($payload['instances'] ?? []), 'is_array'))),
            'live_code_bench' => array_map(static fn (array $row): array => [
                'question_id' => $row['question_id'] ?? ($row['native_question_id'] ?? null),
                'pass@1' => $row['pass@1'] ?? null,
                'graded_list' => $row['graded_list'] ?? null,
                'difficulty' => $row['difficulty'] ?? null,
                'platform' => $row['platform'] ?? null,
                'contest_id' => $row['contest_id'] ?? null,
                'usage_capture' => $row['usage_capture'] ?? null,
                'tokens_in' => $row['tokens_in'] ?? null,
                'tokens_out' => $row['tokens_out'] ?? null,
                'duration_sec' => $row['duration_sec'] ?? null,
            ], array_values(array_filter((array) ($payload['results'] ?? []), 'is_array'))),
            'inspect_evals' => array_map(static function (array $sample) use ($payload): array {
                return [
                    'sample_id' => $sample['id'] ?? null,
                    'scores' => $sample['scores'] ?? null,
                    'total_time' => $sample['total_time'] ?? null,
                    'working_time' => $sample['working_time'] ?? null,
                    'model_usage' => $sample['model_usage'] ?? null,
                    'completed' => $sample['completed'] ?? null,
                    'error' => $sample['error'] ?? null,
                    'retries' => $sample['retries'] ?? null,
                    'eval' => $payload['eval'] ?? null,
                ];
            }, array_values(array_filter((array) ($payload['samples'] ?? []), 'is_array'))),
            'hal_harness' => array_map(static fn (array $run): array => [
                'task_id' => $run['task_id'] ?? null,
                'success' => $run['success'] ?? null,
                'total_cost_usd' => $run['total_cost_usd'] ?? null,
                'latency_sec' => $run['latency_sec'] ?? null,
                'input_tokens' => $run['input_tokens'] ?? null,
                'output_tokens' => $run['output_tokens'] ?? null,
                'field_presence' => $run['field_presence'] ?? null,
                'runtime_bridge' => $run['runtime_bridge'] ?? null,
                'model' => $run['model'] ?? null,
                'agent' => $run['agent'] ?? null,
            ], array_values(array_filter((array) ($payload['runs'] ?? []), 'is_array'))),
            'aider_polyglot' => array_map(static fn (array $row): array => [
                'testcase' => $row['testcase'] ?? null,
                'language' => $row['language'] ?? null,
                'tries' => $row['tries'] ?? null,
                'tests_outcomes' => $row['tests_outcomes'] ?? null,
                'duration' => $row['duration'] ?? null,
                'cost' => $row['cost'] ?? null,
                'sent_tokens' => $row['sent_tokens'] ?? null,
                'received_tokens' => $row['received_tokens'] ?? null,
            ], array_values(array_filter((array) ($payload['results'] ?? []), 'is_array'))),
            'swe_marathon' => array_map(static fn (array $task): array => [
                'task_id' => $task['task_id'] ?? null,
                'resolved' => $task['resolved'] ?? null,
                'exception_info' => $task['exception_info'] ?? null,
                'duration_seconds' => $task['duration_seconds'] ?? null,
                'usage' => $task['usage'] ?? null,
                'agent' => $task['agent'] ?? null,
                'model' => $task['model'] ?? null,
            ], array_values(array_filter((array) ($payload['tasks'] ?? []), 'is_array'))),
            default => [],
        };
    }

    /**
     * @param  array<string, mixed>  $meta
     * @param  array<string, mixed>  $delivery
     * @return list<string>
     */
    private function caseIdsForRun(string $runId, array $meta, array $delivery): array
    {
        $planPath = RunPaths::planPath($runId);
        if (is_file($planPath)) {
            $plan = json_decode((string) file_get_contents($planPath), true) ?? [];
            $fromPlan = array_values(array_filter(
                array_map('strval', (array) ($plan['case_ids'] ?? [])),
                fn (string $id): bool => $id !== '',
            ));
            if ($fromPlan !== []) {
                return $fromPlan;
            }
        }
        $fromScope = array_values(array_filter(
            array_map('strval', (array) ($meta['claim_scope']['cases'] ?? [])),
            fn (string $id): bool => $id !== '',
        ));
        if ($fromScope !== []) {
            return $fromScope;
        }

        return array_values(array_map('strval', (array) ($delivery['fase_a_case_pack'] ?? [])));
    }

    /** @return array<string, mixed> */
    private function runArtifacts(string $runId): array
    {
        $dir = RunPaths::runDir($runId);
        $map = [
            'report_json' => $dir.'/report.json',
            'report_md' => $dir.'/report.md',
            'report_csv' => $dir.'/report.csv',
            'adjudication_json' => $dir.'/adjudication.json',
            'evidence_pack_json' => $dir.'/evidence_pack.json',
            'plan_json' => $dir.'/plan.json',
            'native_execution_manifest_json' => $dir.'/native_execution_manifest.json',
            'receipts_jsonl' => $dir.'/receipts.jsonl',
            'events_jsonl' => RunPaths::eventsPath($runId),
            'uplift_json' => $dir.'/uplift.json',
        ];
        $out = [];
        foreach ($map as $key => $path) {
            $out[$key] = [
                'path' => $path,
                'present' => is_file($path),
                'bytes' => is_file($path) ? filesize($path) : null,
            ];
        }

        return $out;
    }

    /**
     * @param  list<array<string, mixed>>  $signals
     * @return list<string>
     */
    private function flattenObservedKeys(array $signals): array
    {
        $keys = [];
        foreach ($signals as $signal) {
            foreach (array_keys($signal) as $key) {
                if ($key === '_unit_file') {
                    continue;
                }
                $value = $signal[$key];
                if ($value === null) {
                    continue;
                }
                $keys[$key] = true;
                if (is_array($value) && ! array_is_list($value)) {
                    foreach (array_keys($value) as $child) {
                        $keys[$key.'.'.$child] = true;
                    }
                }
            }
        }
        $list = array_keys($keys);
        sort($list);

        return $list;
    }

    /**
     * @param  array<string, mixed>  $delivery
     * @param  list<string>  $observedNative
     * @param  list<string>  $observedReport
     * @return array<string, mixed>
     */
    private function deliveryCoverage(array $delivery, array $observedNative, array $observedReport): array
    {
        $expectedNative = array_values(array_map('strval', (array) ($delivery['native_metrics'] ?? [])));
        $expectedReport = array_values(array_map('strval', (array) ($delivery['atlas_report_metrics'] ?? [])));
        $nativeHit = [];
        $nativeMiss = [];
        foreach ($expectedNative as $metric) {
            $base = explode('.', $metric, 2)[0];
            $hit = in_array($metric, $observedNative, true)
                || in_array($base, $observedNative, true)
                || ($base !== $metric && str_starts_with($metric, $base.'.') && in_array($base, $observedNative, true))
                || ($metric === 'verdicts.*' && count(array_filter($observedNative, fn (string $k): bool => str_starts_with($k, 'verdicts'))) > 0)
                || ($metric === 'scores' && in_array('scores', $observedNative, true));
            // wildcard / nested tolerance
            if (! $hit) {
                foreach ($observedNative as $obs) {
                    if ($obs === $base || str_starts_with($obs, $base.'.') || str_starts_with($metric, $obs)) {
                        $hit = true;
                        break;
                    }
                    if (str_ends_with($metric, '.*') && str_starts_with($obs, substr($metric, 0, -1))) {
                        $hit = true;
                        break;
                    }
                }
            }
            if ($hit) {
                $nativeHit[] = $metric;
            } else {
                $nativeMiss[] = $metric;
            }
        }
        $reportHit = array_values(array_intersect($expectedReport, $observedReport));
        $reportMiss = array_values(array_diff($expectedReport, $observedReport));

        return [
            'native_expected' => count($expectedNative),
            'native_observed' => count($nativeHit),
            'native_missing' => $nativeMiss,
            'report_expected' => count($expectedReport),
            'report_observed' => count($reportHit),
            'report_missing' => $reportMiss,
            'dimensions_expected' => array_values((array) ($delivery['capability_dimensions'] ?? [])),
            'uplift_eligible' => (bool) ($delivery['uplift_eligible'] ?? false),
            'uplift_family' => $delivery['uplift_family'] ?? null,
        ];
    }

    /** @param list<mixed> $values */
    private function meanNullable(array $values): ?float
    {
        $nums = array_values(array_filter($values, 'is_numeric'));
        if ($nums === []) {
            return null;
        }

        return round(array_sum(array_map('floatval', $nums)) / count($nums), 6);
    }

    /** @param list<mixed> $values */
    private function sumNullable(array $values): ?float
    {
        $nums = array_values(array_filter($values, 'is_numeric'));
        if ($nums === []) {
            return null;
        }

        return round(array_sum(array_map('floatval', $nums)), 6);
    }

    /** @param list<float> $values */
    private function median(array $values): float
    {
        sort($values);
        $n = count($values);
        $mid = intdiv($n, 2);
        if ($n % 2 === 1) {
            return round($values[$mid], 6);
        }

        return round(($values[$mid - 1] + $values[$mid]) / 2, 6);
    }

    /** @param array<string, mixed> $report */
    private function csv(array $report): string
    {
        $handle = fopen('php://temp', 'w+');
        fputcsv($handle, [
            'suite_id',
            'category',
            'status',
            'run_id',
            'success_rate_itt',
            'intelligence_rate',
            'median_wall_ms',
            'p95_wall_ms',
            'tokens_in_avg',
            'tokens_out_avg',
            'tokens_per_task',
            'tokens_per_second',
            'total_tokens',
            'cost_per_1k_tokens',
            'cost_per_task',
            'cost_basis',
            'env_failure_rate',
            'stability',
            'uplift_family',
            'native_coverage',
            'report_coverage',
            'pipeline_valid',
            'internal_claim_allowed',
            'events_complete',
            'is_atlas_fact',
            'measurement_status',
            'missing_fields',
            'case_ids',
        ]);
        foreach ($report['suite_rows'] as $row) {
            $full = (array) ($row['full_metrics'] ?? []);
            $cov = (array) ($row['delivery_coverage'] ?? []);
            $axes = (array) ($row['axes'] ?? []);
            fputcsv($handle, [
                $row['suite_id'],
                $row['category'] ?? ($row['delivery']['category'] ?? ''),
                $row['status'],
                $row['run_id'],
                $row['success_rate_itt'],
                $row['intelligence_rate'] ?? null,
                $row['median_wall_ms'],
                $full['p95_wall_ms'] ?? null,
                $row['tokens_in_avg'],
                $row['tokens_out_avg'],
                $row['tokens_per_task'] ?? ($full['tokens_per_task'] ?? null),
                $row['tokens_per_second'] ?? ($full['tokens_per_second'] ?? null),
                $row['total_tokens'] ?? ($full['total_tokens'] ?? null),
                $row['cost_per_1k_tokens'] ?? ($full['cost_per_1k_tokens'] ?? null),
                $row['cost_per_task'],
                $row['cost_basis'],
                $row['env_failure_rate'],
                $full['stability'] ?? null,
                $row['delivery']['uplift_family'] ?? null,
                ($cov['native_observed'] ?? 0).'/'.($cov['native_expected'] ?? 0),
                ($cov['report_observed'] ?? 0).'/'.($cov['report_expected'] ?? 0),
                ($row['pipeline_valid'] ?? false) ? 'true' : 'false',
                ($row['internal_claim_allowed'] ?? false) ? 'true' : 'false',
                ($row['events_complete'] ?? false) ? 'true' : 'false',
                ($row['is_atlas_fact'] ?? false) ? 'true' : 'false',
                $axes['measurement']['status'] ?? '',
                implode('|', (array) ($row['missing_fields'] ?? [])),
                implode('|', (array) ($row['case_ids'] ?? [])),
            ]);
        }
        rewind($handle);
        $csv = stream_get_contents($handle);
        fclose($handle);

        return (string) $csv;
    }

    private function eventsCompleteForRun(string $runId): bool
    {
        return EventsLifecycleContract::isClaimGradeComplete(
            RunPaths::eventsPath($runId),
        );
    }

    /**
     * @param  list<string>  $missing
     */
    private function measurementStatus(array $missing, bool $coverageIncomplete, string $suiteId, ?string $runId = null): string
    {
        if ($this->harnessOmitsUsage($suiteId, $runId, $missing)) {
            return 'harness_omit';
        }
        if ($missing === [] && ! $coverageIncomplete) {
            return 'complete';
        }
        if ($missing !== [] && $coverageIncomplete === false) {
            return 'omitted';
        }
        if ($coverageIncomplete) {
            return 'partial';
        }

        return $missing === [] ? 'complete' : 'omitted';
    }

    /**
     * @param  list<string>  $missing
     */
    private function harnessOmitsUsage(string $suiteId, ?string $runId, array $missing): bool
    {
        if ($suiteId === 'inspect_evals' && $missing !== []) {
            return true;
        }
        if ($runId === null || $missing === []) {
            return false;
        }
        foreach (RunReceipt::loadAll($runId) as $receipt) {
            $presence = (array) ($receipt->data['field_presence'] ?? []);
            foreach (['tokens_in', 'tokens_out', 'cost_usd'] as $field) {
                $reason = (string) (($presence[$field]['reason'] ?? '') ?: '');
                if ($reason !== '' && preg_match('/omit|harness_omit|inspect_logs_omit/i', $reason) === 1) {
                    return true;
                }
            }
        }

        return false;
    }

    /**
     * Ledger de execução por unidade: a evidência que separa "modelo errou" de
     * "teste/ambiente falhou ou não terminou". Cada unidade carrega status,
     * failure_class, exit code, wall_ms e — para as que NÃO deram success — o
     * tail do stderr do log nativo (o "porquê"). Somado a isso, um veredito de
     * confiabilidade: env-failure alta ou unidades faltando ⇒ suíte não_confiável
     * (não conta como fraqueza do modelo).
     *
     * @return array<string,mixed>
     */
    private function executionEvidenceForRun(string $runId, int $unitsExpected = 0): array
    {
        $receipts = RunReceipt::loadAll($runId);

        // exit_code + log tail por unidade nativa, indexado pelo prefixo do
        // expected_result_path (<case>__<arm>__r<rep>__<hash>.json).
        $native = [];
        foreach (NativeExecutionReceipt::loadAll($runId) as $nr) {
            $file = basename((string) ($nr->data['expected_result_path'] ?? ''));
            $key = preg_replace('/__[0-9a-f]+\.json$/', '', $file) ?: $file;
            $logDir = RunPaths::nativeReceiptsDir($runId).'/logs';
            $stderrPath = $logDir.'/'.($nr->data['execution_id'] ?? '').'.stderr.log';
            $native[$key] = [
                'execution_id' => $nr->data['execution_id'] ?? null,
                'exit_code' => $nr->data['exit_code'] ?? null,
                'exit_nonzero_promoted' => (bool) ($nr->data['exit_nonzero_promoted'] ?? false),
                'wall_ms' => $nr->data['wall_ms'] ?? null,
                'stderr_path' => is_file($stderrPath) ? $stderrPath : null,
            ];
        }

        $units = [];
        $classes = ['success' => 0, 'model_failure' => 0, 'environment_failure' => 0, 'timeout' => 0, 'invalid_result' => 0, 'other' => 0];
        foreach ($receipts as $r) {
            $status = (string) ($r->data['status'] ?? '');
            $failureClass = (string) ($r->data['failure_class'] ?? '');
            $bucket = match (true) {
                $status === 'success' => 'success',
                $failureClass === FailureClass::ENVIRONMENT => 'environment_failure',
                $failureClass === 'model_failure' => 'model_failure',
                $failureClass === 'timeout', $status === 'timeout' => 'timeout',
                $failureClass === 'invalid_result' => 'invalid_result',
                default => 'other',
            };
            $key = ($r->data['case_id'] ?? '').'__'.str_replace('@', '_', (string) ($r->data['arm_id'] ?? '')).'__r'.($r->data['repetition'] ?? '');
            $nat = $native[$key] ?? [];
            $stderrTail = null;
            $raw = '';
            if ($bucket !== 'success' && is_string($nat['stderr_path'] ?? null)) {
                $raw = (string) file_get_contents($nat['stderr_path']);
                $stderrTail = mb_substr(rtrim($raw), -800);
            }
            // Rede de segurança cross-adapter: se o log mostra erro de API/infra
            // (400, role incompatível, timeout, conexão), a tarefa NÃO foi o
            // modelo errando — reclassifica para ambiente/fluxo mesmo que o
            // adapter tenha marcado model_failure. Impede "0% de raciocínio"
            // quando a verdade é a API recusando a chamada.
            $reclassified = null;
            if (in_array($bucket, ['model_failure', 'invalid_result', 'other'], true)
                && $raw !== ''
                && preg_match('/BadRequestError|error code: 4\d\d|unsupported_message_role|invalid_request_error|does not support|ConnectionError|ReadTimeout|RateLimitError|ServiceUnavailable|InternalServerError|502 Bad Gateway|503 Service/i', $raw) === 1) {
                $reclassified = $bucket;
                $bucket = 'environment_failure';
            }
            $classes[$bucket]++;
            $units[] = [
                'case_id' => $r->data['case_id'] ?? null,
                // task_type é o que a unidade REALMENTE mede. Uma suíte pode
                // abranger domínios distintos (inspect_evals = gsm8k matemática
                // + mmlu conhecimento + gpqa ciência); sem isto, capacidade só
                // pode ser mapeada por suíte e conhecimento viraria "raciocínio".
                'task_type' => $r->data['task_type'] ?? null,
                'arm_id' => $r->data['arm_id'] ?? null,
                'repetition' => $r->data['repetition'] ?? null,
                'status' => $status,
                'failure_class' => $bucket === 'environment_failure' ? FailureClass::ENVIRONMENT : ($failureClass ?: null),
                'reclassified_from' => $reclassified,
                'blame' => match ($bucket) {
                    'success' => 'success',
                    'model_failure', 'invalid_result' => 'model',
                    'environment_failure', 'timeout' => 'environment_or_flow',
                    default => 'unknown',
                },
                'exit_code' => $nat['exit_code'] ?? null,
                'exit_nonzero_promoted' => $nat['exit_nonzero_promoted'] ?? false,
                'wall_ms' => $r->data['wall_ms'] ?? ($nat['wall_ms'] ?? null),
                'stderr_log' => $nat['stderr_path'] ?? null,
                'stderr_tail' => $stderrTail,
            ];
        }

        $total = count($units);
        $envAndFlow = $classes['environment_failure'] + $classes['timeout'];
        $modelAttributable = $classes['success'] + $classes['model_failure'] + $classes['invalid_result'];
        $unitsMissing = $unitsExpected > 0 ? max(0, $unitsExpected - $total) : 0;
        $envRate = $total > 0 ? round($envAndFlow / $total, 4) : 0.0;
        // Confiável = dá para JULGAR O MODELO nesta suíte. O critério é COBERTURA
        // (fração de unidades com desfecho atribuível ao modelo), não o gate de
        // claim de 5%: uma suíte 17/18 model_failure é uma fraqueza real do
        // modelo; uma suíte 7/9 environment_failure é o teste que não rodou.
        $coverage = $total > 0 ? round($modelAttributable / $total, 4) : 0.0;
        $minCoverage = (float) config('atlas_rivals.report.min_model_coverage', 0.7);
        $reliable = $total > 0 && $unitsMissing === 0 && $coverage >= $minCoverage;
        $reason = match (true) {
            $total === 0 => 'no_units_recorded',
            $unitsMissing > 0 => 'units_missing:'.$unitsMissing.'_of_'.$unitsExpected,
            $coverage < $minCoverage => 'model_coverage_'.$coverage.'_below_'.$minCoverage.'_env_or_flow_ate_the_run',
            default => null,
        };
        // O slug acima é para máquina. O humano precisa da frase: um leitor não
        // pode ter de decifrar "model_coverage_0.22_below_0.7" para entender que
        // o teste quebrou e o modelo não está sendo julgado.
        $reasonHuman = match (true) {
            $total === 0 => 'Nenhuma tarefa foi registrada — a suíte não chegou a rodar.',
            $unitsMissing > 0 => "{$unitsMissing} de {$unitsExpected} tarefas não foram registradas — execução incompleta.",
            $coverage < $minCoverage => "{$envAndFlow} de {$total} tarefas quebraram por erro de ambiente/fluxo (o teste não rodou até o fim), "
                ."não por erro do modelo. Sobra pouco para julgar: não é falha do modelo, é medição que não aconteceu.",
            default => null,
        };

        return [
            'units_expected' => $unitsExpected,
            'units_recorded' => $total,
            'units_missing' => $unitsMissing,
            'class_counts' => $classes,
            'environment_or_flow_rate' => $envRate,
            'model_coverage' => $coverage,
            'reliable' => $reliable,
            'unreliable_reason' => $reason,
            'unreliable_reason_human' => $reasonHuman,
            'blame_summary' => [
                'model_failures' => $classes['model_failure'] + $classes['invalid_result'],
                'environment_or_flow_failures' => $envAndFlow,
                'successes' => $classes['success'],
            ],
            // Mesmo cálculo do agregado, fatiado por task_type: permite tratar
            // cada domínio de uma suíte multi-domínio como capacidade própria,
            // com sua confiabilidade (env-failure de um não contamina o outro).
            'blame_by_task_type' => $this->blameByTaskType($units, $minCoverage),
            'units' => $units,
        ];
    }

    /**
     * Agrega a evidência dos task_types pedidos numa fatia única (mesma conta do
     * agregado da suíte, restrita ao domínio). null = a suíte não mede nenhum.
     *
     * @param  array<string,mixed>  $ev
     * @param  list<string>  $taskTypes
     * @return array<string,mixed>|null
     */
    private function sliceByTaskTypes(array $ev, array $taskTypes): ?array
    {
        $byType = (array) ($ev['blame_by_task_type'] ?? []);
        $successes = 0;
        $modelFailures = 0;
        $envFailures = 0;
        $found = false;
        foreach ($taskTypes as $taskType) {
            $g = $byType[$taskType] ?? null;
            if (! is_array($g)) {
                continue;
            }
            $found = true;
            $successes += (int) ($g['successes'] ?? 0);
            $modelFailures += (int) ($g['model_failures'] ?? 0);
            $envFailures += (int) ($g['environment_or_flow_failures'] ?? 0);
        }
        if (! $found) {
            return null;
        }
        $decidable = $successes + $modelFailures;
        $total = $decidable + $envFailures;
        $coverage = $total > 0 ? round($decidable / $total, 4) : 0.0;
        $minCoverage = (float) config('atlas_rivals.report.min_model_coverage', 0.7);
        $reliable = $total > 0 && $coverage >= $minCoverage;

        return [
            'tasks_decidable' => $decidable,
            'intelligence_rate' => $decidable > 0 ? round($successes / $decidable, 4) : null,
            'reliable' => $reliable,
            'unreliable_reason_human' => $reliable ? null : ($total === 0
                ? 'Nenhuma tarefa registrada para esta habilidade.'
                : "{$envFailures} de {$total} tarefas quebraram por erro de ambiente/fluxo "
                    .'(o teste não rodou até o fim), não por erro do modelo.'),
        ];
    }

    /**
     * @param  list<array<string,mixed>>  $units
     * @return array<string, array<string,mixed>>
     */
    private function blameByTaskType(array $units, float $minCoverage): array
    {
        $groups = [];
        foreach ($units as $unit) {
            $taskType = (string) ($unit['task_type'] ?? '');
            if ($taskType === '') {
                continue;
            }
            $groups[$taskType] ??= ['successes' => 0, 'model_failures' => 0, 'environment_or_flow_failures' => 0];
            match ((string) ($unit['blame'] ?? '')) {
                'success' => $groups[$taskType]['successes']++,
                'model' => $groups[$taskType]['model_failures']++,
                'environment_or_flow' => $groups[$taskType]['environment_or_flow_failures']++,
                default => null,
            };
        }

        $out = [];
        foreach ($groups as $taskType => $g) {
            $decidable = $g['successes'] + $g['model_failures'];
            $total = $decidable + $g['environment_or_flow_failures'];
            $coverage = $total > 0 ? round($decidable / $total, 4) : 0.0;
            $reliable = $total > 0 && $coverage >= $minCoverage;
            $out[$taskType] = [
                'successes' => $g['successes'],
                'model_failures' => $g['model_failures'],
                'environment_or_flow_failures' => $g['environment_or_flow_failures'],
                'tasks_decidable' => $decidable,
                'model_coverage' => $coverage,
                'reliable' => $reliable,
                'intelligence_rate' => $decidable > 0 ? round($g['successes'] / $decidable, 4) : null,
                'unreliable_reason_human' => $reliable ? null : ($total === 0
                    ? 'Nenhuma tarefa registrada para esta habilidade.'
                    : "{$g['environment_or_flow_failures']} de {$total} tarefas quebraram por erro de ambiente/fluxo "
                        .'(o teste não rodou até o fim), não por erro do modelo.'),
            ];
        }

        return $out;
    }

    /**
     * Prefer report intelligence_rate; fall back to receipts (excludes environment_failure).
     */
    private function intelligenceFromReceipts(string $runId): ?float
    {
        $items = RunReceipt::loadAll($runId);
        if ($items === []) {
            return null;
        }
        $nonEnv = array_values(array_filter(
            $items,
            fn (RunReceipt $r): bool => ($r->data['failure_class'] ?? null) !== FailureClass::ENVIRONMENT,
        ));
        if ($nonEnv === []) {
            return null;
        }
        $successes = count(array_filter(
            $nonEnv,
            fn (RunReceipt $r): bool => ($r->data['status'] ?? null) === 'success',
        ));

        return round($successes / count($nonEnv), 4);
    }

    /** @param array<string, mixed> $payload */
    public static function hashPayload(array $payload): string
    {
        unset($payload['report_hash']);

        return hash('sha256', json_encode(
            self::canonicalize($payload),
            JSON_UNESCAPED_SLASHES | JSON_PRESERVE_ZERO_FRACTION,
        ));
    }

    private static function canonicalize(mixed $value): mixed
    {
        if (! is_array($value)) {
            return $value;
        }
        if (! array_is_list($value)) {
            ksort($value);
        }

        return array_map(self::canonicalize(...), $value);
    }
}
