<?php

namespace App\Http\Resources;

use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

class ActivityLogResource extends JsonResource
{
    public function toArray(Request $request): array
    {
        return [
            'id' => $this->id,
            'admin' => $this->admin ? [
                'id' => $this->admin->id,
                'name' => $this->admin->name,
                'email' => $this->admin->email,
            ] : null,
            'action' => $this->action,
            'subject_type' => $this->subject_type,
            'subject_id' => $this->subject_id,
            'description' => $this->redactSensitiveContext((string) $this->description),
            'timestamp' => $this->timestamp ? $this->timestamp->toIso8601String() : null,
        ];
    }

    private function redactSensitiveContext(string $description): string
    {
        $description = preg_replace('~(Bearer\s+)[A-Za-z0-9._+/=-]+~i', '$1[REDACTED]', $description) ?? $description;
        $sensitiveKeys = 'password_hash|password|access_token|refresh_token|token|client_secret|secret|authorization|cookie|card_?no|card|rfid|fingerprint|biometric_?template|biometric';

        return preg_replace('~(["\']?(?:'.$sensitiveKeys.')["\']?\s*[:=]\s*)("[^"]*"|\'[^\']*\'|[^\s,;}]+)~i', '$1[REDACTED]', $description) ?? $description;
    }
}
