<?php
require __DIR__.'/../../../vendor/autoload.php';
$app = require __DIR__.'/../../../bootstrap/app.php';
$app->make(Illuminate\Contracts\Console\Kernel::class)->bootstrap();

use App\Models\Admin;
use App\Services\TwoFactor\TwoFactorService;
use Illuminate\Support\Facades\DB;

function waitFor(callable $condition, int $seconds = 30): void {
    $deadline = microtime(true) + $seconds;
    while (!$condition()) {
        if (microtime(true) > $deadline) throw new RuntimeException('harness_timeout');
        usleep(10000);
    }
}

function removeBarrier(string $barrier): void {
    foreach (glob($barrier.'/*') ?: [] as $file) @unlink($file);
    @rmdir($barrier);
}

function startWorker(int $adminId, string $mode, string $value, string $barrier): array {
    $command = escapeshellarg(PHP_BINARY).' '.escapeshellarg(__DIR__.'/worker.php').' '.escapeshellarg((string) $adminId).' '.escapeshellarg($mode).' '.escapeshellarg($value).' '.escapeshellarg($barrier);
    $pipes = [];
    $process = proc_open($command, [1 => ['pipe', 'w'], 2 => ['pipe', 'w']], $pipes);
    if (!is_resource($process)) throw new RuntimeException('worker_start_failed');
    return [$process, $pipes];
}

function closeWorker($process, array $pipes): int {
    foreach ($pipes as $pipe) if (is_resource($pipe)) fclose($pipe);
    return proc_close($process);
}

function runPair(int $adminId, string $mode, string $value): array {
    $barrier = sys_get_temp_dir().'/sg-2fa-'.bin2hex(random_bytes(8));
    mkdir($barrier);
    $workers = [];
    try {
        foreach ([1, 2] as $id) $workers[$id] = startWorker($adminId, $mode, $value, $barrier);
        file_put_contents($barrier.'/go', 'go');
        waitFor(fn() => count(glob($barrier.'/result-*.json') ?: []) === 2);
        $results = array_map(fn($file) => json_decode(file_get_contents($file), true), glob($barrier.'/result-*.json'));
        foreach ($workers as $worker) {
            $exitCode = closeWorker($worker[0], $worker[1]);
            if ($exitCode !== 0) throw new RuntimeException('worker_exit_code_'.$exitCode);
        }
        return $results;
    } finally {
        foreach ($workers as $worker) if (is_resource($worker[0])) @proc_terminate($worker[0]);
        removeBarrier($barrier);
    }
}

function runControlledLock(int $adminId, int $step, string $otp): array {
    $barrier = sys_get_temp_dir().'/sg-2fa-lock-'.bin2hex(random_bytes(8));
    mkdir($barrier);
    $holder = $contender = null;
    $holderPipes = $contenderPipes = [];
    try {
        [$holder, $holderPipes] = startWorker($adminId, 'hold', (string) $step, $barrier);
        waitFor(fn() => is_file($barrier.'/holder-ready.json'));
        $holderInfo = json_decode(file_get_contents($barrier.'/holder-ready.json'), true);
        [$contender, $contenderPipes] = startWorker($adminId, 'totp', $otp, $barrier);
        file_put_contents($barrier.'/go', 'go');
        waitFor(fn() => count(glob($barrier.'/worker-pid-*.json') ?: []) === 1);
        $contenderInfo = json_decode(file_get_contents((glob($barrier.'/worker-pid-*.json') ?: [])[0]), true);
        $blocked = false;
        $blockerPids = [];
        waitFor(function () use (&$blocked, &$blockerPids, $contenderInfo, $holderInfo) {
            $row = DB::selectOne('select pg_blocking_pids(?) as blockers', [(int) $contenderInfo['pid']]);
            $raw = trim((string) ($row->blockers ?? ''), '{}');
            $blockerPids = $raw === '' ? [] : array_map('intval', explode(',', $raw));
            return $blocked = in_array((int) $holderInfo['pid'], $blockerPids, true);
        });
        $completedBeforeRelease = (bool) (glob($barrier.'/result-*.json') ?: []);
        file_put_contents($barrier.'/release', 'release');
        waitFor(fn() => is_file($barrier.'/holder-commit.json'));
        waitFor(fn() => count(glob($barrier.'/result-*.json') ?: []) === 1);
        $result = json_decode(file_get_contents((glob($barrier.'/result-*.json') ?: [])[0]), true);
        $holderExit = closeWorker($holder, $holderPipes); $holder = null;
        $contenderExit = closeWorker($contender, $contenderPipes); $contender = null;
        if ($holderExit !== 0 || $contenderExit !== 0) throw new RuntimeException('controlled_worker_exit_codes');
        return compact('blocked', 'blockerPids', 'completedBeforeRelease', 'result', 'holderInfo', 'contenderInfo');
    } finally {
        if (is_resource($holder)) { @file_put_contents($barrier.'/release', 'release'); @proc_terminate($holder); }
        if (is_resource($contender)) @proc_terminate($contender);
        removeBarrier($barrier);
    }
}

