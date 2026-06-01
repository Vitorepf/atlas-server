---
id: atlas-code-reality-usage-intelligence
type: engineering_knowledge
title: Atlas Code Reality & Usage Intelligence
status: active
category: architecture-audit
priority: 100
summary: Camada canonica que prova mecanicamente se codigo, docs, rotas, comandos, testes e runtimes estao vivos, legados, scaffold, duplicados, headless ou candidatos a quarentena antes de qualquer IA implementar ou apagar algo.
human_summary: Mostra o que no codigo e real, usado, duplicado, legado ou perigoso de apagar antes de qualquer IA mexer no Atlas.
human_what: Runtime de realidade operacional que cruza codigo, docs, testes, rotas, comandos e uso real.
human_purpose: Evitar que uma IA implemente duplicado, apague codigo vivo ou confunda scaffold com produto pronto.
human_input: Recebe arquivos, referencias, testes, comandos, reachability, docs canonicas e sinais de uso runtime.
human_output: Entrega classificacao de usado, legado, duplicado, scaffold, headless, bloqueado ou candidato a quarentena.
human_change_when: Mexa quando surgir novo scanner, novo tipo de evidencia, novo fluxo Dev/Forge ou nova regra de quarentena.
human_block_when: Bloqueie quando nao houver prova de reachability, teste, fonte canonica ou plano seguro antes de apagar ou duplicar.
human_name: Realidade de Codigo e Uso
canonical_name: Atlas Code Reality & Usage Intelligence
technical_name: AtlasCodeRealityUsageIntelligenceService
cartography_type: runtime
canonical_source: docs/engineering-knowledge-base/atlas-code-reality-usage-intelligence.md
tags:
  - atlas-ai
  - code-intelligence
  - architecture-audit
  - anti-duplication
  - legacy-code
  - ai-bootstrap
capabilities:
  - code_reality_usage_intelligence
  - operational_code_truth
  - unused_code_detection
  - scaffold_classification
  - anti_duplicate_implementation_gate
  - provider_context_pack_guard
decisions:
  - Nome canonico/produto obrigatorio: Atlas Code Reality & Usage Intelligence.
  - Acronimo tecnico obrigatorio: ACRUI.
  - Nome interno de experiencia/superficie: Atlas Reality of Code.
  - Runtime tecnico atual: AtlasCodeRealityUsageIntelligenceService.
  - ACRUI nao substitui Code Intelligence, Architecture Scanner, Feature Placement, docs-authority ou SelfConstruction; ele agrega e classifica a realidade operacional.
  - Nenhuma IA pode declarar codigo morto, pronto, duplicado ou seguro de apagar sem evidencia de reachability, docs, testes e uso runtime.
  - Delecao direta e proibida; candidatos entram em quarantine plan com approval humano.
maintenance:
  - Manter abaixo de 520 linhas.
  - Atualizar quando mudarem scanner, docs-authority, Code Intelligence, Feature Placement ou matrix implemented-vs-scaffold.
  - Rodar docs-health, docs-authority-audit e architecture-validate apos alteracoes.
related_paths:
  - docs/engineering-knowledge-base/atlas-documentation-reality-system.md
  - docs/engineering-knowledge-base/atlas-ai-knowledge-governance-system.md
  - docs/engineering-knowledge-base/atlas-ai-documentation-operating-system.md
  - docs/engineering-knowledge-base/atlas-duplication-reality-governance.md
  - docs/engineering-knowledge-base/architecture-audit/implemented-vs-scaffold-matrix.md
  - docs/engineering-knowledge-base/domains/programming.md
  - docs/engineering-knowledge-base/code-intelligence.md
  - docs/engineering-knowledge-base/atlas-software-twin-verified-evolution-runtime.md
  - app/Services/Engineering/EngineeringCodeIntelligenceService.php
  - app/Services/Engineering/EngineeringDocumentationAuthorityAuditService.php
  - app/Services/Ai/Kernel/Architecture/KernelArchitectureStaticScanner.php
  - app/Services/Ai/Kernel/Architecture/AtlasArchitectureReadinessService.php
  - app/Services/Ai/Kernel/Architecture/AtlasFeaturePlacementService.php
  - app/Services/Engineering/AtlasCodeRealityUsageIntelligenceService.php
  - app/Console/Commands/AtlasCodeRealityCommand.php
  - tests/Feature/Engineering/AtlasCodeRealityUsageIntelligenceServiceTest.php
