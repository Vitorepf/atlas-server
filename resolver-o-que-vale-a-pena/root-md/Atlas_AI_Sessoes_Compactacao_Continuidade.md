> Cleanup status: superseded_source_material.
> Canonical replacement: docs/engineering-knowledge-base/atlas-ai-continuity-session-state.md; docs/engineering-knowledge-base/open-brain-context-injection.md.
> Cleanup note: Continuity/session decisions have been promoted to a canonical KB doc. Preserve this file as source material, not authority.

# ATLAS AI - SESSOES, COMPACTACAO E CONTINUIDADE

**Arquitetura profissional para conversas longas, implementacoes por horas, troca de providers e memoria operacional persistente**

---

| | |
|---|---|
| **Operador** | Vitor Emanuel |
| **Sistema** | Atlas |
| **Documento** | Atlas AI - Sessoes, Compactacao e Continuidade |
| **Versao** | 1.0 |
| **Data** | 29 de abril de 2026 |
| **Status** | Especificacao tecnica de implementacao |
| **Autoridade superior** | Atlas_AI_Documentacao_Final.md |
| **Documentos relacionados** | Atlas_AI_Plano_Implementacao_Profissional.md, Atlas_AI_Harness_v1.md, Atlas_AI_Skill_System_v1.md |

---

## 0. Decisao Executiva

O Atlas AI nao deve ser apenas um chat.

O chat e uma superficie de interacao. Por baixo, o Atlas AI precisa ser um sistema de **sessoes cognitivas persistentes** com estado, compactacao, memoria, traces, workflows e continuidade entre providers.

Claude, Codex, GPT e outros modelos nao podem ser donos da sessao. A sessao pertence ao Atlas.

Isso permite:

- conversar por horas sem perder contexto;
- implementar por horas sem o agente esquecer objetivo, plano, arquivos, erros e testes;
- trocar Claude por Codex sem reiniciar a conversa;
- compactar automaticamente antes de degradar performance;
- preservar decisoes e aprendizados;
- diferenciar conversa nova, continuacao e retomada;
- manter historico auditavel sem mandar tudo para o modelo.

---

## 1. Modelo Mental

O Atlas AI deve operar em camadas:

```text
Mensagem
-> Thread
-> Sessao
-> Workspace / Projeto
-> Memoria Persistente
```

| Camada | Funcao | Exemplo |
|---|---|---|
| **Mensagem** | Uma entrada ou saida. | "Ambos" / resposta do Atlas. |
| **Thread** | Conversa continua sobre um assunto. | "Arquitetura de agentes do Atlas AI". |
| **Sessao** | Bloco operacional com estado de trabalho. | "Implementar ContextPack por 3 horas". |
| **Workspace/Projeto** | Contexto estavel acima da sessao. | `atlas-server`, `atlas-app`, BlackInk, Saude. |
| **Memoria Persistente** | Conhecimento qualificado reutilizavel. | Decisoes, preferencias, comandos, padroes. |

Thread e a unidade de experiencia. Sessao e a unidade de trabalho. Trace e a unidade de auditoria.

---

## 2. Objetivos Do Sistema

1. **Nao perder continuidade.**  
   Respostas curtas como "C", "ambos", "isso", "continua" e "faz" precisam resolver contra contexto anterior.

2. **Nao carregar historico bruto infinito.**  
   O Atlas deve compactar conversas longas em resumos estruturados.

3. **Nao depender da memoria do provider.**  
   Claude, Codex e GPT recebem contexto preparado pelo Atlas.

4. **Manter performance por horas.**  
   Em tarefas longas, o contexto deve ser sempre o estado atual, nao a conversa inteira.

5. **Diferenciar conversa e trabalho.**  
   Pergunta simples, decisao, pesquisa e implementacao longa usam estados diferentes.

6. **Permitir troca de provider.**  
   O novo provider deve receber Session Brief, estado atual, resumo e ultimos turnos.

7. **Produzir memoria candidata.**  
   Ao fechar ou compactar sessao, aprendizados reutilizaveis podem virar MemoryDelta.

---

## 3. Modelo De Dados

### 3.1 `ai_threads`

Unidade de conversa visivel no app.

