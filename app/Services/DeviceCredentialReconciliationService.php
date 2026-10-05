<?php

namespace App\Services;

use App\Models\ActivityLog;
use App\Models\Admin;
use App\Models\CredentialRecord;
use App\Models\DevicePersonLink;
use App\Models\DevicePersonState;
use App\Models\DeviceReconciliationDoorResult;
use App\Models\DeviceReconciliationRun;
use App\Models\Door;
use App\Models\Employee;
use Illuminate\Support\Collection;

/**
 * Compares what the access-control devices report with the app records, read-only.
 *
 * Source of truth is split per field: the app owns identity (employee_id, NIK, name,
 * organization, employment status, access authorization); the device owns what is
 * physically stored on it (person present, card stored, fingerprint enrolled). Neither
 * side overwrites the other: devices are only read (UserInfo/CardInfo search) and the
 * employees table is never written. The only writes are this module's own tables and
 * an activity log entry.
 */
class DeviceCredentialReconciliationService
{
    // Person-level statuses (one device person on one device, or an employee on one device).
    public const MATCHED = 'MATCHED';
    public const APP_ONLY = 'APP_ONLY';
    public const DEVICE_ONLY = 'DEVICE_ONLY';
    public const CARD_MISMATCH = 'CARD_MISMATCH';
    public const BIOMETRIC_MISMATCH = 'BIOMETRIC_MISMATCH';
    public const IDENTITY_CONFLICT = 'IDENTITY_CONFLICT';
    public const REVIEW = 'REVIEW';
    public const DEVICE_UNREACHABLE = 'DEVICE_UNREACHABLE';
    public const UNVERIFIED = 'UNVERIFIED';

    // Card statuses.
    public const CARD_APP_RECORDED = 'APP_RECORDED';
    public const CARD_DEVICE_FOUND = 'DEVICE_FOUND';
    public const CARD_MATCHED = 'MATCHED';
    public const CARD_MISMATCH_STATUS = 'MISMATCH';
    public const CARD_NONE = 'NONE';
    public const UNKNOWN = 'UNKNOWN';

    // Fingerprint statuses.
    public const FP_ENROLLED = 'ENROLLED_ON_DEVICE';
    public const FP_NOT_ENROLLED = 'NOT_ENROLLED';
    public const FP_CONFLICT = 'CONFLICT';

    // Employee-level sync statuses.
    public const SYNC_SYNCED = 'SYNCED';
    public const SYNC_PARTIAL = 'PARTIAL';
    public const SYNC_APP_ONLY = 'APP_ONLY';
    public const SYNC_DEVICE_ONLY = 'DEVICE_ONLY';
    public const SYNC_CONFLICT = 'CONFLICT';
    public const SYNC_REVIEW = 'REVIEW';

    // Device link: is this device person the employee? (identifier evidence only)
    public const LINK_MATCHED = 'MATCHED';
    public const LINK_APP_ONLY = 'APP_ONLY';
    public const LINK_DEVICE_ONLY = 'DEVICE_ONLY';
    public const LINK_CONFLICT = 'CONFLICT';
    public const LINK_UNKNOWN = 'UNKNOWN';

    // Identity: is the employee record verified (HR data, device name agreement)? Independent
    // of the device link: a placeholder NIK never undoes an exact person-number match.
    public const IDENTITY_VERIFIED = 'VERIFIED';
    public const IDENTITY_UNVERIFIED = 'UNVERIFIED';
    public const IDENTITY_REVIEW = 'REVIEW';
    public const IDENTITY_CONFLICT_STATUS = 'CONFLICT';

    private const IDENTITY_RANK = [self::IDENTITY_VERIFIED => 0, self::IDENTITY_UNVERIFIED => 1, self::IDENTITY_REVIEW => 2, self::IDENTITY_CONFLICT_STATUS => 3];

    // Physical access per (employee, door).
    public const ACCESS_GRANTED = 'GRANTED';
    public const ACCESS_NOT_GRANTED = 'NOT_GRANTED';
    public const ACCESS_FAILED = 'FAILED';

    private const BASIS_PRIORITY = ['person_number', 'employee_id', 'nik', 'card'];

    public function __construct(
        private HikvisionIsapiService $isapi,
        private CardSecurityService $cardSecurity,
    ) {
    }

    // ------------------------------------------------------------------------------------
    // Run: read devices, evaluate, store the observed state.
    // ------------------------------------------------------------------------------------

    public function run(?Admin $actor = null, array $doorIds = [], int $limit = 1000): DeviceReconciliationRun
    {
        $doors = Door::query()
            ->when($doorIds !== [], fn ($q) => $q->whereIn('id', $doorIds))
            ->orderBy('id')
            ->get();

        $run = DeviceReconciliationRun::create([
            'mode' => 'read_only',
            'triggered_by' => $actor?->id,
            'status' => 'running',
            'door_ids' => $doors->pluck('id')->all(),
            'started_at' => now(),
        ]);

        $index = $this->buildAppIndex();
        $links = DevicePersonLink::query()->get()->keyBy(fn ($l) => $l->door_id . '|' . $l->device_employee_no);
        $doorSummaries = [];

        foreach ($doors as $door) {
            $doorSummaries[] = $this->reconcileDoor($run, $door, $index, $links, $limit);
        }

        $reachable = collect($doorSummaries)->where('reachable', true)->count();
        $run->update([
            'status' => $doors->isEmpty() || $reachable === 0 ? 'failed' : ($reachable < $doors->count() ? 'partial' : 'completed'),
            'summary' => [
                'doors' => $doorSummaries,
                'totals' => $this->sumCounts($doorSummaries),
            ],
            'finished_at' => now(),
        ]);

        ActivityLog::create([
            'admin_id' => $actor?->id,
            'action' => 'device_reconciliation_run',
            'subject_type' => 'DeviceReconciliationRun',
            'subject_id' => $run->id,
            'description' => sprintf(
                'Read-only device reconciliation #%d: %d/%d perangkat terjangkau. Tidak ada data yang ditulis ke perangkat atau ke data karyawan.',
                $run->id,
                $reachable,
                $doors->count()
            ),
            'timestamp' => now(),
        ]);

        return $run->fresh('doorResults');
    }

