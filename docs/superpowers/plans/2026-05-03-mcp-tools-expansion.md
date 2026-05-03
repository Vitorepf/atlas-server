# Atlas MCP Tools Expansion — Implementation Plan

> **For agentic workers:** REQUIRED SUB-SKILL: Use superpowers:subagent-driven-development (recommended) or superpowers:executing-plans to implement this plan task-by-task. Steps use checkbox (`- [ ]`) syntax for tracking.

**Goal:** Expandir o MCP `atlas-open-brain` de 3 para 10 tools, fechando o loop inbound→outbound entre Atlas (orquestrador) e engines (Claude Code/Codex/Gemini), priorizando o padrão de uso 90/10 (90% via Atlas, 10% direto).

**Architecture:** Mantém o padrão monolítico atual de `AtlasOpenBrainMcpService` (registry + router + private methods). Adiciona 7 tools novos em quatro categorias: write-back (1), descoberta de código/docs (2), discovery/meta (2), refinamento (2). Transporte STDIO local — sem HTTP, sem remoto. Cada tool delega para services existentes (`AtlasHybridMemoryRetrievalService`, `EngineeringCodeIntelligenceService`, `EngineeringKnowledgeBaseService`) — zero novos services de domínio.

**Tech Stack:**
- PHP 8.4+, Laravel 13.0
- PHPUnit 12.5.12 (não Pest), Mockery
- MCP protocol v2025-06-18 via JSON-RPC over STDIO
- Postgres (via `psql` em docker-compose)
- Models: `AtlasMemoryEntry`, `AtlasOpenBrainAccessLog`, `AtlasEngineeringCodeSymbol`, `AtlasEngineeringKnowledgeItem`

**Scope (in):**
- 7 novos tools MCP, todos em `app/Services/Ai/AtlasOpenBrainMcpService.php`
- Testes feature em `tests/Feature/Ai/AtlasOpenBrainMcpServiceTest.php`
- Curadoria de memória: 3 entries críticas (migrations, fresh > reparo, schema invariants) populadas via `atlas:memory:seed-core` ou comando análogo
- Re-projection CLAUDE.md/AGENTS.md depois da curadoria
- Validação end-to-end com Claude Code e Codex reais
- Benchmark de latência (cold start + per-tool)

**Scope (out):**
- HTTP+SSE transport (fica para fase 2 do MCP)
- Daemon/warm process pool (otimização separada — listada como follow-up)
- Modelo de identidade do consumidor (audit per-engine)
- Policy layer contextual além do `external_ai_allowed` binário
- Refator do MCP service em sub-services por domínio (YAGNI até o file passar de 800 linhas)
- Tools que dependem de infra ainda não construída (ex: ChromaDB, MCP remoto)

---

## The 5 Specialized Agents

Cada task é taggeada com o agente owner. Quando executando subagent-driven, dispatch o agente correspondente.

| ID | Nome | Foco | Tools que pode chamar |
|---|---|---|---|
| **A1** | **MCP Architect** | Define schemas, error taxonomy, capability contract, decisões de arquitetura inline. Reviews de PRs de outros agentes. | Read, Grep, ExitPlanMode |
| **A2** | **Backend Implementer** | Escreve PHP/Laravel — methods, registry entries, callTool routing, helpers. Segue padrões existentes do MCP service. | All Edit/Write tools |
| **A3** | **Test Engineer** | Escreve testes PHPUnit feature/unit. Garante coverage de happy path, validação, error cases. | Edit/Write + Bash (rodar testes) |
| **A4** | **Memory Curator** | Popula Atlas memory com regras canônicas do master CLAUDE.md. Re-projeta CLAUDE.md/AGENTS.md. Valida que recall retorna as regras. | Bash (artisan/atlas commands), Edit |
| **A5** | **Integration Validator** | Testa MCP via Claude Code CLI real, Codex CLI real. Mede latência cold/warm. Valida que regra imperativa fira. Reporta falhas. | Bash, Read |

**Handoffs entre agentes:**
- A1 entrega specs → A2 implementa → A3 testa → A1 reviews
- A4 roda em paralelo com A2/A3 (não depende deles)
- A5 só roda depois de A2 + A3 + A4 completos

---

## File Structure

**Modify:**
- `app/Services/Ai/AtlasOpenBrainMcpService.php` — adicionar 7 entries em `tools()`, 7 cases em `callTool()`, 7 private methods
- `app/Console/Commands/AtlasMemorySeedCoreCommand.php` (criar se não existir, ou estender existente) — seed das 3 regras canônicas
- `~/.claude/CLAUDE.md` — atualizar lista de tools mencionadas (10 ao invés de 3)
- `~/.codex/AGENTS.md` — idem
- `atlas-server/CLAUDE.md` (manual block) — idem
- `atlas-server/AGENTS.md` (manual block) — idem

**Create:**
- `tests/Feature/Ai/AtlasOpenBrainMcpServiceTest.php` — testes feature dos 7 novos tools
- `docs/superpowers/plans/2026-05-03-mcp-tools-expansion.md` — este arquivo (já criado)
- `docs/engineering-knowledge-base/mcp-tools-contract.md` — capability spec (output de A1 na Phase 0)
- `tests/Feature/Ai/AtlasOpenBrainMcpBenchmarkTest.php` — latency benchmarks (A5)

**No changes:**
- `app/Console/Commands/AtlasOpenBrainMcpCommand.php` — entry point já é genérico, não muda
- Models — usamos os existentes
- Migrations — schema atual suficiente

---

## Phase 0 — Foundation [Owner: A1 MCP Architect]

### Task 0.1 — Capability Spec Doc

**Files:**
- Create: `docs/engineering-knowledge-base/mcp-tools-contract.md`

- [ ] **Step 0.1.1: Criar doc de contrato dos 10 tools**

```markdown
# Atlas MCP Tools Contract v1.1

Protocol: MCP 2025-06-18, STDIO local only.
Server: atlas-open-brain.

## Tool inventory (10)

### Read-only (8)
| Name | Purpose | Stable |
|---|---|---|
| atlas_memory_recall | Hybrid recall (registry + verbatim + semantic) | ✅ existing |
| atlas_open_brain_context_pack | Audited context pack para tarefas | ✅ existing |
| atlas_memory_maintenance_status | Health check | ✅ existing |
| atlas_decision_query | Recall filtrado a type=decision | 🆕 |
| atlas_code_find_relevant | Search code symbols (lexical via catalog) | 🆕 |
| atlas_docs_lookup | Search knowledge base items | 🆕 |
| atlas_recent_changes | Files mudados recentemente no workspace | 🆕 |
| atlas_workspace_info | Metadata do workspace (Atlas-tracked? scopes?) | 🆕 |
| atlas_capabilities | Lista todos tools + schemas (capability negotiation) | 🆕 |

### Write (1)
| Name | Purpose |
|---|---|
| atlas_memory_record | Engine reporta decisão tomada → fecha loop |

## Error taxonomy

Todo tool retorna `{ok: bool, tool: string, ...}`. Erros:

| Error code | Meaning | Retry safe? |
|---|---|---|
| `query_required` | Argumento obrigatório ausente | Não — input bug |
| `workspace_not_atlas_tracked` | Workspace fora dos repos conhecidos do Atlas | Não — chamada inadequada |
| `index_stale` | Code/docs index desatualizado vs. filesystem | Sim depois de re-index |
| `provider_safe_filter_empty` | Recall encontrou matches mas todos foram redacted | Não — escalar pra humano |
| `internal_error` | Exception não esperada | Sim com backoff |

## Annotations
- `readOnlyHint: true` para os 8 read tools
- `readOnlyHint: false, destructiveHint: false` para `atlas_memory_record` (write mas não destruidor)
```

- [ ] **Step 0.1.2: Commit**

```bash
git add docs/engineering-knowledge-base/mcp-tools-contract.md
git commit -m "docs(mcp): add tools contract v1.1 with 10-tool inventory and error taxonomy"
```

