# BLUEPRINT — ProviderPipeUnification

> status: draft-v2.1 (2 verifies adversariais independentes — ambos SOBREVIVE; 7 emendas consolidadas)
> DELTAS do 2º verify: (d) `FaseABatteryOrchestrator:738` spawna hermes via Process SEM consult — superfície Rivals FaseA deve ser DECLARADA fora-de-escopo com receipt OU coberta pela perna estática E1; (e) `EngineeringClaudeCodeBaselineRunnerService:84` usa app(ProviderGovernanceConsult) — entra na auditoria F3 de service-location junto com o base driver L420; (f) 9 call-sites de setResolver em tests/ (Swarm×5, AutoFailover×2, Conductor incl. null) — enumerados no shim F2; o critério 6.4 escopado a app/ não os vê, a migração das suites acontece na mesma PR.
> data: 2026-07-22
> obra: GOD Debulk · cluster ROTEAMENTO
> objetivo estratégico: 3 executores (Dev/Forge/Autônomos) com o MESMO poder consumindo o MESMO seam de provider — bypass cego = 0, medido.

## 1. Contexto provado (lido no código)

### 1.1 O pipe canônico existe e já é decorado
`app/Services/Ai/AiProviderManager.php` (392 LOC, 35 refs) é o único ponto com os 4 decoradores de
governança: compressão (L124), response-cache + cost-guard (`maybeWrapWithCache` L202–237, wrap
`CachingAiProvider` L229), consulta ADML (`getRecommended` L285), medição de cobertura
(`recordCovered` L117/L328). Registry aberta por config (`atlas.ai.provider_drivers`), built-ins
nunca sobrescritos (L376).

### 1.2 A registry é DUPLICADA — e JÁ DRIFOU (verificado pelo arquiteto)
`app/Services/Ai/Provider/Drivers/ProviderDriverRegistry.php` (164 LOC) mantém 2º catálogo
`DRIVER_CLASSES` hardcoded (L14–21):

| chave | AiProviderManager | ProviderDriverRegistry |
|---|---|---|
| claude_cli / codex_cli / gemini_cli / jarvis_mlx / hermes_cli | sim | sim |
| **minimax_m27_cli** | **sim** | **NÃO — drift** |
| **claude_codex** (council) | **NÃO — drift** | **sim** |

Dois contratos distintos: `AiProvider` (execução real) vs `Kernel\Provider\ProviderDriver`
(wrapper de compliance: `prepareRequest/identityFragment`, `complianceReport` exige `executed=false`).
A função de governança da registry é valiosa e sobrevive à fusão. Consumidores:
`AtlasDecideService` L528, `AtlasDecideFrontierGeneratorService` L76, `AtlasAiArchitectureValidationService` L58.

### 1.3 Raiz da assimetria: resolver-closure livre
`AtlasSwarmExecutorService::setResolver(?Closure)` (L74–77) aceita qualquer closure. Call-sites:
produção `AppServiceProvider` L1131 → `AtlasSwarmProductionResolverService::asClosure()` (JÁ passa
por `AiProviderManager::get()` L144 + cost sentinel — o caminho bom existe; o tipo não o pina);
conductor `AtlasEngineeringRunConductorService` L187/L615 via `AtlasConductorResolverGuard::decorate(Closure)`;
smoke `AtlasSwarmExecutorCommand` L62; shadow `resolverForMode(MODE_SHADOW)` L330.

### 1.4 Muscle path Forge e o seam de consulta que JÁ existe
`AtlasForgeProviderProcessRunner` (241 LOC) grava `recordBypass` por spawn real (L127–133) EXCETO
com `governed=true` — setado por `AtlasForgeBaseCliInvocationDriver` L187 quando
`ProviderGovernanceConsult::consultBeforeSpawn()` retornou advisory. `ProviderGovernanceConsult`
(229 LOC) delega ao MESMO `AtlasDecideGatewayConsultationService` + MESMO `AiCallCostGuard` do
manager, grava `PATH_CONSULTED`, advisory-first com enforce opt-in. `PipelineRunExecutor` L1554
(gateway Dev claude) também consulta. Ledger computa `governed = covered + consulted`;
`atlas:provider:coverage` reporta por surface/provider.

