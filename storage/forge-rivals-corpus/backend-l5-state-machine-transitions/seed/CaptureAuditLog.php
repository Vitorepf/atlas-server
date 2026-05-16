<?php

declare(strict_types=1);

namespace App\Domain\Captures;

final class CaptureAuditLog
{
    /** @var list<array{from:string,to:string,by:string,at:string,reason:string,accepted:bool}> */
    private array $entries = [];

    public function append(string $from, string $to, string $by, string $reason, bool $accepted): void
    {
        $this->entries[] = [
            'from' => $from,
            'to' => $to,
            'by' => $by,
            'at' => '2026-05-16T00:00:00Z', // SEED: replace with injected clock
            'reason' => $reason,
            'accepted' => $accepted,
        ];
    }

    /** @return list<array{from:string,to:string,by:string,at:string,reason:string,accepted:bool}> */
    public function entries(): array
    {
        return $this->entries;
    }

    public function fingerprint(): string
    {
        return hash('sha256', (string) json_encode($this->entries, JSON_THROW_ON_ERROR));
    }
}