---

## Phase 1 — Tier 1 Tools [Owner: A2 Backend + A3 Test]

Esses 3 tools são o coração do plano. `atlas_memory_record` fecha o loop. `atlas_code_find_relevant` expõe o índice de 8.799 símbolos. `atlas_docs_lookup` torna a KB queryable.

### Task 1.1 — atlas_memory_record (write-back)

**Files:**
- Modify: `app/Services/Ai/AtlasOpenBrainMcpService.php` (registry, callTool, new private method)
- Test: `tests/Feature/Ai/AtlasOpenBrainMcpServiceTest.php` (create)

- [ ] **Step 1.1.1: Criar arquivo de teste com test failing**

```php
<?php

namespace Tests\Feature\Ai;

use App\Models\AtlasMemoryEntry;
use App\Services\Ai\AtlasOpenBrainMcpService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class AtlasOpenBrainMcpServiceTest extends TestCase
{
    use RefreshDatabase;

    public function test_memory_record_creates_entry_with_provider_safe_defaults(): void
    {
        $service = $this->app->make(AtlasOpenBrainMcpService::class);

        $response = $service->handleJsonRpc([
            'jsonrpc' => '2.0',
            'id' => 1,
            'method' => 'tools/call',
            'params' => [
                'name' => 'atlas_memory_record',
                'arguments' => [
                    'memory_type' => 'decision',
                    'scope_type' => 'project',
                    'scope_id' => 'atlas-server',
                    'title' => 'Triggers de updated_at vivem no DB',
                    'body' => 'Toda tabela com updated_at precisa de trg_<table>_updated_at chamando set_updated_at(). Eloquent não cobre bulk update, raw SQL ou outros workers.',
                    'evidence' => ['CLAUDE.md', 'app/Console/Commands/AtlasUpdatedAtAuditCommand.php'],
                ],
            ],
        ]);

        $structured = $response['result']['structuredContent'];

        $this->assertTrue($structured['ok']);
        $this->assertSame('atlas_memory_record', $structured['tool']);
        $this->assertArrayHasKey('memory_entry_id', $structured);

        $entry = AtlasMemoryEntry::find($structured['memory_entry_id']);
        $this->assertNotNull($entry);
        $this->assertSame('decision', $entry->memory_type);
        $this->assertSame('project', $entry->scope_type);
        $this->assertSame('atlas-server', $entry->scope_id);
        $this->assertSame('active', $entry->status);
        $this->assertSame('normal', $entry->privacy_class);
        $this->assertTrue((bool) $entry->external_ai_allowed);
    }

    public function test_memory_record_rejects_unknown_memory_type(): void
    {
        $service = $this->app->make(AtlasOpenBrainMcpService::class);

        $response = $service->handleJsonRpc([
            'jsonrpc' => '2.0',
            'id' => 2,
            'method' => 'tools/call',
            'params' => [
                'name' => 'atlas_memory_record',
                'arguments' => [
                    'memory_type' => 'fofoca',
                    'scope_type' => 'global',
                    'title' => 'X',
                    'body' => 'Y',
                ],
            ],
        ]);

        $structured = $response['result']['structuredContent'];

        $this->assertFalse($structured['ok']);
        $this->assertSame('invalid_memory_type', $structured['error']);
    }
}
```

- [ ] **Step 1.1.2: Rodar teste e ver falhar**

```bash
cd /Users/vitorepf/Develop/atlas/atlas-server && /opt/homebrew/bin/php artisan test --filter AtlasOpenBrainMcpServiceTest
```

Esperado: 2 testes falham com `Unknown Atlas MCP tool [atlas_memory_record]`.

- [ ] **Step 1.1.3: Adicionar entry no array `tools()` (linha ~120)**

Edit em `app/Services/Ai/AtlasOpenBrainMcpService.php`, adicionar dentro do array retornado por `tools()`, depois do entry de `atlas_memory_maintenance_status`:

```php
[
    'name' => 'atlas_memory_record',
    'title' => 'Atlas Memory Record',
    'description' => 'Persiste uma decisão, learning ou contexto técnico no registry Atlas. Provider-safe por default. Fecha o loop entre engine e Atlas memory.',
    'inputSchema' => [
        'type' => 'object',
        'properties' => [
            'memory_type' => ['type' => 'string', 'description' => 'Tipo: decision, technical_context, harness_learning, preference, feedback, issue, resolution, benchmark_observation.'],
            'scope_type' => ['type' => 'string', 'description' => 'Scope: global, project, task, engineering_run, workspace, user, session.'],
            'scope_id' => ['type' => 'string', 'description' => 'ID do scope (ex: project slug, task UUID). Omit para scope global.'],
            'title' => ['type' => 'string', 'description' => 'Título curto da entry (até 200 chars).'],
            'body' => ['type' => 'string', 'description' => 'Corpo completo da decisão/learning.'],
            'summary' => ['type' => 'string', 'description' => 'Resumo opcional (até 500 chars).'],
            'tags' => ['type' => 'array', 'description' => 'Tags livres para classificação.'],
            'evidence' => ['type' => 'array', 'description' => 'Referências (file paths, URLs, commit SHAs).'],
            'context' => ['type' => 'object', 'description' => 'Contexto Atlas: workspace, project_id, task_id, run_id.'],
        ],
        'required' => ['memory_type', 'scope_type', 'title', 'body'],
    ],
    'annotations' => [
        'readOnlyHint' => false,
        'destructiveHint' => false,
        'openWorldHint' => false,
    ],
],
```

- [ ] **Step 1.1.4: Adicionar case em `callTool()` match (linha ~167)**

```php
'atlas_memory_record' => $this->toolResponse($id, $this->memoryRecord($arguments)),
```

- [ ] **Step 1.1.5: Implementar método `memoryRecord()` no final da seção privada (depois de `maintenanceStatus`)**

```php
/**
 * @param  array<string,mixed>  $arguments
 * @return array<string,mixed>
 */
private function memoryRecord(array $arguments): array
{
    $memoryType = $this->string($arguments['memory_type'] ?? null);
    $scopeType = $this->string($arguments['scope_type'] ?? null);
    $title = $this->string($arguments['title'] ?? null);
    $body = $this->string($arguments['body'] ?? null);

    if ($memoryType === null || ! in_array($memoryType, AtlasMemoryEntry::TYPES, true)) {
        return ['ok' => false, 'tool' => 'atlas_memory_record', 'error' => 'invalid_memory_type'];
    }
    if ($scopeType === null || ! in_array($scopeType, AtlasMemoryEntry::SCOPES, true)) {
        return ['ok' => false, 'tool' => 'atlas_memory_record', 'error' => 'invalid_scope_type'];
    }
    if ($title === null || $body === null) {
        return ['ok' => false, 'tool' => 'atlas_memory_record', 'error' => 'title_and_body_required'];
    }

    $context = $this->object($arguments['context'] ?? []);
    $tags = is_array($arguments['tags'] ?? null) ? $arguments['tags'] : [];
    $evidence = is_array($arguments['evidence'] ?? null) ? $arguments['evidence'] : [];

    $entry = AtlasMemoryEntry::create([
        'memory_type' => $memoryType,
        'scope_type' => $scopeType,
        'scope_id' => $this->string($arguments['scope_id'] ?? null),
        'project_id' => $this->string($context['project_id'] ?? null),
        'task_id' => $this->string($context['task_id'] ?? null),
        'engineering_run_id' => $this->string($context['run_id'] ?? null),
        'session_id' => $this->string($context['session_id'] ?? null),
        'user_id' => $this->string($context['user_id'] ?? null),
        'title' => $title,
        'body' => $body,
        'summary' => $this->string($arguments['summary'] ?? null),
        'importance' => 5,
        'priority' => 5,
        'confidence' => 0.8,
        'privacy_class' => 'normal',
        'external_ai_allowed' => true,
        'redaction_status' => 'clean',
        'source_type' => 'mcp_tool',
        'source_label' => 'atlas_memory_record',
        'status' => 'active',
        'tags' => $tags,
        'metadata' => ['evidence' => $evidence, 'context' => $context],
        'recorded_at' => now(),
    ]);

    return [
        'ok' => true,
        'tool' => 'atlas_memory_record',
        'memory_entry_id' => (string) $entry->id,
        'memory_type' => $entry->memory_type,
        'scope_type' => $entry->scope_type,
        'recorded_at' => $entry->recorded_at?->toJSON(),
    ];
}
```

