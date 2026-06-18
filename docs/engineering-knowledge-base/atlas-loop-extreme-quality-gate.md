---
id: atlas-loop-extreme-quality-gate
type: engineering_knowledge
doc_schema: atlas_canonical_module_doc.v1
title: Atlas Loop Extreme Quality Gate
slug: atlas-loop-extreme-quality-gate
status: building
implementation_state: design_approved_partial_php_baseline
category: software_company_stewardship
priority: 95
summary: Gate de qualidade EXTREMA diff-scoped, polyglot e fail-closed para o loop autonomo de Stewardship rodando 24/7 por meses; verifica somente os arquivos mudados do ciclo com ferramentas locais OSS, usa mutacao como oraculo de verdade e alimenta o repair loop com a saida exata.
tags: [atlas-ai, stewardship, area-focus-loop, quality-gate, polyglot, fail-closed, mutation-testing, local-first]
capabilities: [diff_scoped_verification, polyglot_fail_closed, mutation_truth_oracle, repair_fed_exact_output, version_pinned_deterministic_gate]
decisions:
  - Gate roda SO sobre os arquivos mudados do ciclo, nunca o codebase inteiro.
  - Linguagem tocada sem toolchain configurado BLOQUEIA o merge (fail-closed).
  - Mutation testing (Infection diff-lines) e o oraculo de forca de teste, nao apenas cobertura.
  - Toda falha de ferramenta entrega stdout/stderr EXATO ao repair loop.
  - Somente ferramentas locais OSS; zero SaaS (sem CodeRabbit/Snyk-cloud/SonarCloud).
  - Default 'off' para manter os 1125 testes do AreaFocusLoop byte-identicos; producao roda com enforcement=enforce.
maintenance:
  - Atualizar antes de mudar a tabela de tiers por linguagem, o ponto de injecao no AutonomousEvolutionSessionService ou a politica de repair do quarantine.
  - Manter versoes de ferramentas pinadas (larastan ^3.10 / phpstan ^2.2 / infection ^0.33) e o baseline PHPStan congelado.
  - Cada nova linguagem entra como fail-closed ate o operador instalar e declarar o toolchain local.
risk_level: high
owner: atlas-ai
graph_id: atlas-loop-extreme-quality-gate
human_name: Atlas Loop Extreme Quality Gate
canonical_name: Atlas Loop Extreme Quality Gate
technical_name: AutonomousEvolutionSessionService
cartography_type: module
canonical_source: docs/engineering-knowledge-base/atlas-loop-extreme-quality-gate.md
graph_title: Atlas Loop Extreme Quality Gate
graph_world: atlas
graph_layer: module
graph_kind: module
graph_parent: atlas-agentic-engineering-os
graph_status: building
graph_source: repo
depends_on: [atlas-agentic-engineering-os, atlas-ai-knowledge-governance-system]
flows_to: [atlas-agentic-engineering-os]
unlocks: [diff_scoped_quality_gate, polyglot_fail_closed_merge_block]
governs: [area_focus_gate_report, language_quality_gate_receipt]
authority_class: gate
related_paths:
  - docs/engineering-knowledge-base/atlas-loop-extreme-quality-gate.md
  - app/Services/Ai/SoftwareCompanyStewardship/AreaFocusLoop/AutonomousEvolutionSessionService.php
  - app/Services/Ai/SoftwareCompanyStewardship/AreaFocusLoop/AreaFocusGateEvaluatorService.php
  - app/Services/Ai/SoftwareCompanyStewardship/AreaFocusLoop/StewardshipMergeQueueService.php
  - app/Services/Ai/SoftwareCompanyStewardship/AreaFocusLoop/AreaFocusCandidateQuarantineService.php
  - app/Services/Ai/SoftwareCompanyStewardship/AreaFocusLoop/FinalDeliveryQualityGateService.php
repo_paths:
  - docs/engineering-knowledge-base/atlas-loop-extreme-quality-gate.md
  - app/Services/Ai/SoftwareCompanyStewardship/AreaFocusLoop/AutonomousEvolutionSessionService.php
evidence:
  - app/Services/Ai/SoftwareCompanyStewardship/AreaFocusLoop/AutonomousEvolutionSessionService.php
  - app/Services/Ai/SoftwareCompanyStewardship/AreaFocusLoop/AreaFocusGateEvaluatorService.php
  - app/Services/Ai/SoftwareCompanyStewardship/AreaFocusLoop/AreaFocusCandidateQuarantineService.php
  - composer.json
  - phpunit.xml
