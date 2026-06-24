<?php

declare(strict_types=1);

namespace Tests\Unit\Ai\AutonomousEvolution\Cortex;

use App\Services\Ai\AutonomousEvolution\Discovery\Cortex\AtlasCortexIntentExtractor;
use App\Services\Ai\AutonomousEvolution\Discovery\Cortex\IntentExtractionFact;
use PHPUnit\Framework\TestCase;

final class AtlasCortexIntentExtractorTest extends TestCase
{
    public function test_extracts_class_docblock_purpose_and_design_tags(): void
    {
        [$phpPath, $docsRoot] = $this->fixture(<<<'PHP'
<?php

namespace Tests\Fixtures\CortexIntent;

/**
 * @purpose Turn queue facts into intent facts.
 * @design Deterministic parser over source bytes.
 */
final class TaggedIntentFixture
{
}
PHP);

        $fact = (new AtlasCortexIntentExtractor($docsRoot))->extract($phpPath);

        $this->assertSame('Tests\Fixtures\CortexIntent\TaggedIntentFixture', $fact->fqcn);
        $this->assertSame('Turn queue facts into intent facts.', $fact->docblockPurpose);
        $this->assertSame('Deterministic parser over source bytes.', $fact->docblockDesign);
        $this->assertSame(IntentExtractionFact::CONFIDENCE_HIGH, $fact->confidence);
    }

    public function test_links_fqcn_to_preferred_markdown_doc_excerpt(): void
    {
        [$phpPath, $docsRoot] = $this->fixture(<<<'PHP'
<?php

namespace Tests\Fixtures\CortexIntent;

final class DocMentionOnlyFixture
{
}
PHP);
        $this->writeDoc($docsRoot, 'notes/random.md', 'DocMentionOnlyFixture appears in a non-preferred note.');
        $this->writeDoc(
            $docsRoot,
            'engineering-knowledge-base/cortex-intent.md',
            'The class Tests\Fixtures\CortexIntent\DocMentionOnlyFixture captures purpose from canonical docs.',
        );

        $fact = (new AtlasCortexIntentExtractor($docsRoot))->extract($phpPath);

        $this->assertSame('engineering-knowledge-base/cortex-intent.md', $fact->docPath);
        $this->assertStringContainsString('DocMentionOnlyFixture captures purpose', (string) $fact->docExcerpt);
        $this->assertLessThanOrEqual(200, strlen((string) $fact->docExcerpt));
    }

    public function test_same_file_bytes_produce_byte_identical_fact(): void
    {
        [$phpPath, $docsRoot] = $this->fixture(<<<'PHP'
<?php

namespace Tests\Fixtures\CortexIntent;

/**
 * @purpose Keep intent extraction deterministic.
 */
final class DeterministicIntentFixture
{
}
PHP);
        $extractor = new AtlasCortexIntentExtractor($docsRoot);

        $first = json_encode($extractor->extract($phpPath)->toArray(), JSON_UNESCAPED_SLASHES | JSON_THROW_ON_ERROR);
        $second = json_encode($extractor->extract($phpPath)->toArray(), JSON_UNESCAPED_SLASHES | JSON_THROW_ON_ERROR);

        $this->assertSame($first, $second);
    }

    public function test_missing_tags_and_docs_returns_low_confidence_without_throwing(): void
    {
        [$phpPath, $docsRoot] = $this->fixture(<<<'PHP'
<?php

namespace Tests\Fixtures\CortexIntent;

final class UnknownIntentFixture
{
}
PHP);

        $fact = (new AtlasCortexIntentExtractor($docsRoot))->extract($phpPath);

        $this->assertSame('Tests\Fixtures\CortexIntent\UnknownIntentFixture', $fact->fqcn);
        $this->assertNull($fact->docblockPurpose);
        $this->assertNull($fact->docblockDesign);
        $this->assertNull($fact->docPath);
        $this->assertNull($fact->docExcerpt);
        $this->assertSame(IntentExtractionFact::CONFIDENCE_LOW, $fact->confidence);
    }

    /**
     * @return array{0:string,1:string}
     */
    private function fixture(string $php): array
    {
        $root = sys_get_temp_dir().'/atlas-cortex-intent-'.bin2hex(random_bytes(6));
        $srcRoot = $root.'/app/Services/Ai/AutonomousEvolution';
        $docsRoot = $root.'/docs';
        mkdir($srcRoot, 0775, true);
        mkdir($docsRoot, 0775, true);
        $phpPath = $srcRoot.'/Fixture.php';
        file_put_contents($phpPath, $php);

        return [$phpPath, $docsRoot];
    }

    private function writeDoc(string $docsRoot, string $relativePath, string $body): void
    {
        $path = $docsRoot.'/'.$relativePath;
        $dir = dirname($path);
        if (! is_dir($dir)) {
            mkdir($dir, 0775, true);
        }
        file_put_contents($path, $body);
    }
}
