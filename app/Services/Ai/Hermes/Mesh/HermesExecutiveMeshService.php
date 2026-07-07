<?php

namespace App\Services\Ai\Hermes\Mesh;

/**
 * @deprecated Compat alias only. The canonical class is the RUNTIME ADAPTER
 * {@see HermesWorkcellAdapter} — the Hermes implementation of the provider-neutral
 * Workcell Adapter contract, running UNDER the Workcell Fabric (AAWR). The name
 * "Hermes Executive Mesh" is retired by the Atlas Orchestrator Canon (a provider
 * must never name an architecture position); Hermes is only one implementation.
 * This subclass is kept solely so frozen callers/DI type-hints keep resolving.
 * Canon: docs/engineering-knowledge-base/atlas-orchestrator-canon.md.
 */
class HermesExecutiveMeshService extends HermesWorkcellAdapter {}