evidence_refs:
  - symbol: AutonomousEvolutionSessionService
  - command: atlas:plan-execution:run
  - test: AutonomousEvolutionSessionServiceTest
required_tests:
  - "php artisan test tests/Unit/Ai/SoftwareCompanyStewardship/AreaFocusLoop/LanguageQualityGateServiceTest.php"
  - "php artisan test --testsuite=Unit,Feature --filter=AreaFocusLoop"
  - "php artisan atlas:engineering:knowledge docs-health --json"
next_actions:
  - Implementar LanguageQualityGateService + ponto de injecao L4008 do AutonomousEvolutionSessionService.
  - Gerar e committar phpstan-baseline.neon contra todo o app/ uma vez offline.
  - Confirmar com o operador as questoes de SME abertas (canal de repair, path legacy, instalacao Python/Node).
allowed_changes:
  - Adicionar tiers por linguagem como dados de config conforme o operador instala toolchains locais.
  - Endurecer thresholds (level PHPStan, min-covered-msi) sem quebrar diff-scoping nem fail-closed.
forbidden_changes:
  - whole_codebase_analysis
  - merge_untouched_language_without_toolchain
  - cloud_saas_scanner
  - default_enforce_breaking_existing_tests
requires_evidence: true
line_limit: 520
schema:
  - atlas.software_company_stewardship.area_focus_gate_report.v1
  - atlas.software_company_stewardship.language_quality_gate_receipt.v1
---

> ⚠️ **DEFINIÇÃO CANÔNICA DO LOOP — leia primeiro: `docs/loop-canonical-definition.md` + memórias `loop-*`.** Este doc descreve IMPLEMENTAÇÃO / ESTADO / HISTÓRICO; parte do framing aqui (refactor / ciclomática / landing-rate / best-of-N / proxy) é o **ALVO ERRADO**. O Loop = evolução autônoma **exponencial** de features REAIS do Atlas (entender escopo → projeção frontier + crítica cross-model → multi-agente → teste → wiring), **nunca faxina / proxy / one-shot**. Objetivo final: ser o ÚNICO que evolui o Atlas 24/7 sozinho.


# Atlas Loop Extreme Quality Gate

## Resumo

Gate de qualidade EXTREMA que protege o merge do loop autonomo de Stewardship (`AreaFocusLoop`) que roda 24/7 por meses. Quatro leis governam o desenho: (1) **local-first** — apenas ferramentas OSS locais, zero SaaS; (2) **diff-scoped** — verifica somente os arquivos (e, onde possivel, as linhas) mudados do ciclo, nunca o codebase inteiro, para nao arrastar o loop nem re-analisar os 1125 testes do AreaFocusLoop; (3) **fail-closed** — se uma linguagem tocada nao tem toolchain configurado, o merge e BLOQUEADO (nunca merge de codigo nao verificado); (4) **repair-fed** — toda falha de ferramenta entrega a saida exata (stdout/stderr, file:line:identifier) ao repair loop.

O oraculo de verdade nao e cobertura, e **mutacao**: Infection rodando `--git-diff-lines` prova que os testes do ciclo realmente matam mutantes nas linhas mudadas. O gate e deterministico, version-pinned e rapido (sub-2s no caminho quente PHP), apto a operar sem supervisao por meses.

## Papel no Atlas

E a ultima lei de verificacao antes do merge governado do loop. Composto por dois sub-gates diff-scoped (PHPStan/Larastan estatico + Infection mutacao) e um classificador polyglot que decide, por linguagem tocada, se o ciclo pode prosseguir (`pass`), avisar (`warn`) ou ser bloqueado (`block`).

Atlas e o cerebro; este gate e o musculo de controle de qualidade que impede o loop de mergear regressao silenciosa enquanto o operador dorme. Ele NAO concorre com nenhum produto externo; substitui CodeRabbit/Snyk-cloud/SonarCloud como produto, rodando 100% local. Ele e antifragil: cada linguagem nova que aparece no diff sem toolchain bloqueia (forca o operador a endurecer a cobertura), em vez de degradar silenciosamente.

## Onde Se Encaixa

