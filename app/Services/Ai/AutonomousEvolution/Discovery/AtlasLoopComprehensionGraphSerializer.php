<?php

declare(strict_types=1);

namespace App\Services\Ai\AutonomousEvolution\Discovery;


use App\Services\Ai\SelfConstruction\Support\RecursivelyCanonicalizesArrays;
final class AtlasLoopComprehensionGraphSerializer
{
    use RecursivelyCanonicalizesArrays;

    /**
     * @param  array<string,mixed>  $snapshot
     */
    public function encode(array $snapshot): string
    {
        return (string) json_encode(
            $this->canonicalize($snapshot),
            JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE
        )."\n";
    }

    /**
     * @return array<string,mixed>
     */
    public function decode(string $json): array
    {
        $decoded = json_decode($json, true);

        return is_array($decoded) ? $decoded : [];
    }

    /**
     * @param  array<string,mixed>  $snapshot
     */
    public function writeSnapshot(array $snapshot, string $dir): string
    {
        $encoded = $this->encode($snapshot);
        $hash = hash('sha256', $encoded);
        $path = rtrim($dir, '/').'/snapshot-'.gmdate('YmdHis').'-'.substr($hash, 0, 12).'.json';
        if (! is_dir($dir)) {
            mkdir($dir, 0o755, true);
        }
        file_put_contents($path, $encoded);

        return $path;
    }

    /**
     * @return array<string,mixed>
     */
    public function readSnapshot(string $path): array
    {
        return $this->decode((string) file_get_contents($path));
    }

    /**
     * @param  array<int|string,mixed>  $value
     * @return array<int|string,mixed>
     */
}
