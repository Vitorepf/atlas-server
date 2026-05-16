<?php

declare(strict_types=1);

namespace App\Domain\Inbox;

// LEAK: direct import across module boundary.
use App\Domain\Captures\CaptureRepository;

final class InboxLeak1
{
    /** @var CaptureRepository */
    private $repo;

    public function attach(CaptureRepository $repo): void
    {
        $this->repo = $repo;
    }
}