doc_schema: atlas_canonical_module_doc.v1
graph_id: atlas-code-reality-usage-intelligence
graph_title: Atlas Code Reality & Usage Intelligence
graph_world: atlas
graph_layer: system
graph_kind: module
graph_parent: atlas-ai-canonical-architecture-index
graph_status: active
graph_source: repo
owner: architecture-audit
repo_paths:
  - docs/engineering-knowledge-base/atlas-code-reality-usage-intelligence.md
allowed_changes:
  - Atualizar este doc quando codigo, arquitetura, fluxo, evidencia ou decisao canonica mudar.
forbidden_changes:
  - Criar runtime concorrente ao Code Intelligence, SelfConstruction, Architecture Scanner ou Feature Placement.
  - Declarar codigo morto sem quarantine plan e evidencia verificavel.
  - Apagar codigo automaticamente.
depends_on:
  - atlas-ai-knowledge-governance-system
  - atlas-ai-documentation-operating-system
flows_to:
  - atlas-cartography
  - atlas-code
  - programming-dev
  - programming-forge
unlocks:
  - ai-safe-implementation-context
  - anti-duplicate-implementation
  - legacy-code-quarantine
governs:
  - architecture-audit
  - code-intelligence
  - programming-domain
evidence:
  - docs/engineering-knowledge-base/atlas-code-reality-usage-intelligence.md
  - app/Services/Engineering/AtlasCodeRealityUsageIntelligenceService.php
  - app/Console/Commands/AtlasCodeRealityCommand.php
  - tests/Feature/Engineering/AtlasCodeRealityUsageIntelligenceServiceTest.php
evidence_refs:
  - symbol: EngineeringCodeIntelligenceService
  - command: atlas:engineering:knowledge
required_tests:
  - "php artisan atlas:engineering:knowledge docs-health --json"
  - "php artisan atlas:ai:docs-authority-audit --json"
  - "php artisan atlas:ai:architecture-validate --json"
  - "php artisan test tests/Feature/Engineering/AtlasCodeRealityUsageIntelligenceServiceTest.php"
requires_evidence: true
risk_level: high
visual_tags:
  - system
  - architecture-audit
  - code-reality
ai_entrypoints:
  - Leia Resumo, Contratos, Regras para IA, Classificacao Operacional, Evidencias e Riscos antes de implementar ou apagar codigo.
ai_usage_notes:
  - ACRUI classifica realidade operacional; nao executa provider, nao roda benchmark e nao deleta arquivos.
  - Termos como active_runtime, parked_scaffold, legacy_adapter, future e planned sao vocabulario classificador deste runtime quando aparecem nas tabelas/regras ACRUI; nao significam status futuro deste documento.
quality_gates:
  - "php artisan atlas:engineering:knowledge docs-health --json"
  - "php artisan atlas:ai:docs-authority-audit --json"
  - "php artisan atlas:ai:architecture-validate --json"
failure_modes:
  - IA chama scaffold de produto pronto.
  - IA chama codigo ativo de dead code.
  - IA duplica feature por nao achar owner real.
  - IA apaga adapter legado necessario.
observability_signals:
  - docs-authority-audit status
  - architecture-validate status
  - code-intelligence index freshness
next_actions:
  - Reduzir filas emitidas por ACRUI sem autorizar delecao automatica.
---
# Atlas Code Reality & Usage Intelligence
## Resumo
Atlas Code Reality & Usage Intelligence, ou ACRUI, e a camada que prova a
realidade operacional do codigo do Atlas. Ela existe porque o Atlas e
construido por varias IAs, fornecedores e sessoes diferentes; sem um mapa mecanico, qualquer IA pode confundir doc antiga, scaffold, adapter legado,
runtime ativo, duplicacao e codigo morto.
ACRUI responde:
```text
Este codigo, doc, rota, comando, teste ou runtime esta vivo, estacionado,
headless, legado, duplicado, incompleto ou morto?
```
A resposta precisa vir com evidencia, nao com leitura subjetiva.
## Papel no Atlas
ACRUI e infraestrutura de verdade operacional para Atlas Dev, Forge, Atlas AI, Code Intelligence, docs-authority, SelfConstruction, APCR, AEMOR, ASEIF, ASRE e AREG.
Ele nao e uma nova feature isolada. Ele e o gate que impede bagunca
arquitetural:
- duplicacao de flows;
- padroes divergentes;
- codigo finalizado mas nao plugado;
- scaffold vendido como produto;
- codigo ativo marcado como morto;
- docs canônicos ignorados por provider externo;
- implementacao em camada errada.
## Onde Se Encaixa
ACRUI e filho operacional do `atlas-documentation-reality-system.md`: ADRS
organiza a area inteira; ACRUI prova a realidade do codigo e da documentacao
para impedir implementacao duplicada, leitura falsa ou delecao perigosa.

