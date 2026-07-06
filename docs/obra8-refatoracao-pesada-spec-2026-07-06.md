# Obra #8 (proposta) — Refatoração Pesada: consolidação, reuso, simplificação e redução de LOC

Data: 2026-07-06 · Status: **spec aprovável** (nenhuma linha de produção tocada ainda)
Método: 12 finders por dimensão sobre `app/` (1,7M LOC) e `tests/` (1,2M LOC) → 98 achados brutos → dedup → **verificação adversarial independente** dos 44 maiores (cada verificador tentou REFUTAR o achado lendo código real e rodando greps/scripts próprios). 56 agentes no total.

## Placar

| Categoria | Itens | LOC líquidas (ajustadas pelo verificador) |
|---|---|---|
| **Confirmados** (sobreviveram a todos os cheques) | 19 | **~27.600** |
| **Condicionais** (reais, mas exigem re-escopo/pré-condição) | 19 | até ~24.100 (encolhe após escopo) |
| **Refutados** (NÃO fazer — registrar para não re-propor) | 6 | 0 |
| Menores (<150 LOC, não verificados) | 54 | ~2.800 |

Potencial total honesto: **~30k LOC confirmadas+menores, teto ~55k** com os condicionais — sem deletar nenhum comportamento, assert ou gate.

---

## Regras pétreas da obra (valem para TODA slice)