    private function reconcileDoor(DeviceReconciliationRun $run, Door $door, array $index, Collection $links, int $limit): array
    {
        $users = $this->isapi->fetchUsers($door, $limit, true);

        if (!($users['status'] ?? false)) {
            // Unreachable: keep the last known observations untouched; the door result says
            // the device could not be verified now. Never conclude "not enrolled" from this.
            $result = DeviceReconciliationDoorResult::create([
                'run_id' => $run->id,
                'door_id' => $door->id,
                'reachable' => false,
                'error' => mb_substr((string) ($users['error'] ?? 'Perangkat tidak dapat dihubungi.'), 0, 255),
                'verified_at' => now(),
            ]);

            return $this->doorSummary($door, $result, []);
        }

        $cards = $this->isapi->fetchCards($door, max($limit, 1) * 5, true);
        $cardsKnown = (bool) ($cards['status'] ?? false);
        $cardsByPerson = collect($cardsKnown ? $cards['cards'] : [])->groupBy('employee_no');
        $complete = (int) ($users['total_device_matches'] ?? 0) <= (int) ($users['inspected_users'] ?? 0);
        $now = now();
        $seen = [];
        $counts = array_fill_keys(['device_matched', 'identity_verified', 'identity_unverified', 'identity_review', 'synced', 'partial', 'review', 'device_only', 'conflict', 'fingerprint_unknown', 'fingerprints_reported'], 0);
        $fingerprintTotal = 0;

        foreach ($users['users'] as $user) {
            $no = trim((string) ($user['employee_no'] ?? ''));
            if ($no === '' || isset($seen[$no])) {
                continue;
            }
            $seen[$no] = true;

            $personCards = $cardsByPerson->get($no, collect());
            $observed = [
                'device_employee_no' => $no,
                'device_name' => trim((string) ($user['name'] ?? '')),
                'device_enabled' => (string) ($user['status'] ?? ''),
                'card_count' => $user['card_count'] ?? ($cardsKnown ? $personCards->count() : null),
                'card_hashes' => $cardsKnown ? $personCards->pluck('card_hash')->filter()->unique()->values()->all() : null,
                'card_masks' => $cardsKnown ? $personCards->pluck('card_mask')->filter()->unique()->values()->all() : null,
                'fingerprint_count' => $user['fingerprint_count'] ?? null,
                'face_count' => $user['face_count'] ?? null,
            ];

            $evaluation = $this->evaluate($observed, $door, $index, $links->get($door->id . '|' . $no));
            $this->countEvaluation($counts, $evaluation);
            if ($observed['fingerprint_count'] === null) {
                $counts['fingerprint_unknown']++; // not reported by the device: unknown, not zero
            } else {
                $counts['fingerprints_reported']++;
                $fingerprintTotal += $observed['fingerprint_count'];
            }

            DevicePersonState::updateOrCreate(
                ['door_id' => $door->id, 'device_employee_no' => $no],
                $observed + $evaluation + [
                    'present_on_device' => true,
                    'last_run_id' => $run->id,
                    'last_seen_at' => $now,
                    'last_verified_at' => $now,
                ]
            );
        }

        if ($complete) {
            // Only a complete inventory proves a person is no longer on the device.
            DevicePersonState::query()
                ->where('door_id', $door->id)
                ->whereNotIn('device_employee_no', array_keys($seen) ?: [''])
                ->update(['present_on_device' => false, 'last_run_id' => $run->id, 'last_verified_at' => $now]);
        }

        $appOnly = $this->appOnlyEmployeeIds($door)->count();

        $result = DeviceReconciliationDoorResult::create([
            'run_id' => $run->id,
            'door_id' => $door->id,
            'reachable' => true,
            'error' => $complete ? ($cardsKnown ? null : 'Daftar kartu tidak dapat dibaca; status kartu memakai jumlah kartu saja.') : 'Inventaris parsial: naikkan limit untuk membaca semua pengguna.',
            'total_users' => count($seen),
            'total_cards' => (int) ($cardsKnown ? ($cards['inspected_cards'] ?? 0) : collect($users['users'])->sum('card_count')),
            'total_fingerprints' => $fingerprintTotal,
            'counts' => $counts + ['app_only' => $appOnly, 'complete' => $complete],
            'verified_at' => $now,
        ]);

        return $this->doorSummary($door, $result, $result->counts);
    }

    private function countEvaluation(array &$counts, array $evaluation): void
    {
        $link = $evaluation['device_link_status'];
        if ($link === self::LINK_MATCHED) {
            $counts['device_matched']++;
            $key = 'identity_' . strtolower($evaluation['identity_status']);
            if (isset($counts[$key])) {
                $counts[$key]++;
            }
        } elseif ($link === self::LINK_DEVICE_ONLY) {
            $counts['device_only']++;
        } elseif ($link === self::LINK_CONFLICT) {
            $counts['conflict']++;
        }
        foreach ([self::SYNC_SYNCED => 'synced', self::SYNC_PARTIAL => 'partial', self::SYNC_REVIEW => 'review'] as $status => $key) {
            if ($evaluation['status'] === $status) {
                $counts[$key]++;
            }
        }
    }

