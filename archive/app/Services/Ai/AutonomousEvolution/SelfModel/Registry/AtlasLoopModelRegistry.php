<?php

declare(strict_types=1);

namespace App\Services\Ai\AutonomousEvolution\SelfModel\Registry;

final class AtlasLoopModelRegistry
{
    private ?string $active = null;

    private ?string $previous = null;

    /** @var list<array{version:string,promoted_at:string,receipt:bool}> */
    private array $history = [];

    public function active(): ?string
    {
        return $this->active;
    }

    public function previous(): ?string
    {
        return $this->previous;
    }

    /**
     * @return array{status:string,active?:string,reason?:string}
     */
    public function promote(string $version, bool $operatorReceiptPresent): array
    {
        if (! $operatorReceiptPresent) {
            return [
                'status' => 'rejected',
                'reason' => 'operator_receipt_required',
            ];
        }

        if ($this->active !== null) {
            $this->previous = $this->active;
        }

        $this->active = $version;
        $this->history[] = [
            'version' => $version,
            'promoted_at' => $this->nextPromotedAt(),
            'receipt' => true,
        ];

        return [
            'status' => 'ok',
            'active' => $this->active,
        ];
    }

    /**
     * @return array{status:string,active?:string,from?:string,reason?:string}
     */
    public function revertToPrevious(): array
    {
        if ($this->previous === null) {
            return [
                'status' => 'blocked',
                'reason' => 'no_previous_version',
            ];
        }

        $from = $this->active;
        $this->active = $this->previous;
        $this->previous = $from;

        return [
            'status' => 'reverted',
            'active' => $this->active,
            'from' => $from,
        ];
    }

    /**
     * @return list<array{version:string,promoted_at:string,receipt:bool}>
     */
    public function history(): array
    {
        return $this->history;
    }

    private function nextPromotedAt(): string
    {
        return sprintf('promotion-%06d', count($this->history));
    }
}
