<?php

namespace Database\Seeders;

use App\Models\RecruitmentStage;
use Illuminate\Database\Seeder;

class RecruitmentStageSeeder extends Seeder
{
    public function run(): void
    {
        $stages = [
            ['code' => 'APPLIED', 'name' => 'Berkas Masuk', 'sequence' => 1],
            ['code' => 'SCREENING', 'name' => 'Screening CV', 'sequence' => 2],
            ['code' => 'HR_INTERVIEW', 'name' => 'Interview HR', 'sequence' => 3],
            ['code' => 'TECHNICAL_TEST', 'name' => 'Tes Teknis / Assessment', 'sequence' => 4],
            ['code' => 'USER_INTERVIEW', 'name' => 'Interview User / Supervisor', 'sequence' => 5],
            ['code' => 'MANAGEMENT_REVIEW', 'name' => 'Review Manajemen', 'sequence' => 6],
            ['code' => 'OFFER', 'name' => 'Offering & Kontrak', 'sequence' => 7],
        ];

        foreach ($stages as $stage) {
            RecruitmentStage::firstOrCreate(['code' => $stage['code']], $stage);
        }
    }
}