```sql
CREATE TABLE ai_threads (
  id UUID PRIMARY KEY DEFAULT gen_random_uuid(),
  thread_key TEXT NOT NULL UNIQUE,
  title TEXT NOT NULL,
  status TEXT NOT NULL DEFAULT 'active' CHECK (status IN (
    'active', 'paused', 'archived', 'closed'
  )),
  surface TEXT NOT NULL DEFAULT 'app' CHECK (surface IN (
    'app', 'mac_cli', 'api', 'automation', 'browser'
  )),
  domain TEXT,
  current_session_id UUID,
  summary TEXT,
  summary_version INTEGER NOT NULL DEFAULT 0,
  last_message_at TIMESTAMPTZ,
  metadata JSONB NOT NULL DEFAULT '{}'::jsonb,
  created_at TIMESTAMPTZ NOT NULL DEFAULT NOW(),
  updated_at TIMESTAMPTZ NOT NULL DEFAULT NOW()
);
```

Campos importantes em `metadata`:

```json
{
  "active_goal": "",
  "last_provider": "claude_cli",
  "preferred_mode": "direct|plan|review|execute|research|decision",
  "pinned_memory_refs": [],
  "project_refs": [],
  "manual_title": false
}
```

### 3.2 `ai_messages`

Historico conversacional visivel e pesquisavel.

```sql
CREATE TABLE ai_messages (
  id UUID PRIMARY KEY DEFAULT gen_random_uuid(),
  thread_id UUID NOT NULL REFERENCES ai_threads(id) ON DELETE CASCADE,
  trace_id UUID REFERENCES ai_traces(id) ON DELETE SET NULL,
  role TEXT NOT NULL CHECK (role IN (
    'user', 'assistant', 'system', 'tool', 'summary'
  )),
  provider TEXT,
  agent_slug TEXT,
  content TEXT NOT NULL,
  content_hash TEXT,
  token_estimate INTEGER,
  compacted_at TIMESTAMPTZ,
  metadata JSONB NOT NULL DEFAULT '{}'::jsonb,
  created_at TIMESTAMPTZ NOT NULL DEFAULT NOW()
);
```

Mensagem nunca deve ser apagada para compactar. Compactacao cria resumo, nao deleta historico.

### 3.3 `ai_sessions`

Unidade operacional de trabalho.

```sql
CREATE TABLE ai_sessions (
  id UUID PRIMARY KEY DEFAULT gen_random_uuid(),
  thread_id UUID REFERENCES ai_threads(id) ON DELETE SET NULL,
  session_key TEXT NOT NULL UNIQUE,
  type TEXT NOT NULL CHECK (type IN (
    'chat', 'dev', 'review', 'debug', 'research', 'decision', 'memory', 'study'
  )),
  status TEXT NOT NULL DEFAULT 'active' CHECK (status IN (
    'active', 'paused', 'waiting_human', 'completed', 'failed', 'closed'
  )),
  objective TEXT NOT NULL,
  provider_primary TEXT,
  provider_last TEXT,
  workflow TEXT,
  risk_level TEXT NOT NULL DEFAULT 'low',
  started_at TIMESTAMPTZ NOT NULL DEFAULT NOW(),
  last_activity_at TIMESTAMPTZ NOT NULL DEFAULT NOW(),
  completed_at TIMESTAMPTZ,
  metadata JSONB NOT NULL DEFAULT '{}'::jsonb,
  created_at TIMESTAMPTZ NOT NULL DEFAULT NOW(),
  updated_at TIMESTAMPTZ NOT NULL DEFAULT NOW()
);
```

### 3.4 `ai_session_states`

Estado compacto atual, usado para contexto de alta performance.

```sql
CREATE TABLE ai_session_states (
  id UUID PRIMARY KEY DEFAULT gen_random_uuid(),
  session_id UUID NOT NULL REFERENCES ai_sessions(id) ON DELETE CASCADE,
  version INTEGER NOT NULL,
  state_kind TEXT NOT NULL CHECK (state_kind IN (
    'chat', 'dev', 'research', 'decision', 'memory', 'study'
  )),
  state JSONB NOT NULL,
  source_message_id UUID,
  source_trace_id UUID,
  created_by TEXT NOT NULL DEFAULT 'atlas',
  created_at TIMESTAMPTZ NOT NULL DEFAULT NOW(),
  UNIQUE(session_id, version)
);
```

Exemplo para chat:

```json
{
  "objective": "Discutir arquitetura de agentes e skills do Atlas AI",
  "current_topic": "sessões e compactação",
  "user_position": "quer substituir Claude/GPT e ter continuidade entre providers",
  "decisions": [],
  "open_questions": [
    "como detectar nova sessão?",
    "como compactar automaticamente?"
  ],
  "next_step": "projetar modelo de dados e algoritmo"
}
```

Exemplo para dev:

