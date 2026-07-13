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
