<?php

namespace App\Services;

use App\Models\Door;
use Illuminate\Support\Facades\Log;

class HikvisionAlertStreamClient
{
    public const MAX_BUFFER_SIZE = 1048576; // 1 MB
    public const MAX_PART_SIZE = 1048576;   // 1 MB
    public const DEFAULT_BOUNDARY = 'boundary';

    /**
     * Optional handler for testing/mocking stream execution.
     *
     * @var (\Closure(Door, callable, ?callable, array): array)|null
     */
    public static ?\Closure $streamHandler = null;

    public function __construct(
        protected HikvisionIsapiService $isapiService,
        protected HikvisionEventIngestionService $ingestionService
    ) {}

    /**
     * Extract boundary token from Content-Type header.
     */
    public function detectBoundary(?string $contentType): string
    {
        if (!$contentType) {
            return self::DEFAULT_BOUNDARY;
        }

        if (preg_match('/boundary=["\']?([^"\';\s]+)["\']?/i', $contentType, $matches)) {
            return trim($matches[1]);
        }

        return self::DEFAULT_BOUNDARY;
    }

    /**
     * Extract complete MIME parts from buffer based on boundary delimiter.
     *
     * @return array<int, string>
     */
    public function extractParts(string &$buffer, string $boundary, bool &$initialized): array
    {
        $marker = '--' . $boundary;
        $markerLen = strlen($marker);
        $parts = [];

        if (!$initialized) {
            $firstPos = strpos($buffer, $marker);
            if ($firstPos === false) {
                if (strlen($buffer) > self::MAX_BUFFER_SIZE) {
                    Log::warning('[HikvisionAlertStream] Buffer exceeded limit before first boundary. Discarding.', [
                        'buffer_size' => strlen($buffer),
                    ]);
                    $buffer = '';
                }
                return [];
            }

            // Discard preamble before first boundary
            $buffer = substr($buffer, $firstPos + $markerLen);
            $initialized = true;
        }

        while (($nextPos = strpos($buffer, $marker)) !== false) {
            $part = substr($buffer, 0, $nextPos);
            $buffer = substr($buffer, $nextPos + $markerLen);

            $cleanPart = trim($part);
            if ($cleanPart !== '' && $cleanPart !== '--') {
                if (strlen($cleanPart) > self::MAX_PART_SIZE) {
                    Log::warning('[HikvisionAlertStream] Extracted MIME part exceeded MAX_PART_SIZE. Discarding.', [
                        'part_size' => strlen($cleanPart),
                    ]);
                    continue;
                }
                $parts[] = $part;
            }
        }

        if (strlen($buffer) > self::MAX_BUFFER_SIZE) {
            Log::warning('[HikvisionAlertStream] Buffer exceeded 1MB without boundary match. Resetting buffer.', [
                'buffer_size' => strlen($buffer),
            ]);
            $buffer = '';
            $initialized = false;
        }

        return $parts;
    }

    /**
     * Parse individual MIME part into headers and body.
     */
    public function parsePart(string $rawPart): ?array
    {
        if (strlen($rawPart) > self::MAX_PART_SIZE) {
            Log::warning('[HikvisionAlertStream] Raw part exceeded MAX_PART_SIZE. Discarding.', [
                'part_size' => strlen($rawPart),
            ]);
            return null;
        }

        $clean = trim($rawPart);
        if ($clean === '' || $clean === '--') {
            return null;
        }

        $headerBodySplit = preg_split('/\r?\n\r?\n/', $clean, 2);
        if (count($headerBodySplit) < 2) {
            $headers = [];
            $body = $clean;
        } else {
            $rawHeaders = $headerBodySplit[0];
            $body = trim($headerBodySplit[1]);
            $headers = [];

            foreach (preg_split('/\r?\n/', $rawHeaders) as $line) {
                if (str_contains($line, ':')) {
                    [$k, $v] = explode(':', $line, 2);
                    $headers[strtolower(trim($k))] = trim($v);
                }
            }
        }

        if (isset($headers['content-length']) && (int) $headers['content-length'] > self::MAX_PART_SIZE) {
            Log::warning('[HikvisionAlertStream] Content-Length header exceeded MAX_PART_SIZE. Discarding.', [
                'content_length' => (int) $headers['content-length'],
            ]);
            return null;
        }

        if (strlen($body) > self::MAX_PART_SIZE) {
            Log::warning('[HikvisionAlertStream] Part body exceeded MAX_PART_SIZE. Discarding.', [
                'body_size' => strlen($body),
            ]);
            return null;
        }

        if ($body === '') {
            return null;
        }

        return [
            'headers' => $headers,
            'content_type' => $headers['content-type'] ?? null,
            'content_length' => isset($headers['content-length']) ? (int) $headers['content-length'] : null,
            'body' => $body,
        ];
    }

