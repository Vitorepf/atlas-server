# OBRA → Codex: Materialização para P4-pequeno (refinamento do §7.2 do handoff)

> Anexo ao `HANDOFF-CODEX-unified-evolution-loop.md`. Leia o handoff inteiro primeiro (§4 do gate é obrigatório).
> Esta obra concorda com a priorização do Codex (materialização = o salto), mas corrige o que o shape limpo de 6 passos subestima. **Não comece a codar sem ler §1 e §2 — é onde o plano ingênuo falha no repo real.**

---

## 0. A correção de moldura (leia antes de tudo)

O plano "cria workspace → aplica diff → roda testes → certifica" trata o **workspace** como a dificuldade. **Não é.** O workspace é ~40% fácil. O difícil, e o que de fato destrava P4, é:

> **Qual é o VERIFICADOR FROZEN (o teste RED→GREEN) para um alvo que toca o framework (`use App\...`)?**

Sem isso, materializar é inútil — o loop não tem alvo pra moer. As 3 sub-classes honestas de P4:
- **(a)** Bug-fix com **teste existente já falhando** → o teste É o verificador. Raro (se houvesse teste vermelho, alguém já teria visto).
- **(b)** Bug-fix com **reprodução** → o loop precisa **gerar um teste RED** da repro, depois moer até GREEN. Gerar um teste RED *correto* (que falha pela razão certa) para um alvo framework-reaching é uma task de LLM por si só. O `AtlasEvolutionTaskGenerator` já faz isso para arquivos self-contained — **estendê-lo para framework-reaching é o coração da obra**, não a materialização.
- **(c)** Feature com **spec** → gerar teste de aceitação RED da spec, moer até GREEN.
- **(d)** Sem teste, sem repro, sem spec → **o loop NÃO pode fazer. Roteia pra humano.** (Fronteira honesta.)

**Materialização é NECESSÁRIA mas NÃO SUFICIENTE.** Ela dá o workspace onde o teste *pode* rodar; a outra metade (gerar o RED frozen + o gate P4) é o trabalho de verdade. Sequencie sabendo disso.

---

## 1. As 4 armadilhas que o shape ingênuo ignora (e o desenho certo)

### 1.1 vendor/ + DB + env — "instalar deps mínimas" está ERRADO
Um app Laravel **não roda teste sem `vendor/`** (composer, ~centenas de MB) e sem bootstrap de DB/env. `composer install` por cenário = minutos + rede = inviável.
- **Certo:** o workspace materializado **symlinka** `vendor/`, `.env.testing` (e qualquer config de teste) como **read-only** — o loop nunca muda deps. Copia só o **source rastreado** (git), e sobrepõe o **um arquivo candidato**.
- **DB de teste:** use `sqlite :memory:` (ou um schema PG throwaway) configurado no `.env.testing` do workspace — **nunca** o PG real (porta 5433): testes não-herméticos corromperiam estado / ficariam flaky. Rode as migrations no setup do workspace.
- **Isolamento:** o workspace precisa do próprio `storage/`, cache limpo, e env apontando pra dentro dele. Teste que escreve fora do workspace = bug a isolar.

### 1.2 O materializer P4 é OUTRO componente — NÃO estenda o self-contained
`AtlasLoopWorkspaceMaterializer` cria um **temp dir nu** (sem git, sem Laravel) — é o materializer SELF-CONTAINED. P4 precisa de um workspace **git + Laravel completo**. Conflatá-los é armadilha.
- **Certo:** novo `AtlasLoopFrameworkWorkspaceMaterializer` (ou similar) que faz **`git worktree add`** (compartilha objetos do `.git`, barato) num temp + symlinka `vendor/`/`.env.testing`/`storage` + aplica o candidato. Git worktree dá de graça: diff/scope/tamper/revert-recheck que o `AtlasEvolutionFrozenJudge` já usa.
  - Cuidado: worktree não traz `vendor/` (gitignored) → symlink obrigatório. E precisa `rm` do worktree no finally + registrar o prefixo no `AtlasLoopResourceGate::PREFIXES` (reaper).

### 1.3 O gate de P4 é DIFERENTE do gate de código morto — NÃO reuse
O `AtlasEngineeringHonestyGate::evaluateDeadCodeRemoval` tem `pure_deletion` + `survivors_unchanged` (byte-equal). **Uma implementação ADICIONA código** → esses dois holdouts REJEITARIAM um P4 legítimo.
- **Certo:** método/serviço novo `evaluateImplementation(...)` com holdouts próprios:
  - diff aplica em checkout limpo (worktree);
  - **aceitação frozen passa** (o teste RED→GREEN gerado/existente);
  - **suíte ampla relevante passa** (holdout selado — sem regressão fora do alvo);
  - **nenhum arquivo fora de `allowed_globs`** (SCOPE — já no frozen judge);
  - `revert_recheck`: reverter o candidato volta a RED (diff-earned — o teste depende do diff);
  - `merged_to_main:false`.
  - **Sem** pure_deletion/survivors_unchanged (são específicos de remoção).

