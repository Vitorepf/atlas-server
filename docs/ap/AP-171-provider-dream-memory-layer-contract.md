# AP-171 - Provider Dream Memory Layer Contract

Status: foundation-registry-implemented

## Problema

O Atlas usa providers como motores de execucao, especialmente Claude/Sonnet em
fluxos gerais. A memoria canonica, porem, pertence ao Atlas. Quando providers
passarem a oferecer memoria refletida/curada, como Claude Managed Agents Dreams,
o Atlas deve capturar o ganho composto sem entregar soberania de memoria ao
provider.

A tese: Atlas melhora o contexto enviado ao provider; o provider usa sua
memoria operacional para servir melhor ao Atlas; outputs melhores geram melhor
curadoria no Atlas; o proximo ciclo fica mais forte.

## Regra Constitucional

Claude, Codex, Gemini, GPT ou qualquer provider podem ter working memory, mas
nenhum provider e fonte soberana do Atlas.

Camadas oficiais:

```text
Atlas Canonical Memory
  fonte soberana: docs canonicos, Postgres Memory Registry, Evidence Ledger,
  Code Intelligence, context packs e promocao revisavel
        |
        v
Provider Projection
  recorte provider-safe, orcado, redigido, versionado e regeneravel
        |
        v
Provider Working Memory
  memoria operacional por provider sobre como servir melhor ao Atlas
        |
        v
Provider Dream / Reflection
  processo externo que reorganiza working memory e sugere deltas
        |
        v
Atlas Review Gate
  diff, riscos, Inbox, Decision Receipt, Evidence Ledger e promocao humana
```

## Definicoes

`Atlas Canonical Memory` e a verdade duravel. Ela responde: o que e verdadeiro,
auditavel e reutilizavel sobre Vitor, Atlas, projetos, decisoes, arquitetura,
resultados e aprendizados?

`Provider Projection` e um pacote controlado que o Atlas envia para um provider.
Ela pode conter memorias provider-safe, regras de operacao, docs resumidos,
context refs e limites de privacidade. Pode ser regenerada e nunca vence o
Memory Core.

`Provider Working Memory` e memoria operacional do provider. Ela responde: como
este provider deve interpretar o Atlas, reduzir retrabalho, obedecer docs,
preservar estilo, evitar erros conhecidos e escolher melhor formato de output?

`Dream` e uma capability externa de reflexao. Ela pode reorganizar sessoes e
working memory do provider, mas sua saida e sempre candidate/proposal.

## Contrato Com Claude Dreams

Quando Anthropic Managed Agents Dreams estiver disponivel para uso operacional,
o Atlas pode registra-lo como capability externa:

- provider: `anthropic_managed_agents`;
- capability: `provider_dream_memory`;
- external_feature: `claude_managed_agents_dreams`;
- status inicial: `research_preview_or_unavailable`;
- autoridade: `proposal_only_atlas_memory_remains_source_of_truth`.

O Atlas nunca deve permitir que Dreams escreva direto em:

- `atlas_memory_entries`;
- docs canonicos;
- Evidence Ledger como evento factual final;
- provider projections canonicas;
- policy/model routing;
- Decision Receipt historico.

Dreams so pode produzir:

- candidate provider working memory;
- candidate memory deltas;
- contradiction report;
- stale-memory report;
- provider behavior lessons;
- recommendation para Inbox/Curator.

## Fluxo Operacional Futuro

1. Atlas seleciona janela de sessoes, traces e context packs.
2. Atlas cria export provider-safe para o provider dream job.
3. Atlas registra `PROVIDER_DREAM_REQUESTED` no Evidence Ledger.
4. Provider executa Dreams em memoria externa/working memory.
5. Atlas recebe output como `provider_dream_candidate`.
6. Atlas calcula diff contra Memory Core, provider projection e ultimas sessoes.
7. Atlas classifica cada delta: promote, provider_only, reject, needs_human.
8. Atlas emite Inbox review quando houver risco, contradicao ou promocao.
9. Humano aprova ou rejeita.
10. Atlas promove somente via Memory Delta Promotion revisavel.
11. Atlas registra `PROVIDER_DREAM_REVIEWED` e, se aplicavel,
    `MEMORY_DELTA_PROMOTED`.

## Schemas Alvo

### `atlas.provider_dream.request.v1`

Campos obrigatorios:

- `provider`;
- `capability`;
- `input_memory_projection_id`;
- `input_session_refs`;
- `privacy_policy_version`;
- `instructions_hash`;
- `requested_by`;
- `occurred_at`.

### `atlas.provider_dream.candidate.v1`

Campos obrigatorios:

- `provider`;
- `external_job_id`;
- `status`;
- `output_memory_store_ref`;
- `candidate_delta_count`;
- `contradiction_count`;
- `stale_candidate_count`;
- `provider_only_lesson_count`;
- `raw_output_stored=false`;
- `review_required=true`.