**Veredito keep-separate da auditoria `forge-driver-unification-audit` RESPEITADO**: drivers CLI do
Forge não fundem no manager (streaming/worktree quebrariam). A meta é zerar o bypass *cego*
movendo todo spawn para o caminho consultado com receipt.

### 1.5 Achados laterais
- `AtlasForgeBaseCliInvocationDriver` L420 usa `app(ProviderGovernanceConsult::class)` — service
  location; container não-bootado degrada silenciosamente para `governed=false`.
- `FairClaudePolicy`: FORA do escopo (ainda referenciada por AiProviderChoiceResolver/AiGatewayService/
  AiWorker + 4 commands; des-abstração exige prova própria — obra separada).

## 2. Owners-alvo

| Peça | Owner canônico pós-obra |
|---|---|
| Catálogo de chaves de provider (fonte única) | `AiProviderManager` |
| Facet compliance/manifest dos kernel drivers | `AiProviderManager` (delegando ao `ProviderPreparedRequestValidator` existente); `ProviderDriverRegistry` vira shim `@deprecated` ≤1 ciclo e morre |
| Contrato do resolver do Swarm | interface `SwarmArmResolver` (bloco `AtlasDecide`) |
| Implementação de produção do resolver | `AtlasSwarmProductionResolverService` (única que gasta; consome `AiProviderManager::get`) |
| Spawn de processo Forge | `AtlasForgeProviderProcessRunner` — exceção governada com receipt; jamais spawn sem `ProviderGovernanceConsult` |
| Métrica de fechamento | `ProviderGovernanceCoverageLedger` + `atlas:provider:coverage` |

## 3. Grafo one-way

```
executores (Dev PipelineRunExecutor | Forge drivers | Autônomos/Swarm)
        │
        ▼
 SwarmArmResolver (interface) ──► AtlasSwarmProductionResolverService
        │                                    │
        ▼                                    ▼
 ProviderGovernanceConsult ──────────► AiProviderManager  ◄── (única registry)
        │                                    │
        ▼                                    ▼
 ProviderGovernanceCoverageLedger      AiProvider / drivers concretos
```