```json
{
  "objective": "Implementar ContextPack no atlas-server",
  "repo": "/Users/vitorepf/Develop/atlas/atlas-server",
  "branch": "main",
  "files_touched": [
    "app/Services/Ai/AiPromptBuilder.php",
    "app/Services/Ai/AiContextPackBuilder.php"
  ],
  "plan": [
    {"step": "Criar contratos", "status": "done"},
    {"step": "Integrar traces", "status": "done"},
    {"step": "Criar threads", "status": "next"}
  ],
  "commands_run": [
    {"cmd": "php artisan test", "status": "passed"}
  ],
  "known_risks": [],
  "next_step": "criar ai_threads e ai_messages"
}
```

### 3.5 `ai_compactions`

Registro auditavel de compactacoes.

```sql
CREATE TABLE ai_compactions (
  id UUID PRIMARY KEY DEFAULT gen_random_uuid(),
  thread_id UUID REFERENCES ai_threads(id) ON DELETE CASCADE,
  session_id UUID REFERENCES ai_sessions(id) ON DELETE CASCADE,
  compaction_key TEXT NOT NULL UNIQUE,
  kind TEXT NOT NULL CHECK (kind IN (
    'auto', 'manual', 'provider_switch', 'phase_change', 'session_resume', 'session_close'
  )),
  input_message_ids JSONB NOT NULL DEFAULT '[]'::jsonb,
  input_trace_ids JSONB NOT NULL DEFAULT '[]'::jsonb,
  previous_summary TEXT,
  summary TEXT NOT NULL,
  state_delta JSONB NOT NULL DEFAULT '{}'::jsonb,
  token_estimate_before INTEGER,
  token_estimate_after INTEGER,
  quality_score SMALLINT CHECK (quality_score IS NULL OR quality_score BETWEEN 1 AND 5),
  provider TEXT,
  model TEXT,
  metadata JSONB NOT NULL DEFAULT '{}'::jsonb,
  created_at TIMESTAMPTZ NOT NULL DEFAULT NOW()
);
```

### 3.6 `ai_context_snapshots`

Contexto efetivamente enviado ao provider.

```sql
CREATE TABLE ai_context_snapshots (
  id UUID PRIMARY KEY DEFAULT gen_random_uuid(),
  trace_id UUID REFERENCES ai_traces(id) ON DELETE CASCADE,
  thread_id UUID REFERENCES ai_threads(id) ON DELETE SET NULL,
  session_id UUID REFERENCES ai_sessions(id) ON DELETE SET NULL,
  context_pack JSONB NOT NULL,
  prompt_hash TEXT,
  token_estimate INTEGER,
  created_at TIMESTAMPTZ NOT NULL DEFAULT NOW()
);
```

No V1, `context_pack` pode continuar em `ai_traces.metadata`. Esta tabela entra quando precisarmos auditar e comparar melhor.

---

## 4. Decidir Nova Thread, Continuacao Ou Nova Sessao

O Atlas precisa de um `ThreadResolver`.

Entrada:

```text
input atual
thread ativa
session ativa
tempo desde ultima mensagem
surface
provider solicitado
task_type inferido
domain inferido
referencias curtas detectadas
estado atual da sessao
```

Saida:

```json
{
  "action": "continue_thread | new_thread | new_session_same_thread | resume_thread | ask_user",
  "confidence": 0.0,
  "reason": "",
  "thread_id": "",
  "session_id": ""
}
```

### 4.1 Continuar Thread

Continuar quando:

- input e curto e referencial: "sim", "nao", "C", "ambos", "isso", "continua", "faz";
- ultima mensagem tem pergunta aberta;
- mesmo dominio;
- mesma superficie;
- tempo desde ultima mensagem e baixo;
- usuario nao pediu novo assunto;
- workflow atual ainda esta ativo.

Regra:

```text
Se input curto depende de contexto, nunca criar nova thread automaticamente.
```

### 4.2 Nova Thread

Criar nova thread quando:

- usuario pede "novo assunto", "nova conversa";
- mudanca forte de dominio sem referencia;
- thread anterior fechada ou arquivada;
- ultima atividade muito antiga e input nao parece continuacao;
- contexto antigo teria alto risco de contaminar resposta;
- usuario abre explicitamente nova conversa.

### 4.3 Nova Sessao Na Mesma Thread

Criar nova sessao quando:

- mesma conversa muda de fase;
- conversa vira implementacao;
- pesquisa vira decisao;
- decisao vira execucao;
- app vira CLI;
- provider muda para executar outra funcao;
- tarefa fica longa e precisa estado proprio.