Ponto de injecao exato em `AutonomousEvolutionSessionService::runOwnerFlowCycle` (`app/Services/Ai/SoftwareCompanyStewardship/AreaFocusLoop/AutonomousEvolutionSessionService.php`): **depois da final-delivery law (termina ~L4008) e antes do workcell judge gate (~L4010)**. Nesse ponto:

- `commitSandbox` (~L3969) ja produziu `$commit` e `$changedFiles` (paths relativos do `git status --porcelain` no worktree).
- `$worktree` (raiz absoluta do sandbox isolado) esta em escopo.
- O workcell judge (~L4027) e o ultimo gate antes de `governedMergeForCycle` (~L4046), entao inserir acima dele mantem "ferramentas reais verificam o diff real" como ultima lei antes do merge.

O gate roda **dentro do `$worktree`** (branch isolado do ciclo), nunca no checkout principal, para que ciclos concorrentes nao colidam. O diff anchor e o `base_ref` (default `main`, `StewardshipMergeQueueService.php:80`); a execucao usa o mesmo seam Symfony `Process` com `setWorkingDirectory($worktree)` ja provado em `StewardshipMergeQueueService.php:505-513` e `runValidation`.

Vocabulario de saida reusa `atlas.software_company_stewardship.area_focus_gate_report.v1` (`AreaFocusGateEvaluatorService.php:37,45-51`): `pass` / `warn` / `block`.

## Contratos

Servico novo `LanguageQualityGateService` (mesmo namespace `AreaFocusLoop`, espelha `FinalDeliveryQualityGateService` — puro, stateless, container-resolved):

```
const BLOCKER = 'language_quality_gate_failed';

assess(string $worktree, array $changedFiles): array
// retorna:
{
  passed: bool,
  blocker: ?string,                  // self::BLOCKER quando passed=false
  languages: list<string>,           // linguagens executaveis tocadas
  fail_closed_languages: list<string>,// linguagens tocadas sem toolchain
  enforcement: string,               // 'off' | 'enforce'
  tool_results: list<{               // alimenta o repair loop com saida exata
    language: string,
    command: string,
    exit_code: int,
    output_excerpt: string           // AtlasSecurity::redactString, bounded
  }>
}
```

Receipt schema: `atlas.software_company_stewardship.language_quality_gate_receipt.v1`. Mapeamento de exit code de ferramenta para vocabulario do gate (`AreaFocusGateEvaluatorService.php:45-51`): `0`→`GATE_PASS`; mutation `8` (MSI nao atingido)→`GATE_BLOCK` (quality); `1`/outro/crash→`GATE_BLOCK` (infra). Qualquer non-zero, incluindo linguagem-tocada-sem-toolchain, BLOQUEIA.

## Fluxo

1. `commitSandbox` retorna `$changedFiles` (paths relativos) + `$worktree` (absoluto).
2. **Classificador polyglot** (funcao pura, deterministica, first-match-wins): resolve cada path para um token de linguagem por extensao/basename. Ordem: ignore allowlist (`vendor/`, `node_modules/`, `.git/`, `storage/`, `dist/`, `*.min.js`) → deps (`composer.json`, `go.mod`, `package.json`, `Package.swift`, `requirements*.txt`) → `.php` → `.py/.pyi` → `.go` → `.swift` → `.ts/.tsx/.mts/.cts` → `.js/.jsx/.mjs/.cjs` → config-doc (`.json/.yaml/.md/.neon/.xml/.sh/...`) → **anything else = `unknown` → BLOCK** (`gate_unknown_file_class:<path>`). Um `.rs` novo nunca passa como "sem linguagem".
3. Se `enforcement !== 'enforce'` → retorna `passed:true` imediatamente, sem rodar processo (no-op de teste/default).
4. Para cada linguagem executavel tocada: se nao ha toolchain resolvivel (binario ausente via `command -v`/teste de path) → `fail_closed_languages[]`, `passed:false`. Se ha → roda os comandos diff-scoped daquela linguagem dentro de `$worktree`, com `{files}` = SOMENTE os arquivos daquela linguagem (shell-quoted), `setTimeout(180)`.
5. **PHP (home language, wired now)**: `vendor/bin/pint --test -- $FILES` (format) → `vendor/bin/phpstan analyse --configuration=phpstan.neon --memory-limit=512M --no-progress --error-format=json $FILES` (estatico, diff-scoped por argv que sobrepoe `paths:`) → `php artisan test $TEST_FILES` (apenas testes tocados, nunca a suite inteira) → Infection mutacao (ver abaixo).
6. **Mutation truth oracle (PHP)**: `php -d zend_extension=.../pcov.so -d pcov.enabled=1 -d pcov.directory=app vendor/bin/infection --git-diff-lines --git-diff-base="$BASE" --min-covered-msi=80 --min-msi=0 --threads=max --only-covered --no-progress --no-interaction --logger-json=storage/logs/infection/infection.json`. Mutila SO as linhas mudadas; exit `8` = mutantes sobreviventes = block.
7. Qualquer exit non-zero, qualquer `fail_closed_languages` non-empty, ou crash de analyzer → `passed:false`, `blocker=BLOCKER`.
8. Se `passed:false`: `governCycleOutcome(... final_status:'blocked', merge_performed:false, blockers:[BLOCKER], validation.results=tool_results ...)` — early-return aditivo, fluxo do merge nunca alcancado. Se `passed:true`: fluxo prossegue transparente para o workcell gate.

