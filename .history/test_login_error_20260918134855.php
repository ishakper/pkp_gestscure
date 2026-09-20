<?php
require __DIR__ . '/vendor/autoload.php';
$app = require_once __DIR__ . '/bootstrap/app.php';
$kernel = $app->make(Illuminate\Contracts\Http\Kernel::class);
try {
    $request = Illuminate\Http\Request::create('/login', 'GET');
    $response = $kernel->handle($request);
    echo "STATUS: " . $response->getStatusCode() . "\n";
    if ($response->getStatusCode() >= 400) {
        echo substr($response->getContent(), 0, 1000) . "\n";
    }
} catch (\Throwable $e) {
    echo "EXCEPTION: " . get_class($e) . "\n";
    echo "MESSAGE: " . $e->getMessage() . "\n";
    echo "FILE: " . $e->getFile() . ":" . $e->getLine() . "\n";
    echo $e->getTraceAsString() . "\n";
}
