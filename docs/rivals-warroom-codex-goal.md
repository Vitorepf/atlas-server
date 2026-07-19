# Prompt de goal para o Codex (copiar e colar)

Rivals precisa de confiabilidade TOTAL e você vai trabalhar junto com o Claude
nisso, coordenados por um arquivo. Missão: revisar TODOS os testes das 10 suítes
do Rivals, entender por que cada braço quebra, investigar até a causa-raiz,
resolver, e garantir que **nada quebre em silêncio** — todo teste tem que deixar
log legível e todo `environment_failure` tem que nomear a razão no recibo. No
fim: compreensão completa do Rivals e confiança de que o número que ele mostra é
verdade.

REGRAS PÉTREAS (atlas-server):
- Branch local `main` SEMPRE. Zero branch de obra, zero merge. Commits escopados
  (`git add -- <arquivos>`), mensagem explica o PORQUÊ. Antes de commit:
  `git branch --show-current` = `main`.
- Rode os testes antes de commitar: `php artisan test tests/Feature/Ai/Arena` +
  os testes do Rivals que você tocar. Verde obrigatório.

CANAL DE COORDENAÇÃO COM O CLAUDE (obrigatório):
- Leia `docs/rivals-warroom.md` INTEIRO antes de tocar qualquer coisa. É o
  blackboard compartilhado — o Claude já semeou o estado atual, as causas-raiz
  provadas e os handoffs.
- Antes de editar um alvo (arquivo/suíte): RESERVE na §3 (Claims) do war-room.
- Ao descobrir ou consertar algo: REGISTRE na §5 (Log) com sintoma → causa-raiz
  PROVADA (cite o log/comando, não deduza) → fix → prova. Dedução sem log NÃO é
  causa-raiz (o Claude já errou uma vez deduzindo "single-shot" — leia a §5).
- Nunca edite um alvo que o Claude reservou. Se precisar, escreva em §6 (Handoffs)
  e siga em outro alvo. Atualize o §4 (board) quando uma suíte mudar de estado.

SEU FOCO (complementa o Claude, evita colisão — a tabela de Claims manda):
- Pipeline de execução, normalizador (`NativeResultNormalizer`), report,
  `AtlasArenaDrainCommand`, e o harness harbor (senior_swe_bench, swe_marathon).
- **Garantia de logging (lei suprema):** audite CADA runner (`rivals-native-runner.php`,
  os unit-scripts, os agentes python) e garanta que stdout E stderr são sempre
  capturados em arquivo, e que todo recibo de `environment_failure` carrega uma
  `failure_reason` legível (hoje vários vêm com reason vazio — isso é "quebrou em
  silêncio", proibido).
- Cross-check dos fixes do Claude (adapters/bridge/endpoint) — se algum tiver
  buraco, registre em §5 e handoff em §6.

PRIORIDADES ABERTAS (pegue uma, reserve no war-room):
1. senior_swe/marathon: quando fecharem, audite o agente harbor com-Atlas (patch
   aplicado? reward? tokens?). Se `environment_failure`, ache a causa (rede do
   container? build? coleta do resultado?).
2. Garantir `failure_reason` nomeada em TODO env_failure do pipeline inteiro.
3. Cross-check: rode 1 unidade de cada suíte ✅ e confirme que o log existe e o
   recibo é honesto.

MÉTODO: nada de "acho que". Reproduza, leia o log, prove a causa, conserte, prove
o conserto, registre. Trabalhe append-only no war-room pra o Claude ver seu
progresso e ninguém pisar no outro.
