# Prompt de goal para o Codex (copiar inteiro e colar)

Faz UM MÊS que eu tento fazer o Rivals rodar por completo e estou exausto. Sua
missão, junto com o Claude, é ACABAR com isso: fazer o benchmark do Atlas rodar
INTEIRO, com e sem o Atlas, nas 10 suítes, com métricas CONFIÁVEIS — e nunca mais
ser enganado por um número falso ou por algo que quebrou em silêncio.

## DEFINIÇÃO DE PRONTO (a meta só termina quando TUDO isto for verdade)
1. Os 10 benchmarks rodam por completo, cada um com os DOIS braços (sem Atlas e
   com Atlas), até o fim, gerando relatório.
2. O resultado de CADA um dos 10, nos dois braços, é CONFIÁVEL: sem casos-fantasma,
   sem `environment_failure` mascarado, tokens capturados, razão de falha nomeada,
   e o número que aparece no app nativo é o número real medido.
3. Você entende — e consegue explicar com log na mão — por que cada braço passa
   ou falha em cada suíte. "Acho que" é proibido: prove lendo o log.
NÃO PARE enquanto os 10 não tiverem rodado com e sem Atlas E os dados não forem
confiáveis. Se um quebrar, identifique rápido, ache a causa-raiz, conserte, prove.

## LEI SUPREMA: nada quebra em silêncio
Todo teste/unidade DEVE deixar log legível (stdout E stderr capturados em arquivo).
Todo recibo de falha DEVE nomear a razão (`failure_reason`) — hoje vários vêm
vazios, e isso é "quebrou sem ninguém saber", o que causou o mês perdido. Elimine
isso: se algo falha, o log tem que dizer o quê e por quê, na hora.

## ENTENDA CADA BENCHMARK ANTES DE MEXER
Acesse a documentação de cada uma das 10 suítes (terminal_bench, bfcl,
aider_polyglot, hal_harness, swe_bench_live, inspect_evals, live_code_bench,
tau2_bench, senior_swe_bench, swe_marathon) — os repos clonados vivem em
`tools/rivals/benchmarks/<suite>/` (README, docs, CLI `--help`). Entenda: como a
suíte espera ser rodada, qual o formato de resposta/coleta que o corretor exige,
e por que o braço com-Atlas (via adapter/bridge/endpoint) pode não bater com esse
formato. O mapa canônico dos braços está em
`docs/engineering-knowledge-base/rivals-atlas-arm-implementation-guide.md`.

## CANAL DE COORDENAÇÃO COM O CLAUDE (obrigatório, é assim que vocês trabalham juntos)
Leia `docs/rivals-warroom.md` INTEIRO antes de tocar em qualquer coisa. É o
blackboard compartilhado; o Claude já semeou o estado atual das 10 suítes, as
causas-raiz já provadas (com commit), os handoffs e uma correção pública de um
erro dele (deduziu "single-shot" e estava errado — a lição é: leia o log).
Protocolo, sempre:
- RESERVE na §3 (Claims) antes de editar um alvo. Um alvo tem um dono por vez.
- REGISTRE na §5 (Log) ao descobrir/consertar: sintoma → causa-raiz PROVADA (cite
  o comando/log) → fix → prova (comando + saída).
- Atualize o §4 (board por suíte) quando o estado mudar. Use §6 (Handoffs) se
  precisar de algo que o Claude reservou — não pise no alvo dele.

## SEU FOCO (complementa o Claude; a tabela de Claims é a verdade)
- Pipeline de execução, `NativeResultNormalizer`, report, `AtlasArenaDrainCommand`,
  e o harness harbor (senior_swe_bench, swe_marathon).
- A garantia de logging da LEI SUPREMA acima, em TODOS os runners
  (`rivals-native-runner.php`, os unit-scripts, os agentes python).
- Cross-check dos fixes do Claude (adapters/bridge/endpoint). Buraco → §5 + §6.
- Prioridades abertas (pegue e reserve): senior_swe/marathon quando fecharem
  (auditar o agente harbor com-Atlas: patch aplicado? reward? tokens?);
  `failure_reason` nomeada em todo env_failure; rodar 1 unidade de cada suíte ✅ e
  confirmar log + recibo honesto.

## REGRAS PÉTREAS (atlas-server)
- Branch local `main` SEMPRE. Zero branch de obra, zero merge. `git branch
  --show-current` = `main` antes de commit. Commits escopados (`git add --
  <arquivos>`), mensagem explica o PORQUÊ.
- Testes verdes antes de commitar: `php artisan test tests/Feature/Ai/Arena` +
  o que você tocar.
- Sempre use os modelos que eu tenho (Verboo, tokens ilimitados). Rode de verdade,
  não simule.

## MÉTODO
Reproduza → leia o log → prove a causa → conserte → prove o conserto → registre no
war-room (append-only). Vigilância ativa: nada de confiar em silêncio. Trabalhe
até a DEFINIÇÃO DE PRONTO inteira ser verdade. Este é o mês que acaba.