$service = app(TwoFactorService::class);
$admin = Admin::create(['name' => '2FA Harness Admin', 'email' => '2fa-harness-'.bin2hex(random_bytes(4)).'@example.test', 'password' => bcrypt('password'), 'role' => 'super_admin']);
try {
    $secret = $service->generateSecret();
    $codes = $service->enable($admin, $secret);
    App\Models\AdminTwoFactor::where('admin_id', $admin->id)->update(['last_used_step' => null]);
    $otp = app(PragmaRX\Google2FA\Google2FA::class)->getCurrentOtp($secret);
    $results = runPair($admin->id, 'totp', $otp);
    if (count(array_filter($results, fn($r) => ($r['result'] ?? null) === 'ok')) !== 1) throw new RuntimeException('TOTP concurrency failed');
    $record = App\Models\AdminTwoFactor::where('admin_id', $admin->id)->firstOrFail();
    if (!$record->last_used_step) throw new RuntimeException('TOTP step not persisted');
    $results = runPair($admin->id, 'recovery', $codes[0]);
    if (count(array_filter($results, fn($r) => ($r['result'] ?? null) === 'ok')) !== 1) throw new RuntimeException('Recovery concurrency failed');
    $record->refresh();
    if (count((array) $record->recovery_codes) !== count($codes) - 1) throw new RuntimeException('Recovery code was not consumed');
    $record->forceFill(['failed_attempts' => 0, 'locked_until' => null, 'last_used_step' => null])->save();
    $results = runPair($admin->id, 'totp', '111111');
    if (count(array_filter($results, fn($r) => ($r['result'] ?? null) === 'invalid')) !== 2) throw new RuntimeException('Failed-attempt concurrency failed');
    $record->refresh();
    if ((int) $record->failed_attempts !== 2) throw new RuntimeException('Failed attempts were not serialized');
    $record->forceFill(['failed_attempts' => 0, 'last_used_step' => null])->save();
    $lock = runControlledLock($admin->id, app(PragmaRX\Google2FA\Google2FA::class)->getTimestamp(), $otp);
    if (!$lock['blocked'] || $lock['completedBeforeRelease'] || ($lock['result']['result'] ?? null) === 'ok') throw new RuntimeException('Controlled lock failed');
    echo "TOTP_CONCURRENCY_PASS\nRECOVERY_CONCURRENCY_PASS\nFAILED_ATTEMPT_CONCURRENCY_PASS\nCONTROLLED_ROW_LOCK_PASS\n";
    echo "CONTROLLED_ROW_LOCK_HOLDER_PID={$lock['holderInfo']['pid']} CONTENDER_PID={$lock['contenderInfo']['pid']} BLOCKER_PIDS=".implode(',', $lock['blockerPids'])." COMPLETED_BEFORE_RELEASE=false\n";
} finally {
    DB::table('admin_two_factor')->where('admin_id', $admin->id)->delete();
    DB::table('admins')->where('id', $admin->id)->delete();
}