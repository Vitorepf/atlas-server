---
id: atlas-dificuldade-precondicao-do-50x
title: A dificuldade como precondição do 50x — graduação, Delta-theta e os 6 graus de liberdade
status: active
priority: 99
owner: operator
created: 2026-07-15
implementation_state: research_complete_execution_pending
---

> Pesquisa de 13 agentes (2,1M tokens, 353 buscas) sobre a tese do operador:
> "se o modelo cru faz 100%, o teste é fraco — eleve o nível até ele não fazer
> nem 30%, senão parar no 100% é fracasso de evolução".
> Verificado contra report.json (built_at 2026-07-15T18:23Z), o código do repo,
> e a wilson() do próprio StatisticalPolicy.

# A DIFICULDADE COMO PRECONDIÇÃO DO 50×
### Relatório ao operador — 15/07/2026 · verificado contra `report.json` (built_at 2026-07-15T18:23:19Z), o código do repo e a `wilson()` do próprio `StatisticalPolicy`

---

## 0. Entendi. E é mais grave do que você formulou.

Você perguntou se eu entendi por que isso decide se o Atlas porta e eleva ASI em >50×. Entendi, e a pesquisa diz três coisas, nesta ordem:

1. **Você está certo, e a aritmética é pior do que você disse.** A mediana de `bare` nas 30 habilidades não é 66,7% — é **86,1%**, o que dá M_max de **1,16×**. O maior M_max *finito* do arsenal inteiro é **4,50×**. E rodando a `wilson()` do repo em todas as 30 linhas assumindo um Atlas **perfeito**: **24 das 30 não provam ganho nenhum** (M_low < 1,0×), e a melhor linha do pacote inteiro prova **2,34×** contra uma barra de 50×.

2. **Mas a dificuldade não CRIA o M — ela REVELA o M.** "Elevar o nível *para que* o Atlas possa atingir 100%" é causalmente falso. Endurecer o teste puxa os **dois** braços pela mesma curva. O teto de M é propriedade do **Atlas** (Δθ), não do teste. Se Δθ < 3,912 logits, 50× é impossível em **qualquer** teste, em **qualquer** n.

3. **E não existe M para elevar.** `atlas_n=0` em **30 de 30** linhas. `skills.with_atlas: 0`. `claim_allowed: false`. O braço rotulado "com Atlas" era `hermes -z` + **3 linhas de preâmbulo**. Elevar a dificuldade de um teste cujo braço de tratamento nunca executou é otimizar o denominador de uma fração sem numerador.

**A frase que resume:** a dificuldade é precondição **necessária** do 50×, e você acertou isso contra o campo inteiro. Mas ela não é suficiente e não é a primeira. **51 execuções decidem se a obra de dificuldade deve existir. 400 execuções por habilidade é o que ela custa.** Faça as 51 antes das 400.

---

## 1. A SUA TESE — CONFIRMADA (4), CORRIGIDA (5), REFUTADA (1)

### ✅ CONFIRMADO

**1.1 — M ≤ 1/bare. Aritmética, não opinião.**
M = atlas/bare, e a taxa é limitada em [0,1], logo M ≤ 1/bare. M≥50× exige bare ≤ 2%. **A dificuldade é precondição, não parâmetro.** Verificado nas 30 linhas:

| bare | M_max | linhas |
|---|---|---|
| 100% | **1,00×** | 13 |
| 88,9% | 1,12× | bbq:Age, tau2_bench |
| 83,3% | 1,20× | hellaswag, niah |
| 66,7% | 1,50× | aider_polyglot, hal_harness, musr, wmdp_bio |
| 50% | 2,00× | ifeval:punctuation |
| 33,3% | 3,00× | live_code_bench |
| **22,2%** | **4,50×** ← *o melhor do arsenal inteiro* | terminal_bench, wmdp_cyber |
| 0% | **INDEFINIDO** (÷0, não infinito) | 5 |

**Mediana bare = 86,1% → M_max da mediana = 1,16×. Zero de 30 linhas PROVAM bare ≤ 2%** (`wilson_high ≤ 0,02` em 0/30).

**1.2 — Informação de Fisher = 0 em P=1. Teorema.**
`I(θ) = a²·P(1−P)`. Em P=1,00 vale exatamente **zero**. As 13 linhas em 100% não são "linhas fracas" — são linhas que, **por teorema, não distinguem respondente nenhum**. Rodar mais n não conserta: informação é 0 × n. Curva verificada: P=0,50 → 100% da informação · P=0,90 → 36% · P=0,95 → 19% · P=0,99 → 4% · **P=1,00 → 0%**.
Fonte: Zhuang et al., arXiv:2306.10512v3 (`Ij(θ)=αj²·P(yj=1|θ)·P(yj=0|θ)`).

**1.3 — "Parar no 100% é fracasso de evolução" é o abstract do HLE, assinado por 1.119 autores.**
Verbatim: *"benchmarks are not keeping pace in difficulty: LLMs now achieve over 90% accuracy on popular benchmarks like MMLU, limiting informed measurement of state-of-the-art LLM capabilities."* (arXiv:2501.14249). Você não está inventando método. Está redescobrindo a lei do campo — seção 2.

**1.4 — 🔴 A SUA TESE JÁ É LEI ESCRITA NESTE REPO, DATADA 02/07, E O FIO NUNCA FOI LIGADO.**

`config/atlas_rivals.php:104-106`, comentário verbatim que eu li agora:
> ```
> // Régua de dificuldade (decisão do operador 02/07): frontier deve pontuar
> // ~20-30% em braço bare. Acima disso a SUITE é acusada de fácil demais —
> // o report levanta difficulty_flags em vez de celebrar o número.
> ```

`DifficultyCalibrator.php:14-30` implementa as bandas (`>35% too_easy`). E:

```
$ grep -c "DifficultyCalibrator" app/Services/Ai/Rivals/Core/EnterpriseReportBuilder.php
0
```

**O relatório que você leu publica 13 linhas em 100% e nunca acusa uma única vez.** A régua existe, é sua, é honesta, e não roda no relatório que decide. **Você não precisa convencer ninguém. Precisa ligar um fio.**

---

### ⚠️ CORRIGIDO — cada um destes muda a obra

**2.1 — A dificuldade REVELA o M. Não CRIA o M.** *(a correção mais importante)*

Na cauda difícil, `P ≈ e^(θ−b)`, logo **M → e^Δθ, CONSTANTE em b**. Endurecer o teste não permite o Atlas chegar a 100% — puxa os dois braços pela mesma curva:

| Δθ do Atlas | M_max em QUALQUER teste, QUALQUER n |
|---|---|
| 1,5 logits (um wrapper forte) | **4,5× — 50× IMPOSSÍVEL** |
| 2,0 | 7,4× — IMPOSSÍVEL |
| 3,0 | 20,1× — IMPOSSÍVEL |
| **3,912 (=ln 50)** | **50× — o piso absoluto** |
| 7,824 | 2.500× (exibe 50× no item ótimo) |

**Traduzindo a sua barra ao invariante: M≥50× ⟺ Δθ ≥ 3,912 logits ⟺ o Atlas multiplica as CHANCES de sucesso por 50×.** Se Δθ_real for 1,5, você pode graduar até o surreal e nunca ver mais que 4,5×.

**2.2 — M é um DIAL. Mesmo Atlas, M de 2× a 2.500×, só escolhendo o pool.**

Com Δθ fixo em 7,824:

| p_bare | 50% | 30% | 22,2% | 10% | **1,96%** | 0,5% | 0,001% |
|---|---|---|---|---|---|---|---|
| **M** | 2,00× | 3,33× | 4,50× | 9,96× | **50,0×** | 185× | 2.439× |

**"Elevar até bare=2%" é literalmente escolher M=50.** A tese, executada, **fabrica o número declarado**. O invariante é Δθ (invariante ao pool sob Rasch); M é apresentação. → **Pare de reportar M. Reporte Δθ com IC e declare o pool.**

**2.3 — bare=0% é tão inútil quanto bare=100%.**

M = atlas/0 = **indefinido**, não infinito. Lei exata, verificada contra a `wilson()` do repo com erro ≤3e-3 em n∈{2…400}:

> ### **M_low(0/n vs n/n) = n / z²**

| n | 2 | 3 | 5 | **9** | 36 | 100 | **193** | 400 |
|---|---|---|---|---|---|---|---|---|
| **M provado** | 0,52× | 0,78× | 1,30× | **2,34×** | 9,37× | 26,0× | **50,2×** | 104× |

As 5 linhas que parecem "ganho infinito" provam: swe_bench_live **2,34×**, writingbench **2,34×**, swe_marathon **1,30×**, gpqa:Chemistry **0,78×**, senior_swe_bench **0,52×**. **Publicar "INF" a partir de 0/9 seria a fraude estatística mais fácil de cometer e a mais fácil de refutar de fora.**

**2.4 — A janela para PROVAR 50× é bare ≈ 0,15%, não 2% — 13× mais dura.**

Em bare=1,96%, M̂ = **exatamente 50,0** — e ninguém prova M≥50 com estimativa pontual de 50. Rodado contra a `wilson()` do repo, Atlas perfeito:

| bare alvo | n | **M_low provado** | passa 50×? |
|---|---|---|---|
| 2% | 200 | 19,5× | ❌ |
| 2% | **400** | **25,4×** | ❌ |
| **0,2%** | **400** | **70,6×** | ✅ |
| 0% | 400 | 104× | ✅ |

**`n≥193` é miragem** — é o n onde `n/z²` cruza 50 na observação *perfeita* (0/193 **e** 193/193 simultâneos, P≈0,04%). Preço real com poder 80-87%: **n≈340–400**.

**2.5 — "Até o cru não fazer nem 30%" precisa da segunda cláusula.**

O chão é **simétrico**: P(1−P) em P=0,02 vale 7,8% do máximo — quase o mesmo que P=0,98. **Item onde os dois braços falham é tão inútil quanto onde os dois passam.** O alvo não é "difícil": é a **banda entre os dois braços**.

**Corolário operacional sem estatística nenhuma:** o item está na janela **sse os braços DISCORDAM**. `D(u) = Pₐ+P_b−2PₐP_b`, maximizado exatamente em u = −Δ/2. Na janela do 50×, **96,16% dos itens têm "bare falha, Atlas passa"**. Alvo falsificável, zero modelo.

---

### ❌ REFUTADO — e é o que trava tudo

**3.1 — Não existe M para elevar.** 30/30 linhas: `atlas=null`, `atlas_n=0`, `verdict="atlas_nao_medido"`. `skills.with_atlas: 0`. `claim_allowed: false`. As 5 famílias de uplift: `atlas_runtime_proof_missing`.

**3.2 — O braço "com Atlas" era 3 linhas de prompt.** Li os dois scripts agora:

```php
// scripts/rivals-hermes-bare.php:22-30  — braço BARE
$argv = ['hermes','-z', trim(file_get_contents($promptFile)),
         '--provider','verboo','-m',$model,'--usage-file',$usagePath,'--yolo'];

// scripts/rivals-atlas-dev-bridge.php:79-92  — braço "ATLAS"
$atlasPrompt = "# Atlas Dev (rivals atlas_dev arm)\n"
    ."You are executing inside an Atlas-isolated worktree. Apply a production-quality\n"
    ."patch for the task below. Edit files in-place; do not ask clarifying questions.\n\n"
    .$prompt;
$hermesArgv = ['hermes','-z', $atlasPrompt, '--provider','verboo','-m',$model,
               '--usage-file',$usagePath,'--yolo'];
```

**Binário idêntico. Modelo idêntico. Flags idênticas. A única diferença era o preâmbulo.** E cruze com o scorer (`rivals-hermes-bare.php:50-52`): o bare é reprovado se `completed=false` → `'incomplete'`. O braço "Atlas" recebia **"do not ask clarifying questions"** — o antídoto exato para o modo de falha nº1 do one-shot. **E o regime é PIOR quanto mais difícil a tarefa, porque tarefa difícil é onde o modelo pede esclarecimento. A dificuldade não neutraliza esse artefato: ela o AMPLIFICA.**

**3.3 — 🔴 E o braço de controle está contaminado. Isso INVERTE o seu diagnóstico.**

`rivals-hermes-bare.php` roda `hermes -z ... --yolo` **sem** `--ignore-user-config`, **sem** `--ignore-rules`, **sem** `-t` restrito — flags que existem e estão documentadas. Do `--help` do hermes:

> `-z PROMPT, --oneshot PROMPT` — *"Tools, memory, rules, and AGENTS.md in the CWD are loaded as normal"*

Portanto a coluna que o relatório chama de **"modelo sozinho"** é: **kimi-k2.7 + laço agêntico + tool-calling + memória do Hermes + rules do operador + AGENTS.md do CWD + `--yolo`**. E nas suítes atlasbench o CWD é worktree do **repo do Atlas**, onde moram `CLAUDE.md` e `AGENTS.md`. **O braço de controle carrega o Operating Contract do Atlas.**

Você olhou 21/30 no teto e concluiu **"os testes são fáceis demais"**. Há uma segunda explicação igualmente consistente com os mesmos dados: **o "bare" já é Atlas-like, então tudo parece fácil. O numerador não está inflado — o denominador está.**

**E aqui está o Goodhart, dito sem rodeio.** Das duas reformas possíveis:

| | custo | efeito no controle vazado |
|---|---|---|
| **A — a sua tese:** elevar a dificuldade até bare<30% | meses de autoria de corpus | **preserva intacto** |
| **B:** `--ignore-user-config --ignore-rules` no bare | **uma linha** | **conserta** — derruba o bare de graça em todas as 13 linhas saturadas de uma vez |

A tese escolhe A. **A é o que um sistema otimizando o próprio M escolheria** — não porque você seja desonesto, mas porque A é indistinguível de desonesto a partir do output. **Se elevar a dificuldade antes de despoluir o controle, o Atlas vai aparecer multiplicando o Hermes. E o Hermes já é metade do Atlas.**

*Crédito onde é devido, uma linha:* este relatório **se recusou a publicar**. `claim_allowed: false`. O `atlas_arm_note` denuncia o próprio braço. As 30 linhas dizem `atlas_nao_medido` em vez de inventar delta. `bestRunForSuite` prefere o mais recente, não o melhor. Honestidade de instrumento acima da média do campo — e é por isso que os defeitos acima são reparáveis em vez de fatais.

---

## 2. O CAMPO INTEIRO FAZ ISSO — números e datas