ACRUI fica em `architecture-audit` e `programming.dev`, consumindo sistemas
existentes:
| Fonte | Papel |
|---|---|
| Code Intelligence | simbolos, paths, classes, relations e index de codigo |
| KernelArchitectureStaticScanner | contratos estaticos e violacoes AP |
| EngineeringDocumentationAuthorityAuditService | autoridade documental, duplicacao e overlap |
| AtlasFeaturePlacementService | owner/layer/domain/flow antes de implementar |
| Architecture Readiness | estado macro da arquitetura |
| implemented-vs-scaffold matrix | vocabulario e snapshot diagnostico |
| Evidence Ledger / traces | uso real quando disponivel |
| Tests / routes / commands | prova mecanica de reachability |
## Contratos
Nome obrigatorio:
| Campo | Valor |
|---|---|
| Nome canonico / produto | Atlas Code Reality & Usage Intelligence |
| Acronimo tecnico | ACRUI |
| Nome interno de experiencia / superficie | Atlas Reality of Code |
| Runtime tecnico | `AtlasCodeRealityUsageIntelligenceService` |
| Alias historico permitido | Atlas Operational Truth Runtime |
| Alias historico proibido como produto | AOTR |
Schema alvo:
```text
atlas.code_reality_usage_intelligence.v1
```
Comando alvo:
```bash
php artisan atlas:code-reality classify --target="<path|symbol|feature>" --json
php artisan atlas:code-reality usage-map --target="<feature>" --json
php artisan atlas:code-reality reality-audit --json
php artisan atlas:code-reality global-duplication-audit --json
php artisan atlas:code-reality reachability --target="<target>" --json
php artisan atlas:code-reality anti-duplicate --feature="<feature>" --json
php artisan atlas:code-reality deletion-preflight --target="<target>" --json
php artisan atlas:code-reality dead-code-candidates --json
php artisan atlas:code-reality context-pack --task="<task>" --json
```
Todo output deve ser read-only, deterministico e seguro para provider.
## Fluxo
Fluxo padrao antes de qualquer IA implementar:
```text
task
-> session-bootstrap
-> feature-placement
-> ACRUI anti-duplicate
-> ACRUI global-duplication-audit quando a tarefa mexe em docs/codigo/fluxos amplos
-> ACRUI usage-map
-> owner docs + live code + tests
-> implementation
-> tests + docs-health + architecture-validate
```
Fluxo antes de deletar ou arquivar:
```text
target
-> classify
-> reference scan
-> reachability scan
-> test impact scan
-> doc owner scan
-> evidence usage scan
-> quarantine plan
-> human approval
-> deletion only after prior quarantine cycle
```
ACRUI nunca deleta. Ele produz plano e blockers.
## Regras para IA
1. Nunca chame codigo de morto so porque nao achou caller na primeira busca.
2. Nunca confunda `parked_scaffold` com `dead_code_confirmed`.
3. Nunca confunda `headless_available` com inutil.
4. Nunca apague `legacy_adapter` sem migration e teste de compatibilidade.
5. Nunca crie feature nova sem `anti-duplicate`.
6. Nunca trate doc externa, chat ou relatorio de provider como verdade final.
7. Sempre verifique rota, comando, job, event, listener, test, doc owner e trace.
8. Sempre diferencie "codigo existe" de "codigo esta no fluxo padrao".
9. Sempre preserve paths de rollback e evidence antes de quarantine.
10. Sempre prefira reusar sistema existente a criar runtime paralelo.
## Escopo de Implementacao
Esta secao descreve capabilities atuais e vocabulario operacional do ACRUI:
`planned`, `future`, `scaffold`, `legacy` e `active` classificam alvo auditado;
nao declaram que esta doc esta planejada ou que o runtime ACRUI e futuro.

### Bloco 1 - Code Usage Graph
Mantem grafo de simbolos:
- classes;
- traits;
- interfaces;
- methods publicos;
- commands;
- controllers;
- routes;
- jobs;
- events/listeners;
- models/migrations;
- tests;
- frontend imports;
- package exports;
- docs owner.
### Bloco 2 - Runtime Reachability Engine
Classifica se um alvo e alcancavel por:
- HTTP route;
- Artisan command;
- queue job;
- scheduler;
- controller/service caller;
- frontend API client;
- test harness;
- control plane;
- read model;
- provider-safe context pack.

Runtime atual: `AtlasCodeRealityUsageIntelligenceService` emite
`atlas.code_reality.reachability.v1` dentro de `classify`, `usage-map` e pela
acao direta:

