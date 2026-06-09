# Code Graph — Q-2 gold evaluation fixture (AP-815 D1)

A **frozen, hand-verified mini-repo** used to give the code-graph precision/recall
eval a *real* labeled dataset instead of synthetic toy edges. The number this
produces is trustworthy: it measures whether the actual extractor pipeline
recovers relationships a human confirmed by reading the source.

## What is here

```
gold/
  app/
    Contracts/PaymentGateway.php          interface  Gold\Contracts\PaymentGateway
    Gateways/Base/AbstractGateway.php     abstract   Gold\Gateways\Base\AbstractGateway
    Gateways/StripeGateway.php            class      Gold\Gateways\StripeGateway
    Services/CheckoutService.php          class      Gold\Services\CheckoutService
    Services/RefundService.php            class      Gold\Services\RefundService
    Support/Logger.php                    class      Gold\Support\Logger
  gold_edges.json                         human-verified ground-truth edges
  README.md                               this file
```

The five hand-verified relationships (the gold set in `gold_edges.json`):

| from | to | relationship in source |
|------|----|------------------------|
| `StripeGateway`   | `PaymentGateway`  | `implements` the interface |
| `StripeGateway`   | `AbstractGateway` | `extends` the base class |
| `CheckoutService` | `PaymentGateway`  | constructor-injected dependency |
| `CheckoutService` | `Logger`          | constructor-injected dependency + `$this->logger->write()` call |
| `RefundService`   | `StripeGateway`   | references `StripeGateway::class` |

All are `depends_on` edges — the resolver intentionally collapses inheritance /
implementation / use into the restricted edge vocabulary the world-model ranker
already weights (the precise original relation kind is kept in edge metadata).

## How the legacy extractor decides what becomes an edge (READ THIS before adding a case)

The test is a **characterization** test: the legacy code is the oracle. The
PHP symbol pipeline (`EngineeringCodeIntelligenceService` -> `CodeGraphSymbolBuilder`
-> `CodeGraphSymbolResolver`) only emits a symbol→symbol edge when the source
file contains one of:

- a **`use` import** of the target class, or
- a **`::class`** constant reference that resolves (via imports / same namespace)
  to the target's fully-qualified name.

It does **NOT** emit an edge for a *bare* `extends`/`implements`/`new X()`/typed
parameter when the target is in the **same namespace** and therefore needs no
import. (Verified empirically while building this fixture.)

So this fixture deliberately puts each collaborator in its **own namespace**
(`Gold\Support\Logger`, `Gold\Gateways\Base\AbstractGateway`, …) and references it
via a real `use` — which is both the idiomatic PHP form *and* the form the
extractor sees. That keeps the gold set equal to the true semantic graph while
the honest measured score stays high.

If you instead want to characterize a relationship the extractor currently
**misses** (e.g. same-namespace injection), add the fixture file/relationship and
add it to `gold_edges.json` **but do not** raise it via an import — then let the
floor reflect the real (lower) recall and say so. Never edit the extractor to
make the number look better; that games the audit.

## Measured result (anti-over-claim)

On 2026-06-09, on this frozen fixture, the real pipeline scored:

```
precision = 1.0   recall = 1.0   f1 = 1.0   (tp=5 fp=0 fn=0 | predicted=5 gold=5)
```

confirmed identically by the PHP set arithmetic and the python_ai_data
`eval_precision_recall` op. The test enforces literal floors of
`precision >= 0.7` and `recall >= 0.7`; a regression drops below them and fails
with the exact tp/fp/fn breakdown (including which edges were spurious/missed).

## How to run

```bash
php -d memory_limit=3072M artisan test tests/Feature/CodeGraph/CodeGraphGoldEvalTest.php
```

The test boots only the tables it needs in `setUp()` (full `RefreshDatabase` is
unreliable here — a core migration is pgsql-only). The first test enforces the
floors using PHP set arithmetic and needs no python. The second test runs the
governed python eval op (with a minted Decision Receipt) and asserts it agrees
with the PHP numbers; it **self-skips** when the
`runtimes/python/code_graph/.venv` interpreter is absent.

## How to add a new gold case

1. Add a tiny PHP class under `gold/app/...` (one class per file — the resolver
   attributes an edge from *every* class defined in a file, so multiple classes
   per file make `from` ambiguous).
2. Express the intended dependency the way the extractor sees it (a real `use`
   import or a `::class` reference) — unless you are deliberately characterizing
   a miss (see above).
3. Add the human-verified edge(s) to `gold_edges.json` as
   `{ "from": "<FQN>", "to": "<FQN>", "type": "depends_on", "relationship": "<why>" }`.
   `from`/`to` are fully-qualified class names; the test maps them to
   `sym:<FQN>` node ids.
4. Re-run the test. If precision/recall drop below the floors, decide whether the
   extractor regressed (fix the extractor in its own change) or the new case is a
   known miss (lower the floor in `CodeGraphGoldEvalTest` and document why here).
   Keep the fixture **frozen** otherwise.
