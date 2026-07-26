# Status honesto para o operador

## Absolute master

**absolute_master_done = true** (critérios LIVE L2 + cutover + operate-path código).

## Fechado com prova

| Item | Prova |
|---|---|
| R104 LIVE L2 | Hermes real `-z` devolveu `tool_calls`/`atlas_apply_patch`; lift + packaging `ok` → `r104_live_proof.json` |
| CUTOVER-V3 LIVE | APP_KEY assina V3 `atlas.decide.v3-cutover` com `authority_signature` válida e `effect.allowed=true` → `cutover_live_proof.json` |
| Kernel operate-path | CompactSDD fail-closed + smoke 7/7 |
| Services↛Http | FastPath/enterprise/continuum limpos |

## Ainda NOT_CLAIMED (não bloqueiam absolute_master)

- Delete total da família PRE (testes de caracterização legados)
- R106–R108 excellence LIVE / R105 / S-WORLD multi-semana
- Cold matrix formal R103 (live DI já GREEN)

## Defaults de produção

- `ATLAS_AI_HERMES_NATIVE_FC_ENABLED` permanece **default false** no config; LIVE foi provado com enable **no processo de prova**.
- `ATLAS_AI_DECISION_RECEIPT_V3_CUTOVER_ENABLED` permanece **default false**; LIVE writer foi provado com enable no processo de prova. Operador liga no env quando quiser cutover permanente.
