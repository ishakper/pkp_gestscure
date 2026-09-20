<?php
require 'bootstrap/app.php';

$app = app();
echo "Current app environment: " . $app->environment() . "\n";
echo "Is local/testing? " . ($app->environment(['local', 'testing']) ? 'YES' : 'NO') . "\n";

$demoEmployee = \App\Models\Employee::where('employee_id', 'USR-1001')->exists();
echo "Demo employee USR-1001 exists: " . ($demoEmployee ? 'YES' : 'NO') . "\n";

$totalEmployees = \App\Models\Employee::count();
echo "Total employees: " . $totalEmployees . "\n";