| ano | instrumento | o que aconteceu |
|---|---|---|
| 2020 | **MMLU** | satura >90% até 2024 |
| nov/2023 | **GPQA** (arXiv:2311.12022) | PhDs do domínio **65%**; não-especialistas com web irrestrita e **>30 min: 34%**; GPT-4 da época 39%. Dificuldade definida pelo **GAP**, não pela dureza absoluta |
| jun/2024 | **MMLU-Pro** (arXiv:2406.01574) | 4→**10** alternativas. −16 a −33 pp. **E a dificuldade BARATEOU o ruído**: sensibilidade a prompt 4-5% → **2%**; piso de chute 25% → **10%**; CoT passou a superar resposta direta |
| ago/2024 | **SWE-bench Verified** | 93 devs triaram 1.699 amostras; **68,3% REMOVIDO** por testes injustos; GPT-4o **16% → 33,2%**. **Escalou para BAIXO** |
| out/2024 | **GSM-Symbolic / NoOp** (arXiv:2410.05229, ICLR25) | trocar só os **valores** derruba ~8pp; **uma cláusula irrelevante (NoOp) derruba até 65% em TODOS os SOTA** |
| nov/2024 | **FrontierMath** (arXiv:2411.04872) | frontier resolve **"under 2%"**. Inédito + não publicado + verificação automatizada. Cada problema: *"multiple hours... for the upper end questions, multiple days"* |
| jan/2025 | **HLE** (arXiv:2501.14249) | 2.500 questões, 1.119 autores. Lançamento: GPT-4o **2,7%**, o1 **8,0%** |
| fev/2025 | **Platinum Benchmarks** (arXiv:2502.03461) | ~5% do GSM8K com rótulo errado; *"more than half of model failures can be attributed to label noise"* |
| mar/2025 | **ARC-AGI-2** | o3-preview 75,7% na v1 → **4%** na v2; o3-mini-high **0,0%**; gpt-4.5 **0,0%**; painel humano **100%** |
| set/2025 | **SWE-bench Pro** (arXiv:2509.16941) | GPT-5 **23,3%** (vs >70% na Verified). Repos GPL = barreira jurídica anti-contaminação |
| 2026 | **ARC-AGI-3** | formato interativo. IA **<1%** (Gemini 3.1 Pro 0,37%; Grok-4.20 0,00%); humanos 100% |

**A prova de existência do seu denominador:** FrontierMath já construiu o teste onde o cru faz **~2%**. O ~2% que M≥50× exige **é construível** — e o método foi problema inédito + autoria de especialista + verificação automatizada + anti-contaminação. Não foi "pegar tarefa difícil".

**Mas a meia-vida é ~9-18 meses.** ARC-AGI-2: 0% → **85%** (GPT-5.5) em ~15 meses. SWE-bench Pro: 23% → **69,2%** (Opus 4.8) em ~9 meses. HLE: 2,7-8% → **38,3%** (Gemini 3 Pro). **Um teste difícil não é ativo permanente — é consumível. A entrega tem que ser a MÁQUINA de re-graduar, nunca um dataset fixo.**

**E o contraponto que mais ameaça a leitura ingênua do nosso relatório: SWE-bench Verified.** Score baixo parecia dificuldade real e era **harness quebrado em 2 de cada 3 casos**; consertar **dobrou** o GPT-4o. **As 5 linhas em bare=0% do nosso pacote têm a mesma assinatura clínica.** E não é hipótese — **já aconteceu neste repo há um dia**: a memória `rivals-report-trust-hardening-14-07` registra **9 bugs "0% = incapaz" com o modelo NUNCA consultado** (gsm8k 0%→100% ao trocar o provider; mmlu 0%→80% ao remover o cap de 16 tokens; niah 10/10 → "falhou"). **Antes de chamar os 0% de precondição do 50×, é obrigatório provar que não são a Verified esperando para acontecer.**

---

## 3. A ARITMÉTICA DO 50× — o número que decide tudo

Rodado agora contra `StatisticalPolicy::wilson`, **assumindo um Atlas PERFEITO (n/n)** em cada linha:

| corte | linhas de 30 |
|---|---|
| provam **M ≥ 50×** | **0** |
| provam **M ≥ 10×** | **0** |
| provam **M ≥ 2×** | **2** — swe_bench_live e writingbench, ambos 0/9, M_low = **2,34×** |
| provam **qualquer ganho** (M_low > 1,0×) | **6** — + swe_marathon 1,30×, terminal_bench 1,28×, wmdp_cyber 1,28×, live_code_bench 1,09× |
| **não provam NADA** (M_low < 1,0×) | **24** |
| **PROVAM bare ≤ 2%** (a precondição) | **0** |

> ### **Maior M provável do arsenal inteiro: 2,34×. Barra declarada: 50×. Fator de 21× de distância — e isso ANTES do Atlas ter rodado uma única vez.**

**As 5 linhas em bare=0% não provam bare baixo.** `wilson_high(0,n) = z²/(n+z²)`:

| linha | n | **prova apenas** |
|---|---|---|
| swe_bench_live, writingbench | 9 | bare ≤ **29,9%** |
| swe_marathon | 5 | bare ≤ **43,4%** |
| gpqa_diamond:Chemistry | 3 | bare ≤ **56,2%** |
| senior_swe_bench | 2 | bare ≤ **65,8%** |

**O orçamento:** o pacote inteiro soma **208 recibos** (`sum(bare_n)`). Provar 50× em **UMA** habilidade custa **n ≥ 189** só de ingresso e **n ≈ 340–400** com poder de 80-87%. **O pacote inteiro, somado, é do tamanho de meia prova de 50×.** Cobrir 30 habilidades no padrão 50×: ~12.000 tarefas/braço, ~24.000 nos dois — **58× o pacote atual.**

**E o `n=9` é mentira estatística.** Os 219 recibos dos 10 `included_run_ids` são **73 casos × 3 repetições**. `SkillMatrix.php:95` empilha as 3 reps como 3 casos Bernoulli independentes:
```php
$tally[$key][$arm][] = ($receipt->data['status'] ?? null) === 'success' ? 1.0 : 0.0;
```
E a hipótese iid é violada nos próprios dados: se iid, pass^3 esperado = 0,644³ = 26,7%; **observado = 56,2%**. **O n efetivo é 3, não 9 → o CI de Newcombe é ANTI-CONSERVADOR → a regra dos 44 pp que você citou está OTIMISTA.**

---

## 4. A DESCOBERTA MAIS PROFUNDA — o instrumento não toca o objeto

Esta é a parte que muda a categoria do problema, e ela tem **duas camadas**.

### Camada 1 — múltipla escolha tem teto de M PERMANENTE, imune a dificuldade

No modelo de 3 parâmetros, `P(θ) = c + (1−c)·σ(a(θ−b))`. Com b→∞ (item infinitamente difícil), **P → c**. Logo **E[bare] ≥ c sempre**, e:

> ### **M_max = 1/c, ESTRUTURALMENTE. Nenhuma graduação quebra isso.**

| formato | c | **teto de M — PARA SEMPRE** | linhas |
|---|---|---|---|
| binário (winogrande: `option1`/`option2`) | 0,50 | **2×** | 1 |
| 4 alternativas (mmlu, arc, gpqa, wmdp, hellaswag, sec_qa, musr, truthfulqa, bbq) | 0,25 | **4×** | ~13 |

Fontes: `huggingface.co/datasets/allenai/winogrande` (campos `option1`/`option2`, "binary options"); GPQA paper arXiv:2311.12022 + `epoch.ai/benchmarks/gpqa-diamond` ("random-chance baseline on Diamond is 25%").

