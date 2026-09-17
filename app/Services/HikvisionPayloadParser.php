<?php

namespace App\Services;

use Illuminate\Support\Facades\Log;

class HikvisionPayloadParser
{
    public static function normalizeVerificationMethod(mixed $value): string
    {
        $raw = trim((string) $value);
        if ($raw === '') {
            return 'UNKNOWN';
        }

        $normalized = strtoupper((string) preg_replace('/(?<!^)[A-Z]/', '_$0', $raw));
        $normalized = strtoupper((string) preg_replace('/[^A-Z0-9]+/', '_', $normalized));
        $normalized = trim($normalized, '_');

        return match ($normalized) {
            'FINGERPRINT', 'FINGER_PRINT', 'FP' => 'Fingerprint',
            'CARD', 'NORMAL_CARD', 'PATROL_CARD', 'SUPER_CARD' => 'Card',
            'FACE', 'FACE_RECOGNITION' => 'Face',
            'PIN' => 'PIN',
            'PASSWORD', 'PASSWD' => 'Password',
            'MULTI_FACTOR', 'MULTIFACTOR', 'CARD_AND_FACE', 'CARD_AND_FINGERPRINT',
            'CARD_OR_FACE_OR_FP', 'CARD_OR_FACE_OR_FINGERPRINT' => 'Multi_Factor',
            default => 'UNKNOWN',
        };
    }

    /**
     * Parses and normalizes various Hikvision payload formats (JSON, XML, Multipart).
     *
     * @param string $rawContent
     * @param string $contentType
     * @return array|null Returns normalized event array or null if invalid.
     */
    public static function parse(string $rawContent, string $contentType = ''): ?array
    {
        $normalized = [
            'source_format' => 'UNKNOWN',
            'device_ip' => '',
            'event_time' => '',
            'employee_no' => '',
            'card_reference' => '',
            'major_event' => null,
            'minor_event' => null,
            'device_serial' => '',
            'direction' => 'UNKNOWN',
            'verification_method' => 'UNKNOWN',
            'is_valid_event' => false,
        ];

        // 1. Try parsing as JSON first
        $json = @json_decode($rawContent, true);
        if (is_array($json)) {
            $jsonEventType = strtolower((string) ($json['eventType'] ?? ''));
            if (in_array($jsonEventType, ['heartbeat', 'keepalive', 'devicestatus', 'videoloss', 'healthstatus'], true)) {
                return null;
            }

            if (isset($json['AccessControllerEvent'])) {
                $normalized['source_format'] = 'HIKVISION_JSON';
                $normalized['device_ip'] = $json['ipAddress'] ?? $json['ipv4Address'] ?? '';
                $normalized['event_time'] = $json['dateTime'] ?? '';

                $eventData = $json['AccessControllerEvent'];
                $major = isset($eventData['majorEventType']) ? (int) $eventData['majorEventType'] : null;
                $minor = isset($eventData['subEventType']) ? (int) $eventData['subEventType'] : null;
                $card = (string) ($eventData['cardNo'] ?? $eventData['cardNumber'] ?? '');
                $employee = (string) ($eventData['employeeNoString'] ?? $eventData['employeeNo'] ?? '');

                if ($major === null && $minor === null && $card === '' && $employee === '') {
                    return null;
                }

                $normalized['major_event'] = $major;
                $normalized['minor_event'] = $minor;
                $normalized['card_reference'] = $card;
                $normalized['employee_no'] = $employee;
                $normalized['device_serial'] = (string) ($eventData['serialNo'] ?? '');
                $normalized['direction'] = strtoupper((string) ($eventData['direction'] ?? $eventData['readerDirection'] ?? $eventData['attendanceDirection'] ?? 'UNKNOWN'));
                $normalized['verification_method'] = self::normalizeVerificationMethod(
                    $eventData['verifyMethod'] ?? $eventData['currentVerifyMode'] ?? $eventData['verificationMethod'] ?? null
                );
                $normalized['is_valid_event'] = true;
                return $normalized;
            }
        }

        // 2. Try parsing as XML or Multipart
        // Extract XML if embedded in multipart
        $xmlString = trim($rawContent);
        if (str_contains($rawContent, '------') && str_contains($rawContent, 'xml')) {
            // It's a multipart payload, let's extract everything from <?xml to the end of the root node
            if (preg_match('/(<\?xml.*?(?:<\/EventNotificationAlert>|<\/AccessControllerEvent>))/ms', $rawContent, $matches)) {
                $xmlString = $matches[1];
            }
            $normalized['source_format'] = 'HIKVISION_MULTIPART';
        } else {
            $normalized['source_format'] = 'HIKVISION_XML';
        }

        if (str_contains($xmlString, '<EventNotificationAlert') || str_contains($xmlString, 'AccessControllerEvent')) {
            try {
                // Strip namespaces for easier parsing
                $xmlString = preg_replace('/xmlns[^=]*="[^"]*"/i', '', $xmlString);
                $xml = @simplexml_load_string($xmlString, 'SimpleXMLElement', LIBXML_NOCDATA);
                if ($xml !== false) {
                    $xmlEventType = strtolower((string) ($xml->eventType ?? ''));
                    if (in_array($xmlEventType, ['heartbeat', 'keepalive', 'devicestatus', 'videoloss', 'healthstatus'], true)) {
                        return null;
                    }

                    $normalized['device_ip'] = (string) ($xml->ipAddress ?? $xml->ipv4Address ?? '');
                    $normalized['event_time'] = (string) ($xml->dateTime ?? '');
                    
                    if (isset($xml->AccessControllerEvent)) {
                        $eventData = $xml->AccessControllerEvent;
                    } else {
                        $eventData = $xml;
                    }

                    $major = (int) ($eventData->majorEventType ?? 0);
                    $minor = (int) ($eventData->subEventType ?? 0);
                    $card = (string) ($eventData->cardNo ?? $eventData->cardNumber ?? '');
                    $employee = (string) ($eventData->employeeNoString ?? $eventData->employeeNo ?? '');

                    // Must have a valid access event indicator (non-zero major/minor event or card/employee)
                    if ($major === 0 && $minor === 0 && $card === '' && $employee === '') {
                        return null;
                    }

                    $normalized['major_event'] = $major;
                    $normalized['minor_event'] = $minor;
                    $normalized['card_reference'] = $card;
                    $normalized['employee_no'] = $employee;
                    $normalized['device_serial'] = (string) ($eventData->serialNo ?? '');
                    $normalized['direction'] = strtoupper((string) ($eventData->direction ?? $eventData->readerDirection ?? $eventData->attendanceDirection ?? 'UNKNOWN'));
                    $normalized['verification_method'] = self::normalizeVerificationMethod(
                        (string) ($eventData->verifyMethod ?? $eventData->currentVerifyMode ?? $eventData->verificationMethod ?? '')
                    );
                    $normalized['is_valid_event'] = true;
                    return $normalized;
                }
            } catch (\Throwable $e) {
                Log::warning('[HikvisionPayloadParser] Failed to parse XML', ['error' => $e->getMessage()]);
            }
        }

        return null;
    }
}
