# Atlas Rivals — Open Gaps (v1)

> Status vivo pós-obra 13/07/2026 (revisão + correção da máquina de 10 benchmarks).
> Fonte: sessão de revisão do operador; commits `4e1f44dcfd` (rivals) e `dd4dfafa0f` (kernel).

## Resolvido em 13/07 (não reabrir sem evidência nova)

- Arm não-bare em suíte sem runtime Atlas agora **falha fast no plan** (pré-spend);
  antes rodava bare disfarçado e o RuntimeProofAttacher abortava a suíte inteira
  como `environment_failure` mesmo com reward 1.0 (tau2).
- Case pack terminal_bench apontava para tasks inexistentes no dataset
  `terminal-bench-core==0.1.1` (`git-bisect`, `kernel-config`) → `git-multibranch`.
- Provider-lock estrito restaurado no bridge (caminho atlas:cli:dev).
- Ledger semântico re-apontado após quarentena de epoch (13/07 16:53); chain verified.
- Report enterprise agora declara `atlas_uplift.bare_only_suites` (5/10 suítes sem
  família de uplift são bare-only POR CONSTRUÇÃO, não gap silencioso).
- Modo oficial `battery --mode/kind=atlas` (atlas_dev-only nas uplift_families).
- Kernel Atlas Dev mutativo destravado em 8 camadas (venv semantic_rag, 57 tabelas
  wiped re-migradas, prompt real com goal+arquivos+contrato, parser tolerante a
  stream de raciocínio, preimage do sandbox, lint poliglota, behavioral opcional,
  allowed_files a partir de arquivos citados no pedido).

## Endurecimento de confiança do relatório (14/07 — não reabrir)

Objetivo do operador: benchmark de capacidade **mais completo e confiável do mundo,
sem ambiguidade nem perigo de sugerir precisão falsa**. Entregue nesta sessão:

- **Faixa de confiança 95% (Wilson) por capacidade** — card mostra `45% · faixa
  provável 31–60%`. Um % cru sobre amostra pequena escondia a margem. Reusa
  `StatisticalPolicy::wilson`.
- **Headline "fato medido" magnitude-aware** — antes dizia "Atlas melhorou mais
  vezes (2↑/1↓)" sob rótulo "confirmado" enquanto o saldo médio era NEGATIVO.
  Agora contagem e magnitude precisam concordar; divergência → "dividido" + saldo
  médio em pp (o árbitro do sinal). Mesma lógica da capa.
- **Par diagnóstico separado do fato confirmado** — 3/5 pares eram `diagnostic_only`
  (2× ambos-0%, Aider excluído) e entravam na contagem/saldo/veredito (Aider +14.3
  contava como vitória silenciosa). Agora balde "só diagnóstico — não conta como
  fato"; veredito usa só pares confirmados → 1↑/1↓, saldo -27.8pp. Capa, stats e
  painel de fatos concordam.
- **Cor de veredito só em delta confirmado** — delta diagnóstico no card de
  capacidade (ex.: Programação +5.1pp, 100% diagnóstico) não pode ser verde de
  ganho; fica neutro + "não é ganho confirmado".
- **Delta de amostra pequena (<20 tarefas pareadas)** rotulado "tendência, não
  conclusão".

### RESOLVIDO 14/07: Raciocínio nunca falhou — provider errado (root cause + prova ao vivo)