## Regras para IA

- **Filtro 5-perguntas**: aumenta o multiplicador composto (loop honesto que nunca mergeia regressao), e antifragil (linguagem nova = block, nao degradacao), aproxima da execucao fim-a-fim governada, destrava substituir o produto de quality-gate externo, preserva soberania local-first. Passa nas 5.
- NUNCA analisar o codebase inteiro. Sempre passar a lista de arquivos mudados por argv (PHPStan) ou `--git-diff-lines` (Infection). Diff-scope e LEI.
- NUNCA mergear linguagem tocada sem toolchain. Fail-closed e LEI: na duvida, BLOCK.
- NUNCA usar scanner SaaS/cloud. Apenas binarios locais OSS. `npx --no-install` em toda invocacao Node para que ferramenta ausente vire erro, nunca fetch de rede.
- NUNCA deixar `enforcement=enforce` como default — quebraria os 1125 testes. Default e `off`; producao injeta `ATLAS_STEWARDSHIP_LANGUAGE_QUALITY=enforce`.
- SEMPRE entregar saida exata da ferramenta (file:line:identifier, diff de mutante) ao repair loop, nunca um resumo generico.
- NUNCA committar `storage/phpstan/` nem `storage/infection-tmp/` (cache machine-local; poisona cross-machine).
- NUNCA adicionar `phpstan/phpstan-strict-rules` ao gate (incha o baseline, gera alvos de repair falsos).
- Pinar versoes: `larastan/larastan:^3.10`, `phpstan/phpstan:^2.2`, `infection/infection:^0.33`. Um loop de meses nao pode ter o analyzer removido silenciosamente.

## Escopo de Implementacao

### ENTREGUE AGORA (keystone PHP estatico — wired, testado, provado e2e)
- `CycleLanguageQualityGateService` (decisao + classificador polyglot puro, fail-closed) + `CyclePhpTierRunner` (interface, fakeavel em teste) + `ShellCyclePhpTierRunner` (runner real PHPStan).
- Injecao em `runOwnerFlowCycle` logo apos a lei de delivery-final + accessor `languageQualityGate()` via `app(...)` (espelha `finalDeliveryGate()`).
- `phpstan.neon` na raiz (level 5, `extension.neon` da Larastan + `phpstan-baseline.neon` + `tmpDir: storage/phpstan`). Sem flag `--tmp-dir` (nao existe no PHPStan 2.x); `tmpDir` so via config.
- `phpstan-baseline.neon` VAZIO commitado (placeholder). **FATO-CHAVE:** arquivos NOVOS analisam limpos SEM baseline — o padrao dominante do loop (criar contracts/services) ja e gateado corretamente hoje. O baseline so e necessario para nao falso-bloquear quando o loop EDITA um arquivo legado com debito.
- **BLOCKER de ativacao do baseline cheio (2026-05-29):** gerar o baseline sobre toda a arvore (`git ls-files '*.php'`, ~6k arquivos) FALHOU apos ~15min — o parser do PHPStan 2.2.1 rejeita um arquivo com "empty array elements on line 29" (sintaxe PHP 8.5 nao suportada pelo php-parser embarcado, ou fixture quebrada). Follow-up bounded: identificar o arquivo ofensor (`excludePaths` ou corrigir), depois `--generate-baseline`. Ate la: `enforce` e seguro para ciclos que so criam arquivos novos; ciclos que editam legado podem falso-bloquear ate o baseline existir.
- Config `config/atlas.php` → `software_company_stewardship.language_quality` (`enforcement` env default `off`; `toolchains.php.tools = [phpstan]`). Binding `CyclePhpTierRunner`→`ShellCyclePhpTierRunner` no `AppServiceProvider`.
- Runner roda PHPStan a partir da raiz do repo principal (worktree e vendor-free) analisando os arquivos do worktree por path absoluto; pula paths inexistentes (seguro vs aspas do git porcelain); timeout distinto de erro (fail-closed).
- Blocker `language_quality_gate_failed` NAO-terminal → o finding e re-tentado pelo runner ate passar; saida exata da ferramenta gravada em `language_quality_gate.tool_results` + `validation.results` (operator-visible/evidence).
- Prova e2e: arquivo com erro de tipo real → `passed:false` (PHPStan o pega); arquivo limpo → `passed:true`; `.ts` → fail-closed `['ts']`.