1. `AiProviderManager` NUNCA importa `Programming\` nem `AtlasDecide\Atlas*Executor*` — é folha de governança.
2. Nenhum executor importa driver concreto — só manager e consult.
3. Shim da registry importa `AiProviderManager::keys()` para paridade; o manager jamais importa
   `Provider\Drivers\`. O `KernelArchitectureStaticScanner` (já caça `new ClaudeCliProvider`, L275–277) ganha a regra.

## 4. Mapa mudança→ganho (teste do patamar — prova falsificável por linha)

| # | Mudança | Ganho | Prova |
|---|---|---|---|
| M1 | Manager dono único do catálogo: ganha `kernelDriverFor(key)` + `complianceReport()`; `DRIVER_CLASSES` → config `atlas.ai.kernel_driver_classes` keyed pelas MESMAS chaves; `claude_codex` entra como `compliance_only=true` com receipt | drift estrutural impossível: 1 mapa, 2 facets | teste de paridade `keys(compliance) ⊆ keys(execução) ∪ exceções` — hoje FALHA (minimax/council), vira verde |
| M2 | `interface SwarmArmResolver`; `setResolver(?Closure)` → `setArmResolver(SwarmArmResolver)`; shadow/smoke viram classes nomeadas; `AtlasConductorResolverGuard::decorate` tipado | closure livre morre; todo resolver rastreável; produção só via manager | `rg 'setResolver\(' app` = 0 pós-compat; regra no scanner |
| M3 | Forge: `ProviderGovernanceConsult` injetado por construtor no base driver (mata `app()` L420); runner com ledger obrigatório no binding de produção; todo call-site com `governed` derivado de consult real | bypass cego → 0; todo spawn consulted ou receipt de exceção | `by_surface.forge_process_runner.bypass = 0` na janela de medição |
| M4 | Executores que escolhem provider por conta própria consultam `getRecommended`/`consultBeforeSpawn` | 3 executores, mesmo poder, mesmo seam | `governed_rate = 1.0` |

NÃO faz (decisão): não roteia shell-out Forge por `AiProviderManager::get()` (keep-separate);
não toca `FairClaudePolicy`; não muda `CachingAiProvider`/compressão; não altera formato do ledger.

## 5. Ordem de migração

**F0 — Characterization**: congelar `get/getRecommended/keys` (± decoradores); snapshot
`manifest/complianceReport` (oráculo da fusão); `execute` do Swarm com resolver stub byte-idêntico;
runner governed/não-governed → consulted/bypass.

**F1 — Registry única (M1)**: config nova → facets no manager (teste de paridade fail-closed) →
shim `@deprecated` → migrar 3 consumidores de app **+ [E3] os consumidores de TESTE (`ProviderDriverWrappersTest` L275/301/326) na mesma fase** → deletar shim no ciclo seguinte **+ remover a exceção path-literal do check ap12 (scanner L285 aponta para o arquivo da registry — ficaria stale)**. Guarda: manager ~550
LOC (≤800); se estourar, facet compliance extrai para colaborador que LÊ o mapa único.

**F2 — Resolver tipado (M2)** [emendas E2]: interface + `setArmResolver(?SwarmArmResolver)` — **aceita null (reset)**: uso vivo `setResolver(null)` em `AtlasEngineeringRunConductorServiceTest:113`; `decorate` tem assinatura REAL `(Closure $inner, array $options)` — a versão tipada preserva `$options` (budget/schema/modo) e migra `AtlasConductorResolverGuardTest` na mesma fase; `setResolver` como shim 1 ciclo;
produção implementa a interface (assinatura já bate — 1 linha + morte do `asClosure`); shadow/smoke
viram classes; guard tipado NA MESMA fase (senão M2 é teatro); remover shim + regra no scanner.

**F3 — Forge exceção governada (M3)**: injeção por construtor; binding com ledger; auditar
call-sites (Cursor/Hermes drivers, PipelineRunExecutor) — todo `governed=false` vira consulta ou
receipt nomeado; janela de medição.

**F4 — Fechamento (M4)**: medir; bypass remanescente = bug com surface identificada.

F1⊥F2 (paralelizáveis); F3 depende só de F0; F4 de todas.

## 6. Critério falsificável de fechamento

`atlas:provider:coverage --reset` → rodar os 3 executores → `--json`. Fechada **sse TODAS**:
1. `by_provider.<cli>.bypass == 0` para claude/codex/gemini/hermes/minimax;
2. `governed_rate == 1.0` com `total > 0` (baseline-zero não conta);
3. teste de paridade de catálogos verde;
4. `rg 'setResolver\(' app/` vazio e `rg 'new (Claude|Codex|Gemini|Hermes|Minimax)\w*Provider' app/` só no bloco do manager/scanner;
5. **[E1 — perna ESTÁTICA anti-spawn-cego]** a métrica do ledger é vacuamente satisfazível (spawn não-instrumentado é invisível — provado: `AtlasHermesOpsCommand:79` roda o binário hermes cru com `new Process` fora do ledger; idem `SystemFleetDriver:140` e `EngineeringHarnessRunnerService:1486`). Critério adicional obrigatório: regra no scanner (ou rg de gate) por `Process|proc_open|shell_exec` combinado com binários claude/codex/gemini/hermes/minimax FORA dos seams instrumentados = 0 hits não-justificados; todo hit vira consulted ou receipt de exceção nomeada;
6. characterization F0 verde inalterada.

Qualquer uma falhando = obra aberta. Sem média, sem "quase".

## 7. Riscos

| Risco | Sev | Mitigação |
|---|---|---|
| Fusão muda semântica do `complianceReport` e afrouxa gate de arquitetura | alta | oráculo F0; validação delega ao MESMO validator, movido não reescrito |
| `claude_codex` sem lane de execução — fusão ingênua mata ou cria lane falsa | média | `compliance_only` declarado com receipt; paridade o reconhece |
| Matar `setResolver` quebra suites/smoke | média | shim 1 ciclo + migração na mesma PR |
| Guard devolver closure reabre o buraco | média | `decorate()` tipado na MESMA fase |
| Consult no Forge adicionar latência/falha no spawn | baixa | seam já é fail-open (`consultBeforeSpawn` never throws); enforce atrás de flag |
| `governed_rate` gameável (marcar sem consultar) | alta | `governed` só nasce de advisory não-nulo (padrão L187); characterization pina; receipt p/ exceções |
| Manager estourar ≤800 | baixa | facet extraível para colaborador que lê o mapa único |
