# atlas-semantic-rag

Real **semantic + graph RAG** Python data runtime for Atlas — embeddings, vector
search and graph retrieval that, by the `runtime_language_boundary` canon, must
run in **Python**, never in the PHP kernel. It exists to replace the PHP
*fallbacks* (`local_hash_signature`, token-cosine `ProgrammingLocalVectorIndex`)
with a real engine, satisfying `SemanticNotesPythonContract`
(`embeddings_engine_in_python = true`, `php_adapter_only`).

## Honesty contract (why this is not the old TF-IDF)

- Embeddings come from a **real learned model** (`fastembed` local ONNX, or
  OpenAI `text-embedding-3-small`). **Never a hash or a TF-IDF token bag.**
- If no real provider is available the runtime **raises an explicit error**
  (`NoEmbeddingProviderError`) — it never silently fabricates vectors.
- Proof: `tests/test_real_embeddings.py` shows that semantically-related text
  ranks high even with **non-overlapping vocabulary** — impossible for a hash or
  TF-IDF. The vector/RAG/graph *logic* is separately proven with known vectors
  (`test_vector_store.py`, `test_rag.py`), so correctness is model-independent.

## Boundary protocol (PHP kernel → this runtime)

The kernel invokes `python3 main.py <manifest.json>` (the canonical
`ProgrammingPythonRuntimeExecutor` shape) and reads one JSON line back.

| operation  | manifest | returns |
|------------|----------|---------|
| `embed`    | `{texts:[...]}` | real vectors (for pgvector) + boundary receipt |
| `retrieve` | `{documents:[{id,text,metadata}], query, k?, graph_expand?, graph_threshold?}` | ranked matches (`via: semantic\|graph`) |
| `graph`    | `{documents:[...], graph_threshold?}` | embedding-similarity graph |

Secrets / provider / model overrides in the manifest are rejected
(`FORBIDDEN_KEYS`) — the kernel governs those; the runtime resolves its own
provider from the environment (local first for sovereignty).

## Run

```bash
python3 -m venv .venv && . .venv/bin/activate
pip install -e '.[test,openai]'   # or .[test,local] for the offline model
pytest                            # logic tests always run; real-embedding test runs when a provider is present
```
