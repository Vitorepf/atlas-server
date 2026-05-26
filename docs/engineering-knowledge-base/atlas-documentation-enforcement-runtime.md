---
id: atlas-documentation-enforcement-runtime
type: engineering_knowledge
title: Atlas Documentation Enforcement Runtime
status: active
implementation_status: active_local_hard_gate_certified
implementation_boundary: ADER is implemented as a read-only pre-implementation hard gate, exposed in Architecture Operations, session-bootstrap and required validation; it aggregates canonical truth but does not create a new truth source, mutate docs/code or prove every Atlas feature complete.
category: documentation-governance
priority: 100
summary: Gate unificado que agrega docs-health, authority audit, ADRS, ACRUI e Cartografia antes de qualquer IA implementar, duplicar, deletar ou declarar capacidade documental.
human_summary: Um comando curto que diz se uma IA pode comecar a codar ou se precisa parar por documentacao, duplicacao, owner doc, Cartografia ou realidade de codigo.
human_what: Runtime de enforcement documental pre-implementacao.
human_purpose: Fazer Codex, Claude, Gemini e subagentes seguirem a documentacao canonica sem depender de memoria de conversa.
human_input: Tarefa, feature, workspace e alvos opcionais.
human_output: Status ready/review/blocked, nota, comandos obrigatorios, blockers, warnings, evidencias e hash.
human_change_when: Atualize quando mudar docs-health, ADRS, ACRUI, Cartografia, session bootstrap, feature placement ou a politica de gate para IAs.
human_block_when: Bloqueie quando docs-health falhar, autoridade documental bloquear, ADRS nao estiver pronto, ACRUI detectar duplicacao perigosa ou reachability fraco.
human_name: Fiscal de Documentacao das IAs
canonical_name: Atlas Documentation Enforcement Runtime
technical_name: AtlasDocumentationEnforcementService
cartography_type: runtime
canonical_source: docs/engineering-knowledge-base/atlas-documentation-enforcement-runtime.md
tags:
  - atlas-ai
  - documentation-governance
  - enforcement
  - ai-bootstrap
  - anti-duplication
  - hard-gate
capabilities:
  - documentation_enforcement
  - pre_implementation_gate
  - provider_context_guard
  - documentation_authority_aggregation
  - anti_duplicate_gate
  - human_cartography_boundary
decisions:
  - Nome canonico/produto obrigatorio: Atlas Documentation Enforcement Runtime.
  - Acronimo tecnico obrigatorio: ADER.
  - Nome interno de experiencia/superficie: Atlas Documentation Hard Gate.
  - Runtime tecnico atual: AtlasDocumentationEnforcementService.
  - ADER nao cria nova fonte da verdade; ele agrega as fontes canonicas existentes em uma decisao curta.
  - Status blocked proibe codigo; status review exige decisao de owner doc ou operador; status ready libera implementacao.
  - Provider projection, AGENTS.md, CLAUDE.md, Obsidian e chat nunca vencem docs canonicas do repo.
maintenance:
  - Manter abaixo de 520 linhas.
  - Atualizar junto com docs-health, ADRS, ACRUI, Cartografia, session-bootstrap e feature-placement.
  - Rodar os testes AtlasDocumentationEnforcement antes de declarar gate pronto.
related_paths:
  - docs/engineering-knowledge-base/atlas-canonical-glossary-and-naming.md
  - docs/engineering-knowledge-base/atlas-documentation-reality-system.md
  - docs/engineering-knowledge-base/atlas-code-reality-usage-intelligence.md
  - docs/engineering-knowledge-base/atlas-duplication-reality-governance.md
  - docs/engineering-knowledge-base/atlas-universal-reality-cartography.md
  - docs/engineering-knowledge-base/atlas-ai-knowledge-governance-system.md
  - docs/engineering-knowledge-base/atlas-ai-documentation-operating-system.md
  - app/Services/Engineering/AtlasDocumentationEnforcementService.php
  - app/Console/Commands/AtlasDocumentationEnforcementCommand.php
  - app/Services/Engineering/EngineeringDocumentationHealthService.php
  - app/Services/Engineering/EngineeringDocumentationAuthorityAuditService.php
  - app/Services/Engineering/AtlasDocumentationRealitySystemService.php
  - app/Services/Engineering/AtlasCodeRealityUsageIntelligenceService.php
  - tests/Feature/Engineering/AtlasDocumentationEnforcementServiceTest.php
  - tests/Feature/Engineering/AtlasDocumentationEnforcementCommandTest.php
doc_schema: atlas_canonical_module_doc.v1
graph_id: atlas-documentation-enforcement-runtime
graph_title: Atlas Documentation Enforcement Runtime
graph_world: atlas
graph_layer: system
graph_kind: module
graph_parent: atlas-documentation-reality-system
graph_status: active
graph_source: repo
owner: documentation-governance
repo_paths:
  - docs/engineering-knowledge-base/atlas-documentation-enforcement-runtime.md
allowed_changes:
  - Atualizar este doc quando o gate, comandos, status ou dependencias mudarem.
