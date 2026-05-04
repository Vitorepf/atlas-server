<?php

namespace Tests\Unit;

use App\Services\Semantic\AtlasVaultFrontmatterService;
use InvalidArgumentException;
use Tests\TestCase;

class AtlasVaultFrontmatterServiceTest extends TestCase
{
    public function test_builds_and_parses_managed_frontmatter_contract(): void
    {
        $service = new AtlasVaultFrontmatterService;

        $frontmatter = $service->build([
            'atlas_id' => 'mem_123',
            'atlas_type' => 'memory_entry',
            'source_type' => 'atlas_memory_entry',
            'source_id' => 'mem_123',
            'privacy_class' => 'normal',
            'provider_safe' => true,
            'redaction_status' => 'clean',
            'updated_at' => '2026-05-03T00:00:00Z',
        ]);
        $markdown = $service->render($frontmatter)."\n# Titulo\n";
        $parsed = $service->parse($markdown);

        $this->assertSame('mem_123', $frontmatter['atlas_id']);
        $this->assertTrue($frontmatter['atlas_managed']);
        $this->assertSame('managed', $frontmatter['sync_status']);
        $this->assertSame([], $service->validateManaged($frontmatter));
        $this->assertTrue($service->isManaged($parsed['frontmatter']));
        $this->assertSame('managed', $service->syncStatus($parsed['frontmatter']));
    }

    public function test_rejects_invalid_frontmatter_enums(): void
    {
        $this->expectException(InvalidArgumentException::class);

        (new AtlasVaultFrontmatterService)->build([
            'atlas_id' => 'mem_123',
            'atlas_type' => 'memory_entry',
            'privacy_class' => 'public',
        ]);
    }

    public function test_build_parses_boolean_strings_strictly(): void
    {
        $frontmatter = (new AtlasVaultFrontmatterService)->build([
            'atlas_id' => 'mem_123',
            'atlas_type' => 'memory_entry',
            'atlas_managed' => 'true',
            'provider_safe' => '0',
            'canonical' => 'false',
        ]);

        $this->assertTrue($frontmatter['atlas_managed']);
        $this->assertFalse($frontmatter['provider_safe']);
        $this->assertFalse($frontmatter['canonical']);
    }

    public function test_build_rejects_invalid_boolean_strings(): void
    {
        $this->expectException(InvalidArgumentException::class);

        (new AtlasVaultFrontmatterService)->build([
            'atlas_id' => 'mem_123',
            'atlas_type' => 'memory_entry',
            'provider_safe' => 'maybe',
        ]);
    }

    public function test_build_rejects_secret_provider_safe_notes(): void
    {
        $this->expectException(InvalidArgumentException::class);

        (new AtlasVaultFrontmatterService)->build([
            'atlas_id' => 'mem_123',
            'atlas_type' => 'memory_entry',
            'privacy_class' => 'secret',
            'provider_safe' => true,
        ]);
    }

    public function test_validate_managed_rejects_secret_provider_safe_notes(): void
    {
        $service = new AtlasVaultFrontmatterService;
        $frontmatter = $service->build([
            'atlas_id' => 'mem_123',
            'atlas_type' => 'memory_entry',
            'privacy_class' => 'secret',
            'provider_safe' => false,
        ]);

        $frontmatter['provider_safe'] = true;

        $this->assertContains('secret_provider_safe', $service->validateManaged($frontmatter));
    }

    public function test_build_rejects_provider_safe_with_blocked_or_needs_review_redaction(): void
    {
        $this->expectException(InvalidArgumentException::class);

        (new AtlasVaultFrontmatterService)->build([
            'atlas_id' => 'mem_123',
            'atlas_type' => 'memory_entry',
            'provider_safe' => true,
            'redaction_status' => 'needs_review',
        ]);
    }

    public function test_validate_managed_rejects_provider_safe_with_blocked_redaction(): void
    {
        $service = new AtlasVaultFrontmatterService;
        $frontmatter = $service->build([
            'atlas_id' => 'mem_123',
            'atlas_type' => 'memory_entry',
            'provider_safe' => false,
            'redaction_status' => 'blocked',
        ]);

        $frontmatter['provider_safe'] = true;

        $this->assertContains('unsafe_redaction_provider_safe', $service->validateManaged($frontmatter));
    }

    public function test_build_rejects_invalid_updated_at_timestamp(): void
    {
        $this->expectException(InvalidArgumentException::class);

        (new AtlasVaultFrontmatterService)->build([
            'atlas_id' => 'mem_123',
            'atlas_type' => 'memory_entry',
            'updated_at' => 'tomorrow',
        ]);
    }

    public function test_validate_managed_rejects_invalid_updated_at_timestamp(): void
    {
        $service = new AtlasVaultFrontmatterService;
        $frontmatter = $service->build([
            'atlas_id' => 'mem_123',
            'atlas_type' => 'memory_entry',
            'updated_at' => '2026-05-03T00:00:00Z',
        ]);

        $frontmatter['updated_at'] = '2026-13-03T00:00:00Z';

        $this->assertContains('invalid_updated_at', $service->validateManaged($frontmatter));
    }

    public function test_parse_returns_errors_instead_of_throwing_for_unsupported_yaml(): void
    {
        $parsed = (new AtlasVaultFrontmatterService)->parse(<<<'MD'
---
atlas_id: mem_123
bad-list:
  - unsupported
---

# Note
MD);

        $this->assertSame([], $parsed['frontmatter']);
        $this->assertStringStartsWith('frontmatter_parse_failed:', $parsed['errors'][0]);
    }

    public function test_validate_managed_rejects_non_boolean_flags(): void
    {
        $service = new AtlasVaultFrontmatterService;
        $frontmatter = $service->build([
            'atlas_id' => 'mem_123',
            'atlas_type' => 'memory_entry',
        ]);

        $frontmatter['atlas_managed'] = 'true';
        $frontmatter['provider_safe'] = 'yes';
        $frontmatter['canonical'] = 'false';

        $this->assertContains('invalid_atlas_managed', $service->validateManaged($frontmatter));
        $this->assertContains('invalid_provider_safe', $service->validateManaged($frontmatter));
        $this->assertContains('invalid_canonical', $service->validateManaged($frontmatter));
    }
}
