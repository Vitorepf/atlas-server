---
id: atlas-duplication-reality-governance
type: engineering_knowledge
title: Atlas Duplication Reality Governance
status: active
category: documentation-governance
priority: 100
summary: Politica canonica que consolida como Atlas detecta e bloqueia duplicacao entre docs, features, runtimes, codigo, status implementado/scaffold e fluxos de IA.
human_summary: Define o caminho unico para provar se algo esta duplicado ou se deve reutilizar doc, runtime ou feature existente antes de qualquer IA codar.
human_what: Governanca anti-duplicacao para documentacao, codigo e features.
human_purpose: Impedir que multiplas IAs criem caminhos paralelos, documentacao concorrente ou claims falsos sobre o que esta implementado.
human_input: Recebe tarefa, feature, alvo de codigo, owner docs, outputs de ADER, docs-authority, ACRUI, docs-health, bootstrap e placement.
human_output: Entrega decisao de reuse, extend, supersede, review ou blocked com evidencia e comandos obrigatorios.
human_change_when: Atualize quando mudar ADER, ACRUI, docs-authority, session-bootstrap, feature-placement ou regras de status implementado/scaffold.
human_block_when: Bloqueie quando houver duplicate id/runtime, owner gap, overlap sem decisao de owner, codigo sem reachability ou doc que declare implementado sem prova.
tags:
  - atlas-ai
  - documentation
  - anti-duplication
  - code-reality
  - feature-placement
  - enforcement
capabilities:
  - duplication_reality_governance
  - anti_duplicate_documentation_gate
  - anti_duplicate_feature_gate
  - implemented_vs_scaffold_truth
  - ai_safe_reuse_decision
decisions:
  - Este doc nao cria runtime novo; ele organiza ADER, ADRS, ACRUI, docs-authority, bootstrap e placement.
  - Duplicacao real em docs canonicos e bloqueio; overlap contextual e sinal de review ate owner decidir reuse, extend ou supersede.
  - Codigo so pode ser chamado de duplicado, morto, pronto ou scaffold com evidencia de reachability, testes, docs e owner.
  - Se doc diz que algo nao esta implementado e o codigo prova o contrario, o estado canonico entra em drift e deve ser corrigido antes de claim forte.
maintenance:
  - Manter abaixo de 520 linhas.
  - Atualizar snapshot apos mudancas relevantes em docs, ACRUI ou ADER.
  - Rodar ADER strict, docs-health, docs-authority-audit e ACRUI reality-audit apos alteracoes.
related_paths:
  - docs/engineering-knowledge-base/atlas-documentation-reality-system.md
  - docs/engineering-knowledge-base/atlas-code-reality-usage-intelligence.md
  - docs/engineering-knowledge-base/atlas-documentation-enforcement-runtime.md
  - docs/engineering-knowledge-base/atlas-ai-documentation-operating-system.md
  - docs/engineering-knowledge-base/atlas-ai-knowledge-governance-system.md
  - docs/engineering-knowledge-base/architecture-audit/implemented-vs-scaffold-matrix.md
  - app/Services/Engineering/EngineeringDocumentationAuthorityAuditService.php
  - app/Services/Engineering/AtlasCodeRealityUsageIntelligenceService.php
  - app/Services/Engineering/AtlasDocumentationEnforcementService.php
  - app/Services/Ai/Kernel/Architecture/AtlasFeaturePlacementService.php
doc_schema: atlas_canonical_module_doc.v1
graph_id: atlas-duplication-reality-governance
graph_title: Atlas Duplication Reality Governance
graph_world: atlas
graph_layer: system
graph_kind: policy
graph_parent: atlas-documentation-reality-system
graph_status: active
graph_source: repo
owner: documentation-governance
repo_paths:
  - docs/engineering-knowledge-base/atlas-duplication-reality-governance.md
allowed_changes:
  - Atualizar regras, snapshot e comandos quando os gates anti-duplicacao mudarem.
forbidden_changes:
  - Transformar este doc em segunda fonte de verdade ou runtime paralelo ao ACRUI/ADER.
  - Declarar duplicacao zero fora do escopo provado pelos comandos.
  - Declarar implementado sem codigo, teste, comando, rota, migration ou evidence verificavel.
