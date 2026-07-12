<?php

declare(strict_types=1);

namespace App\Services\Ai\Compounding;

use App\Services\Ai\Kernel\Evidence\AtlasEvidenceLedger;

/**
 * Read-only verifier for the candidate's content-addressed evidence bindings.
 * The caller supplies artifacts read from the canonical ledger/read model; this
 * class never creates or mutates evidence.
 */
final class CausalLearningEvidenceBindingVerifier
{
    public function __construct(private readonly ?AtlasEvidenceLedger $ledger = null) {}

    /** @return array{admitted:bool,missing:list<string>,mismatched:list<string>,invalid:list<string>} */
    public function verify(CausalLearningCandidate $candidate, array $artifacts = []): array
    {
        if ($artifacts === []) {
            $artifacts = $this->artifactsFromLedger($candidate);
        }
        $byId = [];
        foreach ($artifacts as $artifact) {
            if (! is_array($artifact) || ! is_string($artifact['artifact_id'] ?? null)) {
                continue;
            }
            $byId[$artifact['artifact_id']] = $artifact;
        }

        $missing = [];
        $mismatched = [];
        $invalid = [];
        foreach ($candidate->data['binding_refs'] as $binding => $ref) {
            $artifactId = (string) $ref['artifact_id'];
            $artifact = $byId[$artifactId] ?? null;
            if ($artifact === null) {
                $missing[] = $binding;
                continue;
            }
            if (($artifact['integrity_valid'] ?? false) !== true) {
                $invalid[] = $binding;
                continue;
            }
            if (! is_string($artifact['hash'] ?? null) || ! hash_equals($ref['hash'], $artifact['hash'])) {
                $mismatched[] = $binding;
            }
        }

        return [
            'admitted' => $missing === [] && $mismatched === [] && $invalid === [],
            'missing' => $missing,
            'mismatched' => $mismatched,
            'invalid' => $invalid,
        ];
    }

    /** @return list<array{artifact_id:string,hash:string,integrity_valid:bool}> */
    private function artifactsFromLedger(CausalLearningCandidate $candidate): array
    {
        $ledger = $this->ledger;
        if ($ledger === null && function_exists('app')) {
            try {
                $ledger = app(AtlasEvidenceLedger::class);
            } catch (\Throwable) {
                $ledger = null;
            }
        }
        if (! $ledger instanceof AtlasEvidenceLedger) {
            return [];
        }

        $artifacts = [];
        foreach ($candidate->data['binding_refs'] as $ref) {
            $artifactId = (string) $ref['artifact_id'];
            $event = $ledger->eventById($artifactId);
            if ($event === null) {
                continue;
            }
            $hashes = array_values(array_filter([
                (string) ($event->payload_hash ?? ''),
                (string) ($event->event_hash ?? ''),
                ...$this->payloadHashes($event->payload),
            ], static fn (string $hash): bool => $hash !== ''));
            $artifacts[] = [
                'artifact_id' => $artifactId,
                'hash' => in_array((string) $ref['hash'], $hashes, true) ? (string) $ref['hash'] : ($hashes[0] ?? ''),
                'integrity_valid' => $ledger->eventIntegrityValid($event),
            ];
        }

        return $artifacts;
    }

    /** @return list<string> */
    private function payloadHashes(mixed $payload): array
    {
        if (! is_array($payload)) {
            return [];
        }
        $hashes = [];
        foreach ($payload as $value) {
            if (is_string($value) && preg_match('/^[a-f0-9]{64}$/', $value) === 1) {
                $hashes[] = $value;
            } elseif (is_array($value)) {
                $hashes = array_merge($hashes, $this->payloadHashes($value));
            }
        }

        return $hashes;
    }
}
