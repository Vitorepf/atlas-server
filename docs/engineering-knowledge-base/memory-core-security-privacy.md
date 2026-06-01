---
id: atlas-memory-core-security-privacy
type: engineering_knowledge
title: Atlas Memory Core Security And Privacy Policy
status: active
category: maintenance
priority: 98
summary: Politica operacional de seguranca, privacy, redaction e provider-safety para memoria, context packs e provider projections.
tags:
  - atlas
  - memory
  - privacy
  - security
capabilities:
  - memory_core_security_privacy
  - memory_privacy_review
  - redaction
  - provider_safety
  - projection_guardrails
decisions:
  - Conteudo privado nunca deve depender de confianca implicita no provider.
  - Captura bruta nasce inelegivel para memoria, contexto, embedding e projection.
  - Redaction deve ocorrer antes de context pack ou projection.
  - Segredos nao pertencem a memoria; pertencem a secret storage apropriado.
  - Delete/tombstone deve bloquear memoria, embedding, cache, resumo e Constelacao.
maintenance:
  - Atualize esta politica quando classes de privacy, redaction ou provider rules mudarem.
  - Teste qualquer relaxamento de policy com fixture dedicado.
related_paths:
  - docs/engineering-knowledge-base/memory/cognitive-immune-learning-kernel.md
  - app/Services/Ai/AtlasMemoryPrivacyService.php
  - app/Services/Ai/AtlasMemorySourcePrivacyPolicy.php
  - app/Services/Ai/AtlasProviderProjectionService.php
  - app/Services/Ai/AtlasProviderProjectionAuditService.php
  - config/atlas.php
doc_schema: atlas_canonical_module_doc.v1

graph_id: atlas-memory-core-security-privacy

graph_title: Atlas Memory Core Security And Privacy Policy

graph_world: atlas

graph_layer: module

graph_kind: policy

graph_parent: atlas-ai-canonical-architecture-index

graph_status: active

graph_source: repo
human_name: Atlas Memory Core Security And Privacy Policy
canonical_name: Atlas Memory Core Security And Privacy Policy
technical_name: atlas-memory-core-security-privacy
cartography_type: policy
canonical_source: docs/engineering-knowledge-base/memory-core-security-privacy.md

owner: maintenance

repo_paths:
  - docs/engineering-knowledge-base/memory-core-security-privacy.md

allowed_changes:
  - Atualizar este doc quando codigo, arquitetura, fluxo, evidencia ou decisao canonica mudar.

forbidden_changes:
  - Declarar runtime, maturidade ou prontidao sem evidencia verificavel e gates verdes.

depends_on:
  - atlas-ai-documentation-operating-system

flows_to:
  - atlas-cartography
  - atlas-code

unlocks:
  - ai-safe-implementation-context

governs:
  - maintenance

evidence:
  - docs/engineering-knowledge-base/memory-core-security-privacy.md
implementation_state: partial
evidence_refs:
  - symbol: AtlasMemoryPrivacyService
  - command: atlas:memory:privacy

required_tests:
  - "php artisan atlas:engineering:knowledge docs-health --json"

requires_evidence: true

risk_level: high

visual_tags:
  - module
  - policy
  - maintenance

ai_entrypoints:
  - Leia Resumo, Contratos, Regras para IA, Evidencias e Riscos antes de implementar.

ai_usage_notes:
  - Use repo_paths, allowed_changes, forbidden_changes e required_tests como limites operacionais.

quality_gates:
  - "php artisan atlas:engineering:knowledge docs-health --json"

failure_modes:
  - Contexto desatualizado entre doc, codigo, teste e evidencia.

observability_signals:
  - docs-health status ok

next_actions:
  - Manter este doc sincronizado com codigo, testes, evidencias e Cartografia.
---
# Atlas Memory Core Security And Privacy Policy

Esta politica define o que pode entrar na memoria, no prompt e em provider
projection. A regra e conservadora: se houver duvida, manter no Atlas e exigir
review antes de enviar para provider.

## Classes De Privacy

| Classe | Uso | Provider externo |
|---|---|---|
| `normal` | Conteudo operacional comum, docs publicos internos, decisoes nao sensiveis | Permitido se redigido/limpo |
| `private` | Conteudo pessoal, interno, dominio privado ou traces operacionais | Bloqueado por padrao via config atual |
| `sensitive` | Saude, financas, seguranca, segredos parciais, dados de alto risco | Bloqueado por padrao |
| `secret` | Tokens, credenciais, segredos, conteudo redigido automaticamente por risco | Sempre bloqueado |

Config atual relevante:

```text
ATLAS_PRIVACY_BLOCK_EXTERNAL_AI_FOR=private,sensitive
```

Mesmo que a env mude, `secret` deve continuar bloqueado.

## Defaults Por Fonte

| Fonte | Default |
|---|---|
| `ai_trace` | `private` |
| `ai_attachment` | `private` |
| `engineering_artifact` | `private` |
| `context_bundle` | `private` |
| `semantic_note` | `normal`, salvo frontmatter/policy |
| conteudo redigido automaticamente | `secret` |

## O Que Nunca Deve Entrar Em Provider

