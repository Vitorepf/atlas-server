<?php

declare(strict_types=1);

namespace App\Services\Ai\Organism;

use ReflectionMethod;
use RuntimeException;
use Throwable;

/**
 * AOBG N4.F4 — THE HARDENED PROPOSE-ONLY BOUNDARY. The single gate EVERY actuate() flows
 * through. This is the load-bearing safety of N4 made impossible-to-bypass.
 *
 * Three layers of containment, in order:
 *
 *   1) STRUCTURAL ADMISSION ({@see assertCannotAct()}): before an actuator may ever run, the
 *      gate proves — by reflection — that it inherits the SEALED act path. An actuator that
 *      RE-DECLARES actuate() (i.e. tries to define its own act behaviour) is REFUSED at
 *      registration ({@see AtlasOrganismRegistry::register()} calls this) AND again here at
 *      call time. The only actuate() any domain can run is {@see AbstractDomainActuator::actuate()},
 *      which is `final` and contains NO real-world I/O (no http-post/order/transfer/publish/
 *      purchase). So no domain — present or future, honest or hostile — can flip the boundary
 *      into doing a side effect.
 *
 *   2) PURE ACT PATH: the gate invokes the sealed actuate(), whose ONLY outcome is
 *      {status:'requires_operator', recorded, proposal_ref, domain, instructions, ceiling}.
 *      The gate then ASSERTS the returned status is requires_operator and strips any field that
 *      smells like an executed action (order_id/tx/fill/receipt_url/…) — defence in depth.
 *
 *   3) AUDIT RECEIPT ({@see ActuationReceiptStore}): the gate writes an append-only,
 *      provider-safe receipt for every attempt, so the operator can prove per domain exactly
 *      what Atlas was asked to actuate and that NOTHING was executed. Fail-open: a missing
 *      audit store never throws and never lets the gate skip — the result is still
 *      requires_operator; the receipt just records recorded:false honestly.
 *
 * HONEST CEILING (declared, NON-NEGOTIABLE): PROPOSE-ONLY. The gate NEVER executes real money,
 * orders, ad spend, purchases, or publishing — those are PROHIBITED and gated to propose-only
 * by Atlas's canon. The operator executes any real-world action themselves. Never claim
 * "operating companies live".
 */
final class AtlasOrganismActuationGate
{
    /**
     * Fields that would indicate a real-world action was performed. The sealed act path can
     * never produce these, but the gate strips them as defence-in-depth so even a (impossible)
     * future leak cannot surface an "executed" artifact through the gate.
     *
     * @var list<string>
     */
    private const ACTION_ARTIFACT_KEYS = [
        'order_id', 'order', 'tx', 'tx_hash', 'txid', 'transaction_id', 'fill', 'fills',
        'receipt_url', 'charge_id', 'payment_id', 'transfer_id', 'post_id', 'published_url',
        'message_id', 'campaign_id', 'live_url', 'external_id',
    ];

    public function __construct(private readonly ?ActuationReceiptStore $receipts = null) {}

    /**
     * Admission check: refuse any actuator that does not inherit the SEALED, final act path.
     * Called at registration time AND at actuate() time. An actuator that re-declares
     * actuate() (defines its own act behaviour) is rejected — it could try to act.
     *
     * @throws RuntimeException when the actuator is not propose-only by construction
     */
    public static function assertCannotAct(DomainActuator $actuator): void
    {
        // It MUST extend the sealed base — the interface alone is not enough, because the base
        // is what makes actuate() final + I/O-free. A bare DomainActuator implementor could
        // define an acting actuate().
        if (! $actuator instanceof AbstractDomainActuator) {
            throw new RuntimeException(
                'organism actuator for "'.self::safeDomain($actuator).'" must extend AbstractDomainActuator '
                .'(the sealed propose-only act path) — a bare DomainActuator could perform a real-world action.'
            );
        }

        // The act DECISION must come from the sealed base, never re-declared by the subclass.
        try {
            $declaring = (new ReflectionMethod($actuator, 'actuate'))->getDeclaringClass()->getName();
        } catch (Throwable $e) {
            throw new RuntimeException('cannot verify actuator act path is sealed: '.$e->getMessage());
        }

        if ($declaring !== AbstractDomainActuator::class) {
            throw new RuntimeException(
                'organism actuator for "'.self::safeDomain($actuator).'" RE-DECLARES actuate() in '
                .$declaring.' — the act path must remain the sealed AbstractDomainActuator::actuate() '
                .'(propose-only, no real-world side effect). Registration refused.'
            );
        }
    }

    /**
     * Drive one actuation through the hardened boundary. Returns requires_operator + the
     * operator instruction, and writes an audit receipt. Performs NO real-world side effect.
     *
     * @return array{
     *     status:string, recorded:bool, proposal_ref:string, domain:string,
     *     instructions:string, ceiling:string, audit:array{recorded:bool, receipt_ref:string, reason?:string}
     * }
     */
    public function actuate(DomainActuator $actuator, DomainProposal $proposal): array
    {
        // Layer 1 — admission, again at call time (belt + suspenders).
        self::assertCannotAct($actuator);

        // Layer 2 — invoke the SEALED act path. Its only outcome is requires_operator.
        $result = $actuator->actuate($proposal);

        $status = (string) ($result['status'] ?? '');
        if ($status !== 'requires_operator') {
            // The sealed base can only ever return requires_operator; reaching here means the
            // contract was violated — fail CLOSED, never pass an "executed" outcome through.
            throw new RuntimeException(
                'propose-only invariant violated: actuate() returned status "'.$status.'" — '
                .'the only permitted outcome is requires_operator.'
            );
        }

        // Layer 2 (defence-in-depth) — strip any executed-action artifact. The sealed path
        // cannot produce these; stripping guarantees the gate never surfaces one regardless.
        foreach (self::ACTION_ARTIFACT_KEYS as $k) {
            unset($result[$k]);
        }

        // Layer 3 — append the provider-safe audit receipt (fail-open: never throws/skips).
        $audit = ['recorded' => false, 'receipt_ref' => $proposal->ref(), 'reason' => 'no_receipt_store'];
        if ($this->receipts !== null) {
            try {
                $audit = $this->receipts->record($proposal, $result);
            } catch (Throwable) {
                // An audit-store outage must NOT become a way to skip the gate or to act. The
                // result is still requires_operator; we record the outage honestly.
                $audit = ['recorded' => false, 'receipt_ref' => $proposal->ref(), 'reason' => 'receipt_store_error'];
            }
        }

        $result['audit'] = $audit;

        return $result;
    }

    private static function safeDomain(DomainActuator $actuator): string
    {
        try {
            $d = $actuator->domain();

            return $d !== '' ? $d : $actuator::class;
        } catch (Throwable) {
            return $actuator::class;
        }
    }
}
