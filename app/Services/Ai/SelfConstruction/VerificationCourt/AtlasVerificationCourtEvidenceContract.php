<?php

declare(strict_types=1);

namespace App\Services\Ai\SelfConstruction\VerificationCourt;

/**
 * Pure contract gate that requires completion evidence to include a receipt hash
 * chain binding task, lease, allowed files, and command proof before the court
 * may consider it.
 *
 * NO network I/O, NO file I/O, NO provider calls.
 */
final class AtlasVerificationCourtEvidenceContract
{
    public const SCHEMA = 'atlas.verification_court.evidence_contract.v1';

    /**
     * @param  array{
     *   task_packet_id?:string,
     *   lease_id?:string,
     *   allowed_files_hash?:string,
     *   command_hash?:string,
     * }  $allegation
     * @param  array{
     *   receipt_chain?:?array{
     *     task_packet_id?:string,
     *     lease_id?:string,
     *     allowed_files_hash?:string,
     *     command_hash?:string,
     *   },
     * }  $evidence
     * @return array{
     *   schema:string,
     *   accepted:bool,
     *   blockers:list<string>,
     * }
     */
    public function verify(array $allegation, array $evidence): array
    {
        $blockers = [];

        $chain = $evidence['receipt_chain'] ?? null;

        if (! is_array($chain)) {
            return $this->envelope(false, ['missing:receipt_chain']);
        }

        $fields = ['task_packet_id', 'lease_id', 'allowed_files_hash', 'command_hash'];

        foreach ($fields as $field) {
            $alleged = (string) ($allegation[$field] ?? '');
            $chained = (string) ($chain[$field] ?? '');

            if ($chained === '') {
                $blockers[] = "missing_in_chain:{$field}";
            } elseif ($alleged !== '' && $alleged !== $chained) {
                $blockers[] = "mismatch:{$field}";
            }
        }

        if (count($blockers) > 0) {
            return $this->envelope(false, $blockers);
        }

        return $this->envelope(true, []);
    }

    /** @param  list<string>  $blockers */
    private function envelope(bool $accepted, array $blockers): array
    {
        sort($blockers, SORT_STRING);

        return [
            'schema' => self::SCHEMA,
            'accepted' => $accepted,
            'blockers' => $blockers,
        ];
    }
}
