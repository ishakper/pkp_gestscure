<?php

namespace App\Console\Commands;

use App\Models\Admin;
use App\Services\TwoFactor\TwoFactorService;
use Illuminate\Console\Command;

/**
 * Emergency access: removes an admin's 2FA (lost phone and lost recovery codes).
 * The admin is asked to set 2FA up again at the next login if their role requires it.
 */
class ResetAdminTwoFactorCommand extends Command
{
    protected $signature = 'securegate:two-factor:reset {email : Email admin} {--force : Lewati konfirmasi}';

    protected $description = 'Reset 2FA seorang admin (HP hilang dan recovery code tidak tersedia)';

    public function handle(TwoFactorService $twoFactor): int
    {
        $admin = Admin::where('email', $this->argument('email'))->first();
        if (! $admin) {
            $this->error('Admin tidak ditemukan.');

            return self::FAILURE;
        }
        if (! $twoFactor->recordFor($admin)) {
            $this->info("{$admin->email} belum memakai 2FA. Tidak ada yang direset.");

            return self::SUCCESS;
        }
        if (! $this->option('force') && ! $this->confirm("Reset 2FA untuk {$admin->email}?")) {
            return self::FAILURE;
        }

        $twoFactor->disable($admin, 'direset lewat artisan oleh operator server');
        $this->info("2FA {$admin->email} direset. Setup ulang akan diminta saat login berikutnya bila perannya mewajibkan 2FA.");

        return self::SUCCESS;
    }
}
