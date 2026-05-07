# AP-148 — Agent Behavior Identity Fragment

Status: implemented-identity-contract

## Problema

O Atlas ja possui `IdentityFragment` obrigatorio em todo `ProviderDriver`, mas
os principios de comportamento de agente ainda estavam documentados como backlog
em `atlas-ai-agent-behavior-contract.md`. Isso deixava espaco para cada surface
ou prompt local copiar uma versao diferente de "como programar bem".

## Contrato

`AgentBehaviorContract` e a fonte unica para o fragmento comportamental curto do
Atlas. Ele deve:

- declarar `atlas-ai.agent-behavior.v1`;
- conter os quatro principios: `Assumption Management`, `Simplicity Bias`,
  `Surgical Diff Discipline` e `Verifiable Goal Loop`;
- gerar `content_hash` deterministico;
- ser anexado por `AtlasProviderIdentityProjector` dentro do `IdentityFragment`
  de todo provider;
- aparecer em `identity_fragment.metadata.agent_behavior_contract`.

Esse contrato nao substitui gates de review nem implementa sozinho deteccao de
overengineering, diff lateral ou verificacao ausente. Ele e a primeira camada de
enforcement: todo provider recebe a mesma disciplina comportamental no ponto de
identidade do Kernel.

## Nao Escopo

AP-148 nao cria novo AI Domain, nao altera Atlas Decide, nao chama provider real
e nao injeta prompt local em surfaces. ABC-2 a ABC-5 continuam como backlog:
Programming Domain deve consumir esse contrato de forma explicita, Review Mode
deve mostrar checklist, e Quality Gates devem emitir findings comportamentais.

## Testes

- `ProviderDriverWrappersTest::test_identity_fragment_is_stable_and_hashable`
- `ProviderDriverWrappersTest::test_prepare_request_injects_identity_fragment_into_payload`
- `KernelArchitectureStaticScanner::scanAgentBehaviorIdentityFragment`