- [ ] **Step 1.1.6: Rodar teste e ver passar**

```bash
cd /Users/vitorepf/Develop/atlas/atlas-server && /opt/homebrew/bin/php artisan test --filter AtlasOpenBrainMcpServiceTest
```

Esperado: 2 testes pass.

- [ ] **Step 1.1.7: Commit**

```bash
git add app/Services/Ai/AtlasOpenBrainMcpService.php tests/Feature/Ai/AtlasOpenBrainMcpServiceTest.php
git commit -m "feat(mcp): add atlas_memory_record tool to close inbound→outbound loop"
```

---

### Task 1.2 — atlas_code_find_relevant

Usa `EngineeringCodeIntelligenceService::symbols(filters, limit)` que já faz busca por filtros de metadata (layer, type, language).

- [ ] **Step 1.2.1: Adicionar 2 testes em `AtlasOpenBrainMcpServiceTest`**

```php
public function test_code_find_relevant_returns_symbols_matching_query(): void
{
    \App\Models\AtlasEngineeringCodeModule::factory()->create(['slug' => 'memory-service', 'language' => 'php']);
    $module = \App\Models\AtlasEngineeringCodeModule::first();
    \App\Models\AtlasEngineeringCodeSymbol::factory()->create([
        'module_id' => $module->id,
        'name' => 'AtlasHybridMemoryRetrievalService',
        'type' => 'class',
        'language' => 'php',
    ]);

    $service = $this->app->make(AtlasOpenBrainMcpService::class);
    $response = $service->handleJsonRpc([
        'jsonrpc' => '2.0', 'id' => 3, 'method' => 'tools/call',
        'params' => [
            'name' => 'atlas_code_find_relevant',
            'arguments' => ['query' => 'memory retrieval', 'limit' => 5],
        ],
    ]);

    $structured = $response['result']['structuredContent'];
    $this->assertTrue($structured['ok']);
    $this->assertNotEmpty($structured['symbols']);
}

public function test_code_find_relevant_requires_query(): void
{
    $service = $this->app->make(AtlasOpenBrainMcpService::class);
    $response = $service->handleJsonRpc([
        'jsonrpc' => '2.0', 'id' => 4, 'method' => 'tools/call',
        'params' => ['name' => 'atlas_code_find_relevant', 'arguments' => []],
    ]);

    $this->assertFalse($response['result']['structuredContent']['ok']);
    $this->assertSame('query_required', $response['result']['structuredContent']['error']);
}
```

- [ ] **Step 1.2.2: Rodar e ver falhar**

```bash
/opt/homebrew/bin/php artisan test --filter test_code_find_relevant
```

- [ ] **Step 1.2.3: Adicionar entry em `tools()`**

```php
[
    'name' => 'atlas_code_find_relevant',
    'title' => 'Atlas Code Find Relevant',
    'description' => 'Busca símbolos no índice de código (classes, métodos, funções, rotas, migrations, tests) por nome, layer, type ou language. Não faz semântica vetorial — usa metadados estruturados do índice.',
    'inputSchema' => [
        'type' => 'object',
        'properties' => [
            'query' => ['type' => 'string', 'description' => 'Termo de busca (matches em name e module slug).'],
            'symbol_type' => ['type' => 'string', 'description' => 'Filtra por tipo: class, method, function, route, migration, test, command.'],
            'language' => ['type' => 'string', 'description' => 'Filtra por linguagem: php, ts, tsx, js, jsx, md.'],
            'layer' => ['type' => 'string', 'description' => 'Filtra por layer arquitetural (se módulo tiver layer atribuído).'],
            'workspace' => ['type' => 'string', 'description' => 'Workspace local.'],
            'limit' => ['type' => 'integer', 'description' => 'Max símbolos retornados (default 20, max 100).'],
        ],
        'required' => ['query'],
    ],
    'annotations' => ['readOnlyHint' => true, 'destructiveHint' => false, 'openWorldHint' => false],
],
```

- [ ] **Step 1.2.4: Adicionar routing em `callTool()`**

```php
'atlas_code_find_relevant' => $this->toolResponse($id, $this->codeFindRelevant($arguments)),
```

- [ ] **Step 1.2.5: Implementar método**

```php
/**
 * @param  array<string,mixed>  $arguments
 * @return array<string,mixed>
 */
private function codeFindRelevant(array $arguments): array
{
    $query = $this->string($arguments['query'] ?? null);
    if ($query === null) {
        return ['ok' => false, 'tool' => 'atlas_code_find_relevant', 'error' => 'query_required'];
    }

    $limit = min(100, max(1, (int) ($arguments['limit'] ?? 20)));
    $filters = array_filter([
        'name_like' => $query,
        'type' => $this->string($arguments['symbol_type'] ?? null),
        'language' => $this->string($arguments['language'] ?? null),
        'layer' => $this->string($arguments['layer'] ?? null),
    ]);

    $result = $this->code->symbols($filters, $limit);

    return [
        'ok' => true,
        'tool' => 'atlas_code_find_relevant',
        'workspace' => $this->workspace($arguments['workspace'] ?? null),
        'query' => $query,
        'filters' => $filters,
        'symbols' => $result['symbols'] ?? $result,
        'count' => is_array($result['symbols'] ?? null) ? count($result['symbols']) : 0,
        'generated_at' => now()->toJSON(),
    ];
}
```

> ⚠️ **A1 Architect note for A2:** O método `EngineeringCodeIntelligenceService::symbols()` aceita `name_like` como filtro? Verificar lendo o código real (linha 179 do service). Se aceitar `name` exato apenas, ajustar o filter aqui ou estender o service. Se estender, abrir task separada antes de implementar.

- [ ] **Step 1.2.6: Rodar testes**

```bash
/opt/homebrew/bin/php artisan test --filter test_code_find_relevant
```

- [ ] **Step 1.2.7: Commit**

```bash
git add app/Services/Ai/AtlasOpenBrainMcpService.php tests/Feature/Ai/AtlasOpenBrainMcpServiceTest.php
git commit -m "feat(mcp): add atlas_code_find_relevant tool exposing 8.7k symbol index"
```

---

### Task 1.3 — atlas_docs_lookup

Usa `EngineeringKnowledgeBaseService::catalog(filters, limit)`.

- [ ] **Step 1.3.1: Adicionar 2 testes**

```php
public function test_docs_lookup_returns_matching_kb_items(): void
{
    \App\Models\AtlasEngineeringKnowledgeItem::factory()->create([
        'slug' => 'memory-core-failure-modes',
        'title' => 'Memory Core Failure Modes',
        'category' => 'engineering',
        'status' => 'active',
    ]);

    $service = $this->app->make(AtlasOpenBrainMcpService::class);
    $response = $service->handleJsonRpc([
        'jsonrpc' => '2.0', 'id' => 5, 'method' => 'tools/call',
        'params' => ['name' => 'atlas_docs_lookup', 'arguments' => ['query' => 'failure']],
    ]);

    $structured = $response['result']['structuredContent'];
    $this->assertTrue($structured['ok']);
    $this->assertNotEmpty($structured['docs']);
}

public function test_docs_lookup_requires_query(): void
{
    $service = $this->app->make(AtlasOpenBrainMcpService::class);
    $response = $service->handleJsonRpc([
        'jsonrpc' => '2.0', 'id' => 6, 'method' => 'tools/call',
        'params' => ['name' => 'atlas_docs_lookup', 'arguments' => []],
    ]);

    $this->assertFalse($response['result']['structuredContent']['ok']);
    $this->assertSame('query_required', $response['result']['structuredContent']['error']);
}
```