    /**
     * Re-evaluate a stored observation against the current app data and links, without
     * contacting the device (used after a human decision).
     */
    public function reevaluate(DevicePersonState $state): DevicePersonState
    {
        $link = DevicePersonLink::query()
            ->where('door_id', $state->door_id)
            ->where('device_employee_no', $state->device_employee_no)
            ->first();

        $evaluation = $this->evaluate([
            'device_employee_no' => $state->device_employee_no,
            'device_name' => (string) $state->device_name,
            'card_count' => $state->card_count,
            'card_hashes' => $state->card_hashes,
            'fingerprint_count' => $state->fingerprint_count,
        ], $state->door, $this->buildAppIndex(), $link);

        $state->update($evaluation);

        return $state->fresh();
    }

    // ------------------------------------------------------------------------------------
    // Matching
    // ------------------------------------------------------------------------------------

    /**
     * Index of the app side, keyed by every identifier the matcher may use.
     */
    public function buildAppIndex(): array
    {
        $index = ['employees' => [], 'person_number' => [], 'employee_id' => [], 'nik' => [], 'card' => [], 'name' => []];

        $credentialHashes = CredentialRecord::query()
            ->whereNotNull('employee_id')
            ->whereNotNull('card_number_hash')
            ->whereNotIn('status', ['REVOKED', 'revoked', 'INACTIVE', 'inactive'])
            ->get(['employee_id', 'card_number_hash'])
            ->groupBy('employee_id');

        Employee::query()->with('biometricStatus')->orderBy('id')->chunk(500, function ($employees) use (&$index, $credentialHashes) {
            foreach ($employees as $employee) {
                $hashes = $credentialHashes->get($employee->id, collect())->pluck('card_number_hash')->all();
                if (trim((string) $employee->card_no) !== '') {
                    $hashes[] = $this->cardSecurity->hashCardNumber((string) $employee->card_no);
                }
                $hashes = array_values(array_unique(array_filter($hashes)));

                $index['employees'][$employee->id] = [
                    'id' => $employee->id,
                    'employee_id' => $employee->employee_id,
                    'name' => (string) $employee->name,
                    'nik' => (string) $employee->nik,
                    'nik_unverified' => self::isUnverifiedNik($employee->nik),
                    'card_hashes' => $hashes,
                    'app_card' => self::appRecordsCard($employee),
                    'app_fingerprint' => self::appRecordsFingerprint($employee),
                ];

                foreach (array_unique(array_filter([self::normalizeId($employee->source_person_number), self::normalizeId($employee->hikvision_employee_no)])) as $key) {
                    $index['person_number'][$key][] = $employee->id;
                }
                if (($key = self::normalizeId($employee->employee_id)) !== '') {
                    $index['employee_id'][$key][] = $employee->id;
                }
                if (!self::isUnverifiedNik($employee->nik) && ($key = self::normalizeId($employee->nik)) !== '') {
                    $index['nik'][$key][] = $employee->id;
                }
                foreach ($hashes as $hash) {
                    $index['card'][$hash][] = $employee->id;
                }
                if (($key = self::normalizeName($employee->name)) !== '') {
                    $index['name'][$key][] = $employee->id;
                }
            }
        });

        return $index;
    }

