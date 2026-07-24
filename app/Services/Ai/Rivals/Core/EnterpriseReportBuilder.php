<?php

namespace App\Services\Ai\Rivals\Core;

use App\Services\Ai\Rivals\Core\EnterpriseReport\EnterpriseReportCapabilitySection;
use App\Services\Ai\Rivals\Core\EnterpriseReport\EnterpriseReportModelSection;
use App\Services\Ai\Rivals\Core\EnterpriseReport\EnterpriseReportSuiteRowSection;
use App\Services\Ai\Rivals\Core\EnterpriseReport\EnterpriseReportSupport;
use App\Services\Ai\Rivals\Core\EnterpriseReport\EnterpriseReportUpliftSkillsSection;
use App\Services\Ai\Rivals\Support\AtomicWriter;
use App\Services\Ai\Rivals\Support\RunPaths;
use App\Services\Ai\Rivals\Support\SchemaContract;
use RuntimeException;
use App\Support\YesNo;

/**
 * Relatório empresarial consolidado Fase A — sempre 10 suites, nunca claim agregado.
 */
class EnterpriseReportBuilder
{
    private string $profile = 'fase_a';

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
        // é uma BIBLIOTECA com 129 evals. ~20 ligados; o resto dorme instalado no
        // repo. `dormant` dimensiona a lacuna: declarar "não coberto" sem dizer
        // que há N instrumentos parados ali subestima o que falta. O total sai de
        // array_sum(dormant) — não repita o número aqui, ele envelhece.
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