Exemplo:

```text
Thread: "Arquitetura Atlas AI"
Sessao 1: conversa conceitual
Sessao 2: implementar ai_threads
Sessao 3: revisar resultado
```

### 4.4 Perguntar Ao Usuario

Perguntar quando:

- dois contextos possiveis competem;
- input e curto, mas ha mais de uma pergunta aberta;
- risco alto e contexto ambiguo;
- decisao pode alterar projeto/sessao errada.

Pergunta ideal:

```text
Voce quer continuar a sessao anterior de X ou abrir novo assunto?
```

No app, isso deve ser UI de 2 opcoes, nao textao.

---

## 5. Compactacao

Compactacao nao e apagar historico. Compactacao e transformar historico bruto em estado util.

### 5.1 O Que Compactar

Para chat:

```text
objetivo da conversa
posicao atual de Vitor
decisoes tomadas
perguntas abertas
conceitos definidos
preferencias expressas
proximo passo
```

Para dev:

```text
objetivo
repo/branch
plano
arquivos tocados
diff summary
comandos rodados
erros encontrados
decisoes tecnicas
riscos
proximo passo
```

Para research:

```text
pergunta
criterios de fonte
fontes consultadas
claims confirmadas
claims duvidosas
lacunas
recomendacao parcial
proximo passo
```

Para decision:

```text
objetivo
opcoes
criterios
tradeoffs
risco
reversibilidade
stakeholders
decisao ratificada ou pendente
```

### 5.2 Quando Compactar Automaticamente

Compactar quando:

- mensagem N desde ultima compactacao: default 12;
- estimativa de tokens da thread passa limite: default 20k;
- sessao muda de fase;
- provider vai trocar;
- sessao sera retomada depois de inatividade;
- tarefa longa passa marco de progresso;
- antes de fechar sessao;
- antes de gerar MemoryDelta.

### 5.3 Tipos De Compactacao

| Tipo | Quando | Resultado |
|---|---|---|
| **rolling_summary** | conversa cresce | resumo incremental da thread |
| **session_state_update** | tarefa avanca | novo estado de sessao |
| **provider_handoff** | troca Claude/Codex | handoff brief |
| **phase_transition** | plan -> execute, research -> decision | estado reformatado para nova fase |
| **closeout_summary** | fechar sessao | completion packet + MemoryDelta |
| **resume_brief** | retomar depois | contexto compacto de retomada |

### 5.4 Algoritmo De Compactacao

```text
1. coletar mensagens desde ultima compactacao
2. coletar summary atual
3. coletar state atual
4. classificar tipo de sessao
5. renderizar prompt de compactacao
6. gerar novo summary + state_delta
7. validar schema
8. salvar ai_compactions
9. atualizar ai_threads.summary
10. criar nova ai_session_state
11. marcar mensagens como compacted_at, sem apagar
```

### 5.5 Quality Gate Da Compactacao

Uma compactacao precisa preservar:

- objetivo;
- decisoes;
- open loops;
- restricoes;
- preferencias novas;
- riscos;
- artefatos;
- proximo passo.

Se perder open loop ou decisao, compactacao falha.

---

## 6. Contexto Enviado Ao Modelo

O Atlas deve montar Context Pack em camadas:

```text
1. Master Prompt + leis relevantes
2. Skill ativa
3. TaskRequest
4. Session Brief
5. Thread Summary
6. Session State
7. Ultimas mensagens brutas
8. Memorias relevantes
9. Evidencias/ferramentas/repos
10. Lacunas e constraints
```

### 6.1 Orcamento Inicial

| Camada | Budget sugerido |
|---|---:|
| Identidade e skill | 2k-4k tokens |
| Session Brief | 1k |
| Thread Summary | 1k-2k |
| Session State | 2k-6k |
| Ultimas mensagens | 4-12 turnos |
| Memorias relevantes | 3-8 itens |
| Repo/diff/tools | depende da tarefa |

### 6.2 Regra De Performance

Para tarefa longa, o modelo deve receber **estado atual**, nao conversa inteira.

Isso e o que faz Codex performar por horas: nao depender so de historico bruto, mas manter plano, arquivos, erros, comandos e proximo passo como estado operacional.

---

## 7. Troca De Provider

Provider switch nao pode reiniciar a sessao.

Quando Vitor troca Claude -> Codex ou Codex -> Claude:

1. Atlas compacta se necessario.
2. Cria `provider_handoff`.
3. Novo provider recebe Session Brief.
4. Novo provider recebe ultimas mensagens relevantes.
5. Novo provider recebe objetivo, estado, decisoes e proximo passo.
6. Trace registra troca.

### 7.1 Handoff Brief

```json
{
  "thread_title": "Arquitetura Atlas AI",
  "session_type": "decision",
  "objective": "Definir sistema de sessoes e compactacao",
  "user_current_intent": "quer arquitetura profissional, nao apenas chat",
  "decisions": [
    "Atlas AI nao sera apenas chat",
    "sessao pertence ao Atlas, nao ao provider"
  ],
  "open_questions": [
    "qual modelo de dados implementar primeiro?"
  ],
  "last_provider": "claude_cli",
  "new_provider": "codex_cli",
  "next_step": "produzir especificacao implementavel"
}
```

### 7.2 Regra

O novo provider deve ser informado:

```text
Voce esta entrando em uma sessao ja em andamento. Nao trate como conversa nova.
```

---

## 8. Sessoes Longas De Desenvolvimento

Sessao de dev precisa ser mais parecida com Codex do que com chat.

### 8.1 Estado De Dev

```json
{
  "objective": "",
  "repo": "",
  "branch": "",
  "dirty_state_at_start": "",
  "plan": [],
  "files_read": [],
  "files_changed": [],
  "commands_run": [],
  "test_status": "",
  "known_errors": [],
  "architecture_decisions": [],
  "blocked_on": [],
  "next_step": "",
  "completion_packet": null
}
```

### 8.2 Checkpoints

Criar checkpoint quando:

- antes de editar;
- depois de plano aprovado;
- depois de teste verde;
- antes de troca de provider;
- antes de compactacao grande;
- antes de pausa longa.

### 8.3 Retomada

`atlas resume` deve mostrar:

```text
Sessao: implementar ai_threads
Estado: active
Ultimo passo: ContextPack integrado
Proximo passo: migration ai_threads/ai_messages
Testes: php artisan test passou
Riscos: nenhum bloqueante
```

---

## 9. UX No App

O app deve parecer simples, mas operar como sistema de sessoes.

### 9.1 Elementos Visiveis

- lista de threads;
- thread atual;
- botao nova conversa;
- indicacao discreta de provider;
- troca de provider mantendo thread;
- "resumir sessao";
- "salvar aprendizado";
- "fechar sessao";
- aviso quando Atlas detecta novo assunto;
- painel de contexto/memoria usado.

### 9.2 Regras De UX

- Nao perguntar demais.
- Nao expor banco/tabela/trace por default.
- Nao mostrar compactacao como evento tecnico salvo se Vitor pedir.
- Mostrar "Atlas resumiu a sessao" apenas quando util.
- Para input curto, resolver automaticamente contra contexto.
- Para ambiguidade real, perguntar com opcoes curtas.

---

## 10. UX No CLI/TUI

Comandos canonicos atuais:

```bash
atlas ask "..."
atlas resume
atlas threads
atlas compact
atlas handoff codex
atlas handoff claude
atlas dev "..."
atlas review --base main
```

Normalizacao conforme `Atlas_AI_CLI_Nomenclatura_Comandos_TUI_ADR.md`:

| Comando antigo citado | Status | Substituto canonico |
|---|---|---|
| `atlas new` | legado/futuro ambiguo | `atlas ask` inicia conversa; futuro `atlas threads new` se necessario |
| `atlas sessions` | legado com alias de compatibilidade | `atlas threads` |
| `atlas close` | legado/futuro ambiguo | futuro `atlas threads archive` |
| `atlas switch codex/claude` | legado com alias de compatibilidade | `atlas handoff codex/claude` |
| `atlas trace last` | planejado | futuro `atlas trace last/show` no roadmap de tool events |

### 10.1 Sessao Longa

```bash
atlas dev "implementar ai_threads e ai_messages"
# trabalha
atlas compact
# troca motor
atlas handoff codex
# continua
# futuro: atlas threads archive
```

O CLI deve sempre mostrar:

```text
thread
sessao
provider atual
modo
proximo passo
gates pendentes
```

---

## 11. Memory Delta Ao Fechar Sessao

Fechar sessao deve propor memoria candidata:

```json
{
  "kind": "decision|preference|procedure|bug_lesson|architecture_rule",
  "title": "",
  "content": "",
  "evidence_refs": [],
  "scope": "",
  "confidence": "low|medium|high",
  "valid_from": "",
  "valid_until": null,
  "activation_triggers": [],
  "do_not_use_when": [],
  "ratification_required": true
}
```