    /**
     * Decide who a device person is and how their credentials compare, as three separate
     * answers:
     * - device_link_status: MATCHED (one employee by identifier or admin link), DEVICE_ONLY,
     *   CONFLICT (identifiers point to different employees);
     * - identity_status: VERIFIED / UNVERIFIED (placeholder NIK) / REVIEW (device name missing
     *   or different, card-only match) / CONFLICT;
     * - status (sync): SYNCED (exact link, verified identity, consistent credentials),
     *   PARTIAL (exact link, something not validated yet), REVIEW (ambiguous / low-confidence
     *   match), CONFLICT, DEVICE_ONLY.
     *
     * Priority: 1 exact device person number (source_person_number / hikvision_employee_no),
     * 2 exact normalized employee_id / NIK, 3 card identifier owned by exactly one employee,
     * 4 a human link decision, 5 name — only ever a candidate, never a match.
     */
    public function evaluate(array $observed, Door $door, array $index, ?DevicePersonLink $link = null): array
    {
        $no = self::normalizeId($observed['device_employee_no'] ?? '');
        $deviceName = trim((string) ($observed['device_name'] ?? ''));
        $deviceHashes = $observed['card_hashes'] ?? null;
        $reasons = [];
        $strong = [];

        foreach (['person_number', 'employee_id', 'nik'] as $basis) {
            foreach ($index[$basis][$no] ?? [] as $id) {
                $strong[$id][] = $basis;
            }
        }
        foreach ((array) $deviceHashes as $hash) {
            $owners = array_values(array_unique($index['card'][$hash] ?? []));
            if (count($owners) === 1) {
                $strong[$owners[0]][] = 'card';
            } elseif (count($owners) > 1) {
                $reasons[] = 'card_shared_by_multiple_employees';
            }
        }

        $employeeId = null;
        $basis = null;
        $confidence = 'none';

        if ($link && $link->decision === DevicePersonLink::LINKED && $link->employee_id && isset($index['employees'][$link->employee_id])) {
            $employeeId = $link->employee_id;
            $basis = 'manual_link';
            $confidence = 'high';
            if (array_diff(array_keys($strong), [$employeeId]) !== []) {
                $reasons[] = 'link_disagrees_with_identifier';
            }
        } elseif (count($strong) > 1) {
            // Never auto-linked or merged: the admin decides in Detail Perbandingan.
            $reasons[] = 'identifiers_point_to_different_employees';

            return $this->result([
                'status' => self::SYNC_CONFLICT,
                'device_link_status' => self::LINK_CONFLICT,
                'identity_status' => self::IDENTITY_CONFLICT_STATUS,
                // Both employees are listed in detail.conflicting_employee_ids; neither is
                // presented as "the" candidate.
                'match_basis' => 'multiple',
                'confidence' => 'low',
                'card_status' => self::cardStatusWithoutEmployee($observed['card_count'] ?? null),
                'fingerprint_status' => self::fingerprintStatus($observed['fingerprint_count'] ?? null, false),
            ], $reasons, ['conflicting_employee_ids' => array_keys($strong)]);
        } elseif (count($strong) === 1) {
            $employeeId = array_key_first($strong);
            $bases = $strong[$employeeId];
            $basis = collect(self::BASIS_PRIORITY)->first(fn ($b) => in_array($b, $bases, true));
            $confidence = $basis === 'card' ? 'medium' : 'high';
        }

        if ($employeeId === null) {
            $candidateId = null;
            $nameMatches = array_values(array_unique($index['name'][self::normalizeName($deviceName)] ?? []));
            if (count($nameMatches) === 1) {
                $candidateId = $nameMatches[0]; // shown to the admin, never linked automatically
                $reasons[] = 'name_only_candidate';
            } elseif (count($nameMatches) > 1) {
                $reasons[] = 'ambiguous_name';
            }
            if (self::isMissingName($deviceName)) {
                $reasons[] = 'device_name_missing';
            }

            $sync = self::isMissingName($deviceName) || in_array('ambiguous_name', $reasons, true) ? self::SYNC_REVIEW : self::SYNC_DEVICE_ONLY;
            if ($link?->decision === DevicePersonLink::REVIEW) {
                $sync = self::SYNC_REVIEW;
                $reasons[] = 'marked_for_review';
            }
            if ($link?->decision === DevicePersonLink::IGNORED) {
                $sync = self::SYNC_DEVICE_ONLY;
                $reasons[] = 'ignored_by_admin';
            }

            return $this->result([
                'status' => $sync,
                'device_link_status' => self::LINK_DEVICE_ONLY,
                'identity_status' => self::IDENTITY_REVIEW,
                'candidate_employee_id' => $candidateId,
                'match_basis' => $candidateId ? 'name_candidate' : null,
                'confidence' => $candidateId ? 'low' : 'none',
                'card_status' => self::cardStatusWithoutEmployee($observed['card_count'] ?? null),
                'fingerprint_status' => self::fingerprintStatus($observed['fingerprint_count'] ?? null, false),
            ], $reasons);
        }

        $app = $index['employees'][$employeeId];

        if (self::isMissingName($deviceName)) {
            $reasons[] = 'device_name_missing';
        } elseif (!self::namesAgree($deviceName, $app['name'])) {
            $reasons[] = 'name_mismatch';
        }
        if ($app['nik_unverified']) {
            $reasons[] = 'nik_unverified';
        }
        if ($basis === 'card') {
            $reasons[] = 'matched_by_card_only';
        }
        if ($link?->decision === DevicePersonLink::REVIEW) {
            $reasons[] = 'marked_for_review';
        }

        $cardStatus = self::cardStatus($observed['card_count'] ?? null, $deviceHashes, $app['card_hashes'], $app['app_card']);
        $fpStatus = self::fingerprintStatus($observed['fingerprint_count'] ?? null, $app['app_fingerprint']);
        if (in_array($cardStatus, [self::CARD_MISMATCH_STATUS, self::CARD_APP_RECORDED], true)) {
            $reasons[] = 'card_mismatch';
        }
        if ($fpStatus === self::FP_CONFLICT) {
            $reasons[] = 'fingerprint_conflict';
        }

        $identity = match (true) {
            in_array('link_disagrees_with_identifier', $reasons, true) => self::IDENTITY_CONFLICT_STATUS,
            array_intersect($reasons, ['device_name_missing', 'name_mismatch', 'matched_by_card_only', 'marked_for_review']) !== [] => self::IDENTITY_REVIEW,
            $app['nik_unverified'] => self::IDENTITY_UNVERIFIED,
            default => self::IDENTITY_VERIFIED,
        };
        $credentialsConsistent = array_intersect($reasons, ['card_mismatch', 'fingerprint_conflict']) === [];

        $sync = match (true) {
            $identity === self::IDENTITY_CONFLICT_STATUS => self::SYNC_CONFLICT,
            $confidence !== 'high', $link?->decision === DevicePersonLink::REVIEW => self::SYNC_REVIEW,
            $identity === self::IDENTITY_VERIFIED && $credentialsConsistent => self::SYNC_SYNCED,
            default => self::SYNC_PARTIAL,
        };

        return $this->result([
            'status' => $sync,
            'device_link_status' => self::LINK_MATCHED,
            'identity_status' => $identity,
            'employee_id' => $employeeId,
            'match_basis' => $basis,
            'confidence' => $confidence,
            'card_status' => $cardStatus,
            'fingerprint_status' => $fpStatus,
        ], $reasons);
    }

