<?php

declare(strict_types=1);

namespace App\Services\Ai\Organism;

/**
 * AOBG N4.F4 — the ACTUATION-RECEIPT seam (the audit trail of every actuate() attempt).
 *
 * THE LOAD-BEARING SAFETY OF N4 is that actuate() is propose-only — it RECORDS + returns
 * 'requires_operator' and performs ZERO real-world side effect. F4 hardens that boundary so
 * that EVERY actuation attempt, for EVERY domain, flows through one gate
 * ({@see AtlasOrganismActuationGate}) which writes an APPEND-ONLY audit receipt here before
 * returning requires_operator. So the operator can later prove, per domain, exactly which
 * proposals Atlas was asked to actuate and that NONE was executed.
 *
 * COST / I/O CONTAINMENT: this is the ONLY place the actuation path touches durable storage,
 * and it writes a RECEIPT (audit metadata), never a real-world action. The gate itself stays
 * pure (no HTTP/order/transfer/publish reachable — see {@see AbstractDomainActuator}); the
 * receipt write is a local DB append. Fail-open: a missing store returns recorded:false with
 * an honest reason — it NEVER throws (an audit-store outage must not become a way to skip the
 * gate), and it NEVER fabricates a write.
 *
 * Production binds {@see EvidenceLedgerActuationReceiptStore} (durable, provider-safe). Tests
 * inject an in-memory fake — sqlite-safe + cost-free.
 */
interface ActuationReceiptStore
{
    /**
     * Append a provider-safe audit receipt for one actuation attempt. The result is ALWAYS
     * requires_operator (no execution) — the receipt records that fact, never an action.
     *
     * @param  array<string,mixed>  $result  the gate result {status, recorded, proposal_ref,
     *                                       domain, instructions, ceiling} — provider-safe, never a payload echo.
     * @return array{recorded:bool, receipt_ref:string, reason?:string}
     */
    public function record(DomainProposal $proposal, array $result): array;

    /**
     * Recent actuation receipts (for the operator review surface). Provider-safe labels only.
     *
     * @return list<array<string,mixed>>
     */
    public function recent(?string $domain = null, int $limit = 50): array;
}