### DIFERIDO de proposito (NAO entregue inerte — respeita a lei anti-scaffold)
- **Infection (mutacao)**: exige coverage driver (pcov/xdebug) — AUSENTE neste ambiente. Embarcar `infection.json5` agora seria scaffold inerte. Proximo tier, apos `pecl install pcov`.
- **Pint blocking**: sem baseline → falso-positivo recorrente em arquivos legados tocados. Fora do tier bloqueante por ora (pode rodar em modo fix nao-bloqueante depois).
- **Repair policy case**: NAO adicionado. O blocker e nao-terminal por padrao (`repairPolicyForBlockers` retorna `none`) → runner re-tenta com cap. O `retry_then_quarantine` do plano original foi descartado (o `governCycleOutcome` nao re-invoca o gate — seria narrativa falsa).
- **Python/Go/Swift/TS**: fail-closed (BLOQUEIA) ate cada toolchain OSS local ser wired e declarado em `toolchains`. Especificados como dados, nao codigo.

## Dependencias

- **Ja instalado/correto** (medido): `larastan/larastan ^3.10` (v3.10.0 → PHPStan 2.2.1), `pint 1.27`, `phpunit 12.5.23`, runtime PHP 8.5.5, Laravel 13.6.
- **Instalar uma vez (local OSS)**: `composer require --dev "infection/infection:^0.33"` (resolve clean, 19 installs, 0 conflitos — dry-run real). `pecl install pcov` (driver de cobertura — NAO adicionar ao php.ini; habilitar via `-d` so na invocacao do gate, para os 1125 testes rodarem na velocidade plena).
- **Runtime seams reusados**: `commitSandbox`/`changedFiles`, `governCycleOutcome`, `buildFailureCapsule`, Symfony `Process` + `AtlasSecurity::processEnv`/`redactString`.
- **Linguagens nao-PHP**: nenhuma ferramenta instalada hoje (`go` runtime ausente; `node 24`, `python3 3.14`, `swift 6.3.1` presentes mas sem linters). Todas fail-closed.

## Evidencias

- Larastan instalado e version-correct; PHPStan single-file level 5 = 1.5s; dir 98 arquivos level 6 cold 15s / warm 1.7s; workers default crasham a 128M (fake "severe errors") corrigido por `--memory-limit=512M`; baseline suprime per-file/per-message identifier-keyed, erro NOVO no mesmo arquivo nao casa e bloqueia. Fonte: runs medidos no repo real.
- Infection 0.33.2 runtime require sem pin de PHPUnit; resolve clean contra a stack; sem coverage driver hoje (`php -m` sem Xdebug/PCOV); `base_ref` default `main` (`StewardshipMergeQueueService.php:80`); Process seam L505-513; vocabulario gate `AreaFocusGateEvaluatorService.php:37,45-51`.
- Loop hoje verifica so com `php artisan test <file>` + `git diff --check` (`ownerValidationCommands` ~L2984-3008); Pint NAO e invocado no loop.
- Required tests: `php artisan test tests/Unit/Ai/SoftwareCompanyStewardship/AreaFocusLoop/LanguageQualityGateServiceTest.php` e regressao `--filter=AreaFocusLoop` (1125 verdes byte-identicos com `enforcement=off`).