**Isto corrige a sua tese num ponto que a intuição não podia ver: "elevar o nível até o modelo não fazer 30%" NÃO FUNCIONA em múltipla escolha.** Num MCQ de 4 alternativas o modelo cru nunca cai abaixo de ~25% por sorte. **~14 das 30 linhas jamais provarão 50×, por mais surreal que fique o item.** Bônus: a informação máxima cai de 0,25a² (c=0) para 0,155a² (c=0,25) — um MCQ, no melhor caso possível, carrega **62%** da informação de um item de resposta aberta. E o pico de informação num MCQ de 4 alternativas fica em **P\*=(1+√(1+8c))/4 = 68,3%**, não 50% — se calibrar mirando 50%, calibra errado.

### Camada 2 — e esta é a que importa: **zero órgãos do Atlas têm superfície de acoplamento num item de MMLU**

Um item de MMLU é: ler um enunciado + 4 alternativas, emitir uma letra. Turno único. Sem estado. Sem repo. Sem teste. Sem artefato. Sem filesystem. Sem plano a decompor.

| órgão do Atlas | superfície num item de MMLU |
|---|---|
| Memory recall | recall de **quê**? não há decisão prévia sobre `college_physics` |
| Context Pack / RAG | pack de **qual workspace**? não há workspace |
| Evidence Ledger | prova de **qual evento runtime**? não há runtime |
| Gates G0-G8 | portão sobre **qual escrita**? não há escrita |
| Scoped committer | commit de **quê**? não há diff |
| **Verificação** (o produto declarado em `atlas-terminal-first-focus.md`) | verificar **qual artefato**? a resposta é uma letra |

> ### **Portanto: mesmo um "MMLU-Impossível", onde o kimi faz 2%, mediria M ≈ 1× — porque o Atlas também faria ~2%. Não por falta de capacidade: por falta de SUPERFÍCIE.**

**As 13 linhas em 100% não estão "saturadas" — estão off-target desde o dia 1.** Saturação implica que já foram informativas e deixaram de ser. Estas nunca foram: o eixo delas não cruza o eixo do Atlas. **Nenhuma quantidade de dificuldade as põe no alvo.**

**A prova barata dessa tese é o eixo de eficiência: nessas linhas o Atlas não agrega 0%. Agrega NEGATIVO.**
Imposto medido de Atlas: ~65s de hooks/turno + 18,7s/pack, duplicado ≈ **83,7s** (`docs/engineering-knowledge-base/atlas-acos-max-frontier-plan-v1.md:48`, `:322`, `:802`) contra as medianas de `wall_ms` dos recibos:

| linha | wall bare | **M_wall com o imposto** |
|---|---|---|
| sec_qa_v1 | 2,0s | **0,023× — 43× mais lento** |
| gsm8k | 2,9s | **0,033× — 30× mais lento** |
| truthfulqa | 3,1s | 0,036× — 28× mais lento |
| coconot | 7,9s | 0,086× — 12× mais lento |

*(Ressalva honesta: 65s é o hook do Claude Code, não o braço `atlas_dev` do rivals — que nunca rodou. É estimativa de ordem de grandeza, e é a única que existe.)*

### O corolário orçamentário

**62% do orçamento de medição está num instrumento que não pode engajar um único órgão do Atlas, por construção:** **171 de 275** cases em `storage/atlas/rivals/external/inspect_evals/cases/` são `inspect_evals/mmlu_0_shot`. Não é uma linha fraca do pacote — **é 62% do pacote apontado para fora do alvo.**

### E isso reordena as 30 linhas em 3 baldes — não em "teto/sem teto"

A classificação atual usa **um** critério (headroom) e o mede mal (estimativa pontual, n=9). Os critérios reais são **três, independentes**: (1) `c≈0` (formato), (2) órgãos acoplam (estado + artefato + verificação + horizonte), (3) headroom.

| balde | critério | linhas | veredito |
|---|---|---|---|
| **A — TETO DE FORMATO** | c > 0 (múltipla escolha) | **14**: winogrande, arc_challenge, mmlu×3, gpqa×2, hellaswag, wmdp_bio, wmdp_cyber, sec_qa_v1, musr, truthfulqa, bbq:Age | **M ≤ 4× para sempre.** Dificuldade nenhuma quebra. Nunca voltam ao eixo-M |
| **B — SEM ÓRGÃO** | c≈0, mas turno único, sem estado/artefato | **7**: gsm8k, mgsm:bn, coconot, niah, ifeval×2, bfcl† | O Atlas não tem mecanismo. Off-target, não saturado |
| **C — O EIXO DO M** | c≈0 **E** órgãos acoplam | **9**: aider_polyglot, hal_harness, live_code_bench, senior_swe_bench, swe_bench_live, swe_marathon, tau2_bench, terminal_bench, writingbench | **É aqui que 50× é DEFINÍVEL.** Precisam de n e de escala de razão |

*(† bfcl = marginal: tem artefato verificável, mas sem repo nem horizonte.)*

> ### 🔴 **A régua de hoje erra nos DOIS sentidos.**
> As "9 sem teto" ∩ Balde C = **6**. As outras 3 "sem teto" — **gpqa:Chemistry, wmdp_cyber, ifeval:punctuation** — são **armadilhas**: headroom sem superfície, teto permanente de 4×. Perseguir gpqa:Chemistry (0/3, parece "frontier") é puro desperdício.
> E 3 do Balde C — **aider_polyglot, hal_harness, tau2_bench** — foram declaradas "com teto" e escritas como perdidas. **São justamente onde os órgãos acoplam.**
> **A régua manda perseguir 3 becos sem saída e desistir de 3 dos 9 lugares onde o M existe.**

---

## 5. AS MÉTRICAS SEM TETO

**O teto é da MÉTRICA, não do mundo.** `SkillMatrix.php:128-131` computa `gain_undemonstrable` sobre **diferença de proporções** — escala limitada em [−1,+1]. E:

```
$ grep -ciE "ratio|multiplic" app/Services/Ai/Rivals/Core/SkillMatrix.php
0
```

**O relatório é mudo sobre o M por construção, não por omissão.** A métrica com teto responde *"é impossível provar"*. A métrica de razão responde *"custa n"* — e isso vira fila de orçamento, não desistência.

| escala | fórmula | temos? | custo | o que 50× significa lá |
|---|---|---|---|---|
| **Δθ (log-odds-ratio)** ⭐ | `ln(OR_MH)` | **SIM** — só faltam execuções | **51 exec** | Δθ ≥ 3,912 (piso) / 7,824 (exibível no item ótimo) |
| **pass^k / flaky** | groupby em `repetition` | **SIM, e joga fora** | **0 execução** | k que sobrevive ao limiar |
| **MTBF = 1/(1−p)** | transformada da taxa que já medimos | **SIM** | n | terminal_bench: p_atlas=98,44%, n≈193 |
| **tokens/tarefa-resolvida** | já publicado, **no lugar errado** | **SIM** | 0 execução | razão de eficiência |
| **horizonte METR** | duração @50% de sucesso | **NÃO** — falta tempo humano/caso | anotação | "cru 8 min, Atlas 400 min" |
| **saldo em $ (Vending-Bench 2)** | métrica única, sem teto, com piso negativo | NÃO | obra | **11,5× disponível** ($5.478 vs ~$63K humano) |

**MTBF destrói a leitura de que 21/30 estão perdidas.** `tau2_bench` bare=0,8889 → MTBF=9,0 tarefas. Na taxa o M máximo é **1,12×**. Em MTBF, **50× é enunciável** e custa p_atlas=99,778%, n≈1350. **E a ordem de prioridade INVERTE:** terminal_bench (hoje "sem teto") custa **n≈193**; tau2_bench (hoje "com teto") custa **n≈1350**.

