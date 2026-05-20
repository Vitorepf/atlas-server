---
id: atlas-epistemic-operating-system
type: engineering_knowledge
title: Atlas Epistemic Operating System
status: future
category: knowledge-governance
priority: 100
implementation_state: future_truth_os_not_current_runtime
summary: Especificacao canonica do proximo patamar do Knowledge Governance System: sistema operacional de verdade, confianca, contradicao, maturidade, permissoes e autoevolucao para IAs que constroem o Atlas.
tags:
  - atlas
  - epistemic-os
  - knowledge-governance
  - truth-graph
  - confidence
  - self-evolution
capabilities:
  - epistemic_operating_system
  - truth_graph
  - confidence_engine
  - drift_detection
  - contradiction_register
  - maturity_gates
  - ai_knowledge_permissions
  - autonomous_governance_loop
decisions:
  - Atlas Epistemic Operating System e a evolucao acima do Knowledge Governance System.
  - Knowledge Governance define fonte de verdade; Epistemic OS calcula confianca, atualidade, contradicao, maturidade e acionabilidade dessa verdade.
  - Toda autoimplementacao por IA deve depender de estado epistemico verificavel antes de alterar codigo, docs, policy, runtime ou arquitetura.
  - Nenhuma area pode ser considerada segura para autoevolucao sem evidencias conectadas a docs canonicos, codigo, testes, Evidence Ledger e status de drift.
  - Epistemic OS alimenta Cartographic Knowledge OS com scores, riscos, lacunas e estados visuais.
  - Epistemic OS e governado pelo Sovereign OS quando a verdade vira decisao de direcao, autonomia, prioridade ou auto-modificacao.
maintenance:
  - Atualizar quando Knowledge Governance, Evidence Ledger, Code Intelligence, Cartografia ou Self-Construction OS mudarem contrato de verdade.
  - Manter abaixo de 520 linhas; dividir em docs filhos quando virar implementacao.
  - Rodar docs-health depois de alterar.
related_paths:
  - docs/engineering-knowledge-base/atlas-ai-knowledge-governance-system.md
  - docs/engineering-knowledge-base/atlas-sovereign-operating-system.md
  - docs/engineering-knowledge-base/atlas-ai-documentation-operating-system.md
  - docs/engineering-knowledge-base/atlas-canonical-module-doc-v1.md
  - docs/engineering-knowledge-base/atlas-cartographic-knowledge-os.md
  - docs/engineering-knowledge-base/atlas-semantic-graph.md
  - docs/engineering-knowledge-base/code-intelligence.md
  - docs/engineering-knowledge-base/memory-core-runbook.md
  - docs/engineering-knowledge-base/open-brain-context-injection.md
  - docs/engineering-knowledge-base/atlas-ai-self-construction-os.md
  - docs/engineering-knowledge-base/self-construction/multi-provider-agent-orchestration-contract.md
  - docs/engineering-knowledge-base/atlas-ai-spec-operating-system.md
  - docs/engineering-knowledge-base/atlas-ai-cognitive-runtime.md
  - docs/engineering-knowledge-base/atlas-ai-telemetry-evidence-performance.md
doc_schema: atlas_canonical_module_doc.v1
graph_id: atlas-epistemic-operating-system
graph_title: Atlas Epistemic Operating System
graph_world: atlas
graph_layer: system
graph_kind: system
graph_parent: atlas-ai-knowledge-governance-system
graph_status: future
graph_source: repo
owner: knowledge-governance
repo_paths:
  - docs/engineering-knowledge-base/atlas-epistemic-operating-system.md
allowed_changes:
  - Evoluir especificacao de verdade, confianca, contradicao, maturidade, drift e permissoes de IA.
  - Criar docs filhos para Truth Graph, Confidence Engine, Drift Detector, Contradiction Register e Autonomous Governance Loop.
