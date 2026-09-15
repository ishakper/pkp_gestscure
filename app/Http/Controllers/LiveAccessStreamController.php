<?php

namespace App\Http\Controllers;

use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\StreamedResponse;
use App\Models\AccessLog;
use Carbon\Carbon;
use Illuminate\Support\Facades\Log;

class LiveAccessStreamController extends Controller
{
    /**
     * Create an SSE connection that pushes new events to the client.
     */
    public function stream(Request $request)
    {
        // Enforce max execution time so the worker isn't permanently blocked indefinitely.
        // A 60-second limit is good, client will just transparently reconnect via standard SSE.
        set_time_limit(60); 

        $response = new StreamedResponse(function () use ($request) {
            $lastId = $request->header('Last-Event-ID', 0);
            if (!$lastId) {
                // If no last ID, start from highest ID
                $lastLog = AccessLog::orderBy('id', 'desc')->first();
                $lastId = $lastLog ? $lastLog->id : 0;
            }

            $startTime = time();
            $testing = app()->runningUnitTests();
            
            while (true) {
                if (connection_aborted()) {
                    break;
                }

                // Break loop after 55 seconds to allow graceful disconnect and worker recycling
                if (time() - $startTime > 55) {
                    echo "event: reload\ndata: {}\n\n";
                    if (ob_get_level() > 0) {
                        ob_flush();
                    }
                    flush();
                    break;
                }

                $newLogs = AccessLog::with(['door', 'employee'])
                    ->where('id', '>', $lastId)
                    ->orderBy('id', 'asc')
                    ->get();

                foreach ($newLogs as $log) {
                    $payload = json_encode([
                        'id' => $log->id,
                        'log_id' => $log->log_id,
                        'time' => Carbon::parse($log->timestamp)->format('H:i:s'),
                        'door_name' => $log->door ? $log->door->door_name : 'Unknown',
                        'employee_name' => $log->employee ? $log->employee->name : ($log->nik ?: 'UNKNOWN USER'),
                        'verify_method' => $log->verify_method,
                        'access_status' => $log->access_status,
                        'source' => $log->source,
                    ]);

                    echo "id: {$log->id}\n";
                    echo "data: {$payload}\n\n";
                    
                    $lastId = $log->id;
                }

                // Send heartbeat comment to keep connection alive
                echo ": heartbeat\n\n";
                
                if (ob_get_level() > 0) {
                    ob_flush();
                }
                flush();

                if ($testing) {
                    break;
                }

                sleep(2); // Poll every 2 seconds
            }
        });

        $response->headers->set('Content-Type', 'text/event-stream');
        $response->headers->set('Cache-Control', 'no-cache');
        $response->headers->set('Connection', 'keep-alive');
        $response->headers->set('X-Accel-Buffering', 'no'); // Important for Nginx

        return $response;
    }
}
