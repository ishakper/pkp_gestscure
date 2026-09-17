<?php

namespace App\Services;

use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Log;

class SecuregateMetricsService
{
    private const CACHE_PREFIX = 'securegate:metrics:';

    /**
     * Record an access event and its resolution, mapping result, decision, and ingestion status.
     * All operations fail-open: any exception is caught and logged safely without rethrowing.
     *
     * @param array $context [
     *   'event_class' => 'mapped_identity'|'unmapped_identity'|'system_alarm'|'unknown',
     *   'resolution' => 'person_no'|'card_no'|'both'|'none',
     *   'mapping_result' => 'mapped'|'unmapped'|'ambiguous',
     *   'decision' => 'granted'|'denied'|'other',
     *   'ingestion_status' => 'processed'|'ignored'|'failed',
     *   'unknown_identity' => bool,
     *   'unmapped_person' => bool,
     *   'unmapped_card' => bool,
     * ]
     */
    public function recordEvent(array $context): void
    {
        try {
            // 1. securegate_event_ingestion_total
            $ingestionStatus = $context['ingestion_status'] ?? 'processed';
            if (!in_array($ingestionStatus, ['processed', 'ignored', 'failed'], true)) {
                $ingestionStatus = 'failed';
            }
            $this->incrementCounter('securegate_event_ingestion_total', ['status' => $ingestionStatus]);

            // If ignored or failed without reaching event classification, return early
            if ($ingestionStatus !== 'processed') {
                return;
            }

            // 2. securegate_access_events_total
            $eventClass = $context['event_class'] ?? 'unknown';
            if (!in_array($eventClass, ['mapped_identity', 'unmapped_identity', 'system_alarm', 'unknown'], true)) {
                $eventClass = 'unknown';
            }
            $this->incrementCounter('securegate_access_events_total', ['event_class' => $eventClass]);

            // 3. securegate_identity_resolution_total
            $resolution = $context['resolution'] ?? 'none';
            if (!in_array($resolution, ['person_no', 'card_no', 'both', 'none'], true)) {
                $resolution = 'none';
            }
            $this->incrementCounter('securegate_identity_resolution_total', ['resolution' => $resolution]);

            // 4. securegate_mapping_result_total
            $mappingResult = $context['mapping_result'] ?? 'unmapped';
            if (!in_array($mappingResult, ['mapped', 'unmapped', 'ambiguous'], true)) {
                $mappingResult = 'unmapped';
            }
            $this->incrementCounter('securegate_mapping_result_total', ['result' => $mappingResult]);

            // 5. securegate_access_decisions_total
            $decision = $context['decision'] ?? 'other';
            if (!in_array($decision, ['granted', 'denied', 'other'], true)) {
                $decision = 'other';
            }
            $this->incrementCounter('securegate_access_decisions_total', ['decision' => $decision]);

            // 6. securegate_unknown_identity_events_total
            if (!empty($context['unknown_identity'])) {
                $this->incrementCounter('securegate_unknown_identity_events_total', []);
            }

            // 7. Additional triage counters (aggregate, no PII)
            if (!empty($context['unmapped_person'])) {
                $this->incrementCounter('securegate_unmapped_person_events_total', []);
            }
            if (!empty($context['unmapped_card'])) {
                $this->incrementCounter('securegate_unmapped_card_events_total', []);
            }
            if ($eventClass === 'system_alarm') {
                $this->incrementCounter('securegate_system_alarm_events_total', []);
            }

        } catch (\Throwable $e) {
            // Fail-open: Observability errors must NEVER disrupt ingestion or door operations
            Log::warning('[SecuregateMetricsService] Failed to record metric', [
                'error' => $e->getMessage(),
            ]);
        }
    }

    /**
     * Increment a metric counter in cache store.
     */
    public function incrementCounter(string $metricName, array $labels = [], int $step = 1): void
    {
        try {
            $key = $this->buildMetricKey($metricName, $labels);
            if (Cache::has($key)) {
                Cache::increment($key, $step);
            } else {
                Cache::forever($key, $step);
            }

            // Register key in metric index for fast scraping/rendering
            $indexKey = self::CACHE_PREFIX . 'index';
            $index = Cache::get($indexKey, []);
            if (!in_array($key, $index, true)) {
                $index[] = $key;
                Cache::forever($indexKey, $index);
            }
        } catch (\Throwable $e) {
            Log::warning('[SecuregateMetricsService] Failed to increment counter', [
                'metric' => $metricName,
                'error' => $e->getMessage(),
            ]);
        }
    }