    private function result(array $values, array $reasons, array $detail = []): array
    {
        return $values + [
            'employee_id' => null,
            'candidate_employee_id' => null,
            'reasons' => array_values(array_unique($reasons)) + ($detail ? ['detail' => $detail] : []),
        ];
    }

    public static function cardStatus(?int $deviceCount, ?array $deviceHashes, array $appHashes, bool $appRecordsCard): string
    {
        if ($deviceCount === null && $deviceHashes === null) {
            return self::UNKNOWN; // the device did not report cards: no evidence either way
        }
        $deviceCount ??= count($deviceHashes ?? []);

        if ($deviceCount > 0) {
            if ($appHashes !== [] && !empty($deviceHashes)) {
                return array_intersect($appHashes, $deviceHashes) !== [] ? self::CARD_MATCHED : self::CARD_MISMATCH_STATUS;
            }

            return self::CARD_DEVICE_FOUND;
        }

        return $appRecordsCard ? self::CARD_APP_RECORDED : self::CARD_NONE;
    }

    private static function cardStatusWithoutEmployee(?int $deviceCount): string
    {
        return $deviceCount === null ? self::UNKNOWN : ($deviceCount > 0 ? self::CARD_DEVICE_FOUND : self::CARD_NONE);
    }

    public static function fingerprintStatus(?int $deviceCount, bool $appRecordsFingerprint): string
    {
        if ($deviceCount === null) {
            return self::UNKNOWN;
        }
        if ($deviceCount > 0) {
            return self::FP_ENROLLED;
        }

        return $appRecordsFingerprint ? self::FP_CONFLICT : self::FP_NOT_ENROLLED;
    }

    // ------------------------------------------------------------------------------------
    // Read models for the API / UI
    // ------------------------------------------------------------------------------------

    /**
     * Latest result per device (one query).
     */
    public function latestDoorResults(): Collection
    {
        $ids = DeviceReconciliationDoorResult::query()->selectRaw('MAX(id) as id')->groupBy('door_id')->pluck('id');

        return DeviceReconciliationDoorResult::query()->whereIn('id', $ids)->get()->keyBy('door_id');
    }

    /**
     * Employee-level verification derived from device observations. Never derived from
     * the app checkboxes: those are reported separately as app_recorded.
     */
    public function employeeVerification(Employee $employee, ?Collection $doorResults = null): array
    {
        $doorResults ??= $this->latestDoorResults();
        $states = ($employee->relationLoaded('deviceStates') ? $employee->deviceStates : $employee->deviceStates()->get())
            ->where('present_on_device', true)
            ->keyBy('door_id');
        $doors = $employee->relationLoaded('doors') ? $employee->doors : $employee->doors()->get();
        $assigned = $doors->keyBy('id');
        $doorIds = $assigned->keys()->merge($states->keys())->unique()->sort()->values();

        $perDoor = [];
        foreach ($doorIds as $doorId) {
            $result = $doorResults->get($doorId);
            $state = $states->get($doorId);
            $assignment = $assigned->get($doorId)?->pivot;

            $stateStatus = $state ? self::stateStatuses($state) : null;
            $status = match (true) {
                $result === null => self::UNVERIFIED,
                !$result->reachable => self::DEVICE_UNREACHABLE,
                $state !== null => $stateStatus['status'],
                default => self::APP_ONLY,
            };
            $link = match (true) {
                $result === null, !$result->reachable => self::LINK_UNKNOWN,
                $state !== null => self::LINK_MATCHED,
                default => self::LINK_APP_ONLY,
            };

            $access = match (true) {
                $result === null => self::UNKNOWN,
                !$result->reachable => self::DEVICE_UNREACHABLE,
                ($assignment?->sync_status ?? null) === 'failed' => self::ACCESS_FAILED,
                $state !== null && $assignment !== null => self::ACCESS_GRANTED,
                $state === null => self::ACCESS_NOT_GRANTED,
                default => self::UNKNOWN, // on the device but no assignment in the app
            };

            $perDoor[] = [
                'door_id' => $doorId,
                'assigned_in_app' => $assignment !== null,
                'status' => $status,
                'device_link_status' => $link,
                'identity_status' => $stateStatus['identity_status'] ?? null,
                'access_status' => $access,
                'card_status' => $result?->reachable && $state ? $state->card_status : ($result && !$result->reachable ? self::DEVICE_UNREACHABLE : self::UNKNOWN),
                'fingerprint_status' => $result?->reachable && $state ? $state->fingerprint_status : ($result && !$result->reachable ? self::DEVICE_UNREACHABLE : self::UNKNOWN),
                'device_employee_no' => $state?->device_employee_no,
                'verified_at' => $result?->verified_at?->toIso8601String(),
            ];
        }

        $appCard = self::appRecordsCard($employee);
        $appFp = self::appRecordsFingerprint($employee);

        $links = array_column($perDoor, 'device_link_status');
        $identities = array_filter(array_column($perDoor, 'identity_status'));

        return [
            'sync_status' => self::aggregateSync(array_column($perDoor, 'status')),
            'device_link_status' => in_array(self::LINK_MATCHED, $links, true) ? self::LINK_MATCHED
                : (in_array(self::LINK_APP_ONLY, $links, true) ? self::LINK_APP_ONLY : self::LINK_UNKNOWN),
            // Identity is about the employee record: device evidence where linked, otherwise the
            // HR data alone (placeholder NIK = UNVERIFIED).
            'identity_status' => $identities !== []
                ? self::worstIdentity($identities)
                : (self::isUnverifiedNik($employee->nik) ? self::IDENTITY_UNVERIFIED : self::IDENTITY_VERIFIED),
            'card_status' => self::aggregateCard(array_column($perDoor, 'card_status'), $appCard),
            'fingerprint_status' => self::aggregateFingerprint(array_column($perDoor, 'fingerprint_status')),
            'app_recorded' => ['card' => $appCard, 'fingerprint' => $appFp],
            'last_verified_at' => collect($perDoor)->pluck('verified_at')->filter()->sort()->last(),
            'doors' => $perDoor,
        ];
    }

