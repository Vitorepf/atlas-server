import React from "react";

export type ExecutionStatus = "loading" | "running" | "blocked" | "passed" | "failed";

export interface ExecutionStatusPanelProps {
  status: ExecutionStatus;
  label?: string;
}

/**
 * Painel de status de execução.
 *
 * BUG (seed): independente do `status` recebido, sempre renderiza "loading".
 * O arm precisa:
 *   1. Renderizar texto diferente para cada estado (running/blocked/passed/failed/loading).
 *   2. Cada estado precisa de `role="status"` + `aria-live="polite"` anunciando a transição.
 *   3. Status dot estático (sem animação pulse — proibido pelo invalid_if `pulse_halo_introduced`).
 */
export function ExecutionStatusPanel({ status, label }: ExecutionStatusPanelProps): JSX.Element {
  return (
    <div className="execution-status-panel">
      <span className="status-dot" data-status={status} />
      <span className="status-label">loading</span>
    </div>
  );
}