## Riscos

- **PCOV em PHP 8.5.5 nao compilado no sandbox** (sem rede). Mitigacao: `pecl install pcov && php -d extension=pcov.so -m | grep pcov` uma vez; fallback `xdebug.mode=coverage` (mais lento, ainda local). Risco: alto se PCOV falhar — gate de mutacao fica bloqueado ate fallback.
- **Casing de chave JSON do Infection 0.33.2** (`coveredCodeMsi` vs `covered_code_msi`) nao confirmado; parser deve `?? 0`-guard ambos contra `vendor/infection/infection/resources/schema.json`.
- **Caminho legacy paralelo** (`runValidation` + `governedMergeForCycle` ~L1493-1549) pode ser alcancado sob `--allow-direct-provider-driver`; se reachable em producao, injetar o gate tambem apos `commitSandbox` ~L1549. Confirmar com SME.
- **Baseline drift**: ao corrigir um erro baselined, a entrada vira unmatched; `reportUnmatchedIgnoredErrors: false` evita falso-block as 3h. Regenerar baseline offline periodicamente.
- **Cache cold apos `composer update`**: result cache keya em versao do analyzer; primeira run apos bump e cold (auto-detectado, seguro). Nao compartilhar `storage/phpstan` entre worktrees.
- **Default enforce acidental** quebraria os 1125 testes. Forbidden change explicito; default `off` e lei.

## Exemplos

PHP estatico diff-scoped (block + saida exata para repair):
```
$ vendor/bin/phpstan analyse --configuration=phpstan.neon --memory-limit=512M \
    --no-progress --error-format=json app/Services/Ai/.../FooService.php
{"gate":"phpstan","status":"block","reason":"static_errors","count":2}
# JSON files[path].messages[] = {file,line,message,identifier} -> repair prompt verbatim
```

Mutacao como oraculo (survivor → instrucao de repair exata):
```
$ vendor/bin/infection --git-diff-lines --git-diff-base=main --min-covered-msi=80 ...
INFECTION_EXIT=8
COVERED_MSI=78.5 ESCAPED=1
SURVIVOR app/Services/Ai/.../FooService.php:142 GreaterThan
  was: if ($ratio > 0.9) {
  mut: if ($ratio >= 0.9) {
# repair: um teste precisa distinguir > de >= em FooService.php:142
```

Fail-closed polyglot (linguagem sem toolchain → block):
```
# ciclo tocou app/.../widget.ts ; nenhum linter TS instalado
{"passed":false,"blocker":"language_quality_gate_failed",
 "fail_closed_languages":["ts"],"reason":"gate_language_not_enabled:ts missing=[biome,tsc,vitest]"}
```

No-op default (1125 testes intactos):
```
# enforcement=off -> assess() retorna passed:true sem rodar processo
{"passed":true,"enforcement":"off","tool_results":[]}
```

## Proximas Acoes

- Implementar `LanguageQualityGateService` + classificador polyglot + ponto de injecao ~L4008 e accessor `languageQualityGate()`.
- Gerar `phpstan-baseline.neon` uma vez offline (`--memory-limit=1G --generate-baseline` contra todo `app/`) e committar; adicionar `storage/phpstan/` e `storage/infection-tmp/` ao `.gitignore`.
- `composer require --dev infection/infection:^0.33`; `pecl install pcov` e provar carregamento em PHP 8.5.5.
- Adicionar case `language_quality_gate_failed` em `repairPolicyForBlockers` (~L204) como `retry_then_quarantine`/`stop_session:false`.
- Adicionar `config/atlas.php` bloco `language_quality` (default `off`).
- Escrever `LanguageQualityGateServiceTest` (off no-op; ts sem toolchain → block; php sujo → block com saida exata; php limpo → pass) + teste de integracao do seam + teste da politica de quarantine.
- SME (operador): (1) qual campo o `Ap786OwnerFlowExecutor` repair le como primario (`failure_capsule` vs `validation.results`); (2) o path legacy ~L1493-1549 e reachable em producao; (3) metodo de instalacao permitido para Python/Node (`pipx`/`pnpm -D` local); (4) se os tiers de upgrade PHP (PHPStan/Infection) devem ser hard-block ou advisory ate instalados.
