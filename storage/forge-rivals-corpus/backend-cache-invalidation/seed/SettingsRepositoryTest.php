<?php

declare(strict_types=1);

namespace Tests\Unit\Repositories;

use App\Repositories\SettingsRepository;
use PHPUnit\Framework\TestCase;

final class SettingsRepositoryTest extends TestCase
{
    public function test_update_invalidates_cache(): void
    {
        $repo = new SettingsRepository;
        $repo->update('theme', 'slate');
        $this->assertSame('slate', $repo->get('theme'));

        // Update precisa invalidar o cache — o próximo get devolve o novo valor.
        $repo->update('theme', 'gold');
        $this->assertSame('gold', $repo->get('theme'), 'cache stale: update não invalidou');
    }

    public function test_read_uses_cache_on_hit(): void
    {
        $repo = new SettingsRepository;
        $repo->update('theme', 'slate');
        $repo->get('theme'); // miss → popula cache
        $hitsBefore = $repo->cacheHits;
        $repo->get('theme'); // segunda leitura
        $this->assertSame($hitsBefore + 1, $repo->cacheHits, 'segunda leitura precisa ser cache hit');
    }

    public function test_missing_key_returns_null_without_caching(): void
    {
        $repo = new SettingsRepository;
        $this->assertNull($repo->get('absent'));
        $this->assertSame(0, $repo->cacheHits);
    }
}
