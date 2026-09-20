<?php

namespace App\Services;

use App\Events\AccessLogCreated;
use App\Models\AccessLog;
use App\Models\Door;
use App\Models\Employee;
use App\Services\SecuregateMetricsService;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Str;

class HikvisionEventIngestionService
{
    /**
     * Ingest a sanitized Hikvision event from either alertStream or webhook.
     *
     * @param array $eventData Normalized event data
     * @param Door|null $door Door instance if already resolved
     * @param string $source HIKVISION_ALERTSTREAM or HIKVISION_WEBHOOK
     * @param array $options Configuration options (mode, max_event_age_seconds, max_future_skew_seconds, trusted_time)
     */
    public function ingest(
        array $eventData,
        ?Door $door = null,
        string $source = 'HIKVISION_ALERTSTREAM',
        array $options = []
    ): array {
        // 1. Resolve Door Device
        if (!$door) {
            $doorId = $eventData['door_id'] ?? null;
            if ($doorId) {
                $door = Door::where('door_id', $doorId)->first();
            }
        }

        if (!$door && !empty($eventData['device_ip'])) {
            $door = Door::where('device_ip', $eventData['device_ip'])->first();
        }

        if (!$door) {
            return [
                'status' => 'error',
                'code' => 404,
                'message' => 'Perangkat terminal pintu tidak terdaftar (Unregistered Device). Hubungi administrator sistem.',
            ];
        }

        // 2. Normalize Event Data
        $major = isset($eventData['major_event']) ? (int) $eventData['major_event'] : null;
        $sub = isset($eventData['minor_event']) ? (int) $eventData['minor_event'] : null;

        $eventType = strtoupper($eventData['event_type'] ?? 'STANDARD_TAP');
        if ($major === 5) {
            if (in_array($sub, [37, 38], true)) {
                $eventType = 'TAMPER_ALARM';
            } elseif (in_array($sub, [21, 22], true)) {
                $eventType = 'DOOR_FORCED_OPEN';
            } elseif (in_array($sub, [26, 27], true)) {
                $eventType = 'DURESS_FINGERPRINT';
            }
        }

        $deviceIp = $eventData['device_ip'] ?? $door->device_ip;
        $cardNo = $eventData['card_number'] ?? ($eventData['card_no'] ?? ($eventData['card_reference'] ?? ($eventData['card'] ?? null)));
        $rawUser = $eventData['user'] ?? ($eventData['nik'] ?? ($eventData['employee_id'] ?? ($eventData['employee_no'] ?? null)));
        $eventTimestamp = $eventData['timestamp'] ?? ($eventData['event_time'] ?? now()->toIso8601String());
        $serialNo = $eventData['serial_no'] ?? ($eventData['device_serial'] ?? null);
        $direction = strtoupper((string) ($eventData['direction'] ?? 'UNKNOWN'));
        if (!in_array($direction, ['ENTRY', 'EXIT', 'UNKNOWN'], true)) {
            $direction = 'UNKNOWN';
        }

        // Defense-in-depth: Reject non-access or structurally empty stream events
        if ($source === 'HIKVISION_ALERTSTREAM') {
            $isAlarm = in_array($eventType, ['DOOR_FORCED_OPEN', 'TAMPER_ALARM', 'DURESS_FINGERPRINT'], true);
            $hasAccessIdentity = !empty($cardNo) || !empty($rawUser) || ($major === 5 && $sub > 0);
            if (!$isAlarm && !$hasAccessIdentity) {
                app(SecuregateMetricsService::class)->recordEvent([
                    'ingestion_status' => 'ignored',
                ]);
                return [
                    'status' => 'ignored',
                    'code' => 422,
                    'message' => 'Event diabaikan: identitas akses tidak memadai atau bukan event akses yang valid.',
                ];
            }
        }

        // 3. Live-Only Freshness & Historical Backlog Gate
        $mode = strtoupper((string) ($options['mode'] ?? ($source === 'HIKVISION_ALERTSTREAM' ? 'LIVE_ONLY' : 'HISTORICAL_REPLAY')));
        if ($mode === 'LIVE_ONLY') {
            $rawTimestamp = $eventData['timestamp'] ?? ($eventData['event_time'] ?? null);
            if ($rawTimestamp) {
                $eventTime = strtotime((string) $rawTimestamp);
                if ($eventTime !== false) {
                    $trustedTime = isset($options['trusted_time'])
                        ? (is_numeric($options['trusted_time']) ? (int) $options['trusted_time'] : strtotime((string) $options['trusted_time']))
                        : time();

                    $maxAge = (int) ($options['max_event_age_seconds'] ?? config('services.hikvision.stream_max_event_age_seconds', 120));
                    $maxFutureSkew = (int) ($options['max_future_skew_seconds'] ?? config('services.hikvision.stream_max_future_skew_seconds', 300));
                    $age = $trustedTime - $eventTime;

                    // Future skew check (timestamp too far in future)
                    if ($age < -$maxFutureSkew) {
                        return [
                            'status' => 'invalid',
                            'reason_code' => 'INVALID_FUTURE_TIMESTAMP',
                            'code' => 422,
                            'message' => 'Event tidak valid: timestamp melampaui batas toleransi masa depan.',
                            'data' => [
                                'door_id' => $door->door_id,
                                'skew_seconds' => -$age,
                            ],
                        ];
                    }

                    // Historical backlog check (event older than allowed freshness window)
                    if ($age > $maxAge) {
                        return [
                            'status' => 'skipped',
                            'reason_code' => 'HISTORICAL_BACKLOG',
                            'code' => 200,
                            'message' => 'Event dilewati: historical backlog di luar jendela live-only.',
                            'data' => [
                                'door_id' => $door->door_id,
                                'event_age_seconds' => $age,
                            ],
                        ];
                    }
                }
            }
        }

        // 4. Resolve Employee Safely
        $employee = null;
        $reason = $eventData['reason'] ?? null;
        $personResolved = false;
        $cardResolved = false;

        if ($eventType === 'DOOR_FORCED_OPEN') {
            $verifyMethod = $eventData['verify_method'] ?? 'Sensor';
            $accessStatus = 'Alarm';
            $displayNik = $rawUser ? substr(strip_tags((string) $rawUser), 0, 50) : 'SENSOR-FORCED-OPEN';
            $reason = $reason ?: '[CRITICAL ALARM] Pintu Dibuka Paksa (Door Forced Open) - Potensi Pembobolan / Intrusi Ilegal!';
        } elseif ($eventType === 'TAMPER_ALARM') {
            $verifyMethod = $eventData['verify_method'] ?? 'Sensor';
            $accessStatus = 'Alarm';
            $displayNik = $rawUser ? substr(strip_tags((string) $rawUser), 0, 50) : 'SENSOR-TAMPER';
            $reason = $reason ?: '[CRITICAL ALARM] Sensor Sabotase Aktif (Tamper Alarm) - Perangkat Terminal Dilepas / Dibongkar!';
        } elseif ($eventType === 'DURESS_FINGERPRINT') {
            if ($rawUser) {
                $employee = Employee::where('nik', $rawUser)
                    ->orWhere('employee_id', $rawUser)
                    ->orWhere('card_no', $rawUser)
                    ->first();
                if ($employee) {
                    $personResolved = true;
                }
            }
            if (!$employee && $cardNo) {
                $employee = Employee::where('card_no', $cardNo)->first();
                if ($employee) {
                    $cardResolved = true;
                }
            } elseif ($employee && $cardNo) {
                $cardEmp = Employee::where('card_no', $cardNo)->first();
                if ($cardEmp && $cardEmp->id === $employee->id) {
                    $cardResolved = true;
                }
            }
            $verifyMethod = $eventData['verify_method'] ?? 'Duress_Fingerprint';
            $accessStatus = 'Duress';
            $displayNik = $employee ? $employee->nik : ($rawUser ? substr(strip_tags((string) $rawUser), 0, 50) : 'DURESS-USER');
            $reason = $reason ?: '[EMERGENCY DURESS] Akses Pintu Dibuka di Bawah Ancaman (Duress Alarm Triggered)!';
        } else {
            $eventType = 'STANDARD_TAP';
            if ($rawUser) {
                $employee = Employee::where('nik', $rawUser)
                    ->orWhere('employee_id', $rawUser)
                    ->orWhere('card_no', $rawUser)
                    ->first();
                if ($employee) {
                    $personResolved = true;
                }
            }
            if (!$employee && $cardNo) {
                $employee = Employee::where('card_no', $cardNo)->first();
                if ($employee) {
                    $cardResolved = true;
                }
            } elseif ($employee && $cardNo) {
                $cardEmp = Employee::where('card_no', $cardNo)->first();
                if ($cardEmp && $cardEmp->id === $employee->id) {
                    $cardResolved = true;
                }
            }

            $rawVerify = $eventData['verify_method'] ?? ($eventData['verification_method'] ?? null);
            $normalizedVerify = $rawVerify !== null ? HikvisionPayloadParser::normalizeVerificationMethod($rawVerify) : 'UNKNOWN';
            $verifyMethod = $normalizedVerify !== 'UNKNOWN'
                ? $normalizedVerify
                : (!empty($cardNo) ? 'Card' : 'UNKNOWN');

            if (isset($eventData['access_status'])) {
                $accessStatus = ucfirst(strtolower((string) $eventData['access_status']));
            } else {
                $accessStatus = $employee ? 'Granted' : 'Denied';
            }

            // PRIVACY: never store raw card in displayNik or reason
            if ($employee) {
                $displayNik = $employee->nik;
            } elseif ($rawUser && $rawUser !== $cardNo) {
                $displayNik = substr(strip_tags((string) $rawUser), 0, 50);
            } else {
                $displayNik = null;
            }

            $reason = $reason ?: ($accessStatus === 'Denied' && !$employee ? 'Unknown Card / Unregistered User' : null);
        }

        // 4. Deterministic Event Fingerprint Deduplication
        // Prevents duplicate recording across Webhook + AlertStream coexistence and network retransmissions,
        // while guaranteeing that legitimate distinct accesses persist.
        $parsedTime = $eventTimestamp ? date('Y-m-d H:i:s', strtotime($eventTimestamp)) : null;

        $dedupQuery = AccessLog::where('door_id', $door->id)
            ->where('event_type', $eventType)
            ->where('created_at', '>=', now()->subMinutes(5));

        if ($employee) {
            $dedupQuery->where('employee_id', $employee->id);
        } elseif ($displayNik !== null) {
            $dedupQuery->where('nik', $displayNik);
        }

        if ($parsedTime) {
            $timeStart = date('Y-m-d H:i:s', strtotime($parsedTime) - 3);
            $timeEnd = date('Y-m-d H:i:s', strtotime($parsedTime) + 3);
            $dedupQuery->whereBetween('timestamp', [$timeStart, $timeEnd]);
        }

        if ($serialNo) {
            $dedupQuery->where('device_serial', $serialNo);
        }

        $existingLog = $dedupQuery->first();
        if ($existingLog) {
            return [
                'status' => 'success',
                'duplicate' => true,
                'code' => 200,
                'message' => 'Event duplikat diabaikan',
                'data' => [
                    'log_id' => $existingLog->log_id,
                    'door_id' => $door->door_id,
                ],
            ];
        }

        // 5. Generate Unique Log ID
        $logId = 'LOG-' . date('YmdHis') . '-' . Str::random(4);

        // 6. Create Access Log Entry with privacy-preserving sanitized fields
        $accessLog = AccessLog::create([
            'log_id' => strtoupper($logId),
            'door_id' => $door->id,
            'employee_id' => $employee ? $employee->id : null,
            'nik' => $displayNik,
            'event_type' => $eventType,
            'device_ip' => $deviceIp,
            'verify_method' => $verifyMethod,
            'access_status' => $accessStatus,
            'reason' => $reason,
            'source' => $source,
            'source_format' => $eventData['source_format'] ?? 'STREAM',
            'timestamp' => $parsedTime ?: now(),
            'device_serial' => $serialNo,
            'major_event' => $major,
            'minor_event' => $sub,
        ]);

        $accessLog->setAttribute('attendance_direction', $direction);

        $logMessage = ($source === 'HIKVISION_WEBHOOK')
            ? '[ISAPI Webhook] Access event recorded'
            : '[Hikvision Event Ingested]';

        Log::info($logMessage, [
            'log_id' => $accessLog->log_id,
            'door_id' => $door->door_id,
            'device_ip' => $deviceIp,
            'event_type' => $eventType,
            'access_status' => $accessLog->access_status,
            'verify_method' => $verifyMethod,
            'employee_id' => $employee?->employee_id,
            'source' => $source,
            'source_format' => $eventData['source_format'] ?? 'STREAM',
        ]);

        AccessLogCreated::dispatch($accessLog);

        // 7. Record Observability Metrics (Fail-open, Privacy-Safe, Low-Cardinality)
        try {
            $isAlarm = in_array($eventType, ['DOOR_FORCED_OPEN', 'TAMPER_ALARM', 'DURESS_FINGERPRINT'], true);
            $isHardwareAlarm = in_array($eventType, ['DOOR_FORCED_OPEN', 'TAMPER_ALARM'], true);
            $hasIdentityBearing = !empty($rawUser) || !empty($cardNo);

            if ($isHardwareAlarm) {
                $eventClass = 'system_alarm';
            } elseif ($isAlarm && !$hasIdentityBearing) {
                $eventClass = 'system_alarm';
            } elseif ($employee) {
                $eventClass = 'mapped_identity';
            } elseif ($hasIdentityBearing) {
                $eventClass = 'unmapped_identity';
            } else {
                $eventClass = 'unknown';
            }

            if ($personResolved && $cardResolved) {
                $resolution = 'both';
            } elseif ($personResolved) {
                $resolution = 'person_no';
            } elseif ($cardResolved) {
                $resolution = 'card_no';
            } else {
                $resolution = 'none';
            }

            $mappingResult = $employee ? 'mapped' : 'unmapped';

            $decisionNorm = strtolower((string) $accessStatus);
            if (!in_array($decisionNorm, ['granted', 'denied'], true)) {
                $decisionNorm = 'other';
            }

            app(SecuregateMetricsService::class)->recordEvent([
                'event_class' => $eventClass,
                'resolution' => $resolution,
                'mapping_result' => $mappingResult,
                'decision' => $decisionNorm,
                'ingestion_status' => 'processed',
                'unknown_identity' => (!$employee && $hasIdentityBearing && !$isHardwareAlarm),
                'unmapped_person' => (!empty($rawUser) && !$employee && !$isHardwareAlarm),
                'unmapped_card' => (!empty($cardNo) && !$employee && !$isHardwareAlarm),
            ]);
        } catch (\Throwable $e) {
            Log::warning('[HikvisionEventIngestion] Metrics recording skipped', [
                'error' => $e->getMessage(),
            ]);
        }

        // Optional telemetry: track high-water mark serial per door
        if ($serialNo && is_numeric($serialNo)) {
            $watermarkKey = "hikvision-alertstream:watermark:{$door->door_id}";
            $currentWatermark = (int) Cache::get($watermarkKey, 0);
            if ((int) $serialNo > $currentWatermark) {
                Cache::forever($watermarkKey, (int) $serialNo);
            }
        }

        return [
            'status' => 'success',
            'duplicate' => false,
            'code' => 200,
            'message' => 'Event notifikasi tap / alarm akses berhasil dicatat',
            'data' => [
                'log_id' => $accessLog->log_id,
                'event_type' => $eventType,
                'door_id' => $door->door_id,
                'door_name' => $door->door_name,
                'employee_name' => $employee ? $employee->name : ($eventType === 'STANDARD_TAP' ? 'Unknown / Unregistered Card' : 'Security Alarm Event'),
                'access_status' => $accessLog->access_status,
                'reason' => $accessLog->reason,
                'timestamp' => $accessLog->timestamp instanceof \DateTimeInterface ? $accessLog->timestamp->toIso8601String() : \Carbon\Carbon::parse($accessLog->timestamp)->toIso8601String(),
            ],
            'access_log' => $accessLog,
        ];
    }
}