- tokens, API keys, secrets, cookies, session IDs;
- dados pessoais sensiveis sem review explicito;
- saude/financas quando classificados como `sensitive`;
- traces completos com input/output bruto;
- attachments e artifacts sem redaction;
- arquivos de provider projection nao gerenciados ou com drift nao revisado;
- memoria marcada como `external_ai_allowed=false`;
- verbatim bloqueado, pendente ou sem review quando houver risco.

## Redaction

Redaction deve produzir campos provider-safe:

- `redacted_title`;
- `redacted_summary`;
- `redacted_body`;
- `redaction_status`.

Se redaction mudar conteudo relevante, o item deve ir para review. Se a redaction
detectar segredo ou padrao arriscado, classificar como `secret` e bloquear
provider.

## Review Obrigatorio

Review e obrigatorio quando:

- `privacy_class` nao e `normal`;
- `redaction_status=redacted`;
- `external_ai_allowed=false`;
- o item vem de trace, attachment, artifact ou context bundle;
- um operador quer liberar projection com conteudo novo;
- uma memoria conflita com outra memoria ativa.

## Provider Projections

Provider projection e saida derivada, nao memoria primaria.

Regras:

- `preview` e `review` antes de `apply` ou `write`;
- `apply` requer confirmacao explicita;
- `write` nao deve sobrescrever arquivo humano sem adopt/force controlado;
- drift manual fora de bloco gerenciado bloqueia escrita segura;
- purge de auditoria requer fingerprint gerado por dry-run;
- operador pode ser exigido por policy configuravel.

## Obsidian E Ferramentas Externas

Obsidian, Claude, Codex, Cursor, ChatGPT e outros clientes podem consumir ou
espelhar memoria, mas nao sao fonte primaria.

Contrato detalhado: `obsidian-atlas-vault.md`.

Permitido:

- espelhar docs canonicos;
- exportar resumos provider-safe;
- usar projections geradas pelo Atlas.

Nao permitido:

- tratar nota solta como memoria canonica sem sync/promocao;
- promover conversa para memoria sem review;
- copiar segredos para docs canonicos ou provider files.
- exportar/importar nota do vault sem privacy, redaction, frontmatter e origem
  auditavel.

## Quarentena Cognitiva

Security/privacy tambem protege a qualidade cognitiva do Atlas. Captura bruta
de conversa, nota, audio, CLI ou surface nao pode ser assumida como memoria,
contexto, evidence, embedding ou projection.

Antes de qualquer uso cognitivo, o item deve passar pelos gates definidos em
`memory/cognitive-immune-learning-kernel.md`:

- classificar trivial, operacional, candidato real, privado, sensivel, secret,
  untrusted ou prompt injection;
- bloquear embedding para captura nao classificada, privada, secret, trivial,
  operacional efemera ou tombstoned;
- manter prompt injection como dado hostil, nunca como instrucao;
- exigir source refs, escopo, motivo e review state para toda promocao;
- registrar exclusao ou bloqueio quando o item quase entrou em contexto.

## Retencao E Arquivamento

- memoria errada deve ser arquivada, nao apagada silenciosamente;
- delete humano deve propagar tombstone para memoria, verbatim, embeddings,
  caches, summaries, context packs e Constelacao;
- projection audits podem ser purgados por politica, com dry-run/fingerprint;
- docs canonicos removidos devem arquivar knowledge items via `sync --prune`;
- code modules/symbols removidos devem arquivar via `index-code --prune`;
- conflitos resolvidos devem manter historico de review.

## Checklist Antes De Enviar Contexto A Provider

1. O item e necessario para a tarefa?
2. O item esta ativo e nao arquivado?
3. A privacy class permite provider?
4. Redaction foi aplicada?
5. O texto e curto o bastante para o budget?
6. Existe motivo de inclusao no context pack?
7. Existe rastro de auditoria se for memoria usada?
8. O item passou pela quarentena cognitiva e nao esta tombstoned/superseded?

## Resumo

Politica operacional de seguranca, privacy, redaction e provider-safety para memoria, context packs e provider projections.

## Papel no Atlas

Define a responsabilidade desta peca dentro da arquitetura Atlas.

## Onde Se Encaixa

Relaciona esta peca com seu sistema, camada, fluxo ou modulo pai.

## Contratos

Declara invariantes, entradas, saidas, limites e obrigacoes relevantes.

## Fluxo

Descreve o caminho operacional ou a sequencia de uso quando aplicavel.

## Regras para IA

Agentes devem respeitar escopo, evidencias, testes e proibicoes antes de alterar codigo.

## Escopo de Implementacao

Mudancas devem permanecer nos caminhos e limites declarados no frontmatter.

## Dependencias

Dependencias canonicas vivem em frontmatter e no corpo deste documento.

## Evidencias

Evidencias aceitas incluem docs, comandos, testes, receipts, reports e paths verificaveis.

## Riscos

Riscos principais devem ser tratados antes de promover status, runtime ou claims de prontidao.

## Exemplos

Exemplos concretos devem ser adicionados quando reduzirem ambiguidade para humanos ou IAs.

## Proximas Acoes

Proximas acoes devem ser concretas, verificaveis e ligadas a gates de qualidade.