forbidden_changes:
  - Declarar Epistemic OS como implemented sem codigo, testes, ledger e endpoints reais.
  - Permitir autoimplementacao por IA baseada apenas em chat, Obsidian ou provider projection.
  - Tratar score epistemico como verdade absoluta sem evidencias e explicacao.
depends_on:
  - atlas-ai-knowledge-governance-system
  - atlas-ai-documentation-operating-system
  - code-intelligence
  - evidence-ledger
flows_to:
  - atlas-sovereign-operating-system
  - atlas-cartographic-knowledge-os
  - atlas-ai-self-construction-os
  - atlas-code
unlocks:
  - ai-safe-autonomous-evolution
  - semantic-drift-repair
  - confidence-aware-agent-permissions
governs:
  - knowledge-governance
  - self-construction
  - ai-implementation-context
  - documentation-truth
evidence:
  - docs/engineering-knowledge-base/atlas-epistemic-operating-system.md
  - docs/engineering-knowledge-base/atlas-ai-knowledge-governance-system.md
required_tests:
  - php artisan atlas:engineering:knowledge docs-health --json
requires_evidence: true
risk_level: critical
visual_tags:
  - system
  - epistemic
  - truth
  - self-evolution
ai_entrypoints:
  - Leia este doc antes de permitir que IA altere arquitetura, policy, Knowledge Governance, Cartografia ou Self-Construction OS.
  - Use os modulos e DoD como contrato de implementacao futura, nao como claim de runtime pronto.
ai_usage_notes:
  - Se nao houver score epistemico implementado, aja conservadoramente e use Knowledge Governance atual.
  - Nunca trate area de baixo nivel de evidencia como autorizada para escrita autonoma.
quality_gates:
  - php artisan atlas:engineering:knowledge docs-health --json
  - future: atlas epistemic health --json
  - future: atlas epistemic drift scan --json
failure_modes:
  - IA codar antes de saber se a verdade esta atual.
  - Cartografia mostrar mapa bonito sem confianca real.
  - Score epistemico esconder contradicao em vez de explicar.
  - Autoevolucao mexer em area que deveria ser read-only ou human-review.
observability_signals:
  - docs-health status ok
  - future: epistemic health status ok
  - future: unresolved contradictions count
  - future: stale truth claims count
next_actions:
  - Implementar Truth Graph read-only a partir de docs, codigo, testes e Evidence Ledger.
---
# Atlas Epistemic Operating System

## Resumo

Atlas Epistemic Operating System e o proximo patamar do Knowledge Governance
System. Ele nao substitui a governanca de conhecimento atual; ele a torna
computavel, auditavel e acionavel por IAs.

Knowledge Governance responde "qual fonte manda?". Epistemic OS responde "quao
confiavel, atual, provada, contraditoria, madura e acionavel e esta verdade?".

O objetivo e permitir que o Atlas seja construido, corrigido, reorganizado e
evoluido por IA sem depender de memoria solta, intuicao de agente ou leitura
manual incompleta.

Ele nao decide proposito final. Quando a pergunta deixa de ser "isso e
confiavel?" e vira "isso deve ser feito?", a autoridade sobe para
`atlas-sovereign-operating-system.md`.

## Papel no Atlas

Epistemic OS e a camada de soberania cognitiva do Atlas. Ele decide o estado de
confianca do conhecimento antes que uma IA use esse conhecimento para escrever
codigo, alterar arquitetura, propor refactor, criar AP, modificar policy ou
promover uma ideia para runtime.

Ele deve alimentar:

- Atlas Code, com contexto confiavel e limites de escrita.
- Self-Construction OS, com maturidade e permissoes por area.
- Cartographic Knowledge OS, com estados visuais de confianca, lacuna e drift.
- Open Brain, com retrieval filtrado por autoridade e freshness.
- Provider projections, com memoria segura e nao obsoleta.

## Onde Se Encaixa

