---
id: atlas-documentation-reality-block-upgrade-map
type: engineering_knowledge
title: Atlas Documentation Reality Block Upgrade Map
status: active
category: documentation-governance
priority: 100
summary: Mapa de evolucao dos blocos ADRS para elevar autoridade documental, realidade operacional, eficiencia de contexto, Cartografia humana e feedback humano-doc ao nivel mais robusto antes da implementacao.
human_name: Mapa de Upgrade dos Blocos ADRS
canonical_name: Atlas Documentation Reality Block Upgrade Map
technical_name: ADRSBlockUpgradeMap
cartography_type: module
canonical_source: docs/engineering-knowledge-base/atlas-documentation-reality-block-upgrade-map.md
tags:
  - atlas-ai
  - documentation
  - block-architecture
  - code-reality
  - cartography
  - context-efficiency
capabilities:
  - documentation_reality_block_upgrade
  - adr_system_block_architecture
  - ai_context_efficiency_design
  - human_cartography_design
  - operational_truth_design
decisions:
  - Nome canonico/produto obrigatorio: Atlas Documentation Reality Block Upgrade Map.
  - Acronimo tecnico obrigatorio: ADR-BUM.
  - Nome interno de experiencia/superficie: Atlas Documentation Reality Upgrade Board.
  - Runtime tecnico: nenhum; este documento define inteligencia e robustez de blocos.
  - ADR-BUM nao substitui ADRS; ele detalha como cada bloco deve evoluir antes de virar runtime.
  - Bloco so vira implementacao quando tiver saida verificavel, owner, evidencia, gate e risco declarado.
maintenance:
  - Manter abaixo de 520 linhas.
  - Atualizar quando ADRS, ADRIB, ACRUI, AURC ou Documentation OS mudarem os blocos oficiais.
  - Rodar docs-health, docs-authority-audit e architecture-validate apos alteracoes.
related_paths:
  - docs/engineering-knowledge-base/atlas-documentation-reality-system.md
  - docs/engineering-knowledge-base/atlas-documentation-reality-block-registry.md
  - docs/engineering-knowledge-base/atlas-documentation-reality-implementation-blueprint.md
  - docs/engineering-knowledge-base/atlas-code-reality-usage-intelligence.md
  - docs/engineering-knowledge-base/atlas-universal-reality-cartography.md
doc_schema: atlas_canonical_module_doc.v1
graph_id: atlas-documentation-reality-block-upgrade-map
graph_title: Atlas Documentation Reality Block Upgrade Map
graph_world: atlas
graph_layer: system
graph_kind: module
graph_parent: atlas-documentation-reality-system
graph_status: active
graph_source: repo
owner: documentation-governance
repo_paths:
  - docs/engineering-knowledge-base/atlas-documentation-reality-block-upgrade-map.md
allowed_changes:
  - Refinar blocos, upgrades, gates e evidencias da area ADRS.
forbidden_changes:
  - Criar nova fonte de verdade.
  - Trocar nomes canonicos do ADRS sem atualizar ADRS.
  - Autorizar codigo mutativo a partir deste mapa.
depends_on:
  - atlas-documentation-reality-system
  - atlas-documentation-reality-implementation-blueprint
flows_to:
  - atlas-code-reality-usage-intelligence
  - atlas-universal-reality-cartography
  - atlas-cartography
unlocks:
  - documentation-reality-implementation
  - block-level-readiness
  - ai-safe-context-governance
governs:
  - documentation-governance
  - architecture-audit
  - human-cartography
evidence:
  - docs/engineering-knowledge-base/atlas-documentation-reality-block-upgrade-map.md
required_tests:
  - "php artisan atlas:engineering:knowledge docs-health --json"
  - "php artisan atlas:ai:docs-authority-audit --json"
  - "php artisan atlas:ai:architecture-validate --json"
requires_evidence: true
risk_level: critical
visual_tags:
  - system
  - block-upgrade-map
  - documentation-reality
ai_entrypoints:
  - Leia este doc quando precisar elevar, implementar ou revisar blocos ADRS.
ai_usage_notes:
  - Use este mapa para transformar bloco conceitual em runtime testavel sem quebrar a hierarquia do ADRS.
quality_gates:
  - "php artisan atlas:engineering:knowledge docs-health --json"
  - "php artisan atlas:ai:docs-authority-audit --json"
  - "php artisan atlas:ai:architecture-validate --json"
failure_modes:
  - IA implementar bloco sem evidencia objetiva.
  - Area visual crescer sem verdade operacional.
  - Context pack ficar minimo demais e perder restricao critica.
observability_signals:
  - block_readiness_score
  - evidence_coverage
  - source_freshness
  - visual_coverage
  - context_minimality