    public static function aggregateSync(array $statuses): string
    {
        $verified = array_values(array_diff($statuses, [self::UNVERIFIED, self::DEVICE_UNREACHABLE]));

        if (in_array(self::SYNC_CONFLICT, $verified, true)) {
            return self::SYNC_CONFLICT;
        }
        if (in_array(self::SYNC_REVIEW, $verified, true)) {
            return self::SYNC_REVIEW;
        }
        if ($verified === []) {
            return in_array(self::DEVICE_UNREACHABLE, $statuses, true) ? self::DEVICE_UNREACHABLE : self::UNVERIFIED;
        }
        if (array_unique($statuses) === [self::SYNC_SYNCED]) {
            return self::SYNC_SYNCED;
        }
        if (array_unique($verified) === [self::APP_ONLY]) {
            return self::SYNC_APP_ONLY;
        }

        return self::SYNC_PARTIAL;
    }

    private static function worstIdentity(array $identities): string
    {
        return collect($identities)->sortByDesc(fn ($i) => self::IDENTITY_RANK[$i] ?? 0)->first();
    }

    /**
     * Sync / link / identity of a stored observation. Rows from before the split (NULL
     * device_link_status, old combined status) are mapped conservatively until re-read.
     */
    public static function stateStatuses(DevicePersonState $state): array
    {
        if ($state->device_link_status !== null) {
            return ['status' => $state->status, 'device_link_status' => $state->device_link_status, 'identity_status' => $state->identity_status];
        }
        $link = match (true) {
            $state->status === self::IDENTITY_CONFLICT => self::LINK_CONFLICT,
            $state->employee_id !== null => self::LINK_MATCHED,
            default => self::LINK_DEVICE_ONLY,
        };
        $status = match ($state->status) {
            self::MATCHED => self::SYNC_SYNCED,
            self::IDENTITY_CONFLICT => self::SYNC_CONFLICT,
            self::DEVICE_ONLY => self::SYNC_DEVICE_ONLY,
            self::REVIEW => $link === self::LINK_MATCHED ? self::SYNC_PARTIAL : self::SYNC_REVIEW,
            default => $link === self::LINK_MATCHED ? self::SYNC_PARTIAL : self::SYNC_REVIEW,
        };

        return ['status' => $status, 'device_link_status' => $link, 'identity_status' => null];
    }

    public static function aggregateCard(array $statuses, bool $appRecordsCard): string
    {
        foreach ([self::CARD_MISMATCH_STATUS, self::CARD_MATCHED, self::CARD_DEVICE_FOUND] as $status) {
            if (in_array($status, $statuses, true)) {
                return $status;
            }
        }
        if ($statuses !== [] && array_unique($statuses) === [self::DEVICE_UNREACHABLE]) {
            return self::DEVICE_UNREACHABLE;
        }
        // Only a reachable device that holds no card is evidence; otherwise the card stays UNKNOWN
        // whatever the app records.
        if (in_array(self::CARD_NONE, $statuses, true) || in_array(self::CARD_APP_RECORDED, $statuses, true)) {
            return $appRecordsCard ? self::CARD_APP_RECORDED : self::CARD_NONE;
        }

        return self::UNKNOWN;
    }

    public static function aggregateFingerprint(array $statuses): string
    {
        foreach ([self::FP_CONFLICT, self::FP_ENROLLED] as $status) {
            if (in_array($status, $statuses, true)) {
                return $status;
            }
        }
        if ($statuses !== [] && array_unique($statuses) === [self::FP_NOT_ENROLLED]) {
            return self::FP_NOT_ENROLLED;
        }
        if ($statuses !== [] && array_unique($statuses) === [self::DEVICE_UNREACHABLE]) {
            return self::DEVICE_UNREACHABLE;
        }

        return self::UNKNOWN;
    }

