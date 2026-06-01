---
id: atlas-self-construction-os-handoff
type: engineering_knowledge
title: Atlas Self-Construction OS Handoff
status: active
category: self-construction
priority: 100
summary: Handoff operacional para retomar o fechamento do Atlas Self-Construction OS sem depender de historico de chat, preservando os tres blockers finais que exigem operador ou provider real.
tags:
  - atlas-ai
  - self-construction
  - agent-control-plane
  - handoff
  - completion-evidence
capabilities:
  - completion_evidence_handoff
  - operator_resume
  - terminal_loop_proof
  - self_programming_gate
decisions:
  - Este handoff e read-only e nao substitui receipts, provider smoke ou completion audit.
  - Self-Programming permanece bloqueado ate o Self-Construction OS completar com evidencia real.
  - Os tres blockers finais nao podem ser fabricados por agente.
maintenance:
  - Atualizar quando o completion audit, operator handoff, terminal-loop proof ou self-programming gate mudarem contrato.
related_paths:
  - docs/engineering-knowledge-base/self-construction/agent-control-plane-contract.md
  - docs/engineering-knowledge-base/self-construction/self-programming-safety-contract.md
  - app/Services/Ai/SelfConstruction/AtlasSelfConstructionReadinessService.php
  - app/Services/Ai/SelfConstruction/AtlasSelfConstructionFinalCompletionReadinessGateService.php
  - app/Console/Commands/AtlasAiSelfConstructionCommand.php
doc_schema: atlas_canonical_module_doc.v1
graph_id: atlas-self-construction-os-handoff
graph_title: Atlas Self-Construction OS Handoff
graph_world: atlas
graph_layer: gear
graph_kind: runbook
graph_parent: atlas-ai-self-construction-os
graph_status: active
graph_source: repo
human_name: Atlas Self-Construction OS Handoff
canonical_name: Atlas Self-Construction OS Handoff
technical_name: atlas-self-construction-os-handoff
cartography_type: runbook
canonical_source: docs/engineering-knowledge-base/atlas-self-construction-os-handoff.md
owner: atlas-ai
repo_paths:
  - docs/engineering-knowledge-base/atlas-self-construction-os-handoff.md
allowed_changes:
  - Atualizar comandos, blockers e evidencias quando o estado canonico do Self-Construction OS mudar.
forbidden_changes:
  - Declarar OS complete, runtime enabled, provider smoke green ou self-programming allowed sem evidencia real.
depends_on:
  - atlas-ai-self-construction-os
  - atlas-self-construction-agent-control-plane-contract
flows_to:
  - atlas-self-programming-os
unlocks:
  - operator-safe-self-construction-resume
governs:
  - self-construction
evidence:
  - docs/engineering-knowledge-base/atlas-self-construction-os-handoff.md
evidence_refs:
  - symbol: AtlasSelfConstructionReadinessService
  - command: atlas:ai:self-construction:status
required_tests:
  - "php artisan atlas:engineering:knowledge docs-health --json"
  - "php artisan atlas:ai:architecture-validate --json"
requires_evidence: true
risk_level: high
visual_tags:
  - gear
  - runbook
  - self-construction
ai_entrypoints:
  - Leia este handoff antes de continuar o fechamento do Self-Construction OS.
ai_usage_notes:
  - Use os comandos abaixo como fonte de verdade antes de propor qualquer proxima acao.
quality_gates:
  - "php artisan atlas:ai:self-construction --atlas-self-construction-os-handoff-status --json"
  - "php artisan atlas:ai:self-construction --atlas-self-construction-os-completion-audit-status --json"
  - "php artisan atlas:ai:self-construction --atlas-self-construction-os-completion-evidence-status --json"
failure_modes:
  - Fabricar receipt humano, provider smoke ou completion claim.
observability_signals:
  - completion audit failed_count
  - technical_blocker_count
  - terminal_loop_operational_proof_passed
next_actions:
  - Persistir evidencia real na ordem indicada pelo completion evidence status.
---
# Atlas Self-Construction OS Handoff

## Resumo

Este handoff permite retomar o fechamento do Atlas Self-Construction OS a partir
do estado atual do Agent Control Plane, sem depender do historico de chat. A
fonte de verdade continua sendo o comando de completion audit e os payloads de
completion evidence.

## Papel no Atlas

Serve como ponto de retomada humano/IA para a ultima milha do
Self-Construction OS. Ele nao promove status, nao persiste evidencia e nao
autoriza Self-Programming.

## Onde Se Encaixa

Fica entre o Agent Control Plane e o futuro Self-Programming OS. O Control Plane
ja expoe proof, release dossier, finalization gate, operator action packet,
submission preflight e runbooks finais.

