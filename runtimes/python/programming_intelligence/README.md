# Atlas Programming Intelligence Runtime

Governed Python runtime for programming code intelligence. It is intentionally
standard-library only and does not call providers, tools, shell commands, memory
stores, databases, or network endpoints.

## Contract

Input is a JSON manifest:

```json
{
  "schema_version": "atlas.programming.python_runtime.request.v1",
  "workspace": "/repo",
  "files": ["app/Foo.php"],
  "limits": {"max_files": 40, "max_bytes_per_file": 250000}
}
```

Output is `atlas.programming.python_runtime.analysis.v1` with provider-safe
symbols, import references, deterministic local token vectors and receipt hash.

## Smoke

```bash
PYTHONPATH=runtimes/python/programming_intelligence python3 -m unittest discover runtimes/python/programming_intelligence/tests
python3 runtimes/python/programming_intelligence/main.py manifest.json
```
