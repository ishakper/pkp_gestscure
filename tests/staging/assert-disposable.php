<?php
require '/var/www/html/vendor/autoload.php';
$app = require '/var/www/html/bootstrap/app.php';
$app->make(Illuminate\Contracts\Console\Kernel::class)->bootstrap();

$expected = [
    'driver' => 'pgsql',
    'host' => 'verification-db',
    'database' => 'securegate_verification',
];
$actual = [
    'driver' => (string) config('database.default'),
    'host' => (string) config('database.connections.pgsql.host'),
    'database' => (string) config('database.connections.pgsql.database'),
];
if (getenv('DISPOSABLE_VERIFICATION') !== 'true' || (string) env('APP_ENV') !== 'testing' || $actual !== $expected) {
    throw new RuntimeException('Refusing destructive test command: verification identity not proven.');
}
if (preg_match('/production|parity|snapshot|staging(?!-db)/i', implode('|', $actual))) {
    throw new RuntimeException('Refusing destructive test command: forbidden database identity.');
}
echo "Disposable verification database guard: PASS\n";