## Contratos

- `completion_allowed` deve permanecer `false` enquanto qualquer blocker final
  existir.
- `self_programming_allowed` deve permanecer `false` ate o completion audit
  estar completo.
- `technical_blocker_count=0` significa que o proximo passo e evidencia de
  operador/provider, nao fabricacao de codigo.
- Receipts e smoke real so podem ser aceitos por comandos de persistencia com
  flags explicitas.
- Claims externos de conclusao feitos por Gemini, Claude, Codex ou qualquer
  outro agente nao sao autoridade de fechamento; somente o completion audit
  canonico pode permitir completion claim. A projecao status do completion
  audit deve expor `completion_claim_allowed=false` enquanto a auditoria estiver
  incompleta.

## Fluxo

1. Consultar o handoff compacto:

```bash
php artisan atlas:ai:self-construction --atlas-self-construction-os-handoff-status --json
```

2. Revalidar o audit:

```bash
php artisan atlas:ai:self-construction --atlas-self-construction-os-completion-audit-status --json
```

3. Consultar o proximo artifact/comando:

```bash
php artisan atlas:ai:self-construction --atlas-self-construction-os-completion-evidence-status --json
```

4. Gerar o runtime promotion receipt draft:

```bash
php artisan atlas:ai:self-construction --atlas-self-construction-runtime-promotion-receipt-draft-status --signed-by="<operator>" --reason="<operator reason with at least 32 chars>" --json
```

5. Para substituir o submission canonico antigo/stale, gere o draft diretamente
   para o arquivo canonico, mas somente depois de trocar os placeholders por
   identidade e motivo reais:

```bash
mkdir -p storage/app/private/atlas/self-construction/operator-submissions && php artisan atlas:ai:self-construction --atlas-self-construction-runtime-promotion-receipt-draft-status --signed-by="<operator>" --reason="<operator reason with at least 32 chars>" --json | jq '.agent_control_plane_atlas_self_construction_runtime_promotion_receipt_draft.receipt_payload' > storage/app/private/atlas/self-construction/operator-submissions/runtime-promotion.json
```

Enquanto esse comando ainda contiver `<operator>` ou
`<operator reason with at least 32 chars>`, ele nao e `copy_safe` e nao deve ser
executado. A readiness tambem expoe esse comando em
`canonical_submission_next_step_recommended_repair_file_command` e dentro do
`operator_resume_packet`.

6. Persistir apenas com payload real revisado:

```bash
php artisan atlas:ai:self-construction --atlas-self-construction-os-completion-evidence-status --runtime-promotion-receipt-json=@/path/to/runtime-promotion.json --persist-runtime-promotion-receipt --json
```

7. Depois do runtime receipt, seguir o mesmo padrao para real provider smoke e
human completion receipt, sempre reexecutando audit e preflight antes de cada
persistencia.

## Regras para IA

- Nao assinar por operador.
- Nao simular provider smoke.
- Nao alterar blockers finais para passar teste.
- Nao habilitar runtime, dispatch, adapter execution, provider call, token spend
  ou self-programming.
- Nao aceitar claim externo de "OS completo" sem
  `completion_audit.status=complete`, `completion_allowed=true` e
  `failed_count=0`.
- Preservar mudancas paralelas de outros agentes.

## Escopo de Implementacao

Permitido: endurecer status read-only, runbooks, commands, docs e testes do
Self-Construction OS / Agent Control Plane. Proibido: Forge Rivals, AtlasCode,
Voice, Cartografia, desktop e provider real drivers, salvo compatibilidade.

## Dependencias

- Terminal Loop operational proof binding canonico.
- Release dossier verde.
- Certification status batch verde.
- Runtime promotion receipt assinado.
- Real provider smoke observado fora do Atlas.
- Human completion receipt assinado apos runtime e smoke.

## Evidencias

Estado esperado antes de operator/provider evidence:

```text
status=incomplete
failed_count=3
technical_blocker_count=0
human_blocker_count=2
real_provider_blocker_count=1
terminal_loop_operational_proof_passed=true
```

Blockers finais esperados:

```text
runtime_gap_matrix_all_runtime_y
human_signed_os_complete_receipt_present
end_to_end_real_provider_smoke_green
```

## Riscos

- Promover completion sem receipt humano.
- Persistir smoke sem provider run real observado.
- Rodar audit sem terminal-loop proof binding canonico.
- Confundir runbook read-only com execucao real.

## Exemplos