**🔴 O Atlas já tem k=3 e joga fora.** Os 219 recibos dos 10 runs incluídos carregam `repetition` 1/2/3. Desagrupando por caso (73 casos distintos):
- **pass@1 = 64,4% · pass^3 = 56,2% · pass@3 = 69,9%**
- **13,7% dos casos (10/73) são FLAKY**: `django__django-11815 [1,0,0]`, `polyglot_003 [0,1,0]`, `tb_hello [1,1,0]`, `airline_task_014 [1,1,0]`…

> **Os 13,7% flaky são a SUPERFÍCIE ENDEREÇÁVEL do wrapper: casos onde o modelo PROVA que sabe e mesmo assim erra. É literalmente o M de verificação. Nenhum ganho de inteligência do provider fecha isso; só governança/verificação fecha.** Custo de computar: um `groupby`. Zero execução, zero provider spend.

**⚠️ ARMADILHA — pass^k CONTINUA sendo 0..100%. Tem teto igual.** Não é a métrica de razão; é o **insumo** dela. O que não tem teto é o **k que sobrevive a um limiar** ("cru aguenta k=2, Atlas aguenta k=100" = 50×), a MTBF derivada, o horizonte em minutos, e o saldo em dólares. **Publicar pass^3 como métrica-fim é trocar um teto por outro.**
Referência: τ-bench (arXiv:2406.12045) define `pass^k`; GPT-4o faz **61% pass@1 e 25% pass^8** em retail.

**O horizonte METR SERVE — mas é o 4º passo, não o 1º.** METR (arXiv:2503.14499) abandonou a taxa exatamente pelo seu motivo (*"individual benchmarks saturate increasingly quickly"*) e mede a **duração da tarefa completada com 50% de sucesso**. Dobra a cada **~7 meses** (2019-2025), **~4 meses** em 2024-25; Claude 3.7 Sonnet ≈ 50 min. É razão pura, sem teto. O modelo de meia-vida (arXiv:2505.05115) dá a **ponte**: taxa de falha constante por minuto → *"each agent could be characterised by its own half-life"*. **É o mecanismo exato do M: o wrapper não precisa acertar mais uma questão — precisa baixar o hazard, e isso compõe exponencialmente ao longo da tarefa.**
**Bloqueador:** exige anotar **tempo humano por caso**, e não existe em nenhum recibo. Por isso é o 4º.

**O eixo de eficiência está no relatório, decapitado.** `EnterpriseReportBuilder.php:512-514, 634-636` já publica `median_wall_ms` e `tokens_per_task` — **em `model_capabilities`, FORA de `skills.rows`**. `wall_ms` presente em 216/219; `tokens_out` em 201/219. `cost_usd` = 0 em 219/219, com o motivo registrado honestamente (`verboo_subscription_marginal` — assinatura tem marginal zero). **O eixo honesto é TOKEN, não dólar; insistir em dólar seria fabricar número.**
Precedente do campo: ARC-AGI-2 pôs **eficiência no score** — painel humano **$17/tarefa**, o3-preview-low **$200/tarefa**, ARChitects **$0,25/tarefa** — *"Intelligence is not solely defined by the ability to solve problems or achieve high scores."*

> ### **A RECOMENDAÇÃO PÉTREA: PARE DE REPORTAR M. REPORTE Δθ.**
> **"M≥50×" não é uma barra — são três**, dependendo de onde se mede: 50× na taxa no item ótimo → **Δθ ≥ 7,824**; 50× na taxa no supremo → **Δθ ≥ 3,912**; 50× em MTBF sobre tau2_bench → **Δθ ≥ 4,03**. Isso é o dial da seção 2.2.
> **Declare a barra no invariante: Δθ ≥ 3,912 logits ⟺ odds-ratio de 50×, invariante ao pool.** É melhor que "M≥50×" porque **não pode ser gameada pela escolha do pool** — e torna a tese **falsificável por 51 execuções.**

---

## 6. A ESCADA DE GRADUAÇÃO — 2 leis, 5 degraus, 7 travas

### As duas leis (verificadas contra a `wilson()` do repo)

> **Lei 1 — INGRESSO: `n ≥ z²·(1−t)/t`** — n mínimo para **PROVAR** que o bare está abaixo do teto t.

| teto t | 30% | 20% | 5% | **2%** | **1%** | 0,1% |
|---|---|---|---|---|---|---|
| **n de ingresso** | **9** | 16 | 73 | **189** | **381** | 3.840 |

**Um degrau não pode ser DECLARADO sem o n de ingresso.** G4 (bare<2%) não existe com n=9: 0/9 prova apenas bare<29,9%, que é banda de G1. **O n não é opcional — é o bilhete de entrada.**

> **Lei 2 — DENOMINADOR: `M_low(0/n vs n/n) = n/z²`** — o teto absoluto de prova de qualquer degrau. Nem Atlas perfeito contra cru zerado passa disso.

### Os cinco degraus

| # | nome | banda bare | n ingresso | n operacional | **M_low provável** | instrumento |
|---|---|---|---|---|---|---|
| **G0** | **Canário** | ≥95% | — | 9 | — (eixo de **DANO**) | as 13 saturadas + Balde B |
| **G1** | Calibração | <30% | 9 | 36 | 2,37× | **terminal_bench (22,2%)**, live_code_bench (33,3%) |
| **G2** | Elite | <20% | 16 | 100 | 5,52× | swe_bench_live triado, aider_polyglot endurecido |
| **G3** | Fronteira | <5% | 73 | 200 | 19,5× | **frontier_cs_research**, livecodebench_pro tier Hard-adjacent |
| **G4** | **Surreal** | **<1%** | **381** | **400** | **70,6×** ✅ | AtlasBench autoral, scoring contínuo, horizonte longo |

**Só G4 passa de 50×.** G3 a n=200 prova 19,5×; a n=400 com bare=2% prova **25,4×**. **Não existe atalho: o 50× custa n≈400 e bare≈0,2%.**

### A regra de promoção (a que você pediu, corrigida por n)

> Um degrau **se aposenta do eixo-M** quando `wilson_low(k_bare, n) > teto(G_k)` — o **PISO do IC** ultrapassa a banda, não a estimativa pontual.
> Um degrau **abre** quando `wilson_high(k_bare, n) < teto(G_{k+1})`, com o n de ingresso da Lei 1.
> Enquanto o IC cruzar a fronteira: veredito **`unknown`**, o degrau continua.
> **Aposentar do eixo-M ≠ deletar. O degrau aposentado migra para G0** (canário): n=9, gate binário, custo total ~7 min.

**Por que `unknown` é obrigatório:** hoje `DifficultyCalibrator::bandFor(float $rate)` (linha 14) **não tem `$n`**. Com p=0,30 e n=9, o **mesmo instrumento** cai em: `too_easy` 27% · `elite_valid` 27% · `borderline` 27% · `hard` 16% · `frontier` 4%. **A decisão de aposentar um teste é hoje um dado de 5 faces.** E a prova viva está no seu relatório: `gpqa_diamond:Physics` (3/3) tem Wilson95 **[43,9%; 100%]** e `gpqa_diamond:Chemistry` (0/3) **[0%; 56,2%]** — **os intervalos se sobrepõem em 12 pontos.** Mesmo instrumento, mesmo modelo, polos opostos por ruído. A régua declararia um `too_easy` e o outro `frontier`.
**Fix: uma assinatura.** `bandFor(int $k, int $n): string` → `unknown` quando o Wilson cruza duas bandas.

