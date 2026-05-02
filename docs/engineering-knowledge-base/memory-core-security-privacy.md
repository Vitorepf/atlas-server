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
  - privacy_review
  - redaction
  - provider_safety
  - projection_guardrails
decisions:
  - Conteudo privado nunca deve depender de confianca implicita no provider.
  - Redaction deve ocorrer antes de context pack ou projection.
  - Segredos nao pertencem a memoria; pertencem a secret storage apropriado.
maintenance:
  - Atualize esta politica quando classes de privacy, redaction ou provider rules mudarem.
  - Teste qualquer relaxamento de policy com fixture dedicado.
related_paths:
  - app/Services/Ai/AtlasMemoryPrivacyService.php
  - app/Services/Ai/AtlasMemorySourcePrivacyPolicy.php
  - app/Services/Ai/AtlasProviderProjectionService.php
  - app/Services/Ai/AtlasProviderProjectionAuditService.php
  - config/atlas.php
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

Permitido:

- espelhar docs canonicos;
- exportar resumos provider-safe;
- usar projections geradas pelo Atlas.

Nao permitido:

- tratar nota solta como memoria canonica sem sync/promocao;
- promover conversa para memoria sem review;
- copiar segredos para docs canonicos ou provider files.

## Retencao E Arquivamento

- memoria errada deve ser arquivada, nao apagada silenciosamente;
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