    /**
     * Get a snapshot of all metrics as a structured array.
     */
    public function getMetricsSnapshot(): array
    {
        try {
            $indexKey = self::CACHE_PREFIX . 'index';
            $index = Cache::get($indexKey, []);
            $metrics = [];

            foreach ($index as $key) {
                $val = (int) Cache::get($key, 0);
                // Parse key: securegate:metrics:<name>:<label_hash>
                $parsed = $this->parseMetricKey($key);
                if ($parsed) {
                    $metrics[] = [
                        'name' => $parsed['name'],
                        'labels' => $parsed['labels'],
                        'value' => $val,
                    ];
                }
            }

            // Calculate mapping coverage ratio
            $coverage = $this->calculateMappingCoverageRatio();

            return [
                'metrics' => $metrics,
                'mapping_coverage_ratio' => $coverage,
            ];
        } catch (\Throwable $e) {
            Log::warning('[SecuregateMetricsService] Failed to get metrics snapshot', [
                'error' => $e->getMessage(),
            ]);
            return [
                'metrics' => [],
                'mapping_coverage_ratio' => null,
            ];
        }
    }

    /**
     * Calculate mapping coverage ratio:
     * mapped identity events / all identity-bearing events
     *
     * Returns null if denominator is 0 (never divides by zero, never defaults to 0% when no events).
     */
    public function calculateMappingCoverageRatio(): ?float
    {
        try {
            $mappedKey = $this->buildMetricKey('securegate_access_events_total', ['event_class' => 'mapped_identity']);
            $unmappedKey = $this->buildMetricKey('securegate_access_events_total', ['event_class' => 'unmapped_identity']);

            $mappedCount = (int) Cache::get($mappedKey, 0);
            $unmappedCount = (int) Cache::get($unmappedKey, 0);

            $denominator = $mappedCount + $unmappedCount;
            if ($denominator <= 0) {
                return null;
            }

            return round($mappedCount / $denominator, 4);
        } catch (\Throwable $e) {
            return null;
        }
    }

