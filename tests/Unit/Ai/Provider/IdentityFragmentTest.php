<?php

namespace Tests\Unit\Ai\Provider;

use App\Services\Ai\Kernel\Provider\IdentityFragment;
use Tests\TestCase;

class IdentityFragmentTest extends TestCase
{
    public function test_from_text_produces_stable_hash_and_payload(): void
    {
        $fragment = IdentityFragment::fromText('atlas.test.identity', 'Stable identity text.', [
            'source' => 'unit_test',
        ]);

        $this->assertSame('atlas.test.identity', $fragment->identityId);
        $this->assertSame(hash('sha256', 'Stable identity text.'), $fragment->contentHash);
        $this->assertSame('Stable identity text.', $fragment->text);
        $this->assertSame('unit_test', data_get($fragment->metadata, 'source'));
        $this->assertSame([
            'identity_id' => 'atlas.test.identity',
            'content_hash' => hash('sha256', 'Stable identity text.'),
            'text' => 'Stable identity text.',
            'metadata' => [
                'source' => 'unit_test',
            ],
        ], $fragment->toArray());
    }

    public function test_blank_identity_id_uses_default_identity(): void
    {
        $fragment = IdentityFragment::fromText(' ', 'Identity');

        $this->assertSame('atlas-ai.identity', $fragment->identityId);
        $this->assertSame([], $fragment->metadata);
    }
}