- [ ] **Step 1.3.2: Rodar e ver falhar**

- [ ] **Step 1.3.3: Adicionar entry em `tools()`**

```php
[
    'name' => 'atlas_docs_lookup',
    'title' => 'Atlas Docs Lookup',
    'description' => 'Busca em itens da knowledge base (docs/engineering-knowledge-base/*.md indexados). Filtra por categoria, status, slug. Retorna metadata + path do arquivo.',
    'inputSchema' => [
        'type' => 'object',
        'properties' => [
            'query' => ['type' => 'string', 'description' => 'Termo de busca em title e slug.'],
            'category' => ['type' => 'string', 'description' => 'Filtra por categoria: engineering, architecture, runbook, decision, etc.'],
            'status' => ['type' => 'string', 'description' => 'Filtra por status: active, archived, draft.'],
            'limit' => ['type' => 'integer', 'description' => 'Max docs retornados (default 10, max 50).'],
        ],
        'required' => ['query'],
    ],
    'annotations' => ['readOnlyHint' => true, 'destructiveHint' => false, 'openWorldHint' => false],
],
```

- [ ] **Step 1.3.4: Routing + method**

```php
// Em callTool:
'atlas_docs_lookup' => $this->toolResponse($id, $this->docsLookup($arguments)),

// Implementação:
private function docsLookup(array $arguments): array
{
    $query = $this->string($arguments['query'] ?? null);
    if ($query === null) {
        return ['ok' => false, 'tool' => 'atlas_docs_lookup', 'error' => 'query_required'];
    }

    $limit = min(50, max(1, (int) ($arguments['limit'] ?? 10)));
    $filters = array_filter([
        'query' => $query,
        'category' => $this->string($arguments['category'] ?? null),
        'status' => $this->string($arguments['status'] ?? null) ?: 'active',
    ]);

    $result = $this->knowledge->catalog($filters, $limit);

    return [
        'ok' => true,
        'tool' => 'atlas_docs_lookup',
        'query' => $query,
        'filters' => $filters,
        'docs' => $result['items'] ?? $result,
        'count' => is_array($result['items'] ?? null) ? count($result['items']) : 0,
        'generated_at' => now()->toJSON(),
    ];
}
```

- [ ] **Step 1.3.5: Rodar tests**

- [ ] **Step 1.3.6: Commit**

```bash
git add app/Services/Ai/AtlasOpenBrainMcpService.php tests/Feature/Ai/AtlasOpenBrainMcpServiceTest.php
git commit -m "feat(mcp): add atlas_docs_lookup tool exposing knowledge base"
```

---

## Phase 2 — Tier 2 Tools [Owner: A2 Backend + A3 Test]

### Task 2.1 — atlas_capabilities

Self-introspection: retorna o array `tools()` completo + protocol version + server info. Permite engines fazer capability negotiation sem precisar reimplementar parsing do `tools/list`.

- [ ] **Step 2.1.1: Adicionar teste**

```php
public function test_capabilities_returns_full_tool_inventory(): void
{
    $service = $this->app->make(AtlasOpenBrainMcpService::class);
    $response = $service->handleJsonRpc([
        'jsonrpc' => '2.0', 'id' => 7, 'method' => 'tools/call',
        'params' => ['name' => 'atlas_capabilities', 'arguments' => []],
    ]);

    $structured = $response['result']['structuredContent'];
    $this->assertTrue($structured['ok']);
    $this->assertSame(AtlasOpenBrainMcpService::PROTOCOL_VERSION, $structured['protocol_version']);
    $this->assertCount(10, $structured['tools']); // 3 existing + 7 new
    $this->assertContains('atlas_memory_record', array_column($structured['tools'], 'name'));
}
```

- [ ] **Step 2.1.2: Rodar e ver falhar**

- [ ] **Step 2.1.3: Registrar tool**

```php
[
    'name' => 'atlas_capabilities',
    'title' => 'Atlas Capabilities',
    'description' => 'Retorna inventário completo de tools MCP do Atlas, com schemas, annotations, protocol version e server info. Use para capability negotiation.',
    'inputSchema' => ['type' => 'object', 'properties' => [], 'required' => []],
    'annotations' => ['readOnlyHint' => true, 'destructiveHint' => false, 'openWorldHint' => false],
],
```

- [ ] **Step 2.1.4: Routing + method**

```php
'atlas_capabilities' => $this->toolResponse($id, $this->capabilities()),

private function capabilities(): array
{
    return [
        'ok' => true,
        'tool' => 'atlas_capabilities',
        'protocol_version' => self::PROTOCOL_VERSION,
        'server' => [
            'name' => 'atlas-open-brain',
            'version' => '1.1.0', // bump from 1.0.0 to signal new tools
        ],
        'tools' => $this->tools(),
        'transport' => 'stdio',
        'remote_capable' => false,
        'generated_at' => now()->toJSON(),
    ];
}
```

> ⚠️ **A1 Architect note:** Bumpar `serverInfo.version` de '1.0.0' para '1.1.0' em `initializeResult()` (linha 140) também — essa mudança sinaliza expansão para clients que cacheiam capabilities.

- [ ] **Step 2.1.5: Rodar + commit**

```bash
git add app/Services/Ai/AtlasOpenBrainMcpService.php tests/Feature/Ai/AtlasOpenBrainMcpServiceTest.php
git commit -m "feat(mcp): add atlas_capabilities for capability negotiation, bump to 1.1.0"
```

---

### Task 2.2 — atlas_workspace_info

Retorna metadata estruturada do workspace: é um projeto Atlas conhecido? quais scopes têm memória? quando foi indexado? Permite engine decidir RAPIDAMENTE se vale consultar Atlas profundamente ou se deve cair pra grep.

- [ ] **Step 2.2.1: Adicionar teste**

```php
public function test_workspace_info_returns_metadata_for_atlas_tracked_workspace(): void
{
    AtlasMemoryEntry::factory()->create([
        'scope_type' => 'project',
        'scope_id' => 'atlas-server',
        'status' => 'active',
    ]);

    $service = $this->app->make(AtlasOpenBrainMcpService::class);
    $response = $service->handleJsonRpc([
        'jsonrpc' => '2.0', 'id' => 8, 'method' => 'tools/call',
        'params' => [
            'name' => 'atlas_workspace_info',
            'arguments' => ['workspace' => '/Users/vitorepf/Develop/atlas/atlas-server'],
        ],
    ]);

    $structured = $response['result']['structuredContent'];
    $this->assertTrue($structured['ok']);
    $this->assertTrue($structured['atlas_tracked']);
    $this->assertGreaterThan(0, $structured['memory_entry_count']);
}
```

- [ ] **Step 2.2.2: Rodar e ver falhar**

- [ ] **Step 2.2.3: Registrar tool**

```php
[
    'name' => 'atlas_workspace_info',
    'title' => 'Atlas Workspace Info',
    'description' => 'Retorna metadata do workspace: Atlas o reconhece? quantas entries de memória? quando o code intelligence foi indexado? Usar para decidir profundidade de consulta antes de outros tools.',
    'inputSchema' => [
        'type' => 'object',
        'properties' => [
            'workspace' => ['type' => 'string', 'description' => 'Caminho absoluto do workspace.'],
        ],
        'required' => ['workspace'],
    ],
    'annotations' => ['readOnlyHint' => true, 'destructiveHint' => false, 'openWorldHint' => false],
],
```

- [ ] **Step 2.2.4: Routing + method**

