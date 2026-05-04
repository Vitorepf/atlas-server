# ATLAS CLI - PACKETS TECNICOS V1

**Anexo tecnico dos contratos minimos para o Atlas CLI evoluir de dashboard/CLI para produto terminal profissional**

---

| | |
|---|---|
| **Sistema** | Atlas |
| **Documento** | Atlas CLI - Packets Tecnicos V1 |
| **Data** | 30 de abril de 2026 |
| **Status** | especificacao |
| **Relacionado** | Atlas_CLI_Produto_Final_Roadmap_7_Pontos.md |

---

## 0. Regra

Packets sao contratos de produto. Eles impedem que o Atlas CLI vire chat improvisado.

Cada packet deve ser:

- serializavel em JSON;
- registravel em trace;
- resumivel para TUI;
- utilizavel por testes;
- estavel o suficiente para evoluir com versao.

---

## 1. `dev_execution`

Unidade de trabalho do `atlas dev`.

```json
{
  "type": "dev_execution",
  "version": 1,
  "objective": "implementar streaming token a token",
  "workspace": "/Users/vitorepf/Develop/atlas/atlas-server",
  "phase": "inspect|plan|edit|test|repair|review|finish",
  "provider": "codex_cli",
  "skill": "dev-executor",
  "steps": [
    {
      "id": "inspect",
      "status": "pending|running|passed|failed|blocked",
      "summary": "mapear endpoints de streaming"
    }
  ],
  "files_touched": [],
  "commands_run": [],
  "tests_run": [],
  "checkpoints": [],
  "quality_gates": [],
  "status": "running|blocked|failed|completed"
}
```

### Invariantes

- Toda escrita dentro de `dev_execution` deve apontar para checkpoint.
- `status=completed` exige quality gate ou justificativa explicita.
- `phase` nunca deve voltar silenciosamente sem registrar motivo.

---

## 2. `tool_event`

Evento auditavel de ferramenta.

```json
{
  "event_id": "uuid",
  "trace_id": "uuid",
  "session_id": "uuid",
  "tool": "file.patch",
  "risk": "read|write|danger",
  "permission_status": "allowed|requires_approval|denied",
  "approved_by": "operator|null",
  "input_summary": "aplicar patch no endpoint de streaming",
  "output_summary": "arquivo atualizado com sucesso",
  "changed_files": [
    "app/Http/Controllers/AiInteractionController.php"
  ],
  "checkpoint_id": "uuid|null",
  "exit_code": 0,
  "duration_ms": 100,
  "created_at": "datetime"
}
```

### Invariantes

- Shell mutavel sempre gera `tool_event`.
- Escrita sem `changed_files` e sem justificativa e invalida.
- `risk=danger` nunca pode ter permissao implicita.

---

## 3. `permission_session`

Escopo temporario de permissao para uma sessao.

```json
{
  "session_id": "uuid",
  "workspace": "/Users/vitorepf/Develop/atlas/atlas-server",
  "mode": "read|write|danger",
  "allowed_roots": [
    "/Users/vitorepf/Develop/atlas"
  ],
  "approved_tools": [
    "file.patch",
    "test.run"
  ],
  "approved_paths": [
    "app/",
    "tests/"
  ],
  "denied_patterns": [
    "rm -rf",
    "git reset --hard"
  ],
  "expires_at": "datetime|null"
}
```

### Invariantes

- `danger` expira sempre.
- Aprovacao por sessao nao substitui bloqueios globais.
- Path fora dos roots permitidos falha antes de pedir aprovacao.

---

## 4. `memory_delta`

Memoria candidata gerada depois de sessao importante.

```json
{
  "type": "decision|project_pattern|preference|bug_pattern|test_pattern|architecture",
  "claim": "Neste repo, testes Feature validam melhor o fluxo de AI Gateway que testes Unit isolados.",
  "evidence": [
    {
      "source": "trace",
      "id": "uuid",
      "summary": "falha reproduzida e corrigida em teste Feature"
    }
  ],
  "scope": "global|workspace|project|thread",
  "confidence": "low|medium|high",
  "valid_from": "date",
  "valid_until": null,
  "use_when": [
    "tarefas envolvendo AI Gateway"
  ],
  "do_not_use_when": [
    "mudancas puramente visuais no app"
  ],
  "requires_confirmation": true
}
```

### Invariantes

- `claim` sem evidencia nao vira memoria.
- `requires_confirmation=true` e default para preferencias e decisoes.
- Memoria deve dizer quando nao usar.

---

## 5. `router_decision`

Registro da escolha de provider/modelo.

```json
{
  "task_type": "dev|review|debug|research|decision|chat",
  "selected_provider": "codex_cli",
  "fallback_provider": "claude_cli",
  "signals": {
    "provider_online": true,
    "historical_success_rate": 0.84,
    "latency_score": 0.7,
    "quality_score": 0.9,
    "risk": "medium"
  },
  "reason": "Tarefa de codigo em repo local; Codex esta online e tem melhor score historico para edicao."
}
```

### Invariantes

- Toda decisao automatica deve ter `reason` curto.
- `fallback_provider` deve existir quando houver provider alternativo saudavel.
- Tarefa critica pode exigir reviewer com provider diferente do executor.

---

## 6. Prioridade De Implementacao

1. `dev_execution`
2. `tool_event`
3. `permission_session`
4. `memory_delta`
5. `router_decision`

Motivo: primeiro o Atlas precisa executar trabalho com rastro e permissao; depois aprender e melhorar o roteamento.