depends_on:
  - atlas-documentation-reality-system
  - atlas-code-reality-usage-intelligence
  - atlas-documentation-enforcement-runtime
flows_to:
  - session-bootstrap
  - feature-placement
  - atlas-dev
  - atlas-forge
  - atlas-cartography
unlocks:
  - ai-safe-reuse-before-implementation
  - duplicate-free-documentation-governance
  - implemented-vs-scaffold-drift-control
governs:
  - documentation-governance
  - architecture-audit
  - programming-domain
evidence:
  - docs/engineering-knowledge-base/atlas-duplication-reality-governance.md
  - app/Services/Engineering/EngineeringDocumentationAuthorityAuditService.php
  - app/Services/Engineering/AtlasCodeRealityUsageIntelligenceService.php
required_tests:
  - "php artisan atlas:ai:docs-authority-audit --json"
  - "php artisan atlas:code-reality reality-audit --json"
  - "php artisan atlas:documentation:enforce --task=\"<task>\" --feature=\"<feature>\" --strict --json"
  - "php artisan atlas:engineering:knowledge docs-health --json"
requires_evidence: true
risk_level: critical
visual_tags:
  - documentation
  - anti-duplication
  - code-reality
ai_entrypoints:
  - Leia este doc antes de criar doc macro, runtime, feature, fluxo, comando, surface ou claim de implementado/scaffold.
ai_usage_notes:
  - Use os comandos deste doc como gate; nao use chat ou busca parcial para declarar que algo nao existe.
quality_gates:
  - "php artisan atlas:documentation:enforce --task=\"<task>\" --feature=\"<feature>\" --strict --json"
  - "php artisan atlas:ai:docs-authority-audit --json"
  - "php artisan atlas:code-reality anti-duplicate --feature=\"<feature>\" --json"
failure_modes:
  - IA cria feature paralela porque nao reconheceu owner doc.
  - Doc declara planned enquanto codigo esta ativo.
  - Codigo ativo e chamado de morto por falta de reachability scan.
  - Overlap contextual e confundido com duplicacao real sem decisao de owner.
observability_signals:
  - docs-authority duplicate group counts
  - ACRUI anti-duplicate match_count
  - ACRUI reality-audit unknown_or_unused_count
  - ADER status and score
next_actions:
  - Evoluir ACRUI para relatorio global de duplicate feature candidates por simbolo, rota, comando, capability e owner doc.
---
# Atlas Duplication Reality Governance
## Resumo
Atlas Duplication Reality Governance e a politica que impede IAs de criarem
documentacao, features, comandos, runtimes ou fluxos paralelos quando ja existe
um caminho canonico.

Ela nao substitui ADRS, ACRUI ou ADER. Ela explica como usar esses gates para
diferenciar duplicacao real, overlap contextual, scaffold, implementado e drift.

## Papel no Atlas
Documentacao e a camada mais critica do Atlas porque o projeto e construido por
multiplas IAs. Se a documentacao nao condiz com codigo, testes e evidence, a IA
seguinte implementa no lugar errado ou duplica o que ja existe.

Esta politica responde:

```text
Isso ja existe?
Qual owner doc manda?
Existe runtime/codigo/teste provando implementacao?
A doc esta atrasada em relacao ao codigo?
Devo reutilizar, extender, supersede ou bloquear?
```

## Onde Se Encaixa
```text
ADER hard gate
  -> docs-authority-audit: duplicacao documental canonica
  -> feature-placement: owner/layer/surface/runtime
  -> ACRUI anti-duplicate: duplicacao por feature
  -> ACRUI reachability/reality-audit: realidade de codigo
  -> implemented-vs-scaffold matrix: vocabulario de status
```

ADRS e o doc mae. ACRUI prova codigo. ADER bloqueia ou libera. Este doc e o
contrato operacional anti-duplicacao.

## Contratos
Tipos de duplicacao:

| Tipo | Bloqueio |
|---|---|
| `duplicate_doc_id` ou `duplicate_graph_id` | blocked |
| `duplicate_technical_runtime` | blocked |
| capability overlap cross-owner sem decisao | review |
| feature com match ACRUI | review ate reuse/extend/supersede |
| codigo sem reachability mas doc diz implemented | blocked |
| doc diz planned/scaffold mas codigo ativo prova runtime | review/drift |