    public function build(string $profile = 'fase_a'): array
    {
        $this->profile = $profile;
        $support = new EnterpriseReportSupport;
        $suiteRowSection = new EnterpriseReportSuiteRowSection($support, $profile);
        $capabilitySection = new EnterpriseReportCapabilitySection($support);
        $modelSection = new EnterpriseReportModelSection($support);
        $upliftSkillsSection = new EnterpriseReportUpliftSkillsSection($support);
        $suiteIds = (new SuiteRegistry)->profileSuiteIds($profile);
        $upliftFamilies = $profile === 'engineering_native'
            ? array_combine($suiteIds, $suiteIds)
            : (array) config('atlas_rivals.uplift_families', []);
        $primaryModel = (string) config('atlas_rivals.fase_a.primary_model', 'verboo_kimi_k2_7');

        $runs = $this->scanRuns();
        $suiteRows = [];
        $included = [];
        $excluded = [];
        $gaps = [];
        $modelsSeen = [];

        foreach ($suiteIds as $suiteId) {
            $match = $support->bestRunForSuite($suiteId, $runs);
            if ($match === null) {
                $suiteRows[] = $suiteRowSection->emptySuiteRow($suiteId);
                $gaps[] = "not_run:{$suiteId}";

                continue;
            }

            [$runId, $meta] = $match;
            $row = $suiteRowSection->suiteRowFromRun($suiteId, $runId, $meta);
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
            $familyRow = $upliftSkillsSection->upliftFamilyRow((string) $family, (string) $suiteId, $runs, $primaryModel);
            $atlasUplift['families'][] = $familyRow;
            if ($familyRow['status'] === 'not_run') {
                $gaps[] = "uplift_not_run:{$family}";
            } elseif (($familyRow['status'] ?? '') !== 'real_uplift') {
                $gaps[] = 'uplift_'.$familyRow['status'].':'.$family
                    .(isset($familyRow['reason']) ? ':'.$familyRow['reason'] : '');
            }
        }

        // `uplift_families` is the five-family analytical slice, not execution
        // support. All ten external suites now have distinct Atlas routes.
        $atlasUplift['bare_only_suites'] = [];
        $atlasUplift['additional_dual_arm_suites'] = array_values(array_map(
            static fn (string $suiteId): array => [
                'suite_id' => $suiteId,
                'status' => 'dual_arm_supported',
                'reason' => 'outside_five_family_analytical_slice',
            ],
            array_diff($suiteIds, array_values($upliftFamilies)),
        ));

        $modelMatrix = count($modelIds) >= 2
            ? [
                'mode' => 'model_vs_model',
                'model_ids' => $modelIds,
                'rows' => $modelSection->modelMatrixRows($suiteRows, $runs),
            ]
            : $modelSection->singleModelAtlasFaceMatrix($primaryModel, $suiteRows, $runs, $atlasUplift);

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

        $facts = $modelSection->buildMeasuredFacts($primaryModel, $suiteRows, $atlasUplift, $counts);

        $deliveryInventory = EnterpriseSuiteDeliveryCatalog::all($profile);

        $report = [
            'schema_version' => SchemaContract::ENTERPRISE_REPORT,
            'profile' => $profile,
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
            'model_capabilities' => $capabilitySection->buildCapabilityAggregates($suiteRows, $atlasUplift, $primaryModel),
            'model_profiles' => $modelSection->buildModelProfiles(
                $dissections,
                $atlasUplift,
                $upliftSkillsSection->suiteReliabilityMap($suiteRows),
            ),
            'model_matrix' => $modelMatrix,
            // Os runs de uplift entram junto: o braço Atlas vive NELES, não nos
            // runs bare mais novos que o resto do relatório usa. Sem eles a aba
            // diria "0 habilidades com os dois braços" enquanto a aba Uplift
            // mostra 5 famílias com par real — duas verdades na mesma tela.
            'skills' => $upliftSkillsSection->buildSkills(array_merge($included, array_values(array_filter(array_map(
                static fn (array $f): ?string => is_string($f['run_id'] ?? null) ? $f['run_id'] : null,
                (array) ($atlasUplift['families'] ?? []),
            )))), $atlasUplift),
            'atlas_uplift' => $atlasUplift,
            // Veredito com-vs-sem-Atlas por capacidade: MESMA fonte que o app
            // nativo (ArenaCapabilityProfileService — pool + Wilson + Newcombe +
            // guarda de seleção + purga da era-fraude). Uma verdade, dois
            // renderizadores; duas agregações divergentes foi exatamente a
            // classe de mentira morta em 20/07.
            'arena_capability_profile' => $capabilitySection->arenaCapabilityProfile(),
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
            RunPaths::enterpriseReportPath($profile),
            json_encode($report, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES | JSON_PRESERVE_ZERO_FRACTION),
        );
        $presenter = new EnterpriseReportPresenter;
        AtomicWriter::write(
            RunPaths::enterpriseMarkdownPath($profile),
            $presenter->markdown($report, $runs),
        );
        AtomicWriter::write(RunPaths::enterpriseCsvPath($profile), $this->csv($report));
        AtomicWriter::write(
            RunPaths::enterpriseHtmlPath($profile),
            $presenter->html($report, $runs),
        );

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
                YesNo::trueFalse($row['pipeline_valid'] ?? false),
                YesNo::trueFalse($row['internal_claim_allowed'] ?? false),
                YesNo::trueFalse($row['events_complete'] ?? false),
                YesNo::trueFalse($row['is_atlas_fact'] ?? false),
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

    /**
     * Reflection-pinned; delega para a primitiva leaf. Assinatura identica.
     *
     * @param  array<string,mixed>  $ev
     * @param  list<string>  $taskTypes
     * @return array<string,mixed>|null
     */
    private function sliceByTaskTypes(array $ev, array $taskTypes): ?array
    {
        return (new EnterpriseReportSupport)->sliceByTaskTypes($ev, $taskTypes);
    }

    /** Reflection-pinned; delega para a primitiva leaf. Assinatura identica. */
    private function runHasBareArm(array $run): bool
    {
        return (new EnterpriseReportSupport)->runHasBareArm($run);
    }

    /**
     * Reflection-pinned; delega para a section. Assinatura identica.
     *
     * @param  list<array<string, mixed>>  $suiteRows
     * @param  array<string, mixed>  $atlasUplift
     * @param  array<string, int>  $counts
     * @return array<string, mixed>
     */
    private function buildMeasuredFacts(string $primaryModel, array $suiteRows, array $atlasUplift, array $counts): array
    {
        return (new EnterpriseReportModelSection(new EnterpriseReportSupport))
            ->buildMeasuredFacts($primaryModel, $suiteRows, $atlasUplift, $counts);
    }

    /**
     * Reflection-pinned; delega para a section. Assinatura identica.
     *
     * @param  array<string,mixed>  $atlasUplift
     * @param  list<string>  $runIds
     */
    private function atlasArmNote(int $withAtlas, array $atlasUplift, array $runIds = []): ?string
    {
        return (new EnterpriseReportUpliftSkillsSection(new EnterpriseReportSupport))
            ->atlasArmNote($withAtlas, $atlasUplift, $runIds);
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
