# TEMP - Ordem de implementacao e melhoria do Atlas Unified Evolution Loop

> Documento temporario de trabalho. Nao e doc canonico da KB.
> Use junto com:
> 1. `dissecar/HANDOFF-CODEX-unified-evolution-loop.md`
> 2. `dissecar/DIRECAO-CODEX-loop-ownership.md`
> 3. `dissecar/OBRA-CODEX-reprova-out-of-process.md`
> 4. `dissecar/OBRA-CODEX-materializacao-P4.md`
>
> Status em 2026-06-09: Fases A, B, C, D, E e F concluidas em codigo/teste.
> Atualizacao: Semantic Implementation Certification e Intent Verifier Factory
> implementados para P4 pequeno.

## 0. Regras que valem para todos os itens

- Propose-only: nunca mergear para `main`.
- Rejeicao do gate, `null` e `no winner` sao resultado honesto, nao falha.
- Nao inflar nota: o loop completo ainda nao e 9.5 nos 4 pontos.
- Antes de mexer: rodar `php artisan atlas:ai:session-bootstrap --task="..." --json` e `php artisan atlas:ai:place-feature "..." --json`.
- Se tocar `AtlasEngineeringHonestyGate`, re-rodar testes e exploits em `storage/atlas/tmp/exploit*.php`.
- Depois de doc/codigo canonico: sincronizar KB e Code Intelligence. Para arquivos temporarios em `dissecar/`, sync nao e obrigatorio.

## 1. Ordem executiva

| Ordem | Item | Tipo | Motivo | Pronto quando |
|---:|---|---|---|---|
| 1 | Re-prova out-of-process das 73 propostas | Implementar agora | Transforma candidatas em propostas independentemente verificadas | `independently_verified.jsonl` e `refuted.jsonl` existem no run dir, painel mostra o novo tier |
| 2 | Rodar a re-prova no run real `run-20260608-133531-fe7dda` | Operacional | Valor parado no disco nao deve apodrecer | Cada proposta tem status `independently_verified` ou `refuted` com razao |
| 3 | Supervisor real 24h | Robustez | O loop morreu em 6.6h e o report ficou `running` sem worker PHP | launchd/supervisor reinicia, respeita kill-switch e usa `heartbeat.json` + `report.json` como liveness |
| 4 | Verificar/completar resiliencia de DB da campanha P1/P4 | Robustez | Blip de Postgres nao pode matar campanha duravel | Testes de `AtlasLoopDbResilience` e `AtlasLoopCampaignSupervisor` passam; lock sempre e liberado |
| 5 | Materializacao P4 Estagio 1 | Salto principal | Provar workspace Laravel completo com teste existente antes de gerar RED novo | `git worktree` + `vendor/` symlink + DB hermetico roda teste alvo e gate P4 certifica propose-only |
| 6 | Gate P4 `evaluateImplementation` | Salto principal | Gate de dead-code rejeita implementacao legitima | Gate novo aceita codigo novo com teste frozen, scope, revert-recheck e suite ampla do vencedor |
| 7 | Semantic Implementation Certification | Salto principal | P4 precisa de prova semantica, nao so re-rodar o mesmo teste | Gate P4 + painel adversarial + refutadores externos obrigatorios quando configurados + recibo |
| 8 | Intent Verifier Factory para alvo framework-reaching | Salto principal | O gargalo real e o verificador frozen, nao o workspace | Dada intencao estreita + alvo + atomo executavel, gera teste RED, refutavel e alimenta o grinder |
| 9 | Detector de duplicacao/clones | Modo novo | Cobre duplicacoes e encanamentos desnecessarios do pedido original | Modo emite flags com AST normalizado e nao auto-resolve julgamento semantico |
| 10 | Sintese meta dos achados | Inteligencia do loop | Evita corrigir 47 sintomas quando a raiz e template/gerador | Relatorio agrupa achados por causa raiz e propoe uma acao raiz |
| 11 | Modos de complexidade, cobertura e doc-drift | Cobertura P2/P3 | Amplia varredura para problemas ainda nao cobertos | Cada modo tem verificador frozen, fixture, gate e report por modo |
| 12 | Priorizacao por impacto | Qualidade de fila | Trabalhar no que importa primeiro, nao so no que aparece mais | Fila ordena por uso, risco, doc owner, code graph e historico de aceite |
| 13 | Learning flywheel | Compounding | Decisao humana deve melhorar descoberta futura | Aprovado/rejeitado alimenta prioridade sem auto-aplicar julgamento |
| 14 | Provar outros providers pelo mesmo gate | Antifragilidade | Capturar melhorias de engines sem hardcode | Mesmo modo roda com provider configurado pelo Atlas e compara por evidencias, nao narrativa |
| 15 | Generalizacao cross-dominio | Salto de tese | O engine e "busca sob verificador frozen", nao so engenharia | Primeiro dominio novo checavel tem discovery, verifier, gate, report e fronteira honesta |