```bash
php artisan atlas:code-reality reachability --target="<target>" --json
```

O grafo separa sinais por `routes`, `commands`, `tests`, `owner_docs`,
`code_callers`, `config` e `database`, gera edges e declara confidence
`high|medium|low|review_required|none`. Isso ainda e read-only e nao autoriza
delete.

`reality-audit` audita o cluster ADRS/ACRUI/AURC. `global-duplication-audit`
varre docs, classes PHP, comandos Artisan, rotas estaticas, rotas registradas,
sinais de legado/scaffold e clusters criticos para listar candidatos globais de
duplicacao. Ele emite `triage_queue` com severidade e proximos comandos; e
read-only, pode retornar `blocked` quando ha candidatos reais, nao autoriza
delecao e nao prova "duplicacao zero". `deletion-preflight` nunca autoriza
delecao; ele retorna decisao, provas e sequencia obrigatoria de
quarentena/aprovacao humana.
### Bloco 3 - Usage Evidence Correlator
Cruza alvo com:
- traces;
- receipts;
- evidence packs;
- jobs recentes;
- control-plane snapshots;
- tests executados;
- code-intelligence freshness.
Quando nao existir evidencia runtime, ACRUI deve dizer `no_recent_runtime_usage`,
nao inferir morte.
### Bloco 4 - Implemented vs Scaffold Classifier
Vocabulário canonico:
| Status | Significado |
|---|---|
| `active_runtime` | usado por fluxo real ou caminho operacional padrao |
| `active_read_only` | superficie oficial de auditoria/certificacao |
| `headless_available` | funciona via CLI/API/teste, mas sem UX principal |
| `parked_scaffold` | existe de proposito e esta congelado ate gate futuro |
| `legacy_adapter` | mantido para compatibilidade |
| `duplicate_candidate` | sobrepoe capability existente e precisa merge/supersede |
| `unused_candidate` | sem caller claro, exige quarantine review |
| `dead_code_confirmed` | sem caller, teste, doc owner, evidence ou plano ativo |
| `unknown_requires_audit` | informacao insuficiente |