`is_latest_model()` do inspect_ai (`inspect_ai/model/_openai.py`) trata **qualquer
nome não-OpenAI** como codename de fronteira da OpenAI ("treat any such
unrecognized name as the latest model") → `is_gpt_5()`=true → `system_role` vira
`developer` → Verboo devolve **400 unsupported_message_role** → "Task interrupted
(no samples completed)" → 9/9 environment_failure. **O modelo nunca foi chamado.**

Prova ao vivo (14/07): `openai-api/verboo/kimi-k2.7` → **9/9 accuracy 1.000** nos
3 casos gsm8k × 3 reps. O relatório dizia "Raciocínio 0%" — o **oposto exato** da
verdade (100%). Nenhum humano detectaria isso lendo o relatório.

Fix (commit `bdef912e5c`): `native_models.inspect_evals` = `openai-api/verboo/
kimi-k2.7` (provider p/ endpoint compatível de terceiros, lê `VERBOO_API_KEY` que
o `VerbooEnvironment` já exporta); removida a gambiarra `-M responses_api=false`;
novo task_type `math_reasoning` (gsm8k era `coding_patch`/`tool_use_function_
calling` — punha matemática dentro de Programação); teste trava o provider-lock.

**Pendente**: a medição só entra no relatório num run de bateria (`--mode=bare`);
o CLI `plan` usa `allowSynthetic=false` e recusa suítes sem repo snapshot — é a
bateria que passa `allowSynthetic: true`. Não fabricar receipt fora do harness.

**Lição transferível**: apontar um provider nativo (`openai/`) para endpoint
compatível de terceiros ativa heurísticas de modelo do vendor. Suspeitar sempre
que uma suíte inteira zerar com env-failure e wall_ms ~1s (modelo nem chamado).

### Investigação Raciocínio (inspect_evals) — histórico da caçada

`Raciocínio` mostra `—` / `não confiável` / n=0 (honesto — não afirma que o modelo
falha em raciocinar). Fixtures atuais (run 20260709) têm `score=null` sem string de
erro → o safety-net do `InspectEvalsAdapter` mapeia para `invalid_result`, fora de
qualquer claim de "modelo errou". A role `developer` (erro histórico HTTP 400 no
Verboo/kimi) NÃO vem da detecção de modelo do inspect: `is_o_series_model` /
`is_gpt_5_model` (`inspect_ai/model/_openai.py`) não casam com `openai/kimi-k2.7`.
Completar a medição de Raciocínio exige run inspect ao vivo contra Verboo (spend +
venv + reprodução do erro real) — bloqueado na bateria atual liberar recursos.

## Aberto

1. **Corte 22-role mutativa em workspace estrangeiro** —
   `kernel_mutative_role_receipt_binding_invalid` em
   `KernelEvidenceAuthority::issueMutativeRoleDisposition`. O pipeline mutativo do
   `atlas:cli:dev --efficient` chega verificado até
   `behaviorally_verified_pending_quality_court` e a corte recusa o binding de
   receipt de papel fora do repo Atlas. Enquanto isso, o braço `atlas_dev` do
   Rivals para modelos hermes usa o `hermes -z` one-shot do bridge
   (`execution: hermes_cli_oneshot`, disclosado no receipt) — que mede
   "modelo + prompt Atlas", não o runtime Atlas completo. Fechar a corte para
   workspaces estrangeiros é o que torna o uplift hermes um fato de runtime.
2. **Closure claim-grade da Fase A** — `native_run_10_of_10` e
   `uplift_families_5_of_5` exigem baterias com `internal_claim_allowed=true`
   (3 reps, zero env-failure, bundle verificado). Runs de 12/07 são medição real
   mas não claim-grade. É execução (horas de bateria), não código.
3. **Tokens**: `inspect_evals` não emite usage (harness omite; reportar N/A
   honesto); `live_code_bench`/`swe_marathon` corrigidos pendentes de re-run.
4. **Segundo modelo provado ponta-a-ponta** — "qualquer modelo" exige provar um
   segundo modelo (bare + atlas_dev) além de `verboo_kimi_k2_7`; model-matrix
   segue fail-closed até lá.
5. **Suite `tests/Unit/Ai/Programming/AtlasDev` tem 111 falhas pré-existentes na
   main** (artefatos de boot de facade/container + expectativas divergentes) —
   anterior a esta obra (baseline HEAD limpo = 111; working tree = 110).