    /**
     * Open outbound alertStream to a Door terminal and process events.
     *
     * @param callable(array, Door): (bool|void) $onEvent
     * @param (callable(): bool)|null $shouldStop
     */
    public function streamFromDoor(
        Door $door,
        callable $onEvent,
        ?callable $shouldStop = null,
        array $options = []
    ): array {
        if (static::$streamHandler !== null) {
            return (static::$streamHandler)($door, $onEvent, $shouldStop, $options);
        }

        $credentials = $this->isapiService->getDeviceCredentials($door);
        $hostPort = $this->isapiService->getDeviceHostAndPort($door);
        $url = "http://{$hostPort['host']}:{$hostPort['port']}/ISAPI/Event/notification/alertStream";

        $buffer = '';
        $boundary = self::DEFAULT_BOUNDARY;
        $initialized = false;
        $eventCount = 0;
        $byteCount = 0;
        $httpCode = 0;
        $startTime = microtime(true);
        $lastHeartbeat = time();
        $stopRequested = false;
        $lastError = null;

        $maxRuntime = $options['max_runtime'] ?? null;
        $maxEvents = $options['max_events'] ?? null;
        $connectTimeout = $options['connect_timeout'] ?? 5;
        $timeout = $options['timeout'] ?? 0; // 0 = continuous stream
        $onConnected = $options['on_connected'] ?? null;
        $onTick = $options['on_tick'] ?? null;

        $ch = curl_init();
        curl_setopt_array($ch, [
            CURLOPT_URL => $url,
            CURLOPT_HTTPAUTH => CURLAUTH_DIGEST,
            CURLOPT_USERPWD => "{$credentials['username']}:{$credentials['password']}",
            CURLOPT_RETURNTRANSFER => false,
            CURLOPT_HEADER => false,
            CURLOPT_CONNECTTIMEOUT => $connectTimeout,
            CURLOPT_TIMEOUT => $timeout,
            CURLOPT_BUFFERSIZE => 8192,
            CURLOPT_TCP_KEEPALIVE => 1,
            CURLOPT_TCP_KEEPIDLE => 15,
            CURLOPT_TCP_KEEPINTVL => 15,
            CURLOPT_NOPROGRESS => false,
            CURLOPT_XFERINFOFUNCTION => function (
                $ch,
                int $dltotal,
                int $dlnow,
                int $ultotal,
                int $ulnow
            ) use (
                &$stopRequested,
                $shouldStop,
                $maxRuntime,
                $startTime,
                $onTick
            ): int {
                if ($onTick !== null) {
                    $onTick();
                }
                if ($shouldStop !== null && $shouldStop()) {
                    $stopRequested = true;
                    return 1; // Abort curl transfer on stop request
                }
                if ($maxRuntime !== null && (microtime(true) - $startTime) >= $maxRuntime) {
                    $stopRequested = true;
                    return 1; // Abort curl transfer on max runtime
                }
                return 0;
            },
            CURLOPT_HEADERFUNCTION => function ($ch, string $headerLine) use (&$boundary, &$httpCode, $onConnected): int {
                $len = strlen($headerLine);
                if (preg_match('/^HTTP\/[\d\.]+\s+(\d+)/i', $headerLine, $matches)) {
                    $httpCode = (int) $matches[1];
                }
                if (stripos($headerLine, 'Content-Type:') === 0) {
                    $boundary = $this->detectBoundary($headerLine);
                    if ($httpCode === 200 && $onConnected !== null) {
                        $onConnected();
                    }
                }
                return $len;
            },
            CURLOPT_WRITEFUNCTION => function ($ch, string $chunk) use (
                &$buffer,
                &$boundary,
                &$initialized,
                &$eventCount,
                &$byteCount,
                &$lastHeartbeat,
                &$stopRequested,
                $door,
                $onEvent,
                $shouldStop,
                $maxRuntime,
                $maxEvents,
                $startTime
            ): int {
                $chunkLen = strlen($chunk);
                $byteCount += $chunkLen;
                $lastHeartbeat = time();
                $buffer .= $chunk;

                $parts = $this->extractParts($buffer, $boundary, $initialized);
                foreach ($parts as $rawPart) {
                    $parsedPart = $this->parsePart($rawPart);
                    if (!$parsedPart) {
                        continue;
                    }

                    $parsedEvent = HikvisionPayloadParser::parse($parsedPart['body'], $parsedPart['content_type']);
                    if (!empty($parsedEvent) && !empty($parsedEvent['is_valid_event'])) {
                        $parsedEvent['source_format'] = $parsedEvent['source_format'] ?? 'ALERTSTREAM_MULTIPART';
                        $accepted = $onEvent($parsedEvent, $door);
                        if ($accepted !== false) {
                            $eventCount++;
                        }
                    }

                    if ($maxEvents !== null && $eventCount >= $maxEvents) {
                        $stopRequested = true;
                        return 0; // Abort curl write to gracefully stop
                    }
                }

                if ($maxRuntime !== null && (microtime(true) - $startTime) >= $maxRuntime) {
                    $stopRequested = true;
                    return 0;
                }

                if ($shouldStop !== null && $shouldStop()) {
                    $stopRequested = true;
                    return 0;
                }

                return $chunkLen;
            },
        ]);

        $execResult = curl_exec($ch);
        $curlErrno = curl_errno($ch);
        $curlError = curl_error($ch);
        $effectiveCode = curl_getinfo($ch, CURLINFO_HTTP_CODE) ?: $httpCode;
        curl_close($ch);

        $endTime = microtime(true);

        if (($curlErrno === CURLE_WRITE_ERROR || $curlErrno === 42 /* CURLE_ABORTED_BY_CALLBACK */) && $stopRequested) {
            // Graceful abort via callback or write function
            $status = 'stopped';
            $lastError = null;
        } elseif ($curlErrno !== 0) {
            $status = 'error';
            $lastError = "cURL Error ({$curlErrno}): {$curlError}";
        } elseif ($effectiveCode >= 400) {
            $status = 'error';
            $lastError = "HTTP Error {$effectiveCode}";
        } else {
            $status = 'completed';
        }

        return [
            'status' => $status,
            'http_code' => $effectiveCode,
            'events_received' => $eventCount,
            'bytes_received' => $byteCount,
            'start_time' => $startTime,
            'end_time' => $endTime,
            'runtime_seconds' => round($endTime - $startTime, 2),
            'last_heartbeat_at' => $lastHeartbeat,
            'last_error' => $lastError,
        ];
    }
}
