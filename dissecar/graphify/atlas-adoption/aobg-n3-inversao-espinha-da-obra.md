# AOBG N3 — A Inversão: a Espinha da Obra, em produto

Estado: **PRODUTO, PROVADO LIVE NO DEV (custo zero)** (2026-06-10). N1 deu ao cérebro uma porta da frente; N2 fez o cérebro intervir durante a sessão; **N3 faz o cérebro DIRIGIR**. O operador declara um INTENT em linguagem natural e o Atlas decompõe numa OBRA (um trabalho de múltiplos passos), executa governado, e devolve **UM branch pronto-para-merge**. Isso ELEVA A UNIDADE DE TRABALHO de `edit → obra` — o único multiplicador de ordem 50x. Tudo provado LIVE contra o pgsql de dev, custo zero (o LLM pago é stubado nos testes e na prova; o gasto real é o único comando do operador). Não commitado (decisão do operador).

> Doc de operador. A árvore canônica (`docs/engineering-knowledge-base`) exige frontmatter de cartografia; este doc vive em `dissecar/` de propósito, fora do contrato de grafo.

## O salto de N2 para N3 — a inversão

- **N1 = a biblioteca.** A IA recebia o cérebro se *pedisse*. Passivo.
- **N2 = a sentinela.** O cérebro *seguia* a tarefa e agia nos eventos do ciclo de vida da ferramenta. Reativo.
- **N3 = a inversão.** O cérebro **decompõe e conduz** o trabalho. O motor não consulta o cérebro (N1) nem o cérebro vigia o motor (N2): **o cérebro É o motor de planejamento.** Ativo, dirigindo.

A regra-mãe segue: **não se constrói um segundo motor.** N3 é, em quase toda a sua extensão, o **FIO que faltava** entre peças já provadas — a decomposição é a única lógica genuinamente nova; execução, materialização, certificação e write-back reusam serviços que já existiam e já eram provados.

## A obra de ponta a ponta: um gesto humano

```
atlas:obra:deliver "cria um serviço Foo; depois liga ele no Bar; por fim testa"
```

1. **DECOMPÕE** o intent num **plan-DAG** (passos com `depends_on`, provado acíclico).
2. **EXECUTA** cada nó na ordem topológica, acumulando a mudança certificada de cada passo no **MESMO** branch `atlas/obra/<id>` — passo N constrói sobre o estado de N-1.
3. **CERTIFICA** o branch montado **como um todo** (não só passo a passo).
4. **GRAVA** a obra + cada passo + o resultado **de volta no cérebro** (compounding — a próxima obra enxerga as anteriores).
5. Para em **UM branch.** O **operador** revisa e dá merge. O Atlas nunca faz merge, nunca dá push, nunca toca a main.

## As fases (F1–F4) e os arquivos

| Fase | O que é | Arquivo principal |
|---|---|---|
| **F1** | A espinha da DECOMPOSIÇÃO (intent → plan-DAG → passos, validado/anchored/persistido) | `app/Services/Ai/Obra/AtlasObraPlanService.php` |
| **F2** | O EXECUTOR da obra (caminhada topológica → UM branch que acumula) | `app/Services/Ai/Obra/AtlasObraExecutor.php` |
| **F3** | A CERTIFICAÇÃO de integração (o branch montado como um todo) | `app/Services/Ai/Obra/AtlasObraCertificationService.php` |
| **F4** | A SUPERFÍCIE do operador (um comando comissiona a obra) | `app/Services/Ai/Obra/AtlasObraService.php` + `AtlasObraDeliverCommand.php` |

Peças reusadas (o "não construa um segundo motor"):

- **Materializador** (a única autoridade git): `GovernedBranchMaterializationService.php` ganhou o **modo obra-accumulate** — `openObra()` / `applyStepToObra()` / `closeObra()` / `measureObra()`. Preserva TODOS os invariantes de soberania (nunca main / push / merge; main provado byte-idêntico; `discardBranch()` agora governa também o prefixo `atlas/obra/`).
- **Delivery por nó**: o seam `ObraNodeDelivery` — `ProviderObraNodeDelivery` dirige o já-provado `AtlasLiveCodeDeliveryService` (gasto real); um fake nos testes (zero tokens).
- **Cérebro**: `AtlasOpenBrainContextPackService` (N1, ancoragem por passo) + `AtlasRealityGraphIngestionService::recordMissionOutcome()` (por passo) e `recordObraOutcome()` (a obra como unidade de primeira classe — nó `obra` + nó `evidence` + arestas `generated` para cada passo).
- **Spine tables**: migration `2026_06_10_140000_create_atlas_obra_plan_tables.php` (`atlas_obra_plans` + `atlas_obra_nodes`, SQL portável pgsql/sqlite, `intent` redigido, `depends_on` provado acíclico antes de persistir).

