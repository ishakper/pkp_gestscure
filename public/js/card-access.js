/* ==========================================================================
   PKP SecureGate — Card Access & NFC Provisioning
   Phase 1 / 1.5: design foundation, UX flows, state model.
   --------------------------------------------------------------------------
   NOT INTEGRATED. Every backend call goes through an adapter:

     - ContractPendingAdapter (default): rejects every call with
       CONTRACT_PENDING until ISHAK locks the backend contract.
     - PreviewAdapter: clearly-labelled sample fixtures, used only when
       APP_CONFIG.cardAccessPreview is true (local/testing) or in the
       standalone design prototype. Fixtures contain only masked card
       identifiers and fictional employees.

   Nothing in this file writes to the backend, talks to Hikvision, or reads
   an NFC tag. Mutating actions in preview mode are simulated in memory.
   ========================================================================== */
(function (global) {
    'use strict';

    // ======================================================================
    // 1. Utilities
    // ======================================================================
    function esc(value) {
        if (value === null || value === undefined) return '';
        return String(value)
            .replace(/&/g, '&amp;')
            .replace(/</g, '&lt;')
            .replace(/>/g, '&gt;')
            .replace(/"/g, '&quot;')
            .replace(/'/g, '&#39;');
    }

    // Card identifiers are always shown masked. Backend is expected to send a
    // masked value (CredentialRecord::masked_identifier); this is a second guard.
    function maskId(value) {
        if (!value) return '—';
        const clean = String(value).replace(/[^0-9A-Za-z]/g, '');
        if (!clean) return '—';
        return '••••' + clean.slice(-4).toUpperCase();
    }

    const dateFmt = (typeof Intl !== 'undefined')
        ? new Intl.DateTimeFormat('id-ID', { day: '2-digit', month: 'short', year: 'numeric', hour: '2-digit', minute: '2-digit', timeZone: 'Asia/Jakarta' })
        : null;
    const dayFmt = (typeof Intl !== 'undefined')
        ? new Intl.DateTimeFormat('id-ID', { day: '2-digit', month: 'short', year: 'numeric', timeZone: 'Asia/Jakarta' })
        : null;

    function fmtDateTime(iso) {
        if (!iso) return '—';
        const d = new Date(iso);
        if (isNaN(d.getTime()) || !dateFmt) return esc(iso);
        return esc(dateFmt.format(d).replace('.', ':')) + ' WIB';
    }
    function fmtDate(iso) {
        if (!iso) return '—';
        const d = new Date(iso);
        if (isNaN(d.getTime()) || !dayFmt) return esc(iso);
        return esc(dayFmt.format(d));
    }
    function list(items, empty) {
        if (!items || !items.length) return '<span class="ca-dim">' + esc(empty || 'Tidak ada') + '</span>';
        return items.map(esc).join(', ');
    }

    // ======================================================================
    // 2. State model
    //    Four domains are never merged into one "Active" flag:
    //    EMPLOYEE IDENTITY → APP CREDENTIAL → ACCESS PERMISSION → DEVICE STATE
    // ======================================================================
    const TONE_BADGE = {
        success: 'badge-success',
        attention: 'badge-warning',
        critical: 'badge-danger',
        neutral: 'badge-dim',
        info: 'badge-info',
    };

    // Record-level lifecycle state (backend-owned).
    const LIFECYCLE = {
        ACTIVE_SYNCED:       { label: 'Active · Synced',      tone: 'success',   hint: 'Kredensial aktif dan sesuai dengan perangkat Hikvision.' },
        ACTIVE_NOT_SYNCED:   { label: 'Active · Not Synced',  tone: 'attention', hint: 'Data aplikasi ada, tetapi kondisi fisik di perangkat berbeda atau belum tersinkron.' },
        CARD_NOT_REGISTERED: { label: 'Card Not Registered',  tone: 'neutral',   hint: 'Karyawan ada, tetapi belum memiliki kredensial aktif.' },
        NEEDS_VERIFICATION:  { label: 'Needs Verification',   tone: 'attention', hint: 'Identitas, kredensial, atau akses masih ambigu. Jangan dianggap aktif.' },
        DISABLED:            { label: 'Disabled',             tone: 'neutral',   hint: 'Kredensial dinonaktifkan sementara.' },
        REVOKED:             { label: 'Revoked',              tone: 'critical',  hint: 'Akses fisik dicabut secara sengaja.' },
    };

    // Application credential status (domain B).
    const CREDENTIAL = {
        ACTIVE:   { label: 'Active',   tone: 'success' },
        DISABLED: { label: 'Disabled', tone: 'neutral' },
        REVOKED:  { label: 'Revoked',  tone: 'critical' },
        PENDING:  { label: 'Pending',  tone: 'attention' },
        NONE:     { label: 'No Card',  tone: 'neutral' },
    };

    // Device synchronisation status (domain D, per record).
    const SYNC = {
        VERIFIED:           { label: 'Verified',           tone: 'success' },
        SYNCING:            { label: 'Syncing',            tone: 'info' },
        PENDING:            { label: 'Pending',            tone: 'attention' },
        FAILED:             { label: 'Failed',             tone: 'critical' },
        OUT_OF_SYNC:        { label: 'Out of Sync',        tone: 'critical' },
        NEEDS_VERIFICATION: { label: 'Needs Verification', tone: 'attention' },
        NOT_APPLICABLE:     { label: 'N/A',                tone: 'neutral' },
    };

    // Overall verification result (shown on detail + verification component).
    const VERIFICATION = {
        VERIFIED:           { label: 'Verified',           tone: 'success' },
        OUT_OF_SYNC:        { label: 'Out of Sync',        tone: 'critical' },
        NEEDS_VERIFICATION: { label: 'Needs Verification', tone: 'attention' },
        DISABLED:           { label: 'Disabled',           tone: 'neutral' },
        REVOKED:            { label: 'Revoked',            tone: 'critical' },
        NOT_VERIFIED:       { label: 'Not Verified',       tone: 'neutral' },
    };

    // Per-field device comparison.
    const MATCH = {
        MATCH:    { label: 'Match',    tone: 'success',  mark: '✓', cls: 'ok' },
        MISMATCH: { label: 'Mismatch', tone: 'critical', mark: '✕', cls: 'fail' },
        MISSING:  { label: 'Missing on device', tone: 'critical', mark: '✕', cls: 'fail' },
        UNKNOWN:  { label: 'Unknown',  tone: 'neutral',  mark: '?', cls: 'unknown' },
        PENDING:  { label: 'Pending',  tone: 'attention', mark: '…', cls: 'pending' },
    };

    const DEVICE_HEALTH = {
        HEALTHY:            { label: 'Healthy',            tone: 'success' },
        WARNING:            { label: 'Warning',            tone: 'attention' },
        OFFLINE:            { label: 'Offline',            tone: 'neutral' },
        SYNC_FAILED:        { label: 'Sync Failed',        tone: 'critical' },
        NEEDS_VERIFICATION: { label: 'Needs Verification', tone: 'attention' },
    };

    const ACTIVITY = {
        CARD_REGISTERED:  { label: 'Card Registered',  tone: 'info' },
        ACCESS_ASSIGNED:  { label: 'Access Assigned',  tone: 'info' },
        ACCESS_CHANGED:   { label: 'Access Changed',   tone: 'info' },
        CARD_REPLACED:    { label: 'Card Replaced',    tone: 'info' },
        CARD_DISABLED:    { label: 'Card Disabled',    tone: 'neutral' },
        CARD_REVOKED:     { label: 'Card Revoked',     tone: 'critical' },
        SYNC_STARTED:     { label: 'Sync Started',     tone: 'info' },
        SYNC_COMPLETED:   { label: 'Sync Completed',   tone: 'success' },
        SYNC_FAILED:      { label: 'Sync Failed',      tone: 'critical' },
        DEVICE_VERIFIED:  { label: 'Device Verified',  tone: 'success' },
    };

    function badge(map, key, extraTitle) {
        const meta = map[key] || { label: key ? String(key).replace(/_/g, ' ') : 'Unknown', tone: 'neutral' };
        const title = extraTitle || meta.hint || '';
        return '<span class="badge ca-badge ' + TONE_BADGE[meta.tone] + '"' + (title ? ' title="' + esc(title) + '"' : '') +
            '><span class="badge-dot"></span>' + esc(meta.label) + '</span>';
    }
    const AccessStatusBadge = (k, t) => badge(LIFECYCLE, k, t);
    const CardStatusBadge = (k) => badge(CREDENTIAL, k || 'NONE');
    const SyncStatusBadge = (k) => badge(SYNC, k);
    const VerificationStatusBadge = (k) => badge(VERIFICATION, k);

    // Display guard. The backend owns the lifecycle state; the UI only refuses
    // to *display* a healthy state when the record's own device comparison
    // contradicts it. This never feeds back into data or KPIs.
    function resolveDisplayState(rec) {
        const declared = rec.lifecycle_state;
        if (!LIFECYCLE[declared]) return { state: 'NEEDS_VERIFICATION', guarded: true, reason: 'Status dari backend tidak dikenal.' };
        if (declared !== 'ACTIVE_SYNCED') return { state: declared, guarded: false };
        const d = rec.device || {};
        const matches = [d.person_match, d.credential_match, d.access_match];
        if (matches.some(m => m === 'MISMATCH' || m === 'MISSING')) {
            return { state: 'ACTIVE_NOT_SYNCED', guarded: true, reason: 'Perbandingan perangkat menunjukkan perbedaan.' };
        }
        if (matches.some(m => m !== 'MATCH') || rec.verification !== 'VERIFIED') {
            return { state: 'NEEDS_VERIFICATION', guarded: true, reason: 'Kondisi perangkat belum terverifikasi.' };
        }
        return { state: 'ACTIVE_SYNCED', guarded: false };
    }

    function collectMismatches(rec) {
        const d = rec.device || {};
        const out = [];
        if (d.credential_match === 'MISMATCH' || d.credential_match === 'MISSING') {
            out.push({ kind: 'CARD', title: 'Card Mismatch', appLabel: 'App Card', deviceLabel: 'Device Card',
                app: rec.credential ? maskId(rec.credential.masked_identifier) : '—',
                device: d.device_card_masked ? maskId(d.device_card_masked) : 'Tidak ada di perangkat',
                note: 'Nomor kartu di SecureGate berbeda dengan yang tersimpan di terminal Hikvision.' });
        }
        if (d.access_match === 'MISMATCH' || d.access_match === 'MISSING') {
            out.push({ kind: 'ACCESS', title: 'Access Mismatch', appLabel: 'App Access', deviceLabel: 'Device Access',
                app: (rec.access && rec.access.buildings.length) ? rec.access.buildings.join(' + ') : 'Tidak ada',
                device: (d.device_access && d.device_access.length) ? d.device_access.join(' + ') : 'Tidak ada',
                note: 'Hak akses di perangkat tidak sama dengan hak akses yang disetujui di SecureGate.' });
        }
        if (d.person_match === 'MISMATCH' || d.person_match === 'MISSING') {
            out.push({ kind: 'PERSON', title: 'Person Mismatch', appLabel: 'App Person', deviceLabel: 'Device Person',
                app: rec.employee.name + ' (' + rec.employee.employee_code + ')',
                device: d.device_person || 'Tidak ditemukan di perangkat',
                note: 'Identitas orang di perangkat tidak cocok dengan karyawan di SecureGate. Perlu verifikasi manual.' });
        }
        return out;
    }

    // ======================================================================
    // 3. Frontend authorization UX (visibility only — backend still enforces)
    // ======================================================================
    // Provisional mapping onto permissions already issued by PortalAccess.
    // To be confirmed in the RBAC contract.
    const CAPABILITY_PERMISSION = {
        CAN_VIEW: 'credential.view',
        CAN_EDIT_ACCESS: 'access.manage',
        CAN_PROVISION_CARD: 'credential.manage',
        CAN_SYNC: 'credential.sync',
        CAN_REVOKE: 'credential.revoke',
        CAN_VIEW_AUDIT: 'audit.view',
    };

    function makeCan(permissions, role) {
        const set = new Set(permissions || []);
        return function can(cap) {
            if (role === 'super_admin') return true;
            const perm = CAPABILITY_PERMISSION[cap];
            return !!perm && set.has(perm);
        };
    }

    const ACTION_DEFS = {
        view:     { label: 'View Detail',        icon: '👁', cap: 'CAN_VIEW' },
        edit:     { label: 'Edit Access',        icon: '🔑', cap: 'CAN_EDIT_ACCESS' },
        enroll:   { label: 'Daftarkan Kartu NFC', icon: '📶', cap: 'CAN_PROVISION_CARD' },
        replace:  { label: 'Replace Card',       icon: '🔁', cap: 'CAN_PROVISION_CARD' },
        resync:   { label: 'Re-Sync',            icon: '⚡', cap: 'CAN_SYNC' },
        verify:   { label: 'Verify on Device',   icon: '✔', cap: 'CAN_SYNC' },
        disable:  { label: 'Disable Card',       icon: '⏸', cap: 'CAN_PROVISION_CARD' },
        revoke:   { label: 'Revoke Card',        icon: '⛔', cap: 'CAN_REVOKE', danger: true },
        audit:    { label: 'View Audit History', icon: '🛡', cap: 'CAN_VIEW_AUDIT' },
    };

    // Which actions make sense for a lifecycle state. Backend may later send
    // rec.allowed_actions; when present it is intersected with this list.
    function actionsFor(rec, can) {
        const s = rec.lifecycle_state;
        const base = ['view'];
        if (s === 'CARD_NOT_REGISTERED') base.push('enroll', 'edit');
        else if (s === 'REVOKED') base.push('resync');
        else {
            base.push('edit', 'replace', 'resync', 'verify');
            if (s !== 'DISABLED') base.push('disable');
            base.push('revoke');
        }
        base.push('audit');
        const allowed = Array.isArray(rec.allowed_actions) ? new Set(rec.allowed_actions.concat(['view'])) : null;
        return base.map(key => {
            const def = ACTION_DEFS[key];
            let deniedReason = null;
            if (!can(def.cap)) deniedReason = 'Tidak memiliki izin (' + def.cap + ')';
            else if (allowed && !allowed.has(key)) deniedReason = 'Tidak diizinkan oleh kebijakan backend';
            return { key, def, deniedReason };
        });
    }

    // ======================================================================
    // 4. Adapters
    // ======================================================================
    class ContractPendingError extends Error {
        constructor(method) {
            super('Backend contract for "' + method + '" is not locked yet.');
            this.code = 'CONTRACT_PENDING';
            this.method = method;
        }
    }

    // Default adapter: integration is intentionally blocked until the Backend
    // Contract Lock. Method list = the contract surface the UI needs.
    const ADAPTER_METHODS = [
        'getKpis', 'getRecentActivity', 'getSyncHealth', 'getAttentionQueue',
        'listCards', 'getEmployeeAccess', 'getAccessCatalog', 'getAccessAssignment', 'previewAccessChange', 'applyAccessChange',
        'listDeviceSync', 'getDiagnostics', 'getAuditHistory',
        'searchEnrollmentCandidates', 'validateScannedCard', 'registerCard',
        'replaceCard', 'disableCard', 'revokeCard', 'retrySync', 'verifyOnDevice',
    ];
    const ContractPendingAdapter = { mode: 'contract-pending' };
    ADAPTER_METHODS.forEach(m => {
        ContractPendingAdapter[m] = function () { return Promise.reject(new ContractPendingError(m)); };
    });

    // ---- Preview fixtures: fictional, masked, explicitly labelled ----
    function createPreviewAdapter() {
        const BUILDINGS = ['Gedung A', 'Gedung B', 'Gedung C', 'Gedung D'];
        const DOORS = {
            'Gedung A': ['A-01 Main Entrance', 'A-02 Office Door', 'A-03 Restricted Room'],
            'Gedung B': ['B-01 Main Entrance', 'B-02 Office Door', 'B-03 Server Room'],
            'Gedung C': ['C-01 Main Entrance', 'C-02 Office Door'],
            'Gedung D': ['D-01 Main Entrance', 'D-02 Warehouse'],
        };
        const PROFILES = ['Staf Standar', 'Infra Teknis', 'Area Terbatas'];

        function emp(n, building, extra) {
            const code = 'CONTOH-' + String(n).padStart(3, '0');
            return Object.assign({ id: n, name: 'Karyawan Contoh ' + String(n).padStart(2, '0'), employee_code: code,
                employment_status: 'ACTIVE', building, department: 'Departemen Contoh', position: 'Staf' }, extra || {});
        }
        function cred(last4, status, origin, at) {
            return { masked_identifier: '••••' + last4, credential_type: 'CARD', status, origin,
                registered_at: at || '2026-09-12T02:15:00Z', registered_by: origin === 'NFC_ENROLLMENT' ? 'Operator Infra (contoh)' : null };
        }
        function acc(buildings, doors, profile) {
            return { buildings, doors, profile: profile || 'Staf Standar', valid_from: '2026-09-01', valid_until: null };
        }
        function dev(o) {
            return Object.assign({ person_match: 'MATCH', credential_match: 'MATCH', access_match: 'MATCH', device_card_masked: null,
                device_access: [], device_person: null, last_sync_at: '2026-10-07T01:40:00Z', last_verified_at: '2026-10-07T01:51:00Z',
                device_status: 'ONLINE', sync_status: 'VERIFIED', error: null }, o);
        }

        const records = [
            { employee: emp(1, 'Gedung B'), credential: cred('3163', 'ACTIVE', 'LEGACY'), access: acc(['Gedung B'], ['B-01 Main Entrance', 'B-02 Office Door']),
              device: dev({ device_card_masked: '3163', device_access: ['Gedung B'] }), lifecycle_state: 'ACTIVE_SYNCED', verification: 'VERIFIED' },
            { employee: emp(2, 'Gedung A'), credential: cred('5520', 'ACTIVE', 'LEGACY'), access: acc(['Gedung A'], ['A-01 Main Entrance']),
              device: dev({ credential_match: 'MISMATCH', device_card_masked: '9042', device_access: ['Gedung A'], sync_status: 'OUT_OF_SYNC', last_verified_at: '2026-10-06T08:10:00Z' }),
              lifecycle_state: 'ACTIVE_NOT_SYNCED', verification: 'OUT_OF_SYNC' },
            { employee: emp(3, 'Gedung B'), credential: cred('7710', 'ACTIVE', 'NFC_ENROLLMENT'), access: acc(['Gedung B'], ['B-01 Main Entrance', 'B-02 Office Door']),
              device: dev({ access_match: 'MISMATCH', device_card_masked: '7710', device_access: ['Gedung A', 'Gedung B'], sync_status: 'OUT_OF_SYNC' }),
              lifecycle_state: 'ACTIVE_NOT_SYNCED', verification: 'OUT_OF_SYNC' },
            { employee: emp(4, 'Gedung C'), credential: null, access: acc([], [], null),
              device: dev({ person_match: 'UNKNOWN', credential_match: 'UNKNOWN', access_match: 'UNKNOWN', last_sync_at: null, last_verified_at: null, sync_status: 'NOT_APPLICABLE' }),
              lifecycle_state: 'CARD_NOT_REGISTERED', verification: 'NOT_VERIFIED' },
            { employee: emp(5, 'Gedung D'), credential: cred('2208', 'ACTIVE', 'LEGACY'), access: acc(['Gedung D'], ['D-01 Main Entrance']),
              device: dev({ person_match: 'MISMATCH', device_person: 'CONTOH5 (nama di terminal)', device_card_masked: '2208', device_access: ['Gedung D'], sync_status: 'NEEDS_VERIFICATION', last_verified_at: null }),
              lifecycle_state: 'NEEDS_VERIFICATION', verification: 'NEEDS_VERIFICATION' },
            { employee: emp(6, 'Gedung A'), credential: cred('4471', 'DISABLED', 'LEGACY'), access: acc(['Gedung A'], ['A-01 Main Entrance', 'A-02 Office Door']),
              device: dev({ device_card_masked: '4471', device_access: [], sync_status: 'VERIFIED' }), lifecycle_state: 'DISABLED', verification: 'DISABLED' },
            { employee: emp(7, 'Gedung B', { employment_status: 'RESIGNED' }), credential: cred('8810', 'REVOKED', 'NFC_ENROLLMENT'), access: acc([], []),
              device: dev({ person_match: 'MISSING', credential_match: 'MISSING', access_match: 'MATCH', device_card_masked: null, sync_status: 'VERIFIED' }),
              lifecycle_state: 'REVOKED', verification: 'REVOKED' },
            // Backend says ACTIVE_SYNCED, but device is offline and unverified → display guard downgrades it.
            { employee: emp(8, 'Gedung C'), credential: cred('6034', 'ACTIVE', 'LEGACY'), access: acc(['Gedung C'], ['C-01 Main Entrance']),
              device: dev({ credential_match: 'UNKNOWN', access_match: 'UNKNOWN', person_match: 'UNKNOWN', device_status: 'OFFLINE', sync_status: 'NEEDS_VERIFICATION', last_verified_at: '2026-09-28T03:00:00Z' }),
              lifecycle_state: 'ACTIVE_SYNCED', verification: 'NEEDS_VERIFICATION' },
            { employee: emp(9, 'Gedung D'), credential: cred('1199', 'ACTIVE', 'NFC_ENROLLMENT', '2026-10-07T01:30:00Z'), access: acc(['Gedung D'], ['D-01 Main Entrance', 'D-02 Warehouse']),
              device: dev({ credential_match: 'PENDING', access_match: 'PENDING', person_match: 'PENDING', sync_status: 'PENDING', last_sync_at: null, last_verified_at: null }),
              lifecycle_state: 'ACTIVE_NOT_SYNCED', verification: 'NOT_VERIFIED' },
            { employee: emp(10, 'Gedung B'), credential: cred('A82F', 'ACTIVE', 'NFC_ENROLLMENT', '2026-10-07T00:55:00Z'), access: acc(['Gedung B', 'Gedung C'], ['B-01 Main Entrance', 'C-01 Main Entrance', 'C-02 Office Door']),
              device: dev({ credential_match: 'MISSING', access_match: 'MISSING', person_match: 'MATCH', sync_status: 'FAILED', last_verified_at: null,
                  error: { code: 'DEVICE_HTTP_503', summary: 'Terminal C-01 menolak permintaan (HTTP 503). Kredensial belum tertulis di perangkat.', door: 'C-01 Main Entrance', attempts: 3, last_attempt_at: '2026-10-07T01:05:00Z' } }),
              lifecycle_state: 'ACTIVE_NOT_SYNCED', verification: 'OUT_OF_SYNC' },
        ];
        const candidates = [
            { employee: emp(4, 'Gedung C'), card_state: 'CARD_NOT_REGISTERED', eligibility: { eligible: true } },
            { employee: emp(12, 'Gedung A'), card_state: 'CARD_NOT_REGISTERED', eligibility: { eligible: true } },
            { employee: emp(1, 'Gedung B'), card_state: 'ACTIVE_SYNCED', eligibility: { eligible: false, code: 'HAS_ACTIVE_CARD', reason: 'Karyawan sudah memiliki kartu aktif. Gunakan alur Replace Card.' } },
            { employee: emp(11, 'Gedung B', { employment_status: 'INACTIVE' }), card_state: 'CARD_NOT_REGISTERED', eligibility: { eligible: false, code: 'EMPLOYEE_INACTIVE', reason: 'Status kepegawaian tidak aktif menurut backend.' } },
        ];
        const delay = (v, ms) => new Promise(r => setTimeout(() => r(JSON.parse(JSON.stringify(v))), ms || 250));
        const find = id => records.find(r => r.employee.id === Number(id));

        return {
            mode: 'preview',
            getKpis: () => delay({ total_cards: 9, active_cards: 6, pending_sync: 1, sync_failed: 1, needs_verification: 2, disabled_cards: 1, as_of: '2026-10-07T01:55:00Z', source: 'preview-fixture' }, 500),
            getSyncHealth: () => delay({ items: [
                { key: 'HEALTHY', count: 3 }, { key: 'WARNING', count: 1 }, { key: 'OFFLINE', count: 1 },
                { key: 'SYNC_FAILED', count: 1 }, { key: 'NEEDS_VERIFICATION', count: 2 } ], as_of: '2026-10-07T01:55:00Z' }, 400),
            getRecentActivity: () => delay([
                { at: '2026-10-07T01:51:00Z', type: 'DEVICE_VERIFIED', employee: 'Karyawan Contoh 01', operator: 'Sistem', target: 'B-01 Main Entrance', result: 'Verified' },
                { at: '2026-10-07T01:30:00Z', type: 'CARD_REGISTERED', employee: 'Karyawan Contoh 09', operator: 'Operator Infra (contoh)', target: '••••1199', result: 'Menunggu sinkronisasi' },
                { at: '2026-10-07T01:05:00Z', type: 'SYNC_FAILED', employee: 'Karyawan Contoh 10', operator: 'Sistem', target: 'C-01 Main Entrance', result: 'HTTP 503' },
                { at: '2026-10-07T00:55:00Z', type: 'ACCESS_ASSIGNED', employee: 'Karyawan Contoh 10', operator: 'Operator Infra (contoh)', target: 'Gedung B + C', result: 'Tersimpan' },
                { at: '2026-10-06T08:10:00Z', type: 'SYNC_COMPLETED', employee: 'Karyawan Contoh 02', operator: 'Sistem', target: 'A-01 Main Entrance', result: 'Kartu berbeda terdeteksi' },
                { at: '2026-10-06T04:20:00Z', type: 'CARD_DISABLED', employee: 'Karyawan Contoh 06', operator: 'Admin Infra (contoh)', target: '••••4471', result: 'Disabled' },
                { at: '2026-10-05T09:00:00Z', type: 'CARD_REVOKED', employee: 'Karyawan Contoh 07', operator: 'Admin Infra (contoh)', target: '••••8810', result: 'Dicabut dari 2 pintu' },
            ], 450),
            getAttentionQueue: () => delay(records.filter(r => ['ACTIVE_NOT_SYNCED', 'NEEDS_VERIFICATION'].includes(resolveDisplayState(r).state)), 450),
            listCards: (q) => {
                q = q || {};
                const s = (q.search || '').trim().toLowerCase();
                let items = records.filter(r => {
                    if (s && !(r.employee.name.toLowerCase().includes(s) || r.employee.employee_code.toLowerCase().includes(s) ||
                        (r.credential && r.credential.masked_identifier.toLowerCase().includes(s.replace(/[^0-9a-z]/g, ''))))) return false;
                    if (q.building && r.employee.building !== q.building) return false;
                    if (q.cardStatus && resolveDisplayState(r).state !== q.cardStatus) return false;
                    if (q.syncStatus && r.device.sync_status !== q.syncStatus) return false;
                    if (q.verification && r.verification !== q.verification) return false;
                    return true;
                });
                const perPage = q.perPage || 25;
                const page = q.page || 1;
                const total = items.length;
                items = items.slice((page - 1) * perPage, page * perPage);
                return delay({ items, meta: { page, per_page: perPage, total } }, 450);
            },
            getEmployeeAccess: (id) => delay(find(id), 350),
            getAccessCatalog: () => delay({ buildings: BUILDINGS, doors: DOORS, profiles: PROFILES }, 200),
            getAccessAssignment: (id) => delay(find(id) ? find(id).access : null, 250),
            previewAccessChange: (id, draft) => {
                const cur = find(id).access;
                const added = draft.doors.filter(d => !cur.doors.includes(d));
                const removed = cur.doors.filter(d => !draft.doors.includes(d));
                return delay({ current: cur, next: draft, added, removed }, 300);
            },
            listDeviceSync: (q) => delay(records.filter(r => r.credential && (!q || !q.status || r.device.sync_status === q.status)), 450),
            getDiagnostics: (id) => delay(find(id), 300),
            getAuditHistory: (id) => {
                const r = find(id);
                if (!r || !r.credential) return delay([], 300);
                const out = [{ at: r.credential.registered_at, title: r.credential.origin === 'LEGACY' ? 'Kartu tercatat (data existing)' : 'Card Registered via NFC', by: r.credential.registered_by || 'Data existing', tone: 'success' }];
                if (r.device.sync_status === 'FAILED') out.unshift({ at: r.device.error.last_attempt_at, title: 'Sync Failed · ' + r.device.error.code, by: 'Sistem', tone: 'critical' });
                if (r.lifecycle_state === 'DISABLED') out.unshift({ at: '2026-10-06T04:20:00Z', title: 'Card Disabled', by: 'Admin Infra (contoh)', tone: 'attention' });
                if (r.lifecycle_state === 'REVOKED') out.unshift({ at: '2026-10-05T09:00:00Z', title: 'Card Revoked · Resign', by: 'Admin Infra (contoh)', tone: 'critical' });
                if (r.device.last_verified_at) out.unshift({ at: r.device.last_verified_at, title: 'Device Verified', by: 'Sistem', tone: 'success' });
                return delay(out, 300);
            },
            searchEnrollmentCandidates: (term) => {
                const s = (term || '').toLowerCase();
                return delay(candidates.filter(c => !s || c.employee.name.toLowerCase().includes(s) || c.employee.employee_code.toLowerCase().includes(s)), 300);
            },
            // Preview-only: outcome chosen by the reviewer via the scenario picker.
            validateScannedCard: (empId, scenario) => {
                const results = {
                    READY: { status: 'READY', masked_identifier: '••••A82F' },
                    DUPLICATE: { status: 'CARD_ALREADY_REGISTERED', masked_identifier: '••••3163', owner_hint: 'Terdaftar atas karyawan lain (detail disembunyikan)' },
                    NEEDS_VERIFICATION: { status: 'CARD_REQUIRES_VERIFICATION', masked_identifier: '••••9042', note: 'Kartu ini ditemukan di terminal Hikvision tetapi tidak tercatat di SecureGate.' },
                };
                return delay(results[scenario] || results.READY, 900);
            },
            // Simulated mutations: resolve without changing anything.
            applyAccessChange: () => delay({ simulated: true, saved: true, sync: 'PENDING' }, 600),
            disableCard: () => delay({ simulated: true }, 500),
            revokeCard: () => delay({ simulated: true }, 500),
            retrySync: () => delay({ simulated: true, sync: 'SYNCING' }, 500),
            verifyOnDevice: () => delay({ simulated: true }, 500),
            registerCard: () => delay({ simulated: true }, 500),
            replaceCard: () => delay({ simulated: true }, 500),
        };
    }

    // ======================================================================
    // 5. Catalogues: errors, empty states
    // ======================================================================
    // Each error states what failed, what remains saved, and the safe next action.
    const ERRORS = {
        NETWORK_UNAVAILABLE: { icon: '📡', tone: 'critical', title: 'Network Unavailable', failed: 'Perangkat ini tidak terhubung ke jaringan.', saved: 'Tidak ada perubahan yang dikirim. Data di SecureGate tetap seperti sebelumnya.', actions: ['Coba lagi setelah koneksi pulih'] },
        API_UNAVAILABLE: { icon: '🛰', tone: 'critical', title: 'API Unavailable', failed: 'Server SecureGate tidak merespons.', saved: 'Perubahan belum tersimpan. Isian formulir tetap di layar.', actions: ['Coba lagi', 'Hubungi admin sistem bila berlanjut'] },
        HIKVISION_OFFLINE: { icon: '🔌', tone: 'attention', title: 'Hikvision Offline', failed: 'Terminal pintu tidak dapat dihubungi.', saved: 'Data kredensial dan hak akses tetap tersimpan di SecureGate; perangkat belum diperbarui.', actions: ['Retry Sync saat terminal online', 'Lihat Status Sistem'] },
        NFC_UNSUPPORTED: { icon: '📵', tone: 'attention', title: 'NFC Unsupported', failed: 'Perangkat atau browser ini tidak mendukung pembacaan NFC.', saved: 'Belum ada data yang dibuat.', actions: ['Gunakan perangkat Android dengan Chrome yang mendukung NFC'] },
        NFC_DISABLED: { icon: '📴', tone: 'attention', title: 'NFC Disabled', failed: 'NFC dimatikan di pengaturan perangkat atau izin ditolak.', saved: 'Belum ada data yang dibuat.', actions: ['Aktifkan NFC di Pengaturan Android lalu coba lagi'] },
        CARD_UNREADABLE: { icon: '💳', tone: 'attention', title: 'Card Unreadable', failed: 'Kartu terdeteksi tetapi nomornya tidak dapat dibaca.', saved: 'Belum ada data yang dibuat.', actions: ['Tempelkan kartu lebih lama di bagian belakang ponsel', 'Coba kartu lain bila rusak'] },
        DUPLICATE_CARD: { icon: '⧉', tone: 'critical', title: 'Card Already Registered', failed: 'Kartu ini sudah terdaftar atas karyawan lain.', saved: 'Tidak ada perubahan. Data pemilik kartu yang ada tidak diubah.', actions: ['Gunakan kartu lain', 'Laporkan ke Admin Infra untuk investigasi'] },
        EMPLOYEE_INACTIVE: { icon: '🚫', tone: 'critical', title: 'Employee Inactive', failed: 'Status kepegawaian karyawan tidak aktif.', saved: 'Tidak ada perubahan.', actions: ['Periksa data kepegawaian di menu Pengguna'] },
        EMPLOYEE_NOT_ELIGIBLE: { icon: '🚫', tone: 'critical', title: 'Employee Not Eligible', failed: 'Backend menolak pendaftaran kartu untuk karyawan ini.', saved: 'Tidak ada perubahan.', actions: ['Baca alasan dari backend', 'Pilih karyawan lain'] },
        PERMISSION_DENIED: { icon: '🔒', tone: 'neutral', title: 'Permission Denied', failed: 'Akun Anda tidak memiliki izin untuk tindakan ini.', saved: 'Tidak ada perubahan.', actions: ['Minta akses ke Super Admin'] },
        CARD_MISMATCH: { icon: '≠', tone: 'critical', title: 'Card Mismatch', failed: 'Kartu di perangkat berbeda dengan kartu di SecureGate.', saved: 'Data SecureGate tidak diubah otomatis.', actions: ['Re-Sync untuk menulis ulang kartu ke perangkat', 'Verify bila tidak yakin data mana yang benar'] },
        DEVICE_MISMATCH: { icon: '≠', tone: 'critical', title: 'Device Mismatch', failed: 'Orang atau hak akses di perangkat berbeda dengan SecureGate.', saved: 'Data SecureGate tidak diubah otomatis.', actions: ['Verify identitas', 'Re-Sync setelah dikonfirmasi'] },
        PARTIAL_SYNC: { icon: '◐', tone: 'attention', title: 'Partial Synchronization', failed: 'Sebagian terminal berhasil diperbarui, sebagian gagal.', saved: 'Kredensial dan hak akses tersimpan di SecureGate. Terminal yang gagal belum diperbarui.', actions: ['Retry Sync untuk terminal yang gagal', 'View Diagnostic'] },
        VERIFICATION_TIMEOUT: { icon: '⏱', tone: 'attention', title: 'Verification Timeout', failed: 'Perangkat tidak mengirim konfirmasi dalam batas waktu.', saved: 'Kredensial tersimpan dan perintah sinkronisasi terkirim, tetapi belum terverifikasi.', actions: ['Verify lagi', 'Jangan anggap akses fisik aktif sebelum terverifikasi'] },
        CONTRACT_PENDING: { icon: '🧩', tone: 'info', title: 'Menunggu Backend Contract Lock', failed: 'Endpoint untuk bagian ini belum difinalkan oleh tim backend.', saved: 'Tidak ada data yang dibaca atau diubah.', actions: ['Tampilan akan aktif setelah kontrak API dikunci'] },
    };

    function ErrorState(key, extra) {
        const e = ERRORS[key] || ERRORS.API_UNAVAILABLE;
        const actions = (extra && extra.actionsHtml) || '';
        return '<div class="ca-alert tone-' + e.tone + '" role="alert">' +
            '<div class="ca-alert-icon" aria-hidden="true">' + e.icon + '</div><div style="min-width:0">' +
            '<div class="ca-alert-title">' + esc(e.title) + '</div>' +
            '<dl><dt>Yang gagal</dt><dd>' + esc((extra && extra.failed) || e.failed) + '</dd>' +
            '<dt>Yang tetap tersimpan</dt><dd>' + esc(e.saved) + '</dd>' +
            '<dt>Langkah aman</dt><dd>' + e.actions.map(esc).join(' · ') + '</dd></dl>' +
            (actions ? '<div class="ca-alert-actions">' + actions + '</div>' : '') +
            '</div></div>';
    }

    const EMPTY = {
        NO_CARDS: { title: 'Belum ada kartu', body: 'Belum ada kredensial kartu yang tercatat.' },
        NO_RESULTS: { title: 'Tidak ada karyawan yang cocok', body: 'Ubah kata kunci atau filter pencarian.' },
        NO_SYNC_FAILURES: { title: 'Tidak ada sinkronisasi gagal', body: 'Semua antrean sinkronisasi dalam kondisi baik.' },
        NO_ACTIVITY: { title: 'Belum ada aktivitas', body: 'Aktivitas kartu akan muncul di sini.' },
        NO_AUDIT: { title: 'Belum ada riwayat audit', body: 'Belum ada tindakan yang tercatat untuk kredensial ini.' },
        NO_ACCESS: { title: 'Belum ada akses', body: 'Karyawan ini belum memiliki hak akses gedung atau pintu.' },
    };
    function EmptyState(key, actionHtml) {
        const e = EMPTY[key];
        return '<div class="ca-empty"><div class="ca-empty-title">' + esc(e.title) + '</div><div>' + esc(e.body) + '</div>' + (actionHtml || '') + '</div>';
    }

    function PermissionDenied(capLabel) {
        return '<div class="ca-denied">🔒 Anda tidak memiliki izin <b>' + esc(capLabel) + '</b>. Tampilan disembunyikan; backend tetap memeriksa izin pada setiap permintaan.</div>';
    }

    // ======================================================================
    // 6. Components (pure render functions → HTML strings)
    // ======================================================================
    const KPI_DEFS = [
        { key: 'total_cards', label: 'Total Cards', tone: 'info', filter: {} },
        { key: 'active_cards', label: 'Active', tone: 'success', filter: { cardStatus: 'ACTIVE_SYNCED' } },
        { key: 'pending_sync', label: 'Pending Sync', tone: 'attention', filter: { syncStatus: 'PENDING' } },
        { key: 'sync_failed', label: 'Sync Failed', tone: 'critical', filter: { syncStatus: 'FAILED' } },
        { key: 'needs_verification', label: 'Needs Verification', tone: 'attention', filter: { cardStatus: 'NEEDS_VERIFICATION' } },
        { key: 'disabled_cards', label: 'Disabled', tone: 'neutral', filter: { cardStatus: 'DISABLED' } },
    ];
    function CardAccessKpi(kpis) {
        return '<div class="ca-kpi-grid">' + KPI_DEFS.map(def => {
            const loading = kpis === null;
            const value = loading ? '<span class="ca-skel lg"></span>' : (kpis[def.key] === undefined || kpis[def.key] === null ? '—' : esc(kpis[def.key]));
            return '<button type="button" class="ca-kpi tone-' + def.tone + '" data-ca-action="kpi-filter" data-filter=\'' + esc(JSON.stringify(def.filter)) + '\'>' +
                '<div class="metric-label">' + esc(def.label) + '</div><div class="metric-value">' + value + '</div></button>';
        }).join('') + '</div>' +
            (kpis && kpis.as_of ? '<div class="ca-dim" style="margin:-1.25rem 0 1.5rem">Per ' + fmtDateTime(kpis.as_of) + ' · sumber: endpoint KPI backend' + (kpis.source === 'preview-fixture' ? ' (data contoh)' : '') + '</div>' : '');
    }

    function RecentActivity(items) {
        if (items === null) return '<ul class="ca-activity">' + [1, 2, 3, 4].map(() => '<li><span class="ca-skel"></span><span class="ca-skel"></span><span></span></li>').join('') + '</ul>';
        if (!items.length) return EmptyState('NO_ACTIVITY');
        return '<ul class="ca-activity">' + items.map(a => {
            const meta = ACTIVITY[a.type] || { label: a.type, tone: 'neutral' };
            return '<li><time>' + fmtDateTime(a.at) + '</time><div style="min-width:0"><div class="ca-act-main">' + esc(meta.label) + ' · ' + esc(a.employee) + '</div>' +
                '<div class="ca-act-sub">Operator: ' + esc(a.operator) + ' · Target: ' + esc(a.target) + '</div></div>' +
                '<span class="ca-tone-' + meta.tone + '" style="font-size:0.75rem;font-weight:600">' + esc(a.result) + '</span></li>';
        }).join('') + '</ul>';
    }

    function SyncHealth(data) {
        if (data === null) return '<ul class="ca-health">' + [1, 2, 3].map(() => '<li><span class="ca-skel" style="width:40%"></span></li>').join('') + '</ul>';
        return '<ul class="ca-health">' + data.items.map(it => {
            const meta = DEVICE_HEALTH[it.key] || { label: it.key, tone: 'neutral' };
            return '<li><span>' + badge(DEVICE_HEALTH, it.key) + '</span><span class="ca-health-count ca-tone-' + meta.tone + '">' + esc(it.count) + '</span></li>';
        }).join('') + '</ul>';
    }

    function MismatchAlert(m) {
        return '<div class="ca-mismatch" data-kind="' + m.kind + '">' +
            '<div class="ca-mismatch-head"><span class="ca-mismatch-title">≠ ' + esc(m.title) + '</span><span class="badge ca-badge badge-danger">' + esc(m.kind) + '</span></div>' +
            '<div class="ca-compare"><div class="ca-compare-cell"><div class="ca-compare-label">' + esc(m.appLabel) + ' · SecureGate</div><div class="ca-compare-value">' + esc(m.app) + '</div></div>' +
            '<div class="ca-compare-ne" aria-label="tidak sama dengan">≠</div>' +
            '<div class="ca-compare-cell"><div class="ca-compare-label">' + esc(m.deviceLabel) + ' · Hikvision</div><div class="ca-compare-value">' + esc(m.device) + '</div></div></div>' +
            '<div class="ca-mismatch-note">' + esc(m.note) + '</div></div>';
    }

    function MismatchChips(rec) {
        const mm = collectMismatches(rec);
        if (!mm.length) return '';
        return '<div class="ca-badge-row" style="margin-top:0.3rem">' + mm.map(m => '<span class="badge ca-badge badge-danger">≠ ' + esc(m.kind) + '</span>').join('') + '</div>';
    }

    function CardActionMenu(rec, can) {
        const items = actionsFor(rec, can);
        return '<div class="ca-menu"><button type="button" class="ca-menu-btn" data-ca-action="menu" aria-haspopup="true" aria-label="Aksi untuk ' + esc(rec.employee.name) + '">⋯</button>' +
            '<div class="ca-menu-list" role="menu">' + items.map(it => {
                const sep = it.key === 'revoke' || it.key === 'audit' ? '<hr>' : '';
                return sep + '<button type="button" role="menuitem" data-ca-action="' + it.key + '" data-id="' + rec.employee.id + '"' +
                    (it.deniedReason ? ' disabled title="' + esc(it.deniedReason) + '"' : '') +
                    (it.def.danger ? ' class="ca-danger"' : '') + '><span aria-hidden="true">' + it.def.icon + '</span>' + esc(it.def.label) + '</button>';
            }).join('') + '<div class="ca-menu-note">Hapus permanen tidak tersedia. Gunakan Disable / Revoke.</div></div></div>';
    }

    function cardCell(rec) {
        return rec.credential ? '<span class="ca-mask">' + esc(maskId(rec.credential.masked_identifier)) + '</span>' : '<span class="ca-dim">Belum ada</span>';
    }

    function CardsTableRows(items, can) {
        return items.map(rec => {
            const disp = resolveDisplayState(rec);
            return '<tr class="ca-row" data-ca-action="view" data-id="' + rec.employee.id + '">' +
                '<td><div class="ca-emp-name">' + esc(rec.employee.name) + '</div>' + MismatchChips(rec) + '</td>' +
                '<td class="ca-mask" style="color:var(--text-muted)">' + esc(rec.employee.employee_code) + '</td>' +
                '<td>' + cardCell(rec) + '</td>' +
                '<td class="ca-col-optional ca-nowrap">' + esc(rec.employee.building) + '</td>' +
                '<td class="ca-col-optional">' + esc(rec.access.profile || '—') + '</td>' +
                '<td>' + AccessStatusBadge(disp.state, disp.guarded ? 'Ditampilkan konservatif: ' + disp.reason : null) + '</td>' +
                '<td>' + SyncStatusBadge(rec.device.sync_status) + '</td>' +
                '<td class="ca-col-optional ca-muted">' + fmtDateTime(rec.device.last_verified_at) + '</td>' +
                '<td data-stop>' + CardActionMenu(rec, can) + '</td></tr>';
        }).join('');
    }

    // Mobile: each record becomes an EmployeeAccessCard.
    function EmployeeAccessCard(rec, can) {
        const disp = resolveDisplayState(rec);
        return '<div class="ca-card-item" data-ca-action="view" data-id="' + rec.employee.id + '">' +
            '<div class="ca-card-item-head"><div style="min-width:0"><div class="ca-emp-name">' + esc(rec.employee.name) + '</div>' +
            '<div class="ca-dim">' + esc(rec.employee.employee_code) + ' · ' + esc(rec.employee.building) + '</div></div>' +
            '<div data-stop>' + CardActionMenu(rec, can) + '</div></div>' +
            '<div class="ca-badge-row" style="margin-top:0.6rem">' + AccessStatusBadge(disp.state) + SyncStatusBadge(rec.device.sync_status) + '</div>' + MismatchChips(rec) +
            '<dl><dt>Kartu</dt><dd>' + cardCell(rec) + '</dd><dt>Profil</dt><dd>' + esc(rec.access.profile || '—') + '</dd>' +
            '<dt>Terverifikasi</dt><dd>' + fmtDateTime(rec.device.last_verified_at) + '</dd></dl></div>';
    }

    function TableSkeleton(cols, rows) {
        return Array.from({ length: rows || 5 }).map(() => '<tr>' + Array.from({ length: cols }).map(() => '<td><span class="ca-skel"></span></td>').join('') + '</tr>').join('');
    }

    function matchRow(label, key) {
        const m = MATCH[key] || MATCH.UNKNOWN;
        return '<li><span>' + esc(label) + '</span><span class="ca-check-mark ' + m.cls + '" title="' + esc(m.label) + '">' + m.mark + ' <span class="ca-dim">' + esc(m.label) + '</span></span></li>';
    }

    // Access verification for one record. App permission, device credential,
    // device permission and verification are always listed separately.
    function VerificationSummary(rec, opts) {
        const d = rec.device;
        const appOk = rec.access.buildings.length > 0 ? 'MATCH' : 'UNKNOWN';
        const verified = rec.verification === 'VERIFIED' ? 'MATCH' : (rec.verification === 'OUT_OF_SYNC' ? 'MISMATCH' : 'UNKNOWN');
        const canSync = opts && opts.can && opts.can('CAN_SYNC');
        const needsResync = rec.verification === 'OUT_OF_SYNC' || d.sync_status === 'FAILED';
        return '<div class="ca-box" style="margin-bottom:0.9rem"><div class="ca-box-head"><span class="ca-box-title">Access Verification</span>' + VerificationStatusBadge(rec.verification) + '</div>' +
            '<div class="ca-box-body"><div class="ca-muted" style="margin-bottom:0.5rem">' + esc(rec.employee.name) + ' · ' + cardCell(rec) + ' · ' + list(rec.access.buildings, 'Tanpa gedung') + '</div>' +
            '<ul class="ca-check">' + matchRow('Application Permission', appOk) + matchRow('Device Credential', d.credential_match) +
            matchRow('Device Permission', d.access_match) + matchRow('Verification', verified) + '</ul>' +
            '<div class="ca-verify-foot"><span class="ca-muted">Last Verified: ' + fmtDateTime(d.last_verified_at) + '</span>' +
            (needsResync ? '<button type="button" class="btn-primary btn-sm" data-ca-action="resync" data-id="' + rec.employee.id + '"' + (canSync ? '' : ' disabled title="Tidak memiliki izin CAN_SYNC"') + '>⚡ Re-Sync</button>' : '') +
            '</div><div class="ca-dim" style="margin-top:0.5rem">Permintaan API yang berhasil ≠ akses fisik terverifikasi. Status Verified hanya dari konfirmasi perangkat.</div></div></div>';
    }

    function AuditTimeline(items) {
        if (items === null) return '<div class="ca-box-body"><span class="ca-skel"></span><br><span class="ca-skel" style="width:60%"></span></div>';
        if (!items.length) return EmptyState('NO_AUDIT');
        return '<ul class="ca-timeline">' + items.map(i => '<li class="tone-' + (i.tone || 'neutral') + '"><div style="font-weight:600">' + esc(i.title) + '</div><div class="ca-dim">' + fmtDateTime(i.at) + ' · ' + esc(i.by) + '</div></li>').join('') + '</ul>';
    }

    function domain(index, title, statusHtml, body) {
        return '<section class="ca-domain"><div class="ca-domain-head"><span class="ca-domain-title"><span class="ca-domain-index">' + index + '</span>' + esc(title) + '</span>' + (statusHtml || '') + '</div>' + body + '</section>';
    }
    function kv(rows) {
        return '<dl class="ca-kv">' + rows.filter(r => r[1] !== undefined).map(r => '<dt>' + esc(r[0]) + '</dt><dd>' + r[1] + '</dd>').join('') + '</dl>';
    }

    function EmployeeAccessDetail(rec, can) {
        const disp = resolveDisplayState(rec);
        const e = rec.employee, c = rec.credential, a = rec.access, d = rec.device;
        const mm = collectMismatches(rec);
        let html = '';
        if (disp.guarded) {
            html += '<div class="ca-alert tone-attention"><div class="ca-alert-icon">⚠</div><div><div class="ca-alert-title">Ditampilkan konservatif</div>' +
                '<div class="ca-muted">Backend melaporkan <b>' + esc(rec.lifecycle_state) + '</b>, tetapi ' + esc(disp.reason.toLowerCase()) + ' Status tidak ditampilkan sebagai aktif sampai terverifikasi.</div></div></div>';
        }
        if (d.sync_status === 'FAILED' && d.error) {
            html += ErrorState('HIKVISION_OFFLINE', { failed: d.error.summary, actionsHtml: actionBtn('resync', e.id, '⚡ Retry Sync', can('CAN_SYNC')) + actionBtn('diagnostics', e.id, 'View Diagnostic', true) });
        }
        mm.forEach(m => { html += MismatchAlert(m); });

        html += domain('A', 'Employee Identity', '<span class="ca-dim">Sumber: data Pengguna</span>', kv([
            ['Nama', esc(e.name)], ['Employee ID', '<span class="ca-mask">' + esc(e.employee_code) + '</span>'],
            ['Status Kepegawaian', esc(e.employment_status)], ['Gedung', esc(e.building)],
            ['Departemen', e.department ? esc(e.department) : undefined], ['Jabatan', e.position ? esc(e.position) : undefined],
        ]));
        html += domain('B', 'Application Credential', CardStatusBadge(c ? c.status : 'NONE'), c ? kv([
            ['Card Identifier', '<span class="ca-mask">' + esc(maskId(c.masked_identifier)) + '</span>'],
            ['Credential Type', esc(c.credential_type)],
            ['Registered At', fmtDateTime(c.registered_at)],
            ['Registered By', c.registered_by ? esc(c.registered_by) : '<span class="ca-dim">Tidak tersedia</span>'],
            ['Asal Data', c.origin === 'LEGACY' ? 'Data existing <span class="ca-dim">(sah — tanpa metadata NFC)</span>' : 'Pendaftaran NFC'],
        ]) : '<div class="ca-box-body">' + EmptyState('NO_CARDS', can('CAN_PROVISION_CARD') ? '<button type="button" class="btn-secondary" data-ca-action="enroll" data-id="' + e.id + '">📶 Daftarkan Kartu NFC</button>' : '') + '</div>');
        html += domain('C', 'Access Permission', a.buildings.length ? '<span class="badge ca-badge badge-info">' + a.buildings.length + ' gedung</span>' : '', a.buildings.length ? kv([
            ['Building Access', list(a.buildings)], ['Door Access', list(a.doors)], ['Access Profile', esc(a.profile || '—')],
            ['Valid From', fmtDate(a.valid_from)], ['Valid Until', a.valid_until ? fmtDate(a.valid_until) : 'Tanpa batas'],
        ]) : '<div class="ca-box-body">' + EmptyState('NO_ACCESS') + '</div>');
        html += domain('D', 'Hikvision Device State', SyncStatusBadge(d.sync_status), '<div class="ca-box-body"><ul class="ca-check">' +
            matchRow('Person Match', d.person_match) + matchRow('Credential Match', d.credential_match) + matchRow('Access Match', d.access_match) + '</ul></div>' +
            kv([['Last Sync', fmtDateTime(d.last_sync_at)], ['Last Verification', fmtDateTime(d.last_verified_at)], ['Device Status', esc(d.device_status || '—')]]));
        html += VerificationSummary(rec, { can });
        html += '<section class="ca-domain"><div class="ca-domain-head"><span class="ca-domain-title">Audit History</span></div><div class="ca-box-body" data-ca-slot="audit">' +
            (can('CAN_VIEW_AUDIT') ? AuditTimeline(null) : PermissionDenied('CAN_VIEW_AUDIT')) + '</div></section>';
        return html;
    }

    function actionBtn(action, id, label, allowed, cls) {
        return '<button type="button" class="' + (cls || 'btn-secondary btn-sm') + '" data-ca-action="' + action + '" data-id="' + id + '"' + (allowed ? '' : ' disabled title="Tidak memiliki izin"') + '>' + label + '</button>';
    }

    function DetailSkeleton() {
        return ['A', 'B', 'C', 'D'].map(i => domain(i, 'Memuat…', '', '<div class="ca-box-body"><span class="ca-skel"></span><br><span class="ca-skel" style="width:70%"></span><br><span class="ca-skel" style="width:50%"></span></div>')).join('');
    }

    function StepIndicator(steps, current) {
        return '<ol class="ca-steps" aria-label="Langkah">' + steps.map((s, i) => '<li class="' + (i < current ? 'done' : i === current ? 'current' : '') + '"' + (i === current ? ' aria-current="step"' : '') + '>' + esc(s) + '</li>').join('') + '</ol>';
    }

    // stages: [{label, state: done|fail|wait|run|skip, note}]
    function SyncProgress(stages) {
        const mark = { done: '<span class="state-done">✓</span>', fail: '<span class="state-fail">✕</span>', wait: '<span class="state-wait">—</span>', run: '<span class="spinner-sm" aria-label="berjalan"></span>', skip: '<span class="state-skip">dilewati</span>' };
        return '<ul class="ca-progress" aria-live="polite">' + stages.map(s => '<li><span>' + esc(s.label) + (s.note ? '<div class="ca-dim">' + esc(s.note) + '</div>' : '') + '</span>' + mark[s.state] + '</li>').join('') + '</ul>';
    }

    const NFC_STATES = {
        NFC_READY: { icon: '📶', cls: '', label: 'NFC Ready', help: 'Tekan Start NFC Scan, lalu tempelkan kartu di belakang ponsel.' },
        WAITING: { icon: '📳', cls: 'is-waiting', label: 'Waiting for Card', help: 'Tempelkan kartu dan tahan sampai terdeteksi.' },
        DETECTED: { icon: '💳', cls: 'is-detected', label: 'Card Detected', help: 'Kartu terbaca. Memeriksa ke SecureGate…' },
        VALIDATING: { icon: '⏳', cls: 'is-waiting', label: 'Validating', help: 'Memeriksa duplikasi dan status kartu di backend.' },
        READY: { icon: '✓', cls: 'is-detected', label: 'Ready', help: 'Kartu valid. Belum ada yang disimpan — lanjutkan ke pengaturan akses.' },
        ERROR: { icon: '✕', cls: 'is-error', label: 'Error', help: 'Lihat detail di bawah.' },
    };
    function NfcScanPanel(state, canStart) {
        const s = NFC_STATES[state] || NFC_STATES.NFC_READY;
        const busy = state === 'WAITING' || state === 'DETECTED' || state === 'VALIDATING';
        return '<div class="ca-scan-target ' + s.cls + '" aria-live="polite"><div class="ca-scan-icon" aria-hidden="true">' + s.icon + '</div>' +
            '<div class="ca-scan-state">' + esc(s.label) + (busy ? ' <span class="spinner-sm"></span>' : '') + '</div><div class="ca-scan-help">' + esc(s.help) + '</div>' +
            (state === 'NFC_READY' || state === 'ERROR' ? '<button type="button" class="btn-primary" style="margin-top:0.9rem;min-height:44px" data-ca-action="nfc-start"' + (canStart ? '' : ' disabled') + '>' + (state === 'ERROR' ? 'Scan Ulang' : 'Start NFC Scan') + '</button>' : '') +
            '</div>';
    }

    function NfcValidationResult(result) {
        if (!result) return '';
        if (result.status === 'READY') {
            return '<div class="ca-alert tone-success"><div class="ca-alert-icon">✓</div><div><div class="ca-alert-title">Card Detected</div>' +
                '<dl><dt>Card Identifier</dt><dd class="ca-mask">' + esc(maskId(result.masked_identifier)) + '</dd><dt>Validation Status</dt><dd>' + '<span class="badge ca-badge badge-success"><span class="badge-dot"></span>Ready</span>' + '</dd></dl></div></div>';
        }
        if (result.status === 'CARD_ALREADY_REGISTERED') {
            return ErrorState('DUPLICATE_CARD', { failed: 'Kartu ' + maskId(result.masked_identifier) + ' sudah terdaftar. ' + (result.owner_hint || '') });
        }
        if (result.status === 'CARD_REQUIRES_VERIFICATION') {
            return '<div class="ca-alert tone-attention"><div class="ca-alert-icon">⚠</div><div><div class="ca-alert-title">Card Requires Verification</div>' +
                '<dl><dt>Card Identifier</dt><dd class="ca-mask">' + esc(maskId(result.masked_identifier)) + '</dd><dt>Temuan</dt><dd>' + esc(result.note) + '</dd>' +
                '<dt>Yang tetap tersimpan</dt><dd>Tidak ada perubahan. Data existing tidak diubah otomatis.</dd>' +
                '<dt>Langkah aman</dt><dd>Selesaikan rekonsiliasi di Device Sync sebelum mendaftarkan kartu ini.</dd></dl></div></div>';
        }
        return ErrorState(result.status);
    }

    // AccessPermissionMatrix: buildings (left) + doors of the focused building (right).
    function AccessPermissionMatrix(catalog, draft, focusBuilding, original, opts) {
        const disabled = opts && opts.readOnly;
        const buildingRows = catalog.buildings.map(b => {
            const on = draft.buildings.includes(b);
            const changed = original && (original.buildings.includes(b) !== on);
            return '<div class="ca-toggle-row' + (b === focusBuilding ? ' is-selected' : '') + (changed ? ' is-changed' : '') + '">' +
                '<span class="ca-toggle-label" data-ca-action="focus-building" data-building="' + esc(b) + '">' + esc(b) + ' <span class="ca-dim">· ' + (on ? 'Enabled' : 'Disabled') + '</span></span>' +
                '<label class="ca-switch"><input type="checkbox" data-ca-toggle="building" data-building="' + esc(b) + '"' + (on ? ' checked' : '') + (disabled ? ' disabled' : '') + ' aria-label="Akses ' + esc(b) + '"><span></span></label></div>';
        }).join('');
        const doors = catalog.doors[focusBuilding] || [];
        const buildingOn = draft.buildings.includes(focusBuilding);
        const doorRows = doors.map(dname => {
            const on = draft.doors.includes(dname);
            const changed = original && (original.doors.includes(dname) !== on);
            return '<div class="ca-toggle-row' + (changed ? ' is-changed' : '') + '"><span class="ca-toggle-label">' + esc(dname) + ' <span class="ca-dim">· ' + (on ? 'Enabled' : 'Disabled') + '</span></span>' +
                '<label class="ca-switch"><input type="checkbox" data-ca-toggle="door" data-door="' + esc(dname) + '"' + (on ? ' checked' : '') + (disabled || !buildingOn ? ' disabled' : '') + ' aria-label="Akses pintu ' + esc(dname) + '"><span></span></label></div>';
        }).join('');
        return '<div class="ca-perm-layout"><div class="ca-box" style="margin:0"><div class="ca-box-head"><span class="ca-box-title">Building Access</span></div>' + buildingRows + '</div>' +
            '<div class="ca-box" style="margin:0"><div class="ca-box-head"><span class="ca-box-title">Door Access · ' + esc(focusBuilding) + '</span></div>' +
            (buildingOn ? doorRows : '<div class="ca-empty">Aktifkan ' + esc(focusBuilding) + ' untuk memilih pintu.</div>') + '</div></div>';
    }

    function AccessScheduleFields(catalog, draft, prefix) {
        const p = prefix || 'ca';
        return '<div class="ca-form-grid" style="margin-top:1rem">' +
            '<div class="form-row"><label for="' + p + 'Profile">Access Profile</label><select id="' + p + 'Profile" data-ca-field="profile">' +
            catalog.profiles.map(pr => '<option' + (pr === draft.profile ? ' selected' : '') + '>' + esc(pr) + '</option>').join('') + '</select></div>' +
            '<div class="form-row"><label for="' + p + 'From">Valid From</label><input type="date" id="' + p + 'From" data-ca-field="valid_from" value="' + esc(draft.valid_from || '') + '"></div>' +
            '<div class="form-row"><label for="' + p + 'Until">Valid Until</label><input type="date" id="' + p + 'Until" data-ca-field="valid_until" value="' + esc(draft.valid_until || '') + '"></div>' +
            '<div class="form-row"><label for="' + p + 'Reason">Reason / Note *</label><textarea id="' + p + 'Reason" data-ca-field="reason" placeholder="Wajib diisi untuk audit">' + esc(draft.reason || '') + '</textarea></div></div>';
    }

    function AccessDiff(diff) {
        return '<div class="ca-diff"><div class="ca-diff-col"><div class="ca-compare-label">Current Access</div><ul>' + (diff.current.buildings.length ? diff.current.buildings.map(b => '<li>' + esc(b) + '</li>').join('') : '<li class="ca-dim">Tidak ada</li>') + '</ul></div>' +
            '<div class="ca-diff-col"><div class="ca-compare-label">New Access</div><ul>' + (diff.next.buildings.length ? diff.next.buildings.map(b => '<li>' + esc(b) + '</li>').join('') : '<li class="ca-dim">Tidak ada</li>') + '</ul></div>' +
            '<div class="ca-diff-col"><div class="ca-compare-label">Added</div><ul class="ca-diff-added">' + (diff.added.length ? diff.added.map(d => '<li>' + esc(d) + '</li>').join('') : '<li class="ca-dim" style="list-style:none">None</li>') + '</ul></div>' +
            '<div class="ca-diff-col"><div class="ca-compare-label">Removed</div><ul class="ca-diff-removed">' + (diff.removed.length ? diff.removed.map(d => '<li>' + esc(d) + '</li>').join('') : '<li class="ca-dim" style="list-style:none">None</li>') + '</ul></div></div>';
    }

    // ======================================================================
    // 7. Page controller
    // ======================================================================
    const TABS = [
        { key: 'overview', label: '📊 Overview' },
        { key: 'cards', label: '💳 Cards' },
        { key: 'permissions', label: '🔑 Access Permissions' },
        { key: 'sync', label: '⚡ Device Sync' },
    ];

    function CardAccessPage(root, options) {
        this.root = root;
        this.adapter = options.adapter;
        this.can = makeCan(options.permissions, options.role);
        this.preview = this.adapter.mode === 'preview';
        this.toast = options.toast || function () {};
        this.state = { tab: 'overview', filters: { search: '', building: '', cardStatus: '', syncStatus: '', verification: '', page: 1, perPage: 25 }, syncFilter: '' };
        this.hosts = this.ensureHosts();
        this.render();
        root.addEventListener('click', e => this.onClick(e));
        root.addEventListener('input', e => this.onInput(e));
        root.addEventListener('change', e => this.onChange(e));
        [this.hosts.drawer, this.hosts.modal, this.hosts.flow].forEach(h => {
            h.addEventListener('click', e => this.onClick(e));
            h.addEventListener('change', e => this.onChange(e));
            h.addEventListener('input', e => this.onInput(e));
        });
        document.addEventListener('click', e => { if (!e.target.closest('.ca-menu')) this.closeMenus(); });
        window.addEventListener('scroll', () => this.closeMenus(), true);
        window.addEventListener('resize', () => this.closeMenus());
        document.addEventListener('keydown', e => { if (e.key === 'Escape') { this.closeMenus(); this.closeDrawer(); this.closeModal(); } });
    }

    CardAccessPage.prototype.ensureHosts = function () {
        const mk = (id, cls) => {
            // Recreate on every mount so a remount never stacks event listeners.
            const existing = document.getElementById(id);
            if (existing) existing.remove();
            const el = document.createElement('div');
            el.id = id;
            el.className = cls;
            document.body.appendChild(el);
            return el;
        };
        return {
            drawer: mk('caDrawerHost', 'ca-drawer-overlay'),
            modal: mk('caModalHost', 'modal-overlay'),
            flow: mk('caFlowHost', 'ca-flow-overlay'),
        };
    };

    CardAccessPage.prototype.render = function () {
        const canProvision = this.can('CAN_PROVISION_CARD');
        this.root.innerHTML =
            (this.preview ? '<div class="ca-preview-banner" role="note"><span>🧪</span><div><strong>DESIGN PREVIEW — Phase 1 / 1.5.</strong> Semua data di halaman ini adalah <strong>contoh fiktif</strong>, bukan data produksi. Tidak ada perubahan yang dikirim ke backend atau perangkat Hikvision. Integrasi menunggu Backend Contract Lock.</div></div>'
                : '<div class="ca-preview-banner" role="note"><span>🧩</span><div><strong>Menunggu Backend Contract Lock.</strong> Modul Card Access sudah siap secara struktur, tetapi belum terhubung ke API.</div></div>') +
            '<div class="ca-header table-toolbar" style="padding:1.25rem 1.5rem"><div class="toolbar-left" style="display:block"><div class="ca-breadcrumb">Access Management / Card Access</div><h2><span>💳</span> Card Access</h2>' +
            '<div class="ca-header-desc">Kelola kartu akses karyawan, hak akses gedung & pintu, dan status sinkronisasi Hikvision.</div></div>' +
            '<div class="toolbar-right"><button type="button" class="btn-secondary" data-ca-action="refresh">🔄 Refresh</button>' +
            (canProvision ? '<button type="button" class="btn-primary" data-ca-action="enroll">📶 Daftarkan Kartu NFC</button>' : '') + '</div></div>' +
            '<div class="ats-subnav ca-subnav" style="display:flex;gap:0.5rem;margin-bottom:1.5rem;border-bottom:1px solid var(--border-color);padding-bottom:0.75rem" role="tablist">' +
            TABS.map(t => '<button type="button" role="tab" class="subnav-btn' + (t.key === this.state.tab ? ' active' : '') + '" data-ca-action="tab" data-tab="' + t.key + '" aria-selected="' + (t.key === this.state.tab) + '">' + t.label + '</button>').join('') + '</div>' +
            TABS.map(t => '<div class="ca-panel' + (t.key === this.state.tab ? ' active' : '') + '" data-ca-panel="' + t.key + '" role="tabpanel"></div>').join('');
        this.loadTab(this.state.tab);
    };

    CardAccessPage.prototype.panel = function (key) { return this.root.querySelector('[data-ca-panel="' + key + '"]'); };

    CardAccessPage.prototype.handleError = function (el, err) {
        const code = err && err.code ? err.code : (err && err.status === 403 ? 'PERMISSION_DENIED' : 'API_UNAVAILABLE');
        el.innerHTML = ErrorState(ERRORS[code] ? code : 'API_UNAVAILABLE', { actionsHtml: code === 'CONTRACT_PENDING' ? '' : '<button type="button" class="btn-secondary btn-sm" data-ca-action="refresh">Coba lagi</button>' });
    };

    CardAccessPage.prototype.switchTab = function (key) {
        this.state.tab = key;
        this.root.querySelectorAll('[data-ca-action="tab"]').forEach(b => { const on = b.dataset.tab === key; b.classList.toggle('active', on); b.setAttribute('aria-selected', on); });
        this.root.querySelectorAll('[data-ca-panel]').forEach(p => p.classList.toggle('active', p.dataset.caPanel === key));
        this.loadTab(key);
    };

    CardAccessPage.prototype.loadTab = function (key) {
        if (!this.can('CAN_VIEW')) { this.panel(key).innerHTML = PermissionDenied('CAN_VIEW'); return; }
        if (key === 'overview') return this.loadOverview();
        if (key === 'cards') return this.loadCards();
        if (key === 'permissions') return this.loadPermissions();
        if (key === 'sync') return this.loadSync();
    };

    // ---- Overview ----
    CardAccessPage.prototype.loadOverview = function () {
        const el = this.panel('overview');
        el.innerHTML = '<div data-slot="kpi">' + CardAccessKpi(null) + '</div>' +
            '<div class="ca-overview-grid"><div>' +
            '<div class="ca-box"><div class="ca-box-head"><span class="ca-box-title">Perlu Tindakan</span><span class="ca-dim">Mismatch & verifikasi</span></div><div data-slot="attention"><div class="ca-box-body"><span class="ca-skel"></span></div></div></div>' +
            '<div class="ca-box"><div class="ca-box-head"><span class="ca-box-title">Recent Card Activity</span></div><div data-slot="activity">' + RecentActivity(null) + '</div></div></div>' +
            '<div><div class="ca-box"><div class="ca-box-head"><span class="ca-box-title">Hikvision Sync Health</span></div><div data-slot="health">' + SyncHealth(null) + '</div></div>' +
            '<div class="ca-box"><div class="ca-box-body ca-muted" style="font-size:0.78rem;line-height:1.55"><b style="color:var(--text-main)">Model status</b><br>Employee Identity → Application Credential → Access Permission → Hikvision Device State. ' +
            'Sebuah kartu hanya <b>Verified</b> bila keempatnya cocok dan dikonfirmasi oleh perangkat.</div></div></div></div>';
        const slot = s => el.querySelector('[data-slot="' + s + '"]');
        this.adapter.getKpis().then(k => { slot('kpi').innerHTML = CardAccessKpi(k); }).catch(err => this.handleError(slot('kpi'), err));
        this.adapter.getRecentActivity({ limit: 8 }).then(a => { slot('activity').innerHTML = RecentActivity(a); }).catch(err => this.handleError(slot('activity'), err));
        this.adapter.getSyncHealth().then(h => { slot('health').innerHTML = SyncHealth(h); }).catch(err => this.handleError(slot('health'), err));
        this.adapter.getAttentionQueue().then(items => {
            slot('attention').innerHTML = items.length ? '<ul class="ca-activity">' + items.map(r => {
                const mm = collectMismatches(r);
                const disp = resolveDisplayState(r);
                return '<li class="ca-attn" data-ca-action="view" data-id="' + r.employee.id + '"><div style="min-width:0"><div class="ca-act-main">' + esc(r.employee.name) + '</div>' +
                    '<div class="ca-act-sub">' + (mm.length ? mm.map(m => m.title).join(' · ') : esc(SYNC[r.device.sync_status] ? SYNC[r.device.sync_status].label : r.device.sync_status)) + '</div></div>' +
                    '<span>' + AccessStatusBadge(disp.state) + '</span><span class="ca-dim">Detail →</span></li>';
            }).join('') + '</ul>' : EmptyState('NO_SYNC_FAILURES');
        }).catch(err => this.handleError(slot('attention'), err));
    };

    // ---- Cards ----
    CardAccessPage.prototype.loadCards = function () {
        const el = this.panel('cards');
        if (!el.dataset.ready) {
            const opt = (map, sel) => Object.keys(map).map(k => '<option value="' + k + '"' + (sel === k ? ' selected' : '') + '>' + esc(map[k].label) + '</option>').join('');
            const f = this.state.filters;
            el.innerHTML = '<div class="table-container ca-table-container"><div class="table-toolbar"><div class="toolbar-left" style="flex:1">' +
                '<div class="search-box" style="flex:1;max-width:360px"><span aria-hidden="true">🔍</span><input type="search" data-ca-filter="search" placeholder="Cari nama, Employee ID, atau 4 digit kartu" aria-label="Cari kartu" style="width:100%"></div>' +
                '<button type="button" class="btn-secondary ca-filter-toggle" data-ca-action="filters-open">⚙ Filter</button>' +
                '<div class="ca-filters" data-ca-slot="filters">' +
                '<select data-ca-filter="building" aria-label="Gedung"><option value="">Semua Gedung</option></select>' +
                '<select data-ca-filter="cardStatus" aria-label="Status kartu"><option value="">Semua Status Kartu</option>' + opt(LIFECYCLE, f.cardStatus) + '</select>' +
                '<select data-ca-filter="syncStatus" aria-label="Status sync"><option value="">Semua Status Sync</option>' + opt(SYNC, f.syncStatus) + '</select>' +
                '<select data-ca-filter="verification" aria-label="Status verifikasi"><option value="">Semua Verifikasi</option>' + opt(VERIFICATION, f.verification) + '</select>' +
                '<button type="button" class="btn-secondary ca-filter-toggle" data-ca-action="filters-close">Terapkan</button></div></div></div>' +
                '<div class="ca-table-wrap"><table class="ca-table"><thead><tr><th>Employee</th><th>Employee ID</th><th>Card</th><th class="ca-col-optional">Building</th><th class="ca-col-optional">Access Profile</th><th>Card Status</th><th>Device Sync</th><th class="ca-col-optional">Last Verified</th><th><span class="sr-only" style="position:absolute;left:-9999px">Aksi</span></th></tr></thead><tbody data-ca-slot="rows"></tbody></table></div>' +
                '<div class="ca-card-list" data-ca-slot="cards"></div><div class="ca-table-foot" data-ca-slot="foot"></div></div>';
            el.dataset.ready = '1';
            // Building options come from the facility catalog, never hardcoded.
            this.adapter.getAccessCatalog().then(cat => {
                const sel = el.querySelector('[data-ca-filter="building"]');
                sel.insertAdjacentHTML('beforeend', cat.buildings.map(b => '<option>' + esc(b) + '</option>').join(''));
                sel.value = this.state.filters.building || '';
            }).catch(() => {});
        }
        el.querySelectorAll('[data-ca-filter]').forEach(inp => { const v = this.state.filters[inp.dataset.caFilter]; if (inp.value !== (v || '')) inp.value = v || ''; });
        const rows = el.querySelector('[data-ca-slot="rows"]');
        const cards = el.querySelector('[data-ca-slot="cards"]');
        const foot = el.querySelector('[data-ca-slot="foot"]');
        rows.innerHTML = TableSkeleton(9, 5);
        cards.innerHTML = '<div class="ca-card-item"><span class="ca-skel"></span><br><span class="ca-skel" style="width:60%"></span></div>'.repeat(3);
        foot.innerHTML = '';
        const reqId = (this._cardsReq = (this._cardsReq || 0) + 1);
        this.adapter.listCards(this.state.filters).then(res => {
            if (reqId !== this._cardsReq) return;
            this._records = res.items;
            const hasFilter = ['search', 'building', 'cardStatus', 'syncStatus', 'verification'].some(k => this.state.filters[k]);
            if (!res.items.length) {
                const empty = EmptyState(hasFilter ? 'NO_RESULTS' : 'NO_CARDS', hasFilter ? '<button type="button" class="btn-secondary" data-ca-action="reset-filters">Reset Filter</button>' : '');
                rows.innerHTML = '<tr><td colspan="9">' + empty + '</td></tr>';
                cards.innerHTML = empty;
            } else {
                rows.innerHTML = CardsTableRows(res.items, this.can);
                cards.innerHTML = res.items.map(r => EmployeeAccessCard(r, this.can)).join('');
            }
            const m = res.meta;
            const from = m.total ? (m.page - 1) * m.per_page + 1 : 0;
            const to = Math.min(m.page * m.per_page, m.total);
            foot.innerHTML = '<span>Menampilkan ' + from + '–' + to + ' dari ' + esc(m.total) + ' <span class="ca-dim">(total dari backend)</span></span>' +
                '<span style="display:flex;gap:0.4rem"><button type="button" class="btn-secondary btn-sm" data-ca-action="page" data-dir="-1"' + (m.page <= 1 ? ' disabled' : '') + '>‹ Sebelumnya</button>' +
                '<button type="button" class="btn-secondary btn-sm" data-ca-action="page" data-dir="1"' + (to >= m.total ? ' disabled' : '') + '>Berikutnya ›</button></span>';
        }).catch(err => {
            rows.innerHTML = '<tr><td colspan="9"></td></tr>';
            this.handleError(rows.querySelector('td'), err);
            this.handleError(cards, err);
        });
    };

    // ---- Access permissions ----
    CardAccessPage.prototype.loadPermissions = function (employeeId) {
        const el = this.panel('permissions');
        if (!this.can('CAN_EDIT_ACCESS')) {
            el.innerHTML = PermissionDenied('CAN_EDIT_ACCESS');
            return;
        }
        el.innerHTML = '<div class="ca-box"><div class="ca-box-body"><span class="ca-skel"></span></div></div>';
        Promise.all([this.adapter.getAccessCatalog(), this.adapter.listCards({ perPage: 100 })]).then(([catalog, res]) => {
            this._catalog = catalog;
            const employees = res.items.filter(r => r.lifecycle_state !== 'REVOKED');
            const id = Number(employeeId || (this._perm && this._perm.id) || (employees[0] && employees[0].employee.id));
            const rec = employees.find(r => r.employee.id === id) || employees[0];
            if (!rec) { el.innerHTML = EmptyState('NO_RESULTS'); return; }
            const original = { buildings: rec.access.buildings.slice(), doors: rec.access.doors.slice() };
            this._perm = { id: rec.employee.id, rec, original, draft: Object.assign({}, rec.access, { buildings: rec.access.buildings.slice(), doors: rec.access.doors.slice(), reason: '' }), focus: rec.access.buildings[0] || catalog.buildings[0] };
            el.innerHTML = '<div class="ca-box"><div class="ca-box-head" style="flex-wrap:wrap"><div style="display:flex;gap:0.75rem;align-items:center;flex-wrap:wrap">' +
                '<span class="ca-box-title">Employee</span><div class="search-box"><select data-ca-action-change="perm-employee" aria-label="Pilih karyawan">' +
                employees.map(r => '<option value="' + r.employee.id + '"' + (r.employee.id === rec.employee.id ? ' selected' : '') + '>' + esc(r.employee.name) + ' · ' + esc(r.employee.employee_code) + '</option>').join('') + '</select></div></div>' +
                '<span>' + AccessStatusBadge(resolveDisplayState(rec).state) + '</span></div>' +
                '<div class="ca-box-body"><div data-ca-slot="matrix"></div><div data-ca-slot="schedule">' + AccessScheduleFields(catalog, this._perm.draft, 'caPerm') + '</div></div>' +
                '<div class="ca-sticky-actions"><span class="ca-muted" data-ca-slot="dirty">Belum ada perubahan.</span><span style="display:flex;gap:0.5rem">' +
                '<button type="button" class="btn-secondary" data-ca-action="perm-reset">Batal</button><button type="button" class="btn-primary" data-ca-action="perm-review" disabled>Review Changes</button></span></div></div>' +
                '<div class="ca-dim">Perubahan toggle tidak langsung disimpan. Semua perubahan ditinjau dulu lalu diterapkan dan disinkronkan sekaligus.</div>';
            this.renderMatrix();
        }).catch(err => this.handleError(el, err));
    };

    CardAccessPage.prototype.renderMatrix = function () {
        const p = this._perm;
        const slot = this.panel('permissions').querySelector('[data-ca-slot="matrix"]');
        if (!slot) return;
        slot.innerHTML = AccessPermissionMatrix(this._catalog, p.draft, p.focus, p.original);
        const added = p.draft.doors.filter(d => !p.original.doors.includes(d)).length;
        const removed = p.original.doors.filter(d => !p.draft.doors.includes(d)).length;
        const bChanged = p.draft.buildings.length !== p.original.buildings.length || p.draft.buildings.some(b => !p.original.buildings.includes(b));
        const dirty = added || removed || bChanged;
        const reasonOk = (p.draft.reason || '').trim().length > 0;
        this.panel('permissions').querySelector('[data-ca-slot="dirty"]').textContent = dirty
            ? ('Perubahan belum disimpan: +' + added + ' pintu, −' + removed + ' pintu.' + (reasonOk ? '' : ' Isi Reason / Note untuk melanjutkan.'))
            : 'Belum ada perubahan.';
        this.panel('permissions').querySelector('[data-ca-action="perm-review"]').disabled = !(dirty && reasonOk);
    };

    // ---- Device sync ----
    CardAccessPage.prototype.loadSync = function () {
        const el = this.panel('sync');
        const chips = ['', 'VERIFIED', 'SYNCING', 'PENDING', 'FAILED', 'OUT_OF_SYNC', 'NEEDS_VERIFICATION'];
        el.innerHTML = '<div class="table-container ca-table-container"><div class="table-toolbar"><div class="toolbar-left ats-subnav ca-subnav" style="display:flex;gap:0.4rem">' +
            chips.map(c => '<button type="button" class="subnav-btn' + (c === this.state.syncFilter ? ' active' : '') + '" style="padding:0.4rem 0.8rem;font-size:0.78rem" data-ca-action="sync-filter" data-status="' + c + '">' + (c ? esc(SYNC[c].label) : 'Semua') + '</button>').join('') +
            '</div></div><div class="ca-table-wrap"><table class="ca-table"><thead><tr><th>Employee</th><th>Application State</th><th>Hikvision State</th><th class="ca-col-optional">Credential</th><th class="ca-col-optional">Access</th><th>Verification</th><th class="ca-col-optional">Last Verified</th><th>Action</th></tr></thead><tbody data-ca-slot="rows">' + TableSkeleton(8, 4) + '</tbody></table></div>' +
            '<div class="ca-card-list" data-ca-slot="cards"></div></div>' +
            '<div class="ca-dim">Application State = data SecureGate. Hikvision State = hasil baca perangkat. Verification = konfirmasi fisik. Ketiganya tidak pernah digabung.</div>';
        const rows = el.querySelector('[data-ca-slot="rows"]');
        const cards = el.querySelector('[data-ca-slot="cards"]');
        this.adapter.listDeviceSync({ status: this.state.syncFilter }).then(items => {
            this._syncRecords = items;
            if (!items.length) {
                const empty = EmptyState(this.state.syncFilter === 'FAILED' ? 'NO_SYNC_FAILURES' : 'NO_RESULTS');
                rows.innerHTML = '<tr><td colspan="8">' + empty + '</td></tr>';
                cards.innerHTML = empty;
                return;
            }
            const appState = r => CardStatusBadge(r.credential ? r.credential.status : 'NONE') + '<div class="ca-dim" style="margin-top:0.25rem">' + list(r.access.buildings, 'Tanpa akses') + '</div>';
            const devState = r => SyncStatusBadge(r.device.sync_status) + '<div class="ca-dim" style="margin-top:0.25rem">Terminal: ' + esc(r.device.device_status || '—') + '</div>';
            const m = k => { const x = MATCH[k] || MATCH.UNKNOWN; return '<span class="ca-check-mark ' + x.cls + '">' + x.mark + '</span> <span class="ca-dim">' + esc(x.label) + '</span>'; };
            // One contextual primary action per row; the rest live in the overflow menu.
            const actions = r => {
                const st = r.device.sync_status;
                const id = r.employee.id;
                const primary = (st === 'FAILED' || st === 'OUT_OF_SYNC') ? actionBtn('resync', id, '⚡ Retry Sync', this.can('CAN_SYNC'))
                    : (st === 'NEEDS_VERIFICATION' || st === 'PENDING') ? actionBtn('verify', id, '✔ Verify', this.can('CAN_SYNC'))
                    : actionBtn('diagnostics', id, 'Diagnostics', true);
                const item = (a, label, ok) => '<button type="button" role="menuitem" data-ca-action="' + a + '" data-id="' + id + '"' + (ok ? '' : ' disabled title="Tidak memiliki izin"') + '>' + label + '</button>';
                return '<div style="display:flex;gap:0.35rem;align-items:center;justify-content:flex-end">' + primary +
                    '<div class="ca-menu"><button type="button" class="ca-menu-btn" data-ca-action="menu" aria-haspopup="true" aria-label="Aksi sinkronisasi ' + esc(r.employee.name) + '">⋯</button><div class="ca-menu-list" role="menu">' +
                    item('resync', '⚡ Retry Sync', this.can('CAN_SYNC')) + item('verify', '✔ Verify', this.can('CAN_SYNC')) + item('diagnostics', '🩺 Diagnostics', true) + '<hr>' + item('audit', '🛡 Audit', this.can('CAN_VIEW_AUDIT')) + '</div></div></div>';
            };
            rows.innerHTML = items.map(r => '<tr><td><div class="ca-emp-name">' + esc(r.employee.name) + '</div>' + MismatchChips(r) + '</td><td>' + appState(r) + '</td><td>' + devState(r) + '</td>' +
                '<td class="ca-col-optional">' + m(r.device.credential_match) + '</td><td class="ca-col-optional">' + m(r.device.access_match) + '</td><td>' + VerificationStatusBadge(r.verification) + '</td>' +
                '<td class="ca-col-optional ca-muted">' + fmtDateTime(r.device.last_verified_at) + '</td><td>' + actions(r) + '</td></tr>').join('');
            cards.innerHTML = items.map(r => '<div class="ca-card-item"><div class="ca-card-item-head"><div><div class="ca-emp-name">' + esc(r.employee.name) + '</div>' + MismatchChips(r) + '</div>' + VerificationStatusBadge(r.verification) + '</div>' +
                '<div class="ca-tri" style="margin-top:0.6rem"><div class="ca-tri-row"><b>APP</b>' + CardStatusBadge(r.credential.status) + '</div><div class="ca-tri-row"><b>DEVICE</b>' + SyncStatusBadge(r.device.sync_status) + '</div><div class="ca-tri-row"><b>VERIFY</b>' + VerificationStatusBadge(r.verification) + '</div></div>' +
                '<div style="margin-top:0.7rem">' + actions(r) + '</div></div>').join('');
        }).catch(err => { this.handleError(el.querySelector('.table-container'), err); });
    };

    // ---- Drawer (Employee Access Profile) ----
    CardAccessPage.prototype.openDrawer = function (id) {
        const h = this.hosts.drawer;
        h.innerHTML = '<div class="ca-drawer" role="dialog" aria-modal="true" aria-labelledby="caDrawerTitle"><div class="ca-drawer-head"><div><div class="ca-breadcrumb">Employee Access Profile</div><div class="modal-title" id="caDrawerTitle">Memuat…</div></div>' +
            '<button type="button" class="modal-close-btn" data-ca-action="drawer-close" aria-label="Tutup">✕</button></div><div class="ca-drawer-body">' + DetailSkeleton() + '</div><div class="ca-drawer-foot"></div></div>';
        h.classList.add('active');
        document.body.classList.add('modal-open');
        this.adapter.getEmployeeAccess(id).then(rec => {
            if (!rec) { h.querySelector('.ca-drawer-body').innerHTML = EmptyState('NO_RESULTS'); return; }
            this._current = rec;
            const disp = resolveDisplayState(rec);
            h.querySelector('#caDrawerTitle').innerHTML = esc(rec.employee.name) + ' <span style="margin-left:0.35rem">' + AccessStatusBadge(disp.state) + '</span>';
            h.querySelector('.ca-drawer-body').innerHTML = EmployeeAccessDetail(rec, this.can);
            const acts = actionsFor(rec, this.can).filter(a => a.key !== 'view' && a.key !== 'audit');
            h.querySelector('.ca-drawer-foot').innerHTML = acts.map(a => '<button type="button" class="' + (a.def.danger ? 'btn-secondary ca-danger' : 'btn-secondary') + '" style="' + (a.def.danger ? 'color:#fca5a5;border-color:rgba(239,68,68,0.35)' : '') + '" data-ca-action="' + a.key + '" data-id="' + rec.employee.id + '"' + (a.deniedReason ? ' disabled title="' + esc(a.deniedReason) + '"' : '') + '>' + a.def.icon + ' ' + esc(a.def.label) + '</button>').join('');
            if (this.can('CAN_VIEW_AUDIT')) {
                this.adapter.getAuditHistory(id).then(items => { const s = h.querySelector('[data-ca-slot="audit"]'); if (s) s.innerHTML = AuditTimeline(items); })
                    .catch(err => { const s = h.querySelector('[data-ca-slot="audit"]'); if (s) this.handleError(s, err); });
            }
        }).catch(err => this.handleError(h.querySelector('.ca-drawer-body'), err));
    };
    CardAccessPage.prototype.closeDrawer = function () {
        this.hosts.drawer.classList.remove('active');
        this.hosts.drawer.innerHTML = '';
        if (!this.hosts.modal.classList.contains('active')) document.body.classList.remove('modal-open');
    };

    // ---- Generic modal ----
    CardAccessPage.prototype.openModal = function (title, body, foot, maxWidth) {
        const h = this.hosts.modal;
        h.style.zIndex = 120;
        h.innerHTML = '<div class="modal-card" role="dialog" aria-modal="true" style="max-width:' + (maxWidth || 560) + 'px"><div class="modal-header"><div class="modal-title">' + title + '</div>' +
            '<button type="button" class="modal-close-btn" data-ca-action="modal-close" aria-label="Tutup">✕</button></div><div data-ca-slot="modal-body">' + body + '</div>' +
            (foot ? '<div style="display:flex;justify-content:flex-end;gap:0.6rem;margin-top:1.1rem;flex-wrap:wrap" data-ca-slot="modal-foot">' + foot + '</div>' : '') + '</div>';
        h.classList.add('active');
    };
    CardAccessPage.prototype.closeModal = function () { this.hosts.modal.classList.remove('active'); this.hosts.modal.innerHTML = ''; };

    CardAccessPage.prototype.findRecord = function (id) {
        id = Number(id);
        const pools = [this._current ? [this._current] : [], this._records || [], this._syncRecords || []];
        for (const p of pools) { const r = p.find(x => x.employee.id === id); if (r) return Promise.resolve(r); }
        return this.adapter.getEmployeeAccess(id);
    };

    // ---- Review changes (Access Permissions) ----
    CardAccessPage.prototype.reviewAccess = function () {
        const p = this._perm;
        this.adapter.previewAccessChange(p.id, p.draft).then(diff => {
            this.openModal('Review Changes · ' + esc(p.rec.employee.name),
                AccessDiff(diff) + kv([['Access Profile', esc(p.draft.profile)], ['Valid From', fmtDate(p.draft.valid_from)], ['Valid Until', p.draft.valid_until ? fmtDate(p.draft.valid_until) : 'Tanpa batas'], ['Reason', esc(p.draft.reason)]]) +
                '<div class="ca-dim" style="margin-top:0.6rem">Setelah diterapkan, status perangkat menjadi <b>Pending</b> sampai Hikvision mengonfirmasi. Tersimpan ≠ terverifikasi.</div>',
                '<button type="button" class="btn-secondary" data-ca-action="modal-close">Cancel</button><button type="button" class="btn-primary" data-ca-action="perm-apply"' + (this.can('CAN_SYNC') ? '' : ' disabled title="Butuh izin CAN_SYNC"') + '>Apply &amp; Synchronize</button>', 640);
        }).catch(err => this.toastError(err));
    };

    CardAccessPage.prototype.toastError = function (err) {
        const e = ERRORS[err && err.code] || ERRORS.API_UNAVAILABLE;
        this.toast(e.title + ': ' + e.failed, 'error');
    };
    CardAccessPage.prototype.simulated = function (msg) {
        this.toast((this.preview ? '[Simulasi] ' : '') + msg + (this.preview ? ' Tidak ada data yang diubah.' : ''), 'info');
    };

    // ---- Disable / Revoke / Diagnostics / Audit ----
    CardAccessPage.prototype.openDisable = function (rec) {
        this.openModal('Disable Card',
            '<div class="ca-alert tone-attention"><div class="ca-alert-icon">⏸</div><div><div class="ca-alert-title">Nonaktifkan sementara</div><div class="ca-muted">Kartu ' + cardCell(rec) + ' milik <b>' + esc(rec.employee.name) + '</b> tidak bisa dipakai sampai diaktifkan kembali. Data dan hak akses tetap tersimpan.</div></div></div>' +
            '<div class="form-row"><label for="caDisableReason">Reason *</label><textarea id="caDisableReason" data-ca-required></textarea></div>',
            '<button type="button" class="btn-secondary" data-ca-action="modal-close">Batal</button><button type="button" class="btn-primary" data-ca-action="confirm-disable" data-id="' + rec.employee.id + '" disabled>Disable &amp; Sync</button>');
    };

    // ConfirmRevokeDialog: deliberate — reason + acknowledgement required.
    CardAccessPage.prototype.openRevoke = function (rec) {
        const doors = rec.access.doors;
        this.openModal('<span style="color:#fca5a5">⛔ Revoke Card Access</span>',
            '<div class="ca-alert tone-critical"><div class="ca-alert-icon">⚠</div><div><div class="ca-alert-title">Tindakan ini mencabut akses fisik</div><div class="ca-muted">Kredensial dicabut dan dihapus dari terminal terkait. Riwayat audit tetap disimpan; ini bukan penghapusan data.</div></div></div>' +
            kv([['Employee', esc(rec.employee.name) + ' · <span class="ca-mask">' + esc(rec.employee.employee_code) + '</span>'], ['Masked Card', cardCell(rec)], ['Current Access', esc(rec.access.profile || '—')],
                ['Affected Buildings', list(rec.access.buildings)], ['Affected Doors', list(doors) + ' <span class="ca-dim">(' + doors.length + ')</span>']]) +
            '<div class="form-row"><label for="caRevokeReasonType">Reason *</label><select id="caRevokeReasonType" data-ca-required><option value="">Pilih alasan…</option><option>Resign / kontrak berakhir</option><option>Kartu hilang</option><option>Pelanggaran keamanan</option><option>Lainnya</option></select></div>' +
            '<div class="form-row"><label for="caRevokeNote">Catatan *</label><textarea id="caRevokeNote" data-ca-required></textarea></div>' +
            '<label style="display:flex;gap:0.5rem;font-size:0.82rem;color:var(--text-muted);align-items:flex-start"><input type="checkbox" data-ca-required style="margin-top:0.2rem"> Saya memahami bahwa ' + esc(rec.employee.name) + ' akan kehilangan akses ke ' + doors.length + ' pintu.</label>',
            '<button type="button" class="btn-secondary" data-ca-action="modal-close">Batal</button><button type="button" class="btn-danger-solid" data-ca-action="confirm-revoke" data-id="' + rec.employee.id + '" disabled>REVOKE CARD ACCESS</button>', 600);
    };

    CardAccessPage.prototype.openDiagnostics = function (rec) {
        const d = rec.device;
        const err = d.error;
        this.openModal('Diagnostics · ' + esc(rec.employee.name),
            (err ? ErrorState('PARTIAL_SYNC', { failed: err.summary }) : '<div class="ca-alert tone-info"><div class="ca-alert-icon">ℹ</div><div><div class="ca-alert-title">Tidak ada error sinkronisasi terakhir</div></div></div>') +
            kv([['Sync Status', SyncStatusBadge(d.sync_status)], ['Error Code', err ? '<span class="ca-mask">' + esc(err.code) + '</span>' : '—'], ['Terminal', err ? esc(err.door) : '—'],
                ['Attempts', err ? esc(err.attempts) : '—'], ['Last Attempt', err ? fmtDateTime(err.last_attempt_at) : fmtDateTime(d.last_sync_at)], ['Device Status', esc(d.device_status)]]) +
            collectMismatches(rec).map(MismatchAlert).join(''),
            '<button type="button" class="btn-secondary" data-ca-action="modal-close">Tutup</button>' + actionBtn('resync', rec.employee.id, '⚡ Retry Sync', this.can('CAN_SYNC'), 'btn-primary'), 600);
    };

    CardAccessPage.prototype.openAudit = function (rec) {
        this.openModal('Audit History · ' + esc(rec.employee.name), AuditTimeline(null));
        this.adapter.getAuditHistory(rec.employee.id).then(items => {
            const s = this.hosts.modal.querySelector('[data-ca-slot="modal-body"]'); if (s) s.innerHTML = AuditTimeline(items);
        }).catch(err => { const s = this.hosts.modal.querySelector('[data-ca-slot="modal-body"]'); if (s) this.handleError(s, err); });
    };

    // ======================================================================
    // 8. Flows: NFC enrollment + Replace card (full-screen on mobile)
    // ======================================================================
    const ENROLL_STEPS = ['Employee', 'Scan', 'Access', 'Review', 'Sync'];
    const REPLACE_STEPS = ['Current Card', 'Reason', 'Scan New', 'Access', 'Review', 'Sync'];

    CardAccessPage.prototype.nfcEnvironment = function () {
        if (typeof global.NDEFReader === 'undefined') return 'NFC_UNSUPPORTED';
        return 'OK';
    };

    CardAccessPage.prototype.startEnrollment = function (preselectId) {
        if (!this.can('CAN_PROVISION_CARD')) { this.toast('Anda tidak memiliki izin untuk mendaftarkan kartu.', 'error'); return; }
        this.flow = { kind: 'enroll', step: 0, employee: null, candidates: null, search: '', scan: 'NFC_READY', scenario: 'READY', validation: null, draft: null, focus: null, outcome: 'SUCCESS', progress: null, preselectId };
        this.closeDrawer();
        this.hosts.flow.classList.add('active');
        document.body.classList.add('modal-open');
        Promise.all([this.adapter.getAccessCatalog(), this.adapter.searchEnrollmentCandidates('')]).then(([cat, cands]) => {
            this._catalog = cat;
            this.flow.candidates = cands;
            if (preselectId) {
                const c = cands.find(x => x.employee.id === Number(preselectId));
                if (c && c.eligibility.eligible) { this.flow.employee = c.employee; this.flow.step = 1; }
            }
            this.renderFlow();
        }).catch(err => { this.hosts.flow.innerHTML = '<div class="ca-flow"><div class="ca-flow-body">' + ErrorState(err.code || 'API_UNAVAILABLE') + '</div><div class="ca-flow-foot"><button type="button" class="btn-secondary" data-ca-action="flow-close">Tutup</button></div></div>'; });
        this.renderFlow();
    };

    CardAccessPage.prototype.startReplace = function (rec) {
        if (!this.can('CAN_PROVISION_CARD')) return;
        this.flow = { kind: 'replace', step: 0, employee: rec.employee, rec, reason: '', reasonNote: '', retain: true, scan: 'NFC_READY', scenario: 'READY', validation: null,
            draft: Object.assign({}, rec.access, { buildings: rec.access.buildings.slice(), doors: rec.access.doors.slice(), reason: '' }), focus: rec.access.buildings[0] || 'Gedung A', outcome: 'SUCCESS', progress: null };
        this.closeDrawer();
        this.hosts.flow.classList.add('active');
        document.body.classList.add('modal-open');
        this.adapter.getAccessCatalog().then(cat => { this._catalog = cat; this.renderFlow(); });
        this.renderFlow();
    };

    CardAccessPage.prototype.closeFlow = function () {
        this.flow = null;
        this.hosts.flow.classList.remove('active');
        this.hosts.flow.innerHTML = '';
        document.body.classList.remove('modal-open');
    };

    function selectedEmployee(e, cardState) {
        return '<div class="ca-selected-emp"><div class="ca-compare-label">Selected Employee</div><div class="ca-emp-name" style="margin-top:0.2rem">' + esc(e.name) + '</div>' +
            '<div class="ca-dim">' + esc(e.employee_code) + ' · ' + esc(e.building) + ' · ' + esc(e.employment_status) + '</div>' + (cardState ? '<div style="margin-top:0.4rem">' + AccessStatusBadge(cardState) + '</div>' : '') + '</div>';
    }

    CardAccessPage.prototype.previewScenarioPicker = function (field, options) {
        if (!this.preview) return '';
        return '<div class="form-row" style="border:1px dashed rgba(245,158,11,0.4);border-radius:0.6rem;padding:0.6rem 0.75rem"><label>🧪 Skenario preview (hanya mode desain)</label><select data-ca-flow-field="' + field + '">' +
            options.map(o => '<option value="' + o[0] + '"' + (this.flow[field] === o[0] ? ' selected' : '') + '>' + esc(o[1]) + '</option>').join('') + '</select></div>';
    };

    CardAccessPage.prototype.renderFlow = function () {
        const f = this.flow;
        if (!f) return;
        const enroll = f.kind === 'enroll';
        const steps = enroll ? ENROLL_STEPS : REPLACE_STEPS;
        const title = enroll ? 'Register NFC Card' : 'Replace Card';
        let body = '', primary = '', secondary = '<button type="button" class="btn-secondary" data-ca-action="flow-back">' + (f.step === 0 ? 'Cancel' : 'Kembali') + '</button>';
        const stepKey = steps[f.step];
        const cat = this._catalog;

        if (!cat || (enroll && !f.candidates)) {
            body = '<span class="ca-skel"></span><br><span class="ca-skel" style="width:70%"></span>';
        } else if (stepKey === 'Employee') {
            const s = f.search.toLowerCase();
            const items = f.candidates.filter(c => !s || c.employee.name.toLowerCase().includes(s) || c.employee.employee_code.toLowerCase().includes(s));
            body = '<div class="search-box" style="margin-bottom:0.8rem"><span>🔍</span><input type="search" data-ca-flow-field="search" value="' + esc(f.search) + '" placeholder="Cari nama atau Employee ID" style="width:100%;font-size:16px" aria-label="Cari karyawan"></div>' +
                (items.length ? '<ul class="ca-pick-list">' + items.map(c => {
                    const el = c.eligibility;
                    return '<li><button type="button" class="ca-pick' + (f.employee && f.employee.id === c.employee.id ? ' is-selected' : '') + '"' + (el.eligible ? ' data-ca-action="flow-pick" data-id="' + c.employee.id + '"' : ' aria-disabled="true"') + '>' +
                        '<span style="min-width:0"><span class="ca-emp-name">' + esc(c.employee.name) + '</span><span class="ca-dim" style="display:block">' + esc(c.employee.employee_code) + ' · ' + esc(c.employee.building) + ' · ' + esc(c.employee.employment_status) + '</span>' +
                        (el.eligible ? '' : '<span class="ca-tone-critical" style="display:block;font-size:0.75rem;margin-top:0.2rem">' + esc(el.reason) + '</span>') + '</span>' + AccessStatusBadge(c.card_state) + '</button>' +
                        (!el.eligible && el.code === 'HAS_ACTIVE_CARD' ? '<button type="button" class="btn-secondary btn-sm" style="margin-top:0.35rem" data-ca-action="replace" data-id="' + c.employee.id + '">🔁 Buka alur Replace Card</button>' : '') + '</li>';
                }).join('') + '</ul>' : EmptyState('NO_RESULTS'));
            primary = '<button type="button" class="btn-primary" data-ca-action="flow-next"' + (f.employee ? '' : ' disabled') + '>Lanjut ke Scan</button>';
        } else if (stepKey === 'Current Card') {
            body = selectedEmployee(f.employee, resolveDisplayState(f.rec).state) + '<div class="ca-compare-cell is-old" style="margin-bottom:0.9rem"><div class="ca-compare-label">Current Card</div><div class="ca-compare-value">' + cardCell(f.rec) + ' · ' + CardStatusBadge(f.rec.credential.status) + '</div>' +
                '<div class="ca-dim" style="margin-top:0.3rem">Akses saat ini: ' + list(f.rec.access.buildings) + '</div></div>' +
                '<div class="ca-dim">Kartu lama akan dinonaktifkan sesuai kebijakan backend saat kartu baru berhasil didaftarkan. Dua kartu aktif bersamaan tidak ditampilkan kecuali backend mengizinkannya.</div>';
            primary = '<button type="button" class="btn-primary" data-ca-action="flow-next">Lanjut</button>';
        } else if (stepKey === 'Reason') {
            body = selectedEmployee(f.employee) + '<div class="ca-choice" role="radiogroup" aria-label="Replacement reason">' + [['LOST', 'Lost — kartu hilang'], ['DAMAGED', 'Damaged — kartu rusak'], ['REPLACEMENT', 'Replacement — penggantian rutin'], ['OTHER', 'Other']].map(r =>
                '<label><input type="radio" name="caReplaceReason" value="' + r[0] + '" data-ca-flow-field="reason"' + (f.reason === r[0] ? ' checked' : '') + '> ' + esc(r[1]) + '</label>').join('') + '</div>' +
                '<div class="form-row"><label for="caReplaceNote">Catatan' + (f.reason === 'OTHER' ? ' *' : '') + '</label><textarea id="caReplaceNote" data-ca-flow-field="reasonNote">' + esc(f.reasonNote) + '</textarea></div>' +
                (f.reason === 'LOST' ? '<div class="ca-alert tone-attention"><div class="ca-alert-icon">⚠</div><div class="ca-muted">Kartu hilang: kartu lama harus dicabut dari perangkat. Status kartu lama baru dianggap selesai setelah terverifikasi.</div></div>' : '');
            primary = '<button type="button" class="btn-primary" data-ca-action="flow-next"' + (f.reason && (f.reason !== 'OTHER' || f.reasonNote.trim()) ? '' : ' disabled') + '>Lanjut ke Scan</button>';
        } else if (stepKey === 'Scan' || stepKey === 'Scan New') {
            const env = this.nfcEnvironment();
            body = selectedEmployee(f.employee) + (stepKey === 'Scan New' ? '<div class="ca-old-new"><div class="ca-compare-cell is-old"><div class="ca-compare-label">Old Card</div><div class="ca-compare-value">' + cardCell(f.rec) + '</div></div><div class="ca-compare-cell is-new"><div class="ca-compare-label">New Card</div><div class="ca-compare-value">' + (f.validation && f.validation.status === 'READY' ? '<span class="ca-mask">' + esc(f.validation.masked_identifier) + '</span>' : '<span class="ca-dim">Belum dipindai</span>') + '</div></div></div>' : '') +
                '<div class="ca-compare-label" style="margin-bottom:0.4rem">NFC Card</div>' +
                (env !== 'OK' && !this.preview ? ErrorState(env) : '') +
                (env !== 'OK' && this.preview ? '<div class="ca-dim" style="margin-bottom:0.6rem">Browser ini tidak mendukung Web NFC (' + env + '). Pemindaian disimulasikan untuk preview desain.</div>' : '') +
                NfcScanPanel(f.scan, this.preview || env === 'OK') + NfcValidationResult(f.validation) +
                this.previewScenarioPicker('scenario', [['READY', 'Kartu valid'], ['DUPLICATE', 'Kartu sudah terdaftar'], ['NEEDS_VERIFICATION', 'Kartu perlu verifikasi'], ['CARD_UNREADABLE', 'Kartu tidak terbaca'], ['NFC_DISABLED', 'NFC dimatikan']]) +
                '<div class="ca-dim">Kartu tidak didaftarkan otomatis setelah terdeteksi. Pendaftaran hanya terjadi di langkah Review.</div>';
            primary = '<button type="button" class="btn-primary" data-ca-action="flow-next"' + (f.validation && f.validation.status === 'READY' ? '' : ' disabled') + '>Lanjut</button>';
        } else if (stepKey === 'Access') {
            const replaceIntro = !enroll ? '<div class="ca-choice" role="radiogroup" aria-label="Retain existing access"><label><input type="radio" name="caRetain" value="1" data-ca-flow-field="retain"' + (f.retain ? ' checked' : '') + '> Pertahankan akses yang ada (' + esc(f.rec.access.buildings.join(', ') || 'tanpa akses') + ')</label>' +
                '<label><input type="radio" name="caRetain" value="0" data-ca-flow-field="retain"' + (!f.retain ? ' checked' : '') + '> Atur ulang akses untuk kartu baru</label></div>' : '';
            if (enroll && !f.draft) f.draft = { buildings: [], doors: [], profile: cat.profiles[0], valid_from: '', valid_until: '', reason: '' };
            if (!f.focus) f.focus = f.employee.building || cat.buildings[0];
            const showMatrix = enroll || !f.retain;
            body = selectedEmployee(f.employee) + replaceIntro + (showMatrix ? AccessPermissionMatrix(cat, f.draft, f.focus, null) + AccessScheduleFields(cat, f.draft, 'caFlow') : '');
            const ok = !showMatrix || (f.draft.doors.length > 0 && (f.draft.reason || '').trim());
            primary = '<button type="button" class="btn-primary" data-ca-action="flow-next"' + (ok ? '' : ' disabled') + '>Review</button>';
        } else if (stepKey === 'Review') {
            const operator = (global.APP_CONFIG && global.APP_CONFIG.admin && global.APP_CONFIG.admin.name) || 'Operator saat ini';
            const access = (!enroll && f.retain) ? f.rec.access : f.draft;
            body = '<div class="ca-dim" style="margin-bottom:0.6rem">Tinjau semua data sebelum mendaftarkan.</div>' +
                (enroll ? '' : '<div class="ca-old-new"><div class="ca-compare-cell is-old"><div class="ca-compare-label">Old Card · akan dinonaktifkan</div><div class="ca-compare-value">' + cardCell(f.rec) + '</div></div><div class="ca-compare-cell is-new"><div class="ca-compare-label">New Card</div><div class="ca-compare-value"><span class="ca-mask">' + esc(f.validation.masked_identifier) + '</span></div></div></div>') +
                kv([['Employee', esc(f.employee.name) + ' · <span class="ca-mask">' + esc(f.employee.employee_code) + '</span>'],
                    ['Card Identifier', '<span class="ca-mask">' + esc(f.validation.masked_identifier) + '</span>'],
                    !enroll ? ['Replacement Reason', esc(f.reason) + (f.reasonNote ? ' — ' + esc(f.reasonNote) : '')] : ['Credential Type', 'CARD (NFC)'],
                    ['Building Access', list(access.buildings)], ['Door Access', list(access.doors)], ['Access Profile', esc(access.profile || '—')],
                    ['Operator', esc(operator)]]) +
                this.previewScenarioPicker('outcome', [['SUCCESS', 'Hasil: sukses & terverifikasi'], ['SYNC_FAILED', 'Hasil: tersimpan, sync perangkat gagal'], ['VERIFY_TIMEOUT', 'Hasil: verifikasi timeout']]);
            primary = '<button type="button" class="btn-primary" data-ca-action="flow-submit">' + (enroll ? 'REGISTER &amp; SYNC' : 'REPLACE &amp; SYNC') + '</button>';
        } else if (stepKey === 'Sync') {
            body = this.renderProgressBody();
            secondary = '';
            primary = f.progress && f.progress.done ? this.progressActions() : '<button type="button" class="btn-primary" disabled>Memproses…</button>';
        }

        this.hosts.flow.innerHTML = '<div class="ca-flow" role="dialog" aria-modal="true" aria-label="' + esc(title) + '"><div class="ca-flow-head"><div><div class="ca-breadcrumb">Card Access</div><div class="modal-title">' + esc(title) + '</div></div>' +
            (stepKey === 'Sync' && !(f.progress && f.progress.done) ? '' : '<button type="button" class="modal-close-btn" data-ca-action="flow-close" aria-label="Tutup">✕</button>') + '</div>' +
            '<div class="ca-flow-body">' + StepIndicator(steps, f.step) + body + '</div><div class="ca-flow-foot">' + secondary + primary + '</div></div>';
    };

    CardAccessPage.prototype.runScan = function () {
        const f = this.flow;
        f.validation = null;
        if (f.scenario === 'NFC_DISABLED' || f.scenario === 'CARD_UNREADABLE') {
            f.scan = 'WAITING'; this.renderFlow();
            setTimeout(() => { if (this.flow !== f) return; f.scan = 'ERROR'; f.validation = { status: f.scenario }; this.renderFlow(); }, 900);
            return;
        }
        // Production path: Web NFC (NDEFReader) or a native Android bridge reads
        // the UID, then backend validates. Not implemented before contract lock.
        f.scan = 'WAITING'; this.renderFlow();
        setTimeout(() => {
            if (this.flow !== f) return;
            f.scan = 'DETECTED'; this.renderFlow();
            setTimeout(() => {
                if (this.flow !== f) return;
                f.scan = 'VALIDATING'; this.renderFlow();
                this.adapter.validateScannedCard(f.employee.id, f.scenario).then(res => {
                    if (this.flow !== f) return;
                    f.validation = res;
                    f.scan = res.status === 'READY' ? 'READY' : 'ERROR';
                    this.renderFlow();
                }).catch(err => { f.scan = 'ERROR'; f.validation = { status: err.code || 'API_UNAVAILABLE' }; this.renderFlow(); });
            }, 600);
        }, 1100);
    };

    CardAccessPage.prototype.renderProgressBody = function () {
        const p = this.flow.progress;
        let html = SyncProgress(p.stages);
        if (p.done) {
            if (p.result === 'SUCCESS') {
                html = '<div class="ca-alert tone-success" style="margin-bottom:0.9rem"><div class="ca-alert-icon">✓</div><div><div class="ca-alert-title">Card Active · Verified</div><div class="ca-muted">Kartu tersimpan di SecureGate, tertulis di perangkat Hikvision, dan dikonfirmasi oleh perangkat.</div></div></div>' + html;
            } else if (p.result === 'SYNC_FAILED') {
                html = '<div class="ca-alert tone-critical" style="margin-bottom:0.9rem"><div class="ca-alert-icon">◐</div><div><div class="ca-alert-title">Card Registered · Device Sync Failed</div>' +
                    '<div class="ca-muted" style="margin-top:0.3rem">Kredensial berhasil disimpan di SecureGate, tetapi belum terverifikasi di Hikvision. <b>Akses fisik belum aktif.</b></div></div></div>' + html;
            } else {
                html = ErrorState('VERIFICATION_TIMEOUT') + html;
            }
        }
        return html;
    };

    CardAccessPage.prototype.progressActions = function () {
        const p = this.flow.progress;
        const id = this.flow.employee.id;
        if (p.result === 'SUCCESS') return '<button type="button" class="btn-primary" data-ca-action="flow-done" data-id="' + id + '">Lihat Kartu</button>';
        return '<span style="display:flex;gap:0.5rem;flex-wrap:wrap;width:100%">' +
            '<button type="button" class="btn-primary" data-ca-action="flow-retry">⚡ Retry Sync</button>' +
            '<button type="button" class="btn-secondary" data-ca-action="flow-diagnostic" data-id="' + id + '">View Diagnostic</button>' +
            '<button type="button" class="btn-secondary" data-ca-action="flow-done" data-id="' + id + '">Return to Card</button></span>';
    };

    CardAccessPage.prototype.submitFlow = function () {
        const f = this.flow;
        const enroll = f.kind === 'enroll';
        const labels = enroll
            ? ['Registering Credential', 'Creating Access Assignment', 'Synchronizing Hikvision', 'Verifying Device']
            : ['Deactivating Old Card', 'Registering New Credential', (f.retain ? 'Carrying Over Access' : 'Creating Access Assignment'), 'Synchronizing Hikvision', 'Verifying Device'];
        f.step = (enroll ? ENROLL_STEPS : REPLACE_STEPS).length - 1;
        f.progress = { stages: labels.map(l => ({ label: l, state: 'wait' })), done: false, result: null };
        const call = enroll ? this.adapter.registerCard : this.adapter.replaceCard;
        call.call(this.adapter, {}).then(() => this.animateProgress(f)).catch(err => {
            f.progress.stages[0].state = 'fail';
            f.progress.stages[0].note = (ERRORS[err.code] || ERRORS.API_UNAVAILABLE).failed;
            f.progress.done = true; f.progress.result = 'SYNC_FAILED';
            this.renderFlow();
        });
        this.renderFlow();
    };

    // Walks the progress stages from startIdx. In preview the outcome comes
    // from the scenario picker; in production each stage maps to backend
    // job status (see backend dependencies: sync/retry + verification API).
    CardAccessPage.prototype.animateProgress = function (f, startIdx) {
        const stages = f.progress.stages;
        const syncIdx = stages.findIndex(s => s.label === 'Synchronizing Hikvision');
        const last = stages.length - 1;
        let i = startIdx || 0;
        const finish = (result) => { f.progress.done = true; f.progress.result = result; this.renderFlow(); };
        const tick = () => {
            if (this.flow !== f) return;
            if (i > (startIdx || 0)) stages[i - 1].state = 'done';
            if (f.outcome === 'SYNC_FAILED' && i === syncIdx + 1) {
                stages[syncIdx].state = 'fail';
                stages[syncIdx].note = 'Terminal tidak merespons (contoh: HTTP 503).';
                stages.slice(syncIdx + 1).forEach(s => { s.state = 'skip'; });
                return finish('SYNC_FAILED');
            }
            if (i > last) return finish('SUCCESS');
            stages[i].state = 'run';
            this.renderFlow();
            if (f.outcome === 'VERIFY_TIMEOUT' && i === last) {
                setTimeout(() => {
                    if (this.flow !== f) return;
                    stages[last].state = 'fail';
                    stages[last].note = 'Tidak ada konfirmasi perangkat dalam batas waktu.';
                    finish('VERIFY_TIMEOUT');
                }, 1400);
                return;
            }
            i += 1;
            setTimeout(tick, 750);
        };
        tick();
    };

    // Retry only re-runs synchronisation + verification; the credential that
    // is already stored in SecureGate is not registered a second time.
    CardAccessPage.prototype.retryFlowSync = function () {
        const f = this.flow;
        const stages = f.progress.stages;
        const syncIdx = stages.findIndex(s => s.label === 'Synchronizing Hikvision');
        stages.slice(syncIdx).forEach(s => { s.state = 'wait'; s.note = null; });
        f.progress.done = false;
        f.progress.result = null;
        f.outcome = 'SUCCESS';
        this.renderFlow();
        this.adapter.retrySync(f.employee.id).then(() => this.animateProgress(f, syncIdx)).catch(err => {
            stages[syncIdx].state = 'fail';
            stages[syncIdx].note = (ERRORS[err.code] || ERRORS.API_UNAVAILABLE).failed;
            f.progress.done = true; f.progress.result = 'SYNC_FAILED';
            this.renderFlow();
        });
    };

    // Diagnostic view for a credential created in this flow (not yet in any list).
    CardAccessPage.prototype.flowRecord = function () {
        const f = this.flow;
        const access = (f.kind === 'replace' && f.retain) ? f.rec.access : f.draft;
        const failed = f.progress && f.progress.result !== 'SUCCESS';
        return {
            employee: f.employee,
            credential: { masked_identifier: f.validation.masked_identifier, status: 'ACTIVE' },
            access: access,
            device: {
                person_match: 'UNKNOWN', credential_match: failed ? 'MISSING' : 'MATCH', access_match: failed ? 'MISSING' : 'MATCH',
                last_sync_at: null, last_verified_at: null, device_status: failed ? 'UNREACHABLE' : 'ONLINE',
                sync_status: failed ? 'FAILED' : 'VERIFIED',
                error: failed ? { code: f.progress.result === 'VERIFY_TIMEOUT' ? 'VERIFICATION_TIMEOUT' : 'DEVICE_SYNC_FAILED', summary: 'Kredensial tersimpan di SecureGate tetapi belum tertulis/terverifikasi di terminal.', door: (access.doors[0] || '—'), attempts: 1, last_attempt_at: new Date().toISOString() } : null,
            },
            verification: failed ? 'OUT_OF_SYNC' : 'VERIFIED',
        };
    };

    // ======================================================================
    // 9. Event handling
    // ======================================================================
    // Menus are position:fixed so they are never clipped by scrolling tables.
    // On phones CSS turns them into a bottom sheet and ignores these offsets.
    CardAccessPage.prototype.positionMenu = function (menu) {
        const list = menu.querySelector('.ca-menu-list');
        if (window.innerWidth <= 768) { list.style.top = ''; list.style.left = ''; return; }
        const r = menu.querySelector('.ca-menu-btn').getBoundingClientRect();
        const h = list.offsetHeight;
        const w = list.offsetWidth;
        const top = (r.bottom + 4 + h > window.innerHeight) ? Math.max(8, r.top - h - 4) : r.bottom + 4;
        list.style.top = top + 'px';
        list.style.left = Math.max(8, r.right - w) + 'px';
    };

    CardAccessPage.prototype.closeMenus = function (except) {
        document.querySelectorAll('.ca-menu.open').forEach(m => { if (m !== except) m.classList.remove('open'); });
    };

    CardAccessPage.prototype.onClick = function (e) {
        const t = e.target.closest('[data-ca-action]');
        if (!t || t.disabled || t.getAttribute('aria-disabled') === 'true') return;
        const action = t.dataset.caAction;
        const id = t.dataset.id;
        // Row click opens detail; ignore clicks that originate inside the action cell.
        if ((action === 'view') && e.target.closest('[data-stop]') && !e.target.closest('.ca-menu-list')) return;
        if (action !== 'menu') this.closeMenus();
        switch (action) {
            case 'tab': return this.switchTab(t.dataset.tab);
            case 'refresh': return this.loadTab(this.state.tab);
            case 'menu': {
                e.stopPropagation();
                const m = t.closest('.ca-menu');
                this.closeMenus(m);
                m.classList.toggle('open');
                if (m.classList.contains('open')) this.positionMenu(m);
                return;
            }
            case 'kpi-filter': {
                const f = JSON.parse(t.dataset.filter || '{}');
                Object.assign(this.state.filters, { search: '', building: '', cardStatus: '', syncStatus: '', verification: '', page: 1 }, f);
                return this.switchTab('cards');
            }
            case 'reset-filters':
                Object.assign(this.state.filters, { search: '', building: '', cardStatus: '', syncStatus: '', verification: '', page: 1 });
                return this.loadCards();
            case 'filters-open': return this.panel('cards').querySelector('[data-ca-slot="filters"]').classList.add('open');
            case 'filters-close': return this.panel('cards').querySelector('[data-ca-slot="filters"]').classList.remove('open');
            case 'page': this.state.filters.page += Number(t.dataset.dir); return this.loadCards();
            case 'sync-filter': this.state.syncFilter = t.dataset.status; return this.loadSync();
            case 'view': return this.openDrawer(id);
            case 'drawer-close': return this.closeDrawer();
            case 'modal-close': return this.closeModal();
            case 'enroll': return this.startEnrollment(id);
            case 'edit':
                this.closeDrawer();
                this.switchTab('permissions');
                return this.loadPermissions(id);
            case 'replace': return this.findRecord(id).then(r => { this.closeFlow(); this.startReplace(r); });
            case 'disable': return this.findRecord(id).then(r => this.openDisable(r));
            case 'revoke': return this.findRecord(id).then(r => this.openRevoke(r));
            case 'diagnostics': return this.findRecord(id).then(r => this.openDiagnostics(r));
            case 'audit': return this.findRecord(id).then(r => this.openAudit(r));
            case 'resync': return this.adapter.retrySync(id).then(() => this.simulated('Re-sync diantrikan. Status menjadi Syncing sampai perangkat mengonfirmasi.')).catch(err => this.toastError(err));
            case 'verify': return this.adapter.verifyOnDevice(id).then(() => this.simulated('Permintaan verifikasi dikirim ke perangkat.')).catch(err => this.toastError(err));
            case 'confirm-disable': return this.adapter.disableCard(id, {}).then(() => { this.closeModal(); this.simulated('Kartu dinonaktifkan dan sinkronisasi diantrikan.'); }).catch(err => this.toastError(err));
            case 'confirm-revoke': return this.adapter.revokeCard(id, {}).then(() => { this.closeModal(); this.closeDrawer(); this.simulated('Pencabutan akses diantrikan. Status Revoked final setelah perangkat terverifikasi.'); }).catch(err => this.toastError(err));
            case 'focus-building':
                if (this.flow) { this.flow.focus = t.dataset.building; return this.renderFlow(); }
                this._perm.focus = t.dataset.building; return this.renderMatrix();
            case 'perm-reset': return this.loadPermissions(this._perm && this._perm.id);
            case 'perm-review': return this.reviewAccess();
            case 'perm-apply': return this.adapter.applyAccessChange(this._perm.id, this._perm.draft).then(() => { this.closeModal(); this.simulated('Perubahan akses tersimpan. Sinkronisasi perangkat: Pending.'); this.loadPermissions(this._perm.id); }).catch(err => this.toastError(err));
            // flow
            case 'flow-close': return this.closeFlow();
            case 'flow-back':
                if (!this.flow || this.flow.step === 0) return this.closeFlow();
                this.flow.step -= 1; return this.renderFlow();
            case 'flow-next':
                this.flow.step += 1;
                return this.renderFlow();
            case 'flow-pick': {
                const c = this.flow.candidates.find(x => x.employee.id === Number(id));
                this.flow.employee = c.employee; this.flow.validation = null; this.flow.scan = 'NFC_READY';
                return this.renderFlow();
            }
            case 'nfc-start': return this.runScan();
            case 'flow-submit': return this.submitFlow();
            case 'flow-retry': return this.retryFlowSync();
            case 'flow-diagnostic': return this.openDiagnostics(this.flowRecord());
            case 'flow-done': { this.closeFlow(); if (this.state.tab === 'cards') this.loadCards(); return; }
        }
    };

    CardAccessPage.prototype.onInput = function (e) {
        const t = e.target;
        if (t.dataset.caFilter === 'search') {
            clearTimeout(this._searchTimer);
            this._searchTimer = setTimeout(() => { this.state.filters.search = t.value; this.state.filters.page = 1; this.loadCards(); }, 300);
            return;
        }
        if (t.dataset.caField && this._perm && !this.flow) {
            this._perm.draft[t.dataset.caField] = t.value;
            if (t.dataset.caField === 'reason') this.renderMatrix();
            return;
        }
        if (t.dataset.caField && this.flow) {
            this.flow.draft[t.dataset.caField] = t.value;
            const btn = this.hosts.flow.querySelector('[data-ca-action="flow-next"]');
            if (btn && t.dataset.caField === 'reason') btn.disabled = !(this.flow.draft.doors.length && t.value.trim());
            return;
        }
        if (t.dataset.caFlowField === 'search') {
            this.flow.search = t.value;
            clearTimeout(this._flowSearch);
            this._flowSearch = setTimeout(() => { const pos = t.selectionStart; this.renderFlow(); const n = this.hosts.flow.querySelector('[data-ca-flow-field="search"]'); if (n) { n.focus(); n.setSelectionRange(pos, pos); } }, 250);
            return;
        }
        if (t.dataset.caFlowField === 'reasonNote') {
            this.flow.reasonNote = t.value;
            const btn = this.hosts.flow.querySelector('[data-ca-action="flow-next"]');
            if (btn) btn.disabled = !(this.flow.reason && (this.flow.reason !== 'OTHER' || t.value.trim()));
            return;
        }
        if (t.hasAttribute('data-ca-required')) this.checkRequired();
    };

    CardAccessPage.prototype.checkRequired = function () {
        const modal = this.hosts.modal;
        const fields = Array.from(modal.querySelectorAll('[data-ca-required]'));
        const ok = fields.every(f => f.type === 'checkbox' ? f.checked : f.value.trim().length > 0);
        const btn = modal.querySelector('[data-ca-action="confirm-revoke"], [data-ca-action="confirm-disable"]');
        if (btn) btn.disabled = !ok;
    };

    CardAccessPage.prototype.onChange = function (e) {
        const t = e.target;
        if (t.dataset.caFilter && t.dataset.caFilter !== 'search') {
            this.state.filters[t.dataset.caFilter] = t.value; this.state.filters.page = 1; return this.loadCards();
        }
        if (t.dataset.caActionChange === 'perm-employee') return this.loadPermissions(t.value);
        if (t.dataset.caToggle) {
            const draft = this.flow ? this.flow.draft : this._perm.draft;
            const cat = this._catalog;
            if (t.dataset.caToggle === 'building') {
                const b = t.dataset.building;
                if (t.checked) { if (!draft.buildings.includes(b)) draft.buildings.push(b); }
                else { draft.buildings = draft.buildings.filter(x => x !== b); draft.doors = draft.doors.filter(d => !(cat.doors[b] || []).includes(d)); }
                if (this.flow) this.flow.focus = b; else this._perm.focus = b;
            } else {
                const d = t.dataset.door;
                draft.doors = t.checked ? draft.doors.concat([d]) : draft.doors.filter(x => x !== d);
            }
            return this.flow ? this.renderFlow() : this.renderMatrix();
        }
        if (t.dataset.caField === 'profile' || t.dataset.caField === 'valid_from' || t.dataset.caField === 'valid_until') {
            (this.flow ? this.flow.draft : this._perm.draft)[t.dataset.caField] = t.value;
            return;
        }
        if (t.dataset.caFlowField === 'outcome') { this.flow.outcome = t.value; return; }
        if (t.dataset.caFlowField === 'scenario') { this.flow.scenario = t.value; this.flow.validation = null; this.flow.scan = 'NFC_READY'; return this.renderFlow(); }
        if (t.dataset.caFlowField === 'reason') { this.flow.reason = t.value; return this.renderFlow(); }
        if (t.dataset.caFlowField === 'retain') { this.flow.retain = t.value === '1'; return this.renderFlow(); }
        if (t.hasAttribute('data-ca-required')) this.checkRequired();
    };

    // ======================================================================
    // 10. State gallery (design review only; used by the standalone prototype)
    // ======================================================================
    function renderStateGallery(el, adapter) {
        const sample = {
            employee: { id: 99, name: 'Karyawan Contoh 99', employee_code: 'CONTOH-099', building: 'Gedung B' },
            credential: { masked_identifier: '••••3163', status: 'ACTIVE' },
            access: { buildings: ['Gedung B'], doors: ['B-01 Main Entrance'] },
            device: { person_match: 'MATCH', credential_match: 'MATCH', access_match: 'MATCH', last_verified_at: '2026-10-07T01:51:00Z', sync_status: 'VERIFIED' },
            verification: 'VERIFIED',
        };
        const oos = JSON.parse(JSON.stringify(sample));
        oos.device.access_match = 'MISMATCH'; oos.verification = 'OUT_OF_SYNC';
        const can = () => true;
        const box = (t, b) => '<div class="ca-box"><div class="ca-box-head"><span class="ca-box-title">' + esc(t) + '</span></div><div class="ca-box-body">' + b + '</div></div>';
        const badges = (map, fn) => '<div class="ca-badge-row">' + Object.keys(map).map(k => fn(k)).join('') + '</div>';
        el.innerHTML =
            '<h2 class="section-title" style="margin:2rem 0 1rem">Status vocabulary</h2><div class="ca-gallery-grid">' +
            box('AccessStatusBadge · lifecycle', badges(LIFECYCLE, AccessStatusBadge)) + box('CardStatusBadge · application credential', badges(CREDENTIAL, CardStatusBadge)) +
            box('SyncStatusBadge · device', badges(SYNC, SyncStatusBadge)) + box('VerificationStatusBadge', badges(VERIFICATION, VerificationStatusBadge)) +
            box('Device health', badges(DEVICE_HEALTH, k => badge(DEVICE_HEALTH, k))) + '</div>' +
            '<h2 class="section-title" style="margin:2rem 0 1rem">Mismatch states</h2><div class="ca-gallery-grid">' +
            [{ kind: 'CARD', title: 'Card Mismatch', appLabel: 'App Card', deviceLabel: 'Device Card', app: '••••3163', device: '••••9042', note: 'Nomor kartu di SecureGate berbeda dengan yang tersimpan di terminal Hikvision.' },
             { kind: 'ACCESS', title: 'Access Mismatch', appLabel: 'App Access', deviceLabel: 'Device Access', app: 'Gedung B', device: 'Gedung A + Gedung B', note: 'Hak akses di perangkat tidak sama dengan hak akses yang disetujui di SecureGate.' },
             { kind: 'PERSON', title: 'Person Mismatch', appLabel: 'App Person', deviceLabel: 'Device Person', app: 'Karyawan Contoh 05 (CONTOH-005)', device: 'CONTOH5 (nama di terminal)', note: 'Identitas orang di perangkat tidak cocok. Perlu verifikasi manual.' }].map(MismatchAlert).join('') + '</div>' +
            '<h2 class="section-title" style="margin:2rem 0 1rem">Access verification</h2><div class="ca-gallery-grid">' + VerificationSummary(sample, { can }) + VerificationSummary(oos, { can }) + '</div>' +
            '<h2 class="section-title" style="margin:2rem 0 1rem">Error states</h2><div class="ca-gallery-grid">' + Object.keys(ERRORS).map(k => ErrorState(k)).join('') + '</div>' +
            '<h2 class="section-title" style="margin:2rem 0 1rem">Empty states</h2><div class="ca-gallery-grid">' + Object.keys(EMPTY).map(k => box(k, EmptyState(k))).join('') + '</div>' +
            '<h2 class="section-title" style="margin:2rem 0 1rem">Loading & progress</h2><div class="ca-gallery-grid">' +
            box('KPI loading', CardAccessKpi(null).replace('ca-kpi-grid', 'ca-kpi-grid" style="grid-template-columns:repeat(3,1fr)')) +
            box('Table loading', '<table class="ca-table"><tbody>' + TableSkeleton(3, 3) + '</tbody></table>') +
            box('Detail loading', DetailSkeleton()) +
            box('Sync progress', SyncProgress([{ label: 'Registering Credential', state: 'done' }, { label: 'Creating Access Assignment', state: 'done' }, { label: 'Synchronizing Hikvision', state: 'run' }, { label: 'Verifying Device', state: 'wait' }])) +
            box('Partial failure', SyncProgress([{ label: 'Registering Credential', state: 'done' }, { label: 'Creating Access Assignment', state: 'done' }, { label: 'Synchronizing Hikvision', state: 'fail', note: 'Terminal tidak merespons.' }, { label: 'Verifying Device', state: 'skip' }])) +
            box('NFC states', ['NFC_READY', 'WAITING', 'VALIDATING', 'READY', 'ERROR'].map(s => NfcScanPanel(s, true)).join('')) + '</div>' +
            '<h2 class="section-title" style="margin:2rem 0 1rem">Permission states</h2><div class="ca-gallery-grid">' + Object.keys(CAPABILITY_PERMISSION).map(c => PermissionDenied(c)).join('') + '</div>';
    }

    // ======================================================================
    // 11. Public API
    // ======================================================================
    let page = null;
    const CardAccess = {
        // Called from dashboard.js switchTab(). Mounts once, then refreshes.
        load: function (rootId) {
            const root = document.getElementById(rootId || 'cardAccessRoot');
            if (!root) return;
            if (page && page.root === root) { page.loadTab(page.state.tab); return page; }
            const cfg = global.APP_CONFIG || {};
            page = new CardAccessPage(root, {
                adapter: cfg.cardAccessPreview ? createPreviewAdapter() : ContractPendingAdapter,
                permissions: cfg.permissions,
                role: cfg.admin && cfg.admin.role,
                toast: typeof global.showToast === 'function' ? global.showToast : null,
            });
            return page;
        },
        mount: function (root, options) { page = new CardAccessPage(root, options); return page; },
        createPreviewAdapter,
        ContractPendingAdapter,
        ADAPTER_METHODS,
        renderStateGallery,
        // Exposed for tests / future integration.
        model: { LIFECYCLE, CREDENTIAL, SYNC, VERIFICATION, MATCH, DEVICE_HEALTH, ACTIVITY, ERRORS, EMPTY, CAPABILITY_PERMISSION },
        resolveDisplayState,
        collectMismatches,
        maskId,
    };
    global.CardAccess = CardAccess;
})(window);