    /**
     * Rows for the Reconciliation Center: one per employee plus one per device person that
     * is not tied to an employee (device-only, conflict, review).
     */
    public function overview(array $filters = []): array
    {
        $doorResults = $this->latestDoorResults();
        $doors = Door::query()->orderBy('id')->get()->keyBy('id');
        $search = mb_strtolower(trim((string) ($filters['search'] ?? '')));
        $rows = collect();

        Employee::query()
            ->with(['biometricStatus', 'doors', 'deviceStates' => fn ($q) => $q->where('present_on_device', true)])
            ->orderBy('name')
            ->get()
            ->each(function (Employee $employee) use ($rows, $doorResults, $doors) {
                $verification = $this->employeeVerification($employee, $doorResults);
                $rows->push([
                    'kind' => 'employee',
                    'key' => 'employee-' . $employee->id,
                    'employee_id' => $employee->id,
                    'state_id' => null,
                    'name' => trim((string) $employee->name) !== '' ? $employee->name : 'Nama belum tersedia',
                    'user_id' => $employee->employee_id,
                    'nik' => $employee->nik,
                    'person_number' => $employee->source_person_number ?: $employee->hikvision_employee_no,
                    'credential' => $employee->credential_method ?: 'unknown',
                    'card_status' => $verification['card_status'],
                    'fingerprint_status' => $verification['fingerprint_status'],
                    'devices' => collect($verification['doors'])->map(fn ($d) => [
                        'door_id' => $d['door_id'],
                        'door_code' => $doors->get($d['door_id'])?->door_id,
                        'status' => $d['status'],
                        'access_status' => $d['access_status'],
                    ])->all(),
                    'app_status' => self::appStatus($employee),
                    'device_link_status' => $verification['device_link_status'],
                    'identity_status' => $verification['identity_status'],
                    'app_recorded' => $verification['app_recorded'],
                    'device_status' => self::deviceStatusLabel($verification['doors']),
                    'sync_status' => $verification['sync_status'],
                    'last_verified_at' => $verification['last_verified_at'],
                ]);
            });

        DevicePersonState::query()
            ->with(['candidate:id,employee_id,name'])
            ->where('present_on_device', true)
            ->whereNull('employee_id')
            ->orderBy('door_id')->orderBy('device_employee_no')
            ->get()
            ->each(function (DevicePersonState $state) use ($rows, $doorResults, $doors) {
                $result = $doorResults->get($state->door_id);
                $statuses = self::stateStatuses($state);
                $rows->push([
                    'kind' => 'device',
                    'key' => 'device-' . $state->id,
                    'employee_id' => null,
                    'state_id' => $state->id,
                    'name' => self::isMissingName($state->device_name) ? '-' : $state->device_name,
                    'user_id' => null,
                    'nik' => null,
                    'person_number' => $state->device_employee_no,
                    'credential' => null,
                    'card_status' => $state->card_status,
                    'fingerprint_status' => $state->fingerprint_status,
                    'card_count' => $state->card_count,
                    'fingerprint_count' => $state->fingerprint_count,
                    'devices' => [[
                        'door_id' => $state->door_id,
                        'door_code' => $doors->get($state->door_id)?->door_id,
                        'status' => $result && !$result->reachable ? self::DEVICE_UNREACHABLE : $statuses['status'],
                        'access_status' => $result && !$result->reachable ? self::DEVICE_UNREACHABLE : self::UNKNOWN,
                    ]],
                    'device_link_status' => $statuses['device_link_status'],
                    'identity_status' => $statuses['identity_status'],
                    'candidate' => $state->candidate ? ['id' => $state->candidate->id, 'employee_id' => $state->candidate->employee_id, 'name' => $state->candidate->name] : null,
                    'confidence' => $state->confidence,
                    'reasons' => $state->reasons,
                    'app_status' => 'Tidak ada di aplikasi',
                    'device_status' => 'Ada di perangkat',
                    'sync_status' => $statuses['status'],
                    'ignored' => in_array('ignored_by_admin', (array) $state->reasons, true),
                    'last_verified_at' => $state->last_verified_at?->toIso8601String(),
                ]);
            });

        $counts = [
            'all' => $rows->count(),
            'synced' => $rows->where('sync_status', self::SYNC_SYNCED)->count(),
            'partial' => $rows->where('sync_status', self::SYNC_PARTIAL)->count(),
            'app_only' => $rows->where('sync_status', self::SYNC_APP_ONLY)->count(),
            'device_only' => $rows->where('sync_status', self::SYNC_DEVICE_ONLY)->count(),
            'conflict' => $rows->where('sync_status', self::SYNC_CONFLICT)->count(),
            'review' => $rows->where('sync_status', self::SYNC_REVIEW)->count(),
        ];

        // "Data Perangkat Belum Terhubung": device persons with no employee, not ignored.
        $unlinked = $rows->where('kind', 'device')->where('ignored', false)->take(50)->values()->all();

        $tabStatus = [
            'synced' => self::SYNC_SYNCED, 'partial' => self::SYNC_PARTIAL, 'app_only' => self::SYNC_APP_ONLY,
            'device_only' => self::SYNC_DEVICE_ONLY, 'conflict' => self::SYNC_CONFLICT, 'review' => self::SYNC_REVIEW,
        ];
        $tab = (string) ($filters['tab'] ?? 'all');
        if (isset($tabStatus[$tab])) {
            $rows = $rows->where('sync_status', $tabStatus[$tab]);
        }
        if ($search !== '') {
            $rows = $rows->filter(fn ($row) => str_contains(mb_strtolower(implode(' ', array_filter([
                $row['name'], $row['user_id'], $row['nik'], $row['person_number'],
            ]))), $search));
        }

        $perPage = max(1, min((int) ($filters['per_page'] ?? 20), 100));
        $total = $rows->count();
        $lastPage = max(1, (int) ceil($total / $perPage));
        $page = max(1, min((int) ($filters['page'] ?? 1), $lastPage));

        return [
            'devices' => $this->deviceSummaries($doors, $doorResults),
            'counts' => $counts,
            'unlinked' => $unlinked,
            'rows' => $rows->values()->slice(($page - 1) * $perPage, $perPage)->values()->all(),
            'pagination' => ['current_page' => $page, 'per_page' => $perPage, 'total_records' => $total, 'last_page' => $lastPage],
            'last_run' => DeviceReconciliationRun::query()->latest('id')->first(['id', 'status', 'started_at', 'finished_at']),
        ];
    }

    public function deviceSummaries(?Collection $doors = null, ?Collection $doorResults = null): array
    {
        $doors ??= Door::query()->orderBy('id')->get()->keyBy('id');
        $doorResults ??= $this->latestDoorResults();

        return $doors->map(function (Door $door) use ($doorResults) {
            $result = $doorResults->get($door->id);

            return $this->doorSummary($door, $result, $result?->counts ?? []);
        })->values()->all();
    }

