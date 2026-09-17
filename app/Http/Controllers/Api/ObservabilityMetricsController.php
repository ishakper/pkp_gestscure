<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Services\SecuregateMetricsService;
use Illuminate\Http\Request;
use Illuminate\Http\Response;

class ObservabilityMetricsController extends Controller
{
    public function __construct(
        protected SecuregateMetricsService $metricsService
    ) {}

    /**
     * Expose metrics in Prometheus text exposition format.
     */
    public function prometheus(Request $request): Response
    {
        $content = $this->metricsService->renderPrometheus();

        return response($content, 200, [
            'Content-Type' => 'text/plain; version=0.0.4; charset=utf-8',
            'Cache-Control' => 'no-cache, no-store, must-revalidate',
        ]);
    }

    /**
     * Expose metrics snapshot as JSON for health / monitoring dashboards.
     */
    public function json(Request $request)
    {
        $snapshot = $this->metricsService->getMetricsSnapshot();

        return response()->json([
            'status' => 'success',
            'data' => $snapshot,
        ]);
    }
}