### Quem escolhe a dificuldade — a trava mais importante

**Hoje: o Atlas.** `config/atlas_rivals.php:417`:
```php
'repo_path' => env('ATLAS_RIVALS2_ATLASBENCH_REPO', base_path()),
```
`base_path()` = o próprio repo. `AtlasBenchSuiteAdapter::mineCases()` roda `git log` no repo do Atlas, cada commit vira case, **e o grader é o teste que o próprio commit introduziu**. 4.841 desses commits foram escritos pelo sistema autônomo do Atlas. **O Atlas é avaliado reproduzindo commits que o Atlas escreveu, com o teste que o Atlas escreveu como juiz.**
**Fix:** `repo_path` **sem default** — se `ATLAS_RIVALS2_ATLASBENCH_REPO` não estiver setado, a suíte **recusa rodar** (fail-closed). Item vem de fora.

### As 7 travas anti-Goodhart — cada uma com arquivo:linha

| # | grau de liberdade | onde | fix | custo |
|---|---|---|---|---|
| 1 | **Atlas escolhe o item** | `config/atlas_rivals.php:417` (`base_path()`) | fail-closed + corpus externo | 1 linha |
| 2 | **Atlas escreve o grader** | `AtlasBenchSuiteAdapter::mineCases()` | grader do upstream | obra |
| 3 | **bare contaminado** | `rivals-hermes-bare.php:22-30` | `--ignore-user-config --ignore-rules -t <declarado>` | **1 linha** |
| 4 | **preâmbulo assimétrico** | `rivals-atlas-dev-bridge.php:79-82` | preâmbulo do harness, idêntico nos 2 braços | 1 linha |
| 5 | **re-roll grátis** | 364 runs no disco, 10 no relatório, `excluded_run_ids: []`; `bestRunForSuite` = "o mais recente vence" (`EnterpriseReportBuilder.php:457-487`) | `run_id` declarado ANTES; `excluded_run_ids` soma 364 | pré-registro |
| 6 | **contaminação por retrieval** | `ContaminationGuard.php:14-70` — 3 regex no **PROMPT**, zero no retrieval | check de retrieval | obra |
| 7 | **a régua se auto-anula** | **âncoras: `grep -rlnE "anchor_item\|vertical_scal\|equating\|linking_item" app/ config/` → ZERO** | vertical equating | ver abaixo |

**Sobre a trava 5, o número que dói:** com n=9 e p=0,667, `P(observar ≤2/9) = 0,83%`. Em **364 draws: 95%**. **A banda `elite_valid` que a sua tese pede é alcançável por reamostragem pura, sem tocar em dificuldade nenhuma.** Existe `Preregistration` (alpha=0,05, power=0,90, Holm, ITT — trabalho sério), mas ela é mintada **por run** (`RunPlan.php:105-110`) e a seleção do que entra no relatório é **por relatório**. Cada re-roll ganha seu próprio pré-registro válido. **Pré-registro que não vincula a decisão de reportagem não previne nada.** *(Crédito: `bestRunForSuite` ordenar por recência e não por score é uma regra honesta — não é cherry-pick. Mas "o último vence" é superfície de re-roll gratuita.)*

**Sobre a trava 6, o ponto cego estrutural:** `ContaminationGuard` audita 3 regex anti-receita no **texto autoral do prompt**. **Nenhuma ataca a superfície de recuperação. E o M do Atlas *É* a superfície de recuperação** (memória, Context Pack, RAG sobre o repo). **O guarda higieniza o canal que o braço bare usa e ignora o canal onde vive a vantagem do braço Atlas.**

**Sobre a trava 7 — a que a própria escada cria, e a mais insidiosa:**
Uma escada que endurece toda vez que satura **nunca falsifica nada** — sempre dá para dizer "estamos em 30% no degrau novo, estamos progredindo". **Goodhart de luxo: não gamear o número, gamear a régua.** O único sinal que sobrevive é a **dificuldade do degrau**, e isso exige **âncoras** (itens fixos ligando as escalas).
**E há um conflito não-arbitrado no código:** `config/atlas_rivals.php:120-121` expira **todo case em 30 dias** (`max_case_age_days`). **Por construção nenhum item sobrevive para ligar G_k a G_{k+1}. Anti-contaminação e vertical equating estão em conflito direto, e ninguém decidiu.**
**Resolução, e não precisa de sistema novo:** âncoras são usadas **só para Δθ** (Atlas-vs-bare no MESMO run), nunca para claim absoluto de degrau. **Contaminação enviesa p, mas sob Rasch desloca os DOIS θ pelo mesmo tanto — e Δθ é invariante. Cancela na razão de chances.** Logo âncoras são isentas do prazo de 30 dias sem abrir buraco. Duas listas, duas regras.

### O teste de fogo, em uma frase

> Se o Atlas escolhe a dificuldade, escreve o corpus a partir dos próprios commits, escreve o grader, escreve o preâmbulo que só o próprio braço recebe, escolhe qual dos 364 runs entra no relatório, e ainda assim mede 50× — **isso não é um multiplicador, é um espelho.**
> **Cada um desses seis graus de liberdade existe hoje, em código, com linha e arquivo. Feche os seis e o 50× que sobrar é real. Feche zero e eleve a dificuldade, e o 50× aparece — e não vai significar nada.**

---

## 7. O QUE FAZER COM AS 30 HABILIDADES DE HOJE

| habilidade | bare | n | M_max | **M_low** | balde | **veredito** |
|---|---|---|---|---|---|---|
| **swe_bench_live**:repair_regression | 0% | 9 | INDEF | **2,34×** | **C** | **MANTER — eixo do M. Triar à la Verified ANTES** (68,3% do SWE-bench original era harness quebrado) |
| **writingbench**:Academic&Eng | 0% | 9 | INDEF | **2,34×** | **C** | **MANTER — eixo do M.** Triar |
| **swe_marathon**:long_horizon | 0% | 5 | INDEF | 1,30× | **C** | **MANTER — horizonte longo = o mecanismo do Atlas.** Triar |
| **senior_swe_bench**:bug_investig | 0% | 2 | INDEF | 0,52× | **C** | **MANTER — mas n=2 prova bare≤65,8%.** Sem n, é decorativa |
| **terminal_bench**:terminal_agent | 22,2% | 9 | **4,50×** | 1,28× | **C** | **⭐ MANTER E DAR n → 36.** O instrumento **mais bem calibrado** do pacote. **NÃO migrar para TB 2.x** — a SOTA faz 91,9% no TB2, mas nossa fronteira é o kimi, e subir a versão empurra 22%→~0% e destrói a mensurabilidade |
| **live_code_bench**:coding_patch | 33,3% | 9 | 3,00× | 1,09× | **C** | **MANTER — borderline.** Dar n |
| **aider_polyglot**:coding_patch | 66,7% | 9 | 1,50× | 0,80× | **C** | **🔴 FALSO NEGATIVO — o relatório mandou desistir e é onde os órgãos acoplam.** Não aposentar: dar n + escala de razão. **⚠️ Os dois braços não rodam o mesmo harness** (bare = upstream `--tries 2`; atlas = `rivals-aider-polyglot-unit.php` sem loop) — o par é **incomparável**, e a assimetria está *contra* o Atlas |
| **hal_harness**:long_horizon | 66,7% | 9 | 1,50× | 0,80× | **C** | **🔴 FALSO NEGATIVO.** Idem |
| **tau2_bench**:tool_use | 88,9% | 9 | 1,12× | 0,72× | **C** | **🔴 FALSO NEGATIVO.** Na taxa, M_max=1,12×. **Em MTBF (9,0 tarefas), 50× é enunciável** — custa p_atlas=99,778% e n≈1350 |
| **gpqa_diamond**:Chemistry | 0% | 3 | INDEF | 0,78× | **A** | **⚠️ ARMADILHA — headroom sem superfície.** MCQ 4-alt: **teto 4× permanente**. 0/3 prova apenas bare≤56,2%. **Perseguir é desperdício puro** |
| **wmdp_cyber** | 22,2% | 9 | 4,50× | 1,28× | **A** | **⚠️ ARMADILHA.** MCQ: teto **4×** para sempre. Não é eixo de capacidade |
| **ifeval**:punctuation | 50% | 6 | 2,00× | 0,75× | **B** | **G0 canário.** Formato, turno único, zero órgão |
| **winogrande** | 100% | 6 | 1,00× | 0,61× | **A** | **G0 — binário: teto 2× PARA SEMPRE** |
| arc_challenge · mmlu×3 · gpqa:Physics · hellaswag · wmdp_bio · sec_qa_v1 · musr · truthfulqa · bbq:Age | 66,7–100% | 3–9 | 1,00–1,50× | 0,44–0,80× | **A** | **G0 canário — teto 4× permanente.** MCQ nunca volta ao eixo-M |
| gsm8k · mgsm:bn · coconot · niah · ifeval:detectable · bfcl | 83,3–100% | 3–9 | 1,00–1,20× | 0,44–0,70× | **B** | **G0 canário.** c≈0 mas **zero órgão do Atlas** — off-target, não saturado |