next_actions:
  - Usar este mapa para priorizar as proximas docs filhas e services read-only.
---
# Atlas Documentation Reality Block Upgrade Map

## Resumo

ADR-BUM define como elevar cada bloco do ADRS para nivel enterprise. O objetivo
e evitar que os blocos sejam apenas nomes bons: cada bloco precisa ter funcao,
upgrade maximo, gate e prova de maturidade.

Este mapa responde:

```text
Como cada bloco fica mais inteligente, profissional e robusto antes de codar?
```

## Papel no Atlas

ADR-BUM e a ponte entre arquitetura macro e implementacao segura. Ele ajuda uma
IA a entender a intencao de cada bloco e impede que ela implemente uma versao
rasa, duplicada ou perigosa.

Ele tambem separa cinco familias que voce listou:

- A. Verdade canonica e autoridade.
- B. Verdade operacional.
- C. Eficiencia para IA.
- D. Mostrar a verdade para humano.
- E. Ponte humano-documentacao.

O ADRS ainda mantem seis planes oficiais. O sexto plane e transversal:
governanca, acesso, privacidade, ciclo de vida e fronteira multi-projeto.

## Onde Se Encaixa

```text
ADRS define blocos oficiais
ADRS Block Registry da ids e metadados navegaveis aos 52 blocos
ADR-BUM eleva cada bloco
ADRIB define ordem de implementacao
ACRUI implementa realidade operacional
AURC implementa cartografia humana
```

Se houver conflito:

1. ADRS decide a existencia e nome do bloco.
2. ADR-BUM decide a maturidade esperada.
3. ADRIB decide a ordem segura.

## Contratos

Todo bloco precisa declarar:

- `owner_plane`;
- `primary_runtime_or_doc`;
- `input_sources`;
- `output_schema`;
- `evidence_refs`;
- `quality_gate`;
- `failure_mode`;
- `human_surface`;
- `ai_context_impact`;
- `readiness_level`.

Readiness levels:

- `L0_named`: so conceito.
- `L1_specified`: contrato e saida definidos.
- `L2_testable`: gate ou teste definido.
- `L3_read_only`: service/comando read-only.
- `L4_integrated`: usado por ACRUI, AURC ou context pack.
- `L5_self_improving`: telemetria real melhora o bloco.

## Fluxo

1. Escolher area e bloco.
2. Verificar se ja existe doc/runtime parecido.
3. Elevar o bloco para pelo menos L2 antes de codar.
4. Implementar read-only primeiro.
5. Conectar a ADRS score.
6. Expor para AURC ou context pack apenas com evidence refs.
7. Medir uso real.

## Criterio Nota 10 Por Area

### A - Autoridade

Nota 10 exige que qualquer conflito entre doc, codigo, KB, ledger, Obsidian,
chat, projection ou Cartografia produza um veredito auditavel. O resultado deve
mostrar fonte vencedora, fonte perdedora, tier, freshness, owner e motivo.

### B - Operacional

Nota 10 exige que qualquer arquivo, rota, comando, teste, runtime ou doc tenha
classificacao operacional conservadora. O sistema deve preferir
`unknown_requires_review` a uma conclusao falsa. Remocao so passa por
quarantine.

### C - IA

Nota 10 exige que a IA receba o menor pacote suficiente, com motivos de inclusao
e omissao. Contexto minimo nao pode perder regra critica; Context Loss Critic
bloqueia pack perigoso.

### D - Humano

Nota 10 exige que a Cartografia explique o macro visualmente: universo,
organizacao, projeto, sistema, fluxo e componente. Texto deve ser detalhe sob
demanda, nao muleta principal.

### E - Ponte Humana

Nota 10 exige que duvida, correcao e uso real voltem para owner docs por fila
rastreavel. Feedback humano nao sobrescreve doc canonico sem gate.

### F - Governanca

Nota 10 exige boundary por projeto, politica de acesso, lineage, SLO e readiness
por bloco. Nenhum bloco entra em runtime sem readiness level e gate.

## Regras para IA

- Nao implemente bloco a partir do nome; leia funcao, upgrade e gate.
- Nao crie novo bloco se um existente pode ser fortalecido.
- Nao chame bloco de pronto sem output schema e prova.
- Nao mova informacao canonica para Cartografia; Cartografia projeta.
- Nao reduza contexto se a omissao remover restricao critica.
- Nao trate correcao humana como verdade ate passar por owner/doc gate.

## Escopo de Implementacao

### A. Verdade canonica e autoridade