## O plan-DAG (F1)

O decompositor quebra o intent em passos e os encadeia. Duas implementações atrás do mesmo seam `ObraDecomposer`:

- **`DeterministicObraDecomposer`** (padrão, **custo zero**): fatia em fronteiras de cláusula (bullets `- `/`1.`, `;`, e o mesmo vocabulário conectivo do classificador de missão: "depois", "por fim", "em seguida", "then", "and"). Produz uma cadeia linear válida (acíclica por construção). É o caminho de **honest-degrade** e o que todo teste injeta.
- **`ProviderObraDecomposer`** (gated, gasta): só roda quando `atlas.obra.decompose_provider` está setado; pede JSON estrito de passos com `key`/`depends_on`. Em qualquer falha (provider off, JSON inválido, vazio) **degrada honestamente** para o determinístico — nunca quebra, nunca fabrica.

O serviço **valida ou recusa**: deps têm que resolver para nós do mesmo plano e o grafo tem que ser acíclico (Kahn). Ciclo, dep pendurada, plano acima do cap (`atlas.obra.max_nodes`, default 12) ou vazio → **recusa ANTES de persistir** (a tabela só guarda plano executável). Cada nó é **brain-anchored** (refs provider-safe do pack N1 para AQUELE passo; fail-open para refs vazias).

## Uma branch, muitos passos (F2 — a acumulação)

Cada nó é commitado no **mesmo worktree mantido**, em ordem de dependência. Como o worktree persiste entre passos e cada passo é commitado, o git computa modify-vs-new contra o estado committado do passo anterior — então o passo N **enxerga de verdade** os passos 1..N-1. Não são N branches; é UM branch com N commits.

Fail-closed na certificação: um nó que não certifica (ou cuja aplicação / gate por-nó falha) **HALTA a obra** — nenhum nó depois roda, o branch parcial é **mantido** para inspeção mas marcado `failed` (nunca um pass parcial silencioso). Fail-open no cérebro: uma queda da ancoragem/gravação degrada a run mas **nunca** quebra a obra.

## O cérebro por nó + os Forge gates

- **Brain-driven**: cada nó recebe o contexto ACOS (o pack N1 daquele passo, redigido, ids/labels/paths apenas — nunca source).
- **Governado**: o gate por-nó é fail-closed. A certificação da delivery **é** a credencial padrão (um passo certificado passa); um `ObraNodeGate` injetado (Forge mais estrito) roda por cima e pode **vetar** — veto = HALT.
- **Compounding**: a obra + cada passo + o resultado são gravados de volta. A próxima obra vê as anteriores no AURG.

## Certificação de integração (F3) — o todo > as partes

Um pass passo-a-passo NÃO implica que a obra integra: o passo 3 pode quebrar o que o passo 1 construiu. F3 fecha essa lacuna — roda o **integrated check** (um teste/medida de branch inteiro, ex.: `php artisan test --filter=Foo`) **no worktree montado**, antes do close soltar o worktree.

- **`certified=true`** SÓ quando todo passo passou E o integrated check RODOU e PASSOU.
- Check fornecido mas que falhou / não conseguiu rodar → `certified=false` → `needs_review` (fail-closed honesto).
- **Sem** integrated check → `disposition=no_integrated_check`: a obra está completa (cada passo certificado isoladamente, tudo em um branch) mas **não foi integration-testada como unidade** — `certified=true` mas explicitamente documentada como não-integrada. O gradiente honesto: `no_integrated_check` < `certified`. Os dentes de F3 mordem no instante em que um check É fornecido e não fica verde.

`AtlasObraCertificationService` é **assembly puro** (sem IO): o materializador roda a medida; este serviço decide certified-ou-não e monta o envelope determinístico com `receipt_hash` (sha256 sobre os fatos load-bearing — re-certificar o mesmo estado montado dá o mesmo hash).

## Never-merge — a soberania

- A obra inteira é **UM branch** `atlas/obra/<id>`; o loop **nunca** faz merge, **nunca** dá push, **nunca** toca a main.
- `closeObra()` **prova** que a main ficou intocada (HEAD + working tree byte-idênticos à baseline de `openObra()`).
- A obra inteira é **reversível**: `discardBranch()` só apaga refs sob `atlas/materialize/` OU `atlas/obra/` — nunca main, nunca um branch do operador, sempre delete local (nunca push).
- A leitura via MCP (`atlas_obra_status`) é **read-only** — não existe tool de deliver exposta ao motor externo (provado em `AtlasObraStatusMcpTest`). O gasto é só o comando CLI do operador.

## O comando real (gasta — o gesto do operador)