### 1.4 Holdout por-cenário (rápido) ≠ holdout final (suíte cheia)
Rodar `php artisan test` inteiro por candidato × N cenários = caro demais. O loop de trading resolveu com métrica rápida in-process; aqui o holdout É teste.
- **Certo:** per-cenário roda só os **testes ALVO** (o teste gerado + o TestCase da classe, se existir) — rápido, é o verificador frozen do juiz. O **vencedor** roda a **suíte ampla relevante uma vez** (o holdout selado, no gate). Distinga os dois claramente no `grindAndGate`.

---

## 2. Escopo em estágios (não tente tudo de uma vez)

**Estágio 1 — prova a corrente (o mais estreito viável).** Alvo: um arquivo framework-reaching que **JÁ TEM** um TestCase, com um bug introduzido de propósito num fixture. Materializa via worktree + vendor-symlink + sqlite :memory:, o teste existente é o frozen, mói 1 fix, gate P4 certifica propose-only. **Sem geração de teste, sem multi-arquivo.** Isso valida vendor/DB/worktree/gate sem o pedaço de geração.

**Estágio 2 — geração de RED para framework.** Estende `AtlasEvolutionTaskGenerator` (ou novo gerador) pra criar um teste RED de uma repro/spec para alvo framework-reaching, no workspace materializado. Aqui mora o valor real.

**Estágio 3 — multi-arquivo coordenado** (rename → callers): `allowed_globs` multi-arquivo + holdout coordenado. Depois.

**Fronteira honesta (não apague):** implementação grande, julgamento semântico e refactor amplo continuam humano/Forge até terem gate próprio provado. P4 do loop = mudança pequena, **com teste frozen existente ou gerável**. Sem teste/repro/spec → humano.

---

## 3. Onde plugar (arquivos)

- **Novo:** `app/Services/Ai/AutonomousEvolution/AtlasLoopFrameworkWorkspaceMaterializer.php` (worktree + vendor-symlink + env/DB de teste). NÃO mexer no `AtlasLoopWorkspaceMaterializer` (self-contained).
- **Estende:** `AtlasEvolutionTaskGenerator` (Estágio 2) — geração de RED frozen para framework-reaching.
- **Novo método/serviço:** gate `evaluateImplementation` (não reusar o de dead-code) — pode ser método novo em `AtlasEngineeringHonestyGate` ou um `AtlasImplementationHonestyGate` irmão.
- **Estende:** `AtlasUnifiedLoopOrchestrator::grindAndGate` — novo modo `implement` que usa o materializer P4 + o gate P4 + split holdout-por-cenário/suíte-final. Novo `mode` no `AtlasP3FindingDispatcher` (discovery de alvos P4 = TODOs/FIXMEs confirmados, ou o backlog de phantoms "implement").
- **Reaper:** adicionar o prefixo do worktree em `AtlasLoopResourceGate::PREFIXES`.
- **Não toca:** `AtlasEvolutionScenarioExplorer`, `AtlasEvolutionFrozenJudge` (o contrato de aceitação já serve; só passe os comandos/globs certos).

## 4. Testes (mínimo 3 fixtures + 1 de gate)
- self-contained continua passando (regressão).
- `use App\...` **falha sem materialização e passa com workspace materializado** (a prova da obra).
- candidato mexendo fora de `allowed_globs` é REJEITADO (SCOPE).
- gate P4: um "fix" que faz o teste passar mas **quebra a suíte ampla** é REJEITADO (holdout selado). Um "fix" que passa só porque desabilitou/apagou o teste é REJEITADO (revert_recheck / frozen tests).

## 5. Custo honesto (pro operador saber)
Isto **não é quick-win** — é obra de semanas (vendor/DB/env materialização + geração de RED framework + gate P4 + split de holdout). O ROI é alto (destrava o 4º ponto), mas o caminho honesto é o Estágio 1 primeiro (prova a corrente em dias) antes de prometer P4 amplo. A **re-prova out-of-process** (a outra obra) é mais barata (dias) e vira a fechadura final pra TUDO, inclusive isto — por isso o Codex acertou em pôr ela como #2: materialização abre a porta, re-prova põe fechadura boa.