    private function doorSummary(Door $door, ?DeviceReconciliationDoorResult $result, array $counts): array
    {
        $reachable = (bool) $result?->reachable;
        // Results written before the device-link/identity split have none of these keys and
        // show "—" until the next read-only run.
        $count = fn (string $key) => $reachable && array_key_exists($key, $counts) ? (int) $counts[$key] : null;
        $fingerprintsReported = $count('fingerprints_reported');

        return [
            'door_id' => $door->id,
            'door_code' => $door->door_id,
            'name' => $door->name,
            'status' => $result === null ? self::UNVERIFIED : ($reachable ? 'ONLINE' : self::DEVICE_UNREACHABLE),
            'reachable' => $reachable,
            'error' => $result?->error,
            'users_on_device' => $reachable ? $result->total_users : null,
            'cards_on_device' => $reachable ? $result->total_cards : null,
            // null when the device reported no fingerprint count for anyone: unknown, not 0.
            'fingerprints_on_device' => $fingerprintsReported ? $result->total_fingerprints : null,
            'device_matched' => $count('device_matched'),
            'identity_verified' => $count('identity_verified'),
            'identity_unverified' => $count('identity_unverified'),
            'identity_review' => $count('identity_review'),
            'synced' => $count('synced'),
            'partial' => $count('partial'),
            'review' => $count('review'),
            'device_only' => $count('device_only'),
            'app_only' => $count('app_only'),
            'conflicts' => $count('conflict'),
            'fingerprint_unknown' => $count('fingerprint_unknown'),
            'last_verified_at' => $result?->verified_at?->toIso8601String(),
        ];
    }

    private function sumCounts(array $doorSummaries): array
    {
        $totals = [];
        foreach (['users_on_device', 'cards_on_device', 'fingerprints_on_device', 'device_matched', 'identity_verified', 'identity_unverified', 'identity_review', 'synced', 'partial', 'review', 'device_only', 'app_only', 'conflicts', 'fingerprint_unknown'] as $key) {
            $values = array_filter(array_column($doorSummaries, $key), fn ($v) => $v !== null);
            $totals[$key] = $values === [] ? null : array_sum($values);
        }
        $totals['unreachable'] = count(array_filter($doorSummaries, fn ($d) => !$d['reachable']));

        return $totals;
    }

    /**
     * Employees the app says should be on this device (door assignment) but the device
     * does not have.
     */
    private function appOnlyEmployeeIds(Door $door): Collection
    {
        $onDevice = DevicePersonState::query()->where('door_id', $door->id)->where('present_on_device', true)->whereNotNull('employee_id')->pluck('employee_id');

        return $door->employees()->pluck('employees.id')->diff($onDevice)->values();
    }

    // ------------------------------------------------------------------------------------
    // Helpers
    // ------------------------------------------------------------------------------------

    public static function normalizeId(mixed $value): string
    {
        $value = strtoupper(preg_replace('/\s+/', '', trim((string) $value)));
        if ($value !== '' && ctype_digit($value)) {
            $value = ltrim($value, '0') ?: '0';
        }

        return $value;
    }

    public static function normalizeName(mixed $value): string
    {
        $value = mb_strtolower(trim(preg_replace('/\s+/', ' ', (string) $value)));

        return self::isMissingName($value) ? '' : $value;
    }

    public static function isMissingName(mixed $value): bool
    {
        return in_array(trim((string) $value), ['', '-', '--', 'NULL', 'null'], true);
    }

    public static function isUnverifiedNik(mixed $nik): bool
    {
        $nik = strtoupper(trim((string) $nik));

        return $nik === '' || $nik === '-' || str_starts_with($nik, 'UNVERIFIED');
    }

    public static function namesAgree(string $deviceName, string $appName): bool
    {
        $a = self::normalizeName($deviceName);
        $b = self::normalizeName($appName);
        if ($a === '' || $b === '') {
            return false;
        }
        if ($a === $b || str_contains($a, $b) || str_contains($b, $a)) {
            return true;
        }
        similar_text($a, $b, $percent);

        return $percent >= 80;
    }

    public static function appRecordsCard(Employee $employee): bool
    {
        return (bool) $employee->card_registered
            || trim((string) $employee->card_no) !== ''
            || (bool) optional($employee->biometricStatus)->card_enrolled
            || $employee->credential_method === 'card';
    }

    public static function appRecordsFingerprint(Employee $employee): bool
    {
        return (bool) optional($employee->biometricStatus)->fingerprint_enrolled
            || $employee->credential_method === 'fingerprint';
    }

    private static function appStatus(Employee $employee): string
    {
        $status = strtoupper((string) $employee->employment_status);

        return in_array($status, ['', 'ACTIVE'], true) ? 'Aktif' : $status;
    }

    private static function deviceStatusLabel(array $doors): string
    {
        $statuses = array_column($doors, 'status');
        if ($statuses === []) {
            return 'Belum ada akses perangkat';
        }
        $present = count(array_diff($statuses, [self::APP_ONLY, self::UNVERIFIED, self::DEVICE_UNREACHABLE]));
        if (in_array(self::DEVICE_UNREACHABLE, $statuses, true) && $present === 0) {
            return 'Perangkat tidak terjangkau';
        }
        if (array_unique($statuses) === [self::UNVERIFIED]) {
            return 'Belum diverifikasi';
        }

        return "Ada di {$present}/" . count($statuses) . ' perangkat';
    }
}