### 🐤 Por que NÃO deletar as 13 saturadas — e você já intuiu isso

**Sob H0: p=1, qualquer falha tem p-valor exatamente 0.** n=1 basta para rejeitar. É o único estimando onde **uma** observação decide. Poder de canário com os mesmos 9 recibos:

| se o Atlas derrubar | **canário dispara** | Newcombe declara? |
|---|---|---|
| 1,00 → 0,90 | **61,3%** | não (10pp < 44pp) |
| 1,00 → 0,80 | **86,6%** | não |
| 1,00 → 0,70 | **96,0%** | não |

**Fisher I=0 em P=1 as torna inúteis para CONFIRMAR ganho e ÓTIMAS para tropeçar em DANO** — 96% de sensibilidade a uma regressão de 30pp que o teste de delta chama de inconclusiva. Estimandos diferentes, poder oposto. **Ninguém deleta os testes unitários verdes porque estão verdes.**

**Custo de manter: ~7 minutos** (rodam em 2,0-7,9s) das ~9,57h de wall medidas — **1,2% do orçamento**. Cortá-las apaga 100% do sinal de dano.

**E o Goodhart da própria faxina:** deletar as linhas de M=1 **sobe a média de M reportada sem nenhuma mudança de capacidade** — exatamente o que a lei pétrea do repo proíbe (`CLAUDE.md`: *"Refactor que preserva comportamento = melhoria ZERO"*, memória `loop-not-proxy-cleanup-feedback`). E as linhas propostas para o corte são, convenientemente, **exatamente aquelas onde o Atlas perde 12-43×**.

**Duas listas, dois orçamentos, duas perguntas:**

| lista | pergunta | n | quando roda |
|---|---|---|---|
| **G0 Canário** (21 linhas: baldes A+B) | **"quebrou?"** | 9 basta — o teto mata o ruído | todo run, ~7 min, gate binário |
| **Eixo-M** (9 linhas: balde C) | **"quanto multiplica?"** | ≥200 | campanha |

**Aposentar do eixo-M ≠ aposentar do pacote.** A régua atual (`config:106-118`) só tem um veredito — `too_easy` — e ele significa "descarte". **Falta o veredito "promova a canário".**

### 📦 Substituir por quê — o instrumental já está no disco e 100% ocioso

`tools/rivals/benchmarks/inspect_evals/src/inspect_evals/_registry.py` tem **138 evals** baixados (MIT, UK AI Security Institute): **hle, cybench, swe_lancer, paperbench, mle_bench, osworld, livecodebench_pro, frontier_cs, gdpval, theagentcompany, mlrc_bench, kernelbench, scicode, zerobench, aime2026, cve_bench, cybergym**. **Nenhum tem UM case sequer.** A resposta para "quais benchmarks de fronteira são adotáveis hoje" é: **os que já estão no disco há meses.** Não é decisão de aquisição — é decisão de ligação.

E adicionar fronteira é **arquivo JSON, não código**: `InspectEvalsAdapter.php:132` monta o comando genérico `inspect eval {task_ref} ...`; qualquer um dos 138 é alcançável **trocando a string do `task_ref`**.

| substituto | por quê | estado |
|---|---|---|
| **⭐ frontier_cs_algorithmic** | **PONTUAÇÃO PARCIAL CONTÍNUA** — o bare não colapsa em 0/1, **a banda 2-10% sai por CONSTRUÇÃO, não por sorte.** 238 problemas (172 algorítmicos C++ NP-hard + 66 de pesquisa). README instalado: *"Current frontier models score well below human expert baselines, making this a challenging, unsaturated benchmark"* | **instalado, 0 cases** |
| **livecodebench_pro** | **graduação embutida** por Elo do Codeforces: o4-mini-high faz **53% no Medium e 0% no Hard** (Elo>3000) — todos os modelos em 0% no Hard. Prova que a escada existe e que **os dois extremos publicados são inúteis**; o valor é o degrau do meio | **instalado, 0 cases** (arXiv:2506.11928) |
| **ARC-AGI-3** | **o único público que entrega a banda hoje**: Gemini 3.1 Pro 0,37%, GPT-5.4 High 0,26%, Grok-4.20 0,00%, GPT-5.6 Sol 7,8%; humanos 100%. **7,8% com Atlas em 100% → M≈12,8×** | **NÃO instalado — único gap real de aquisição.** É raciocínio agêntico, não SWE |
| **Vending-Bench 2** | **métrica única sem teto: saldo em $** ao longo de um ano simulado. Gemini 3 Pro **$5.478** vs humano bom ~**$63K** → **11,5× de ganho DISPONÍVEL** (contra 1,5× que a taxa oferece). Piso negativo (falência) **elimina a saturação por construção**. Mecanismo = coerência de longo prazo = exatamente o que o Atlas alega ter | **modelo canônico do G4** |
| **GSM-NoOp** | **a receita mais barata de dificuldade que existe**: inserir uma cláusula irrelevante derruba **até 65pp em TODOS os SOTA**. Zero custo de autoria | arXiv:2410.05229 |

**🔴 BLOQUEADOR de uma linha:** `import inspect_ai` **falha nesta máquina**. Não há `.venv` em `tools/rivals/benchmarks` (`find -maxdepth 2` = vazio), `which inspect` → not found. Mas `~/Library/Caches/inspect_ai` e `~/Library/Application Support/inspect_ai` **EXISTEM** — prova de que já rodou e o ambiente foi perdido. **Padrão sistêmico já registrado na sua memória: construído-mas-não-ligado.** Sem restaurar o env, adicionar case de fronteira produz **falha de infra que o relatório vai etiquetar como "modelo falhou"** — exatamente o bug de 9 casos que você documentou em 14/07.