Nem toda sessao gera memoria. Mas toda sessao relevante deve ser avaliada para isso.

---

## 12. Services A Implementar

### Backend

```text
AiThreadService
AiThreadResolver
AiMessageStore
AiSessionService
AiSessionStateService
AiCompactionService
AiCompactionPolicy
AiProviderHandoffBuilder
AiSessionContextBuilder
AiMemoryDeltaService
```

### Value Objects

```text
ThreadResolution
SessionBrief
SessionState
CompactionRequest
CompactionResult
ProviderHandoff
```

### API

```text
GET    /ai/threads
POST   /ai/threads
GET    /ai/threads/{thread}
PATCH  /ai/threads/{thread}
POST   /ai/threads/{thread}/messages
POST   /ai/threads/{thread}/compact
POST   /ai/threads/{thread}/close
POST   /ai/threads/{thread}/switch-provider
GET    /ai/sessions
GET    /ai/sessions/{session}
POST   /ai/sessions/{session}/resume
```

No curto prazo, `POST /ai/interactions` pode aceitar `thread_id` e `session_id`.

---

## 13. Implementacao Em Fases

### Fase 1 - Threads Reais

- Migration `ai_threads`.
- Migration `ai_messages`.
- `POST /ai/interactions` aceita `thread_id`.
- Se nao houver thread, cria uma.
- Salva user message antes do job.
- Salva assistant message ao concluir job.
- App lista e continua thread.

### Fase 2 - Session State

- Migration `ai_sessions`.
- Migration `ai_session_states`.
- Criar sessao automatica por thread.
- Context Pack inclui summary/state.
- `atlas resume` no futuro usa isso.

### Fase 3 - Compactacao Automatica

- Migration `ai_compactions`.
- `AiCompactionPolicy`.
- Compactar apos N mensagens ou provider switch.
- Atualizar `ai_threads.summary`.
- Criar `ai_session_state` novo.

### Fase 4 - Provider Handoff

- Troca Claude/Codex sem perder contexto.
- `provider_handoff` antes da troca.
- App permite trocar motor dentro da thread.

### Fase 5 - Dev Sessions

- Estado de repo.
- Commands run.
- Files touched.
- Completion packet.
- `atlas dev/resume/compact`.

---

## 14. Metricas

| Metrica | Meta |
|---|---|
| Resolucao de referencia curta | >95% em "sim", "C", "ambos", "continua" dentro da thread |
| Context loss reportado | <5% das interacoes |
| Provider switch bem-sucedido | >90% sem pedir reexplicacao |
| Compactacao util | >80% avaliada como correta em review manual |
| Retomada de sessao | <30s para entender estado |
| Dev session longa | >2h sem perda de objetivo |

---

## 15. Anti-Padroes

| Anti-padrao | Risco | Correcao |
|---|---|---|
| Thread = historico bruto | Contexto caro e ruim | Summary + state |
| Compactar apagando mensagem | Perda de auditoria | Mensagens ficam, resumo adiciona |
| Provider dono da sessao | Troca perde contexto | Sessao pertence ao Atlas |
| Toda mudanca vira nova thread | Fragmentacao | ThreadResolver |
| Nunca abrir nova thread | Contaminacao de contexto | Detecao de mudanca de assunto |
| Compactar sem schema | Resumo bonito, estado inutil | SessionState tipado |
| Compactar tarde demais | Degradacao antes da acao | Policy por tokens/fase |
| Perguntar demais ao usuario | UX ruim | Resolver automaticamente quando confianca alta |

---

## 16. Primeira Implementacao Recomendada

Implementar primeiro:

```text
ai_threads
ai_messages
thread_id em ai_traces
ThreadResolver simples
ContextPack com thread summary + ultimas mensagens
App com thread atual e nova conversa
```

Depois:

```text
ai_sessions
ai_session_states
compactacao manual
compactacao automatica
provider handoff
```

Esta ordem entrega valor rapido e prepara o sistema para sessoes longas sem construir complexidade demais antes da hora.

---

## 17. Regra Final

O Atlas AI pode parecer chat na superficie, mas nao pode funcionar como chat por baixo.

Ele deve funcionar como um sistema de sessoes cognitivas persistentes: cada conversa tem continuidade, cada tarefa longa tem estado, cada compactacao preserva decisoes e proximos passos, cada provider recebe contexto Atlas e cada fechamento pode gerar memoria reutilizavel.