## 2. Sequencia por fases

### Fase A - Blindar o que ja existe

Status: concluida. O run `run-20260608-133531-fe7dda` tem 73 propostas em
`independently_verified.jsonl` e 0 em `refuted.jsonl`.

1. Implementar `atlas:loop:verify-proposals`.
2. Criar `CleanCheckoutVerifier`.
3. Escrever `independently_verified.jsonl` e `refuted.jsonl`.
4. Atualizar `atlas:loop:unified:report` com o novo tier.
5. Rodar no run `storage/atlas/loop/unified/run-20260608-133531-fe7dda/`.

Validacao minima:

```bash
php artisan test tests/Unit/Ai/AutonomousEvolution/Verify/
php -d memory_limit=3G vendor/bin/phpstan analyse --no-progress --level=5 app/Services/Ai/AutonomousEvolution/Verify/
```

### Fase B - Fazer o 24h ser real

Status: concluida em codigo/teste. O supervisor detecta `running` sem worker PHP
real como `stale_running`; o LaunchAgent de `atlas:loop:unified:install-launchd`
retoma pelo mesmo `--run-id`; os testes de resiliencia de DB estao verdes.

1. Transformar o watcher temporario em supervisor real.
2. Liveness deve checar processo PHP real, `heartbeat.json` e idade de `report.json`.
3. Restart deve respeitar `storage/atlas/loop/unified/STOP`.
4. Relatorio stale sem worker PHP deve ser tratado como run morto, nao ativo.
5. Verificar DB resilience existente antes de reimplementar.

Validacao minima:

```bash
php artisan test tests/Unit/Loop/AtlasLoopDbResilienceTest.php tests/Feature/Loop/AtlasLoopCampaignSupervisorTest.php
```

### Fase C - Destravar P4 pequeno

Status: concluida em codigo/teste. O Estagio 1 usa materializacao por `git worktree`,
suporte local hermetico (`vendor`, env de teste, storage/cache gravaveis), clone por
worktree nos cenarios e gate P4 separado (`evaluateImplementation`) com teste alvo,
scope, frozen tamper, `revert_recheck` e holdout selado no vencedor.
O Semantic Implementation Certification agora fica depois do gate P4 e exige, quando
configurado, refutadores externos com `ATLAS_SEMANTIC_REFUTER_PACKET`.

1. Criar `AtlasLoopFrameworkWorkspaceMaterializer`.
2. Usar `git worktree`, symlink read-only para `vendor/`, `.env.testing` hermetico e DB sqlite `:memory:` ou schema descartavel.
3. Criar gate P4 separado, sem `pure_deletion` e sem `survivors_unchanged`.
4. Rodar teste alvo por cenario.
5. Rodar suite ampla relevante apenas no vencedor.
6. So certificar se `revert_recheck` prova que o diff ganhou a metrica.
7. Emitir recibo `atlas.loop.semantic_implementation_certification.v1`.
8. Falhar fechado se painel adversarial refutar ou refutador obrigatorio nao rodar.