| Bloco | Upgrade maximo | Prova |
|---|---|---|
| Documentation Authority Kernel | virar adjudicator de conflito com ranking por tier, freshness, owner e evidence | conflito retorna vencedor e motivo |
| Canonical Source Registry | registrar Atlas e projetos externos com authority root separado | fonte tem owner, repo, tier e boundary |
| Documentation Operating System | compilar regra doc em lint/gate aplicavel por CI | docs-health cobre formato e limite |
| Knowledge Governance System | mapear autoridade entre repo, KB, ledger, Obsidian, chat e projections | matriz de conflito testada |
| Vocabulary Alignment Guard | detectar sinonimos perigosos e nomes conflitantes antes de virar doc/codigo | glossary diff + rename proposal |
| Documentation Lifecycle State Machine | controlar draft, active, scaffold, superseded, archived, quarantine, deleted | transicao invalida bloqueada |

Melhoria adicional: adicionar `Authority Confidence Score`, indicando quando o
vencedor e claro, fraco ou exige decisao humana.

### B. Verdade operacional

| Bloco | Upgrade maximo | Prova |
|---|---|---|
| ACRUI Operational Reality | classificar docs/codigo/fluxos por reachability, teste, evidence e surface | audit JSON por alvo |
| Drift & Duplication Guard | comparar doc, codigo, route, command, test, package e Cartografia | blocker de drift/dup |
| Legacy & Quarantine Governance | transformar remocao em lifecycle com scan, teste, redirect e approval | quarantine plan |
| Evidence & Runtime Proof Bridge | ligar readiness a teste, trace, receipt, command e caller | evidence tuple |
| Documentation Reality Score | gerar score por area com pesos e blockers | score deterministico |
| Source Freshness Gate | bloquear contexto stale por doc/hash/index/runtime | freshness result |
| Contradiction Resolver | abrir fila de contradicao com owner decision obrigatoria | contradiction packet |
| Implementation Readiness Matrix | dizer doc-only, read-only, code-ready, blocked ou needs-human | matrix por bloco |
| Reality Change Journal | manter historico active/scaffold/legacy/superseded/quarantine | append-only journal |
| Semantic Deduplication Engine | detectar docs diferentes com mesma responsabilidade | merge/supersede plan |
| Auto-Split Planner | propor split de docs longas preservando backlinks e owner | split proposal |
| Obsolete Knowledge Simulator | simular impacto de arquivar/ocultar/fundir antes de mudar | impact report |
| Evidence Sufficiency Gate | bloquear claim forte sem doc+code+caller+test+runtime evidence | claim verdict |
| Reality Diff Engine | comparar snapshots de docs/codigo/mapa/context pack | reality diff |
| Orphaned Decision Finder | achar decisao sem owner, path, teste ou evidencia | orphan queue |
| Documentation Entropy Monitor | medir crescimento, repeticao, staleness e dispersao de ownership | entropy score |

Melhoria adicional: adicionar `Reality Confidence Model`, com score por
classificacao para evitar falso positivo de dead code ou readiness.

### C. Eficiencia para IA

| Bloco | Upgrade maximo | Prova |
|---|---|---|
| AI Context Projection | gerar pack minimo por tarefa, risco e owner | pack com motivo de inclusao |
| Context Minimality Ledger | registrar fontes usadas, omitidas e razao | ledger auditavel |
| Multi-Agent Handoff Projection | gerar pacote especifico para Claude/Codex/Gemini/subagente | handoff schema |
| Documentation Budget Governor | limitar tokens por prioridade, authority e risco | budget decision |
| Retrieval Audit Trail | explicar cada retrieval e descarte | retrieval trace |
| Documentation Compression Tiers | criar L0 resumo, L1 contrato, L2 detalhes, L3 evidencia | tiers testados |
| Provider Misread Defense | projetar proibicoes e owner docs para reduzir erro de provider | misread tests |
| Documentation Working Set Cache | manter hot set em RAM/disco sem virar fonte de verdade | cache freshness |
| Context Pack Regression Test | replayar tarefas antigas com pack novo | regression delta |
| Privacy & Redaction Gate | remover segredo e dado sensivel antes de projection | redaction test |
| Access Policy Resolver | decidir quem pode ver detalhe tecnico/evidence | access verdict |
| Synthetic Reader Tests | testar IA limpa lendo so docs canonicas | reader score |
| Canonical Example Corpus | manter exemplos bons/ruins de doc, mapa, context pack e classificacao | examples suite |

Melhoria adicional: adicionar `Context Loss Critic`, que compara pack minimo vs
fonte completa e alerta se uma restricao critica foi perdida.

### D. Mostrar a verdade para o humano

