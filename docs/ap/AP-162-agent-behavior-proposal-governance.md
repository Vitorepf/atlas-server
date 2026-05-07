# AP-162 - Agent Behavior Proposal Governance

Status: implemented

## Problema

AP-157 fez o Self-Improvement consumir o read model de comportamento de agentes.
AP-159 criou o flow dedicado `agent_behavior_review`. AP-161 colocou o flow no
agendamento recorrente. Ainda faltava travar um detalhe importante: quando o
Curator emite uma proposta revisavel sobre comportamento de agente, a proposta
precisa carregar governanca explicita e nao pode parecer uma mudanca
autoaplicavel de prompt, provider, policy ou gate.

Sem esse contrato, uma IA futura poderia tratar findings comportamentais como
patch automatico, ou emitir uma proposta generica sem contexto suficiente para
review humano.

## Decisao

Findings `atlas.self_improvement.agent_behavior_replay.v1` passam a carregar:

- `available_actions[]` explicitas: `review_patch`, `discuss`, `discard`;
- `policy.auto_apply_behavior_change=false`;
- `policy.requires_operator_review=true`;
- `policy.requires_architecture_validate=true`;
- payload `agent_behavior_replay` com schema proprio, contagens, filtros,
  `review_signal` e bloco de governanca;
- source refs dos eventos `GATE_EVALUATED` que originaram a proposta;
- correlacao `LEARNING_PROPOSED.emitted_to_inbox` e
  `LEARNING_PROPOSED.emitted_inbox_item_id` quando `emit=true`.

## Contrato

`AtlasSelfImprovementRuntime::agentBehaviorReplayFindings()` e o unico lugar
autorizado para montar a proposta comportamental recorrente. Ele pode sugerir
revisao, discussao ou descarte, mas nao pode autoalterar comportamento critico.

Mudancas reais em `AgentBehaviorContract`, prompts, gates, provider routing ou
policy continuam exigindo PR, teste, `architecture-validate` e review humano.

## Anti-regressao

O scanner `ap162_agent_behavior_proposal_governance` falha se:

- a proposta perder `available_actions`;
- a policy deixar de declarar `auto_apply_behavior_change=false`;
- o payload dedicado `atlas.self_improvement.agent_behavior_replay.proposal_payload.v1` desaparecer;
- o teste de emissao para Inbox e link com `LEARNING_PROPOSED` for removido;
- a documentacao canonica deixar de citar AP-162.

## Resultado esperado

O Atlas fecha o ciclo entre comportamento real dos agentes, Evidence Ledger,
Curator, Inbox e review humano, preservando o principio: learning pode propor,
mas nao altera comportamento critico sem aprovacao.
