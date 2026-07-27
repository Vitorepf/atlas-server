<?php
// Prova anti-vácuo: segredos que o redator já pegava agora também são vistos
// pelo gate de export para provider.
require '/Users/vitorepf/develop/Atlas/atlas-server/tests/bootstrap.php';
$app = require '/Users/vitorepf/develop/Atlas/atlas-server/bootstrap/app.php';
$app->make(Illuminate\Contracts\Console\Kernel::class)->bootstrap();

$gate = app(App\Services\Ai\Context\AtlasRetrievalPrivacyTrustLayerService::class);
$m = new ReflectionMethod($gate, 'detectSensitiveClasses');
$m->setAccessible(true);

$cases = [
    'github_pat'     => 'x github_pat_'.str_repeat('a', 25).' y',
    'github_token'   => 'x ghp_'.str_repeat('a', 30).' y',
    'aws_access_key' => 'x AKIA'.str_repeat('A', 16).' y',
    'jwt'            => 'x eyJhbGciOiJIUzI1NiJ9.eyJzdWIiOiIxMjM0NTY3ODkwIn0.dozjgNryP4J3jVmNHl0w5N_XgL0n3I9PlFUP0THsR8U y',
    'slack_token'    => 'x xoxb-'.str_repeat('1', 24).' y',
    'openai_key'     => 'x sk-'.str_repeat('b', 24).' y',
    'private_key'    => "-----BEGIN RSA PRIVATE KEY-----\nabc\n-----END RSA PRIVATE KEY-----",
];

$missed = [];
foreach ($cases as $kind => $text) {
    $d = $m->invoke($gate, $text);
    $seen = array_key_exists('secret:'.$kind, $d);
    printf("%-16s detectado=%s  %s\n", $kind, $seen ? 'sim' : 'NAO', json_encode(array_keys($d)));
    if (! $seen) { $missed[] = $kind; }
}

// Não-vacuidade: texto limpo não pode disparar nada.
$clean = $m->invoke($gate, 'a normal sentence about refactoring the gate');
$cleanSecrets = array_filter(array_keys($clean), static fn (string $k): bool => str_starts_with($k, 'secret:'));

if ($missed !== []) { fwrite(STDERR, 'FAIL: ainda escapam '.implode(', ', $missed)."\n"); exit(1); }
if ($cleanSecrets !== []) { fwrite(STDERR, "FAIL: texto limpo disparou ".json_encode($cleanSecrets)."\n"); exit(1); }
echo "PASS: 6 tipos detectados; texto limpo não dispara\n";