| Bloco | Upgrade maximo | Prova |
|---|---|---|
| AURC Visual Reality | renderizar universo->organizacao->projeto->sistema->fluxo->componente | visual graph JSON |
| Human Modal Contract | resumir fonte, regra, risco, teste e proximo passo em linguagem humana | modal snapshot |
| Semantic Zoom Contract | trocar nivel semantico sem virar zoom de pixels | drilldown test |
| Visual Grammar & Nomenclature | padronizar cor, forma, selo, risco, confidence e status | grammar spec |
| Visual Completeness Auditor | achar nodes orfaos, fluxos sem destino e areas invisiveis | visual audit |
| Human Attention Heatmap | medir onde humano trava, clica ou pede explicacao | heatmap event |
| Cartography Task Simulator | validar se humano/IA acha resposta pelo mapa | task score |
| Cross-Modal Consistency Gate | comparar doc, modal, mapa e context pack | consistency diff |
| Cartography Cognitive Load Meter | medir excesso de nodes, edges, texto e ruido | load score |
| Surface Coverage Matrix | mostrar quais docs aparecem em desktop/mobile/CLI/API/Cartografia | coverage matrix |

Melhoria adicional: adicionar `Visual Truth Confidence Overlay`, mostrando se
cada area e provada, inferida, stale, unknown ou bloqueada.

### E. Ponte humano-documentacao

| Bloco | Upgrade maximo | Prova |
|---|---|---|
| Human Correction Loop | transformar confusao/correcao em patch canonico revisavel | correction packet |
| Canonical Question Router | rotear pergunta humana para owner doc, evidence e mapa | routing result |
| Documentation Adoption Meter | medir se humanos/IAs usam owner doc certo | adoption metric |
| Learning-to-Doc Promotion Gate | promover aprendizado de runs para doc canonico com prova | promotion decision |
| Documentation SLO & Alerting | alertar freshness, coverage, orphan, drift e cost | SLO event |
| Owner Escalation Queue | levar drift/contradicao/sem owner para responsavel | escalation item |
| Cross-Organization Boundary | separar Atlas plataforma de projetos/clientes externos | boundary proof |

Melhoria adicional: adicionar `Human Intent Capture Contract`, para transformar
duvida do operador em requisito rastreavel sem virar decisao canonica direta.

### F. Governanca transversal

Esta area existe para completar os seis planes do ADRS e evitar que A-E virem
silos.

| Bloco transversal | Funcao | Prova |
|---|---|---|
| Block Readiness Gate | impede runtime antes de L2/L3 | readiness verdict |
| Evidence Cost Policy | define quando buscar prova cara ou usar prova local | cost decision |
| Project Boundary Resolver | aplica regra por repo/empresa/produto | boundary verdict |
| Documentation Supply Chain | rastreia origem -> transformacao -> projection -> uso | lineage graph |
| Continuous Improvement Loop | usa telemetria para propor melhoria, nao para mudar sozinho | proposal packet |

## Dependencias

- ADRS define planes e blocos.
- ADRIB define ordem de implementacao.
- ACRUI implementa B e parte de A/C/F.
- AURC implementa D e parte de E.
- Documentation OS garante formato.
- Knowledge Governance garante autoridade.
- Code Intelligence e docs-authority fornecem evidencia inicial.

## Evidencias

Um bloco so pode subir de nivel com:

- schema ou contrato;
- input sources declaradas;
- output verificavel;
- teste ou comando;
- evidence refs;
- owner e failure mode;
- impacto em IA, humano ou governanca.

## Riscos

- criar muitos blocos e perder implementabilidade;
- confundir melhoria conceitual com produto pronto;
- fazer Cartografia visual antes de ACRUI;
- economizar token removendo restricao critica;
- permitir que feedback humano sobrescreva doc canonico sem review.

Mitigacao:

- cada bloco tem readiness level;
- ACRUI read-only antes de qualquer mudanca mutativa;
- AURC so renderiza source/evidence;
- Context Loss Critic protege compactacao;
- Human Correction Loop passa por owner.

## Exemplos

- Se uma IA quer apagar service sem caller, ACRUI retorna
  `unused_candidate`, exige quarantine plan e impede delete direto.
- Se uma pessoa nao entende Atlas Dev no mapa, Human Attention Heatmap abre
  melhoria visual e Human Correction Loop pode gerar patch de doc.
- Se um context pack omite regra critica, Context Loss Critic bloqueia a
  projection e registra no Minimality Ledger.

## Proximas Acoes

1. Linkar ADR-BUM no ADRS e ADRIB.
2. Usar este mapa para revisar se os 52 blocos atuais precisam de merge, split
   ou fortalecimento.
3. Priorizar Fase 1 ADRIB: Source Registry Read-Only.
4. Transformar blocos A/B em contracts antes de UI.
5. So depois evoluir AURC visual com confidence overlay e simulator.
