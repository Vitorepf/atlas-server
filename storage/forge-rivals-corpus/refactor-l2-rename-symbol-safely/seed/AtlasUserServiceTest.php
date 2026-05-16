<?php

declare(strict_types=1);

namespace Tests\Unit\Users;

use App\Domain\Users\AtlasUserService;
use PHPUnit\Framework\TestCase;

final class AtlasUserServiceTest extends TestCase
{
    public function test_find_by_id_is_the_canonical_method(): void
    {
        $svc = new AtlasUserService;
        $user = $svc->findById('u1');
        $this->assertSame('Avery', $user['name'] ?? null);
    }

    public function test_get_by_id_alias_returns_same_result(): void
    {
        $svc = new AtlasUserService;
        $this->assertSame($svc->findById('u2'), @$svc->getById('u2'));
    }

    public function test_deprecated_alias_emits_notice(): void
    {
        $svc = new AtlasUserService;
        $notice = null;
        set_error_handler(function (int $errno, string $msg) use (&$notice): bool {
            $notice = $msg;

            return true;
        }, E_USER_DEPRECATED);
        try {
            $svc->getById('u1');
        } finally {
            restore_error_handler();
        }
        $this->assertNotNull($notice, 'getById() must trigger E_USER_DEPRECATED');
    }
}