Fronteira:

- Sem teste existente, repro ou spec, roteia para humano/Forge.
- Implementacao grande continua fora do auto-loop.
- Refactor multi-arquivo amplo entra depois de gate proprio.

### Fase D - Ampliar cobertura P2/P3

Status: concluida em codigo/teste para discovery read-only. `AtlasLoopSignalAnalyzer`
detecta clone estrutural, hotspot de complexidade, gap de cobertura, doc-drift por
mtime de code refs e duplicacao de docs. `AtlasP3FindingDispatcher` mistura esses
achados no mesmo `backlog.json` como `flag`; nenhum desses modos entra em auto-loop.

1. Clone estrutural por AST normalizado.
2. Hotspots de complexidade.
3. Gaps de cobertura.
4. Doc-drift e duplicacao de docs.
5. Sintese meta para causa raiz.

Regra:

- O modo pode flaggar.
- O modo so auto-fecha quando a correcao for behavior-free e checavel.
- Qualquer julgamento semantico vai para humano/Forge.

### Fase E - Loop inteligente e multi-dominio

Status: concluida em codigo/teste como overlay read-only. `AtlasLoopIntelligenceOverlay`
prioriza flags por impacto, aplica feedback humano apenas como peso de fila,
expoe matriz de providers sem invocar provider e lista slots cross-dominio com comandos
de verifier/readiness existentes. `atlas:loop:review-feedback` grava feedback append-only.

1. Prioridade por impacto.
2. Feedback humano alimenta descoberta.
3. Provar outros providers sem hardcode.
4. Dobrar loops de outros dominios no mesmo painel.
5. Comecar por dominio com verificador objetivo, como security scanner ou auditoria de dependencia.

Validacao minima:

```bash
php artisan test tests/Unit/Ai/AutonomousEvolution/AtlasLoopIntelligenceOverlayTest.php tests/Feature/Loop/AtlasLoopReviewFeedbackCommandTest.php
```

### Fase F - Intencao -> Verificador frozen

Status: concluida em codigo/teste para P4 pequeno nos padroes estreitos
`method_return`, `command_output`, `http_response`, `event_dispatched` e
`job_dispatched` e `db_state`.
`AtlasLoopIntentVerifierFactory` compila intencao + alvo em
teste framework-reaching, prova baseline RED em worktree materializada, permite
refutadores externos do proprio verificador via `ATLAS_INTENT_VERIFIER_PACKET` e
entrega `acceptance.commands` ao grinder. O grinder agora aceita task framework
sem acceptance manual quando `intent_verifier_factory=true` e persiste
`task.result.intent_verifier_factory`.

1. Criar `AtlasLoopIntentVerifierFactory`.
2. Criar `atlas:loop:compile-verifier`.
3. Provar RED-preflight antes da implementacao.
4. Bloquear intencao ambigua com `no_executable_verification_atom`.
5. Integrar no grinder P4 antes da materializacao.
6. Manter SIC como fechadura final depois do provider.

Fronteira honesta:

- Implementado: `method_return` em alvo framework/Laravel instanciavel sem args.
- Implementado: `command_output` para comando shell/Artisan com stdout e exit code esperados.
- Implementado: `http_response` para rota Laravel com status e corpo esperados.
- Implementado: `event_dispatched` para gatilhos in-process (`method_call`,
  HTTP interno e Artisan interno) com `Event::fake()`.
- Implementado: `job_dispatched` para gatilhos in-process com
  `Queue::fake()`/`Queue::assertPushed()` e job real no grinder P4.
- Implementado: `db_state` com `setup_sql` hermetico, gatilho in-process,
  SQLite de teste e assert por contagem/filtros simples.