forbidden_changes:
  - Transformar ADER em fonte primaria de conhecimento.
  - Ignorar docs-health, ADRS, ACRUI ou authority audit.
  - Declarar documentacao perfeita quando o gate esta review ou blocked.
depends_on:
  - atlas-ai-knowledge-governance-system
  - atlas-documentation-reality-system
  - atlas-code-reality-usage-intelligence
  - atlas-universal-reality-cartography
flows_to:
  - session-bootstrap
  - feature-placement
  - atlas-dev
  - atlas-forge
  - atlas-cartography
unlocks:
  - provider-safe-documentation-enforcement
  - concise-ai-preflight
  - strict-documentation-gate
governs:
  - documentation-governance
  - provider-bootstrap
  - ai-implementation-sessions
evidence:
  - docs/engineering-knowledge-base/atlas-documentation-enforcement-runtime.md
  - app/Services/Engineering/AtlasDocumentationEnforcementService.php
  - app/Console/Commands/AtlasDocumentationEnforcementCommand.php
required_tests:
  - "php artisan test --filter=AtlasDocumentationEnforcement"
  - "php artisan atlas:documentation:enforce --task=\"<task>\" --feature=\"<feature>\" --json"
  - "php artisan atlas:engineering:knowledge docs-health --json"
  - "composer atlas:docs-gate"
requires_evidence: true
risk_level: high
product_name: Atlas Documentation Enforcement Runtime
runtime_acronym: ADER
internal_product_name: Atlas Documentation Hard Gate
technical_runtime: AtlasDocumentationEnforcementService
visual_tags:
  - documentation
  - enforcement
  - hard-gate
ai_entrypoints:
  - Leia Resumo, Contratos, Regras para IA, Fluxo, Evidencias e Riscos antes de implementar.
ai_usage_notes:
  - ADER deve ser executado antes de codigo quando a tarefa mexe em docs, arquitetura, runtime, surface, Cartografia, Dev, Forge ou memoria.
quality_gates:
  - "php artisan atlas:documentation:enforce --task=\"<task>\" --feature=\"<feature>\" --strict --json"
  - "php artisan atlas:documentation-reality acceptance --strict --json"
  - "php artisan atlas:code-reality reality-audit --json"
  - "composer atlas:docs-gate"
failure_modes:
  - IA ignora owner doc por confiar em conversa.
  - IA duplica runtime porque nao rodou anti-duplicate.
  - IA declara capacidade pronta sem docs-health e evidencia.
  - IA muda Cartografia como se fosse fonte primaria.
observability_signals:
  - ADER status
  - ADER score
  - blockers_count
  - review_count
  - warnings_count
  - certification_hash
next_actions:
  - Adicionar receipt de execucao ADER em runs Dev/Forge.
  - Manter Architecture Operations, session-bootstrap e feature-placement sincronizados quando o comando mudar.
---
# Atlas Documentation Enforcement Runtime
## Resumo
Atlas Documentation Enforcement Runtime, ou ADER, e o gate curto que uma IA
deve executar antes de implementar. Ele existe para responder:

```text
Posso codar agora sem ignorar documentacao canonica, duplicar fluxo ou mexer
em uma area sem owner doc?
```

ADER nao substitui a documentacao. Ele agrega as fontes canonicas existentes e
materializa uma decisao unica: `ready`, `review` ou `blocked`.

## Boundary Atual
Em 2026-05-25, ADER esta ativo como hard gate local read-only. O comando
`atlas:documentation:enforce --strict --json` retorna `ready`, `score=10` e
`grade=elite` quando docs-health, authority audit, ADRS, ACRUI, Cartografia,
session-bootstrap e feature-placement estao limpos. Architecture Operations e
session-bootstrap ja publicam ADER como operacao/validacao obrigatoria.

ADER nao vira fonte primaria: docs canonicas continuam autorais; codigo,
migrations e testes provam implementacao; Evidence Ledger prova runtime; KB,
Code Intelligence, Obsidian e provider projections sao read models/superficies.
ADER tambem nao prova que todas as features do Atlas existem ou estao prontas.

## Papel no Atlas
O Atlas e construido por multiplas IAs. Isso cria risco real de:
- repetir uma feature que ja existe;
- confiar em conversa antiga;
- tratar scaffold como produto pronto;
- ignorar owner doc;
- quebrar Cartografia ou documentacao por excesso de texto;
- declarar uma capacidade sem evidencia.

ADER reduz esse risco criando um preflight unico para Atlas Dev, Forge,
Cartografia, docs, memoria, contexto e runtimes.

## Enforcement Local
O comando ADER e o docs-health tambem sao expostos como gate local versionado.
O operador pode instalar o hook com:

```bash
composer atlas:install-hooks
```

Depois disso, qualquer commit com mudancas staged em `app/`, `routes/`,
`database/`, `config/`, `tests/`, `docs/engineering-knowledge-base/`,
`composer.json`, `composer.lock` ou `artisan` roda:

```bash
git diff --cached --check
php artisan atlas:engineering:knowledge docs-health --json
php artisan atlas:documentation:enforce --task="pre-commit canonical documentation gate" --feature="canonical documentation and implementation governance" --strict --json
```

Esse hook nao transforma ADER em fonte da verdade. Ele so torna a obrigacao
mais dificil de ignorar antes de persistir codigo ou documentacao canonica.

## Onde Se Encaixa
ADER fica acima dos sistemas documentais existentes:

| Sistema | Papel |
|---|---|
| Docs-health | Verifica frontmatter, docs obrigatorios, warnings e tamanho |
| Authority audit | Verifica autoridade, duplicacao e gaps de owner |
| ADRS | Mantem a area de documentacao/verdade/Cartografia organizada |
| ACRUI | Prova realidade de codigo, reachability e duplicacao |
| AURC | Mantem a fronteira visual humana sem virar fonte primaria |

ADER e um agregador read-only. Se uma fonte canonica apontar risco, ADER nao
maquia o status.

## Contratos
| Campo | Valor |
|---|---|
| Nome canonico / produto | Atlas Documentation Enforcement Runtime |
| Acronimo tecnico | ADER |
| Nome interno de experiencia / superficie | Atlas Documentation Hard Gate |
| Runtime tecnico | `AtlasDocumentationEnforcementService` |
| Schema | `atlas.documentation_enforcement.v1` |
| Comando | `php artisan atlas:documentation:enforce --task="<task>" --feature="<feature>" --strict --json` |

Status:
- `ready`: pode implementar.
- `review`: nao e falha tecnica, mas exige decisao/documentacao antes de claim forte.
- `blocked`: nao pode codar.

## Fluxo
Fluxo minimo antes de codigo:

```text
task humana
-> ADER
-> session-bootstrap
-> feature-placement
-> owner docs
-> implementacao
-> testes
-> docs-health
-> evidence
```

Comandos obrigatorios:

```bash
php artisan atlas:documentation:enforce --task="<task>" --feature="<feature>" --strict --json
php artisan atlas:ai:session-bootstrap --task="<task>" --strict --json
php artisan atlas:ai:place-feature "<feature>" --strict --json
php artisan atlas:engineering:knowledge docs-health --json
```

## Regras para IA
- Nao implemente se ADER retornar `blocked`.
- Nao declare "pronto/perfeito/completo" se ADER retornar `review`.
- Nao confie em chat, Obsidian, AGENTS.md ou CLAUDE.md contra docs canonicas.
- Nao crie runtime novo sem `place-feature` e ACRUI anti-duplicate.
- Nao altere Cartografia como fonte primaria; Cartografia e superficie humana.
- Nao apague ou deprecie arquivo sem ACRUI reachability/deletion-preflight.

## Escopo de Implementacao
ADER cobre:
- docs canonicas;
- owner docs;
- line limits e warnings;
- ADRS 52 blocos;
- ACRUI realidade de codigo;
- anti-duplicacao por feature;
- comandos obrigatorios antes de codigo;
- hash deterministico do relatorio.

ADER nao cobre:
- execucao de provider;
- benchmark externo;
- Rivals;
- mutacao de arquivos;
- correcao automatica de docs.

## Dependencias
ADER depende de services existentes:
- `EngineeringDocumentationHealthService`
- `EngineeringDocumentationAuthorityAuditService`
- `AtlasDocumentationRealitySystemService`
- `AtlasCodeRealityUsageIntelligenceService`

## Evidencias
Evidencia minima de saude:

```bash
php artisan test --filter=AtlasDocumentationEnforcement
php artisan atlas:documentation:enforce --task="<task>" --feature="<feature>" --json
php artisan atlas:engineering:knowledge docs-health --json
```

O relatorio sempre deve expor:
- `status`;
- `score`;
- `subarea_scores`;
- `blockers`;
- `review_items`;
- `warnings`;
- `required_before_code`;
- `certification_hash`.

## Riscos
| Risco | Como ADER bloqueia |
|---|---|
| Economia de contexto ruim | Exige owner docs e docs-health |
| IA duplica feature | Usa ACRUI anti-duplicate |
| Doc antiga vira autoridade | Usa hierarchy e authority audit |
| Cartografia vira fonte primaria | Declara boundary explicito |
| Claim sem evidencia | Exige comandos e hash |

## Exemplos
Para avaliar uma tarefa de documentacao:

```bash
php artisan atlas:documentation:enforce \
  --task="melhorar documentacao e governanca" \
  --feature="documentation enforcement runtime" \
  --json
```

Para bloquear qualquer implementacao ate o gate estar limpo:

```bash
php artisan atlas:documentation:enforce \
  --task="melhorar documentacao e governanca" \
  --feature="documentation enforcement runtime" \
  --strict \
  --json
```

## Proximas Acoes
- Adicionar receipt de execucao ADER em runs Dev/Forge.
- Manter Architecture Operations, session-bootstrap e feature-placement
  sincronizados quando o contrato do comando mudar.
