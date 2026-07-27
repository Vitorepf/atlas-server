<?php
require '/Users/vitorepf/develop/Atlas/atlas-server/vendor/autoload.php';
$app = require '/Users/vitorepf/develop/Atlas/atlas-server/bootstrap/app.php';
$app->make(Illuminate\Contracts\Console\Kernel::class)->bootstrap();

use App\Services\Ai\Kernel\Evidence\AtlasEvidenceLedger;
use App\Services\Ai\SoftwareCompanyStewardship\AreaFocusLoop\AtomicBacklog\EvidenceLedgerHashChainIntegrityVerifier;

// Uma linha v2 INTACTA, montada exatamente como o escritor faz.
$payload = ['a' => 1, 'b' => 'x'];
$row = [
    'event_id' => 'evt-1', 'event_type' => 'test.event', 'envelope_id' => 'env-1',
    'correlation_id' => 'corr-1', 'causation_id' => null,
    'scope_type' => 'obra', 'scope_id' => 'obra-1',
    'prev_event_hash' => null, 'occurred_at' => '2026-07-27T00:00:00Z',
    'payload' => $payload,
    'payload_hash' => hash('sha256', AtlasEvidenceLedger::canonicalJson($payload)),
    'schema_version' => 2,
    'chain_basis' => AtlasEvidenceLedger::CHAIN_BASIS_FULL_ENVELOPE_V2,
    'chain_key_hash' => str_repeat('a', 64), 'chain_position' => 1,
];
$row['event_hash'] = AtlasEvidenceLedger::computeV2EnvelopeHash(AtlasEvidenceLedger::fullEnvelopeHashBasis($row));

$v = new EvidenceLedgerHashChainIntegrityVerifier;
$m = new ReflectionMethod($v, 'verifyStoredChain');
$r = $m->invoke($v, [$row], 'chain-1');
printf("A. linha v2 INTACTA   verificadas=%s tampered=%s status=%s\n",
    $r['verified_count'] ?? '?', json_encode($r['tampered_event_ids'] ?? []), $r['status'] ?? '?');

// Mesma linha, payload ADULTERADO depois do hash
$t = $row; $t['payload'] = ['a' => 999, 'b' => 'x'];
$r2 = $m->invoke($v, [$t], 'chain-1');
printf("B. linha v2 ADULTERADA verificadas=%s tampered=%s status=%s\n",
    $r2['verified_count'] ?? '?', json_encode($r2['tampered_event_ids'] ?? []), $r2['status'] ?? '?');