```php
'atlas_workspace_info' => $this->toolResponse($id, $this->workspaceInfo($arguments)),

private function workspaceInfo(array $arguments): array
{
    $workspace = $this->workspace($arguments['workspace'] ?? null);

    // Derivar slug do projeto pelo basename
    $slug = $workspace ? basename($workspace) : null;

    $memoryCount = $slug && Schema::hasTable('atlas_memory_entries')
        ? AtlasMemoryEntry::query()
            ->where('status', 'active')
            ->where(function ($q) use ($slug) {
                $q->where('scope_type', 'project')->where('scope_id', $slug)
                  ->orWhere('scope_type', 'global');
            })
            ->count()
        : 0;

    $codeSummary = $this->code->summary();
    $knowledgeSummary = $this->knowledge->summary();

    return [
        'ok' => true,
        'tool' => 'atlas_workspace_info',
        'workspace' => $workspace,
        'inferred_slug' => $slug,
        'atlas_tracked' => $memoryCount > 0,
        'memory_entry_count' => $memoryCount,
        'code_intelligence' => [
            'indexed' => ($codeSummary['module_count'] ?? 0) > 0,
            'last_index_at' => $codeSummary['last_index_at'] ?? null,
            'module_count' => $codeSummary['module_count'] ?? 0,
            'symbol_count' => $codeSummary['symbol_count'] ?? 0,
        ],
        'knowledge_base' => [
            'indexed' => ($knowledgeSummary['active_count'] ?? 0) > 0,
            'last_sync_at' => $knowledgeSummary['last_sync_at'] ?? null,
            'doc_count' => $knowledgeSummary['active_count'] ?? 0,
        ],
        'recommended_action' => $memoryCount > 0
            ? 'consult_atlas_first'
            : 'fallback_to_local_exploration',
        'generated_at' => now()->toJSON(),
    ];
}
```

- [ ] **Step 2.2.5: Rodar + commit**

---

## Phase 3 — Tier 3 Tools [Owner: A2 Backend + A3 Test]

### Task 3.1 — atlas_recent_changes

Lê git log recente do workspace via `Symfony\Component\Process\Process` (já usado no Atlas) + cross-reference com last index timestamp.

- [ ] **Step 3.1.1: Adicionar teste com mock de processo git**

```php
public function test_recent_changes_returns_files_and_index_freshness(): void
{
    // Workspace deve ser repo git real para teste end-to-end
    $service = $this->app->make(AtlasOpenBrainMcpService::class);
    $response = $service->handleJsonRpc([
        'jsonrpc' => '2.0', 'id' => 9, 'method' => 'tools/call',
        'params' => [
            'name' => 'atlas_recent_changes',
            'arguments' => [
                'workspace' => base_path(),
                'since' => '7 days ago',
            ],
        ],
    ]);

    $structured = $response['result']['structuredContent'];
    $this->assertTrue($structured['ok']);
    $this->assertArrayHasKey('changed_files', $structured);
    $this->assertArrayHasKey('index_fresh', $structured);
}
```

- [ ] **Step 3.1.2: Rodar e ver falhar**

- [ ] **Step 3.1.3: Registrar tool**

```php
[
    'name' => 'atlas_recent_changes',
    'title' => 'Atlas Recent Changes',
    'description' => 'Lista arquivos mudados no workspace recentemente (via git log) e cross-referencia com timestamp do code intelligence index. Retorna `index_fresh: false` se filesystem está à frente do índice.',
    'inputSchema' => [
        'type' => 'object',
        'properties' => [
            'workspace' => ['type' => 'string', 'description' => 'Workspace local (deve ser repo git).'],
            'since' => ['type' => 'string', 'description' => 'Período (git --since): "7 days ago", "2 weeks ago", "yesterday". Default: "7 days ago".'],
            'limit' => ['type' => 'integer', 'description' => 'Max arquivos retornados (default 50, max 200).'],
        ],
        'required' => ['workspace'],
    ],
    'annotations' => ['readOnlyHint' => true, 'destructiveHint' => false, 'openWorldHint' => false],
],
```

- [ ] **Step 3.1.4: Routing + method**

```php
'atlas_recent_changes' => $this->toolResponse($id, $this->recentChanges($arguments)),

private function recentChanges(array $arguments): array
{
    $workspace = $this->workspace($arguments['workspace'] ?? null);
    if ($workspace === null || ! is_dir($workspace . '/.git')) {
        return ['ok' => false, 'tool' => 'atlas_recent_changes', 'error' => 'workspace_not_git_repo'];
    }

    $since = $this->string($arguments['since'] ?? null) ?: '7 days ago';
    $limit = min(200, max(1, (int) ($arguments['limit'] ?? 50)));

    $process = new \Symfony\Component\Process\Process(
        ['git', 'log', '--name-only', '--pretty=format:', '--since=' . $since],
        $workspace
    );
    $process->setTimeout(10);
    $process->run();

    if (! $process->isSuccessful()) {
        return ['ok' => false, 'tool' => 'atlas_recent_changes', 'error' => 'git_command_failed'];
    }

    $files = array_values(array_unique(array_filter(explode("\n", $process->getOutput()))));
    $files = array_slice($files, 0, $limit);

    $codeSummary = $this->code->summary();
    $lastIndexAt = $codeSummary['last_index_at'] ?? null;
    $indexFresh = $lastIndexAt
        ? (\Illuminate\Support\Carbon::parse($lastIndexAt))->greaterThan(now()->sub(\Carbon\CarbonInterval::fromString('1 day')))
        : false;

    return [
        'ok' => true,
        'tool' => 'atlas_recent_changes',
        'workspace' => $workspace,
        'since' => $since,
        'changed_files' => $files,
        'count' => count($files),
        'index_fresh' => $indexFresh,
        'last_index_at' => $lastIndexAt,
        'recommended_action' => $indexFresh ? null : 'reindex_recommended',
        'generated_at' => now()->toJSON(),
    ];
}
```

- [ ] **Step 3.1.5: Rodar + commit**

---

### Task 3.2 — atlas_decision_query

Wrapper sobre `recall` filtrando só `type=decision`. Por que separado do recall genérico? Porque engine pode querer "decisions canônicas" sem se preocupar em parsear filtros — semântica mais clara.

- [ ] **Step 3.2.1: Adicionar teste**

```php
public function test_decision_query_filters_to_decisions_only(): void
{
    AtlasMemoryEntry::factory()->create(['memory_type' => 'decision', 'title' => 'Use Postgres', 'status' => 'active']);
    AtlasMemoryEntry::factory()->create(['memory_type' => 'preference', 'title' => 'Tabs over spaces', 'status' => 'active']);

    $service = $this->app->make(AtlasOpenBrainMcpService::class);
    $response = $service->handleJsonRpc([
        'jsonrpc' => '2.0', 'id' => 10, 'method' => 'tools/call',
        'params' => [
            'name' => 'atlas_decision_query',
            'arguments' => ['query' => 'database'],
        ],
    ]);

    $structured = $response['result']['structuredContent'];
    $this->assertTrue($structured['ok']);
    foreach ($structured['decisions'] as $d) {
        $this->assertSame('decision', $d['memory_type'] ?? null);
    }
}
```

- [ ] **Step 3.2.2: Rodar e ver falhar**

- [ ] **Step 3.2.3: Registrar tool**

```php
[
    'name' => 'atlas_decision_query',
    'title' => 'Atlas Decision Query',
    'description' => 'Recall filtrado para apenas decisões canônicas (memory_type=decision). Use quando precisar de "o que foi decidido sobre X" sem misturar com learnings ou preferences.',
    'inputSchema' => [
        'type' => 'object',
        'properties' => [
            'query' => ['type' => 'string', 'description' => 'Pergunta ou tópico de decisão.'],
            'scope' => ['type' => 'string', 'description' => 'Filtra por scope: global, project, etc.'],
            'workspace' => ['type' => 'string', 'description' => 'Workspace local.'],
            'limit' => ['type' => 'integer', 'description' => 'Max decisões retornadas (default 5).'],
        ],
        'required' => ['query'],
    ],
    'annotations' => ['readOnlyHint' => true, 'destructiveHint' => false, 'openWorldHint' => false],
],
```

