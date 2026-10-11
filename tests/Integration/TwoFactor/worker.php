<?php
require __DIR__.'/../../../vendor/autoload.php';
$app = require __DIR__.'/../../../bootstrap/app.php';
$app->make(Illuminate\Contracts\Console\Kernel::class)->bootstrap();

use Illuminate\Support\Facades\DB;

[$adminId, $mode, $value, $barrier] = array_pad(array_slice($argv, 1), 4, null);
$deadline = microtime(true) + 30;

try {
    if ($mode === 'hold') {
        $record = App\Models\AdminTwoFactor::where('admin_id', (int) $adminId)->firstOrFail();
        $connection = $record->getConnection();
        $connection->beginTransaction();
        $connection->selectOne('select id from admin_two_factor where admin_id = ? for update', [$adminId]);
        $pid = (int) $connection->selectOne('select pg_backend_pid() as pid')->pid;
        file_put_contents($barrier.'/holder-ready.json', json_encode(['pid' => $pid]));
        while (!is_file($barrier.'/release')) {
            if (microtime(true) > $deadline) throw new RuntimeException('holder_release_timeout');
            usleep(10000);
        }
        $connection->update('update admin_two_factor set last_used_step = ? where admin_id = ?', [$value, $adminId]);
        $connection->commit();
        file_put_contents($barrier.'/holder-commit.json', json_encode(['pid' => $pid]));
        exit(0);
    }

    while (!is_file($barrier.'/go')) {
        if (microtime(true) > $deadline) throw new RuntimeException('barrier_timeout');
        usleep(10000);
    }
    $pid = (int) DB::selectOne('select pg_backend_pid() as pid')->pid;
    file_put_contents($barrier.'/worker-pid-'.getmypid().'.json', json_encode(['pid' => $pid]));
    $admin = App\Models\Admin::findOrFail((int) $adminId);
    $result = app(App\Services\TwoFactor\TwoFactorService::class)->attempt(
        $admin,
        $mode === 'totp' ? $value : null,
        $mode === 'recovery' ? $value : null
    );
    file_put_contents($barrier.'/result-'.getmypid().'.json', json_encode(['pid' => getmypid(), 'result' => $result]));
    exit(0);
} catch (Throwable $e) {
    file_put_contents($barrier.'/result-'.getmypid().'.json', json_encode(['pid' => getmypid(), 'error' => get_class($e), 'message' => $e->getMessage()]));
    exit(1);
}