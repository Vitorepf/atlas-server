<?php

declare(strict_types=1);

namespace App\Domain\Inbox;

use App\Domain\Captures\CaptureRepository;

final class InboxLeak3
{
    public function pickRecent(CaptureRepository $repo): array
    {
        return [];
    }
}