- [ ] **Step 3.2.4: Routing + method**

```php
'atlas_decision_query' => $this->toolResponse($id, $this->decisionQuery($arguments)),

private function decisionQuery(array $arguments): array
{
    $query = $this->string($arguments['query'] ?? null);
    if ($query === null) {
        return ['ok' => false, 'tool' => 'atlas_decision_query', 'error' => 'query_required'];
    }

    $context = [];
    $workspace = $this->workspace($arguments['workspace'] ?? null);
    if ($workspace !== null) {
        $context['workspace'] = $workspace;
    }

    $filters = ['memory_type' => 'decision'];
    $scope = $this->string($arguments['scope'] ?? null);
    if ($scope !== null) {
        $filters['scope_type'] = $scope;
    }

    $options = ['limit' => min(20, max(1, (int) ($arguments['limit'] ?? 5)))];

    $recall = $this->recall->recall($query, $context, $filters, $options);

    return [
        'ok' => true,
        'tool' => 'atlas_decision_query',
        'query' => $query,
        'decisions' => $recall['recall'] ?? [],
        'count' => count($recall['recall'] ?? []),
        'summary' => $recall['summary'] ?? [],
        'generated_at' => now()->toJSON(),
    ];
}
```

- [ ] **Step 3.2.5: Rodar + commit**

---

## Phase 4 — Memory Curation [Owner: A4 Memory Curator]

A pior gap identificada na auditoria: as 5 entries que existem hoje no Atlas memory são todas **meta sobre o próprio Atlas** — nenhuma é regra real do projeto. As regras canônicas vivem em `/Users/vitorepf/Develop/atlas/CLAUDE.md` (master) mas não estão consultáveis via MCP. Phase 4 corrige.

### Task 4.1 — Identificar comando de seed existente OU criar

- [ ] **Step 4.1.1: Verificar se existe comando de seed**

```bash
cd /Users/vitorepf/Develop/atlas/atlas-server && /opt/homebrew/bin/php artisan list | grep memory
```

Se aparecer `atlas:memory:seed-core` (mencionado no `nextActions()` do MCP service, linha 351), pular para Task 4.2. Se não, criar comando seguindo o padrão Artisan.

- [ ] **Step 4.1.2 (condicional): Criar `app/Console/Commands/AtlasMemorySeedCoreCommand.php` se não existir**

```php
<?php

namespace App\Console\Commands;

use App\Models\AtlasMemoryEntry;
use Illuminate\Console\Command;

class AtlasMemorySeedCoreCommand extends Command
{
    protected $signature = 'atlas:memory:seed-core {--force : Re-seed even if entries exist}';
    protected $description = 'Seed Atlas memory with canonical decisions from master CLAUDE.md';

    public function handle(): int
    {
        $rules = $this->coreRules();
        $created = 0;
        $skipped = 0;

        foreach ($rules as $rule) {
            $exists = AtlasMemoryEntry::query()
                ->where('memory_type', $rule['memory_type'])
                ->where('title', $rule['title'])
                ->where('status', 'active')
                ->exists();

            if ($exists && ! $this->option('force')) {
                $skipped++;
                continue;
            }

            AtlasMemoryEntry::create($rule);
            $created++;
        }

        $this->info("Seeded {$created} entries, skipped {$skipped} existing.");
        return self::SUCCESS;
    }

    private function coreRules(): array
    {
        return [
            [
                'memory_type' => 'decision',
                'scope_type' => 'global',
                'title' => 'Migrations: nunca INSERT INTO migrations manualmente',
                'body' => "Nunca INSERT INTO migrations manualmente. Nunca editar a tabela migrations pra pular uma migration que conflita com schema existente.\n\nSe uma migration conflita com schema vivo, a migration vira idempotente — não a tabela migrations:\n- Schema::create → guardar com if (! Schema::hasTable(...))\n- Schema::table adicionando coluna → guardar com if (! Schema::hasColumn(...))\n- CREATE INDEX → usar IF NOT EXISTS\n- ADD CONSTRAINT → checar pg_constraint antes\n- CREATE TRIGGER → DROP TRIGGER IF EXISTS antes\n\nA tabela migrations é log de execução do Laravel, não ferramenta de configuração. Carimbar manualmente cria drift silencioso: o Laravel acredita que rodou, o DDL não rodou, e a próxima migration que dependa daquele schema quebra em produção sem aviso.",
                'summary' => 'Migrations devem ser idempotentes; nunca carimbar manualmente o registro.',
                'importance' => 9,
                'priority' => 9,
                'confidence' => 1.0,
                'privacy_class' => 'normal',
                'external_ai_allowed' => true,
                'redaction_status' => 'clean',
                'source_type' => 'manual_curation',
                'source_label' => 'master_claude_md',
                'status' => 'active',
                'tags' => ['migrations', 'database', 'schema', 'laravel', 'canonical'],
                'metadata' => [
                    'evidence' => ['/Users/vitorepf/Develop/atlas/CLAUDE.md'],
                    'incident' => '2026-05-01: 49 migrations carimbadas, 23 tabelas faltando, 14 tabelas legado órfãs',
                ],
                'recorded_at' => now(),
            ],
            [
                'memory_type' => 'decision',
                'scope_type' => 'global',
                'title' => 'Database em dev: fresh > reparo',
                'body' => "Quando o DB local diverge das migrations:\n\n1. Não escrever migration de reparo (gambiarra que vira cicatriz permanente).\n2. docker compose stop backend queue scheduler (senão entram em loop tentando migrar).\n3. docker exec atlas-db psql -U atlas -d postgres -c \"DROP DATABASE atlas WITH (FORCE);\" seguido de CREATE DATABASE atlas OWNER atlas.\n4. docker compose run --rm --no-deps backend php artisan migrate --force (one-shot, output limpo).\n5. Religar backend queue scheduler.\n\nDados de dev são descartáveis por default. Se algum momento tiver dado vivo que importa, pg_dump --data-only antes do nuke e restore depois. Não é a regra, é exceção.",
                'summary' => 'DB local que diverge = nuke + recreate, não migration de reparo.',
                'importance' => 9,
                'priority' => 8,
                'confidence' => 1.0,
                'privacy_class' => 'normal',
                'external_ai_allowed' => true,
                'redaction_status' => 'clean',
                'source_type' => 'manual_curation',
                'source_label' => 'master_claude_md',
                'status' => 'active',
                'tags' => ['database', 'dev_workflow', 'docker', 'canonical'],
                'metadata' => [
                    'evidence' => ['/Users/vitorepf/Develop/atlas/CLAUDE.md'],
                    'related_decision' => 'migrations: nunca INSERT INTO migrations manualmente',
                ],
                'recorded_at' => now(),
            ],
            [
                'memory_type' => 'technical_context',
                'scope_type' => 'global',
                'title' => 'Invariantes de schema vivem no DB, não no ORM',
                'body' => "Toda tabela com coluna updated_at tem trigger trg_<table>_updated_at chamando set_updated_at(). Sem exceção.\n\nCREATE TRIGGER trg_<table>_updated_at\nBEFORE UPDATE ON <table>\nFOR EACH ROW EXECUTE FUNCTION set_updated_at();\n\nAdicionar DB::statement(<<<'SQL' ... SQL); no up() da migration logo depois do Schema::create.\n\nPor que: Eloquent atualiza updated_at em \$model->save(), mas não atualiza em Model::where(...)->update([...]) (bulk update bypassa events), nem em raw SQL (DB::table, DB::statement), nem em workers de outra linguagem, nem em manutenção via psql. Trigger no DB protege todos esses caminhos.\n\nA mesma lógica vale pra outros invariantes mecânicos: CHECK, NOT NULL, UNIQUE, FK, defaults, e triggers de invariante vivem no DB. Lógica de negócio (cobrança, autorização, fluxos) vive no código.\n\nA linha: se a regra existe pra proteger integridade dos dados, vai pro DB. Se existe pra regular comportamento da aplicação, vai pro código.",
                'summary' => 'Invariantes mecânicos (updated_at, CHECK, FK, defaults) vivem no DB; lógica de negócio no código.',
                'importance' => 8,
                'priority' => 8,
                'confidence' => 1.0,
                'privacy_class' => 'normal',
                'external_ai_allowed' => true,
                'redaction_status' => 'clean',
                'source_type' => 'manual_curation',
                'source_label' => 'master_claude_md',
                'status' => 'active',
                'tags' => ['database', 'triggers', 'invariants', 'postgres', 'canonical'],
                'metadata' => [
                    'evidence' => ['/Users/vitorepf/Develop/atlas/CLAUDE.md'],
                    'verification_sql' => "SELECT t.table_name FROM information_schema.tables t JOIN information_schema.columns c ON c.table_schema = t.table_schema AND c.table_name = t.table_name WHERE t.table_schema = 'public' AND t.table_type = 'BASE TABLE' AND c.column_name = 'updated_at' AND NOT EXISTS (SELECT 1 FROM pg_trigger tr JOIN pg_class cl ON cl.oid = tr.tgrelid WHERE cl.relname = t.table_name AND tr.tgname LIKE '%updated_at%' AND NOT tr.tgisinternal);",
                ],
                'recorded_at' => now(),
            ],
        ];
    }
}
```