---

## 8. A ORDEM DE EXECUÇÃO

### FASE 0 — **grátis: zero execução, zero provider spend, e muda o número**

| # | ação | onde | custo | destrava |
|---|---|---|---|---|
| 1 | **Despoluir o bare** | `rivals-hermes-bare.php:22-30` → `--ignore-user-config --ignore-rules -t <declarado>` | **1 linha** | **Mede quanto do "teto" era o controle vazado.** Pode derrubar o bare nas 13 saturadas de graça. **É a única reforma que a tese NÃO faz** |
| 2 | **Preâmbulo simétrico** | texto de sistema do harness, idêntico nos 2 braços | 1 linha | Se o Atlas quer crédito pelo preâmbulo, **o M do Atlas são 3 linhas de texto** — resultado publicável e devastador |
| 3 | **Desagrupar `repetition`** | `SkillMatrix.php:95` | **0 exec** | **pass@1 64,4% vs pass^3 56,2%, 13,7% flaky** = a superfície endereçável do wrapper. E corrige o CI: **n=3, não 9 → o limiar de 44pp está OTIMISTA** |
| 4 | **Ligar o DifficultyCalibrator** | `grep` em `EnterpriseReportBuilder` = 0; + `bandFor(int $k, int $n)` com `unknown` | 1 fio + 1 assinatura | **A sua tese vira lei EXECUTADA.** O relatório passa a acusar as 22 linhas `too_easy` |
| 5 | **Eficiência para dentro de `skills.rows`** | hoje em `model_capabilities` (`EnterpriseReportBuilder.php:512-514, 634-636`) | 0 exec | Liga o eixo que mede o **IMPOSTO** do Atlas (43× mais lento em sec_qa_v1) ao eixo de M |

### FASE 1 — **a decisão: 60 execuções decidem se a obra de dificuldade existe**

| # | ação | custo | destrava |
|---|---|---|---|
| **0** | 🔴 **PRÉ-REQUISITO: fazer o braço Atlas rodar o Atlas** (`rivals-atlas-dev-bridge.php:79-92`) | obra | **Sem isso, tudo abaixo mede prompt engineering.** `atlas_n=0` em 30/30 |
| 6 | **9 execuções em terminal_bench** (bare 2/9, `wilson_low=0,0632`) | **9 exec** | **Atlas ≤4/9 → Δθ_high=3,71 → M teto 40,7× → 50× REFUTADO.** 5/9 → 63,7× sobrevive. **E se Δθ=7,824 fosse verdade, prevê p_atlas=99,86% → P(9/9)=98,75%; qualquer 8/9 ou pior tem p-valor 1,25%** |
| 7 | **51 execuções nas 6 fatias não-saturadas** (terminal_bench 2/9, wmdp_cyber 2/9, live_code_bench 3/9, ifeval:punct 3/6, aider 6/9, musr 6/9) → **Δθ̂ via Mantel-Haenszel + SE de Robins-Breslow-Greenland** | **51 exec** | **Refuta 50× PERMANENTEMENTE**: poder **100%** se Δθ≤1,0 · **99,7%** se Δθ≤1,5 · **92,8%** se Δθ≤2,0 (MC 20.000 iter). **Zero autoria de benchmark** |

> **⚠️ Assimetria honesta e declarada: com 9/fatia o Δθ̂ satura em ~3,13 logits. O pacote atual REFUTA 50× barato e JAMAIS o confirma.** Confirmar exige pool novo em bare≈0,15% + n≈340-400. **Por isso: 51 antes de 400.**

### FASE 2 — **a escada, só se a FASE 1 sobreviver**

| # | ação | custo |
|---|---|---|
| 8 | **Pré-registro que vincula o relatório**: `run_id` declarado ANTES; `excluded_run_ids` soma **364, não 0** | pré-registro |
| 9 | **`uv sync`** no `inspect_evals` — 138 evals de fronteira baixados e 100% ociosos | **1 comando** |
| 10 | **Reapontar os 171 cases de MMLU** → `frontier_cs_algorithmic` (scoring parcial contínuo) | horas |
| 11 | **n=36 no terminal_bench** — o instrumento mais bem calibrado do pacote | 36 exec |
| 12 | **G4: n=400, bare≈0,2%** — **o único degrau que prova 50×** (M_low=70,6×) | 400 exec/hab |

---

## 9. O VEREDITO

**Você está certo sobre a lei, e mais certo do que sabia sobre o dado.** A mediana de bare é 86,1% (M_max 1,16×); o melhor instrumento do arsenal inteiro tem teto de 4,50×; 24 das 30 linhas não provam ganho nenhum nem com um Atlas perfeito; a melhor prova disponível é 2,34× contra uma barra de 50×. E **a sua régua já está escrita no repo desde 02/07 com o fio desligado** (`grep -c DifficultyCalibrator EnterpriseReportBuilder.php → 0`).

**Você precisa de quatro ajustes, e cada um muda a obra:**
1. **A dificuldade revela o M, não o cria.** Δθ < 3,912 logits → 50× impossível em qualquer teste, qualquer n. **O teto é do Atlas, não do teste.**
2. **M é um dial** (2× a 2.500× só pelo pool). **Declare a barra no invariante: Δθ ≥ 3,912 = odds-ratio 50×.**
3. **Múltipla escolha nunca passa de 4×** (chute c=0,25) — e, mais fundo, **um item de MMLU não tem superfície para um único órgão do Atlas acoplar.** Não é saturação: é o instrumento não tocar o objeto. **62% do orçamento está lá.**
4. **A janela para PROVAR 50× é bare ≈0,2% com n≈400** — 13× mais dura que os 2% e 2× mais cara que os 193.

**E uma refutação:** **não existe M para elevar.** `atlas_n=0` em 30/30, e o braço "com Atlas" era `hermes -z` + 3 linhas de preâmbulo enquanto o braço "sem Atlas" carregava o `CLAUDE.md` do Atlas no CWD. **Elevar a dificuldade antes de despoluir o controle faz o Atlas aparecer multiplicando o Hermes — e o Hermes já é metade do Atlas.** Essa é a única forma de o 50× aparecer e não significar nada.

> ### **Ordem: 0 execuções (FASE 0, e muda o número) → 9 execuções (refuta ou não) → 51 execuções (decide se a obra existe) → 400 por habilidade (prova o 50×).**
> ### **A escada é obra de meses e você vai precisar dela. Mas 51 execuções decidem se ela deve existir — e elas custam uma noite de máquina.**

*(Arquivos de trabalho verificados: `/Users/vitorepf/develop/Atlas/atlas-server/storage/atlas/rivals/enterprise/report.json` · `app/Services/Ai/Rivals/Core/StatisticalPolicy.php:187` · `SkillMatrix.php:95,128-131` · `EnterpriseReportBuilder.php:457-487,512-514,634-636` · `DifficultyCalibrator.php:14` · `ContaminationGuard.php:14-70` · `config/atlas_rivals.php:104-121,417` · `scripts/rivals-hermes-bare.php:22-52` · `scripts/rivals-atlas-dev-bridge.php:79-92` · `tools/rivals/benchmarks/inspect_evals/src/inspect_evals/_registry.py`. Script da escada: `/private/tmp/claude-501/-Users-vitorepf-develop-Atlas-atlas-server/f55d43c7-b8dd-4826-8f16-c1ec22322653/scratchpad/escada.php`)*