```text
Repo Docs Canonicos
Codigo / Testes / Migrations
Evidence Ledger
Postgres KB
Code Intelligence
AtlasVault / Obsidian
Provider Projections
        |
        v
Knowledge Governance System
        |
        v
Atlas Epistemic Operating System
        |
        +--> Truth Graph
        +--> Confidence Engine
        +--> Drift Detector
        +--> Contradiction Register
        +--> Maturity Gates
        +--> AI Knowledge Permissions
        +--> Governance Loop
        |
        v
Cartographic Knowledge OS / Atlas Code / Self-Construction OS
```

Ele fica acima da documentacao e abaixo da execucao autonoma. O Epistemic OS nao
executa codigo de produto diretamente; ele autoriza, bloqueia, reduz escopo,
explica risco e orienta IAs antes da execucao.

Na hierarquia maior:

```text
Sovereign OS decide direcao e limites.
Epistemic OS decide confianca e acionabilidade.
Self-Construction OS executa construcao governada.
Cartographic Knowledge OS torna tudo navegavel.
```

## Contratos

1. Toda afirmacao importante precisa ter fonte, autoridade, evidencia e freshness.
2. Toda capability precisa ter maturidade e nivel de permissao para IA.
3. Toda contradicao precisa ser explicita, nao escondida em score medio.
4. Todo score precisa ser explicavel por fatores verificaveis.
5. Toda autorizacao autonoma precisa depender de evidencias e gates.
6. Todo drift entre doc, codigo, teste, ledger e projection precisa virar sinal.
7. Toda Cartografia visual precisa exibir ou herdar estado epistemico.
8. Todo provider recebe contexto filtrado por provider-safety e confianca.

## Modulos

| Modulo | Funcao | Saida principal |
|---|---|---|
| Truth Graph | Conecta claims, docs, codigo, testes, ledger e projections | grafo de verdade |
| Claim Registry | Normaliza afirmacoes importantes em unidades verificaveis | truth claim |
| Evidence Binder | Liga claim a provas mecanicas e runtime | evidence set |
| Confidence Engine | Calcula confianca explicavel por claim/capability | confidence score |
| Freshness Engine | Mede idade e validade temporal da evidencia | freshness status |
| Drift Detector | Detecta divergencia entre fonte e realidade | drift finding |
| Contradiction Register | Registra conflito entre docs, codigo ou claims | contradiction item |
| Maturity Gate Engine | Define maturidade e permissao de autoevolucao | maturity level |
| Agent Permission Matrix | Determina o que cada IA pode ler/escrever | permission envelope |
| Promotion Pipeline | Move source material para doc/AP/test/evidence | promotion packet |
| Governance Loop | Varre, prioriza e cria propostas de reparo | repair proposal |
| Epistemic API | Expoe estado para CLI, Desktop, MCP e Cartografia | query/report |

## Submodulos

### Truth Graph

O Truth Graph e o grafo interno de afirmacoes. Ele nao e apenas link entre
documentos. Ele conecta:

- claim: "Atlas Decide escolhe provider com policy e budget".
- source: doc canonico que afirma isso.
- implementation: classes, rotas, comandos e migrations relacionadas.
- tests: testes que provam comportamento.
- evidence: eventos runtime, receipts, reports ou logs.
- freshness: quando foi verificado.
- confidence: quao forte e a prova.
- contradictions: quais fontes discordam.
- owner: quem governa.
- actionability: se IA pode alterar, propor ou apenas ler.

### Claim Registry

Transforma docs longos em claims rastreaveis. Claims devem ser curtas,
enderecaveis e ligadas a fonte.

Tipos minimos:

- architecture_claim
- runtime_claim
- policy_claim
- implementation_claim
- maturity_claim
- roadmap_claim
- safety_claim
- visual_claim

### Evidence Binder

Liga claims a provas. Uma evidencia pode ser:

- arquivo canonico;
- codigo;
- migration;
- teste;
- comando executado;
- Evidence Ledger event;
- Decision Receipt;
- docs-health;
- architecture-validate;
- Code Intelligence symbol;
- human review packet.

Evidencia sem path, hash, timestamp ou comando verificavel deve valer pouco.

### Confidence Engine

Calcula confianca explicavel. O score nunca deve ser magico.

Fatores minimos:

- source_authority
- implementation_coverage
- test_coverage
- runtime_evidence
- freshness
- contradiction_penalty
- owner_clarity
- graph_link_quality
- provider_projection_freshness

Faixas sugeridas:

| Faixa | Significado |
|---|---|
| 0-30 | especulativo ou source material |
| 31-55 | documentado mas pouco provado |
| 56-75 | parcialmente implementado/provado |
| 76-90 | confiavel para execucao assistida |
| 91-100 | confiavel para automacao restrita |

### Freshness Engine

Mede se a verdade esta velha. Freshness deve considerar:

- ultima alteracao do doc;
- ultima alteracao do codigo relacionado;
- ultimo teste relevante;
- ultimo evento runtime;
- ultima projection provider-safe;
- ultima indexacao KB/Code Intelligence.

### Drift Detector

Detecta quando a realidade mudou e a documentacao nao acompanhou, ou quando doc
promete algo que codigo/teste nao prova.

Tipos:

- doc_to_code_drift
- code_to_doc_drift
- test_to_claim_gap
- ledger_to_doc_gap
- projection_to_canon_drift
- cartography_to_source_drift

### Contradiction Register

Registra conflitos explicitos. Contradicao nao e bug escondido: e entidade de
governanca.

Cada item deve conter:

- claims conflitantes;
- fontes;
- autoridade relativa;
- risco;
- owner;
- decisao necessaria;
- estado: open, accepted, repaired, superseded, archived.

### Maturity Gate Engine

Classifica areas para autoevolucao.

| Nivel | Permissao |
|---|---|
| M0 read-only | IA so le e explica |
| M1 proposal-only | IA cria proposta/AP, nao edita codigo |
| M2 assisted-write | IA edita com revisao humana obrigatoria |
| M3 supervised-autorepair | IA corrige escopo baixo com gates |
| M4 autonomous-bounded | IA executa dentro de pacote permitido |
| M5 self-evolving | IA pode propor e executar evolucao governada com ledger |

M5 nao pode existir para policy critica sem review humano ou receipt forte.

### Agent Permission Matrix

Define contexto e permissao por agente, provider, risco e area.

Exemplos:

- agente de docs pode atualizar `source_material` e docs `future`.
- agente de implementacao pode editar `allowed_files` de pacote aprovado.
- agente de review pode apontar drift e contradicao.
- agente de policy so pode propor mudanca, nao aplicar sem decisao.

### Promotion Pipeline

Move conhecimento entre estados:

```text
chat / Obsidian / pesquisa / bug / learning
        -> source material
        -> triage
        -> canonical claim
        -> doc/AP
        -> implementation
        -> evidence
        -> indexed truth
        -> cartographic node
```

### Governance Loop

Loop continuo:

1. scan docs;
2. scan codigo;
3. scan testes;
4. scan ledger;
5. scan projections;
6. calcular freshness;
7. detectar drift e contradicao;
8. recalcular confidence;
9. criar proposals;
10. atualizar Cartografia e Open Brain.

## Fluxo

```text
Nova tarefa de IA
  -> Session Bootstrap
  -> Feature Placement
  -> Epistemic Query por owner/capability
  -> Confidence + Maturity + Permissions
  -> Context Pack filtrado
  -> Execucao, proposta ou bloqueio
  -> Testes / Evidence
  -> Recalculo epistemico
  -> Cartografia atualizada
```

## Regras para IA

