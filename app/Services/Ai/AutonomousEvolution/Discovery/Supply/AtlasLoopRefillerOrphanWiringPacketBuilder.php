<?php

declare(strict_types=1);

namespace App\Services\Ai\AutonomousEvolution\Discovery\Supply;

use App\Services\Ai\AutonomousEvolution\AtlasLoopOriginationDeliveryBridge;

/**
 * Builds the orphan-wiring RED grindable-packet for the Atlas loop queue
 * refiller.
 *
 * Extracted from AtlasLoopQueueRefiller to reduce the god-class.
 * Pure static methods — delivery bridge is passed as a parameter.
 */
final class AtlasLoopRefillerOrphanWiringPacketBuilder
{
    /**
     * @param  array<string,mixed>  $spec
     * @param  AtlasLoopOriginationDeliveryBridge|null  $deliveryBridge
     * @return array<string,mixed>
     */
    public static function withOrphanWiringRedGrindablePacket(array $spec, string $anchor, string $repoRoot, ?AtlasLoopOriginationDeliveryBridge $deliveryBridge = null): array
    {
        if (! (bool) config('atlas.loop.orphan_wiring_red_grindable_packet_enabled', false)) {
            return $spec;
        }
        $payload = is_array($spec['payload'] ?? null) ? $spec['payload'] : [];
        $method = AtlasLoopRefillerPayloadNormalizer::firstString((array) ($payload['public_methods'] ?? []));
        $fqcn = trim((string) ($payload['orphan_fqcn'] ?? ''));
        if ($anchor === '' || $method === '' || $fqcn === '') {
            return $spec;
        }
        $consumer = self::orphanWiringConsumerPath($payload, $repoRoot, $anchor, $method);
        if ($consumer === null) {
            return $spec;
        }

        $bridge = $deliveryBridge ?? new AtlasLoopOriginationDeliveryBridge;
        $out = $bridge->buildGrindablePacket([
            'primitive_path' => $anchor,
            'consumer_path' => $consumer,
            'behaviour_atom' => [
                'type' => 'method_return',
                'class' => $fqcn,
                'method' => $method,
                'expected' => null,
            ],
        ], (string) ($spec['objective'] ?? ''), $repoRoot);

        if (($out['ready'] ?? false) !== true || ! is_array($out['packet'] ?? null)) {
            return $spec;
        }

        $spec['payload'] = self::payloadWithGrindablePacket($payload, (array) $out['packet']);

        return $spec;
    }

    /**
     * @param  array<string,mixed>  $payload
     */
    public static function orphanWiringConsumerPath(array $payload, string $repoRoot, string $anchor, string $method): ?string
    {
        foreach (['consumer_path', 'caller_path', 'production_caller_path'] as $key) {
            $candidate = ltrim(trim((string) ($payload[$key] ?? '')), '/');
            if ($candidate !== '' && $candidate !== $anchor && is_file(rtrim($repoRoot, '/').'/'.$candidate)) {
                return $candidate;
            }
        }

        $repoRoot = rtrim($repoRoot, '/');
        foreach (['app', 'src'] as $root) {
            $dir = $repoRoot.'/'.$root;
            if (! is_dir($dir)) {
                continue;
            }
            $it = new \RecursiveIteratorIterator(new \RecursiveDirectoryIterator($dir, \RecursiveDirectoryIterator::SKIP_DOTS));
            foreach ($it as $file) {
                if (! $file->isFile() || $file->getExtension() !== 'php') {
                    continue;
                }
                $rel = str_replace('\\', '/', substr($file->getPathname(), strlen($repoRoot) + 1));
                if ($rel === $anchor) {
                    continue;
                }
                $src = (string) @file_get_contents($file->getPathname());
                if ($method !== '' && preg_match('/\b'.preg_quote($method, '/').'\b/', $src) === 1) {
                    return $rel;
                }
            }
        }

        $sibling = ltrim(trim((string) ($payload['sibling_test'] ?? '')), '/');

        return $sibling !== '' && is_file($repoRoot.'/'.$sibling) ? $sibling : null;
    }

    /**
     * @param  array<string,mixed>  $payload
     * @param  array<string,mixed>  $packet
     * @return array<string,mixed>
     */
    public static function payloadWithGrindablePacket(array $payload, array $packet): array
    {
        $packetPayload = is_array($packet['payload'] ?? null) ? (array) $packet['payload'] : $packet;
        $atoms = AtlasLoopRefillerPayloadNormalizer::arrayList($packetPayload['verification_atoms'] ?? $packet['verification_atoms'] ?? []);
        $commands = AtlasLoopRefillerPayloadNormalizer::stringList($packetPayload['verifier_refuter_commands'] ?? $packet['verifier_refuter_commands'] ?? []);
        $acceptance = is_array($packetPayload['acceptance'] ?? null) ? (array) $packetPayload['acceptance'] : [];

        if ($atoms !== []) {
            $payload['verification_atoms'] = array_values(array_merge(AtlasLoopRefillerPayloadNormalizer::arrayList($payload['verification_atoms'] ?? []), $atoms));
        }
        if ($commands !== []) {
            $payload['verifier_refuter_commands'] = array_values(array_merge(AtlasLoopRefillerPayloadNormalizer::stringList($payload['verifier_refuter_commands'] ?? []), $commands));
        }

        $payload['red_required'] = true;
        $payload['acceptance'] = array_merge(is_array($payload['acceptance'] ?? null) ? (array) $payload['acceptance'] : [], $acceptance, [
            'red_required' => true,
        ]);

        return $payload;
    }
}
