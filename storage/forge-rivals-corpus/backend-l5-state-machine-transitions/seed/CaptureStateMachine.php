<?php

declare(strict_types=1);

namespace App\Domain\Captures;

final class CaptureStateMachine
{
    public function __construct(private readonly CaptureAuditLog $audit) {}

    /**
     * SEED: returns ok for every transition. Replace with the explicit
     * allowlist + deny-by-default logic described in README.md, and
     * make sure every attempt is appended to the audit log.
     *
     * @return array{status:string,from:string,to:string,reason?:string}
     */
    public function transition(string $from, string $to, string $by): array
    {
        return ['status' => 'ok', 'from' => $from, 'to' => $to];
    }
}