### `atlas.provider_dream.review.v1`

Campos obrigatorios:

- `candidate_id`;
- `approved_delta_count`;
- `rejected_delta_count`;
- `provider_only_delta_count`;
- `memory_core_promotions`;
- `provider_projection_promotions`;
- `decision_receipt_id`;
- `reviewer`;
- `reviewed_at`.

## O Que Pode Virar Memoria Do Provider

Pode ficar em working memory do Claude:

- como responder ao Atlas com menos retrabalho;
- como respeitar APs, docs mae e limite de linhas;
- erros recorrentes do Claude ao trabalhar com o Atlas;
- formatos que reduziram revisao humana;
- preferencias operacionais de Vitor para este provider;
- heuristicas de quando pedir contexto, chamar tool ou parar;
- sinais de que outro provider deve assumir a tarefa.

## O Que So Pode Virar Memoria Do Atlas

So o Atlas pode promover:

- decisoes duraveis sobre produto, arquitetura, Blackink ou vida pessoal;
- regras canonicas de Kernel, Memory, Domain, Policy ou Evidence;
- mudancas de dominio/flow;
- fatos de resultado, sucesso, falha, custo ou benchmark;
- preferencias globais que devem valer para todos os providers;
- qualquer coisa que altere prompts canonicos, docs ou runtime.

## Riscos E Gates

Riscos principais:

- provider aprender preferencia temporaria como regra permanente;
- provider otimizar para agradar em vez de acertar;
- loops que reforcam erro anterior;
- contradicao com docs canonicos;
- vazamento de contexto nao provider-safe;
- output externo virar memoria sem revisao;
- Claude ficar melhor para si, mas pior para Atlas multi-provider.

Gates obrigatorios:

- privacy/redaction antes de exportar;
- no raw secrets, no raw audio, no raw private transcript;
- diff estruturado antes de review;
- Inbox quando houver promocao para Memory Core;
- Decision Receipt para qualquer promocao aprovada;
- Evidence Ledger para request, candidate, review e promotion;
- rollback por projection id/checksum;
- AP-99 cost/performance tracking do dream job.

## Relacao Com Dynamic Compute Market

Provider Dreams deve alimentar o Dynamic Compute Market como sinal, nao como
decisao. Se Claude com working memory otimizada performar melhor, o Atlas pode
abrir finding:

`provider_dream_improved_provider_performance`

Esse finding recomenda benchmark/promocao revisavel. Ele nao troca provider,
nao altera Atlas Decide e nao escreve policy sozinho.

## Definition Of Done Para Implementacao

Quando a capability estiver disponivel:

1. Registrar provider/capability em um External Agent/Capability Registry.
2. Criar command/API report-only para status e dry-run de dream job.
3. Criar export provider-safe de sessoes/context packs.
4. Criar service que solicita dream job sem escrever memoria canonica.
5. Persistir candidate output como proposal/diff, nao como fato.
6. Gerar Inbox review com source refs e rollback refs.
7. Promover deltas somente por Memory Delta Promotion existente.
8. Registrar eventos no Evidence Ledger.
9. Integrar AP-99 para custo, latencia e qualidade do dream job.
10. Adicionar Self-Improvement finding para stale/contradictory provider memory.
11. Cobrir com testes de privacy, no-direct-write, review-required e rollback.
12. Atualizar provider projections apenas apos aprovacao.

## Fundacao Implementada

Implementado como bloco lateral seguro, sem API, MCP, scheduler, Atlas Decide,
Memory Core write ou provider projection write:

- `ExternalProviderCapabilityRegistry`: registry read-only de capabilities
  externas e adoption candidates;
- `ExternalProviderCapability`: value object provider-safe;
- `ExternalProviderCapabilityRegistryTest`: cobre Dreams como
  `proposal_only`, finance agents como vertical agent benchmarkavel, filtros e
  fail-closed para capability desconhecida.

O registry registra `anthropic_claude_dreams` como
`provider_dream_memory/research_preview` e exige `memory_diff_review`,
`decision_receipt_before_promotion`, `human_review` e
`availability_confirmation`. Ele nao executa Dreams, nao escreve memoria e nao
altera roteamento.

## Nao Escopo

Esta AP nao implementa Dreams agora, nao assume disponibilidade da Anthropic,
nao cria memoria compartilhada unica entre Atlas e Claude e nao autoriza
sincronizacao bidirecional sem review. Enquanto Dreams estiver indisponivel ou
em research preview, o Atlas deve tratar tudo como capability futura.

## Fonte Externa

- Anthropic Managed Agents Dreams docs:
  `https://platform.claude.com/docs/en/managed-agents/dreams`