```bash
# Comissiona a obra inteira de uma vez (decompõe + executa + certifica + grava):
atlas:obra:deliver "cria um Foo; depois liga no Bar; por fim testa" \
    --workspace=/path --provider=codex_cli \
    --integrated-check="php artisan test --filter=Foo" --json

# Ou hand-step (o mesmo motor, fases separadas):
atlas:obra:plan   "..."            # F1 — só planeja (PLAN ONLY, custo zero por padrão)
atlas:obra:run    obra-<id>        # F2/F3 — executa o plano persistido (gasta por nó)
atlas:obra:status --obra=obra-<id> # F4 leitura (READ ONLY, custo zero)
atlas:obra:run    obra-<id> --discard   # descarta a obra inteira (reversível)
```

O caminho default de `atlas:obra:deliver` faz chamadas REAIS de provider (uma por nó do DAG — gasto do operador). `decompose_provider` é o caminho gated do decompositor real (vazio = determinístico custo-zero). Use quando pretende gastar; o branch resultante é seu para inspecionar e dar merge ou descartar.

## Compounding

Cada obra grava um nó `obra` (intent redigido, branch, flags/contagens/hash), um nó `evidence` (o resultado da certificação de integração — sem payloads) e arestas `generated`: `obra → evidence` + `obra → cada passo` (cite-or-omit: só passos que já têm nó de missão no cérebro). A próxima obra, ao ancorar seus nós, **enxerga as obras anteriores** no AURG. O grafo cresce com o uso.

## Prova LIVE (custo zero, pgsql de dev)

Provado contra o pgsql real (`127.0.0.1:5433/atlas`), com repo git temporário real, materializador obra-accumulate real, brain write-back AURG real — só o decompositor (determinístico) e a delivery por-nó (fake) sem gasto. Resultado: **ALL_PASS**.

- `PLAN_NODES=3`, `ONE_BRANCH=atlas/obra/<id>`, `STATUS_DONE`, `CERTIFIED=YES` (o `php -l` integrado RODOU + PASSOU → `disposition=certified`).
- `STEP3_BUILDS_ON_STEP1_2=YES` — o passo 3 leu o conteúdo committado do passo 1 do worktree compartilhado e estendeu; 3 commits, um por passo, em UM branch.
- `MAIN_UNTOUCHED / NEVER_MERGED / NEVER_PUSHED` todos YES; HEAD + working tree byte-idênticos.
- `OBRA_IN_BRAIN=YES` com 4 arestas `generated` (obra→evidence + obra→3 passos).
- `COMPOUNDING=YES` — uma segunda obra gravada; a primeira ainda presente; `SECOND_OBRA_SEES_FIRST=YES`.
- `OBRA_HALTS=YES` — passo 2 falha certificação → status `failed`, statuses `done,failed,skipped`, 1/3 entregue, main intocada.
- `REMAINING_BRANCHES=0`, `BRAIN_BACK_TO_BASELINE` (de volta aos 0 nós-obra / 269 arestas exatos da baseline).

## Cobertura de testes (custo zero, sqlite)

- `tests/Feature/Ai/Obra/` — **42 testes** (plan service + executor + certification + integration certification + service + plan/status commands + provider decomposer).
- Bateria adjacente: `tests/Feature/Ai/RealExecution/` + `tests/Feature/Reality/` — **66 testes** (materializador obra-accumulate, AURG ingestion `recordObraOutcome`, MCP read-side `atlas_obra_status` sem tool de deliver).
- Grupo AOBG + MCP + OpenBrain + Obra — **181 testes** (1362 asserts).

A prova de acumulação é **não-falsificável**: o passo 3 só consegue conter o conteúdo do passo 1 se os dois foram aplicados ao MESMO worktree em ordem — um design de N-branches faria o passo 3 não ver nada.

## Limites honestos

- **A qualidade do decompositor limita a obra.** O determinístico fatia o texto do operador em cláusulas — bom para intents já estruturados ("faz X; depois Y; por fim Z"), raso para um intent vago de uma frase (vira obra de um passo). O decompositor de provider (gated) é mais rico mas gasta e ainda assim é limitado pela qualidade do modelo.
- **O integrated check é opcional, e a honestidade depende dele.** Sem ele, a obra é `no_integrated_check` (completa mas não integration-testada como unidade) — não é o mesmo que `certified`. Forneça um check de branch inteiro para a obra ser provada verde como um todo.
- **O run real é gasto do operador.** A prova cost-free usa stubs; o `atlas:obra:deliver` real chama o provider uma vez por nó. Esse é o único ponto de gasto da obra inteira.
- **Não materializa além do branch.** O Atlas para em UM branch; quem dá merge é o operador (por design — soberania). A materialização para além disso (deploy, etc.) é outra obra.