- [ ] **Step 4.1.3: Rodar comando**

```bash
cd /Users/vitorepf/Develop/atlas/atlas-server && /opt/homebrew/bin/php artisan atlas:memory:seed-core
```

Esperado: "Seeded 3 entries, skipped 0 existing."

- [ ] **Step 4.1.4: Verificar via tool MCP recém-criado**

```bash
echo '{"jsonrpc":"2.0","id":1,"method":"tools/call","params":{"name":"atlas_decision_query","arguments":{"query":"migrations"}}}' | ./bin/atlas open-brain mcp
```

Esperado: deve retornar pelo menos a entry "Migrations: nunca INSERT INTO migrations manualmente".

- [ ] **Step 4.1.5: Commit**

```bash
git add app/Console/Commands/AtlasMemorySeedCoreCommand.php
git commit -m "feat(memory): add seed-core command with 3 canonical decisions from master CLAUDE.md"
```

### Task 4.2 — Re-projetar CLAUDE.md/AGENTS.md depois do seed

- [ ] **Step 4.2.1: Rodar projection**

```bash
cd /Users/vitorepf/Develop/atlas/atlas-server && ./bin/atlas memory projection apply --target=all --yes --json
```

- [ ] **Step 4.2.2: Verificar que `atlas-server/CLAUDE.md` Provider-Safe Memory section agora inclui as 3 entries novas**

```bash
grep -A2 "## Provider-Safe Memory" CLAUDE.md
```

Deve mostrar entries começando com `[decision][global] Migrations:`, `[decision][global] Database em dev:`, `[technical_context][global] Invariantes`.

- [ ] **Step 4.2.3: Idem para AGENTS.md**

```bash
grep -A2 "## Provider-Safe Memory" AGENTS.md
```

- [ ] **Step 4.2.4: Verificar que blocos `<!-- atlas:manual:start -->` foram preservados**

```bash
grep -A1 "atlas:manual:start" CLAUDE.md
grep -A1 "atlas:manual:start" AGENTS.md
```

- [ ] **Step 4.2.5: Commit (se há mudanças nas projections)**

```bash
git add CLAUDE.md AGENTS.md
git commit -m "chore(projection): re-project CLAUDE.md/AGENTS.md after core memory seed"
```

### Task 4.3 — Atualizar regras imperativas globais com 10 tools

- [ ] **Step 4.3.1: Editar `~/.claude/CLAUDE.md` para mencionar 10 tools**

Substituir a tabela "Quando | Tool" para incluir os 7 novos tools (manter formato existente, só expandir):

```markdown
| Quando | Tool |
|---|---|
| Pergunta toca decisão canônica já tomada | `atlas_decision_query` |
| Pergunta genérica sobre regra/learning catalogado | `atlas_memory_recall` |
| Vai começar feature ou refator multi-módulo | `atlas_open_brain_context_pack` |
| Precisa achar código por nome/tipo/layer | `atlas_code_find_relevant` |
| Precisa achar doc da KB | `atlas_docs_lookup` |
| Quer saber se workspace é Atlas-tracked antes de ir fundo | `atlas_workspace_info` |
| Quer saber o que mudou recentemente no workspace | `atlas_recent_changes` |
| Suspeita drift entre projection e código | `atlas_memory_maintenance_status` |
| Precisa reportar decisão tomada de volta pro Atlas | `atlas_memory_record` |
| Quer descobrir todos os tools disponíveis | `atlas_capabilities` |
```

- [ ] **Step 4.3.2: Aplicar mesma mudança em `~/.codex/AGENTS.md`**

- [ ] **Step 4.3.3: Aplicar em `atlas-server/CLAUDE.md` e `atlas-server/AGENTS.md` (manual blocks)**

- [ ] **Step 4.3.4: Commit (apenas arquivos do projeto — globais user-level ficam fora do versionamento)**

```bash
# Edits em ~/.claude/CLAUDE.md e ~/.codex/AGENTS.md NÃO entram aqui (são user-global, fora do repo).
# Versionar separadamente em ~/dotfiles ou similar se quiser histórico.
git add CLAUDE.md AGENTS.md
git commit -m "docs(projection): update manual blocks to reference all 10 MCP tools"
```

---

## Phase 5 — Integration Validation & Benchmarks [Owner: A5 Integration Validator]

### Task 5.1 — End-to-end test via Claude Code

- [ ] **Step 5.1.1: Verificar MCP carrega corretamente**

```bash
cd /Users/vitorepf/Develop/atlas/atlas-server && claude mcp list 2>&1 | grep atlas-open-brain
```

Esperado: `atlas-open-brain: ... - ✓ Connected`

- [ ] **Step 5.1.2: Validar inventário de 10 tools via /mcp ou tools/list**

```bash
echo '{"jsonrpc":"2.0","id":1,"method":"tools/list"}' | ./bin/atlas open-brain mcp | python3 -c "import sys, json; data=json.load(sys.stdin); print(len(data['result']['tools']))"
```

Esperado: `10`

- [ ] **Step 5.1.3: Smoke test de cada tool via JSON-RPC direto**

```bash
for tool in atlas_memory_recall atlas_open_brain_context_pack atlas_memory_maintenance_status atlas_decision_query atlas_code_find_relevant atlas_docs_lookup atlas_workspace_info atlas_recent_changes atlas_capabilities; do
  echo "--- Testing $tool ---"
  args='{"query":"test","workspace":"'$(pwd)'"}'
  echo "{\"jsonrpc\":\"2.0\",\"id\":1,\"method\":\"tools/call\",\"params\":{\"name\":\"$tool\",\"arguments\":$args}}" | ./bin/atlas open-brain mcp 2>&1 | head -3
done
```

Cada tool deve retornar JSON-RPC com `result` (não `error`). Se algum retornar `isError: true`, investigar antes de prosseguir.

### Task 5.2 — Validar regra imperativa fira em projeto não-atlas-server

- [ ] **Step 5.2.1: Abrir Claude Code em /tmp**

Manualmente, criar pasta vazia, abrir Claude Code:

```bash
mkdir -p /tmp/atlas-mcp-test && cd /tmp/atlas-mcp-test && claude
```

- [ ] **Step 5.2.2: Pedir tarefa real**

Prompt: "como o projeto Atlas lida com migrations que conflitam com schema vivo?"

- [ ] **Step 5.2.3: Verificar que `atlas_decision_query` ou `atlas_memory_recall` foi chamado**

Inspecionar resposta visualmente. Deve aparecer tool call na sequência. Resposta deve mencionar "nunca INSERT INTO migrations" e "fresh > reparo" (vindo da Phase 4 seed).