    /**
     * Render metrics in Prometheus text exposition format.
     */
    public function renderPrometheus(): string
    {
        try {
            $snapshot = $this->getMetricsSnapshot();
            $grouped = [];

            foreach ($snapshot['metrics'] as $item) {
                $grouped[$item['name']][] = $item;
            }

            $output = [];
            $output[] = '# HELP securegate_access_events_total Total access events by identity classification';
            $output[] = '# TYPE securegate_access_events_total counter';
            if (isset($grouped['securegate_access_events_total'])) {
                foreach ($grouped['securegate_access_events_total'] as $m) {
                    $lbls = $this->formatLabels($m['labels']);
                    $output[] = "securegate_access_events_total{$lbls} {$m['value']}";
                }
            }

            $output[] = '# HELP securegate_identity_resolution_total Total events by identity resolution source';
            $output[] = '# TYPE securegate_identity_resolution_total counter';
            if (isset($grouped['securegate_identity_resolution_total'])) {
                foreach ($grouped['securegate_identity_resolution_total'] as $m) {
                    $lbls = $this->formatLabels($m['labels']);
                    $output[] = "securegate_identity_resolution_total{$lbls} {$m['value']}";
                }
            }

            $output[] = '# HELP securegate_mapping_result_total Total events by identity mapping result';
            $output[] = '# TYPE securegate_mapping_result_total counter';
            if (isset($grouped['securegate_mapping_result_total'])) {
                foreach ($grouped['securegate_mapping_result_total'] as $m) {
                    $lbls = $this->formatLabels($m['labels']);
                    $output[] = "securegate_mapping_result_total{$lbls} {$m['value']}";
                }
            }

            $output[] = '# HELP securegate_access_decisions_total Total access decisions';
            $output[] = '# TYPE securegate_access_decisions_total counter';
            if (isset($grouped['securegate_access_decisions_total'])) {
                foreach ($grouped['securegate_access_decisions_total'] as $m) {
                    $lbls = $this->formatLabels($m['labels']);
                    $output[] = "securegate_access_decisions_total{$lbls} {$m['value']}";
                }
            }

            $output[] = '# HELP securegate_event_ingestion_total Total events ingested by status';
            $output[] = '# TYPE securegate_event_ingestion_total counter';
            if (isset($grouped['securegate_event_ingestion_total'])) {
                foreach ($grouped['securegate_event_ingestion_total'] as $m) {
                    $lbls = $this->formatLabels($m['labels']);
                    $output[] = "securegate_event_ingestion_total{$lbls} {$m['value']}";
                }
            }

            $output[] = '# HELP securegate_unknown_identity_events_total Total unknown identity events';
            $output[] = '# TYPE securegate_unknown_identity_events_total counter';
            $unknownVal = $this->getCounterValue('securegate_unknown_identity_events_total');
            $output[] = "securegate_unknown_identity_events_total {$unknownVal}";

            $output[] = '# HELP securegate_mapping_coverage_ratio Ratio of mapped identity events over all identity-bearing events';
            $output[] = '# TYPE securegate_mapping_coverage_ratio gauge';
            $ratio = $snapshot['mapping_coverage_ratio'];
            if ($ratio !== null) {
                $output[] = "securegate_mapping_coverage_ratio {$ratio}";
            } else {
                $output[] = "securegate_mapping_coverage_ratio NaN";
            }

            // Additional triage counters
            $output[] = '# HELP securegate_unmapped_person_events_total Total unmapped person events';
            $output[] = '# TYPE securegate_unmapped_person_events_total counter';
            $output[] = "securegate_unmapped_person_events_total " . $this->getCounterValue('securegate_unmapped_person_events_total');

            $output[] = '# HELP securegate_unmapped_card_events_total Total unmapped card events';
            $output[] = '# TYPE securegate_unmapped_card_events_total counter';
            $output[] = "securegate_unmapped_card_events_total " . $this->getCounterValue('securegate_unmapped_card_events_total');

            $output[] = '# HELP securegate_system_alarm_events_total Total system alarm events';
            $output[] = '# TYPE securegate_system_alarm_events_total counter';
            $output[] = "securegate_system_alarm_events_total " . $this->getCounterValue('securegate_system_alarm_events_total');

            return implode("\n", $output) . "\n";
        } catch (\Throwable $e) {
            return "# ERROR: " . $e->getMessage() . "\n";
        }
    }

    /**
     * Reset all metrics (useful for testing).
     */
    public function resetMetrics(): void
    {
        try {
            $indexKey = self::CACHE_PREFIX . 'index';
            $index = Cache::get($indexKey, []);
            foreach ($index as $key) {
                Cache::forget($key);
            }
            Cache::forget($indexKey);
        } catch (\Throwable $e) {
            // ignore
        }
    }

    private function getCounterValue(string $metricName, array $labels = []): int
    {
        $key = $this->buildMetricKey($metricName, $labels);
        return (int) Cache::get($key, 0);
    }

    private function buildMetricKey(string $name, array $labels): string
    {
        ksort($labels);
        $labelStr = http_build_query($labels);
        $metaKey = self::CACHE_PREFIX . 'meta:' . md5($labelStr);
        Cache::forever($metaKey, json_encode(['name' => $name, 'labels' => $labels]));
        return self::CACHE_PREFIX . $name . ':' . md5($labelStr);
    }

    private function parseMetricKey(string $key): ?array
    {
        $parts = explode(':', $key);
        if (count($parts) < 4) {
            return null;
        }
        $name = $parts[2];
        $hash = $parts[3];
        $metaKey = self::CACHE_PREFIX . 'meta:' . $hash;
        $metaRaw = Cache::get($metaKey);
        if ($metaRaw) {
            $meta = json_decode($metaRaw, true);
            return [
                'name' => $meta['name'] ?? $name,
                'labels' => $meta['labels'] ?? [],
            ];
        }
        return [
            'name' => $name,
            'labels' => [],
        ];
    }

    private function formatLabels(array $labels): string
    {
        if (empty($labels)) {
            return '';
        }
        $parts = [];
        foreach ($labels as $k => $v) {
            $safeVal = addslashes((string) $v);
            $parts[] = "{$k}=\"{$safeVal}\"";
        }
        return '{' . implode(',', $parts) . '}';
    }
}
