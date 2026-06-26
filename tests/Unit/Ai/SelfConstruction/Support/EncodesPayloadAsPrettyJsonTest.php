<?php

declare(strict_types=1);

namespace Tests\Unit\Ai\SelfConstruction\Support;

use App\Services\Ai\SelfConstruction\Support\EncodesPayloadAsPrettyJson;
use Tests\TestCase;

final class EncodesPayloadAsPrettyJsonTest extends TestCase
{
    public function test_encode_emits_pretty_printed_json(): void
    {
        $host = $this->makeHost();

        $json = $host->call(['a' => 1, 'b' => 2]);

        $this->assertSame(
            [
                '{',
                '    "a": 1,',
                '    "b": 2',
                '}',
            ],
            explode("\n", $json)
        );
    }

    public function test_encode_does_not_escape_slashes(): void
    {
        $host = $this->makeHost();

        $json = $host->call(['path' => 'atlas/self-construction/operator-submissions/x.json']);

        $this->assertStringContainsString('atlas/self-construction/', $json);
        $this->assertStringNotContainsString('atlas\/self-construction\/', $json);
    }

    public function test_encode_does_not_escape_unicode(): void
    {
        $host = $this->makeHost();

        $json = $host->call(['label' => 'Atlas — configuração']);

        $this->assertStringContainsString('Atlas — configuração', $json);
        $this->assertStringNotContainsString('\u00', $json);
    }

    public function test_encode_handles_nested_arrays(): void
    {
        $host = $this->makeHost();

        $json = $host->call(['outer' => ['inner' => ['deep' => 'value']]]);
        $decoded = json_decode($json, true);

        $this->assertSame(['outer' => ['inner' => ['deep' => 'value']]], $decoded);
    }

    public function test_encode_throws_on_invalid_payload(): void
    {
        $host = $this->makeHost();

        // resource payloads can't be JSON-encoded.
        $resource = fopen('php://memory', 'r');
        $this->assertNotFalse($resource);

        $this->expectException(\JsonException::class);
        $host->call(['r' => $resource]);
    }

    private function makeHost(): object
    {
        return new class
        {
            use EncodesPayloadAsPrettyJson;

            public function call(array $payload): string
            {
                return $this->doEncode($payload);
            }

            private function doEncode(array $payload): string
            {
                return $this->encode($payload);
            }
        };
    }
}