Se NÃO chamou Atlas → regra global em `~/.claude/CLAUDE.md` falhou. Fix: mover regra mais pra topo do arquivo.

### Task 5.3 — Repetir com Codex

- [ ] **Step 5.3.1: Abrir Codex em /tmp**

```bash
cd /tmp/atlas-mcp-test && codex
```

- [ ] **Step 5.3.2: Mesmo prompt, mesma verificação**

### Task 5.4 — Benchmark de latência

Criar `tests/Feature/Ai/AtlasOpenBrainMcpBenchmarkTest.php`:

```php
<?php

namespace Tests\Feature\Ai;

use App\Services\Ai\AtlasOpenBrainMcpService;
use Tests\TestCase;

class AtlasOpenBrainMcpBenchmarkTest extends TestCase
{
    public function test_recall_completes_under_500ms_warm(): void
    {
        $service = $this->app->make(AtlasOpenBrainMcpService::class);

        // Warm up
        $service->handleJsonRpc(['jsonrpc' => '2.0', 'id' => 0, 'method' => 'tools/call',
            'params' => ['name' => 'atlas_memory_recall', 'arguments' => ['query' => 'warmup']]]);

        $start = microtime(true);
        $service->handleJsonRpc(['jsonrpc' => '2.0', 'id' => 1, 'method' => 'tools/call',
            'params' => ['name' => 'atlas_memory_recall', 'arguments' => ['query' => 'migrations']]]);
        $elapsed = microtime(true) - $start;

        $this->assertLessThan(0.5, $elapsed, "Recall took {$elapsed}s, expected < 0.5s warm");
    }

    public function test_capabilities_completes_under_50ms(): void
    {
        $service = $this->app->make(AtlasOpenBrainMcpService::class);
        $service->handleJsonRpc(['jsonrpc' => '2.0', 'id' => 0, 'method' => 'tools/call',
            'params' => ['name' => 'atlas_capabilities', 'arguments' => []]]); // warmup

        $start = microtime(true);
        $service->handleJsonRpc(['jsonrpc' => '2.0', 'id' => 1, 'method' => 'tools/call',
            'params' => ['name' => 'atlas_capabilities', 'arguments' => []]]);
        $elapsed = microtime(true) - $start;

        $this->assertLessThan(0.05, $elapsed, "Capabilities took {$elapsed}s");
    }
}
```

- [ ] **Step 5.4.1: Rodar benchmark**

```bash
cd /Users/vitorepf/Develop/atlas/atlas-server && /opt/homebrew/bin/php artisan test --filter AtlasOpenBrainMcpBenchmarkTest
```

- [ ] **Step 5.4.2: Medir cold start fora dos testes (PHP boot incluso)**

```bash
time (echo '{"jsonrpc":"2.0","id":1,"method":"tools/list"}' | ./bin/atlas open-brain mcp > /dev/null)
```

Documentar em `docs/engineering-knowledge-base/mcp-tools-contract.md` os números reais. Se cold start > 5s, abrir issue separada para Phase 2 (daemon mode).

### Task 5.5 — Reportar status final

- [ ] **Step 5.5.1: Criar relatório em `docs/engineering-knowledge-base/mcp-tools-rollout-report.md`**

```markdown
# MCP Tools Rollout — Validation Report

Date: 2026-05-XX (preenchimento real)

## Inventory delivered
- 10 tools (3 existing + 7 new)
- 1 write tool (`atlas_memory_record`)
- 9 read tools

## Tests
- Feature tests: X passing
- Benchmark tests: X passing
- Cold start: X.Xs
- Warm recall: X ms

## Integration with engines
- Claude Code (CLI): ✓ Connected, regra fira em N% dos prompts testados
- Claude Code (desktop app): ✓ Connected
- Codex CLI: ✓ Connected, regra fira em N% dos prompts testados

## Memory curation
- Core seed: 3 entries (migrations, fresh > reparo, schema invariants)
- Projection re-applied to CLAUDE.md, AGENTS.md
- Provider-safe memory count: X (was 5, now 8)

## Known limitations / follow-ups
- Cold start de X.Xs sugere implementar daemon mode (out of scope desta phase)
- Atlas ainda não auto-injeta regra imperativa em workspaces novos (manual via global CLAUDE.md)
- Tools `atlas_run_report`, `atlas_audit_event` ainda não implementados (Phase 6 quando harness orchestration estiver pronto)
- Ainda sem policy contextual (filtro por tópico/sensibilidade) — apenas binary `external_ai_allowed`
```

- [ ] **Step 5.5.2: Commit final**

```bash
git add docs/engineering-knowledge-base/mcp-tools-rollout-report.md
git commit -m "docs(mcp): rollout report for tools expansion v1.1.0"
```

---

## Self-Review Checklist (executed by A1 Architect after all phases)

### Spec coverage
- [x] 7 novos tools especificados? Sim — record, code_find_relevant, docs_lookup, capabilities, workspace_info, recent_changes, decision_query
- [x] Inclui write-back tool? Sim — `atlas_memory_record`
- [x] Inclui capability negotiation? Sim — `atlas_capabilities`
- [x] Inclui memory curation? Sim — Phase 4
- [x] Inclui validação real com engines? Sim — Phase 5

### Placeholder scan
- [x] Nenhum "TODO" ou "TBD" no plano
- [x] Toda task tem código real (não "implementar similar")
- [x] Comandos têm output esperado quando aplicável

### Type consistency
- [x] `memory_type` constante em todas refs (não vira `type` em alguns lugares)
- [x] `workspace` é sempre absolute path
- [x] Return shape `{ok, tool, ...}` consistente em todos tools novos

### Risk flags
- ⚠️ Task 1.2 assume `EngineeringCodeIntelligenceService::symbols()` aceita filtro `name_like` — verificar antes de implementar
- ⚠️ Phase 4 assume comando `atlas:memory:seed-core` ou existe ou pode ser criado — Step 4.1.1 verifica condicionalmente
- ⚠️ Phase 4 modifica `~/.claude/CLAUDE.md` e `~/.codex/AGENTS.md` que são globais user-level — git diff não vai mostrar essas mudanças no repo do atlas-server, então commit é só dos arquivos do projeto

---

## Out-of-Scope Follow-ups (Phase 6+)

Não fazer agora. Documentar para roadmap.

1. **Daemon mode** — eliminar cold start. Atlas mantém processo PHP vivo, MCP requests vão pra IPC ao invés de spawnar PHP toda chamada.
2. **HTTP+SSE transport** — quando precisar de cenários multi-host ou web client.
3. **Identity & audit per-engine** — saber se foi Claude vs. Codex chamando, qual sessão, qual workspace.
4. **Policy contextual** — filtro além de `external_ai_allowed` binário (por tópico, sensibilidade, scope).
5. **`atlas_run_report` + `atlas_audit_event`** — write-back enriquecido quando o orchestrator de harness estiver pronto.
6. **Semantic search no code intelligence** — hoje `symbols()` é lexical via metadata. Adicionar embedding-based busca pra "encontrar código relacionado a 'cobrança recorrente'" sem nome exato.
7. **Composition tool `atlas_context_for(task)`** — meta-tool que internamente chama recall + code_find + docs_lookup baseado em descrição.

---

## Plan Saved Location

`/Users/vitorepf/Develop/atlas/atlas-server/docs/superpowers/plans/2026-05-03-mcp-tools-expansion.md`

---

## Execution Choice

**Plan complete and saved. Two execution options:**

**1. Subagent-Driven (recommended)** — Eu dispatch um subagent fresco por task, review entre tasks, iteração rápida. Cada um dos 5 agents é dispatched em paralelo onde possível (A4 pode rodar paralelo a A2/A3 inteiro). Estimativa: 1-2 dias de execução.

**2. Inline Execution** — Executo tasks nesta sessão usando executing-plans, batch com checkpoints. Você revê manualmente entre Phases. Estimativa: depende do quanto fica em foreground.

**Qual você prefere?**