1. Se confidence for baixo, nao implemente como verdade; proponha ou investigue.
2. Se houver contradicao aberta em area critica, pause e peça decisao ou crie AP.
3. Se maturity for M0/M1, nao escreva codigo de runtime.
4. Se evidence estiver stale, rode validacoes antes de agir.
5. Se owner nao for claro, use Feature Placement e canonical index.
6. Se Cartografia nao mostra uma peca, corrija fonte canonica antes do visual.
7. Se projection divergir de canon, canon vence.

## Escopo de Implementacao

Fase 0: especificacao e docs canonicos.  
Fase 1: Truth Graph read-only a partir de frontmatter, related_paths e Code Intelligence.  
Fase 2: Confidence Engine simples por doc/capability.  
Fase 3: Drift Detector doc-codigo-teste.  
Fase 4: Contradiction Register e proposals.  
Fase 5: Maturity Gates conectados ao Self-Construction OS.  
Fase 6: Epistemic API para Cartografia, CLI, MCP e Atlas Code.  
Fase 7: Governance Loop periodico e repair packets.

## Definition of Done

Considerar concluido somente quando:

1. Truth Graph existe em runtime e consulta docs, codigo, testes e ledger.
2. Claims importantes possuem source, owner, evidence, confidence e freshness.
3. Confidence Engine explica seus fatores e nao apenas retorna score.
4. Drift Detector encontra divergencias reais doc-codigo-teste-projection.
5. Contradiction Register persiste conflitos e seus estados.
6. Maturity Gate Engine bloqueia ou libera escrita por IA por area.
7. Agent Permission Matrix emite envelopes consumidos por Atlas Code/Self-Construction.
8. Promotion Pipeline move source material para doc/AP/evidence com auditoria.
9. Governance Loop cria proposals verificaveis sem aplicar mudancas perigosas.
10. Cartographic Knowledge OS consome estados epistemicos para render visual.
11. CLI/MCP expõem `epistemic health`, `confidence`, `drift` e `contradictions`.
12. Tests cobrem parsing, scoring, drift, permissions e API.
13. Evidence Ledger registra recalculos importantes e reparos.
14. Provider projections indicam freshness e sao bloqueadas quando stale.
15. docs-health, architecture-validate, sync e index-code continuam verdes.

## Dependencias

- `atlas-ai-knowledge-governance-system`
- `atlas-ai-documentation-operating-system`
- `code-intelligence`
- `memory-core-runbook`
- `open-brain-context-injection`
- `atlas-ai-self-construction-os`
- `atlas-cartographic-knowledge-os`

## Evidencias

Evidencia atual: este documento e a governanca existente.  
Evidencia futura exigida: codigo, testes, commands, ledger events, APIs e
Cartografia consumindo estado epistemico real.

## Riscos

- Criar score bonito que mascara incerteza.
- Transformar governanca em burocracia que trava toda execucao.
- Autorizar IA demais cedo demais.
- Confundir visual cartografico com prova epistemica.
- Penalizar docs antigos sem considerar estabilidade de decisoes.
- Gerar loop autonomo que cria proposals demais e reduz sinal.

## Exemplos

Exemplo: uma IA quer alterar `Atlas Decide`.

Epistemic OS deve responder:

- doc dono: `atlas-ai-kernel-architecture`;
- confidence: 82;
- freshness: codigo mudou ontem, docs sincronizadas hoje;
- contradictions: uma AP antiga menciona provider routing paralelo;
- maturity: M2 assisted-write;
- permissao: pode alterar testes e services listados, mas policy exige review;
- action: implementar em branch/pacote, rodar gates e registrar evidence.

## Proximas Acoes

1. Criar AP para Truth Graph read-only.
2. Definir schema `truth_claim`, `evidence_binding`, `confidence_snapshot`.
3. Integrar com Code Intelligence e docs-health.
4. Expor primeiro endpoint read-only para Cartografia.
5. Criar UI visual de confidence/drift no Cartographic Knowledge OS.