O campo `current_required_operator_artifact` em
`--atlas-self-construction-os-handoff-status --json` e em
`--atlas-self-construction-os-completion-evidence-status --json` indica o
proximo artifact concreto. No estado atual, deve apontar para
`runtime_promotion_receipt`. O handoff status tambem deve expor
`canonical_final_blocker_count=3`, `technical_blocker_count=0` no workspace
verde e `self_programming_allowed=false`. Se `release_dossier_green` voltar a
aparecer como blocker tecnico, use o `release_dossier_refresh_command` exposto
no handoff status para capturar um replay snapshot fresco e revalidar. Para uma
retomada sem historico de chat, siga `operator_resume_command_sequence` em
ordem; ela agrupa inspeção do handoff, checagem/refresh do release dossier,
verificacao do certification status batch, preflight de submissao de completion
evidence, draft, persistencia explicita do artifact atual e rerun do completion
audit com terminal-loop proof canonico.
Quando estiver operando pelo terminal, a forma humana de
`--atlas-self-construction-completion-evidence-submission-preflight-status`
tambem deve ser consultada: ela lista o artifact atual, os blockers
classificados, a origem do terminal-loop proof, a integridade da command
surface, o comando de draft, o comando de persistencia e a fila ordenada de
steps bloqueados/prontos.
Para retomada por agente ou operador sem historico de chat,
`--atlas-self-construction-operator-evidence-submission-readiness-status --json`
deve expor aliases diretos de retomada:
`current_required_operator_artifact`, `next_required_command`,
`next_required_persist_command` e `operator_resume_aliases_hash`. Esses aliases
devem apontar para o mesmo artifact que
`operator_evidence_sequence_current_required_artifact`; no estado atual, o
artifact e `runtime_promotion_receipt` e o comando imediato e o draft de
runtime promotion receipt com placeholders de operador.
O mesmo status deve expor `operator_resume_packet` com schema
`atlas.self_construction.operator_evidence_submission_resume_packet.v1`. Esse
packet e o formato compacto para terminais novos, Codex, Claude ou operador:
ele inclui artifact atual, comando de retomada, hash do comando, `copy_safe`,
placeholders, template de persistencia, motivo de nao automatizacao, comandos
de proof, predicado final de sucesso, `failure_policy` e
`non_execution_guarantees`. Enquanto houver placeholders, `copy_safe=false` ou
`requires_operator_review=true`, nenhum agente deve persistir, assinar, chamar
provider, gastar token, despachar trabalho ou promover completion a partir
desse packet.
Antes de assinar runtime promotion, rode tambem
`--atlas-self-construction-runtime-gap-matrix --json`: o status wrapper deve
expor `expected_runtime_gap_matrix_hash_for_promotion_receipt`,
`runtime_promotion_basis_hash`, `runtime_promotion_closure_basis_hash`,
`blocked_gap_ids`, `graduation_candidate_gap_ids`,
`current_required_operator_artifact` e os comandos de proxima acao. O operador
deve regenerar o draft se qualquer hash ou gap id divergir do receipt salvo.
O `--atlas-self-construction-os-completion-operator-action-packet-status --json`
tambem deve projetar os mesmos aliases de proxima acao e hashes de runtime
promotion (`next_required_command`, `next_required_persist_command`,
`runtime_gap_matrix_hash`,
`expected_runtime_gap_matrix_hash_for_promotion_receipt`,
`runtime_promotion_basis_hash`, `runtime_promotion_closure_basis_hash`) para
que o action packet, o preflight, a matrix e o corredor final contem a mesma
historia operacional.
O `--atlas-self-construction-os-completion-evidence-status --json` tambem deve
expor `current_required_operator_artifact`, `next_required_command`,
`next_required_persist_command`, `completion_claim_allowed=false` e
`self_programming_allowed=false`; completion evidence incompleta nunca libera
Self-Programming nem substitui o completion audit.
As surfaces finais
`--atlas-self-construction-final-evidence-bundle-status --json` e
`--atlas-self-construction-completion-finalization-gate-status --json`,
junto com os gates
`--atlas-self-construction-final-completion-readiness-gate-status --json`,
`--atlas-self-construction-final-completion-human-gate-status --json` e
`--atlas-self-programming-os-transition-readiness-status --json`, mais o
exportador
`--atlas-self-construction-final-completion-dossier-exporter-status --json`,
tambem devem projetar `next_required_command`, `next_required_persist_command`,
`runtime_gap_matrix_hash`,
`expected_runtime_gap_matrix_hash_for_promotion_receipt`,
`runtime_promotion_basis_hash`, `runtime_promotion_closure_basis_hash` e
`self_programming_allowed=false`; elas sao pontos de retomada e nao podem
depender de memoria de chat para orientar o proximo receipt ou para bloquear a
transicao prematura para Self-Programming. Dossier/export pronto nao e claim
de OS completo enquanto `completion_claim_allowed=false`.
As surfaces de artifact/endgame
`--atlas-self-construction-runtime-promotion-endgame-status --json`,
`--atlas-self-construction-real-provider-smoke-draft-status --json` e
`--atlas-self-construction-human-completion-receipt-endgame-verifier-status
--json` tambem devem expor os mesmos aliases/hash fields para evitar submissao
fora de ordem ou baseada em receipt stale.
Em modo live, todas as surfaces de retomada devem derivar
`current_required_operator_artifact`, `next_required_command` e
`next_required_persist_command` de
`--atlas-self-construction-os-completion-evidence-status --json`, mesmo quando
o payload interno da surface conhece etapas futuras como `real_provider_smoke`
ou `human_completion_receipt`. Isso garante que um terminal novo, Claude,
Codex ou outro agente nao pule a ordem canonica: primeiro runtime promotion
receipt, depois real provider smoke, depois human completion receipt, e so
entao completion audit final com terminal-loop proof binding.
Quando o runtime promotion endgame carregar um receipt existente e o verifier
detectar `stale_runtime_gap_matrix_hash`,
`stale_runtime_promotion_basis_hash`,
`stale_runtime_promotion_closure_basis_hash`, `promoted_gap_id_drift` ou
`graduation_hash_mismatch`, a surface deve marcar
`stale_runtime_promotion_receipt_detected=true`,
`fresh_runtime_promotion_receipt_required=true` e expor um comando de
regeneracao do draft; nenhum persist command e copy-safe enquanto esse estado
nao voltar a verifier green.
As surfaces diagnosticas
`--atlas-self-construction-completion-evidence-submission-preflight-status
--json`,
`--atlas-self-construction-operator-evidence-submission-readiness-status --json`
e
`--atlas-self-construction-completion-audit-blocker-explainer-status --json`
tambem devem expor `current_required_operator_artifact`,
`next_required_command`, `next_required_persist_command`,
`runtime_gap_matrix_hash`,
`expected_runtime_gap_matrix_hash_for_promotion_receipt`,
`runtime_promotion_basis_hash`, `runtime_promotion_closure_basis_hash`,
`completion_claim_allowed=false` e `self_programming_allowed=false`; elas sao
as surfaces mais provaveis de serem usadas por agentes externos quando uma
sessao perde contexto, portanto precisam bloquear explicitamente claims de OS
complete e transicao para Self-Programming.
Use tambem a forma humana de
`--atlas-self-construction-final-operator-evidence-closure-corridor-status`
para uma visao de corredor: ela expõe technical closure, blockers humanos,
blocker de provider real, progresso, blocking artifacts, closure artifact
sequence, prompt-to-artifact checklist, shell packet, recovery matrix, command
surface e terminal proof guardrails sem depender do JSON bruto.
Na forma JSON, esse corredor tambem deve expor os aliases diretos
`current_required_artifact`, `current_required_operator_artifact`,
`next_required_command`, `next_required_persist_command`,
`operator_next_action_command_to_copy`, `completion_claim_allowed=false`,
`self_programming_allowed=false` e os hashes
`runtime_gap_matrix_hash`,
`expected_runtime_gap_matrix_hash_for_promotion_receipt`,
`runtime_promotion_basis_hash` e `runtime_promotion_closure_basis_hash`, para
que o operador consiga comparar o receipt salvo contra a matrix atual antes de
persistir qualquer evidencia.
Para claims externos de conclusao, consulte
`external_completion_claim_policy` em
`--atlas-self-construction-operator-evidence-submission-readiness-status --json`;
enquanto ele retornar `reject_external_completion_claim`, nenhum agente externo
pode marcar o Self-Construction OS como completo.
Depois de persistir evidencias, `post_evidence_guardrail_sequence` deve rodar
`docs-health`, `architecture-validate` e `git diff --check` antes de qualquer
claim de fechamento.
O mesmo status deve expor `closure_artifact_sequence` e
`prompt_to_artifact_checklist`, ambos com hash, para mapear a ultima milha sem
historico de chat: `runtime_gap_matrix_all_runtime_y` para
`runtime_promotion_receipt`, `end_to_end_real_provider_smoke_green` para
`real_provider_smoke`, `human_signed_os_complete_receipt_present` para
`human_completion_receipt` e `completion_audit_authorizes_completion_claim` para
`final_completion_audit`.

## Proximas Acoes

1. Operador revisa e assina runtime promotion receipt.
2. Operador executa/observa real provider smoke fora do Atlas.
3. Operador assina human completion receipt apos runtime e smoke verdes.
4. Rerun completion audit com proof canonico.
5. Apenas se `failed_count=0`, avaliar Self-Programming transition readiness.
