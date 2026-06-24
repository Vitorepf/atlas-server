<?php

declare(strict_types=1);

namespace Tests\Unit\Ai\SelfConstruction;

use App\Services\Ai\SelfConstruction\Maestro\MultiProvider\AtlasMaestroPacketClassifier;
use Tests\TestCase;

final class AtlasMaestroPacketClassifierTest extends TestCase
{
    public function test_classify_is_closed_set_and_byte_deterministic(): void
    {
        $packet = $this->packet(['app/Services/Foo.php'], 'small implementation task');
        $classifier = $this->classifier();

        $first = $classifier->classify($packet);
        $second = $classifier->classify($packet);

        $this->assertSame($first, $second);
        $this->assertContains($first, [
            AtlasMaestroPacketClassifier::ARCHITECTURE,
            AtlasMaestroPacketClassifier::MULTI_FILE,
            AtlasMaestroPacketClassifier::GRIND,
            AtlasMaestroPacketClassifier::DOC,
        ]);
    }

    public function test_doc_paths_only_rule(): void
    {
        $packet = $this->packet(['docs/loop-routing.md', 'docs/maestro-provider-policy.md'], 'document routing');

        $this->assertSame(AtlasMaestroPacketClassifier::DOC, $this->classifier()->classify($packet));
        $this->assertSame(AtlasMaestroPacketClassifier::REASON_DOC, $this->classifier()->reasonFor($packet));
    }

    public function test_multi_file_rule(): void
    {
        $packet = $this->packet([
            'app/Services/Ai/Foo.php',
            'app/Console/Commands/FooCommand.php',
            'config/atlas.php',
        ], 'wire a broad change');

        $this->assertSame(AtlasMaestroPacketClassifier::MULTI_FILE, $this->classifier()->classify($packet));
        $this->assertSame(AtlasMaestroPacketClassifier::REASON_MULTI_FILE, $this->classifier()->reasonFor($packet));
    }

    public function test_architecture_keyword_with_small_surface_rule(): void
    {
        $packet = $this->packet([
            'app/Services/Ai/Foo.php',
            'tests/Unit/FooTest.php',
        ], 'Design the routing contract for worker selection');

        $this->assertSame(AtlasMaestroPacketClassifier::ARCHITECTURE, $this->classifier()->classify($packet));
        $this->assertSame(AtlasMaestroPacketClassifier::REASON_ARCHITECTURE, $this->classifier()->reasonFor($packet));
    }

    public function test_grind_default_rule(): void
    {
        $packet = $this->packet(['app/Services/Ai/Foo.php'], 'implement focused green test');

        $this->assertSame(AtlasMaestroPacketClassifier::GRIND, $this->classifier()->classify($packet));
        $this->assertSame(AtlasMaestroPacketClassifier::REASON_GRIND, $this->classifier()->reasonFor($packet));
    }

    /**
     * @param  list<string>  $allowedFiles
     * @return array<string,mixed>
     */
    private function packet(array $allowedFiles, string $objective): array
    {
        return [
            'objective' => $objective,
            'allowed_files' => $allowedFiles,
            'scope_in' => $allowedFiles,
            'acceptance_criteria' => ['focused proof'],
        ];
    }

    private function classifier(): AtlasMaestroPacketClassifier
    {
        return new AtlasMaestroPacketClassifier;
    }
}