- Nao implementado ainda: DB-state com migrations controladas e
  refactor multi-arquivo coordenado.
- Intencao ampla continua humano/Forge ou precisa de spec/refinamento antes do loop.

Validacao minima:

```bash
php artisan test tests/Unit/Ai/AutonomousEvolution/AtlasLoopIntentVerifierFactoryTest.php tests/Feature/Loop/AtlasLoopCompileVerifierCommandTest.php
php artisan test tests/Feature/Loop/AtlasLoopGrindTaskCommandTest.php --filter=compiled
```

## 3. O que nao fazer agora

- Nao comecar por campo novo no report se a re-prova ainda nao existe.
- Nao tentar P4 amplo antes do Estagio 1 de materializacao.
- Nao reaproveitar `evaluateDeadCodeRemoval` para implementacao.
- Nao rodar teste de framework contra Postgres real 5433.
- Nao resolver duplicacao de codigo automaticamente quando houver escolha arquitetural.
- Nao chamar proposta certificada de verdade antes da re-prova out-of-process.

## 4. Estado atual que orienta a ordem

- Run real: `storage/atlas/loop/unified/run-20260608-133531-fe7dda/`.
- Propostas: 73 candidatas, sendo 37 dead-code e 36 docs structure.
- Re-prova independente: 73 verificadas, 0 refutadas.
- Rejeicoes: 1 rejeicao honesta do gate.
- Backlog: 48 phantoms em 27 docs.
- Backlog novo: flags tambem passam a incluir `code_clone`, `complexity_hotspot`,
  `coverage_gap`, `doc_drift` e `doc_duplicate`, sempre roteadas para humano/Forge.
- O supervisor agora detecta o caso real de `running` sem worker PHP e recomenda restart.
- O LaunchAgent do loop pode ser gerado por `atlas:loop:unified:install-launchd`.
- DB resilience existente foi reaproveitada e validada por teste.
- P4 pequeno foi provado por teste e PHPStan nos componentes centrais.
- Fase E exposta por `report.json.intelligence` e pelo comando
  `atlas:loop:review-feedback`.
- Semantic Implementation Certification exposto por
  `atlas:loop:certify-implementation` e pelo `task.result.semantic_implementation_certification`
  do grinder P4.
- Intent Verifier Factory exposto por `atlas:loop:compile-verifier` e pelo
  `task.result.intent_verifier_factory` do grinder P4.
- O factory agora cobre evento despachado in-process, provado no grinder P4 com
  provider fake adicionando `event('atlas.intent.compiler.event_probe')`.
- O factory agora cobre job despachado in-process, provado no grinder P4 com
  provider fake adicionando `App\Jobs\FlushBatchedMobilePushes::dispatch()`.
- O factory agora cobre estado de banco estreito, provado no grinder P4 com
  provider fake inserindo linha em tabela criada por `setup_sql` hermetico.

## 5. Definicao de pronto do pacote

O pacote so pode ser chamado de avancado de verdade quando:

1. As 73 propostas tiverem tier independente (`independently_verified` ou `refuted`).
2. O loop 24h tiver supervisor real e restart seguro.
3. P4 Estagio 1 provar workspace Laravel completo com teste existente.
4. Gate P4 rejeitar teste apagado, scope escape, diff inerte e regressao de suite ampla.
5. Pelo menos um modo novo de P2/P3 estiver vivo com verifier frozen e report.
6. O operador receber relatorio honesto: o que fecha sozinho, o que apenas flagga, e o que continua humano/Forge.
7. O backlog tiver prioridade por impacto, aprendizado por feedback humano, matriz de providers e slots cross-dominio sem execucao automatica.
8. P4 pequeno tiver recibo semantico com painel adversarial, refutador externo quando exigido e invariantes propose-only.
9. P4 pequeno puder nascer de intencao estreita com verifier RED compilado antes
   do provider, ou bloquear explicitamente quando a intencao nao for executavel.