1. **Byte-identidade de saída.** Nenhum payload, hash, ordem de chave, mensagem ou exit code muda. Onde teste atual não congela o valor (só formato), gravar **golden-hash/snapshot ANTES** do rewrite (Fase 0).
2. **Nunca deletar assert/cobertura.** Consolidação de teste preserva contagem de testes E de assertions (before/after no runner).
3. **Conversões em massa são scriptadas**, nunca manuais (ex.: 877 métodos num arquivo de 44k linhas), com diff de prova.
4. **Coordenação com Obra #7 em voo**: arquivos modificados no working tree hoje (`AtlasMemoryRegistryService`, `AgentControlPlaneChainIntegrityAuditService`, `AtlasOpenBrainMcpService*`, etc.) só entram após o merge da Obra #7 — colisão proibida.
5. **HOT_SCOPE do Kernel**: `KernelArchitectureStaticScanner.php` está em `ReadinessCatalog::HOT_SCOPE_FILES` (must_not_touch da esteira) — as slices R-10/R-13 são **lane do operador**, nunca da esteira autônoma.
6. **Esta obra NÃO entra na esteira do Loop** como task de autonomia: refactor LOC-cosmético é proxy vetado (guardrail anti-Goodhart). Lane: operador + provider sob revisão.
7. Vetos herdados intactos: sem fusão de drivers Forge (keep-separate provado), sem "remover lógica do núcleo" (Obra #6 refutou), sem tocar semântica de hashing/ordenação (famílias SORT_STRING/unset/substr ficam como estão), sem tocar área de Medição, sem editar `Aaeos/Generated/` à mão.
8. Órfãos 0-ref = órgãos unwired: qualquer deleção exige tripla prova (supersede + zero callers + cobertura preservada).
9. Cada slice = commit próprio na main com teste verde; push só com OK do operador.

---

## Fase 0 — Infra de prova (pré-requisito das Ondas 2-4)

- **F0.1 Golden-hash harness das Readiness sections.** Os testes atuais das seções são de WIRING (method_exists + contagem), não congelam payload. Gravar snapshot byte-exato (payloads blocked+ready) dos métodos das seções `ReadinessProjection*` antes de qualquer rewrite. Atenção: `ReadinessHash::stable` só ksorta o top-level — ordem aninhada afeta o sha256 e hashes encadeiam entre passos.
- **F0.2 Baseline de vermelhos pré-existentes.** Registrar falhas já existentes (ex.: `AtlasAiSelfConstructionAgentDispatchExecutorReceiptUseWriterTest.php:177`) para não atribuí-las à obra.
- **F0.3 Snapshot before/after de `tools()`** do `AtlasOpenBrainMcpService` (var_export/json hash) — pré-condição da C-12.

---

## Onda 1 — Testes (risco BAIXO, ~14.000 LOC)

### R-01 · Data providers no mega-arquivo de teste — **~7.000 LOC**
`tests/Feature/Ai/AtlasAiSelfConstructionCommandTest.php` (43.997 linhas, 1.446 testes). Verificador achou 71 grupos com corpo byte-idêntico (877 métodos, 22.702 linhas). Converter cada grupo em método genérico + `#[DataProvider]` com rows nomeadas.
**Constraints:** (a) named data sets DEVEM preservar os substrings exatos usados pelos ~30+ comandos advisory `--filter=` embutidos em produção (`ReadinessProjectionAgentCodexSection.php:193`, `AtlasSelfConstructionReadinessService.php:13356`); (b) conversão scriptada com prova de contagem idêntica de testes/assertions; (c) grupos JSON usam rows tipadas (same/false/true/contains/regex) — não achatar em assertSame.

### R-02 · Trait factory `makeAgentRun()` — **~2.400 LOC**
145 blocos de criação de `AtlasSelfConstructionAgentRun` com ~24 colunas-base idênticas em 118 arquivos `tests/Feature/Ai/AtlasAiSelfConstructionAgent*`. Criar `tests/Concerns/MakesSelfConstructionAgentRuns.php` (padrão da casa: 48 traits `Creates*Tables` já existem); cada bloco de ~30 linhas vira 4-6 linhas de overrides. Asserts intocados. Registrar baseline F0.2 antes.

### R-03 · Concern `CreatesSelfConstructionControlPlaneTables` — **~2.385 LOC**
Trio setUp/tearDown/dropTables byte-idêntico (provado por hash) em 110/125 arquivos da família. Extrair concern com as migrations 2026_05_05_020000 (ledger) + 2026_05_12_010000 (control plane). Os 15 variantes (tabelas/migrations extras) ficam fora da leva 1 ou ganham variante própria.

### R-04 · `File::deleteDirectory()` no lugar de 96 rmrf/rrmdir — **~1.400 LOC**
96 arquivos em tests/ (64 rmrf + 32 rrmdir, 1.596 linhas) reimplementam delete recursivo. O próprio mega-arquivo já usa `File::deleteDirectory` (precedente interno).
**Constraints:** escopo SÓ tests/ (as 6 cópias em app/ ficam fora desta slice); `AtlasDevHttpTestCase::rmrf` é protected herdado por 2 subclasses — cobrir os call sites ou manter wrapper; rodar cada teste afetado verde após o swap.

### R-05 · Concerns `Creates*Tables` para Schema::create inline duplicado — **~800 LOC**
Clusters byte-idênticos entre arquivos: atlas_projects (17×29 linhas), ai_threads, ai_messages, atlas_tool_runs, atlas_engineering_runs, atlas_engineering_evidence, ai_traces.
**Constraints:** corrigir premissa do finder — migrations standalone EXISTEM para as 7 tabelas; criar métodos novos hasTable-guarded byte-a-byte (não reusar concerns existentes com semântica drop-recreate divergente); só clusters byte-idênticos.

---

## Onda 2 — Helpers mecânicos (risco BAIXO, ~3.400 LOC)

### R-06 · `AtlasEnvelope::seal()` — **~1.150 LOC**
459 sítios exatos do trio `payload + MissionCanonicalHash::sha256(payload) + return` em 137 arquivos (top: `ExternalActionMandateRegistryService` 164, `EnterpriseFlowFixtureActionRuntimeService` 51). Builder de ~15 linhas em `app/Support/AtlasEnvelope.php`: `seal(array $payload, string $hashKey)`. Schema fica inline no payload (posições variam — NÃO fazer `make(schema, payload)`).
**Constraints:** excluir os 5 arquivos da área de Medição (12 sítios); lotes nos 2 arquivos de Holding rodam `AutonomousHoldingEnterpriseCommandTest` (única cobertura).

### R-07 · `complianceReport()` table-driven — **~700 LOC**
`KernelArchitectureStaticScanner.php`: 1.054 linhas de triplicação mecânica (atribuição + cadeia `&&` + return de 4 linhas por scan, 166 scans). Const `AP_SCANS = [key => método]` + loop de ~12 linhas. Nenhum scan/lógica removido (Obra #6 respeitada — os 167 `scanX()` ficam intocados).
**Constraints:** relaxar o `@return array{...}` de 168 linhas (PHPStan level 5 rejeita shape montado em loop); lane do operador (HOT_SCOPE, regra 5).

### R-08 · Trait `EmitsCanonicalJson` — **~400 LOC**
Bloco `json_encode(PRETTY|UNESCAPED_SLASHES|UNESCAPED_UNICODE)` repetido 959× em app/Console/Commands + 58 helpers privados `encode()`/`printJson()`/`emitJson()` idênticos. Trait com `jsonLine()`/`jsonLineCompact()`.
**Constraints:** migrar só os combos exatos (856 + 103 ordem invertida — OR comutativo, bytes idênticos; os 502 SLASHES|UNICODE sem PRETTY na variante compact); NUNCA migrar combos com THROW_ON_ERROR/SLASHES-only; os 241 sites com fallback `?: '{}'` exigem variante própria ou ficam fora da leva 1; NÃO reusar `EncodesPayloadAsPrettyJson` (tem THROW_ON_ERROR — semântica diferente, documentar coexistência).

### R-09 · Scanner: helper `missingTokenViolations()` — **~330 LOC** (teto file-wide ~3.000)
3 piores métodos scan (`scanVoiceRealtimeProductionPromotionGate` 1.205 ln, +2) têm 45+34+17 grupos foreach 100% uniformes. Dois helpers privados no padrão do já-existente `scanPhpFilesForForbiddenTokens` (linha 7804). Nenhum token/path/mensagem muda. Extensível aos demais 160 métodos depois. Lane do operador (regra 5).

### R-10 · Buildout `readiness()` → tabela de regras — **~320 LOC**
`AutonomousHoldingEnterpriseBuildoutService.php:9599` — 809 linhas de expressão booleana única. Tabela `[path, op, threshold|ref]` + avaliador ~30 linhas.
**Constraints (do verificador):** 7 tipos de op (não 3) — incluindo 196 comparações com refs dinâmicas a 6 campos, 61 bool===false, ~5 thresholds compostos; rows gate-only e report-only; preservar ORDEM das chaves do retorno.

### R-11 · Aposentar `AtlasLoopFrozenTestContentBuilder` — **~280 LOC** (deleção com tripla prova)
Gêmeo byte-idêntico do `AtlasLoopFrozenTestSourceRenderer` (295 LOC espelho). Tripla prova completa: supersede = Renderer (resolvido em runtime), zero callers (só o param morto nullable em `AtlasLoopIntentVerifierFactory:34`, tudo via `app()`), equivalência coberta por teste que roda verde hoje. Remover também o param morto do construtor; converter testes de equivalência em golden do Renderer.

### R-12 · Migrar cópias byte-idênticas de `stableHash` para os traits canônicos — **~120 LOC**
26 arquivos exatos (5 puros → `HashesPayloadCanonically`; 21 ksort → `HashesKsortedPayloadCanonically`, mantendo `RecursivelyKsortsArrays` em 19/21). Output byte-idêntico — veto de hashing NÃO violado (o docblock dos traits registra a auditoria).
**Constraints:** NÃO tocar os 91 restantes (unset/flags/substr/SORT_STRING — veto b756f06704).

### R-13 · `GitSubprocess::run()` compartilhado — **~90 LOC**
~23 clones de `new Process(array_merge(['git'], $argv), $cwd, $env, null, $timeout)` entre AutonomousEvolution e SCS. Cada classe MANTÉM seu `git()` privado como adapter de 1-3 linhas (shape de retorno próprio preservado).
**Constraints:** excluir 3 sites que não batem (shell_exec com seam; delegação a process() próprio); reconciliar com `AtlasLoopObraGitWorktreeProbe` já existente (absorver ou declarar supersedido — proibido um 3º helper); `RuntimeClassConsumptionScanner::runGit` é public com caller externo — mantê-lo como adapter; `AtlasLoopProposalDiffReconstructor` sem testes — pular ou characterization test antes.

### R-14 · Publishing: dedupe da closure de query — **~40 LOC**
`BlogEditorialContextService`: 5 métodos compartilham a mesma closure `where(orWhere like %term%)` → helper `termsQuery()`. Veredicto honesto do verificador: Publishing é majoritariamente lógica legítima; só a closure é clone. Prova: tests/Feature/Ai/Publishing (35 testes) verde.

---

## Onda 3 — Table-driven em Holding/SelfConstruction (risco MÉDIO, ~10.200 LOC)

### R-15 · Chain-walker da subfamília LaterCycle (CodexReviewMergeSection) — **~6.000 LOC**
`ReadinessProjectionCodexReviewMergeSection.php`: 83 métodos/11.947 linhas (linha 6860→EOF) com ZERO estruturas de controle — cada método é o mesmo shape (upstream call → ternário ready/blocked → payload de listas → envelope). Divergências são DADOS. Walker + descritor de ~20 linhas/passo; **stubs públicos nomeados mantidos** (teste pina ≥149 métodos via ReflectionClass; delegators da mãe dependem deles).
**Constraints:** F0.1 golden-hash ANTES (testes atuais só checam formato do hash); ordem de chaves aninhadas byte-idêntica (hash encadeia entre passos); aplicar antes da C-02 para o walker nascer parametrizável por provider.

### R-16 · FixtureActionRuntime: leitor de status table-driven — **~2.200 LOC**
`EnterpriseFlowFixtureActionRuntimeService.php`: 38/43 métodos `*RuntimeStatus` com esqueleto idêntico (4.379 LOC). `runtimeStatusFor(spec)` com spec `[schema, bound_keys, gates, summary_keys]`; wrappers públicos preservam API; os 4-5 não-uniformes (~500 LOC) ficam fora do engine.

### R-17 · Dispatch table dos 170 `render*` do AtlasAiAutonomousHoldingCommand — **~1.400 LOC**
148 seguem template company-only exato (995 linhas de `twoColumnDetail` em só 3 formas de expressão). Tabela `action => [método, [label => summary_path]]` + `renderPayload()` único. A mesma tabela de specs da R-16 pode alimentar.
**Constraints:** EXCLUIR `renderEnterpriseConsolidationRun` (~392 LOC) e `renderEnterpriseFixtureSuite` (~136 LOC) — não seguem o template; os 14 multi-opção exigem spec estendida de args.

### R-18 · Registry: helper do esqueleto dos status read-model — **~450 LOC**
`ExternalActionMandateRegistryService.php`: 75 métodos `Status(?string $companyId=null)`; família simples (linhas 770-2128) >90% esqueleto idêntico (69× idiom wantedCompany, 479× sha256, 495× array_sum(array_map)). Extrair `companyGateStatusPayload(...)`; os ~60 builders `xxxCompany()` (conteúdo legítimo) ficam.

### R-19 · `runtimeRecordsForCompany` → spec por bloco — **~180 LOC**
Método privado de 585 linhas projetando 453 chaves: 351 mecânicas prefix+leaf → loop de projeção; 60 renamed em spec; **37 composites heterogêneos (141 LOC) ficam verbatim como closures — sem DSL**. Conjunto de chaves de saída idêntico (consumido pelos boundKeys dos 42 status).

---

## Onda 4 — Condicionais (reais, mas exigem resolver a pré-condição antes de virar slice)

| ID | Item | LOC teto | Pré-condição que trava |
|---|---|---|---|
| C-01 | AgentCodexSection: gerar 57 triplets por catálogo | ~7.000 | **Seção está FATAL em runtime na main** (chama 2 métodos inexistentes, `ReadinessProjectionAgentCodexSection.php:21-22` — bug dormente shipado no split fda9ce8f1c). Consertar o fatal + criar teste que CHAMA os métodos antes de qualquer golden/refactor. Catálogo real ~40-80 linhas/slice (não 15). |
| C-02 | AgentReviewMergeSection parametrizado por provider (109/109 sufixos do Codex) | ~6.500 | "Re-skin" é exagero: 0/109 idênticos pós-normalização; ~82% compartilhado com ~22 linhas estruturais divergentes/shape. Exige corpus golden-hash (não existe) + reconciliar mecanismos de upstream divergentes (`$this->parent->` vs `__call`+app()). Depende de R-15. |
| C-03 | `agentControlPlane()` (método de 4.043 linhas, 334 elseifs) → loop sobre catálogo | ~2.500 | Catálogo existente só cobre 35 das ~85 famílias e a ordem é rotação da escada (compartilhado com outros consumidores — não reordenar). Obra #6 V2 vetou tocar a família em janela de colisão: esperar quiescência dos workers auto-consolidantes. Golden byte-exato do payload inteiro + control_plane_hash antes. |
| C-04 | Registry: 12 famílias Register/Status → engine spec-driven | ~1.800 | Specs reais ~120-180 LOC/família (não 40); ≥4 shapes de enumeração distintos; run_context_id da família AgentWorkforce é persistido (uniformizar orfanaria rows). Exige protótipo medido: converter 1 par + 1 outlier antes de fixar a tabela. |
| C-05 | Data providers nos demais 137 arquivos de teste (top-20) | ~1.500 | Re-rankear top-20 excluindo já-comprimidos; scanner pina ~270 test paths por conteúdo (2 pins no VoiceRealtime incluem linha-fonte exata); SUT nº1 está no working tree da Obra #7. Nunca entra na esteira do Loop (anti-proxy). |
| C-06 | `ReadinessEnvelope::wrap()` (818 blocos/21.530 linhas medidas) | ~1.100 | Assinatura proposta cobre só ~42 blocos da mãe (373 shapes distintos); zona de escrita ATIVA da esteira (17 commits desde 25/06); fronteira de dupla-contagem com R-15/C-01/C-02 explícita; freeze real é outro teste (os SectionTest são wiring-only). |
| C-07 | Buildout: catálogos puros (1.340 LOC) → dados | ~1.000 | Os 11 `*SourceCatalog` não são tabelas puras (metade é transform com hash-prefix distinto); entrada 'finance' de `blueprints()` faz 5 chamadas a serviço; semântica de domainId desconhecido varia por método (default arm vs fallback vs nenhum) — mapear antes. |
| C-08 | Base TestCase para os 4 testes-padrão de gate | ~600 | Só 17 arquivos têm os 4 padrões juntos; corpos de rollback/idempotent têm ≥2 variantes — precisa de hooks extras e split em traits; slugs de produção espelham nomes de método (decidir manter descritivos). |
| C-09 | Trait storage-root + test seam no SCS | ~370 | Cluster byte-idêntico = 29 classes (não 45); 11 têm setter composto que PROPAGA override a colaboradores (trait quebraria a propagação — ex.: `ContinuousStewardshipRunnerService`); excluir da lista. |
| C-10 | Trait `ReadsCommandOptions` | ~320 | Cluster byte-idêntico = 41+4 arquivos (não ~90); `boolOption` tem 3 semânticas incompatíveis — fora do trait; variantes formatadas diferente exigem prova 1-a-1. |
| C-11 | run() do FixtureActionRuntime: 37 blocos attestation → tabelas | ~250 | attestation_hash usa concat manual com '\|' (NÃO MissionCanonicalHash) — golden-hash por bloco ANTES; 26 regras usam closures forall — verbatim; decidir se inclui as 376 regras extras pós-linha 6776. |
| C-12 | `toolDef()` builder no AtlasOpenBrainMcpService::tools() | ~200 | Scanner pina 25 literais `'name' => 'atlas_X'` no FONTE (CI: KernelBypassRegressionTest:693) — aplicar só aos ~49 tools não pinados; `?array $required = null` (null=omite, []=emite) para byte-identidade; arquivo no working tree da Obra #7; F0.3 antes. |
| C-13 | Concern `CreatesAtlasLedgerEventsTable` (79 arquivos fora da família) | ~180 | Só economiza com design auto-boot (`setUp<Trait>` do Laravel), que diverge da convenção dos 48 concerns existentes — decisão de design primeiro; 2 outliers multi-require manuais. |
| C-14 | Helper JSONL append idempotente por id no SCS | ~170 | recordOnce único não cobre os 14 sites (gates/status divergem); find-side tem 5 variantes de leitura + 1 semântica LATEST vs FIRST — spec por variante; 2 services da evidência não casam com a assinatura. |
| C-15 | Trait de bootstrap de auth da API nos testes | ~160 | Mecanismo REAL é header `X-Atlas-Token` (middleware `AuthenticateAtlasToken.php:24`), não Bearer — evidência do finder estava errada; trait deve popular propriedade `$headers` (mesmo nome) para não gerar churn em 52 arquivos. |
| C-16 | Delegar `stringOrNull()` a `AiValueNormalizer::trimmedStringOrNull` | ~140 | Re-escopar de 45 para 36 arquivos (28 + 8 scalar); os 9 divergentes (ex.: sem trim no `AtlasAaeosGateSignalEvaluator:316`) NÃO entram — delegar mudaria runtime silenciosamente. |
| C-17 | Delegar `stringList` ao `AiStringListNormalizer` | ~120 | Mapear variante por variante (37 métodos canônicos): ~13 já delegam, ~15 não são normalizadores, ~17 sem método exato, 3 com sort() (veto), 1 em Generated/ (veto). Só migrar cópia comprovada. |
| C-18 | Base abstrata `AtlasCertifyCommand` | ~100 | 13-14 fits limpos (não 18); certification services fazem str_contains no FONTE dos comandos — preservar `$signature` verbatim; 13 alvos sem teste de comando (congelar stdout/exit antes); decidir injeção do service. |
| C-19 | Trait `ResolvesWorkspaceOption` | ~100 | Escopo real 23 arquivos (16 fallback config + 7 base_path — NÃO unificar os fallbacks entre si); 5 comandos com git-root walk e 2 fail-fast ficam fora; 13 alvos sem teste dedicado. |

---

## NÃO-FAZER (refutados com prova — não re-propor)

| ID | Proposta refutada | Por quê |
|---|---|---|
| N-01 | Gerar a família OneShotTick (262 métodos, 27k linhas) por catálogo | **Hit direto na lista vetada**: Obra #6 V2 mediu exatamente esta família ontem (05/07) — NÃO byte-idêntica (182 esqueletos estruturais distintos em 262 métodos, medição independente confirmou), auto-consolidante, "tocar = colisão+Goodhart". Catálogo real custaria 3,5-5,5k LOC + interpretador ~2k — economia desonesta. |
| N-02 | Comprimir docblocks dos 436 delegators da mãe (~1.500 LOC) | Medição refuta: 320/429 delegators NÃO têm docblock; os 109 restantes já estão no formato mínimo proposto. Economia real ≈ 0. Variante máxima seria micro-faxina proxy (vetada). |
| N-03 | Derivar o mapa de dispatch do mother command em runtime (983 entradas) | As CHAVES são load-bearing: registram InputOptions (Symfony rejeita opção desconhecida — provado) e a ordem do mapa é precedência observável. LOC líquido ≈ ~0. |
| N-04 | Registry: fundir policies em defaults+overrides (613 LOC "estáticos") | Interseção real: 4/53 blocked ops entre as 9 policies — os "deltas pequenos" são o conteúdo; payloads alimentam policy_decision_json persistido e hashes de receipt; superfície fail-closed com teste que não pegaria drop de blocked op. |
| N-05 | Trocar 52 clamps privados por `Number::clamp` | **`Number::clamp(NAN,0.0,1.0) = 1.0` no PHP 8.5.5 local** — NaN viraria score perfeito em código de gate (padrão de bypass já corrigido 2×); 16/52 nem são clamps escalares; só 2/48 arquivos têm teste congelando NaN. |
| N-07 | **R-19 (refutada NA EXECUÇÃO, 06/07)**: runtimeRecordsForCompany → spec por bloco | Implementada com golden 430/430 verde e descartada: medição real deu **net +84 LOC** — o método original já era 1 linha por chave; a spec flat só reduz caracteres por linha e adiciona camada de indireção. A estimativa −180 do verificador não sobreviveu. Não re-propor. |
| N-06 | Consolidar 53 truncadores em um helper | ~19 falsos positivos do grep; dos ~26 genuínos as semânticas divergem de verdade (mb vs bytes, 4 sufixos, sufixo dentro/fora do limite); `Str::limit` (L13) usa mb_strimwidth (largura visual ≠ mb_substr); alvos em área vetada. Só 4 grupos byte-idênticos reais (~9 helpers) — se quiser, entram como item menor. |

Anti-achados registrados pelos próprios finders (não perseguir): percentile/median/average (26 cópias com semânticas de interpolação distintas), slug manual do Foundry, as 854 rows `schema =>` que são DADO (não glue).

---

## Apêndice A — 54 achados menores (<150 LOC cada, não verificados; ~2.800 LOC somadas)

Entram como fila de "quinta-feira de faxina" APÓS as ondas, cada um com verificação local antes do commit. Top por economia estimada:

- `strictExit(payload, okStatus)` — gate `--strict` reimplementado ~80× em comandos (140)
- `File::ensureDirectoryExists` no lugar de ~73 guardas `if(!is_dir) mkdir` (120)
- ~54 helpers privados de coerção (stringValue/intValue/floatValue…) → `AiValueNormalizer` (120)
- `AiWorker::completeAttempt` (490 ln): blocos telemetria/audit/ledger duplicados entre branches (120)
- 24 loops de leitura JSONL no AutonomousEvolution → reusar `AppendOnlyJsonlStore` (120)
- Contrato "Cortex universal" sem consumidor runtime (110 — deleção exige tripla prova, regra 8)
- `PipelineRunExecutor`: boilerplate caller-side idêntico entre os provider-executors (110)
- 11 walkers de arquivos PHP re-implementados no AutonomousEvolution (110)
- Quarteto TTL file-lock clonado em 4 serviços do ContinuousStewardship (110)
- Registry: 7 builders `*Rejected` idênticos exceto schema/razões (100)
- `AiChatCommand::handle` (563 ln): famílias de slash-commands → tabelas (90)
- 13 `stringList()` privados no AutonomousEvolution (90)
- `OwnerRuntimeFailureStateContract`: interface com ZERO implementações + teste tautológico (86)
- 4 classes `*CanonicalHash` byte-idênticas à `MissionCanonicalHash` → delegar corpos, nomes preservados (85)
- Helpers duplicados entre os services de Holding (findByFlow/findByKey/hashIfPresent…) (80)
- 128 chaves `config('atlas.*')` lidas como constantes absolutas (80 — validar 1 a 1 antes de hardcodar)
- Pares/classes espelho no Cortex e no SCS (70+70)
- `requireTables()`: guarda DatabaseTableAvailability duplicada em 50 comandos (60)
- Runner de comandos de aceitação clonado em 2 gates do Loop (60)
- posix_kill/SIGTERM→SIGKILL re-implementado em 6+ classes (60)
- `callTool()`: match de 64 braços → mapa const compartilhado com `tools()` (55, depende de C-12)
- Providers CLI: builder de anexos triplicado (50), `invocationModel()` idêntico em 3 (35), esqueleto de parsing stream-JSON (35), blocos de prompt de anexos (25), re-embrulho de `AiProviderResult` em 6 pontos (20), 6 `*Policy()` quase-idênticos no Hermes (20), pipeline de imagens PDF/Office duplicado (18) — **tudo extração de helper, drivers continuam separados (veto respeitado)**
- 91 métodos públicos com parâmetro opcional que nenhum caller de produção usa (50 — precisa varredura por método)
- Interfaces decorativas de 1 impl sem uso do tipo (ScopeComprehensionQuery 30, ForgeOwnerRuntimeDispatchPlanner 26, SpecialistFlowHandlerContract 24)
- `AtlasWorkspaceIntelligenceRuntimeService`: sinais de readiness computados 2× (30 — perf)
- clamp01 duplicado 7× → `AiValueNormalizer::clamp01()` próprio (28 — NÃO `Number::clamp`, ver N-05)
- Demais itens de 2-28 LOC no dump da sessão (`wf-minor.json`)
- Perf sem LOC: `KernelArchitectureStaticScanner` relê os mesmos arquivos do disco dezenas de vezes por run → cache `fileContents()` (casa com R-09)

---

## Ordem de execução recomendada

1. **Fase 0** (prova) → 2. **Onda 1** (testes, zero risco de produção, ~14k) → 3. **Onda 2** (helpers, ~3,4k) → 4. **Onda 3** (table-driven, ~10,2k, exige F0.1) → 5. destravar **C-01/C-02/C-03** (dependem de fatal fix + quiescência da Obra #7 + goldens) → 6. demais condicionais por ROI → 7. Apêndice A.

Cada slice: branch de trabalho mental = nenhum (main direto, regra 9), commit isolado, teste verde, LOC before/after no corpo do commit.