## Fluxo
Antes de criar qualquer doc, feature, runtime, comando ou surface:

```bash
php artisan atlas:documentation:enforce --task="<task>" --feature="<feature>" --strict --json
php artisan atlas:ai:session-bootstrap --task="<task>" --json
php artisan atlas:ai:place-feature "<feature>" --json
php artisan atlas:ai:docs-authority-audit --json
php artisan atlas:code-reality anti-duplicate --feature="<feature>" --json
php artisan atlas:code-reality reachability --target="<target>" --json
```

Decisao:
- `reuse`: usar doc/runtime existente.
- `extend`: adicionar capacidade no owner existente.
- `supersede`: substituir com decisao explicita e doc antigo apontando para o novo.
- `review`: owner/operator decide antes de claim forte.
- `blocked`: nao implementar.

## Regras para IA
1. Nao crie feature nova se `place-feature` ou ACRUI apontar owner existente.
2. Nao trate lista de candidatos do bootstrap como duplicacao real; ela e triagem.
3. Nao declare duplicacao zero global fora dos escopos que os comandos provaram.
4. Nao use `rg` sozinho para chamar codigo de morto ou ausente.
5. Nao declare implementado se nao houver codigo, teste, comando, rota, migration
   ou evidence verificavel.
6. Se docs e codigo divergem, registre drift e corrija a fonte canonica.
7. Se existir overlap cross-owner, pare e peça decisao de reuse, extend ou
   supersede.

## Escopo de Implementacao
Snapshot real em 2026-05-25:

| Gate | Resultado |
|---|---|
| `docs-health` | 789 docs, 0 warnings, 0 oversized, 0 frontmatter violations |
| `docs-authority-audit` | 733 docs canonicos, 0 identity duplicate groups, 0 runtime duplicate groups, 0 capability overlap groups, 0 owner gaps |
| `ACRUI reality-audit` | 6 targets ADRS/ACRUI/AURC, 6 active_runtime, 0 unknown_or_unused, 0 weak_reachability |
| `ACRUI anti-duplicate` para esta feature | `match_count=0`, `decision=proceed_with_owner_lookup` |
| `session-bootstrap/place-feature` | encontrou overlap contextual e bloqueou ate leitura dos owner docs |

Interpretacao: nao ha duplicacao canonica provada nos gates atuais. Ha overlap
contextual suficiente para exigir owner lookup, o que e comportamento correto.

## Dependencias
- `EngineeringDocumentationAuthorityAuditService`
- `AtlasCodeRealityUsageIntelligenceService`
- `AtlasDocumentationEnforcementService`
- `AtlasFeaturePlacementService`
- `EngineeringDocumentationHealthService`

## Evidencias
Comandos usados para este snapshot:

```bash
php artisan atlas:ai:docs-authority-audit --json
php artisan atlas:code-reality reality-audit --json
php artisan atlas:code-reality anti-duplicate --feature="documentation and code duplication governance audit" --json
php artisan atlas:engineering:knowledge docs-health --json
```

## Riscos
- ACRUI ainda nao tem contador global exaustivo de features duplicadas por
  semantica; hoje o gate e por feature/alvo e por autoridade documental.
- Read models podem ficar stale se `sync --prune` e `index-code --prune` nao
  rodarem depois de alteracoes.
- Source material arquivado pode parecer duplicado, mas nao e canonico.

## Exemplos
Se a IA quer criar "novo runtime de documentacao":

```text
ADER ready -> place-feature aponta documentation-governance -> ACRUI match
review -> extender ADER/ACRUI/ADRS em vez de criar runtime paralelo
```

Se a doc diz `planned`, mas existe comando com teste:

```text
ACRUI reachability high -> doc entra em drift -> atualizar status/evidence
antes de usar a doc como contexto para outra IA
```

## Proximas Acoes
1. Adicionar relatorio ACRUI global de duplicacao por feature/capability.
2. Fazer ADER consumir esse relatorio quando existir.
3. Expor contador de drift implemented-vs-scaffold por owner doc.
4. Atualizar KB e Code Intelligence apos merge desta politica.