Regra runtime: `dead_code_confirmed` nao e emitido automaticamente pelo ACRUI.
Mesmo com reachability fraca, o output fica em `unused_candidate` ou
`unknown_requires_audit` ate quarantine review e aprovacao humana.
### Bloco 5 - Doc-Code Drift Detector
Detecta:
- doc diz ready, codigo so scaffold;
- codigo ativo sem doc owner;
- teste existe mas doc esta stale;
- comando existe mas arquitetura nao lista;
- rota existe sem policy/gate;
- product claim sem evidence.
### Bloco 6 - Anti-Duplication Gate
Antes de implementar, compara feature com:
- docs canonicos;
- classes existentes;
- comandos;
- routes;
- migrations;
- tests;
- package exports;
- names canonicos;
- capability tags.
Se score alto, output deve exigir:
```text
reuse | extend | supersede_with_decision | stop
```
### Bloco 7 - Legacy Adapter Protector
Protege compatibilidade. Um adapter legado so pode sair se:
- novo path cobre todos os callers;
- testes de regressao provam migracao;
- docs registram deprecation;
- operator aprovou quando houver risco de dados ou UX.
### Bloco 8 - Dead Code Quarantine Planner
Delecao segura tem fases:
1. `unused_candidate`;
2. `quarantine_candidate`;
3. reference scan global;
4. tests focados;
5. redirect/doc note se necessario;
6. approval humano;
7. delete em PR separado.
### Bloco 9 - Surface Parity Mapper
Compara capabilities entre:
- atlas-app;
- atlas-desktop;
- Atlas AI Desktop;
- Atlas Code;
- Atlas Dev;
- Forge;
- CLI;
- API;
- worker.
Exemplo: YouTube e rich input devem indicar se mobile/desktop/API processam o
mesmo payload canonico.
### Bloco 10 - Provider Context Pack Exporter
Gera contexto curto para Claude, Codex, Gemini ou outro provider:
- owner doc;
- paths vivos relevantes;
- paths legados que nao tocar;
- scaffold que nao vender como pronto;
- status de uso;
- testes obrigatorios;
- proibicoes;
- decisao de placement.
### Bloco 11 - Operational Reality Score
Pontua modulo por:
- reachability;
- teste;
- docs owner;
- evidence runtime;
- surface parity;
- anti-duplication;
- maturity;
- drift;
- risk.
Nota alta exige evidencia, nao volume de codigo.
### Bloco 12 - False Positive Defense
ACRUI deve ser conservador:
- se falta dado, retorna `unknown_requires_audit`;
- se ha scaffold intencional, retorna `parked_scaffold`;
- se ha CLI sem UX, retorna `headless_available`;
- se ha route/test/job, nunca retorna `dead_code_confirmed`;
- se ha adapter legado, retorna `legacy_adapter` ate migration provar remocao.
### Bloco 13 - Multi-IA Handoff Contract
Todo handoff para outra IA deve incluir:
- `classification`;
- `owner_doc`;
- `active_paths`;
- `do_not_touch_paths`;
- `reuse_first_paths`;
- `required_tests`;
- `quarantine_status`;
- `known_drifts`;
- `allowed_write_scope`.
### Bloco 14 - Architecture Debt Register
Emite registro read-only de dividas:
- duplicacao real;
- doc stale;
- scaffold esquecido;
- code owner ausente;
- runtime sem surface;
- surface sem backend canon;
- test gap;
- evidence gap.
### Bloco 15 - Product Claim Guard
Bloqueia frases como:
- "100% operacional";
- "substitui Claude/Codex";
- "dead code";
- "pronto em producao";
- "fluxo padrao";
sem evidence tuple:
```text
doc + code + caller + test + runtime/control-plane evidence
```
### Bloco 16 - Safe Implementation Navigator
Antes de programar, ACRUI deve sugerir:
- arquivo certo;
- classe existente para extender;
- teste certo;
- doc owner;
- comando de validacao;
- risco de duplicacao;
- proibicoes.
### Bloco 17 - Continuous Reality Drift Watcher
Auditoria recorrente:
- novo codigo sem doc;
- doc sem codigo;
- rota sem teste;
- command fora do architecture catalog;
- package duplicado;
- frontend divergente;
- scaffold sem owner.
### Bloco 18 - Deletion Cost Simulator
Antes de remover algo, estima impacto:
- callers quebrados;
- tests afetados;
- docs quebradas;
- migrations/models dependentes;
- surface impact;
- rollback path;
- confidence.
## Dependencias
ACRUI depende de:
- `EngineeringCodeIntelligenceService`;
- `EngineeringDocumentationAuthorityAuditService`;
- `KernelArchitectureStaticScanner`;
- `AtlasFeaturePlacementService`;
- `AtlasArchitectureReadinessService`;
- `implemented-vs-scaffold-matrix.md`;
- Evidence Ledger quando disponivel;
- docs-health;
- `rg`/filesystem scan.
Nao depende de provider externo, benchmark ou rivals.
## Evidencias
Evidencia minima para status:
| Status | Evidencia minima |
|---|---|
| `active_runtime` | caller real + teste ou trace + doc owner |
| `active_read_only` | comando/API read-only + teste + doc owner |
| `headless_available` | CLI/API/teste verde + sem UX declarada |
| `parked_scaffold` | doc/gate dizendo parked + testes de fail-closed |
| `legacy_adapter` | caller antigo + deprecation/migration plan |
| `unused_candidate` | ausencia inicial de caller + doc owner incerto |
| `dead_code_confirmed` | zero references + zero tests + zero doc owner + approval |
## Riscos
| Risco | Mitigacao |
|---|---|
| falso dead code | exigir rota/comando/test/doc/evidence scan |
| apagar adapter legado | Legacy Adapter Protector |
| duplicar scanner existente | ACRUI agrega, nao substitui |
| doc ficar grande demais | filhos por bloco se passar 520 linhas |
| IA usar score como verdade absoluta | score sempre lista evidencias e gaps |
| quarantine virar delete automatico | approval humano obrigatorio |
## Exemplos
YouTube nao deve ser classificado como morto se existem rota, job, gateway, resource e testes. O status correto pode ser:
```json
{
  "target": "YouTubeKnowledgeIngestionService",
  "classification": "active_runtime",
  "known_gap": "mobile_desktop_status_sync_latency",
  "dead_code": false
}
```
Voice/LiveKit nao deve ser classificado como produto final se docs dizem parked/scaffold. O status correto pode ser:
```json
{
  "target": "Voice Realtime Surface",
  "classification": "parked_scaffold",
  "production_ready": false
}
```
## Proximas Acoes
1. Continuar triagem das filas reais emitidas por `global-duplication-audit` e `status-drift-audit`.
2. Manter `atlas:code-reality` como gate provider-safe antes de implementacoes, delecoes e claims de limpeza.
3. Ampliar fixtures apenas quando uma fila real exigir nova classificacao ou novo boundary.
4. Rodar docs-health, docs-authority-audit, architecture-validate e teste ACRUI antes de declarar a area limpa.
