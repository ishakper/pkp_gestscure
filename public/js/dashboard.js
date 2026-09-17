/**
 * Centralized Access Door Management System - Dashboard Client Controller
 * Interfaces with Laravel Sanctum & RESTful API v1
 */

const API_BASE = '/api/v1';
let APP_TOKEN = window.APP_CONFIG?.apiToken || sessionStorage.getItem('api_token') || '';

// State Cache
let state = {
    doors: [],
    doorsLookup: [],
    employees: [],
    accessLogs: [],
    activityLogs: [],
    tasks: [],
    pendingRemoteUnlockDoor: null,
    employeePage: 1,
    employeePagination: null,
    metrics: {
        totalUsers: 0,
        activeDoors: 0,
        totalDoors: 4,
        grantedLogs: 0,
        deniedLogs: 0,
    },
    activeTab: 'overviewTab',
    selectedEmployeeForAssign: null,
    searchDebounceTimer: null,
    organization: { buildings: [], divisions: [], positions: [] },
    onboarding: {
        metrics: {},
        cases: [],
        contracts: [],
        documents: [],
        expiringContracts: [],
        currentCase: null
    },
};

// ==========================================
// Security & Sanitization Utilities (XSS Prevention)
// ==========================================
function escapeHtml(str) {
    if (str === null || str === undefined) return '';
    return String(str)
        .replace(/&/g, '&amp;')
        .replace(/</g, '&lt;')
        .replace(/>/g, '&gt;')
        .replace(/"/g, '&quot;')
        .replace(/'/g, '&#039;');
}

// ==========================================
// Toast Notification Utility
// ==========================================
function showToast(message, type = 'success', duration = 3500) {
    const container = document.getElementById('toastContainer');
    if (!container) return;

    const toast = document.createElement('div');
    toast.className = `toast toast-${type}`;

    const icons = {
        success: '✓',
        error: '✕',
        warning: '⚠',
        info: 'ℹ',
    };

    toast.innerHTML = `
        <div class="toast-icon">${icons[type] || 'ℹ'}</div>
        <div class="toast-content">
            <div class="toast-title">${escapeHtml(type.toUpperCase())}</div>
            <div class="toast-message">${escapeHtml(message)}</div>
        </div>
        <button class="toast-close" onclick="this.parentElement.remove()">×</button>
    `;

    container.appendChild(toast);

    setTimeout(() => {
        toast.classList.add('toast-show');
    }, 10);

    setTimeout(() => {
        toast.classList.remove('toast-show');
        setTimeout(() => toast.remove(), 300);
    }, duration);
}

let isRedirectingToLogin = false;

// ==========================================
// Centralized API Client (Fetch with Auth)
// ==========================================
async function apiFetch(endpoint, options = {}) {
    const url = endpoint.startsWith('http') ? endpoint : `${API_BASE}${endpoint}`;

    const headers = {
        'Accept': 'application/json',
        'Content-Type': 'application/json',
        ...(APP_TOKEN ? { 'Authorization': `Bearer ${APP_TOKEN}` } : {}),
        ...options.headers,
    };

    try {
        const response = await fetch(url, { ...options, headers });

        // Handle 401 Unauthorized -> redirect to login (debounced to avoid toast stack)
        if (response.status === 401) {
            if (!isRedirectingToLogin) {
                isRedirectingToLogin = true;
                showToast('Sesi autentikasi telah berakhir. Mengalihkan ke halaman login...', 'error');
                setTimeout(() => {
                    window.location.href = '/login';
                }, 800);
            }
            throw new Error('Unauthorized');
        }

        const data = await response.json().catch(() => ({}));

        if (!response.ok) {
            const errorMsg = data.message || `Request failed with status ${response.status}`;
            throw new Error(errorMsg);
        }

        return data;
    } catch (err) {
        if (err.message !== 'Unauthorized') {
            console.error(`API Error [${endpoint}]:`, err);
        }
        throw err;
    }
}

// ==========================================
// Metric Cards Updater
// ==========================================
async function updateMetricCards() {
    try {
        const [res, attendance] = await Promise.all([
            apiFetch('/admin/dashboard-metrics'),
            apiFetch('/attendance/metrics'),
        ]);
        if (res.status === 'success') {
            const data = res.data;
            const activeUserMetric = document.getElementById('metricActiveEmployees');
            if (activeUserMetric) activeUserMetric.innerText = data.activeEmployees ?? data.totalUsers ?? 0;
            const userMetric = document.getElementById('metricTotalUsers');
            if (userMetric) userMetric.innerText = data.totalUsers ?? 0;

            const regCredMetric = document.getElementById('metricRegisteredCredentials');
            if (regCredMetric) regCredMetric.innerText = data.registeredCredentials ?? 0;

            const doorMetric = document.getElementById('metricActiveDoors');
            if (doorMetric) doorMetric.innerText = `${data.activeDoors ?? 0} / ${data.totalDoors ?? 0}`;

            const deniedMetric = document.getElementById('metricDeniedLogs');
            if (deniedMetric) deniedMetric.innerText = data.deniedLogs ?? 0;
        }
        if (attendance.success) {
            const today = attendance.data?.today || {};
            const values = {
                metricAttendancePresent: Number(today.present || 0) + Number(today.late || 0),
                metricAttendanceLate: Number(today.late || 0),
                metricAttendanceAbsent: Number(today.absent || 0),
                metricAttendanceCheckout: Number(today.checkout || 0),
            };
            Object.entries(values).forEach(([id, value]) => {
                const element = document.getElementById(id);
                if (element) element.innerText = value;
            });
        }
    } catch (err) {
        console.error('Failed to fetch dashboard metrics:', err);
    }
}

// ==========================================
// Section 1: Doors Monitoring & Control
// ==========================================
async function loadDoors() {
    const grid = document.getElementById('doorsGrid');
    const overviewGrid = document.getElementById('overviewDoorsGrid');
    if (!grid && !overviewGrid) return;

    [grid, overviewGrid].filter(Boolean).forEach(target => {
        target.innerHTML = '<div class="loading-td"><div class="spinner"></div> CHECKING terminal Gedung B...</div>';
    });

    try {
        const res = await apiFetch('/admin/doors');
        if (res.status === 'success') {
            state.doors = res.data;
            renderDoorCards(state.doors);
            refreshDoorFilters(state.doors);
            updateMetricCards();
        }
    } catch (err) {
        if (grid) grid.innerHTML = `<div class="error-placeholder">Gagal memuat status pintu: ${err.message}</div>`;
        if (overviewGrid) overviewGrid.innerHTML = `<div class="error-placeholder">Gagal memuat status pintu: ${err.message}</div>`;
    }
}

function refreshDoorFilters(doors) {
    const filters = [
        [document.getElementById('employeeDoorFilter'), 'Semua Hak Akses Pintu'],
        [document.getElementById('logDoorFilter'), 'Semua Pintu'],
        [document.getElementById('logDoorFilterTab'), 'Semua Pintu'],
    ];
    filters.forEach(([select, label]) => {
        if (!select) return;
        const selected = select.value;
        select.innerHTML = `<option value="">${label}</option>` + doors.map(door => {
            const id = escapeHtml(door.door_id);
            const location = escapeHtml(door.building_name || door.location || '-');
            return `<option value="${id}">${id} (${location})</option>`;
        }).join('');
        if (Array.from(select.options).some(option => option.value === selected)) select.value = selected;
    });
}

function renderDoorCards(doors) {
    const renderTargets = [document.getElementById('doorsGrid'), document.getElementById('overviewDoorsGrid')].filter(Boolean);
    if (renderTargets.length === 0) return;

    if (!Array.isArray(doors) || doors.length === 0) {
        renderTargets.forEach(target => target.innerHTML = '<div class="empty-td">Belum ada terminal pintu terkonfigurasi.</div>');
        return;
    }

    const canManageDevices = (window.APP_CONFIG?.permissions || []).includes('device.manage');
    const html = doors.map(door => {
        const isPrimaryDeploymentDoor = door.door_id === 'DOOR-B';
        const healthStatus = door.health_status || (door.connection_status === 'online' ? 'online' : 'offline');
        const isOnline = isPrimaryDeploymentDoor && healthStatus === 'online';
        const unlockDisabled = !isOnline;
        const isMaintenance = Boolean(door.is_manual_override);
        const badgeClass = isPrimaryDeploymentDoor && isOnline ? 'status-online' : 'status-offline';
        const statusLabel = isPrimaryDeploymentDoor ? (healthStatus === 'auth_error' ? 'AUTH ERROR' : (isOnline ? 'ONLINE' : 'OFFLINE')) : 'PLANNED / NOT ACTIVE';
        const safeDoorId = escapeHtml(door.door_id);
        const safeDoorName = escapeHtml(door.door_name || door.name || 'Tanpa nama');
        const safeLocation = escapeHtml(door.building_name || door.location || '-');
        const safeDeviceIp = escapeHtml(door.device_ip || '-');
        const safeModel = escapeHtml(door.device_model || 'DS-K1T804AMF');
        const safeTotalUsers = Number(door.total_assigned_users || door.employees_count || door.door_assignments_count) || 0;
        const lastCheckedStr = door.last_checked_at
            ? new Date(door.last_checked_at).toLocaleString('id-ID', { dateStyle: 'short', timeStyle: 'medium' })
            : 'Belum pernah diperiksa';

        const safeEventCount = Number(door.event_count || 0);

        return `
            <article class="card door-card" id="door-card-${safeDoorId}" data-connection-state="${statusLabel.toLowerCase()}">
                <div class="card-header">
                    <div class="door-code-badge"><span class="door-chip">${safeDoorId}</span><span class="door-loc">${safeLocation}</span></div>
                    <span class="status-badge ${badgeClass}"><span class="status-dot"></span> ${statusLabel}</span>
                </div>
                <div class="card-value door-name-title">${safeDoorName}</div>
                ${!isPrimaryDeploymentDoor ? '<div class="maintenance-note">Gedung B deployment target hanya. Terminal ini planned / not active.</div>' : ''}
                ${isMaintenance ? '<div class="maintenance-note">⚠ Maintenance override aktif — status koneksi tetap berasal dari terminal.</div>' : ''}
                <div class="door-specs">
                    <div class="spec-item"><span class="spec-label">IP Terminal</span><code class="spec-code">${safeDeviceIp}</code></div>
                    <div class="spec-item"><span class="spec-label">Hardware</span><span class="spec-val">${safeModel}</span></div>
                    <div class="spec-item"><span class="spec-label">Assigned Users</span><span class="spec-val highlight">${safeTotalUsers} Pegawai</span></div>
                    <div class="spec-item"><span class="spec-label">Total Events</span><span class="spec-val highlight">${safeEventCount} Event Logs</span></div>
                    <div class="spec-item"><span class="spec-label">Last Communication</span><span class="spec-val">${escapeHtml(lastCheckedStr)}</span></div>
                </div>
                <div class="door-actions">
                    ${canManageDevices ? `<button class="btn-action" onclick="openFacilityModal('${safeDoorId}')">✎ Edit</button>` : ''}
                    ${canManageDevices ? `<button class="btn-action" onclick="toggleDoorStatus('${safeDoorId}', ${!isMaintenance})">⚡ ${isMaintenance ? 'End Maintenance' : 'Maintenance'}</button>` : ''}
                    ${canManageDevices ? `<button class="btn-action btn-unlock" onclick="openRemoteUnlockModal('${safeDoorId}')" ${unlockDisabled ? 'disabled aria-disabled="true" title="Terminal belum terhubung"' : 'title="Buka relay pintu melalui konfirmasi"'}>🔓 Remote Unlock</button>` : ''}
                    ${canManageDevices ? `<button class="btn-action btn-ping" onclick="pingSingleDoor('${safeDoorId}', this)" title="Pemeriksaan ISAPI eksplisit">📡 Diagnose</button>` : ''}
                    <button class="btn-action" onclick="openDoorLogs('${safeDoorId}')">View Logs</button>
                    <button class="btn-action" onclick="openDoorUsers('${safeDoorId}')">Sync Users</button>
                </div>
            </article>`;
    }).join('');

    renderTargets.forEach(target => target.innerHTML = html);
}

async function toggleDoorStatus(doorId, enabled) {
    try {
        const res = await apiFetch(`/admin/doors/${encodeURIComponent(doorId)}/status`, {
            method: 'PATCH',
            body: JSON.stringify({ is_manual_override: Boolean(enabled) })
        });
        if (res.status === 'success') {
            showToast(`Maintenance override ${enabled ? 'diaktifkan' : 'dinonaktifkan'} untuk ${doorId}.`, 'success');
            await loadDoors();
        }
    } catch (err) {
        showToast('Perubahan maintenance tidak dapat disimpan.', 'error');
    }
}

function openDoorLogs(doorId) {
    const filter = document.getElementById('logDoorFilter');
    if (filter) filter.value = doorId;
    switchTab('logsTab');
    loadAccessLogs();
}

function openDoorUsers(doorId) {
    const filter = document.getElementById('employeeDoorFilter');
    if (filter) filter.value = doorId;
    switchTab('employeesTab');
    loadEmployees();
}

function openRemoteUnlockModal(doorId) {
    const door = state.doors.find(item => String(item.door_id) === String(doorId));
    const isOnline = door && (door.connection_status === 'online' || door.status === 'online');
    if (!door || !isOnline) {
        showToast('Remote unlock diblokir: Terminal belum terhubung.', 'warning');
        return;
    }

    state.pendingRemoteUnlockDoor = door;
    document.getElementById('remoteUnlockDoorIdentity').textContent = door.door_name || door.name || door.door_id;
    document.getElementById('remoteUnlockDoorCode').textContent = door.door_id;
    document.getElementById('remoteUnlockDoorLocation').textContent = door.building_name || door.location || '-';
    document.getElementById('remoteUnlockDoorStatus').textContent = 'ONLINE — siap menerima perintah';
    openModal('remoteUnlockModal');
}

function cancelRemoteUnlock() {
    state.pendingRemoteUnlockDoor = null;
    closeModal('remoteUnlockModal');
}

async function confirmRemoteUnlock() {
    const door = state.pendingRemoteUnlockDoor;
    const button = document.getElementById('confirmRemoteUnlockButton');
    if (!door || !button) return;

    button.disabled = true;
    button.textContent = '⏳ Mengirim perintah...';
    try {
        const res = await apiFetch(`/admin/doors/${encodeURIComponent(door.door_id)}/open`, { method: 'POST' });
        if (res.status === 'success') {
            showToast(`Perintah remote unlock ${door.door_id} berhasil dikirim.`, 'success');
            cancelRemoteUnlock();
            await Promise.all([loadDoors(), loadAccessLogs(), updateMetricCards(), loadActivityLogs()]);
        }
    } catch (err) {
        showToast('Remote unlock gagal. Periksa izin dan koneksi terminal, lalu coba kembali.', 'error');
    } finally {
        button.disabled = false;
        button.textContent = 'Konfirmasi & Buka Pintu';
    }
}

async function pingSingleDoor(doorId, btn) {
    const originalText = btn ? btn.innerHTML : '';
    if (btn) {
        btn.disabled = true;
        btn.innerHTML = `⏳ Cek...`;
    }

    try {
        const res = await apiFetch(`/admin/doors/${doorId}/check-connection`, {
            method: 'POST',
        });

        if (res.status === 'success' || res.is_online) {
            showToast(`✓ Terminal ${doorId} online & responsif via ISAPI!`, 'success');
        } else {
            showToast(`⚠ Terminal ${doorId} offline: ${res.message}`, 'warning');
        }

        await loadDoors();
    } catch (err) {
        showToast(`Gagal memeriksa terminal ${doorId}: ${err.message}`, 'error');
    } finally {
        if (btn) {
            btn.disabled = false;
            btn.innerHTML = originalText;
        }
    }
}

async function checkAllDoors(btn) {
    const originalText = btn ? btn.innerHTML : '';
    if (btn) {
        btn.disabled = true;
        btn.innerHTML = `<div class="spinner-sm"></div> Mengaudit ISAPI...`;
    }

    try {
        const res = await apiFetch('/admin/doors/check-all', {
            method: 'POST',
        });

        if (res.status === 'success') {
            showToast(`Audit ISAPI Selesai: ${res.online_count}/${res.total_audited} terminal online.`, 'success');
            await loadDoors();
        }
    } catch (err) {
        showToast(`Gagal mengaudit koneksi: ${err.message}`, 'error');
    } finally {
        if (btn) {
            btn.disabled = false;
            btn.innerHTML = originalText;
        }
    }
}

// ==========================================
// Section 2: User & Privilege Management
// ==========================================
async function loadOrganizationLookup() {
    try {
        const res = await apiFetch('/user-management/organization/lookup');
        if (res.status !== 'success') return;
        state.organization = res.data;
        for (const [field, values] of [['empBuilding', res.data.buildings], ['empDivision', res.data.divisions], ['empPosition', res.data.positions]]) {
            const el = document.getElementById(field); if (!el) continue;
            el.innerHTML = '<option value="">Pilih</option>' + values.map(x => `<option value="${x.id}">${escapeHtml(x.name)}</option>`).join('');
        }
    } catch (err) { console.warn('Organization lookup unavailable', err); }
}

async function loadEmployees(page = state.employeePage) {
    const tbody = document.getElementById('employeesTableBody');
    const countBadge = document.getElementById('employeeCountText');
    const searchVal = document.getElementById('employeeSearch')?.value.trim() || '';
    const doorFilter = document.getElementById('employeeDoorFilter')?.value || '';
    state.employeePage = Math.max(1, Number(page) || 1);

    if (tbody) {
        tbody.innerHTML = `<tr><td colspan="7" class="loading-td"><div class="spinner"></div> Memuat data karyawan & hak akses...</td></tr>`;
    }

    try {
        let url = `/user-management/users?per_page=20&page=${state.employeePage}`;
        if (searchVal) url += `&search=${encodeURIComponent(searchVal)}`;
        if (doorFilter) url += `&door_id=${encodeURIComponent(doorFilter)}`;

        const res = await apiFetch(url);
        if (res.status === 'success') {
            state.employees = res.data;
            state.employeePagination = res.pagination || null;
            state.metrics.totalUsers = res.pagination?.total_records ?? state.employees.length;
            updateMetricCards();

            if (countBadge) {
                countBadge.innerText = `Total: ${state.metrics.totalUsers} Karyawan`;
            }

            renderEmployeesTable(state.employees);
            renderEmployeePagination();
        }
    } catch (err) {
        if (tbody) {
            tbody.innerHTML = `<tr><td colspan="7" class="error-td">Gagal memuat data karyawan: ${err.message}</td></tr>`;
        }
    }
}

function renderEmployeesTable(employees) {
    const targets = [
        document.getElementById('employeesTableBody'),
        document.getElementById('fullEmployeesTableBody')
    ].filter(Boolean);

    if (targets.length === 0) return;

    if (employees.length === 0) {
        targets.forEach(t => {
            t.innerHTML = `<tr><td colspan="7" class="empty-td">Tidak ada data karyawan ditemukan.</td></tr>`;
        });
        return;
    }

    const html = employees.map(emp => {
        // Biometric Badges
        const hasFp = emp.biometric_status?.fingerprint_enrolled;
        const hasCard = emp.biometric_status?.card_enrolled;
        const fpBadge = hasFp
            ? `<span class="badge badge-success" title="Sidik jari aktif"><span class="badge-dot"></span> FP</span>`
            : `<span class="badge badge-dim" title="Belum enroll sidik jari">No FP</span>`;
        const cardBadge = hasCard
            ? `<span class="badge badge-info" title="Kartu RFID: ${emp.card_no || 'Tercatat'}"><span class="badge-dot"></span> Kartu</span>`
            : `<span class="badge badge-dim" title="Belum enroll kartu">No Card</span>`;

        // Door Assignment Badges
        let doorBadges = '<span class="badge badge-dim">Belum Diberi Akses</span>';
        if (emp.door_assign && emp.door_assign.length > 0) {
            doorBadges = emp.door_assign.map(d => {
                let badgeCls = 'badge-pending';
                let icon = '⏳';
                let tooltip = `Status: ${d.sync_status}`;

                if (d.sync_status === 'synced') {
                    badgeCls = 'badge-synced';
                    icon = '✓';
                } else if (d.sync_status === 'failed') {
                    badgeCls = 'badge-failed';
                    icon = '✕';
                    tooltip = d.last_sync_error ? `Error: ${d.last_sync_error}` : 'Sinkronisasi gagal ke hardware';
                }

                return `
                    <span class="badge ${badgeCls} sync-pill" title="${tooltip}">
                        ${icon} ${d.door_id}: ${d.sync_status}
                    </span>
                `;
            }).join(' ');
        }

        const statusBadge = (emp.employment_status === 'ACTIVE' || !emp.employment_status)
            ? `<span class="badge badge-success" style="font-size:0.72rem;padding:0.15rem 0.5rem;"><span class="badge-dot"></span> Aktif</span>`
            : (emp.employment_status === 'INACTIVE'
                ? `<span class="badge badge-danger" style="font-size:0.72rem;padding:0.15rem 0.5rem;"><span class="badge-dot"></span> Non-Aktif</span>`
                : `<span class="badge badge-warning" style="font-size:0.72rem;padding:0.15rem 0.5rem;"><span class="badge-dot"></span> ${escapeHtml(emp.employment_status)}</span>`);

        const safeUserId = escapeHtml(emp.user_id || emp.employee_id || '-');
        const safeNik = escapeHtml(emp.nik || '-');
        const safeName = escapeHtml(emp.name || 'Unnamed');
        const safeCardNo = escapeHtml(emp.card_no || '');
        const safeDept = escapeHtml(emp.department || '-');
        const safeRole = escapeHtml(emp.role || emp.role_jabatan || 'Staff');
        const empId = Number(emp.id);

        return `
            <tr id="emp-row-${empId}">
                <td>
                    <div class="user-id-box">
                        <strong>${safeUserId}</strong>
                        <span class="user-nik">${safeNik}</span>
                    </div>
                </td>
                <td>
                    <div class="user-name-box">
                        <span class="name-text">${safeName}</span>
                        ${safeCardNo ? `<span class="card-no-sub">💳 ${safeCardNo}</span>` : ''}
                    </div>
                </td>
                <td>${safeDept}</td>
                <td>
                    <div style="display:flex;flex-direction:column;gap:0.3rem;align-items:flex-start;">
                        <span>${safeRole}</span>
                        ${statusBadge}
                    </div>
                </td>
                <td>
                    <div class="bio-pill-group">
                        ${fpBadge}
                        ${cardBadge}
                    </div>
                </td>
                <td>
                    <div class="door-pills-wrap">
                        ${doorBadges}
                    </div>
                </td>
                <td style="text-align: right;">
                    <div class="action-btns" style="justify-content: flex-end;">
                        <button class="btn-sm btn-assign" onclick="openDoorAssignmentModal(${empId})" title="Atur Akses Pintu Fisik">
                            🚪 Akses
                        </button>
                        <button class="btn-sm btn-edit" onclick="openEditEmployeeModal(${empId})" title="Edit Profil & Biometrik">
                            ✏️ Edit
                        </button>
                        <button class="btn-sm btn-delete" onclick="handleDeleteEmployeeBtn(${empId}, this)" data-name="${safeName}" title="Hapus Pengguna">
                            🗑️
                        </button>
                    </div>
                </td>
            </tr>
        `;
    }).join('');

    targets.forEach(t => t.innerHTML = html);
}

function handleDeleteEmployeeBtn(id, btn) {
    const name = btn ? btn.getAttribute('data-name') : 'karyawan ini';
    deleteEmployee(id, name || 'karyawan ini');
}

function handleEmployeeSearch() {
    clearTimeout(state.searchDebounceTimer);
    state.searchDebounceTimer = setTimeout(() => {
        loadEmployees(1);
    }, 300);
}

function renderEmployeePagination() {
    const pagination = state.employeePagination;
    document.querySelectorAll('.employee-pagination').forEach(container => {
        if (!pagination || Number(pagination.total_pages || 1) <= 1) {
            container.innerHTML = '';
            return;
        }
        const current = Number(pagination.current_page || 1);
        const total = Number(pagination.total_pages || 1);
        container.innerHTML = `
            <div style="display:flex;justify-content:flex-end;align-items:center;gap:.75rem;padding:1rem;">
                <button class="btn-secondary" ${current <= 1 ? 'disabled' : ''} onclick="loadEmployees(${current - 1})">← Sebelumnya</button>
                <span style="color:var(--text-muted);font-size:.82rem;">Halaman ${current} dari ${total}</span>
                <button class="btn-secondary" ${current >= total ? 'disabled' : ''} onclick="loadEmployees(${current + 1})">Berikutnya →</button>
            </div>`;
    });
}

// ==========================================
// Door Assignment Modal Workflow
// ==========================================
async function openDoorAssignmentModal(empId) {
    const employee = state.employees.find(e => e.id === empId || e.id == empId);
    if (!employee) return;

    state.selectedEmployeeForAssign = employee;

    document.getElementById('assignModalEmpName').innerText = `${employee.name} (${employee.user_id} - ${employee.nik})`;
    document.getElementById('assignModalEmpDept').innerText = `Departemen: ${employee.department} | Role: ${employee.role || 'Staff'}`;

    const container = document.getElementById('doorCheckboxesContainer');
    container.innerHTML = '<div class="spinner"></div> Memuat daftar pintu...';

    document.getElementById('doorAssignModal').classList.add('active');

    // Fetch Lookup Doors
    try {
        const res = await apiFetch('/user-management/doors-lookup');
        if (res.status === 'success') {
            state.doorsLookup = res.data;
            renderDoorAssignmentCheckboxes(state.doorsLookup, employee);
        }
    } catch (err) {
        container.innerHTML = `<div class="error-message">Gagal memuat lookup pintu: ${err.message}</div>`;
    }
}

function renderDoorAssignmentCheckboxes(doors, employee) {
    const container = document.getElementById('doorCheckboxesContainer');
    if (!container) return;

    // Get currently assigned door IDs / codes
    const assignedDoorIds = (employee.door_assign || []).map(d => d.door_id);

    container.innerHTML = doors.map(door => {
        const isChecked = assignedDoorIds.includes(door.door_id) || assignedDoorIds.includes(door.id);
        const safeDoorId = escapeHtml(door.door_id);
        const safeDoorName = escapeHtml(door.name || '');
        const safeDoorLoc = escapeHtml(door.location || '');
        const operationalDoor = state.doors.find(item => String(item.door_id) === String(door.door_id));
        const isOnline = operationalDoor && (operationalDoor.connection_status === 'online' || operationalDoor.status === 'online');
        const assignment = (employee.door_assign || []).find(item => String(item.door_id) === String(door.door_id));
        const syncLabel = assignment ? escapeHtml(assignment.sync_status || 'pending') : 'not assigned';

        return `
            <label class="door-checkbox-card ${isChecked ? 'selected' : ''}">
                <input type="checkbox" name="assign_door_ids" value="${safeDoorId}" ${isChecked ? 'checked' : ''} onchange="this.parentElement.classList.toggle('selected', this.checked)">
                <div class="checkbox-door-info">
                    <div class="checkbox-door-code">${safeDoorId}</div>
                    <div class="checkbox-door-name">${safeDoorName}</div>
                    <div class="checkbox-door-loc">${safeDoorLoc}</div>
                    <div class="checkbox-door-loc">${isOnline ? '🟢 Online' : '🔴 Offline'} · Sync: ${syncLabel}</div>
                </div>
            </label>
        `;
    }).join('');
}

async function submitDoorAssignment(e) {
    e.preventDefault();
    if (!state.selectedEmployeeForAssign) return;

    const saveBtn = document.getElementById('btnSaveDoorAssignment');
    const originalText = saveBtn.innerHTML;
    saveBtn.disabled = true;
    saveBtn.innerHTML = `<div class="spinner-sm"></div> Memproses Sinkronisasi ISAPI...`;

    const checkboxes = document.querySelectorAll('input[name="assign_door_ids"]:checked');
    const selectedDoors = Array.from(checkboxes).map(cb => cb.value);

    try {
        // Send payload to assign doors
        const res = await apiFetch('/user-management/assign-doors', {
            method: 'POST',
            body: JSON.stringify({
                employee_id: state.selectedEmployeeForAssign.id,
                door_ids: selectedDoors,
            })
        });

        if (res.status === 'success') {
            showToast(`Akses pintu berhasil diperbarui untuk ${state.selectedEmployeeForAssign.name}.`, 'success');
            closeModal('doorAssignModal');
            await loadEmployees();
            await loadDoors();
        }
    } catch (err) {
        showToast(`Gagal assign akses pintu: ${err.message}`, 'error');
    } finally {
        saveBtn.disabled = false;
        saveBtn.innerHTML = originalText;
    }
}

async function revokeAllEmployeeDoors() {
    if (!state.selectedEmployeeForAssign) return;

    if (!confirm(`Cabut seluruh hak akses pintu untuk ${state.selectedEmployeeForAssign.name}?`)) {
        return;
    }

    try {
        const res = await apiFetch('/user-management/revoke-doors', {
            method: 'POST',
            body: JSON.stringify({
                employee_id: state.selectedEmployeeForAssign.id,
            })
        });

        if (res.status === 'success') {
            showToast(`Seluruh izin pintu untuk ${state.selectedEmployeeForAssign.name} berhasil dicabut.`, 'success');
            closeModal('doorAssignModal');
            await loadEmployees();
            await loadDoors();
        }
    } catch (err) {
        showToast(`Gagal mencabut hak akses: ${err.message}`, 'error');
    }
}

async function revokeSingleDoor(empId, doorId) {
    if (!confirm(`Cabut izin akses pintu ${doorId} untuk karyawan ini?`)) {
        return;
    }

    try {
        const res = await apiFetch('/user-management/revoke-doors', {
            method: 'POST',
            body: JSON.stringify({
                employee_id: empId,
                door_id: doorId,
            })
        });

        if (res.status === 'success') {
            showToast(`Izin akses pintu ${doorId} berhasil dicabut.`, 'success');
            await loadEmployees();
            await loadDoors();
        }
    } catch (err) {
        showToast(`Gagal mencabut akses ${doorId}: ${err.message}`, 'error');
    }
}

// ==========================================
// Employee CRUD Modals
// ==========================================
function openAddEmployeeModal() {
    document.getElementById('employeeModalTitle').innerText = 'Tambah Karyawan Baru';
    document.getElementById('empDbId').value = '';
    document.getElementById('empUserId').value = 'USR-' + Math.floor(1000 + Math.random() * 9000);
    document.getElementById('empNik').value = 'NIK-' + Math.floor(882000 + Math.random() * 999);
    document.getElementById('empName').value = '';
    document.getElementById('empCardNo').value = 'CARD-' + Math.floor(100000 + Math.random() * 900000);
    document.getElementById('empRole').value = 'Staff';
    ['empEmail','empPhone','empBuilding','empDivision','empPosition','empEmploymentType','empHireDate'].forEach(id => document.getElementById(id).value = '');
    document.getElementById('empEmploymentStatus').value = 'ACTIVE';
    document.getElementById('empFp').checked = true;
    document.getElementById('empCard').checked = true;
    document.getElementById('employeeModal').classList.add('active');
}

function openEditEmployeeModal(empId) {
    const emp = state.employees.find(e => e.id === empId || e.id == empId);
    if (!emp) return;

    document.getElementById('employeeModalTitle').innerText = 'Edit Data Karyawan';
    document.getElementById('empDbId').value = emp.id;
    document.getElementById('empUserId').value = emp.user_id;
    document.getElementById('empNik').value = emp.nik;
    document.getElementById('empName').value = emp.name;
    document.getElementById('empCardNo').value = emp.card_no || '';
    document.getElementById('empDept').value = emp.department;
    document.getElementById('empRole').value = emp.role || emp.role_jabatan || 'Staff';
    document.getElementById('empEmail').value = emp.email || ''; document.getElementById('empPhone').value = emp.phone || '';
    document.getElementById('empBuilding').value = emp.building?.id || ''; document.getElementById('empDivision').value = emp.division?.id || ''; document.getElementById('empPosition').value = emp.position?.id || '';
    document.getElementById('empEmploymentType').value = emp.employment_type || ''; document.getElementById('empEmploymentStatus').value = emp.employment_status || 'ACTIVE'; document.getElementById('empHireDate').value = emp.hire_date || '';
    document.getElementById('empFp').checked = Boolean(emp.biometric_status?.fingerprint_enrolled);
    document.getElementById('empCard').checked = Boolean(emp.biometric_status?.card_enrolled);
    document.getElementById('employeeModal').classList.add('active');
}

async function saveEmployee(e) {
    e.preventDefault();
    const id = document.getElementById('empDbId').value;
    const payload = {
        employee_id: document.getElementById('empUserId').value,
        nik: document.getElementById('empNik').value,
        name: document.getElementById('empName').value,
        card_no: document.getElementById('empCardNo').value,
        email: document.getElementById('empEmail').value, phone: document.getElementById('empPhone').value,
        building_id: document.getElementById('empBuilding').value || null, division_id: document.getElementById('empDivision').value || null, position_id: document.getElementById('empPosition').value || null,
        employment_type: document.getElementById('empEmploymentType').value || null, employment_status: document.getElementById('empEmploymentStatus').value, hire_date: document.getElementById('empHireDate').value || null,
        department: document.getElementById('empDept').value,
        role_jabatan: document.getElementById('empRole').value,
        fingerprint_enrolled: document.getElementById('empFp').checked,
        card_enrolled: document.getElementById('empCard').checked,
    };

    const method = id ? 'PUT' : 'POST';
    const endpoint = id ? `/user-management/employees/${id}` : '/user-management/employees';

    try {
        const res = await apiFetch(endpoint, { method, body: JSON.stringify(payload) });
        if (res.status === 'success') {
            showToast(id ? 'Data karyawan berhasil diperbarui!' : 'Karyawan baru berhasil ditambahkan!', 'success');
            closeModal('employeeModal');
            await loadEmployees();
        }
    } catch (err) {
        showToast(`Gagal menyimpan data karyawan: ${err.message}`, 'error');
    }
}

async function deleteEmployee(id, name) {
    if (!confirm(`Apakah Anda yakin ingin menghapus data karyawan "${name}"?`)) return;

    try {
        const res = await apiFetch(`/user-management/employees/${id}`, { method: 'DELETE' });
        if (res.status === 'success') {
            showToast(`Karyawan "${name}" berhasil dinonaktifkan (Soft Deleted).`, 'success');
            await loadEmployees();
        }
    } catch (err) {
        showToast(`Gagal menghapus karyawan: ${err.message}`, 'error');
    }
}

// ==========================================
// Section 3: Security Access Logs & Filters
// ==========================================
function syncLogFilters(sourceEl) {
    if (!sourceEl) return;
    const val = sourceEl.value;
    const idMap = {
        'logDoorFilter': 'logDoorFilterTab',
        'logDoorFilterTab': 'logDoorFilter',
        'logStatusFilter': 'logStatusFilterTab',
        'logStatusFilterTab': 'logStatusFilter',
        'logAttendanceStateFilter': 'logAttendanceStateFilterTab',
        'logAttendanceStateFilterTab': 'logAttendanceStateFilter',
        'logUserSearch': 'logUserSearchTab',
        'logUserSearchTab': 'logUserSearch',
        'logStartDate': 'logStartDateTab',
        'logStartDateTab': 'logStartDate',
        'logEndDate': 'logEndDateTab',
        'logEndDateTab': 'logEndDate'
    };
    const targetId = idMap[sourceEl.id];
    if (targetId) {
        const targetEl = document.getElementById(targetId);
        if (targetEl) targetEl.value = val;
    }
}

async function loadAccessLogs() {
    const tbody = document.getElementById('logsTableBody');
    const recentTbody = document.getElementById('overviewLogsTableBody');
    const doorFilter = document.getElementById('logDoorFilter')?.value || document.getElementById('logDoorFilterTab')?.value || '';
    const statusFilter = document.getElementById('logStatusFilter')?.value || document.getElementById('logStatusFilterTab')?.value || '';
    const attendanceStateFilter = document.getElementById('logAttendanceStateFilter')?.value || document.getElementById('logAttendanceStateFilterTab')?.value || '';
    const userSearch = (document.getElementById('logUserSearch')?.value || document.getElementById('logUserSearchTab')?.value || '').trim();
    const startDate = document.getElementById('logStartDate')?.value || document.getElementById('logStartDateTab')?.value || '';
    const endDate = document.getElementById('logEndDate')?.value || document.getElementById('logEndDateTab')?.value || '';

    if (tbody) {
            tbody.innerHTML = `<tr><td colspan="8" class="loading-td"><div class="spinner"></div> Memuat event logs akses pintu...</td></tr>`;
    }

    try {
        let url = `/admin/access-logs?limit=40`;
        if (doorFilter) url += `&door_id=${encodeURIComponent(doorFilter)}`;
        if (statusFilter) url += `&status=${encodeURIComponent(statusFilter)}`;
        if (attendanceStateFilter) url += `&attendance_state=${encodeURIComponent(attendanceStateFilter)}`;
        if (userSearch) url += `&user=${encodeURIComponent(userSearch)}`;
        if (startDate) url += `&start_date=${encodeURIComponent(startDate)}`;
        if (endDate) url += `&end_date=${encodeURIComponent(endDate)}`;

        const res = await apiFetch(url);
        if (res.status === 'success') {
            state.accessLogs = res.data;
            renderAccessLogsTable(state.accessLogs);
            updateMetricCards();
        }
    } catch (err) {
        const errorHtml = `<tr><td colspan="8" class="error-td">Gagal memuat log akses: ${escapeHtml(err.message)}</td></tr>`;
        if (tbody) tbody.innerHTML = errorHtml;
        if (recentTbody) recentTbody.innerHTML = errorHtml;
    }
}

async function loadActivityLogs() {
    const tbody = document.getElementById('activityLogsTableBody');
    if (!tbody || !(window.APP_CONFIG?.permissions || []).includes('audit.view')) return;
    try {
        const res = await apiFetch('/admin/activity-logs?per_page=30');
        state.activityLogs = Array.isArray(res.data) ? res.data : [];
        if (state.activityLogs.length === 0) {
            tbody.innerHTML = '<tr><td colspan="5" class="empty-td">Belum ada aktivitas administratif.</td></tr>';
            return;
        }
        tbody.innerHTML = state.activityLogs.map(log => `
            <tr>
                <td>${escapeHtml(formatDateTime(log.timestamp))}</td>
                <td>${escapeHtml(log.admin?.name || 'System')}</td>
                <td><span class="badge badge-info">${escapeHtml(log.action || '-')}</span></td>
                <td>${escapeHtml(log.subject_type || '-')}${log.subject_id ? ` #${Number(log.subject_id)}` : ''}</td>
                <td class="audit-description">${escapeHtml(log.description || '-')}</td>
            </tr>`).join('');
    } catch (err) {
        tbody.innerHTML = '<tr><td colspan="5" class="error-td">Audit timeline tidak dapat dimuat.</td></tr>';
    }
}

async function refreshOperationalData(button) {
    const original = button?.innerHTML || '';
    if (button) { button.disabled = true; button.innerHTML = '⏳ Memuat data...'; }
    try {
        await Promise.all([loadDoors(), loadAccessLogs(), updateMetricCards(), loadActivityLogs()]);
        showToast('Data operasional terbaru berhasil dimuat.', 'success');
    } finally {
        if (button) { button.disabled = false; button.innerHTML = original; }
    }
}

async function loadTasks(page = 1) {
    const body = document.getElementById('tasksTableBody');
    if (!body) return;
    const params = new URLSearchParams({ page: String(page), per_page: '15' });
    const search = document.getElementById('taskSearch')?.value?.trim();
    const status = document.getElementById('taskStatusFilter')?.value;
    const priority = document.getElementById('taskPriorityFilter')?.value;
    if (search) params.set('search', search);
    if (status) params.set('status', status);
    if (priority) params.set('priority', priority);
    body.innerHTML = '<tr><td colspan="8" class="loading-td"><div class="spinner"></div> Memuat tasks...</td></tr>';
    try {
        const [list, metrics] = await Promise.all([apiFetch(`/tasks?${params}`), apiFetch('/tasks/metrics')]);
        state.tasks = Array.isArray(list.data?.data) ? list.data.data : [];
        renderTasks(state.tasks);
        renderTaskMetrics(metrics.data || {});
        renderTaskPagination(list.pagination || {});
    } catch (err) {
        body.innerHTML = `<tr><td colspan="8" class="error-td">Gagal memuat tasks: ${escapeHtml(err.message)}</td></tr>`;
    }
}

function renderTasks(tasks) {
    const body = document.getElementById('tasksTableBody');
    if (!body) return;
    if (!tasks.length) {
        body.innerHTML = '<tr><td colspan="8" class="empty-td">Belum ada task untuk ditampilkan.</td></tr>';
        return;
    }
    body.innerHTML = tasks.map(task => `<tr>
        <td><strong>${escapeHtml(task.title)}</strong><br><small class="text-muted">${escapeHtml(task.task_code)}</small></td>
        <td>${escapeHtml(task.employee?.name || '-')}</td>
        <td>${escapeHtml(task.project_name || '-')}</td>
        <td><span class="badge badge-info">${escapeHtml(task.priority)}</span></td>
        <td>${escapeHtml(task.status)}</td>
        <td>${Number(task.progress) || 0}%</td>
        <td>${escapeHtml(task.due_date || '-')}</td>
        <td><button class="btn-secondary" onclick="openTaskDetail(${Number(task.id)})">Detail</button></td>
    </tr>`).join('');
}

function renderTaskMetrics(metrics) {
    const values = { taskMetricTotal: metrics.total, taskMetricProgress: metrics.in_progress, taskMetricBlocked: metrics.blocked, taskMetricDone: metrics.done };
    Object.entries(values).forEach(([id, value]) => { const element = document.getElementById(id); if (element) element.textContent = value ?? '-'; });
}

function renderTaskPagination(pagination) {
    const target = document.getElementById('taskPagination');
    if (!target || !pagination.total_pages) return;
    const current = Number(pagination.current_page || 1);
    const total = Number(pagination.total_pages || 1);
    target.innerHTML = `<button class="btn-secondary" ${current <= 1 ? 'disabled' : ''} onclick="loadTasks(${current - 1})">← Sebelumnya</button><span>Halaman ${current} / ${total}</span><button class="btn-secondary" ${current >= total ? 'disabled' : ''} onclick="loadTasks(${current + 1})">Berikutnya →</button>`;
}

async function openTaskDetail(taskId) {
    const content = document.getElementById('taskDetailContent');
    if (!content) return;
    content.innerHTML = '<div class="loading-td"><div class="spinner"></div> Memuat detail...</div>';
    document.getElementById('taskDetailModal')?.classList.add('active');
    try {
        const res = await apiFetch(`/tasks/${Number(taskId)}`);
        const task = res.data;
        const logs = task.worklogs || [];
        content.innerHTML = `<div class="task-detail-summary"><h4>${escapeHtml(task.title)}</h4><p>${escapeHtml(task.description || 'Tidak ada deskripsi.')}</p><p><strong>${escapeHtml(task.status)}</strong> · ${Number(task.progress) || 0}% · ${escapeHtml(task.priority)}</p></div><h4>Worklog Timeline</h4>${logs.length ? `<div>${logs.map(log => `<div class="audit-description" style="padding:.65rem 0;border-bottom:1px solid var(--border-color);"><strong>${escapeHtml(log.work_date)}</strong> · ${Number(log.duration_minutes)} menit<br><span>${escapeHtml(log.notes || 'Tanpa catatan')}</span></div>`).join('')}</div>` : '<p class="empty-td">Belum ada worklog.</p>'}`;
    } catch (err) {
        content.innerHTML = `<div class="error-td">Gagal memuat detail: ${escapeHtml(err.message)}</div>`;
    }
}

async function syncHardwareLogs(btn) {
    const originalText = btn ? btn.innerHTML : '';
    if (btn) {
        btn.disabled = true;
        btn.innerHTML = `<div class="spinner-sm"></div> Menarik Log ISAPI...`;
    }

    try {
        const res = await apiFetch('/admin/access-logs/sync-hardware', {
            method: 'POST',
            body: JSON.stringify({ limit: 50 }),
        });

        if (res.status === 'success') {
            showToast(res.message || `Sinkronisasi log berhasil (${res.inserted_count} log baru).`, 'success');
            await loadAccessLogs();
            await loadDoors();
        } else {
            showToast(`Gagal sinkronisasi log: ${res.message}`, 'warning');
        }
    } catch (err) {
        showToast(`Gagal menarik log dari ISAPI: ${err.message}`, 'error');
    } finally {
        if (btn) {
            btn.disabled = false;
            btn.innerHTML = originalText;
        }
    }
}

function renderAccessLogsTable(logs) {
    const renderTargets = [
        document.getElementById('logsTableBody'),
        document.getElementById('overviewLogsTableBody')
    ].filter(Boolean);

    if (renderTargets.length === 0) return;

    if (logs.length === 0) {
        renderTargets.forEach(target => {
            target.innerHTML = `<tr><td colspan="8" class="empty-td">Tidak ada data log yang sesuai dengan filter.</td></tr>`;
        });
        return;
    }

    const html = logs.map(log => {
        const rawStatus = log.access_status || log.status || 'Granted';
        const eventType = log.event_type || 'STANDARD_TAP';

        let statusBadge = '';
        if (rawStatus === 'Alarm' || eventType === 'DOOR_FORCED_OPEN' || eventType === 'TAMPER_ALARM') {
            statusBadge = `<span class="badge badge-alarm">🚨 ALARM / INTRUSION</span>`;
        } else if (rawStatus === 'Duress' || eventType === 'DURESS_FINGERPRINT') {
            statusBadge = `<span class="badge badge-duress">⚠️ DURESS ALERT</span>`;
        } else if (rawStatus === 'Granted') {
            statusBadge = `<span class="badge badge-granted">✓ GRANTED</span>`;
        } else {
            statusBadge = `<span class="badge badge-denied">✕ DENIED</span>`;
        }

        const cardNo = log.user?.card_no || log.card_no;
        let userHtml = '';

        if (eventType === 'DOOR_FORCED_OPEN') {
            userHtml = `<strong style="color: #f87171;">🚨 Pintu Dibobol Paksa</strong><br><small class="text-muted">${escapeHtml(log.reason || 'Sensor intrusi terbuka tanpa autentikasi')}</small>`;
        } else if (eventType === 'TAMPER_ALARM') {
            userHtml = `<strong style="color: #f87171;">🔧 Sabotase Terminal</strong><br><small class="text-muted">${escapeHtml(log.reason || 'Sensor anti-tamper casing terpicu')}</small>`;
        } else if (eventType === 'DURESS_FINGERPRINT') {
            const empName = log.user?.name || log.nik || 'Karyawan';
            userHtml = `<strong>${escapeHtml(empName)}</strong> <span class="badge badge-duress" style="font-size: 0.65rem; padding: 1px 5px;">DURESS</span><br><small class="text-muted">${escapeHtml(log.user?.nik || log.nik || '')} • <em>${escapeHtml(log.reason || 'Akses dibuka di bawah ancaman')}</em></small>`;
        } else if (log.user && (log.user.name || log.user.nik)) {
            userHtml = `<strong>${escapeHtml(log.user.name || 'User')}</strong><br><small class="text-muted">${escapeHtml(log.user.nik || '')} ${cardNo ? `• 💳 ${escapeHtml(cardNo)}` : ''} ${log.user.department ? `• ${escapeHtml(log.user.department)}` : ''}</small>`;
        } else {
            userHtml = `<span class="unknown-user">❓ ${escapeHtml(log.reason || 'Unknown Card / Unregistered User')}</span>`;
        }

        let methodBadge = '';
        if (eventType === 'DOOR_FORCED_OPEN') {
            methodBadge = `<span class="method-chip" style="background: rgba(239,68,68,0.18); color: #f87171; border: 1px solid rgba(239,68,68,0.35);">⚡ Forced Entry</span>`;
        } else if (eventType === 'TAMPER_ALARM') {
            methodBadge = `<span class="method-chip" style="background: rgba(239,68,68,0.18); color: #f87171; border: 1px solid rgba(239,68,68,0.35);">🔧 Tamper Sensor</span>`;
        } else if (eventType === 'DURESS_FINGERPRINT' || log.verify_method === 'Duress_Fingerprint') {
            methodBadge = `<span class="method-chip" style="background: rgba(245,158,11,0.18); color: #fbbf24; border: 1px solid rgba(245,158,11,0.35);">⚠️ Duress FP</span>`;
        } else if (log.verify_method === 'Card' || log.auth_method === 'Card') {
            methodBadge = `<span class="method-chip method-card">💳 Card</span>`;
        } else {
            methodBadge = `<span class="method-chip method-fp">👆 Fingerprint</span>`;
        }

        const timestampStr = log.timestamp
            ? new Date(log.timestamp).toLocaleString('id-ID', { dateStyle: 'medium', timeStyle: 'medium' })
            : '-';

        const safeTime = escapeHtml(timestampStr);
        const attendanceStatus = log.attendance?.result || 'NOT_DERIVED';
        const attendanceClasses = {
            PRESENT: 'badge-success',
            LATE: 'badge-pending',
            ABSENT: 'badge-danger',
            OFF: 'badge-dim',
            LEAVE: 'badge-info',
            NOT_DERIVED: 'badge-neutral',
        };
        const attendanceLabel = attendanceStatus === 'NOT_DERIVED'
            ? (rawStatus === 'Denied' ? 'Ditolak' : 'Belum diproses')
            : attendanceStatus;
        const attendanceResult =
            `<span class="badge ${attendanceClasses[attendanceStatus] || 'badge-info'}">${escapeHtml(attendanceLabel)}</span>` +
            `${log.attendance?.direction ? ` <small class="text-muted">${escapeHtml(log.attendance.direction)}</small>` : ''}`;
        const safeNik = escapeHtml(log.user?.nik || log.nik || 'Employee belum terpetakan');
        const safeName = escapeHtml(log.user?.name || 'Employee belum terpetakan');
        const safeDoor = escapeHtml(log.door_id || '-');

        return `
            <tr>
                <td>${safeTime}</td>
                <td>${safeNik}</td>
                <td>${safeName}</td>
                <td>${methodBadge}</td>
                <td>${safeDoor}</td>
                <td>${escapeHtml(eventType)}</td>
                <td>${statusBadge}</td>
                <td>${attendanceResult}</td>
            </tr>
        `;
    }).join('');

    renderTargets.forEach(target => target.innerHTML = html);
}

function resetLogFilters() {
    ['logDoorFilter', 'logDoorFilterTab'].forEach(id => { const el = document.getElementById(id); if (el) el.value = ''; });
    ['logStatusFilter', 'logStatusFilterTab'].forEach(id => { const el = document.getElementById(id); if (el) el.value = ''; });
    ['logAttendanceStateFilter', 'logAttendanceStateFilterTab'].forEach(id => { const el = document.getElementById(id); if (el) el.value = ''; });
    ['logUserSearch', 'logUserSearchTab'].forEach(id => { const el = document.getElementById(id); if (el) el.value = ''; });
    ['logStartDate', 'logStartDateTab'].forEach(id => { const el = document.getElementById(id); if (el) el.value = ''; });
    ['logEndDate', 'logEndDateTab'].forEach(id => { const el = document.getElementById(id); if (el) el.value = ''; });
    loadAccessLogs();
}

// ==========================================
// Section 4: ISAPI Hardware Simulator
// ==========================================
function handleSimEventTypeChange(eventType) {
    const userLabel = document.getElementById('simUserLabel');
    const userNik = document.getElementById('simUserNik');
    const methodSelect = document.getElementById('simMethod');
    const statusSelect = document.getElementById('simStatus');
    const infoBox = document.getElementById('simScenarioInfo');

    if (!userNik || !methodSelect || !statusSelect) return;

    if (eventType === 'DOOR_FORCED_OPEN') {
        userNik.value = 'SENSOR-FORCED-OPEN';
        userNik.placeholder = 'Sensor pembobolan fisik';
        if (userLabel) userLabel.textContent = 'Identitas Sensor / Pemicu';
        methodSelect.value = 'Sensor';
        statusSelect.value = 'Alarm';
        if (infoBox) {
            infoBox.style.borderLeftColor = '#ef4444';
            infoBox.style.background = 'rgba(239, 68, 68, 0.1)';
            infoBox.innerHTML = `<strong>🚨 Skenario Pembobolan Pintu (DOOR_FORCED_OPEN):</strong> <span>Event pembobolan pintu fisik tanpa autentikasi kartu/biometrik. Sistem memicu status ALARM (High Severity) dan mencatat insiden keamanan ke audit log.</span>`;
        }
    } else if (eventType === 'TAMPER_ALARM') {
        userNik.value = 'SENSOR-TAMPER';
        userNik.placeholder = 'Sensor tamper hardware';
        if (userLabel) userLabel.textContent = 'Identitas Sensor / Pemicu';
        methodSelect.value = 'Sensor';
        statusSelect.value = 'Alarm';
        if (infoBox) {
            infoBox.style.borderLeftColor = '#ef4444';
            infoBox.style.background = 'rgba(239, 68, 68, 0.1)';
            infoBox.innerHTML = `<strong>🔧 Skenario Sabotase Terminal (TAMPER_ALARM):</strong> <span>Event sensor fisik anti-tamper terpicu akibat pembongkaran casing perangkat terminal oleh pihak tidak bertanggung jawab. Memicu status ALARM.</span>`;
        }
    } else if (eventType === 'DURESS_FINGERPRINT') {
        userNik.value = 'NIK-882101';
        userNik.placeholder = 'Misal: NIK-882101';
        if (userLabel) userLabel.textContent = 'NIK Karyawan (Korban Tekanan)';
        methodSelect.value = 'Duress_Fingerprint';
        statusSelect.value = 'Duress';
        if (infoBox) {
            infoBox.style.borderLeftColor = '#f59e0b';
            infoBox.style.background = 'rgba(245, 158, 11, 0.1)';
            infoBox.innerHTML = `<strong>⚠️ Skenario Sidik Jari Darurat (DURESS_FINGERPRINT):</strong> <span>Event sidik jari khusus saat karyawan ditekan/diancam. Pintu fisik tetap terbuka agar keselamatan karyawan terjaga, namun sistem secara senyap (silent alert) memicu status DURESS di dashboard keamanan.</span>`;
        }
    } else {
        // STANDARD_TAP
        userNik.value = 'NIK-882101';
        userNik.placeholder = 'Misal: NIK-882101 atau CARD-1001';
        if (userLabel) userLabel.textContent = 'NIK / User ID / Nomor Kartu';
        methodSelect.value = 'Fingerprint';
        statusSelect.value = 'Granted';
        if (infoBox) {
            infoBox.style.borderLeftColor = '#38bdf8';
            infoBox.style.background = 'rgba(56, 189, 248, 0.08)';
            infoBox.innerHTML = `<strong>💡 Skenario Terpilih:</strong> <span>Simulasi tap kartu / sidik jari reguler pegawai. Akses diberikan jika NIK terdaftar dan memiliki izin ke pintu tersebut.</span>`;
        }
    }
}

async function runEventSimulation(e) {
    e.preventDefault();
    const doorId = document.getElementById('simDoorId')?.value || 'DOOR-A';
    const eventType = document.getElementById('simEventType')?.value || 'STANDARD_TAP';
    const user = document.getElementById('simUserNik')?.value.trim() || '';
    const method = document.getElementById('simMethod')?.value || 'Fingerprint';
    const status = document.getElementById('simStatus')?.value || 'Granted';

    const payload = {
        door_id: doorId,
        event_type: eventType,
        user: user,
        verify_method: method,
        access_status: status,
        timestamp: new Date().toISOString(),
    };

    const simBtn = document.getElementById('btnSendSimulation');
    if (simBtn) {
        simBtn.disabled = true;
        simBtn.innerHTML = `Mengirimkan sinyal ${eventType}...`;
    }

    try {
        const appToken = window.APP_CONFIG?.apiToken || sessionStorage.getItem('api_token') || '';
        const headers = {
            'Content-Type': 'application/json',
            'Accept': 'application/json',
            'X-Simulator': 'true',
        };
        if (appToken) headers.Authorization = `Bearer ${appToken}`;

        const res = await fetch('/api/v1/doors/simulate-event', {
            method: 'POST',
            headers,
            body: JSON.stringify(payload),
        });

        const data = await res.json();
        const resBox = document.getElementById('simResult');
        if (resBox) resBox.style.display = 'block';

        if (res.ok) {
            const simDoor = escapeHtml(data.data?.door_id || doorId);
            const simEmp = escapeHtml(data.data?.employee_name || 'N/A');
            const simStatus = escapeHtml(data.data?.access_status || status);
            const simLogId = escapeHtml(data.data?.log_id || '-');
            const simReason = escapeHtml(data.data?.reason || '');

            let alertClass = 'alert-success';
            let statusTitle = '✓ Sinyal Tap Hardware Berhasil Diproses!';

            if (simStatus === 'Alarm' || eventType === 'DOOR_FORCED_OPEN' || eventType === 'TAMPER_ALARM') {
                alertClass = 'alert-danger';
                statusTitle = '🚨 PERINGATAN: Sinyal ALARM Hardware Terdeteksi!';
            } else if (simStatus === 'Duress' || eventType === 'DURESS_FINGERPRINT') {
                alertClass = 'alert-warning';
                statusTitle = '⚠️ PERINGATAN DARURAT: Silent DURESS Alarm Terpicu!';
            } else if (simStatus === 'Denied') {
                alertClass = 'alert-warning';
                statusTitle = '✕ Sinyal Tap Ditolak (Akses Tidak Diizinkan)';
            }

            if (resBox) {
                resBox.innerHTML = `
                    <div class="alert ${alertClass}">
                        <strong>${statusTitle}</strong><br>
                        <span>Door: <code>${simDoor}</code> | Event: <strong>${escapeHtml(eventType)}</strong> | Target: <strong>${simEmp}</strong> | Status: <strong>${simStatus}</strong></span><br>
                        ${simReason ? `<small>Keterangan: <em>${simReason}</em></small><br>` : ''}
                        <small>Log Audit ID: <code>${simLogId}</code></small>
                    </div>
                `;
            }

            showToast(`Event ${eventType} pada pintu ${simDoor} berhasil dicatat!`, simStatus === 'Alarm' ? 'error' : (simStatus === 'Duress' ? 'warning' : 'success'));
            await loadAccessLogs();
            await loadDoors();
        } else {
            const errMsg = escapeHtml(data.message || 'Error');
            const errStatus = Number(res.status) || 400;

            if (resBox) {
                resBox.innerHTML = `
                    <div class="alert alert-danger">
                        <strong>✕ Simulasi Gagal (${errStatus}):</strong> ${errMsg}
                    </div>
                `;
            }
            showToast(`Simulasi gagal: ${errMsg}`, 'error');
        }
    } catch (err) {
        showToast(`Simulasi error: ${err.message}`, 'error');
    } finally {
        if (simBtn) {
            simBtn.disabled = false;
            simBtn.innerHTML = `⚡ Kirim Sinyal Event Hardware`;
        }
    }
}

// ==========================================
// Modal Utilities
// ==========================================
function closeModal(id) {
    const el = document.getElementById(id);
    if (el) el.classList.remove('active');
}

// Close modals when clicking outside modal card
window.addEventListener('click', (e) => {
    if (e.target.classList.contains('modal-overlay')) {
        e.target.classList.remove('active');
    }
});

// Tab Switching
function switchTab(tabId, btn) {
    document.querySelectorAll('.tab-content').forEach(el => el.classList.remove('active'));
    document.querySelectorAll('.nav-item button').forEach(el => el.classList.remove('active'));

    const targetTab = document.getElementById(tabId);
    if (targetTab) targetTab.classList.add('active');
    if (btn) btn.classList.add('active');

    state.activeTab = tabId;

    // Auto-close sidebar on mobile after tab selection
    if (window.innerWidth <= 768) {
        toggleSidebar(false);
    }

    if (tabId === 'doorsTab' || tabId === 'overviewTab') loadDoors();
    if (tabId === 'employeesTab' || tabId === 'overviewTab') loadEmployees();
    if (tabId === 'logsTab' || tabId === 'overviewTab') loadAccessLogs();
    if (tabId === 'logsTab') loadActivityLogs();
    if (tabId === 'recruitmentTab') loadRecruitmentData();
    if (tabId === 'internshipTab') loadInternshipData();
    if (tabId === 'onboardingTab') loadOnboardingData();
    if (tabId === 'accessTab') loadAccessData();
    if (tabId === 'assetsTab') loadAssetsData();
    if (tabId === 'tasksTab') loadTasks();
    if (tabId === 'attendanceTab') loadAttendanceData();
    if (tabId === 'fieldAttendanceTab') loadFieldAttendanceData();
    if (tabId === 'attendanceRequestsTab') loadAttendanceRequestsData();
    if (tabId === 'attendanceCorrectionsTab') loadAttendanceCorrectionsData();
    if (tabId === 'overtimeRequestsTab') loadOvertimeRequestsData();
    if (tabId === 'buildingSetupTab') loadBuildingHierarchy();
    if (tabId === 'systemStatusTab') loadSystemHealth();
}

// ==========================================
// Floating Logo & Sidebar Controller
// ==========================================
function initSidebar() {
    const isMobile = () => window.innerWidth <= 768;
    const backdrop = document.getElementById('sidebarBackdrop');
    const floatingLogo = document.getElementById('floatingLogo');
    const closeBtn = document.getElementById('sidebarCloseBtn');

    // Retrieve saved state (default: open on desktop >= 768px, closed on mobile)
    let isSavedOpen = localStorage.getItem('pkp_sidebar_open');
    if (isSavedOpen === null) {
        isSavedOpen = localStorage.getItem('pkp_sidebar_collapsed') !== 'true';
    } else {
        isSavedOpen = isSavedOpen === 'true';
    }

    // Apply initial state
    if (!isMobile() && isSavedOpen) {
        document.body.classList.add('sidebar-open');
        document.documentElement.classList.add('sidebar-open');
    } else {
        document.body.classList.remove('sidebar-open');
        document.documentElement.classList.remove('sidebar-open');
    }

    // Clean up any stale legacy classes
    document.body.classList.remove('sidebar-collapsed', 'sidebar-mobile-open');
    document.documentElement.classList.remove('sidebar-collapsed', 'sidebar-mobile-open');

    // 1. Floating Logo Trigger (PKP Coin + Title) -> Toggle Sidebar
    if (floatingLogo) {
        floatingLogo.addEventListener('click', (e) => {
            e.stopPropagation();
            toggleSidebar();
        });
        floatingLogo.addEventListener('keydown', (e) => {
            if (e.key === 'Enter' || e.key === ' ') {
                e.preventDefault();
                toggleSidebar();
            }
        });
    }

    // 2. Close button in mobile drawer
    if (closeBtn) {
        closeBtn.addEventListener('click', (e) => {
            e.stopPropagation();
            toggleSidebar(false);
        });
    }

    // 3. Mobile Backdrop overlay click -> Close drawer
    if (backdrop) {
        backdrop.addEventListener('click', () => {
            toggleSidebar(false);
        });
    }

    // 4. Responsive resize synchronization
    window.addEventListener('resize', () => {
        if (!isMobile()) {
            if (backdrop) backdrop.classList.remove('active');
            const isOpen = localStorage.getItem('pkp_sidebar_open') === 'true';
            if (isOpen) {
                document.body.classList.add('sidebar-open');
                document.documentElement.classList.add('sidebar-open');
            } else {
                document.body.classList.remove('sidebar-open');
                document.documentElement.classList.remove('sidebar-open');
            }
        } else {
            if (!document.body.classList.contains('sidebar-open')) {
                if (backdrop) backdrop.classList.remove('active');
            }
        }
    });

    // 5. Keyboard Shortcuts: Esc to close, Ctrl+B / Cmd+B to toggle
    document.addEventListener('keydown', (e) => {
        if (e.key === 'Escape') {
            if (document.body.classList.contains('sidebar-open')) {
                toggleSidebar(false);
            }
        } else if ((e.ctrlKey || e.metaKey) && e.key.toLowerCase() === 'b') {
            e.preventDefault();
            toggleSidebar();
        }
    });
}

function toggleSidebar(forceState) {
    const isMobile = window.innerWidth <= 768;
    const backdrop = document.getElementById('sidebarBackdrop');

    const isCurrentlyOpen = document.body.classList.contains('sidebar-open') ||
                            document.documentElement.classList.contains('sidebar-open');
    const shouldOpen = typeof forceState === 'boolean' ? forceState : !isCurrentlyOpen;

    if (shouldOpen) {
        document.body.classList.add('sidebar-open');
        document.documentElement.classList.add('sidebar-open');
        localStorage.setItem('pkp_sidebar_open', 'true');
        localStorage.setItem('pkp_sidebar_collapsed', 'false');
        if (isMobile && backdrop) backdrop.classList.add('active');
    } else {
        document.body.classList.remove('sidebar-open');
        document.documentElement.classList.remove('sidebar-open');
        localStorage.setItem('pkp_sidebar_open', 'false');
        localStorage.setItem('pkp_sidebar_collapsed', 'true');
        if (backdrop) backdrop.classList.remove('active');
    }
}

// Expose globally
window.toggleSidebar = toggleSidebar;
window.pingSingleDoor = pingSingleDoor;
window.openRemoteUnlockModal = openRemoteUnlockModal;
window.cancelRemoteUnlock = cancelRemoteUnlock;
window.confirmRemoteUnlock = confirmRemoteUnlock;
window.refreshOperationalData = refreshOperationalData;
window.openDoorLogs = openDoorLogs;
window.openDoorUsers = openDoorUsers;
window.checkAllDoors = checkAllDoors;
window.syncHardwareLogs = syncHardwareLogs;
window.revokeAllEmployeeDoors = revokeAllEmployeeDoors;
window.revokeSingleDoor = revokeSingleDoor;
window.openDoorAssignmentModal = openDoorAssignmentModal;
window.submitDoorAssignment = submitDoorAssignment;
window.handleSimEventTypeChange = handleSimEventTypeChange;
window.runEventSimulation = runEventSimulation;
window.loadTasks = loadTasks;
window.openTaskDetail = openTaskDetail;

// ==========================================
// Section 5: Real-Time SSE Stream (Phase 7-13)
// ==========================================
let liveEventSource = null;

function initLiveAccessStream() {
    if (liveEventSource) {
        liveEventSource.close();
    }

    const sseUrl = '/live-stream';
    liveEventSource = new EventSource(sseUrl);

    liveEventSource.onopen = () => {
        console.log('[SSE] Connected to real-time access stream');
    };

    liveEventSource.onmessage = (event) => {
        try {
            const data = JSON.parse(event.data);
            handleNewLiveEvent(data);
        } catch (e) {
            console.error('[SSE] Failed to parse event', e);
        }
    };

    liveEventSource.addEventListener('reload', () => {
        console.log('[SSE] Server requested reconnect to prevent timeout');
        initLiveAccessStream(); // Reconnect gracefully
    });

    liveEventSource.onerror = (error) => {
        console.error('[SSE] Connection error. Attempting to reconnect...', error);
        liveEventSource.close();
        setTimeout(initLiveAccessStream, 5000); // Reconnect after 5s
    };
}

function handleNewLiveEvent(data) {
    // 1. Show Toast
    let type = 'success';
    if (data.access_status === 'DENIED') type = 'warning';
    if (data.access_status === 'ERROR') type = 'error';
    if (data.verify_method === 'REMOTE_UNLOCK') type = 'info';

    showToast(`🚪 ${data.door_name} - ${data.employee_name} (${data.access_status})`, type, 5000);

    // 2. Reload tables automatically so we don't have to write full row injection logic
    // unless performance dictates it. Since it's a dashboard, calling loadAccessLogs() is easiest.
    loadAccessLogs();
    updateMetricCards();
    if (state.activeTab === 'logsTab' || state.activeTab === 'overviewTab' || state.activeTab === 'attendanceTab') {
        loadActivityLogs();
        if (state.activeTab === 'attendanceTab') loadAttendanceData();
    }

    // Also refresh door status
    loadDoors();
}

// ==========================================
// Initial Boot
// ==========================================
document.addEventListener('DOMContentLoaded', () => {
    // Initialize Collapsible Sidebar Controller
    initSidebar();

    // Initial data loading
    loadDoors();
    loadEmployees();
    loadAccessLogs();
    loadActivityLogs();

    // Initialize Real-time SSE connection
    initLiveAccessStream();

    // Auto-refresh doors and logs periodically every 60 seconds (fallback)
    setInterval(() => {
        loadDoors();
        loadAccessLogs();
        updateMetricCards();
        if (state.activeTab === 'attendanceTab') loadAttendanceData();
        if (state.activeTab === 'logsTab') {
            loadActivityLogs();
        }
    }, 60000);
});

// ==========================================
// Section 7: Recruitment & ATS Controller
// ==========================================
state.ats = {
    activePill: 'pipeline',
    vacancies: [],
    candidates: [],
    applications: [],
    interviews: [],
    stages: [],
    searchDebounce: null,
};

function formatRupiah(amount) {
    if (!amount || isNaN(amount)) return '-';
    return 'Rp ' + Number(amount).toLocaleString('id-ID');
}

function formatDateTime(dtStr) {
    if (!dtStr) return '-';
    try {
        const d = new Date(dtStr);
        return d.toLocaleDateString('id-ID', { day: '2-digit', month: 'short', year: 'numeric', hour: '2-digit', minute: '2-digit' });
    } catch {
        return dtStr;
    }
}

function switchAtsPill(pillName, btn) {
    document.querySelectorAll('.ats-nav-pill').forEach(el => el.classList.remove('active'));
    document.querySelectorAll('.ats-sub-content').forEach(el => el.classList.remove('active'));

    if (btn) {
        btn.classList.add('active');
    } else {
        const defaultBtn = document.getElementById(`pill${pillName.charAt(0).toUpperCase() + pillName.slice(1)}`);
        if (defaultBtn) defaultBtn.classList.add('active');
    }

    const subId = `atsSub${pillName.charAt(0).toUpperCase() + pillName.slice(1)}`;
    const subTarget = document.getElementById(subId);
    if (subTarget) subTarget.classList.add('active');

    state.ats.activePill = pillName;

    if (pillName === 'pipeline') loadAtsApplications();
    if (pillName === 'vacancies') loadAtsVacancies();
    if (pillName === 'candidates') loadAtsCandidates();
    if (pillName === 'interviews') loadAtsInterviews();
}

async function loadRecruitmentData() {
    await Promise.allSettled([
        loadAtsMetrics(),
        loadAtsLookups(),
    ]);

    if (state.ats.activePill === 'pipeline') loadAtsApplications();
    else if (state.ats.activePill === 'vacancies') loadAtsVacancies();
    else if (state.ats.activePill === 'candidates') loadAtsCandidates();
    else if (state.ats.activePill === 'interviews') loadAtsInterviews();
}

async function loadAtsLookups() {
    try {
        const [stagesRes, vacanciesRes, candidatesRes] = await Promise.allSettled([
            apiFetch('/recruitment/stages'),
            apiFetch('/recruitment/vacancies?status=OPEN'),
            apiFetch('/recruitment/candidates')
        ]);

        if (stagesRes.status === 'fulfilled' && stagesRes.value?.status === 'success') {
            state.ats.stages = stagesRes.value.data || [];
        }
        if (vacanciesRes.status === 'fulfilled' && vacanciesRes.value?.status === 'success') {
            state.ats.vacancies = vacanciesRes.value.data || [];
        }
        if (candidatesRes.status === 'fulfilled' && candidatesRes.value?.status === 'success') {
            state.ats.candidates = candidatesRes.value.data || [];
        }
    } catch (err) {
        console.warn('ATS lookups error:', err);
    }
}

async function loadAtsMetrics() {
    try {
        const res = await apiFetch('/recruitment/metrics');
        if (res.status === 'success' && res.data) {
            const m = res.data;
            const elVac = document.getElementById('atsMetricVacancies');
            const elCand = document.getElementById('atsMetricCandidates');
            const elApps = document.getElementById('atsMetricApplications');
            const elHired = document.getElementById('atsMetricHired');

            if (elVac) elVac.textContent = m.open_vacancies ?? 0;
            if (elCand) elCand.textContent = m.total_candidates ?? 0;
            if (elApps) elApps.textContent = m.active_applications ?? 0;
            if (elHired) elHired.textContent = m.hired_count ?? 0;
        }
    } catch (err) {
        console.warn('Gagal memuat metrik ATS:', err);
    }
}

// Sub-Tab 1: Pipeline Lamaran
async function loadAtsApplications() {
    const tbody = document.getElementById('atsApplicationsTableBody');
    if (!tbody) return;

    const search = document.getElementById('searchAtsApplications')?.value.trim() || '';
    const stage = document.getElementById('filterAtsStage')?.value || '';

    tbody.innerHTML = `<tr><td colspan="7" class="loading-td"><div class="spinner"></div> Memuat pipeline pelamar...</td></tr>`;

    try {
        let url = '/recruitment/applications';
        const params = [];
        if (search) params.push(`search=${encodeURIComponent(search)}`);
        if (stage) params.push(`stage=${encodeURIComponent(stage)}`);
        if (params.length > 0) url += `?${params.join('&')}`;

        const res = await apiFetch(url);
        if (res.status !== 'success' || !Array.isArray(res.data) || res.data.length === 0) {
            tbody.innerHTML = `<tr><td colspan="7" class="empty-td">Tidak ada data lamaran ditemukan. Silakan klik "+ Lamar ke Lowongan".</td></tr>`;
            return;
        }

        tbody.innerHTML = res.data.map(app => {
            const cand = app.candidate || {};
            const vac = app.vacancy || {};
            const stageInfo = app.stage || {};
            const candName = `${cand.first_name || ''} ${cand.last_name || ''}`.trim() || 'Kandidat';

            let stageBadgeColor = 'rgba(56, 189, 248, 0.2)';
            let stageTextColor = '#38bdf8';
            if (app.current_stage_code === 'APPLIED') { stageBadgeColor = 'rgba(148, 163, 184, 0.2)'; stageTextColor = '#94a3b8'; }
            if (app.current_stage_code === 'TECHNICAL_TEST') { stageBadgeColor = 'rgba(168, 85, 247, 0.2)'; stageTextColor = '#c084fc'; }
            if (app.current_stage_code.includes('INTERVIEW')) { stageBadgeColor = 'rgba(245, 158, 11, 0.2)'; stageTextColor = '#fbbf24'; }
            if (app.current_stage_code === 'OFFER') { stageBadgeColor = 'rgba(16, 185, 129, 0.2)'; stageTextColor = '#34d399'; }
            if (app.current_stage_code === 'ACCEPTED') { stageBadgeColor = 'rgba(16, 185, 129, 0.3)'; stageTextColor = '#10b981'; }
            if (app.current_stage_code === 'REJECTED') { stageBadgeColor = 'rgba(239, 68, 68, 0.2)'; stageTextColor = '#f87171'; }

            const canHire = ['OFFER', 'ACCEPTED'].includes(app.current_stage_code) || app.status === 'HIRED';

            return `
                <tr>
                    <td>
                        <span style="font-family: monospace; font-weight: 700; color: var(--primary);">${escapeHtml(app.application_number)}</span>
                    </td>
                    <td>
                        <div style="font-weight: 600; color: #ffffff;">${escapeHtml(candName)}</div>
                        <div style="font-size: 0.775rem; color: var(--text-muted);">${escapeHtml(cand.email || '')} ${cand.phone ? '• ' + escapeHtml(cand.phone) : ''}</div>
                    </td>
                    <td>
                        <div style="font-weight: 600; color: #e2e8f0;">${escapeHtml(vac.title || '-')}</div>
                        <div style="font-size: 0.775rem; color: var(--text-muted); font-family: monospace;">${escapeHtml(vac.vacancy_code || '')}</div>
                    </td>
                    <td>
                        <span class="badge" style="background: ${stageBadgeColor}; color: ${stageTextColor}; font-weight: 600;">
                            ${escapeHtml(stageInfo.name || app.current_stage_code)}
                        </span>
                    </td>
                    <td>
                        <span class="badge ${app.status === 'HIRED' ? 'badge-active' : (app.status === 'REJECTED' ? 'badge-danger' : 'badge-info')}">
                            ${escapeHtml(app.status)}
                        </span>
                    </td>
                    <td style="font-size: 0.8rem; color: var(--text-muted);">
                        ${formatDateTime(app.applied_at || app.created_at)}
                    </td>
                    <td style="text-align: right;">
                        <div style="display: flex; gap: 0.4rem; justify-content: flex-end; flex-wrap: wrap;">
                            <button class="btn-action" title="Ubah Tahapan Lamaran" onclick="openTransitionStageModal(${app.id}, '${escapeHtml(candName)}', '${escapeHtml(app.current_stage_code)}')">
                                🔄 Tahap
                            </button>
                            <button class="btn-action" title="Jadwalkan Wawancara" onclick="openScheduleInterviewModal(${app.id}, '${escapeHtml(candName)}')">
                                📅 Interview
                            </button>
                            <button class="btn-action" title="Buat Penawaran Offering" onclick="openCreateOfferModal(${app.id}, '${escapeHtml(candName)}')">
                                📄 Offer
                            </button>
                            ${canHire ? `
                            <button class="btn-action" style="background: rgba(16, 185, 129, 0.2); color: #34d399; border-color: rgba(16, 185, 129, 0.4);" title="Angkat Sebagai Karyawan Resmi" onclick="openConvertToEmployeeModal(${app.id}, '${escapeHtml(candName)}')">
                                ✓ Hire
                            </button>
                            ` : ''}
                        </div>
                    </td>
                </tr>
            `;
        }).join('');
    } catch (err) {
        tbody.innerHTML = `<tr><td colspan="7" class="error-td">Gagal memuat data pelamar: ${escapeHtml(err.message)}</td></tr>`;
    }
}

// Sub-Tab 2: Lowongan Pekerjaan
async function loadAtsVacancies() {
    const tbody = document.getElementById('atsVacanciesTableBody');
    if (!tbody) return;

    const search = document.getElementById('searchAtsVacancies')?.value.trim() || '';
    const status = document.getElementById('filterAtsVacancyStatus')?.value || '';

    tbody.innerHTML = `<tr><td colspan="8" class="loading-td"><div class="spinner"></div> Memuat daftar lowongan...</td></tr>`;

    try {
        let url = '/recruitment/vacancies';
        const params = [];
        if (search) params.push(`search=${encodeURIComponent(search)}`);
        if (status) params.push(`status=${encodeURIComponent(status)}`);
        if (params.length > 0) url += `?${params.join('&')}`;

        const res = await apiFetch(url);
        if (res.status !== 'success' || !Array.isArray(res.data) || res.data.length === 0) {
            tbody.innerHTML = `<tr><td colspan="8" class="empty-td">Belum ada lowongan pekerjaan ditemukan. Klik "+ Lowongan Baru" untuk membuat lowongan.</td></tr>`;
            return;
        }

        tbody.innerHTML = res.data.map(v => {
            const divName = v.division?.name || 'Seluruh Divisi';
            const bldName = v.building?.name || 'Head Office';
            const statusClass = v.status === 'OPEN' ? 'badge-active' : (v.status === 'CLOSED' ? 'badge-danger' : 'badge-info');

            return `
                <tr>
                    <td>
                        <div style="font-weight: 700; color: #ffffff;">${escapeHtml(v.title)}</div>
                        <div style="font-size: 0.775rem; color: var(--primary); font-family: monospace;">${escapeHtml(v.vacancy_code)}</div>
                    </td>
                    <td>
                        <div style="color: #cbd5e1;">${escapeHtml(divName)}</div>
                        <div style="font-size: 0.775rem; color: var(--text-muted);">${escapeHtml(bldName)}</div>
                    </td>
                    <td>
                        <span class="badge" style="background: rgba(99, 102, 241, 0.2); color: #a5b4fc;">${escapeHtml(v.employment_type)}</span>
                        <span style="font-size: 0.775rem; color: var(--text-muted); margin-left: 4px;">${escapeHtml(v.experience_level)}</span>
                    </td>
                    <td style="font-weight: 600; text-align: center;">${v.quota}</td>
                    <td style="text-align: center;">
                        <span class="badge badge-info" style="font-weight: 700;">${v.applications_count ?? 0} Pelamar</span>
                    </td>
                    <td>
                        <span class="badge ${statusClass}">${escapeHtml(v.status)}</span>
                    </td>
                    <td style="font-size: 0.8rem; color: var(--text-muted);">
                        ${v.deadline_at ? formatDateTime(v.deadline_at) : 'Tidak Terbatas'}
                    </td>
                    <td style="text-align: right;">
                        <button class="btn-action" onclick="viewVacancyApplicants(${v.id})" title="Lihat pelamar pada lowongan ini">
                            👥 Pelamar
                        </button>
                    </td>
                </tr>
            `;
        }).join('');
    } catch (err) {
        tbody.innerHTML = `<tr><td colspan="8" class="error-td">Gagal memuat lowongan: ${escapeHtml(err.message)}</td></tr>`;
    }
}

function viewVacancyApplicants(vacancyId) {
    switchAtsPill('pipeline', document.getElementById('pillPipeline'));
    const vac = state.ats.vacancies.find(x => x.id === vacancyId);
    if (vac && document.getElementById('searchAtsApplications')) {
        document.getElementById('searchAtsApplications').value = vac.title;
        loadAtsApplications();
    }
}

// Sub-Tab 3: Talent Pool Kandidat
async function loadAtsCandidates() {
    const tbody = document.getElementById('atsCandidatesTableBody');
    if (!tbody) return;

    const search = document.getElementById('searchAtsCandidates')?.value.trim() || '';

    tbody.innerHTML = `<tr><td colspan="7" class="loading-td"><div class="spinner"></div> Memuat database kandidat...</td></tr>`;

    try {
        let url = '/recruitment/candidates';
        if (search) url += `?search=${encodeURIComponent(search)}`;

        const res = await apiFetch(url);
        if (res.status !== 'success' || !Array.isArray(res.data) || res.data.length === 0) {
            tbody.innerHTML = `<tr><td colspan="7" class="empty-td">Belum ada profil kandidat. Klik "+ Tambah Kandidat" untuk mendaftarkan talenta baru.</td></tr>`;
            return;
        }

        tbody.innerHTML = res.data.map(c => {
            const fullName = `${c.first_name || ''} ${c.last_name || ''}`.trim();
            return `
                <tr>
                    <td>
                        <span style="font-family: monospace; font-weight: 700; color: var(--primary);">${escapeHtml(c.candidate_number)}</span>
                    </td>
                    <td>
                        <div style="font-weight: 600; color: #ffffff;">${escapeHtml(fullName)}</div>
                        <div style="font-size: 0.775rem; color: var(--text-muted);">${c.national_id ? 'NIK: ' + escapeHtml(c.national_id) : ''}</div>
                    </td>
                    <td>
                        <div style="color: #cbd5e1;">${escapeHtml(c.email || '-')}</div>
                        <div style="font-size: 0.775rem; color: var(--text-muted);">${escapeHtml(c.phone || '-')}</div>
                    </td>
                    <td>
                        <div style="color: #e2e8f0;">${escapeHtml(c.current_position || 'Belum Tercatat')}</div>
                        <div style="font-size: 0.775rem; color: var(--text-muted);">${escapeHtml(c.current_company || '-')}</div>
                    </td>
                    <td>
                        <span class="badge" style="background: rgba(148, 163, 184, 0.2); color: #cbd5e1;">
                            ${escapeHtml(c.source || 'CAREER_SITE')}
                        </span>
                    </td>
                    <td>
                        <span class="badge ${c.status === 'HIRED' ? 'badge-active' : (c.status === 'BLACKLISTED' ? 'badge-danger' : 'badge-info')}">
                            ${escapeHtml(c.status || 'ACTIVE')}
                        </span>
                    </td>
                    <td style="text-align: right;">
                        <button class="btn-action" onclick="openApplyModal(${c.id})" title="Daftarkan lamaran untuk kandidat ini">
                            📝 Lamar Lowongan
                        </button>
                    </td>
                </tr>
            `;
        }).join('');
    } catch (err) {
        tbody.innerHTML = `<tr><td colspan="7" class="error-td">Gagal memuat kandidat: ${escapeHtml(err.message)}</td></tr>`;
    }
}

// Sub-Tab 4: Jadwal Interview
async function loadAtsInterviews() {
    const tbody = document.getElementById('atsInterviewsTableBody');
    if (!tbody) return;

    tbody.innerHTML = `<tr><td colspan="8" class="loading-td"><div class="spinner"></div> Memuat jadwal interview...</td></tr>`;

    try {
        const res = await apiFetch('/recruitment/interviews');
        if (res.status !== 'success' || !Array.isArray(res.data) || res.data.length === 0) {
            tbody.innerHTML = `<tr><td colspan="8" class="empty-td">Belum ada sesi wawancara yang dijadwalkan.</td></tr>`;
            return;
        }

        tbody.innerHTML = res.data.map(inv => {
            const app = inv.application || {};
            const cand = app.candidate || {};
            const candName = `${cand.first_name || ''} ${cand.last_name || ''}`.trim() || 'Kandidat';
            const statusClass = inv.status === 'COMPLETED' ? 'badge-active' : (inv.status === 'CANCELLED' ? 'badge-danger' : 'badge-warning');

            const isLink = inv.location && (inv.location.startsWith('http://') || inv.location.startsWith('https://'));

            return `
                <tr>
                    <td>
                        <div style="font-weight: 600; color: #ffffff;">${escapeHtml(candName)}</div>
                        <div style="font-size: 0.775rem; color: var(--primary); font-family: monospace;">${escapeHtml(app.application_number || '-')}</div>
                    </td>
                    <td>
                        <span class="badge" style="background: rgba(245, 158, 11, 0.2); color: #fbbf24; font-weight: 600;">
                            ${escapeHtml(inv.stage_code)}
                        </span>
                    </td>
                    <td>
                        <div style="color: #cbd5e1;">${escapeHtml(inv.interviewer_name || '-')}</div>
                    </td>
                    <td>
                        <div style="color: #e2e8f0; font-weight: 600;">${formatDateTime(inv.scheduled_at)}</div>
                        <div style="font-size: 0.775rem; color: var(--text-muted);">${inv.duration_minutes} Menit</div>
                    </td>
                    <td>
                        ${isLink ? `<a href="${escapeHtml(inv.location)}" target="_blank" style="color: var(--primary); text-decoration: underline;">Tautan Meeting ↗</a>` : `<span style="color: #cbd5e1;">${escapeHtml(inv.location || '-')}</span>`}
                    </td>
                    <td>
                        <span class="badge ${statusClass}">${escapeHtml(inv.status)}</span>
                    </td>
                    <td>
                        ${inv.result ? `
                            <div style="font-weight: 700; color: ${inv.result === 'PROCEED' ? '#10b981' : (inv.result === 'REJECT' ? '#ef4444' : '#f59e0b')}">${escapeHtml(inv.result)}</div>
                            <div style="font-size: 0.775rem; color: var(--text-muted);">Skor: ${inv.score ?? '-'}/100</div>
                        ` : '<span style="color: var(--text-muted); font-size: 0.8rem;">Menunggu Evaluasi</span>'}
                    </td>
                    <td style="text-align: right;">
                        ${inv.status === 'SCHEDULED' ? `
                            <button class="btn-action" style="background: rgba(99, 102, 241, 0.2); color: #a5b4fc; border-color: rgba(99, 102, 241, 0.4);" onclick="openInterviewFeedbackModal(${inv.id})">
                                ✍️ Nilai
                            </button>
                        ` : ''}
                    </td>
                </tr>
            `;
        }).join('');
    } catch (err) {
        tbody.innerHTML = `<tr><td colspan="8" class="error-td">Gagal memuat interview: ${escapeHtml(err.message)}</td></tr>`;
    }
}

// Debounce Helpers for Search
function debounceAtsApplicationsSearch() {
    clearTimeout(state.ats.searchDebounce);
    state.ats.searchDebounce = setTimeout(loadAtsApplications, 300);
}

function debounceAtsVacanciesSearch() {
    clearTimeout(state.ats.searchDebounce);
    state.ats.searchDebounce = setTimeout(loadAtsVacancies, 300);
}

function debounceAtsCandidatesSearch() {
    clearTimeout(state.ats.searchDebounce);
    state.ats.searchDebounce = setTimeout(loadAtsCandidates, 300);
}

// Modal 1: Buat Lowongan
function openAddVacancyModal() {
    const divSelect = document.getElementById('vacDivisionId');
    const bldSelect = document.getElementById('vacBuildingId');

    if (divSelect && state.organization?.divisions) {
        divSelect.innerHTML = '<option value="">Pilih Divisi</option>' +
            state.organization.divisions.map(d => `<option value="${d.id}">${escapeHtml(d.name)}</option>`).join('');
    }
    if (bldSelect && state.organization?.buildings) {
        bldSelect.innerHTML = '<option value="">Pilih Gedung</option>' +
            state.organization.buildings.map(b => `<option value="${b.id}">${escapeHtml(b.name)}</option>`).join('');
    }

    const modal = document.getElementById('modalAddVacancy');
    if (modal) modal.classList.add('active');
}

async function saveVacancy(e) {
    e.preventDefault();
    const payload = {
        title: document.getElementById('vacTitle')?.value.trim(),
        employment_type: document.getElementById('vacEmploymentType')?.value,
        experience_level: document.getElementById('vacExperienceLevel')?.value,
        division_id: document.getElementById('vacDivisionId')?.value || null,
        building_id: document.getElementById('vacBuildingId')?.value || null,
        quota: parseInt(document.getElementById('vacQuota')?.value || 1),
        salary_min: document.getElementById('vacSalaryMin')?.value || null,
        salary_max: document.getElementById('vacSalaryMax')?.value || null,
        description: document.getElementById('vacDescription')?.value.trim(),
        requirements: document.getElementById('vacRequirements')?.value.trim() || null,
    };

    try {
        const res = await apiFetch('/recruitment/vacancies', {
            method: 'POST',
            body: JSON.stringify(payload)
        });
        if (res.status === 'success') {
            showToast('Lowongan pekerjaan baru berhasil dipublikasikan!', 'success');
            closeModal('modalAddVacancy');
            document.getElementById('formAddVacancy')?.reset();
            await Promise.all([loadAtsVacancies(), loadAtsMetrics()]);
        }
    } catch (err) {
        showToast(`Gagal membuat lowongan: ${err.message}`, 'error');
    }
}

// Modal 2: Tambah Kandidat
function openAddCandidateModal() {
    const modal = document.getElementById('modalAddCandidate');
    if (modal) modal.classList.add('active');
}

async function saveCandidate(e) {
    e.preventDefault();
    const payload = {
        first_name: document.getElementById('candFirstName')?.value.trim(),
        last_name: document.getElementById('candLastName')?.value.trim() || null,
        email: document.getElementById('candEmail')?.value.trim(),
        phone: document.getElementById('candPhone')?.value.trim(),
        national_id: document.getElementById('candNationalId')?.value.trim() || null,
        source: document.getElementById('candSource')?.value,
        current_company: document.getElementById('candCompany')?.value.trim() || null,
        current_position: document.getElementById('candPosition')?.value.trim() || null,
    };

    try {
        const res = await apiFetch('/recruitment/candidates', {
            method: 'POST',
            body: JSON.stringify(payload)
        });
        if (res.status === 'success') {
            showToast('Profil kandidat berhasil didaftarkan!', 'success');
            closeModal('modalAddCandidate');
            document.getElementById('formAddCandidate')?.reset();
            await Promise.all([loadAtsCandidates(), loadAtsMetrics()]);
        }
    } catch (err) {
        showToast(`Gagal mendaftarkan kandidat: ${err.message}`, 'error');
    }
}

// Modal 3: Lamar Lowongan
async function openApplyModal(preselectedCandidateId = null) {
    await loadAtsLookups();

    const candSelect = document.getElementById('applyCandidateId');
    const vacSelect = document.getElementById('applyVacancyId');

    if (candSelect) {
        candSelect.innerHTML = '<option value="">-- Pilih Kandidat --</option>' +
            state.ats.candidates.map(c => {
                const name = `${c.first_name || ''} ${c.last_name || ''}`.trim();
                const selected = preselectedCandidateId && Number(preselectedCandidateId) === c.id ? 'selected' : '';
                return `<option value="${c.id}" ${selected}>${escapeHtml(name)} (${escapeHtml(c.candidate_number)})</option>`;
            }).join('');
    }

    if (vacSelect) {
        vacSelect.innerHTML = '<option value="">-- Pilih Lowongan Dibuka --</option>' +
            state.ats.vacancies.map(v => {
                return `<option value="${v.id}">${escapeHtml(v.title)} (${escapeHtml(v.vacancy_code)})</option>`;
            }).join('');
    }

    const modal = document.getElementById('modalApplyVacancy');
    if (modal) modal.classList.add('active');
}

async function saveApplication(e) {
    e.preventDefault();
    const payload = {
        candidate_id: document.getElementById('applyCandidateId')?.value,
        vacancy_id: document.getElementById('applyVacancyId')?.value,
        expected_salary: document.getElementById('applyExpectedSalary')?.value || null,
        notes: document.getElementById('applyNotes')?.value.trim() || null,
    };

    try {
        const res = await apiFetch('/recruitment/applications', {
            method: 'POST',
            body: JSON.stringify(payload)
        });
        if (res.status === 'success') {
            showToast('Lamaran kandidat berhasil didaftarkan ke pipeline!', 'success');
            closeModal('modalApplyVacancy');
            document.getElementById('formApplyVacancy')?.reset();
            await Promise.all([loadAtsApplications(), loadAtsMetrics()]);
        }
    } catch (err) {
        showToast(`Gagal mendaftarkan lamaran: ${err.message}`, 'error');
    }
}

// Modal 4: Transisi Tahapan
function openTransitionStageModal(applicationId, candidateName, currentStage) {
    document.getElementById('transAppId').value = applicationId;
    const nameEl = document.getElementById('transCandName');
    if (nameEl) nameEl.textContent = candidateName;

    const selectEl = document.getElementById('transNewStage');
    if (selectEl && currentStage) {
        selectEl.value = currentStage;
    }

    const modal = document.getElementById('modalTransitionStage');
    if (modal) modal.classList.add('active');
}

async function submitTransitionStage(e) {
    e.preventDefault();
    const appId = document.getElementById('transAppId')?.value;
    const payload = {
        stage_code: document.getElementById('transNewStage')?.value,
        notes: document.getElementById('transReason')?.value.trim() || null,
    };

    try {
        const res = await apiFetch(`/recruitment/applications/${appId}/transition`, {
            method: 'POST',
            body: JSON.stringify(payload)
        });
        if (res.status === 'success') {
            showToast('Tahapan pelamar berhasil diperbarui!', 'success');
            closeModal('modalTransitionStage');
            document.getElementById('formTransitionStage')?.reset();
            await Promise.all([loadAtsApplications(), loadAtsMetrics()]);
        }
    } catch (err) {
        showToast(`Gagal memperbarui tahapan: ${err.message}`, 'error');
    }
}

// Modal 5: Jadwal Interview
function openScheduleInterviewModal(applicationId, candidateName) {
    document.getElementById('schAppId').value = applicationId;
    const nameEl = document.getElementById('schCandName');
    if (nameEl) nameEl.textContent = candidateName;

    // Default time: tomorrow at 10:00
    const now = new Date();
    now.setDate(now.getDate() + 1);
    now.setHours(10, 0, 0, 0);
    const isoString = new Date(now.getTime() - (now.getTimezoneOffset() * 60000)).toISOString().slice(0, 16);
    const dtEl = document.getElementById('schDateTime');
    if (dtEl) dtEl.value = isoString;

    const modal = document.getElementById('modalScheduleInterview');
    if (modal) modal.classList.add('active');
}

async function saveInterviewSchedule(e) {
    e.preventDefault();
    const payload = {
        application_id: document.getElementById('schAppId')?.value,
        stage_code: document.getElementById('schStageCode')?.value,
        scheduled_at: document.getElementById('schDateTime')?.value,
        duration_minutes: parseInt(document.getElementById('schDuration')?.value || 45),
        location: document.getElementById('schLocation')?.value.trim(),
    };

    try {
        const res = await apiFetch('/recruitment/interviews', {
            method: 'POST',
            body: JSON.stringify(payload)
        });
        if (res.status === 'success') {
            showToast('Jadwal interview berhasil ditetapkan!', 'success');
            closeModal('modalScheduleInterview');
            document.getElementById('formScheduleInterview')?.reset();
            if (state.ats.activePill === 'interviews') loadAtsInterviews();
            else loadAtsApplications();
        }
    } catch (err) {
        showToast(`Gagal menjadwalkan interview: ${err.message}`, 'error');
    }
}

// Modal 6: Interview Feedback & Scoring
function openInterviewFeedbackModal(interviewId) {
    document.getElementById('fbInterviewId').value = interviewId;
    const modal = document.getElementById('modalInterviewFeedback');
    if (modal) modal.classList.add('active');
}

async function saveInterviewFeedback(e) {
    e.preventDefault();
    const invId = document.getElementById('fbInterviewId')?.value;
    const payload = {
        score: parseInt(document.getElementById('fbScore')?.value || 0),
        recommendation: document.getElementById('fbRecommendation')?.value,
        feedback: document.getElementById('fbNotes')?.value.trim(),
    };

    try {
        const res = await apiFetch(`/recruitment/interviews/${invId}/feedback`, {
            method: 'POST',
            body: JSON.stringify(payload)
        });
        if (res.status === 'success') {
            showToast('Hasil evaluasi interview berhasil disimpan!', 'success');
            closeModal('modalInterviewFeedback');
            document.getElementById('formInterviewFeedback')?.reset();
            await loadAtsInterviews();
        }
    } catch (err) {
        showToast(`Gagal menyimpan evaluasi: ${err.message}`, 'error');
    }
}

// Modal 7: Buat Surat Offering
function openCreateOfferModal(applicationId, candidateName) {
    document.getElementById('offAppId').value = applicationId;
    const nameEl = document.getElementById('offCandName');
    if (nameEl) nameEl.textContent = candidateName;

    // Default start date = 14 days later, expiry = 7 days later
    const start = new Date();
    start.setDate(start.getDate() + 14);
    const expiry = new Date();
    expiry.setDate(expiry.getDate() + 7);

    const sEl = document.getElementById('offStartDate');
    const eEl = document.getElementById('offExpiryDate');
    if (sEl) sEl.value = start.toISOString().slice(0, 10);
    if (eEl) eEl.value = expiry.toISOString().slice(0, 10);

    const modal = document.getElementById('modalCreateOffer');
    if (modal) modal.classList.add('active');
}

async function saveOffer(e) {
    e.preventDefault();
    const payload = {
        application_id: document.getElementById('offAppId')?.value,
        basic_salary: parseFloat(document.getElementById('offSalary')?.value || 0),
        start_date: document.getElementById('offStartDate')?.value,
        expiry_date: document.getElementById('offExpiryDate')?.value,
        terms: document.getElementById('offTerms')?.value.trim() || null,
    };

    try {
        const res = await apiFetch('/recruitment/offers', {
            method: 'POST',
            body: JSON.stringify(payload)
        });
        if (res.status === 'success') {
            showToast('Surat offering berhasil diterbitkan!', 'success');
            closeModal('modalCreateOffer');
            document.getElementById('formCreateOffer')?.reset();
            await Promise.all([loadAtsApplications(), loadAtsMetrics()]);
        }
    } catch (err) {
        showToast(`Gagal membuat offering: ${err.message}`, 'error');
    }
}

// Modal 8: Konversi Pelamar ke Master Karyawan
function openConvertToEmployeeModal(applicationId, candidateName) {
    document.getElementById('hireAppId').value = applicationId;
    const nameEl = document.getElementById('hireCandName');
    if (nameEl) nameEl.textContent = candidateName;

    const modal = document.getElementById('modalConvertToEmployee');
    if (modal) modal.classList.add('active');
}

async function submitConvertToEmployee(e) {
    e.preventDefault();
    const appId = document.getElementById('hireAppId')?.value;
    const payload = {
        employee_no: document.getElementById('hireEmployeeNo')?.value.trim() || null,
        employment_status: document.getElementById('hireEmploymentStatus')?.value || 'contract',
    };

    try {
        const res = await apiFetch(`/recruitment/applications/${appId}/convert-to-employee`, {
            method: 'POST',
            body: JSON.stringify(payload)
        });
        if (res.status === 'success') {
            showToast('Kandidat berhasil diangkat menjadi Karyawan Resmi!', 'success');
            closeModal('modalConvertToEmployee');
            document.getElementById('formConvertToEmployee')?.reset();
            await Promise.all([loadAtsApplications(), loadAtsMetrics(), loadEmployees()]);
        }
    } catch (err) {
        showToast(`Gagal mengangkat karyawan: ${err.message}`, 'error');
    }
}

// ==========================================
// Section 8: Internship Management Controller
// ==========================================
state.internship = {
    activePill: 'interns',
    internships: [],
    activities: [],
    reports: [],
    evaluations: [],
    mentors: [],
    candidates: [],
    searchDebounce: null,
};

function switchInternPill(pillName, btn) {
    document.querySelectorAll('#internshipTab .ats-nav-pill').forEach(el => el.classList.remove('active'));
    document.querySelectorAll('#internshipTab .ats-sub-content').forEach(el => el.classList.remove('active'));

    if (btn) {
        btn.classList.add('active');
    } else {
        const defaultBtn = document.getElementById(`pillIntern${pillName.charAt(0).toUpperCase() + pillName.slice(1)}`);
        if (defaultBtn) defaultBtn.classList.add('active');
    }

    const subTarget = document.getElementById(`internSub${pillName.charAt(0).toUpperCase() + pillName.slice(1)}`);
    if (subTarget) subTarget.classList.add('active');

    state.internship.activePill = pillName;

    if (pillName === 'interns') loadInternships();
    if (pillName === 'activities') loadInternActivities();
    if (pillName === 'reports') loadInternReports();
    if (pillName === 'evaluations') loadInternEvaluations();
}

async function loadInternshipData() {
    await Promise.allSettled([
        loadInternMetrics(),
        loadInternLookups(),
    ]);

    if (state.internship.activePill === 'interns') loadInternships();
    else if (state.internship.activePill === 'activities') loadInternActivities();
    else if (state.internship.activePill === 'reports') loadInternReports();
    else if (state.internship.activePill === 'evaluations') loadInternEvaluations();
}

async function loadInternLookups() {
    try {
        const [empRes, candRes] = await Promise.allSettled([
            apiFetch('/user-management/users?per_page=100'),
            apiFetch('/recruitment/candidates')
        ]);

        if (empRes.status === 'fulfilled' && empRes.value?.status === 'success') {
            state.internship.mentors = (empRes.value.data || []).map(u => ({
                id: u.id,
                name: u.name,
                role: u.role || u.role_jabatan || 'Employee'
            }));
        }

        if (candRes.status === 'fulfilled' && candRes.value?.status === 'success') {
            state.internship.candidates = candRes.value.data || [];
        }
    } catch (err) {
        console.warn('Internship lookups error:', err);
    }
}

async function loadInternMetrics() {
    try {
        const res = await apiFetch('/internships/metrics');
        if (res.status === 'success' && res.data) {
            const m = res.data;
            const elActive = document.getElementById('internMetricActive');
            const elPending = document.getElementById('internMetricPendingReports');
            const elCompleted = document.getElementById('internMetricCompleted');
            const elActivities = document.getElementById('internMetricTotalActivities');

            if (elActive) elActive.textContent = m.active_interns ?? 0;
            if (elPending) elPending.textContent = m.pending_reports ?? 0;
            if (elCompleted) elCompleted.textContent = m.completed_interns ?? 0;
            if (elActivities) elActivities.textContent = m.total_activities ?? 0;
        }
    } catch (err) {
        console.warn('Gagal memuat metrik magang:', err);
    }
}

// Sub-Tab 1: Daftar Pemagang
async function loadInternships() {
    const tbody = document.getElementById('internsTableBody');
    if (!tbody) return;

    const search = document.getElementById('searchInternships')?.value.trim() || '';
    const status = document.getElementById('filterInternshipStatus')?.value || '';

    tbody.innerHTML = `<tr><td colspan="8" class="loading-td"><div class="spinner"></div> Memuat daftar pemagang...</td></tr>`;

    try {
        let url = '/internships';
        const params = [];
        if (search) params.push(`search=${encodeURIComponent(search)}`);
        if (status) params.push(`status=${encodeURIComponent(status)}`);
        if (params.length > 0) url += `?${params.join('&')}`;

        const res = await apiFetch(url);
        if (res.status !== 'success' || !Array.isArray(res.data) || res.data.length === 0) {
            tbody.innerHTML = `<tr><td colspan="8" class="empty-td">Tidak ada data pemagang ditemukan. Klik "+ Tambah Pemagang" atau konversi dari pelamar.</td></tr>`;
            return;
        }

        state.internship.internships = res.data;

        tbody.innerHTML = res.data.map(intn => {
            const emp = intn.employee || {};
            const mentor = intn.mentor || {};
            const internName = emp.name || intn.candidate?.full_name || 'Pemagang';
            const statusClass = intn.status === 'ACTIVE' ? 'badge-active' : (intn.status === 'COMPLETED' ? 'badge-info' : 'badge-danger');

            return `
                <tr>
                    <td>
                        <span style="font-family: monospace; font-weight: 700; color: var(--primary);">${escapeHtml(intn.intern_id)}</span>
                    </td>
                    <td>
                        <div style="font-weight: 600; color: #ffffff;">${escapeHtml(internName)}</div>
                        <div style="font-size: 0.775rem; color: var(--text-muted);">${escapeHtml(emp.email || intn.candidate?.email || '-')} ${emp.phone ? '• ' + escapeHtml(emp.phone) : ''}</div>
                    </td>
                    <td>
                        <div style="font-weight: 600; color: #e2e8f0;">${escapeHtml(intn.institution)}</div>
                        <div style="font-size: 0.775rem; color: var(--text-muted);">${escapeHtml(intn.major)} (${escapeHtml(intn.education_level || 'S1')})</div>
                    </td>
                    <td>
                        <div style="color: #cbd5e1;">${escapeHtml(intn.position_title || 'Intern')}</div>
                        <div style="font-size: 0.775rem; color: var(--text-muted);">${escapeHtml(intn.division?.name || 'Seluruh Divisi')}</div>
                    </td>
                    <td>
                        <div style="color: #a5b4fc; font-weight: 600;">${escapeHtml(mentor.name || 'Belum Ditugaskan')}</div>
                        <div style="font-size: 0.775rem; color: var(--text-muted);">${escapeHtml(mentor.role || '')}</div>
                    </td>
                    <td style="font-size: 0.8rem; color: var(--text-muted);">
                        ${escapeHtml(intn.start_date || '')} s/d ${escapeHtml(intn.end_date || '')}
                    </td>
                    <td>
                        <span class="badge ${statusClass}">${escapeHtml(intn.status)}</span>
                    </td>
                    <td style="text-align: right;">
                        <div style="display: flex; gap: 0.4rem; justify-content: flex-end; flex-wrap: wrap;">
                            <button class="btn-action" title="Catat Aktivitas Harian" onclick="openLogActivityModal(${intn.id}, '${escapeHtml(internName)}')">
                                📝 Log
                            </button>
                            <button class="btn-action" title="Ajukan Laporan Magang" onclick="openSubmitReportModal(${intn.id}, '${escapeHtml(internName)}')">
                                📑 Laporan
                            </button>
                            <button class="btn-action" title="Lembar Evaluasi" onclick="openSubmitEvaluationModal(${intn.id}, '${escapeHtml(internName)}')">
                                ⭐ Nilai
                            </button>
                            ${intn.status === 'ACTIVE' ? `
                            <button class="btn-action" style="background: rgba(16, 185, 129, 0.2); color: #34d399; border-color: rgba(16, 185, 129, 0.4);" title="Selesaikan Magang & Cetak Sertifikat" onclick="openCompleteInternshipModal(${intn.id}, '${escapeHtml(internName)}')">
                                ✓ Lulus
                            </button>
                            ` : ''}
                        </div>
                    </td>
                </tr>
            `;
        }).join('');
    } catch (err) {
        tbody.innerHTML = `<tr><td colspan="8" class="error-td">Gagal memuat pemagang: ${escapeHtml(err.message)}</td></tr>`;
    }
}

// Sub-Tab 2: Logbook Aktivitas Harian
async function loadInternActivities() {
    const tbody = document.getElementById('internActivitiesTableBody');
    if (!tbody) return;

    tbody.innerHTML = `<tr><td colspan="8" class="loading-td"><div class="spinner"></div> Memuat aktivitas harian...</td></tr>`;

    try {
        if (!state.internship.internships.length) {
            const resInt = await apiFetch('/internships');
            if (resInt.status === 'success') state.internship.internships = resInt.data || [];
        }

        const allActivities = [];
        for (const intn of state.internship.internships.slice(0, 10)) {
            const actRes = await apiFetch(`/internships/${intn.id}/activities`);
            if (actRes.status === 'success' && Array.isArray(actRes.data)) {
                actRes.data.forEach(a => {
                    a.intern_name = intn.employee?.name || intn.candidate?.full_name || intn.intern_id;
                    allActivities.push(a);
                });
            }
        }

        if (allActivities.length === 0) {
            tbody.innerHTML = `<tr><td colspan="8" class="empty-td">Belum ada aktivitas harian yang dicatat. Klik "+ Catat Aktivitas Harian".</td></tr>`;
            return;
        }

        tbody.innerHTML = allActivities.map(act => {
            const statusClass = act.status === 'REVIEWED' ? 'badge-active' : (act.status === 'REJECTED' ? 'badge-danger' : 'badge-warning');

            return `
                <tr>
                    <td>
                        <div style="font-weight: 600; color: #ffffff;">${escapeHtml(act.activity_date)}</div>
                        <div style="font-size: 0.775rem; color: var(--text-muted);">${escapeHtml(act.start_time || '08:30')} - ${escapeHtml(act.end_time || '17:00')}</div>
                    </td>
                    <td>
                        <div style="font-weight: 600; color: #a5b4fc;">${escapeHtml(act.intern_name)}</div>
                    </td>
                    <td>
                        <div style="font-weight: 700; color: #ffffff;">${escapeHtml(act.title)}</div>
                        <div style="font-size: 0.8rem; color: #cbd5e1; margin-top: 2px;">${escapeHtml(act.description)}</div>
                    </td>
                    <td>
                        <span style="font-size: 0.8rem; color: var(--text-muted);">${escapeHtml(act.project_task_ref || '-')}</span>
                    </td>
                    <td style="text-align: center; font-weight: 700; color: #38bdf8;">
                        ${act.progress_percent}%
                    </td>
                    <td>
                        <span class="badge ${statusClass}">${escapeHtml(act.status)}</span>
                    </td>
                    <td>
                        <div style="font-size: 0.8rem; color: var(--text-muted);">${escapeHtml(act.mentor_notes || 'Belum diverifikasi')}</div>
                    </td>
                    <td style="text-align: right;">
                        ${act.status === 'SUBMITTED' ? `
                            <button class="btn-action" style="background: rgba(99, 102, 241, 0.2); color: #a5b4fc; border-color: rgba(99, 102, 241, 0.4);" onclick="openReviewActivityModal(${act.id})">
                                🔍 Verifikasi
                            </button>
                        ` : ''}
                    </td>
                </tr>
            `;
        }).join('');
    } catch (err) {
        tbody.innerHTML = `<tr><td colspan="8" class="error-td">Gagal memuat aktivitas: ${escapeHtml(err.message)}</td></tr>`;
    }
}

// Sub-Tab 3: Laporan Bulanan & Akhir
async function loadInternReports() {
    const tbody = document.getElementById('internReportsTableBody');
    if (!tbody) return;

    tbody.innerHTML = `<tr><td colspan="7" class="loading-td"><div class="spinner"></div> Memuat laporan magang...</td></tr>`;

    try {
        if (!state.internship.internships.length) {
            const resInt = await apiFetch('/internships');
            if (resInt.status === 'success') state.internship.internships = resInt.data || [];
        }

        const allReports = [];
        for (const intn of state.internship.internships.slice(0, 10)) {
            const repRes = await apiFetch(`/internships/${intn.id}/reports`);
            if (repRes.status === 'success' && Array.isArray(repRes.data)) {
                repRes.data.forEach(r => {
                    r.intern_name = intn.employee?.name || intn.candidate?.full_name || intn.intern_id;
                    allReports.push(r);
                });
            }
        }

        if (allReports.length === 0) {
            tbody.innerHTML = `<tr><td colspan="7" class="empty-td">Belum ada laporan magang yang diajukan.</td></tr>`;
            return;
        }

        tbody.innerHTML = allReports.map(rep => {
            const statusClass = rep.status === 'APPROVED' ? 'badge-active' : (rep.status === 'REVISION_REQUIRED' ? 'badge-danger' : 'badge-warning');

            return `
                <tr>
                    <td>
                        <span class="badge" style="background: rgba(56, 189, 248, 0.2); color: #38bdf8; font-weight: 700;">${escapeHtml(rep.report_type)}</span>
                        <div style="font-size: 0.775rem; color: var(--text-muted); margin-top: 3px;">Periode: ${escapeHtml(rep.period_month || '-')}</div>
                    </td>
                    <td>
                        <div style="font-weight: 600; color: #ffffff;">${escapeHtml(rep.intern_name)}</div>
                    </td>
                    <td>
                        <div style="font-weight: 700; color: #ffffff;">${escapeHtml(rep.title)}</div>
                    </td>
                    <td>
                        <div style="font-size: 0.8rem; color: #cbd5e1;">${escapeHtml(rep.summary)}</div>
                    </td>
                    <td>
                        <span class="badge ${statusClass}">${escapeHtml(rep.status)}</span>
                    </td>
                    <td>
                        <div style="font-size: 0.8rem; color: var(--text-muted);">${escapeHtml(rep.mentor_notes || 'Menunggu peninjauan mentor')}</div>
                    </td>
                    <td style="text-align: right;">
                        ${rep.status === 'SUBMITTED' ? `
                            <button class="btn-action" style="background: rgba(99, 102, 241, 0.2); color: #a5b4fc; border-color: rgba(99, 102, 241, 0.4);" onclick="openReviewReportModal(${rep.id})">
                                🔍 Review
                            </button>
                        ` : ''}
                    </td>
                </tr>
            `;
        }).join('');
    } catch (err) {
        tbody.innerHTML = `<tr><td colspan="7" class="error-td">Gagal memuat laporan: ${escapeHtml(err.message)}</td></tr>`;
    }
}

// Sub-Tab 4: Evaluasi & Penilaian
async function loadInternEvaluations() {
    const tbody = document.getElementById('internEvaluationsTableBody');
    if (!tbody) return;

    tbody.innerHTML = `<tr><td colspan="7" class="loading-td"><div class="spinner"></div> Memuat evaluasi magang...</td></tr>`;

    try {
        if (!state.internship.internships.length) {
            const resInt = await apiFetch('/internships');
            if (resInt.status === 'success') state.internship.internships = resInt.data || [];
        }

        const allEvaluations = [];
        for (const intn of state.internship.internships.slice(0, 10)) {
            const evalRes = await apiFetch(`/internships/${intn.id}/evaluations`);
            if (evalRes.status === 'success' && Array.isArray(evalRes.data)) {
                evalRes.data.forEach(e => {
                    e.intern_name = intn.employee?.name || intn.candidate?.full_name || intn.intern_id;
                    allEvaluations.push(e);
                });
            }
        }

        if (allEvaluations.length === 0) {
            tbody.innerHTML = `<tr><td colspan="7" class="empty-td">Belum ada evaluasi nilai yang dicatat.</td></tr>`;
            return;
        }

        tbody.innerHTML = allEvaluations.map(ev => {
            const score = parseFloat(ev.average_score || 0);
            const scoreBadge = score >= 85 ? 'badge-active' : (score >= 70 ? 'badge-info' : 'badge-warning');

            return `
                <tr>
                    <td>
                        <div style="font-weight: 600; color: #ffffff;">${escapeHtml(ev.intern_name)}</div>
                    </td>
                    <td>
                        <span class="badge" style="background: rgba(168, 85, 247, 0.2); color: #c084fc;">${escapeHtml(ev.evaluation_type)}</span>
                    </td>
                    <td>
                        <div style="color: #cbd5e1;">${escapeHtml(ev.evaluator_name || 'Pembimbing')}</div>
                        <div style="font-size: 0.775rem; color: var(--text-muted);">${escapeHtml(ev.evaluator_role || 'Mentor')}</div>
                    </td>
                    <td>
                        <span class="badge ${scoreBadge}" style="font-size: 0.9rem; font-weight: 700;">${score}/100</span>
                    </td>
                    <td>
                        <span style="font-weight: 700; color: ${ev.final_recommendation === 'HIRE_AS_EMPLOYEE' ? '#10b981' : '#fbbf24'};">
                            ${escapeHtml(ev.final_recommendation)}
                        </span>
                    </td>
                    <td style="font-size: 0.8rem; color: var(--text-muted);">
                        ${escapeHtml(ev.evaluated_at || '')}
                    </td>
                    <td style="text-align: right;">
                        <span style="color: var(--text-muted); font-size: 0.8rem;">Tercatat</span>
                    </td>
                </tr>
            `;
        }).join('');
    } catch (err) {
        tbody.innerHTML = `<tr><td colspan="7" class="error-td">Gagal memuat evaluasi: ${escapeHtml(err.message)}</td></tr>`;
    }
}

function debounceInternshipsSearch() {
    clearTimeout(state.internship.searchDebounce);
    state.internship.searchDebounce = setTimeout(loadInternships, 300);
}

// Modal 1: Tambah Pemagang Langsung
function openAddInternshipModal() {
    const divSelect = document.getElementById('intDivisionId');
    const mentorSelect = document.getElementById('intMentorId');

    if (divSelect && state.organization?.divisions) {
        divSelect.innerHTML = '<option value="">Pilih Divisi</option>' +
            state.organization.divisions.map(d => `<option value="${d.id}">${escapeHtml(d.name)}</option>`).join('');
    }

    if (mentorSelect && state.internship.mentors) {
        mentorSelect.innerHTML = '<option value="">Pilih Mentor</option>' +
            state.internship.mentors.map(m => `<option value="${m.id}">${escapeHtml(m.name)} (${escapeHtml(m.role)})</option>`).join('');
    }

    const now = new Date();
    const end = new Date();
    end.setMonth(end.getMonth() + 3);

    const sEl = document.getElementById('intStartDate');
    const eEl = document.getElementById('intEndDate');
    if (sEl) sEl.value = now.toISOString().slice(0, 10);
    if (eEl) eEl.value = end.toISOString().slice(0, 10);

    const modal = document.getElementById('modalAddInternship');
    if (modal) modal.classList.add('active');
}

async function saveInternship(e) {
    e.preventDefault();
    const payload = {
        institution: document.getElementById('intInstitution')?.value.trim(),
        major: document.getElementById('intMajor')?.value.trim(),
        education_level: document.getElementById('intEducationLevel')?.value,
        semester: parseInt(document.getElementById('intSemester')?.value || 6),
        position_title: document.getElementById('intPositionTitle')?.value.trim() || 'Intern',
        start_date: document.getElementById('intStartDate')?.value,
        end_date: document.getElementById('intEndDate')?.value,
        division_id: document.getElementById('intDivisionId')?.value || null,
        mentor_id: document.getElementById('intMentorId')?.value || null,
        campus_supervisor_name: document.getElementById('intCampusSupervisor')?.value.trim() || null,
        campus_supervisor_contact: document.getElementById('intCampusContact')?.value.trim() || null,
        project_assignment: document.getElementById('intProjectAssignment')?.value.trim() || null,
    };

    try {
        const res = await apiFetch('/internships', {
            method: 'POST',
            body: JSON.stringify(payload)
        });
        if (res.status === 'success') {
            showToast('Program magang baru berhasil didaftarkan!', 'success');
            closeModal('modalAddInternship');
            document.getElementById('formAddInternship')?.reset();
            await Promise.all([loadInternships(), loadInternMetrics()]);
        }
    } catch (err) {
        showToast(`Gagal mendaftarkan magang: ${err.message}`, 'error');
    }
}

// Modal 2: Konversi Kandidat ke Magang
async function openConvertCandidateModal() {
    await loadInternLookups();

    const candSelect = document.getElementById('convCandidateId');
    const divSelect = document.getElementById('convDivisionId');
    const mentorSelect = document.getElementById('convMentorId');

    if (candSelect && state.internship.candidates) {
        candSelect.innerHTML = '<option value="">-- Pilih Pelamar/Kandidat --</option>' +
            state.internship.candidates.map(c => {
                const name = `${c.first_name || ''} ${c.last_name || ''}`.trim();
                return `<option value="${c.id}">${escapeHtml(name)} (${escapeHtml(c.candidate_no || '')})</option>`;
            }).join('');
    }

    if (divSelect && state.organization?.divisions) {
        divSelect.innerHTML = '<option value="">Pilih Divisi</option>' +
            state.organization.divisions.map(d => `<option value="${d.id}">${escapeHtml(d.name)}</option>`).join('');
    }

    if (mentorSelect && state.internship.mentors) {
        mentorSelect.innerHTML = '<option value="">Pilih Mentor</option>' +
            state.internship.mentors.map(m => `<option value="${m.id}">${escapeHtml(m.name)} (${escapeHtml(m.role)})</option>`).join('');
    }

    const now = new Date();
    const end = new Date();
    end.setMonth(end.getMonth() + 3);

    const sEl = document.getElementById('convStartDate');
    const eEl = document.getElementById('convEndDate');
    if (sEl) sEl.value = now.toISOString().slice(0, 10);
    if (eEl) eEl.value = end.toISOString().slice(0, 10);

    const modal = document.getElementById('modalConvertCandidateToIntern');
    if (modal) modal.classList.add('active');
}

async function submitConvertCandidateToIntern(e) {
    e.preventDefault();
    const payload = {
        candidate_id: document.getElementById('convCandidateId')?.value,
        institution: document.getElementById('convInstitution')?.value.trim(),
        major: document.getElementById('convMajor')?.value.trim(),
        start_date: document.getElementById('convStartDate')?.value,
        end_date: document.getElementById('convEndDate')?.value,
        division_id: document.getElementById('convDivisionId')?.value || null,
        mentor_id: document.getElementById('convMentorId')?.value || null,
        position_title: document.getElementById('convPositionTitle')?.value.trim() || 'Intern',
    };

    try {
        const res = await apiFetch('/internships/convert-candidate', {
            method: 'POST',
            body: JSON.stringify(payload)
        });
        if (res.status === 'success') {
            showToast('Kandidat berhasil dikonversi menjadi Pemagang resmi!', 'success');
            closeModal('modalConvertCandidateToIntern');
            document.getElementById('formConvertCandidateToIntern')?.reset();
            await Promise.all([loadInternships(), loadInternMetrics(), loadEmployees()]);
        }
    } catch (err) {
        showToast(`Gagal konversi magang: ${err.message}`, 'error');
    }
}

// Modal 3: Catat Logbook Aktivitas
function openLogActivityModal(internshipId, internName) {
    if (!internshipId && state.internship.internships.length) {
        internshipId = state.internship.internships[0].id;
        internName = state.internship.internships[0].employee?.name || 'Pemagang';
    }

    document.getElementById('actInternshipId').value = internshipId || '';
    const nameEl = document.getElementById('actInternName');
    if (nameEl) nameEl.textContent = internName || 'Pemagang';

    const dEl = document.getElementById('actDate');
    if (dEl) dEl.value = new Date().toISOString().slice(0, 10);

    const modal = document.getElementById('modalLogInternActivity');
    if (modal) modal.classList.add('active');
}

async function saveInternActivity(e) {
    e.preventDefault();
    const intId = document.getElementById('actInternshipId')?.value;
    const payload = {
        activity_date: document.getElementById('actDate')?.value,
        start_time: document.getElementById('actStartTime')?.value || '08:30',
        end_time: document.getElementById('actEndTime')?.value || '17:00',
        title: document.getElementById('actTitle')?.value.trim(),
        description: document.getElementById('actDescription')?.value.trim(),
        project_task_ref: document.getElementById('actProjectRef')?.value.trim() || null,
        progress_percent: parseInt(document.getElementById('actProgress')?.value || 100),
    };

    try {
        const res = await apiFetch(`/internships/${intId}/activities`, {
            method: 'POST',
            body: JSON.stringify(payload)
        });
        if (res.status === 'success') {
            showToast('Logbook aktivitas harian berhasil dicatat!', 'success');
            closeModal('modalLogInternActivity');
            document.getElementById('formLogInternActivity')?.reset();
            await Promise.all([loadInternActivities(), loadInternMetrics()]);
        }
    } catch (err) {
        showToast(`Gagal mencatat logbook: ${err.message}`, 'error');
    }
}

// Modal 4: Review Logbook
function openReviewActivityModal(activityId) {
    document.getElementById('revActivityId').value = activityId;
    const modal = document.getElementById('modalReviewInternActivity');
    if (modal) modal.classList.add('active');
}

async function submitReviewInternActivity(e) {
    e.preventDefault();
    const actId = document.getElementById('revActivityId')?.value;
    const payload = {
        status: document.getElementById('revActivityStatus')?.value,
        mentor_notes: document.getElementById('revActivityNotes')?.value.trim(),
    };

    try {
        const res = await apiFetch(`/internships/activities/${actId}/review`, {
            method: 'PUT',
            body: JSON.stringify(payload)
        });
        if (res.status === 'success') {
            showToast('Verifikasi aktivitas harian berhasil disimpan!', 'success');
            closeModal('modalReviewInternActivity');
            document.getElementById('formReviewInternActivity')?.reset();
            await loadInternActivities();
        }
    } catch (err) {
        showToast(`Gagal verifikasi aktivitas: ${err.message}`, 'error');
    }
}

// Modal 5: Ajukan Laporan
function openSubmitReportModal(internshipId, internName) {
    if (!internshipId && state.internship.internships.length) {
        internshipId = state.internship.internships[0].id;
        internName = state.internship.internships[0].employee?.name || 'Pemagang';
    }

    document.getElementById('repInternshipId').value = internshipId || '';
    const nameEl = document.getElementById('repInternName');
    if (nameEl) nameEl.textContent = internName || 'Pemagang';

    const pEl = document.getElementById('repPeriodMonth');
    if (pEl) pEl.value = new Date().toISOString().slice(0, 7);

    const modal = document.getElementById('modalSubmitInternReport');
    if (modal) modal.classList.add('active');
}

async function saveInternReport(e) {
    e.preventDefault();
    const intId = document.getElementById('repInternshipId')?.value;
    const payload = {
        report_type: document.getElementById('repType')?.value,
        period_month: document.getElementById('repPeriodMonth')?.value,
        title: document.getElementById('repTitle')?.value.trim(),
        summary: document.getElementById('repSummary')?.value.trim(),
        achievements: document.getElementById('repAchievements')?.value.trim() || null,
        issues_and_blockers: document.getElementById('repBlockers')?.value.trim() || null,
    };

    try {
        const res = await apiFetch(`/internships/${intId}/reports`, {
            method: 'POST',
            body: JSON.stringify(payload)
        });
        if (res.status === 'success') {
            showToast('Laporan magang berhasil diajukan!', 'success');
            closeModal('modalSubmitInternReport');
            document.getElementById('formSubmitInternReport')?.reset();
            await Promise.all([loadInternReports(), loadInternMetrics()]);
        }
    } catch (err) {
        showToast(`Gagal mengajukan laporan: ${err.message}`, 'error');
    }
}

// Modal 6: Review Laporan
function openReviewReportModal(reportId) {
    document.getElementById('revReportId').value = reportId;
    const modal = document.getElementById('modalReviewInternReport');
    if (modal) modal.classList.add('active');
}

async function submitReviewInternReport(e) {
    e.preventDefault();
    const repId = document.getElementById('revReportId')?.value;
    const payload = {
        status: document.getElementById('revReportStatus')?.value,
        mentor_notes: document.getElementById('revReportNotes')?.value.trim(),
    };

    try {
        const res = await apiFetch(`/internships/reports/${repId}/review`, {
            method: 'PUT',
            body: JSON.stringify(payload)
        });
        if (res.status === 'success') {
            showToast('Keputusan review laporan berhasil disimpan!', 'success');
            closeModal('modalReviewInternReport');
            document.getElementById('formReviewInternReport')?.reset();
            await Promise.all([loadInternReports(), loadInternMetrics()]);
        }
    } catch (err) {
        showToast(`Gagal mereview laporan: ${err.message}`, 'error');
    }
}

// Modal 7: Evaluasi & Penilaian
function openSubmitEvaluationModal(internshipId, internName) {
    if (!internshipId && state.internship.internships.length) {
        internshipId = state.internship.internships[0].id;
        internName = state.internship.internships[0].employee?.name || 'Pemagang';
    }

    document.getElementById('evalInternshipId').value = internshipId || '';
    const nameEl = document.getElementById('evalInternName');
    if (nameEl) nameEl.textContent = internName || 'Pemagang';

    const modal = document.getElementById('modalSubmitInternEvaluation');
    if (modal) modal.classList.add('active');
}

async function saveInternEvaluation(e) {
    e.preventDefault();
    const intId = document.getElementById('evalInternshipId')?.value;
    const payload = {
        evaluation_type: document.getElementById('evalType')?.value,
        discipline_score: parseInt(document.getElementById('evalDiscipline')?.value || 80),
        communication_score: parseInt(document.getElementById('evalCommunication')?.value || 80),
        technical_score: parseInt(document.getElementById('evalTechnical')?.value || 80),
        initiative_score: parseInt(document.getElementById('evalInitiative')?.value || 80),
        teamwork_score: parseInt(document.getElementById('evalTeamwork')?.value || 80),
        attendance_score: parseInt(document.getElementById('evalAttendance')?.value || 80),
        task_completion_score: parseInt(document.getElementById('evalTaskCompletion')?.value || 80),
        professionalism_score: parseInt(document.getElementById('evalProfessionalism')?.value || 80),
        strengths: document.getElementById('evalStrengths')?.value.trim() || null,
        improvements: document.getElementById('evalImprovements')?.value.trim() || null,
        final_recommendation: document.getElementById('evalRecommendation')?.value,
    };

    try {
        const res = await apiFetch(`/internships/${intId}/evaluations`, {
            method: 'POST',
            body: JSON.stringify(payload)
        });
        if (res.status === 'success') {
            showToast('Lembar evaluasi magang berhasil disimpan!', 'success');
            closeModal('modalSubmitInternEvaluation');
            document.getElementById('formSubmitInternEvaluation')?.reset();
            await loadInternEvaluations();
        }
    } catch (err) {
        showToast(`Gagal menyimpan evaluasi: ${err.message}`, 'error');
    }
}

// Modal 8: Selesaikan Magang
function openCompleteInternshipModal(internshipId, internName) {
    document.getElementById('compInternshipId').value = internshipId;
    const nameEl = document.getElementById('compInternName');
    if (nameEl) nameEl.textContent = internName;

    const modal = document.getElementById('modalCompleteInternship');
    if (modal) modal.classList.add('active');
}

async function submitCompleteInternship(e) {
    e.preventDefault();
    const intId = document.getElementById('compInternshipId')?.value;
    const payload = {
        certificate_no: document.getElementById('compCertificateNo')?.value.trim() || null,
        completion_notes: document.getElementById('compNotes')?.value.trim() || null,
    };

    try {
        const res = await apiFetch(`/internships/${intId}/complete`, {
            method: 'POST',
            body: JSON.stringify(payload)
        });
        if (res.status === 'success') {
            showToast('Program magang berhasil diselesaikan dengan predikat kelulusan!', 'success');
            closeModal('modalCompleteInternship');
            document.getElementById('formCompleteInternship')?.reset();
            await Promise.all([loadInternships(), loadInternMetrics()]);
        }
    } catch (err) {
        showToast(`Gagal menyelesaikan magang: ${err.message}`, 'error');
    }
}

// ============================================================
// SECTION 9: ONBOARDING, CONTRACTS & HR DOCUMENTS CONTROLLER
// ============================================================

async function loadOnboardingData() {
    await Promise.all([
        loadOnboardingMetrics(),
        loadOnboardingCases(),
        loadOnboardingContracts(),
        loadOnboardingDocuments(),
        loadExpiringContracts()
    ]);
}

async function loadOnboardingMetrics() {
    try {
        const res = await apiFetch('/onboarding/metrics');
        if (res.success && res.data) {
            state.onboarding.metrics = res.data;
            const m = res.data;
            const elAct = document.getElementById('metricActiveOnboardings');
            const elBlk = document.getElementById('metricBlockedTasks');
            const elCtr = document.getElementById('metricActiveContracts');
            const elDoc = document.getElementById('metricPendingDocuments');

            if (elAct) elAct.textContent = m.active_onboardings ?? 0;
            if (elBlk) elBlk.textContent = m.blocked_tasks ?? 0;
            if (elCtr) elCtr.textContent = m.active_contracts ?? 0;
            if (elDoc) elDoc.textContent = m.pending_documents ?? 0;
        }
    } catch (err) {
        console.error('Failed to load onboarding metrics', err);
    }
}

function switchOnboardingSubTab(subTab, btn) {
    document.querySelectorAll('#onboardingTab .ats-sub-content').forEach(el => el.style.display = 'none');
    document.querySelectorAll('#onboardingTab .subnav-btn').forEach(el => el.classList.remove('active'));

    if (btn) btn.classList.add('active');

    if (subTab === 'cases') {
        const el = document.getElementById('onbSubCases');
        if (el) el.style.display = 'block';
        loadOnboardingCases();
    } else if (subTab === 'contracts') {
        const el = document.getElementById('onbSubContracts');
        if (el) el.style.display = 'block';
        loadOnboardingContracts();
    } else if (subTab === 'documents') {
        const el = document.getElementById('onbSubDocuments');
        if (el) el.style.display = 'block';
        loadOnboardingDocuments();
    } else if (subTab === 'expiring') {
        const el = document.getElementById('onbSubExpiring');
        if (el) el.style.display = 'block';
        loadExpiringContracts();
    }
}

async function loadOnboardingCases() {
    const tbody = document.getElementById('onboardingCasesTableBody');
    if (!tbody) return;

    const search = document.getElementById('onbCaseSearch')?.value.trim() || '';
    const status = document.getElementById('onbCaseStatusFilter')?.value || '';
    const empType = document.getElementById('onbCaseTypeFilter')?.value || '';

    let url = `/onboarding/cases?search=${encodeURIComponent(search)}`;
    if (status) url += `&status=${encodeURIComponent(status)}`;
    if (empType) url += `&employment_type=${encodeURIComponent(empType)}`;

    try {
        const res = await apiFetch(url);
        if (res.success && res.data) {
            state.onboarding.cases = res.data;
            renderOnboardingCasesTable(res.data);
        }
    } catch (err) {
        tbody.innerHTML = `<tr><td colspan="8" style="text-align: center; color: #f87171; padding: 2rem;">Gagal memuat kasus onboarding: ${escapeHtml(err.message)}</td></tr>`;
    }
}

function renderOnboardingCasesTable(cases) {
    const tbody = document.getElementById('onboardingCasesTableBody');
    if (!tbody) return;

    if (!cases || cases.length === 0) {
        tbody.innerHTML = `<tr><td colspan="8" style="text-align: center; color: var(--text-muted); padding: 2rem;">Belum ada kasus onboarding terdaftar.</td></tr>`;
        return;
    }

    const statusBadge = (s) => {
        if (s === 'COMPLETED') return `<span style="background: rgba(16, 185, 129, 0.2); color: #10b981; padding: 0.2rem 0.6rem; border-radius: 999px; font-size: 0.75rem; font-weight: 700;">✓ SELESAI</span>`;
        if (s === 'BLOCKED') return `<span style="background: rgba(239, 68, 68, 0.2); color: #ef4444; padding: 0.2rem 0.6rem; border-radius: 999px; font-size: 0.75rem; font-weight: 700;">⚠️ TERKENDALA</span>`;
        if (s === 'IN_PROGRESS') return `<span style="background: rgba(56, 189, 248, 0.2); color: #38bdf8; padding: 0.2rem 0.6rem; border-radius: 999px; font-size: 0.75rem; font-weight: 700;">⏳ PROSES</span>`;
        return `<span style="background: rgba(148, 163, 184, 0.2); color: #94a3b8; padding: 0.2rem 0.6rem; border-radius: 999px; font-size: 0.75rem; font-weight: 700;">PENDING</span>`;
    };

    tbody.innerHTML = cases.map(c => {
        const empName = c.employee?.name || c.internship?.intern_name || 'Karyawan Baru';
        const empId = c.employee?.employee_id || c.internship?.intern_id || '-';
        const divName = c.division?.name || c.employee?.department || '-';
        const posName = c.position?.name || c.employee?.role || c.employment_type;

        const totalTasks = c.tasks ? c.tasks.length : 0;
        const completedTasks = c.tasks ? c.tasks.filter(t => t.status === 'COMPLETED' || t.status === 'NOT_REQUIRED').length : 0;
        const progressPct = totalTasks > 0 ? Math.round((completedTasks / totalTasks) * 100) : 0;

        return `
            <tr>
                <td style="font-weight: 700; color: #38bdf8;">${escapeHtml(c.case_number)}</td>
                <td>
                    <div style="font-weight: 600; color: #fff;">${escapeHtml(empName)}</div>
                    <div style="font-size: 0.75rem; color: var(--text-muted);">${escapeHtml(empId)}</div>
                </td>
                <td>
                    <div>${escapeHtml(divName)}</div>
                    <div style="font-size: 0.75rem; color: var(--text-muted);">${escapeHtml(posName)}</div>
                </td>
                <td>
                    <div><span style="font-size: 0.775rem; font-weight: 600; color: #f59e0b;">${escapeHtml(c.employment_type)}</span></div>
                    <div style="font-size: 0.75rem; color: var(--text-muted);">${escapeHtml(c.work_location || '-')}</div>
                </td>
                <td>${escapeHtml(c.start_date)}</td>
                <td>
                    <div style="display: flex; align-items: center; gap: 0.5rem;">
                        <div style="flex: 1; background: rgba(255,255,255,0.1); height: 6px; border-radius: 999px; overflow: hidden; min-width: 60px;">
                            <div style="width: ${progressPct}%; background: ${c.status === 'BLOCKED' ? '#ef4444' : '#10b981'}; height: 100%;"></div>
                        </div>
                        <span style="font-size: 0.75rem; font-weight: 700; color: #fff;">${progressPct}%</span>
                    </div>
                    <div style="font-size: 0.7rem; color: var(--text-muted);">${completedTasks}/${totalTasks} Tugas</div>
                </td>
                <td>${statusBadge(c.status)}</td>
                <td style="text-align: right;">
                    <button class="btn-secondary" style="padding: 0.35rem 0.65rem; font-size: 0.75rem;" onclick="viewOnboardingCaseDetail(${c.id})">
                        🔍 Detail & Checklist
                    </button>
                </td>
            </tr>
        `;
    }).join('');
}

async function viewOnboardingCaseDetail(caseId) {
    try {
        const res = await apiFetch(`/onboarding/cases/${caseId}`);
        if (res.success && res.data) {
            state.onboarding.currentCase = res.data;
            const c = res.data;

            const titleEl = document.getElementById('viewOnbCaseTitle');
            const subEl = document.getElementById('viewOnbCaseSubtitle');
            const barEl = document.getElementById('viewOnbProgressBar');
            const txtEl = document.getElementById('viewOnbProgressText');
            const listEl = document.getElementById('viewOnbTasksList');
            const completeBtn = document.getElementById('btnCompleteCaseAction');

            const empName = c.employee?.name || c.internship?.intern_name || 'Karyawan Baru';
            if (titleEl) titleEl.textContent = `📋 Kasus Onboarding: ${c.case_number} (${empName})`;
            if (subEl) subEl.textContent = `Mulai: ${c.start_date} | Target: ${c.target_completion_date || '-'} | Status: ${c.status}`;

            const progress = res.progress ?? 0;
            if (barEl) barEl.style.width = `${progress}%`;
            if (txtEl) txtEl.textContent = `${progress}% (${c.status})`;

            if (completeBtn) {
                if (c.status === 'COMPLETED') {
                    completeBtn.style.display = 'none';
                } else {
                    completeBtn.style.display = 'inline-block';
                }
            }

            if (listEl) {
                if (!c.tasks || c.tasks.length === 0) {
                    listEl.innerHTML = `<div style="text-align: center; color: var(--text-muted); padding: 1.5rem;">Tidak ada tugas checklist.</div>`;
                } else {
                    const taskBadge = (s) => {
                        if (s === 'COMPLETED') return `<span style="color: #10b981; font-weight: 700; font-size: 0.75rem;">✓ SELESAI</span>`;
                        if (s === 'BLOCKED') return `<span style="color: #ef4444; font-weight: 700; font-size: 0.75rem;">⚠️ TERBLOKIR</span>`;
                        if (s === 'IN_PROGRESS') return `<span style="color: #38bdf8; font-weight: 700; font-size: 0.75rem;">⏳ PROSES</span>`;
                        if (s === 'NOT_REQUIRED') return `<span style="color: #94a3b8; font-weight: 700; font-size: 0.75rem;">TIDAK PERLU</span>`;
                        return `<span style="color: #fbbf24; font-weight: 700; font-size: 0.75rem;">PENDING</span>`;
                    };

                    listEl.innerHTML = c.tasks.map(t => `
                        <div style="display: flex; justify-content: space-between; align-items: center; background: rgba(255,255,255,0.03); border: 1px solid var(--border-color); border-radius: 0.5rem; padding: 0.75rem 1rem;">
                            <div style="flex: 1;">
                                <div style="display: flex; align-items: center; gap: 0.5rem;">
                                    <span style="font-weight: 600; color: #fff; font-size: 0.85rem;">${t.order_index}. ${escapeHtml(t.title)}</span>
                                    ${t.is_required ? '<span style="color: #ef4444; font-size: 0.7rem; font-weight: 700;">*WAJIB</span>' : '<span style="color: #94a3b8; font-size: 0.7rem;">(OPSIONAL)</span>'}
                                </div>
                                <div style="font-size: 0.75rem; color: var(--text-muted); margin-top: 0.2rem;">
                                    Kategori: <strong style="color: #38bdf8;">${escapeHtml(t.category)}</strong>
                                    ${t.due_date ? ` | Tenggat: ${t.due_date}` : ''}
                                    ${t.blocker_reason ? ` | <span style="color: #f87171; font-weight: 600;">Kendala: ${escapeHtml(t.blocker_reason)}</span>` : ''}
                                </div>
                            </div>
                            <div style="display: flex; align-items: center; gap: 0.75rem;">
                                ${taskBadge(t.status)}
                                ${c.status !== 'COMPLETED' ? `
                                    <button class="btn-secondary" style="padding: 0.25rem 0.5rem; font-size: 0.725rem;" onclick="openUpdateTaskModal(${t.id}, '${escapeHtml(t.title)}', '${t.status}', '${escapeHtml(t.blocker_reason || '')}')">
                                        Ubah Status
                                    </button>
                                ` : ''}
                            </div>
                        </div>
                    `).join('');
                }
            }

            const modal = document.getElementById('modalViewOnboardingCase');
            if (modal) modal.classList.add('active');
        }
    } catch (err) {
        showToast(`Gagal memuat detail checklist: ${err.message}`, 'error');
    }
}

function openUpdateTaskModal(taskId, title, status, blocker) {
    document.getElementById('taskUpdateId').value = taskId;
    const titleEl = document.getElementById('taskUpdateTitle');
    if (titleEl) titleEl.textContent = title;

    const statusEl = document.getElementById('taskUpdateStatus');
    if (statusEl) statusEl.value = status;

    const blockerEl = document.getElementById('taskBlockerReason');
    if (blockerEl) blockerEl.value = blocker || '';

    toggleBlockerReasonField(status);

    const modal = document.getElementById('modalUpdateOnboardingTask');
    if (modal) modal.classList.add('active');
}

function toggleBlockerReasonField(status) {
    const container = document.getElementById('taskBlockerReasonContainer');
    if (container) {
        container.style.display = status === 'BLOCKED' ? 'block' : 'none';
    }
}

async function submitTaskUpdate(e) {
    e.preventDefault();
    const taskId = document.getElementById('taskUpdateId')?.value;
    const status = document.getElementById('taskUpdateStatus')?.value;
    const blocker = document.getElementById('taskBlockerReason')?.value.trim();
    const notes = document.getElementById('taskUpdateNotes')?.value.trim();

    try {
        const res = await apiFetch(`/onboarding/tasks/${taskId}`, {
            method: 'PUT',
            body: JSON.stringify({
                status: status,
                blocker_reason: blocker || null,
                notes: notes || null
            })
        });

        if (res.success) {
            showToast('Status checklist berhasil diperbarui!', 'success');
            closeModal('modalUpdateOnboardingTask');
            if (state.onboarding.currentCase) {
                await viewOnboardingCaseDetail(state.onboarding.currentCase.id);
            }
            await Promise.all([loadOnboardingCases(), loadOnboardingMetrics()]);
        }
    } catch (err) {
        showToast(`Gagal memperbarui checklist: ${err.message}`, 'error');
    }
}

async function submitCompleteCaseDirect() {
    if (!state.onboarding.currentCase) return;
    const caseId = state.onboarding.currentCase.id;

    if (!confirm('Apakah Anda yakin seluruh checklist telah terpenuhi dan proses onboarding siap diselesaikan?')) {
        return;
    }

    try {
        const res = await apiFetch(`/onboarding/cases/${caseId}/complete`, {
            method: 'POST'
        });

        if (res.success) {
            showToast('✓ Selamat! Proses onboarding berhasil diselesaikan secara resmi.', 'success');
            closeModal('modalViewOnboardingCase');
            await Promise.all([loadOnboardingCases(), loadOnboardingMetrics()]);
        }
    } catch (err) {
        showToast(`✕ Gagal menyelesaikan onboarding: ${err.message}`, 'error');
    }
}

function openAddOnboardingCaseModal() {
    const select = document.getElementById('onbEmployeeSelect');
    if (select && state.employees) {
        select.innerHTML = '<option value="">Pilih Karyawan</option>' +
            state.employees.map(e => `<option value="${e.id}">${escapeHtml(e.name)} (${escapeHtml(e.employee_id)})</option>`).join('');
    }

    const today = new Date().toISOString().split('T')[0];
    const target = new Date(Date.now() + 14 * 86400000).toISOString().split('T')[0];

    const sEl = document.getElementById('onbStartDate');
    const tEl = document.getElementById('onbTargetDate');
    if (sEl) sEl.value = today;
    if (tEl) tEl.value = target;

    const modal = document.getElementById('modalAddOnboardingCase');
    if (modal) modal.classList.add('active');
}

async function saveOnboardingCase(e) {
    e.preventDefault();
    const payload = {
        employee_id: parseInt(document.getElementById('onbEmployeeSelect')?.value) || null,
        employment_type: document.getElementById('onbEmploymentType')?.value || 'PERMANENT',
        work_location: document.getElementById('onbWorkLocation')?.value.trim() || 'Kantor Pusat PKP',
        start_date: document.getElementById('onbStartDate')?.value,
        target_completion_date: document.getElementById('onbTargetDate')?.value,
        notes: document.getElementById('onbNotes')?.value.trim() || null,
    };

    try {
        const res = await apiFetch('/onboarding/cases', {
            method: 'POST',
            body: JSON.stringify(payload)
        });

        if (res.success) {
            showToast('Kasus onboarding dan 10 checklist standar berhasil diinisialisasi!', 'success');
            closeModal('modalAddOnboardingCase');
            document.getElementById('formAddOnboardingCase')?.reset();
            await Promise.all([loadOnboardingCases(), loadOnboardingMetrics()]);
        }
    } catch (err) {
        showToast(`Gagal membuat kasus: ${err.message}`, 'error');
    }
}

// ==========================================
// Contracts Controller
// ==========================================
async function loadOnboardingContracts() {
    const tbody = document.getElementById('contractsTableBody');
    if (!tbody) return;

    const search = document.getElementById('onbContractSearch')?.value.trim() || '';
    const type = document.getElementById('onbContractTypeFilter')?.value || '';

    let url = `/onboarding/contracts?search=${encodeURIComponent(search)}`;
    if (type) url += `&contract_type=${encodeURIComponent(type)}`;

    try {
        const res = await apiFetch(url);
        if (res.success && res.data) {
            state.onboarding.contracts = res.data;
            renderContractsTable(res.data);
        }
    } catch (err) {
        tbody.innerHTML = `<tr><td colspan="8" style="text-align: center; color: #f87171; padding: 2rem;">Gagal memuat kontrak kerja: ${escapeHtml(err.message)}</td></tr>`;
    }
}

function renderContractsTable(contracts) {
    const tbody = document.getElementById('contractsTableBody');
    if (!tbody) return;

    if (!contracts || contracts.length === 0) {
        tbody.innerHTML = `<tr><td colspan="8" style="text-align: center; color: var(--text-muted); padding: 2rem;">Belum ada kontrak kerja tersimpan.</td></tr>`;
        return;
    }

    const statusBadge = (s) => {
        if (s === 'ACTIVE') return `<span style="background: rgba(16, 185, 129, 0.2); color: #10b981; padding: 0.2rem 0.6rem; border-radius: 999px; font-size: 0.75rem; font-weight: 700;">✓ AKTIF</span>`;
        if (s === 'EXPIRED') return `<span style="background: rgba(239, 68, 68, 0.2); color: #ef4444; padding: 0.2rem 0.6rem; border-radius: 999px; font-size: 0.75rem; font-weight: 700;">KADALUARSA</span>`;
        if (s === 'PENDING_SIGNATURE') return `<span style="background: rgba(245, 158, 11, 0.2); color: #f59e0b; padding: 0.2rem 0.6rem; border-radius: 999px; font-size: 0.75rem; font-weight: 700;">MENUNGGU TTD</span>`;
        return `<span style="background: rgba(148, 163, 184, 0.2); color: #94a3b8; padding: 0.2rem 0.6rem; border-radius: 999px; font-size: 0.75rem; font-weight: 700;">DRAFT</span>`;
    };

    tbody.innerHTML = contracts.map(c => {
        const empName = c.employee?.name || c.internship?.intern_name || '-';
        return `
            <tr>
                <td style="font-weight: 700; color: #38bdf8;">${escapeHtml(c.contract_number)}</td>
                <td style="font-weight: 600; color: #fff;">${escapeHtml(empName)}</td>
                <td>
                    <div style="font-weight: 600; color: #fff;">${escapeHtml(c.title)}</div>
                    <div style="font-size: 0.75rem; color: #38bdf8;">${escapeHtml(c.contract_type)}</div>
                </td>
                <td>
                    <div>Mulai: ${escapeHtml(c.start_date)}</div>
                    <div style="font-size: 0.75rem; color: var(--text-muted);">${c.end_date ? `Selesai: ${c.end_date}` : 'Tanpa Batas Waktu'}</div>
                </td>
                <td>
                    <div>${escapeHtml(c.signed_by_employee || '-')}</div>
                    <div style="font-size: 0.75rem; color: var(--text-muted);">${escapeHtml(c.signed_by_company || '-')}</div>
                </td>
                <td>${statusBadge(c.status)}</td>
                <td><span style="font-size: 0.8rem; font-weight: 600; color: #fbbf24;">${escapeHtml(c.renewal_status)}</span></td>
                <td style="text-align: right;">
                    <span style="font-size: 0.75rem; color: var(--text-muted);">Tersimpan</span>
                </td>
            </tr>
        `;
    }).join('');
}

function openAddContractModal() {
    const select = document.getElementById('contractEmployeeSelect');
    if (select && state.employees) {
        select.innerHTML = '<option value="">Pilih Karyawan</option>' +
            state.employees.map(e => `<option value="${e.id}">${escapeHtml(e.name)} (${escapeHtml(e.employee_id)})</option>`).join('');
    }

    const today = new Date().toISOString().split('T')[0];
    const sEl = document.getElementById('contractStartDate');
    if (sEl) sEl.value = today;

    const modal = document.getElementById('modalAddContract');
    if (modal) modal.classList.add('active');
}

async function saveContract(e) {
    e.preventDefault();
    const payload = {
        employee_id: parseInt(document.getElementById('contractEmployeeSelect')?.value) || null,
        contract_type: document.getElementById('contractTypeSelect')?.value || 'FIXED_TERM',
        contract_number: document.getElementById('contractNumber')?.value.trim() || null,
        title: document.getElementById('contractTitle')?.value.trim(),
        start_date: document.getElementById('contractStartDate')?.value,
        end_date: document.getElementById('contractEndDate')?.value || null,
        signed_by_employee: document.getElementById('contractSigneeEmployee')?.value.trim() || null,
        signed_by_company: document.getElementById('contractSigneeCompany')?.value.trim() || 'Direktur HR PKP',
        status: document.getElementById('contractStatusSelect')?.value || 'ACTIVE',
    };

    try {
        const res = await apiFetch('/onboarding/contracts', {
            method: 'POST',
            body: JSON.stringify(payload)
        });

        if (res.success) {
            showToast('Kontrak kerja resmi berhasil diterbitkan!', 'success');
            closeModal('modalAddContract');
            document.getElementById('formAddContract')?.reset();
            await Promise.all([loadOnboardingContracts(), loadOnboardingMetrics()]);
        }
    } catch (err) {
        showToast(`Gagal membuat kontrak: ${err.message}`, 'error');
    }
}

// ==========================================
// Documents Controller (Private & Secure)
// ==========================================
async function loadOnboardingDocuments() {
    const tbody = document.getElementById('documentsTableBody');
    if (!tbody) return;

    const search = document.getElementById('onbDocSearch')?.value.trim() || '';
    const category = document.getElementById('onbDocCategoryFilter')?.value || '';

    let url = `/onboarding/documents?search=${encodeURIComponent(search)}`;
    if (category) url += `&category=${encodeURIComponent(category)}`;

    try {
        const res = await apiFetch(url);
        if (res.success && res.data) {
            state.onboarding.documents = res.data;
            renderDocumentsTable(res.data);
        }
    } catch (err) {
        tbody.innerHTML = `<tr><td colspan="8" style="text-align: center; color: #f87171; padding: 2rem;">Gagal memuat berkas dokumen: ${escapeHtml(err.message)}</td></tr>`;
    }
}

function renderDocumentsTable(docs) {
    const tbody = document.getElementById('documentsTableBody');
    if (!tbody) return;

    if (!docs || docs.length === 0) {
        tbody.innerHTML = `<tr><td colspan="8" style="text-align: center; color: var(--text-muted); padding: 2rem;">Belum ada dokumen privat terunggah.</td></tr>`;
        return;
    }

    const statusBadge = (s) => {
        if (s === 'VERIFIED') return `<span style="background: rgba(16, 185, 129, 0.2); color: #10b981; padding: 0.2rem 0.6rem; border-radius: 999px; font-size: 0.75rem; font-weight: 700;">✓ TERVERIFIKASI</span>`;
        if (s === 'REJECTED') return `<span style="background: rgba(239, 68, 68, 0.2); color: #ef4444; padding: 0.2rem 0.6rem; border-radius: 999px; font-size: 0.75rem; font-weight: 700;">✕ DITOLAK</span>`;
        if (s === 'ARCHIVED') return `<span style="background: rgba(148, 163, 184, 0.2); color: #94a3b8; padding: 0.2rem 0.6rem; border-radius: 999px; font-size: 0.75rem; font-weight: 700;">ARSIP (LAMA)</span>`;
        return `<span style="background: rgba(245, 158, 11, 0.2); color: #f59e0b; padding: 0.2rem 0.6rem; border-radius: 999px; font-size: 0.75rem; font-weight: 700;">MENUNGGU VERIFIKASI</span>`;
    };

    const sizeStr = (bytes) => {
        if (!bytes) return '0 KB';
        return `${Math.round(bytes / 1024)} KB`;
    };

    tbody.innerHTML = docs.map(d => {
        const empName = d.employee?.name || d.internship?.intern_name || '-';
        const isHR = (window.APP_CONFIG?.admin?.role === 'super_admin' || window.APP_CONFIG?.admin?.role === 'hrd');

        return `
            <tr>
                <td style="font-weight: 700; color: #38bdf8;">${escapeHtml(d.document_number)}</td>
                <td style="font-weight: 600; color: #fff;">${escapeHtml(empName)}</td>
                <td>
                    <div style="font-weight: 600; color: #fff;">${escapeHtml(d.title)}</div>
                    <div style="font-size: 0.75rem; color: #38bdf8;">${escapeHtml(d.category)} - ${escapeHtml(d.file_name)}</div>
                </td>
                <td>
                    <div>v${d.version}</div>
                    <div style="font-size: 0.75rem; color: var(--text-muted);">${sizeStr(d.file_size)}</div>
                </td>
                <td><span style="font-size: 0.75rem; color: var(--text-muted);">${escapeHtml(d.visibility)}</span></td>
                <td>${statusBadge(d.status)}</td>
                <td>${d.created_at ? d.created_at.split('T')[0] : '-'}</td>
                <td style="text-align: right; white-space: nowrap;">
                    <button class="btn-secondary" style="padding: 0.35rem 0.65rem; font-size: 0.75rem;" onclick="downloadSecureDocument(${d.id}, '${escapeHtml(d.file_name)}')">
                        ⬇️ Unduh
                    </button>
                    ${isHR && d.status === 'PENDING_VERIFICATION' ? `
                        <button class="btn-primary" style="padding: 0.35rem 0.65rem; font-size: 0.75rem; margin-left: 0.35rem;" onclick="openVerifyDocumentModal(${d.id}, '${escapeHtml(d.title)}')">
                            🛡️ Verifikasi
                        </button>
                    ` : ''}
                </td>
            </tr>
        `;
    }).join('');
}

function openUploadDocumentModal() {
    const select = document.getElementById('docEmployeeSelect');
    if (select && state.employees) {
        select.innerHTML = '<option value="">Pilih Karyawan</option>' +
            state.employees.map(e => `<option value="${e.id}">${escapeHtml(e.name)} (${escapeHtml(e.employee_id)})</option>`).join('');
    }

    const modal = document.getElementById('modalUploadDocument');
    if (modal) modal.classList.add('active');
}

async function submitUploadDocument(e) {
    e.preventDefault();
    const fileInput = document.getElementById('docFileInput');
    if (!fileInput || !fileInput.files || fileInput.files.length === 0) {
        showToast('Silakan pilih berkas dokumen untuk diunggah.', 'warning');
        return;
    }

    const formData = new FormData();
    formData.append('file', fileInput.files[0]);
    formData.append('employee_id', document.getElementById('docEmployeeSelect')?.value || '');
    formData.append('category', document.getElementById('docCategorySelect')?.value || 'OTHER');
    formData.append('visibility', document.getElementById('docVisibilitySelect')?.value || 'CONFIDENTIAL_HR');
    formData.append('title', document.getElementById('docTitle')?.value.trim() || fileInput.files[0].name);

    try {
        const csrfToken = document.querySelector('meta[name="csrf-token"]')?.getAttribute('content') || '';
        const appToken = window.APP_CONFIG?.apiToken || sessionStorage.getItem('api_token') || '';

        const headers = {
            'Accept': 'application/json',
            'X-CSRF-TOKEN': csrfToken,
        };
        if (appToken) headers['Authorization'] = `Bearer ${appToken}`;

        const res = await fetch('/api/v1/onboarding/documents', {
            method: 'POST',
            headers: headers,
            body: formData
        });

        const data = await res.json();
        if (res.ok && data.success) {
            showToast('Dokumen berhasil diunggah ke private storage!', 'success');
            closeModal('modalUploadDocument');
            document.getElementById('formUploadDocument')?.reset();
            await Promise.all([loadOnboardingDocuments(), loadOnboardingMetrics()]);
        } else {
            showToast(`Gagal mengunggah: ${data.message || 'Error validasi'}`, 'error');
        }
    } catch (err) {
        showToast(`Gagal mengunggah berkas: ${err.message}`, 'error');
    }
}

function openVerifyDocumentModal(docId, title) {
    document.getElementById('verifyDocId').value = docId;
    const titleEl = document.getElementById('verifyDocTitle');
    if (titleEl) titleEl.textContent = title;

    const modal = document.getElementById('modalVerifyDocument');
    if (modal) modal.classList.add('active');
}

async function submitVerifyDocument(e) {
    e.preventDefault();
    const docId = document.getElementById('verifyDocId')?.value;
    const status = document.getElementById('verifyDocDecision')?.value;
    const notes = document.getElementById('verifyDocNotes')?.value.trim();

    try {
        const res = await apiFetch(`/onboarding/documents/${docId}/verify`, {
            method: 'PUT',
            body: JSON.stringify({
                status: status,
                notes: notes || null
            })
        });

        if (res.success) {
            showToast(`Status dokumen berhasil diubah menjadi ${status}!`, 'success');
            closeModal('modalVerifyDocument');
            await Promise.all([loadOnboardingDocuments(), loadOnboardingMetrics()]);
        }
    } catch (err) {
        showToast(`Gagal memverifikasi dokumen: ${err.message}`, 'error');
    }
}

async function downloadSecureDocument(docId, fileName) {
    try {
        const appToken = window.APP_CONFIG?.apiToken || sessionStorage.getItem('api_token') || '';
        const headers = {};
        if (appToken) headers['Authorization'] = `Bearer ${appToken}`;

        const res = await fetch(`/api/v1/onboarding/documents/${docId}/download`, {
            method: 'GET',
            headers: headers
        });

        if (!res.ok) {
            const errJson = await res.json().catch(() => ({}));
            showToast(`Gagal mengunduh: ${errJson.message || 'Akses ditolak'}`, 'error');
            return;
        }

        const blob = await res.blob();
        const url = window.URL.createObjectURL(blob);
        const a = document.createElement('a');
        a.href = url;
        a.download = fileName || `document_${docId}.pdf`;
        document.body.appendChild(a);
        a.click();
        a.remove();
        window.URL.revokeObjectURL(url);
        showToast('Berkas dokumen berhasil diunduh.', 'info');
    } catch (err) {
        showToast(`Kesalahan jaringan: ${err.message}`, 'error');
    }
}

// ==========================================
// Expiring Contracts Warning Controller
// ==========================================
async function loadExpiringContracts() {
    const tbody = document.getElementById('expiringContractsTableBody');
    if (!tbody) return;

    try {
        const res = await apiFetch('/onboarding/contracts?expiring=true&days=30');
        if (res.success && res.data) {
            state.onboarding.expiringContracts = res.data;
            renderExpiringContractsTable(res.data);
        }
    } catch (err) {
        tbody.innerHTML = `<tr><td colspan="7" style="text-align: center; color: #f87171; padding: 2rem;">Gagal memeriksa masa berlaku: ${escapeHtml(err.message)}</td></tr>`;
    }
}

function renderExpiringContractsTable(contracts) {
    const tbody = document.getElementById('expiringContractsTableBody');
    if (!tbody) return;

    if (!contracts || contracts.length === 0) {
        tbody.innerHTML = `<tr><td colspan="7" style="text-align: center; color: #10b981; padding: 2rem;">✓ Aman: Tidak ada kontrak kerja yang akan berakhir dalam 30 hari ke depan.</td></tr>`;
        return;
    }

    tbody.innerHTML = contracts.map(c => {
        const empName = c.employee?.name || c.internship?.intern_name || '-';
        const diffDays = Math.ceil((new Date(c.end_date) - new Date()) / (1000 * 60 * 60 * 24));

        return `
            <tr>
                <td style="font-weight: 700; color: #f59e0b;">${escapeHtml(c.contract_number)}</td>
                <td style="font-weight: 600; color: #fff;">${escapeHtml(empName)}</td>
                <td>${escapeHtml(c.contract_type)}</td>
                <td>${escapeHtml(c.end_date)}</td>
                <td><span style="color: #ef4444; font-weight: 700;">${diffDays} Hari Lagi</span></td>
                <td><span style="color: #fbbf24; font-weight: 600;">${escapeHtml(c.renewal_status)}</span></td>
                <td style="text-align: right;">
                    <button class="btn-primary" style="padding: 0.35rem 0.65rem; font-size: 0.75rem;" onclick="showToast('Silakan perpanjang kontrak melalui pembuatan addendum baru.', 'info')">
                        Perpanjang / Evaluasi
                    </button>
                </td>
            </tr>
        `;
    }).join('');
}

function debounceOnboardingSearch() {
    clearTimeout(state.searchDebounceTimer);
    state.searchDebounceTimer = setTimeout(loadOnboardingCases, 350);
}

function debounceContractSearch() {
    clearTimeout(state.searchDebounceTimer);
    state.searchDebounceTimer = setTimeout(loadOnboardingContracts, 350);
}

function debounceDocSearch() {
    clearTimeout(state.searchDebounceTimer);
    state.searchDebounceTimer = setTimeout(loadOnboardingDocuments, 350);
}

// =============================================================
// SPRINT 6: ACCESS PROVISIONING, CREDENTIALS & E-MONEY CONTROLLER
// =============================================================

function loadAccessData() {
    loadAccessMetrics();
    loadAccessMatrixData();
    loadAccessRequests();
    loadAccessProfiles();
    loadCredentials();
    loadDeviceSyncs();
    loadEmoneyCards();
    populateAccessEmployees();
}

function switchAccessSubTab(subTab, btn) {
    document.querySelectorAll('#accessTab .ats-subnav .subnav-btn').forEach(b => b.classList.remove('active'));
    if (btn) btn.classList.add('active');

    const subs = {
        'matrix': 'accessSubMatrix',
        'requests': 'accessSubRequests',
        'profiles': 'accessSubProfiles',
        'credentials': 'accessSubCredentials',
        'syncs': 'accessSubSyncs',
        'emoney': 'accessSubEmoney'
    };

    Object.values(subs).forEach(id => {
        const el = document.getElementById(id);
        if (el) el.style.display = 'none';
    });

    const activeEl = document.getElementById(subs[subTab]);
    if (activeEl) activeEl.style.display = 'block';

    if (subTab === 'matrix') {
        loadAccessMatrixData();
    }
}

// State for Bulk Access Matrix
let accessMatrixState = {
    employees: [],
    doors: [],
    initialState: {}, // key `${empId}_${doorId}` => boolean
    stagedState: {},   // key `${empId}_${doorId}` => boolean
    masterChecked: false
};

async function loadAccessMatrixData() {
    const tbody = document.getElementById('accessMatrixTableBody');
    const thead = document.getElementById('accessMatrixTableHead');
    if (!tbody || !thead) return;

    try {
        const [empRes, doorRes] = await Promise.all([
            apiFetch('/user-management/users'),
            apiFetch('/admin/doors')
        ]);

        let employees = [];
        if (empRes && empRes.data) {
            employees = Array.isArray(empRes.data) ? empRes.data : (empRes.data.data || []);
        } else if (empRes && Array.isArray(empRes)) {
            employees = empRes;
        }

        let doors = [];
        if (doorRes && doorRes.data) {
            doors = Array.isArray(doorRes.data) ? doorRes.data : (doorRes.data.data || []);
        } else if (doorRes && Array.isArray(doorRes)) {
            doors = doorRes;
        }

        accessMatrixState.employees = employees;
        accessMatrixState.doors = doors;

        // Populate department filter options
        const deptSelect = document.getElementById('accessMatrixDeptFilter');
        if (deptSelect) {
            const depts = [...new Set(employees.map(e => e.department).filter(Boolean))];
            const currentVal = deptSelect.value;
            deptSelect.innerHTML = `<option value="">Semua Departemen</option>` +
                depts.map(d => `<option value="${escapeHtml(d)}" ${currentVal === d ? 'selected' : ''}>${escapeHtml(d)}</option>`).join('');
        }

        // Build initial access map
        accessMatrixState.initialState = {};
        accessMatrixState.stagedState = {};

        employees.forEach(emp => {
            const assignedDoorIds = new Set();
            if (emp.door_assignments && Array.isArray(emp.door_assignments)) {
                emp.door_assignments.forEach(da => {
                    const doorObj = da.door || da;
                    if (doorObj.door_id) assignedDoorIds.add(doorObj.door_id);
                    if (doorObj.id) assignedDoorIds.add(String(doorObj.id));
                });
            }
            if (emp.assigned_doors && Array.isArray(emp.assigned_doors)) {
                emp.assigned_doors.forEach(d => assignedDoorIds.add(String(d)));
            }

            doors.forEach(door => {
                const key = `${emp.employee_id || emp.id}_${door.door_id || door.id}`;
                const hasAccess = assignedDoorIds.has(door.door_id) || assignedDoorIds.has(String(door.id));
                accessMatrixState.initialState[key] = hasAccess;
                accessMatrixState.stagedState[key] = hasAccess;
            });
        });

        renderAccessMatrixTable();
    } catch (e) {
        console.error('Gagal memuat Matriks Hak Akses:', e);
        if (tbody) {
            tbody.innerHTML = `<tr><td colspan="5" style="text-align: center; color: #ef4444; padding: 2rem;">Gagal memuat Matriks Hak Akses: ${escapeHtml(e.message)}</td></tr>`;
        }
    }
}

function renderAccessMatrixTable() {
    const thead = document.getElementById('accessMatrixTableHead');
    const tbody = document.getElementById('accessMatrixTableBody');
    if (!thead || !tbody) return;

    const search = (document.getElementById('accessMatrixSearch')?.value || '').toLowerCase();
    const deptFilter = document.getElementById('accessMatrixDeptFilter')?.value || '';

    const filteredEmployees = accessMatrixState.employees.filter(emp => {
        const matchesSearch = !search ||
            (emp.name && emp.name.toLowerCase().includes(search)) ||
            (emp.employee_id && emp.employee_id.toLowerCase().includes(search)) ||
            (emp.nik && emp.nik.toLowerCase().includes(search));
        const matchesDept = !deptFilter || emp.department === deptFilter;
        return matchesSearch && matchesDept;
    });

    const doors = accessMatrixState.doors;

    // Render table header with column checkboxes
    thead.innerHTML = `
        <tr style="border-bottom: 1px solid var(--border-color); text-align: left;">
            <th style="padding: 0.75rem 1rem; min-width: 220px; font-weight: 700; color: #fff;">
                Karyawan / NIK
            </th>
            <th style="padding: 0.75rem 1rem; min-width: 140px; font-weight: 700; color: var(--text-muted);">
                Departemen
            </th>
            ${doors.map(door => {
                const doorCode = door.door_id || door.id;
                const doorName = door.door_name || door.name || doorCode;
                const colAllChecked = filteredEmployees.length > 0 && filteredEmployees.every(emp => {
                    const key = `${emp.employee_id || emp.id}_${doorCode}`;
                    return !!accessMatrixState.stagedState[key];
                });

                return `
                    <th style="padding: 0.75rem 1rem; text-align: center; min-width: 150px; background: rgba(255,255,255,0.03);">
                        <div style="font-size: 0.85rem; font-weight: 700; color: #38bdf8;">${escapeHtml(doorCode)}</div>
                        <div style="font-size: 0.725rem; color: var(--text-muted); font-weight: 400; margin-bottom: 0.35rem;">${escapeHtml(doorName)}</div>
                        <label style="font-size: 0.7rem; color: var(--text-muted); cursor: pointer; display: inline-flex; align-items: center; gap: 0.25rem;">
                            <input type="checkbox" ${colAllChecked ? 'checked' : ''} onchange="toggleColumnMatrixCheckboxes('${escapeHtml(doorCode)}', this.checked)">
                            Select Col
                        </label>
                    </th>
                `;
            }).join('')}
            <th style="padding: 0.75rem 1rem; text-align: right; font-weight: 700;">Aksi Single</th>
        </tr>
    `;

    if (!filteredEmployees.length) {
        tbody.innerHTML = `<tr><td colspan="${doors.length + 3}" style="text-align: center; color: var(--text-muted); padding: 2rem;">Tidak ada karyawan yang sesuai filter.</td></tr>`;
        updateMatrixPendingBadge();
        return;
    }

    // Render tbody rows
    tbody.innerHTML = filteredEmployees.map(emp => {
        const empId = emp.employee_id || emp.id;
        const rowAllChecked = doors.length > 0 && doors.every(door => {
            const doorCode = door.door_id || door.id;
            const key = `${empId}_${doorCode}`;
            return !!accessMatrixState.stagedState[key];
        });

        return `
            <tr style="border-bottom: 1px solid rgba(255,255,255,0.05); transition: background 0.15s ease;" onmouseover="this.style.background='rgba(255,255,255,0.02)'" onmouseout="this.style.background='transparent'">
                <td style="padding: 0.75rem 1rem;">
                    <div style="font-weight: 700; color: #fff;">${escapeHtml(emp.name)}</div>
                    <div style="font-size: 0.775rem; color: #38bdf8; display: flex; align-items: center; gap: 0.5rem;">
                        <span>${escapeHtml(emp.employee_id || emp.id)}</span>
                        <label style="font-size: 0.68rem; color: var(--text-muted); cursor: pointer; display: inline-flex; align-items: center; gap: 0.2rem;">
                            <input type="checkbox" ${rowAllChecked ? 'checked' : ''} onchange="toggleRowMatrixCheckboxes('${escapeHtml(empId)}', this.checked)">
                            Row
                        </label>
                    </div>
                </td>
                <td style="padding: 0.75rem 1rem; font-size: 0.85rem; color: var(--text-muted);">
                    ${escapeHtml(emp.department || '-')}
                </td>
                ${doors.map(door => {
                    const doorCode = door.door_id || door.id;
                    const key = `${empId}_${doorCode}`;
                    const isChecked = !!accessMatrixState.stagedState[key];
                    const wasInitial = !!accessMatrixState.initialState[key];
                    const isChanged = isChecked !== wasInitial;

                    let statusBadge = '';
                    if (isChanged) {
                        statusBadge = isChecked
                            ? '<span style="font-size:0.65rem; color:#10b981; font-weight:700;">+GRANT</span>'
                            : '<span style="font-size:0.65rem; color:#ef4444; font-weight:700;">-REVOKE</span>';
                    } else if (isChecked) {
                        statusBadge = '<span style="font-size:0.65rem; color:rgba(16,185,129,0.7);">Active</span>';
                    } else {
                        statusBadge = '<span style="font-size:0.65rem; color:var(--text-muted);">None</span>';
                    }

                    const bgHighlight = isChanged
                        ? (isChecked ? 'rgba(16,185,129,0.12)' : 'rgba(239,68,68,0.12)')
                        : 'transparent';

                    return `
                        <td style="padding: 0.65rem 0.5rem; text-align: center; background: ${bgHighlight}; transition: background 0.2s ease;">
                            <label style="cursor: pointer; display: inline-flex; flex-direction: column; align-items: center; gap: 0.2rem;">
                                <input type="checkbox" style="width: 1.15rem; height: 1.15rem; cursor: pointer; accent-color: #10b981;" ${isChecked ? 'checked' : ''} onchange="onMatrixCellToggle('${escapeHtml(empId)}', '${escapeHtml(doorCode)}', this.checked)">
                                ${statusBadge}
                            </label>
                        </td>
                    `;
                }).join('')}
                <td style="padding: 0.75rem 1rem; text-align: right; white-space: nowrap;">
                    <button type="button" class="btn-secondary" onclick="openDoorAssignModal('${escapeHtml(emp.id)}')" style="padding: 0.35rem 0.65rem; font-size: 0.75rem;">
                        ⚙️ Edit Hak Akses
                    </button>
                </td>
            </tr>
        `;
    }).join('');

    updateMatrixPendingBadge();
}

function onMatrixCellToggle(empId, doorCode, isChecked) {
    const key = `${empId}_${doorCode}`;
    accessMatrixState.stagedState[key] = isChecked;
    renderAccessMatrixTable();
}

function toggleRowMatrixCheckboxes(empId, isChecked) {
    accessMatrixState.doors.forEach(door => {
        const doorCode = door.door_id || door.id;
        const key = `${empId}_${doorCode}`;
        accessMatrixState.stagedState[key] = isChecked;
    });
    renderAccessMatrixTable();
}

function toggleColumnMatrixCheckboxes(doorCode, isChecked) {
    accessMatrixState.employees.forEach(emp => {
        const empId = emp.employee_id || emp.id;
        const key = `${empId}_${doorCode}`;
        accessMatrixState.stagedState[key] = isChecked;
    });
    renderAccessMatrixTable();
}

function toggleMasterMatrixCheckboxes() {
    accessMatrixState.masterChecked = !accessMatrixState.masterChecked;
    const targetState = accessMatrixState.masterChecked;

    accessMatrixState.employees.forEach(emp => {
        const empId = emp.employee_id || emp.id;
        accessMatrixState.doors.forEach(door => {
            const doorCode = door.door_id || door.id;
            const key = `${empId}_${doorCode}`;
            accessMatrixState.stagedState[key] = targetState;
        });
    });

    renderAccessMatrixTable();
    showToast(targetState ? 'Semua sel matriks dicentang (Grant All)' : 'Semua sel matriks dikosongkan (Revoke All)', 'info');
}

function debounceAccessMatrixSearch() {
    clearTimeout(state.searchDebounceTimer);
    state.searchDebounceTimer = setTimeout(renderAccessMatrixTable, 300);
}

function getMatrixDiff() {
    const diff = [];
    Object.keys(accessMatrixState.stagedState).forEach(key => {
        const staged = !!accessMatrixState.stagedState[key];
        const initial = !!accessMatrixState.initialState[key];
        if (staged !== initial) {
            const [empId, doorCode] = key.split('_');
            const emp = accessMatrixState.employees.find(e => (e.employee_id || e.id) === empId || String(e.id) === empId);
            const door = accessMatrixState.doors.find(d => (d.door_id || d.id) === doorCode || String(d.id) === doorCode);

            diff.push({
                employee_id: empId,
                employee_name: emp ? emp.name : empId,
                door_id: doorCode,
                door_name: door ? (door.door_name || door.name || doorCode) : doorCode,
                action: staged ? 'grant' : 'revoke'
            });
        }
    });
    return diff;
}

function updateMatrixPendingBadge() {
    const diff = getMatrixDiff();
    const badge = document.getElementById('matrixPendingChangesBadge');
    if (badge) {
        if (diff.length > 0) {
            badge.style.display = 'inline-block';
            badge.innerText = `${diff.length} perubahan pending`;
        } else {
            badge.style.display = 'none';
        }
    }
}

function openBulkAccessConfirmModal() {
    const diff = getMatrixDiff();
    if (diff.length === 0) {
        showToast('Tidak ada perubahan matriks hak akses yang belum disimpan.', 'warning');
        return;
    }

    const grantCount = diff.filter(d => d.action === 'grant').length;
    const revokeCount = diff.filter(d => d.action === 'revoke').length;

    const elGrant = document.getElementById('confirmGrantCount');
    const elRevoke = document.getElementById('confirmRevokeCount');
    const tbody = document.getElementById('bulkAccessConfirmTableBody');

    if (elGrant) elGrant.innerText = grantCount;
    if (elRevoke) elRevoke.innerText = revokeCount;

    if (tbody) {
        tbody.innerHTML = diff.map(item => `
            <tr style="border-bottom: 1px solid rgba(255,255,255,0.05);">
                <td style="padding: 0.5rem; font-weight: 600; color: #fff;">${escapeHtml(item.employee_name)} <span style="font-size:0.75rem; color:#38bdf8;">(${escapeHtml(item.employee_id)})</span></td>
                <td style="padding: 0.5rem; color: var(--text-main);">${escapeHtml(item.door_name)} <span style="font-size:0.75rem; color:var(--text-muted);">(${escapeHtml(item.door_id)})</span></td>
                <td style="padding: 0.5rem; white-space: nowrap;">
                    ${item.action === 'grant'
                        ? '<span class="badge" style="background: rgba(16,185,129,0.2); color: #10b981;">+ GRANT (Akses Baru)</span>'
                        : '<span class="badge" style="background: rgba(239,68,68,0.2); color: #ef4444;">- REVOKE (Cabut)</span>'}
                </td>
            </tr>
        `).join('');
    }

    openModal('bulkAccessConfirmModal');
}

async function executeBulkAccessMatrixSubmit() {
    const diff = getMatrixDiff();
    if (diff.length === 0) {
        closeModal('bulkAccessConfirmModal');
        return;
    }

    const btn = document.getElementById('btnConfirmExecuteBulkAccess');
    if (btn) {
        btn.disabled = true;
        btn.innerHTML = '<div class="spinner"></div> Memproses Matriks...';
    }

    try {
        const payload = {
            changes: diff.map(d => ({
                employee_id: d.employee_id,
                door_id: d.door_id,
                action: d.action
            }))
        };

        const res = await apiFetch('/user-management/bulk-access', {
            method: 'POST',
            body: JSON.stringify(payload)
        });

        if (res && (res.status === 'success' || res.success)) {
            showToast(res.message || `Berhasil memproses ${diff.length} perubahan hak akses.`, 'success');
            closeModal('bulkAccessConfirmModal');
            await loadAccessMatrixData();
            loadAccessMetrics();
        } else {
            showToast(res.message || 'Gagal memproses pembaruan matriks massal.', 'error');
        }
    } catch (e) {
        console.error('Error executing bulk access matrix submit:', e);
        showToast(`Gagal: ${e.message}`, 'error');
    } finally {
        if (btn) {
            btn.disabled = false;
            btn.innerHTML = '✓ Konfirmasi & Eksekusi Matrix';
        }
    }
}

// Single Employee Door Assignment Modal Functions
async function openDoorAssignModal(empId) {
    try {
        const empRes = await apiFetch(`/user-management/users/${empId}`);
        const employee = empRes && (empRes.data || empRes);
        if (!employee) throw new Error('Karyawan tidak ditemukan');

        const doorRes = await apiFetch('/admin/doors');
        let doors = [];
        if (doorRes && doorRes.data) {
            doors = Array.isArray(doorRes.data) ? doorRes.data : (doorRes.data.data || []);
        } else if (doorRes && Array.isArray(doorRes)) {
            doors = doorRes;
        }

        const modalEmpId = document.getElementById('assignModalEmpId');
        const modalEmpName = document.getElementById('assignModalEmpName');
        const modalEmpDept = document.getElementById('assignModalEmpDept');
        const container = document.getElementById('doorCheckboxesContainer');

        if (modalEmpId) modalEmpId.value = employee.id || employee.employee_id;
        if (modalEmpName) modalEmpName.innerText = `${employee.name} (${employee.employee_id || employee.id})`;
        if (modalEmpDept) modalEmpDept.innerText = `${employee.department || 'General'} • ${employee.role || 'Staff'}`;

        const assignedDoorIds = new Set();
        if (employee.door_assignments && Array.isArray(employee.door_assignments)) {
            employee.door_assignments.forEach(da => {
                const doorObj = da.door || da;
                if (doorObj.door_id) assignedDoorIds.add(doorObj.door_id);
                if (doorObj.id) assignedDoorIds.add(String(doorObj.id));
            });
        }

        if (container) {
            container.innerHTML = doors.map(door => {
                const doorCode = door.door_id || door.id;
                const doorName = door.door_name || door.name || doorCode;
                const isChecked = assignedDoorIds.has(doorCode) || assignedDoorIds.has(String(door.id));

                return `
                    <label style="display: flex; align-items: center; gap: 0.65rem; background: var(--card-bg); border: 1px solid var(--border-color); border-radius: 0.6rem; padding: 0.75rem 1rem; cursor: pointer;">
                        <input type="checkbox" name="assigned_doors[]" value="${escapeHtml(doorCode)}" ${isChecked ? 'checked' : ''} style="width: 1.1rem; height: 1.1rem; accent-color: #10b981;">
                        <div>
                            <div style="font-weight: 700; font-size: 0.9rem; color: #fff;">${escapeHtml(doorCode)}</div>
                            <div style="font-size: 0.775rem; color: var(--text-muted);">${escapeHtml(doorName)}</div>
                        </div>
                    </label>
                `;
            }).join('');
        }

        openModal('doorAssignModal');
    } catch (e) {
        showToast(`Gagal memuat data akses karyawan: ${e.message}`, 'error');
    }
}

async function submitDoorAssignment(event) {
    event.preventDefault();
    const empId = document.getElementById('assignModalEmpId')?.value;
    if (!empId) return;

    const checkedInputs = document.querySelectorAll('#doorCheckboxesContainer input[name="assigned_doors[]"]:checked');
    const doorIds = Array.from(checkedInputs).map(cb => cb.value);

    const btn = document.getElementById('btnSaveDoorAssignment');
    if (btn) {
        btn.disabled = true;
        btn.innerHTML = '<div class="spinner"></div> Menyimpan...';
    }

    try {
        const res = await apiFetch('/user-management/assign-doors', {
            method: 'POST',
            body: JSON.stringify({
                employee_id: empId,
                door_ids: doorIds
            })
        });

        if (res && (res.status === 'success' || res.success)) {
            showToast(res.message || 'Hak akses pintu berhasil diperbarui.', 'success');
            closeModal('doorAssignModal');
            await loadAccessMatrixData();
            loadAccessMetrics();
        } else {
            showToast(res.message || 'Gagal menyimpan hak akses pintu.', 'error');
        }
    } catch (e) {
        showToast(`Gagal: ${e.message}`, 'error');
    } finally {
        if (btn) {
            btn.disabled = false;
            btn.innerHTML = '💾 Simpan Hak Akses';
        }
    }
}

async function revokeAllAccessForEmployeeModal() {
    const empId = document.getElementById('assignModalEmpId')?.value;
    if (!empId) return;

    if (!confirm('Apakah Anda yakin ingin mencabut seluruh hak akses pintu karyawan ini?')) return;

    try {
        const res = await apiFetch('/user-management/revoke-doors', {
            method: 'POST',
            body: JSON.stringify({
                employee_id: empId
            })
        });

        if (res && (res.status === 'success' || res.success)) {
            showToast(res.message || 'Seluruh hak akses pintu berhasil dicabut.', 'success');
            closeModal('doorAssignModal');
            await loadAccessMatrixData();
            loadAccessMetrics();
        } else {
            showToast(res.message || 'Gagal mencabut hak akses pintu.', 'error');
        }
    } catch (e) {
        showToast(`Gagal: ${e.message}`, 'error');
    }
}

// Card Enrollment Modal Functions
async function openCardEnrollModal(empId) {
    try {
        const empRes = await apiFetch(`/user-management/users/${empId}`);
        const employee = empRes && (empRes.data || empRes);
        if (!employee) throw new Error('Karyawan tidak ditemukan');

        const elId = document.getElementById('enrollModalEmpId');
        const elName = document.getElementById('enrollModalEmpName');
        const elCard = document.getElementById('enrollCardNumberInput');
        const elNotes = document.getElementById('enrollNotesInput');

        if (elId) elId.value = employee.id || employee.employee_id;
        if (elName) elName.innerText = `${employee.name} (${employee.employee_id || employee.id})`;
        if (elCard) elCard.value = employee.card_no || '';
        if (elNotes) elNotes.value = '';

        openModal('cardEnrollModal');
    } catch (e) {
        showToast(`Gagal membuka dialog enroll kartu: ${e.message}`, 'error');
    }
}

async function submitCardEnrollment(event) {
    event.preventDefault();
    const empId = document.getElementById('enrollModalEmpId')?.value;
    const cardNumber = document.getElementById('enrollCardNumberInput')?.value?.trim();
    const notes = document.getElementById('enrollNotesInput')?.value?.trim();

    if (!empId || !cardNumber) {
        showToast('Nomor kartu wajib diisi.', 'warning');
        return;
    }

    const btn = document.getElementById('btnSubmitCardEnroll');
    if (btn) {
        btn.disabled = true;
        btn.innerHTML = '<div class="spinner"></div> Mengirim...';
    }

    try {
        const res = await apiFetch(`/user-management/employees/${empId}/enroll-card`, {
            method: 'POST',
            body: JSON.stringify({
                card_number: cardNumber,
                notes: notes
            })
        });

        if (res && (res.status === 'success' || res.success)) {
            showToast(res.message || `Kartu ${cardNumber} berhasil didaftarkan.`, 'success');
            closeModal('cardEnrollModal');
            if (typeof loadAccessMatrixData === 'function') await loadAccessMatrixData();
            if (typeof loadCredentials === 'function') loadCredentials();
            if (typeof loadEmployees === 'function') loadEmployees();
            if (typeof loadAccessMetrics === 'function') loadAccessMetrics();
        } else {
            showToast(res.message || 'Gagal mendaftarkan kartu.', 'error');
        }
    } catch (e) {
        showToast(`Gagal: ${e.message}`, 'error');
    } finally {
        if (btn) {
            btn.disabled = false;
            btn.innerHTML = '💳 Daftarkan Kartu (ISAPI Sync)';
        }
    }
}

// Lost/Block Card Modal Functions
async function openLostCardModal(empId, cardNo = '') {
    try {
        const empRes = await apiFetch(`/user-management/users/${empId}`);
        const employee = empRes && (empRes.data || empRes);
        if (!employee) throw new Error('Karyawan tidak ditemukan');

        const elId = document.getElementById('lostModalEmpId');
        const elName = document.getElementById('lostModalEmpName');
        const elCard = document.getElementById('lostModalCardNo');
        const elCardDisplay = document.getElementById('lostModalCardDisplay');
        const elReason = document.getElementById('lostCardReasonInput');

        const targetCard = cardNo || employee.card_no || 'Semua Kartu Terdaftar';

        if (elId) elId.value = employee.id || employee.employee_id;
        if (elName) elName.innerText = `${employee.name} (${employee.employee_id || employee.id}) • ${employee.department || '-'}`;
        if (elCard) elCard.value = cardNo || employee.card_no || '';
        if (elCardDisplay) elCardDisplay.innerText = targetCard;
        if (elReason) elReason.value = '';

        openModal('lostCardModal');
    } catch (e) {
        showToast(`Gagal membuka dialog blokir kartu: ${e.message}`, 'error');
    }
}

async function submitBlockLostCard(event) {
    event.preventDefault();
    const empId = document.getElementById('lostModalEmpId')?.value;
    const cardNo = document.getElementById('lostModalCardNo')?.value;
    const reason = document.getElementById('lostCardReasonInput')?.value?.trim();

    if (!empId || !reason) {
        showToast('Alasan pemblokiran kartu wajib diisi.', 'warning');
        return;
    }

    const btn = document.getElementById('btnSubmitBlockLostCard');
    if (btn) {
        btn.disabled = true;
        btn.innerHTML = '<div class="spinner"></div> Memblokir Kartu...';
    }

    try {
        const res = await apiFetch(`/user-management/employees/${empId}/block-lost-card`, {
            method: 'POST',
            body: JSON.stringify({
                card_number: cardNo,
                reason: reason
            })
        });

        if (res && (res.status === 'success' || res.success)) {
            showToast(res.message || 'Kartu berhasil diblokir dan seluruh akses pintu dicabut.', 'success');
            closeModal('lostCardModal');
            if (typeof loadAccessMatrixData === 'function') await loadAccessMatrixData();
            if (typeof loadCredentials === 'function') loadCredentials();
            if (typeof loadEmployees === 'function') loadEmployees();
            if (typeof loadAccessMetrics === 'function') loadAccessMetrics();
        } else {
            showToast(res.message || 'Gagal memblokir kartu.', 'error');
        }
    } catch (e) {
        showToast(`Gagal: ${e.message}`, 'error');
    } finally {
        if (btn) {
            btn.disabled = false;
            btn.innerHTML = '🚨 Konfirmasi Blokir Kartu & Revoke Akses';
        }
    }
}

async function loadAccessMetrics() {
    try {
        const res = await apiFetch('/api/v1/access/metrics');
        if (res && res.success) {
            const d = res.data;
            const elPending = document.getElementById('metricPendingAccessRequests');
            const elCred = document.getElementById('metricActiveCredentials');
            const elSync = document.getElementById('metricPendingDeviceSyncs');
            const elEmn = document.getElementById('metricTotalEmoneyCards');

            if (elPending) elPending.innerText = d.pending_requests ?? 0;
            if (elCred) elCred.innerText = d.active_credentials ?? 0;
            if (elSync) elSync.innerText = d.pending_syncs ?? 0;
            if (elEmn) elEmn.innerText = d.total_emoney ?? 0;
        }
    } catch (e) {
        console.error('Failed to load access metrics', e);
    }
}

async function loadAccessRequests() {
    const tbody = document.getElementById('accessRequestsTableBody');
    if (!tbody) return;

    try {
        const search = document.getElementById('accessRequestSearch')?.value || '';
        const status = document.getElementById('accessRequestStatusFilter')?.value || '';
        const params = new URLSearchParams();
        if (search) params.append('search', search);
        if (status) params.append('status', status);

        const res = await apiFetch(`/api/v1/access/requests?${params.toString()}`);
        if (!res || !res.success || !res.data.length) {
            tbody.innerHTML = `<tr><td colspan="7" style="text-align: center; color: var(--text-muted); padding: 2rem;">Belum ada permohonan hak akses yang diajukan.</td></tr>`;
            return;
        }

        tbody.innerHTML = res.data.map(r => {
            const empName = r.employee?.name || (r.internship ? `[Intern] ${r.internship.intern_id}` : '-');
            const profName = r.access_profile?.name || (r.specific_doors?.length ? `Khusus (${r.specific_doors.join(', ')})` : 'Standar');
            const statusBadge = getAccessStatusBadge(r.status);
            const validUntil = r.valid_until ? r.valid_until.substring(0, 10) : 'Permanen';
            const validRange = `${(r.valid_from || '').substring(0, 10)} s/d ${validUntil}`;

            let actions = '';
            if (r.status === 'PENDING_APPROVAL') {
                actions = `
                    <button class="btn-primary" disabled style="opacity: 0.6; cursor: not-allowed; padding: 0.35rem 0.65rem; font-size: 0.75rem; background: #10b981; border-color: #059669;" title="PLANNED — Device write approval belum diaktifkan (Read-Only UI)">🔒 Setujui <span class="badge badge-warning" style="font-size:0.6rem;">PLANNED</span></button>
                    <button class="btn-secondary" disabled style="opacity: 0.6; cursor: not-allowed; padding: 0.35rem 0.65rem; font-size: 0.75rem; color: #ef4444; border-color: #ef4444;" title="PLANNED — Device write rejection belum diaktifkan (Read-Only UI)">🔒 Tolak <span class="badge badge-warning" style="font-size:0.6rem;">PLANNED</span></button>
                `;
            } else {
                actions = `<span style="font-size: 0.75rem; color: var(--text-muted);">${escapeHtml(r.status)}</span>`;
            }

            return `
                <tr>
                    <td style="font-weight: 700; color: #38bdf8;">${escapeHtml(r.request_number)}</td>
                    <td style="font-weight: 600; color: #fff;">${escapeHtml(empName)}</td>
                    <td>${escapeHtml(profName)}</td>
                    <td>${escapeHtml(r.building_name || 'Kantor Pusat')}</td>
                    <td style="font-size: 0.8rem; color: var(--text-muted);">${escapeHtml(validRange)}</td>
                    <td>${statusBadge}</td>
                    <td style="text-align: right; white-space: nowrap;">${actions}</td>
                </tr>
            `;
        }).join('');
    } catch (e) {
        tbody.innerHTML = `<tr><td colspan="7" style="text-align: center; color: #ef4444; padding: 2rem;">Gagal memuat permohonan akses: ${escapeHtml(e.message)}</td></tr>`;
    }
}

async function loadAccessProfiles() {
    const tbody = document.getElementById('accessProfilesTableBody');
    if (!tbody) return;

    try {
        const search = document.getElementById('accessProfileSearch')?.value || '';
        const params = new URLSearchParams();
        if (search) params.append('search', search);

        const res = await apiFetch(`/api/v1/access/profiles?${params.toString()}`);
        if (!res || !res.success || !res.data.length) {
            tbody.innerHTML = `<tr><td colspan="6" style="text-align: center; color: var(--text-muted); padding: 2rem;">Belum ada profil hak akses yang terdaftar.</td></tr>`;
            return;
        }

        tbody.innerHTML = res.data.map(p => {
            const doors = (p.allowed_doors && p.allowed_doors.length) ? p.allowed_doors.join(', ') : 'Semua Pintu';
            const schedule = `${p.schedule_type} (${p.start_time} - ${p.end_time})`;
            const statusBadge = p.is_active ? '<span class="badge" style="background: rgba(16,185,129,0.2); color: #10b981;">AKTIF</span>' : '<span class="badge" style="background: rgba(239,68,68,0.2); color: #ef4444;">NONAKTIF</span>';

            return `
                <tr>
                    <td style="font-weight: 700; color: #fbbf24;">${escapeHtml(p.code)}</td>
                    <td style="font-weight: 600; color: #fff;">${escapeHtml(p.name)}</td>
                    <td>${escapeHtml(p.building_name || '-')}</td>
                    <td style="font-size: 0.8rem;">${escapeHtml(schedule)}</td>
                    <td style="font-size: 0.8rem; color: #38bdf8;">${escapeHtml(doors)}</td>
                    <td>${statusBadge}</td>
                </tr>
            `;
        }).join('');
    } catch (e) {
        tbody.innerHTML = `<tr><td colspan="6" style="text-align: center; color: #ef4444; padding: 2rem;">Gagal memuat profil: ${escapeHtml(e.message)}</td></tr>`;
    }
}

async function loadCredentials() {
    const tbody = document.getElementById('credentialsTableBody');
    if (!tbody) return;

    try {
        const search = document.getElementById('credentialSearch')?.value || '';
        const type = document.getElementById('credentialTypeFilter')?.value || '';
        const params = new URLSearchParams();
        if (search) params.append('search', search);
        if (type) params.append('credential_type', type);

        const res = await apiFetch(`/api/v1/access/credentials?${params.toString()}`);
        if (!res || !res.success || !res.data.length) {
            tbody.innerHTML = `<tr><td colspan="8" style="text-align: center; color: var(--text-muted); padding: 2rem;">Belum ada data kredensial terdaftar.</td></tr>`;
            return;
        }

        tbody.innerHTML = res.data.map(c => {
            const empName = c.employee?.name || (c.internship ? `[Intern] ${c.internship.intern_id}` : '-');
            const bioBadge = `<span class="badge" style="background: rgba(56,189,248,0.15); color: #38bdf8;">${escapeHtml(c.biometric_status || 'NOT_ENROLLED')}</span>`;
            const statusBadge = c.status === 'ACTIVE'
                ? '<span class="badge" style="background: rgba(16,185,129,0.2); color: #10b981;">ACTIVE</span>'
                : '<span class="badge" style="background: rgba(239,68,68,0.2); color: #ef4444;">REVOKED</span>';
            const issuedAt = c.issued_at ? c.issued_at.substring(0, 10) : '-';

            let actions = '';
            if (c.status === 'ACTIVE') {
                actions = `<button class="btn-secondary" disabled style="opacity: 0.6; cursor: not-allowed; padding: 0.35rem 0.65rem; font-size: 0.75rem; color: #ef4444; border-color: #ef4444;" title="PLANNED — Card/Biometric revocation write ke hardware belum diaktifkan (Read-Only UI)">🔒 Cabut <span class="badge badge-warning" style="font-size:0.6rem;">PLANNED</span></button>`;
            } else {
                actions = `<span style="font-size: 0.75rem; color: var(--text-muted);">${escapeHtml(c.revocation_reason || 'Dicabut')}</span>`;
            }

            return `
                <tr>
                    <td style="font-weight: 700; color: #38bdf8;">${escapeHtml(c.credential_number)}</td>
                    <td style="font-weight: 600; color: #fff;">${escapeHtml(empName)}</td>
                    <td>${escapeHtml(c.credential_type)}</td>
                    <td style="font-family: monospace; font-size: 0.85rem; color: #fbbf24;">${escapeHtml(c.masked_identifier)}</td>
                    <td>${bioBadge}</td>
                    <td>${statusBadge}</td>
                    <td style="font-size: 0.8rem; color: var(--text-muted);">${escapeHtml(issuedAt)}</td>
                    <td style="text-align: right;">${actions}</td>
                </tr>
            `;
        }).join('');
    } catch (e) {
        tbody.innerHTML = `<tr><td colspan="8" style="text-align: center; color: #ef4444; padding: 2rem;">Gagal memuat kredensial: ${escapeHtml(e.message)}</td></tr>`;
    }
}

async function loadDeviceSyncs() {
    const tbody = document.getElementById('deviceSyncsTableBody');
    if (!tbody) return;

    try {
        const status = document.getElementById('deviceSyncStatusFilter')?.value || '';
        const params = new URLSearchParams();
        if (status) params.append('status', status);

        const res = await apiFetch(`/api/v1/access/device-syncs?${params.toString()}`);
        if (!res || !res.success || !res.data.length) {
            tbody.innerHTML = `<tr><td colspan="8" style="text-align: center; color: var(--text-muted); padding: 2rem;">Antrean sinkronisasi perangkat kosong. Semua terminal dalam status tersinkron.</td></tr>`;
            return;
        }

        tbody.innerHTML = res.data.map(s => {
            const doorName = s.door ? `${s.door.door_id} - ${s.door.name || s.door.door_name}` : `Door #${s.door_id}`;
            const crdRef = s.credential_record?.credential_number || `CRD #${s.credential_record_id}`;
            const syncTime = s.completed_at ? s.completed_at.substring(0, 19).replace('T', ' ') : (s.last_attempt_at ? s.last_attempt_at.substring(0, 19).replace('T', ' ') : '-');

            let statusBadge = '';
            if (s.status === 'SUCCESS') statusBadge = '<span class="badge" style="background: rgba(16,185,129,0.2); color: #10b981;">SUCCESS</span>';
            else if (s.status === 'QUEUED') statusBadge = '<span class="badge" style="background: rgba(56,189,248,0.2); color: #38bdf8;">QUEUED</span>';
            else if (s.status === 'FAILED') statusBadge = '<span class="badge" style="background: rgba(239,68,68,0.2); color: #ef4444;">FAILED</span>';
            else statusBadge = `<span class="badge">${escapeHtml(s.status)}</span>`;

            let actions = '';
            if (s.status === 'FAILED' || s.status === 'QUEUED') {
                actions = `<button class="btn-primary" disabled style="opacity: 0.6; cursor: not-allowed; padding: 0.35rem 0.65rem; font-size: 0.75rem;" title="PLANNED — Hardware ISAPI sync write belum diaktifkan (Read-Only UI)">🔒 Sync Ulang <span class="badge badge-warning" style="font-size:0.6rem;">PLANNED</span></button>`;
            } else {
                actions = `<span style="font-size: 0.75rem; color: #10b981;">✓ Synced</span>`;
            }

            return `
                <tr>
                    <td style="font-weight: 600; color: #fff;">${escapeHtml(doorName)}</td>
                    <td><span class="badge" style="background: rgba(245,158,11,0.2); color: #fbbf24;">${escapeHtml(s.operation)}</span></td>
                    <td style="color: #38bdf8;">${escapeHtml(crdRef)}</td>
                    <td>${statusBadge}</td>
                    <td style="font-size: 0.85rem; text-align: center;">${s.attempt_count}</td>
                    <td style="font-family: monospace; font-size: 0.75rem; color: var(--text-muted);">${escapeHtml(s.idempotency_key)}</td>
                    <td style="font-size: 0.8rem; color: var(--text-muted);">${escapeHtml(syncTime)}</td>
                    <td style="text-align: right;">${actions}</td>
                </tr>
            `;
        }).join('');
    } catch (e) {
        tbody.innerHTML = `<tr><td colspan="8" style="text-align: center; color: #ef4444; padding: 2rem;">Gagal memuat antrean sync: ${escapeHtml(e.message)}</td></tr>`;
    }
}

async function loadEmoneyCards() {
    const tbody = document.getElementById('emoneyTableBody');
    if (!tbody) return;

    try {
        const search = document.getElementById('emoneySearch')?.value || '';
        const provider = document.getElementById('emoneyProviderFilter')?.value || '';
        const params = new URLSearchParams();
        if (search) params.append('search', search);
        if (provider) params.append('provider', provider);

        const res = await apiFetch(`/api/v1/access/emoney?${params.toString()}`);
        if (!res || !res.success || !res.data.length) {
            tbody.innerHTML = `<tr><td colspan="7" style="text-align: center; color: var(--text-muted); padding: 2rem;">Belum ada instrumen kartu E-Money yang terdaftar.</td></tr>`;
            return;
        }

        tbody.innerHTML = res.data.map(m => {
            const empName = m.employee?.name || '<span style="color: var(--text-muted); font-style: italic;">Tersedia (Stok)</span>';
            const statusBadge = getEmoneyStatusBadge(m.status);
            const issuedAt = m.issued_at ? m.issued_at.substring(0, 10) : '-';

            let actions = '';
            if (m.status === 'AVAILABLE' || m.status === 'ACTIVE' || m.status === 'ASSIGNED') {
                actions = `
                    <select onchange="onEmoneyStatusSelectChanged(${m.id}, this.value)" style="padding: 0.25rem 0.5rem; font-size: 0.75rem; background: var(--card-bg); border: 1px solid var(--border-color); color: #fff; border-radius: 0.4rem;">
                        <option value="">Aksi Status...</option>
                        <option value="ACTIVE">Aktifkan</option>
                        <option value="SUSPENDED">Tangguhkan (Suspend)</option>
                        <option value="RETURNED">Kembalikan (Return)</option>
                        <option value="LOST">Laporkan Hilang (Lost)</option>
                        <option value="REVOKED">Cabut Permanen</option>
                    </select>
                `;
            } else {
                actions = `<span style="font-size: 0.75rem; color: var(--text-muted);">${escapeHtml(m.status)}</span>`;
            }

            return `
                <tr>
                    <td style="font-weight: 700; color: #a855f7;">${escapeHtml(m.card_uuid)}</td>
                    <td style="font-weight: 600; color: #fff;">${escapeHtml(m.provider)}</td>
                    <td style="font-family: monospace; color: #fbbf24;">${escapeHtml(m.masked_card_number)}</td>
                    <td>${empName}</td>
                    <td>${statusBadge}</td>
                    <td style="font-size: 0.8rem; color: var(--text-muted);">${escapeHtml(issuedAt)}</td>
                    <td style="text-align: right;">${actions}</td>
                </tr>
            `;
        }).join('');
    } catch (e) {
        tbody.innerHTML = `<tr><td colspan="7" style="text-align: center; color: #ef4444; padding: 2rem;">Gagal memuat registri E-Money: ${escapeHtml(e.message)}</td></tr>`;
    }
}

function getAccessStatusBadge(status) {
    switch (status) {
        case 'PENDING_APPROVAL': return '<span class="badge" style="background: rgba(245,158,11,0.2); color: #fbbf24;">MENUNGGU PERSETUJUAN</span>';
        case 'APPROVED': return '<span class="badge" style="background: rgba(16,185,129,0.2); color: #10b981;">DISETUJUI</span>';
        case 'PROVISIONING': return '<span class="badge" style="background: rgba(56,189,248,0.2); color: #38bdf8;">PROVISIONING SYNC</span>';
        case 'ACTIVE': return '<span class="badge" style="background: rgba(16,185,129,0.25); color: #10b981;">AKTIF</span>';
        case 'REJECTED': return '<span class="badge" style="background: rgba(239,68,68,0.2); color: #ef4444;">DITOLAK</span>';
        case 'REVOKED': return '<span class="badge" style="background: rgba(156,163,175,0.2); color: #9ca3af;">DICABUT</span>';
        default: return `<span class="badge">${escapeHtml(status)}</span>`;
    }
}

function getEmoneyStatusBadge(status) {
    switch (status) {
        case 'AVAILABLE': return '<span class="badge" style="background: rgba(56,189,248,0.2); color: #38bdf8;">TERSEDIA</span>';
        case 'ASSIGNED': return '<span class="badge" style="background: rgba(245,158,11,0.2); color: #fbbf24;">DITETAPKAN</span>';
        case 'ACTIVE': return '<span class="badge" style="background: rgba(16,185,129,0.2); color: #10b981;">AKTIF</span>';
        case 'SUSPENDED': return '<span class="badge" style="background: rgba(239,68,68,0.2); color: #ef4444;">DITANGGUHKAN</span>';
        case 'LOST': return '<span class="badge" style="background: rgba(239,68,68,0.3); color: #f87171;">HILANG</span>';
        case 'RETURNED': return '<span class="badge" style="background: rgba(156,163,175,0.2); color: #9ca3af;">DIKEMBALIKAN</span>';
        case 'REVOKED': return '<span class="badge" style="background: rgba(156,163,175,0.3); color: #9ca3af;">DICABUT</span>';
        default: return `<span class="badge">${escapeHtml(status)}</span>`;
    }
}

// Populate Employee dropdown for access requests, credentials, and emoney
async function populateAccessEmployees() {
    try {
        const res = await apiFetch('/api/v1/user-management/employees?per_page=100');
        if (!res || !res.data) return;

        const options = res.data.map(e => `<option value="${e.id}">${escapeHtml(e.name)} (${escapeHtml(e.employee_id || e.nik || 'Staff')})</option>`).join('');

        const reqSelect = document.getElementById('accessReqEmployeeId');
        if (reqSelect) reqSelect.innerHTML = `<option value="">-- Pilih Karyawan Terdaftar --</option>` + options;

        const crdSelect = document.getElementById('crdEmployeeId');
        if (crdSelect) crdSelect.innerHTML = `<option value="">-- Pilih Karyawan --</option>` + options;

        const emnSelect = document.getElementById('emnEmployeeId');
        if (emnSelect) emnSelect.innerHTML = `<option value="">-- Tersedia / Belum Ditetapkan --</option>` + options;

        // Also populate Profiles dropdown in Access Request modal
        const profRes = await apiFetch('/api/v1/access/profiles');
        if (profRes && profRes.success && profRes.data) {
            const profOptions = profRes.data.map(p => `<option value="${p.id}">${escapeHtml(p.code)} - ${escapeHtml(p.name)}</option>`).join('');
            const profSelect = document.getElementById('accessReqProfileId');
            if (profSelect) profSelect.innerHTML = `<option value="">-- Pilih Profil Akses (Opsional) --</option>` + profOptions;
        }
    } catch (e) {
        console.error('Failed to populate employees for access modules', e);
    }
}

function openAddAccessRequestModal() {
    const today = new Date().toISOString().split('T')[0];
    const validFrom = document.getElementById('accessReqValidFrom');
    if (validFrom) validFrom.value = today;
    openModal('modalAddAccessRequest');
}

async function submitAccessRequest(e) {
    e.preventDefault();
    const payload = {
        employee_id: document.getElementById('accessReqEmployeeId')?.value || null,
        access_profile_id: document.getElementById('accessReqProfileId')?.value || null,
        building_name: document.getElementById('accessReqBuilding')?.value || 'Kantor Pusat PKP',
        valid_from: document.getElementById('accessReqValidFrom')?.value || null,
        valid_until: document.getElementById('accessReqValidUntil')?.value || null,
        business_reason: document.getElementById('accessReqReason')?.value || '',
    };

    try {
        const res = await apiFetch('/api/v1/access/requests', {
            method: 'POST',
            body: JSON.stringify(payload)
        });

        if (res && res.success) {
            showToast('Permohonan hak akses berhasil diajukan', 'success');
            closeModal('modalAddAccessRequest');
            loadAccessData();
        } else {
            showToast(res?.message || 'Gagal mengajukan hak akses', 'error');
        }
    } catch (err) {
        showToast(err.message, 'error');
    }
}

function openApproveRequestModal(id) {
    const input = document.getElementById('approveReqId');
    if (input) input.value = id;
    openModal('modalApproveAccessRequest');
}

async function submitApproveAccessRequest(e) {
    e.preventDefault();
    const id = document.getElementById('approveReqId')?.value;
    const notes = document.getElementById('approveReqNotes')?.value || '';

    try {
        const res = await apiFetch(`/api/v1/access/requests/${id}/approve`, {
            method: 'POST',
            body: JSON.stringify({ notes })
        });

        if (res && res.success) {
            showToast('Permohonan hak akses disetujui & antrean sync telah dibuat', 'success');
            closeModal('modalApproveAccessRequest');
            loadAccessData();
        } else {
            showToast(res?.message || 'Gagal menyetujui permohonan', 'error');
        }
    } catch (err) {
        showToast(err.message, 'error');
    }
}

function openRejectRequestModal(id) {
    const input = document.getElementById('rejectReqId');
    if (input) input.value = id;
    openModal('modalRejectAccessRequest');
}

async function submitRejectAccessRequest(e) {
    e.preventDefault();
    const id = document.getElementById('rejectReqId')?.value;
    const reason = document.getElementById('rejectReqReason')?.value || '';

    try {
        const res = await apiFetch(`/api/v1/access/requests/${id}/reject`, {
            method: 'POST',
            body: JSON.stringify({ reason })
        });

        if (res && res.success) {
            showToast('Permohonan hak akses ditolak', 'info');
            closeModal('modalRejectAccessRequest');
            loadAccessData();
        } else {
            showToast(res?.message || 'Gagal menolak permohonan', 'error');
        }
    } catch (err) {
        showToast(err.message, 'error');
    }
}

function openAddAccessProfileModal() {
    openModal('modalAddAccessProfile');
}

async function submitAccessProfile(e) {
    e.preventDefault();
    const payload = {
        code: document.getElementById('profCode')?.value || '',
        name: document.getElementById('profName')?.value || '',
        building_name: document.getElementById('profBuilding')?.value || 'Kantor Pusat PKP',
        schedule_type: document.getElementById('profSchedule')?.value || 'BUSINESS_HOURS',
        description: document.getElementById('profDescription')?.value || '',
    };

    try {
        const res = await apiFetch('/api/v1/access/profiles', {
            method: 'POST',
            body: JSON.stringify(payload)
        });

        if (res && res.success) {
            showToast('Profil hak akses berhasil dibuat', 'success');
            closeModal('modalAddAccessProfile');
            loadAccessProfiles();
            populateAccessEmployees();
        } else {
            showToast(res?.message || 'Gagal membuat profil hak akses', 'error');
        }
    } catch (err) {
        showToast(err.message, 'error');
    }
}

function openAddCredentialModal() {
    openModal('modalAddCredential');
}

async function submitCredential(e) {
    e.preventDefault();
    const payload = {
        employee_id: document.getElementById('crdEmployeeId')?.value || null,
        credential_type: document.getElementById('crdType')?.value || 'CARD',
        card_number: document.getElementById('crdCardNumber')?.value || null,
        notes: document.getElementById('crdNotes')?.value || '',
    };

    try {
        const res = await apiFetch('/api/v1/access/credentials', {
            method: 'POST',
            body: JSON.stringify(payload)
        });

        if (res && res.success) {
            showToast('Kredensial berhasil diterbitkan (Masked & Enkripsi Aman)', 'success');
            closeModal('modalAddCredential');
            loadCredentials();
            loadAccessMetrics();
        } else {
            showToast(res?.message || 'Gagal menerbitkan kredensial', 'error');
        }
    } catch (err) {
        showToast(err.message, 'error');
    }
}

function openRevokeCredentialModal(id) {
    const input = document.getElementById('revokeCrdId');
    if (input) input.value = id;
    openModal('modalRevokeCredential');
}

async function submitRevokeCredential(e) {
    e.preventDefault();
    const id = document.getElementById('revokeCrdId')?.value;
    const reason = document.getElementById('revokeCrdReason')?.value || '';

    try {
        const res = await apiFetch(`/api/v1/access/credentials/${id}/revoke`, {
            method: 'POST',
            body: JSON.stringify({ reason })
        });

        if (res && res.success) {
            showToast('Kredensial dicabut & perintah pembatalan dijadwalkan ke terminal', 'info');
            closeModal('modalRevokeCredential');
            loadCredentials();
            loadDeviceSyncs();
            loadAccessMetrics();
        } else {
            showToast(res?.message || 'Gagal mencabut kredensial', 'error');
        }
    } catch (err) {
        showToast(err.message, 'error');
    }
}

async function retryDeviceSyncItem(id) {
    try {
        const res = await apiFetch(`/api/v1/access/device-syncs/${id}/retry`, {
            method: 'POST'
        });

        if (res && res.success) {
            showToast('Sinkronisasi perangkat berhasil diproses ulang', 'success');
            loadDeviceSyncs();
            loadAccessMetrics();
        } else {
            showToast(res?.message || 'Gagal memproses ulang sinkronisasi', 'error');
        }
    } catch (err) {
        showToast(err.message, 'error');
    }
}

function openAddEmoneyModal() {
    openModal('modalAddEmoney');
}

async function submitEmoneyCard(e) {
    e.preventDefault();
    const payload = {
        employee_id: document.getElementById('emnEmployeeId')?.value || null,
        provider: document.getElementById('emnProvider')?.value || 'MANDIRI_EMONEY',
        card_number: document.getElementById('emnCardNumber')?.value || '',
        notes: document.getElementById('emnNotes')?.value || '',
    };

    try {
        const res = await apiFetch('/api/v1/access/emoney', {
            method: 'POST',
            body: JSON.stringify(payload)
        });

        if (res && res.success) {
            showToast('Kartu E-Money berhasil didaftarkan dalam registri', 'success');
            closeModal('modalAddEmoney');
            loadEmoneyCards();
            loadAccessMetrics();
        } else {
            showToast(res?.message || 'Gagal mendaftarkan kartu E-Money', 'error');
        }
    } catch (err) {
        showToast(err.message, 'error');
    }
}

async function onEmoneyStatusSelectChanged(id, newStatus) {
    if (!newStatus) return;
    if (!confirm(`Ubah status instrumen kartu E-Money ini menjadi ${newStatus}?`)) return;

    try {
        const res = await apiFetch(`/api/v1/access/emoney/${id}/status`, {
            method: 'POST',
            body: JSON.stringify({ status: newStatus })
        });

        if (res && res.success) {
            showToast(`Status kartu E-Money diubah ke ${newStatus}`, 'success');
            loadEmoneyCards();
            loadAccessMetrics();
        } else {
            showToast(res?.message || 'Gagal memperbarui status', 'error');
        }
    } catch (err) {
        showToast(err.message, 'error');
    }
}

function debounceAccessRequestSearch() {
    clearTimeout(state.searchDebounceTimer);
    state.searchDebounceTimer = setTimeout(loadAccessRequests, 350);
}

function debounceAccessProfileSearch() {
    clearTimeout(state.searchDebounceTimer);
    state.searchDebounceTimer = setTimeout(loadAccessProfiles, 350);
}

function debounceCredentialSearch() {
    clearTimeout(state.searchDebounceTimer);
    state.searchDebounceTimer = setTimeout(loadCredentials, 350);
}

function debounceEmoneySearch() {
    clearTimeout(state.searchDebounceTimer);
    state.searchDebounceTimer = setTimeout(loadEmoneyCards, 350);
}

// =============================================================
// SPRINT 7: ENTERPRISE ASSET MANAGEMENT CONTROLLER
// =============================================================

function loadAssetsData() {
    loadAssetsMetrics();
    loadAssetsCategories();
    loadAssetsInventory();
    loadAssetAssignments();
    loadAssetMaintenances();
    loadAssetIncidents();
    populateAssetDropdowns();
}

function switchAssetSubTab(subTab, btn) {
    document.querySelectorAll('#assetsTab .ats-subnav .subnav-btn').forEach(b => b.classList.remove('active'));
    if (btn) btn.classList.add('active');

    const subs = {
        'inventory': 'assetSubInventory',
        'assignments': 'assetSubAssignments',
        'maintenances': 'assetSubMaintenances',
        'incidents': 'assetSubIncidents'
    };

    Object.values(subs).forEach(id => {
        const el = document.getElementById(id);
        if (el) el.style.display = 'none';
    });

    const activeEl = document.getElementById(subs[subTab]);
    if (activeEl) activeEl.style.display = 'block';
}

async function loadAssetsMetrics() {
    try {
        const res = await apiFetch('/assets/metrics');
        if (res && res.success) {
            const d = res.data;
            const elTotal = document.getElementById('metricTotalAssets');
            const elAvail = document.getElementById('metricAvailableAssets');
            const elAsg = document.getElementById('metricAssignedAssets');
            const elMnt = document.getElementById('metricMaintenanceAssets');
            const elLost = document.getElementById('metricLostDamagedAssets');
            const elWarr = document.getElementById('metricExpiringWarranties');

            if (elTotal) elTotal.innerText = d.total_assets ?? 0;
            if (elAvail) elAvail.innerText = d.available_assets ?? 0;
            if (elAsg) elAsg.innerText = d.assigned_assets ?? 0;
            if (elMnt) elMnt.innerText = d.maintenance_assets ?? 0;
            if (elLost) elLost.innerText = d.lost_or_damaged_assets ?? 0;
            if (elWarr) elWarr.innerText = d.expiring_warranties ?? 0;
        }
    } catch (e) {
        console.error('Failed to load asset metrics', e);
    }
}

async function loadAssetsCategories() {
    try {
        const res = await apiFetch('/assets/categories');
        if (res && res.success && Array.isArray(res.data)) {
            const filterEl = document.getElementById('assetCategoryFilter');
            const formEl = document.getElementById('assetFormCategory');

            const options = res.data.map(c => `<option value="${c.id}">${escapeHtml(c.name)}</option>`).join('');

            if (filterEl) {
                filterEl.innerHTML = '<option value="">Semua Kategori</option>' + options;
            }
            if (formEl) {
                formEl.innerHTML = '<option value="">-- Pilih Kategori --</option>' + options;
            }
        }
    } catch (e) {
        console.error('Failed to load asset categories', e);
    }
}

function getAssetStatusBadge(status) {
    const s = String(status || '').toUpperCase();
    const map = {
        'AVAILABLE': '<span class="badge badge-success" style="background: rgba(16, 185, 129, 0.2); color: #34d399;">Tersedia</span>',
        'ASSIGNED': '<span class="badge badge-info" style="background: rgba(56, 189, 248, 0.2); color: #38bdf8;">Dipinjam</span>',
        'MAINTENANCE': '<span class="badge badge-warning" style="background: rgba(245, 158, 11, 0.2); color: #fcd34d;">Servis</span>',
        'REPAIR': '<span class="badge badge-warning" style="background: rgba(245, 158, 11, 0.2); color: #fcd34d;">Perbaikan</span>',
        'LOST': '<span class="badge badge-danger" style="background: rgba(239, 68, 68, 0.2); color: #f87171;">Hilang</span>',
        'DAMAGED': '<span class="badge badge-danger" style="background: rgba(239, 68, 68, 0.2); color: #f87171;">Rusak</span>',
        'DISPOSED': '<span class="badge badge-dim" style="background: rgba(148, 163, 184, 0.2); color: #94a3b8;">Dihapus Buku</span>',
    };
    return map[s] || `<span class="badge badge-dim">${escapeHtml(s)}</span>`;
}

function getAssetConditionBadge(condition) {
    const c = String(condition || '').toUpperCase();
    const map = {
        'NEW': '<span style="font-weight: 700; color: #10b981;">NEW</span>',
        'GOOD': '<span style="font-weight: 600; color: #38bdf8;">GOOD</span>',
        'FAIR': '<span style="font-weight: 600; color: #f59e0b;">FAIR</span>',
        'POOR': '<span style="font-weight: 600; color: #f97316;">POOR</span>',
        'DAMAGED': '<span style="font-weight: 700; color: #ef4444;">DAMAGED</span>',
    };
    return map[c] || `<span>${escapeHtml(c)}</span>`;
}

async function loadAssetsInventory() {
    const tbody = document.getElementById('assetsTableBody');
    if (!tbody) return;

    try {
        const search = document.getElementById('assetSearch')?.value || '';
        const categoryId = document.getElementById('assetCategoryFilter')?.value || '';
        const status = document.getElementById('assetStatusFilter')?.value || '';
        const condition = document.getElementById('assetConditionFilter')?.value || '';

        const params = new URLSearchParams();
        if (search) params.append('search', search);
        if (categoryId) params.append('category_id', categoryId);
        if (status) params.append('status', status);
        if (condition) params.append('condition', condition);

        const res = await apiFetch(`/assets?${params.toString()}`);
        if (!res || !res.success || !res.data.length) {
            tbody.innerHTML = `<tr><td colspan="8" style="text-align: center; color: var(--text-muted); padding: 2.5rem;">Tidak ada aset inventaris yang sesuai dengan filter.</td></tr>`;
            return;
        }

        tbody.innerHTML = res.data.map(a => {
            const safeId = a.id;
            const code = escapeHtml(a.asset_code);
            const name = escapeHtml(a.asset_name);
            const catName = escapeHtml(a.category?.name || 'Umum');
            const brandModel = `${escapeHtml(a.brand || '-')} / ${escapeHtml(a.model || '-')}`;
            const maskedSerial = escapeHtml(a.masked_serial_number || 'Tidak Ada');
            const loc = `${escapeHtml(a.building_name || '-')}<br><small style="color: var(--text-dim);">${escapeHtml(a.location || 'Semua Ruang')}</small>`;
            const statusBadge = getAssetStatusBadge(a.status);
            const condBadge = getAssetConditionBadge(a.condition);

            let actions = `
                <button class="btn-sm btn-secondary" onclick="showAssetDetail(${safeId})" title="Lihat Detail & Riwayat 360">👁️ Detail</button>
            `;

            if (a.status !== 'DISPOSED') {
                actions += ` <button class="btn-sm btn-edit" onclick="openEditAssetModal(${safeId})" title="Edit Data Aset">✏️ Edit</button>`;
            }

            if (a.status === 'AVAILABLE') {
                actions += ` <button class="btn-sm btn-primary" onclick="openAssignSpecificAsset(${safeId})" title="Alokasikan ke Karyawan / Intern">📋 Serahkan</button>`;
                actions += ` <button class="btn-sm btn-secondary" onclick="openMaintenanceSpecificAsset(${safeId})" title="Buka Tiket Servis">🔧 Servis</button>`;
                actions += ` <button class="btn-sm btn-delete" onclick="openDisposeAssetModal(${safeId})" title="Hapus Buku (Disposal)">🗑️ Hapus</button>`;
            } else if (a.status === 'ASSIGNED') {
                actions += ` <button class="btn-sm btn-secondary" onclick="openIncidentSpecificAsset(${safeId})" title="Laporkan Kerusakan/Kehilangan">⚠️ Insiden</button>`;
            } else if (a.status === 'DAMAGED') {
                actions += ` <button class="btn-sm btn-secondary" onclick="openMaintenanceSpecificAsset(${safeId})" title="Buka Tiket Servis">🔧 Servis</button>`;
                actions += ` <button class="btn-sm btn-delete" onclick="openDisposeAssetModal(${safeId})" title="Hapus Buku (Disposal)">🗑️ Hapus</button>`;
            }

            return `
                <tr>
                    <td><strong style="color: var(--primary); font-family: monospace;">${code}</strong></td>
                    <td>
                        <div style="font-weight: 600; color: #ffffff;">${name}</div>
                        <div style="font-size: 0.775rem; color: var(--text-muted);">${catName}</div>
                    </td>
                    <td>${brandModel}</td>
                    <td><code style="background: rgba(0,0,0,0.3); padding: 2px 6px; border-radius: 4px; color: #a5f3fc;">${maskedSerial}</code></td>
                    <td>${loc}</td>
                    <td>${condBadge}</td>
                    <td>${statusBadge}</td>
                    <td style="text-align: right; white-space: nowrap;">${actions}</td>
                </tr>
            `;
        }).join('');
    } catch (e) {
        console.error('Failed to load asset inventory', e);
        tbody.innerHTML = `<tr><td colspan="8" class="error-td">Gagal memuat data inventaris aset: ${escapeHtml(e.message)}</td></tr>`;
    }
}

async function loadAssetAssignments() {
    const tbody = document.getElementById('assetAssignmentsTableBody');
    if (!tbody) return;

    try {
        const status = document.getElementById('assetAssignmentStatusFilter')?.value || '';
        const params = new URLSearchParams();
        if (status) params.append('status', status);

        const res = await apiFetch(`/assets/assignments?${params.toString()}`);
        if (!res || !res.success || !res.data.length) {
            tbody.innerHTML = `<tr><td colspan="9" style="text-align: center; color: var(--text-muted); padding: 2.5rem;">Belum ada riwayat alokasi aset.</td></tr>`;
            return;
        }

        tbody.innerHTML = res.data.map(asg => {
            const asgNum = escapeHtml(asg.assignment_number);
            const assetInfo = `<strong>${escapeHtml(asg.asset?.asset_code || '-')}</strong><br><small style="color: var(--text-muted);">${escapeHtml(asg.asset?.asset_name || '-')}</small>`;
            const assignee = asg.employee
                ? `<div>${escapeHtml(asg.employee.name)} <span style="font-size: 0.725rem; color: var(--primary);">[Karyawan]</span></div>`
                : (asg.internship ? `<div>${escapeHtml(asg.internship.intern_id)} <span style="font-size: 0.725rem; color: #c084fc;">[Intern]</span></div>` : '-');
            const asgDate = asg.assigned_at ? asg.assigned_at.substring(0, 10) : '-';
            const expDate = asg.expected_return_date ? asg.expected_return_date.substring(0, 10) : '<span style="color: var(--text-dim);">-</span>';
            const condOut = getAssetConditionBadge(asg.condition_out);
            const condIn = asg.condition_in ? getAssetConditionBadge(asg.condition_in) : '<span style="color: var(--text-dim);">-</span>';
            const statusBadge = asg.status === 'ACTIVE'
                ? '<span class="badge badge-info" style="background: rgba(56, 189, 248, 0.2); color: #38bdf8;">ACTIVE</span>'
                : '<span class="badge badge-success" style="background: rgba(16, 185, 129, 0.2); color: #34d399;">RETURNED</span>';

            let actions = '';
            if (asg.status === 'ACTIVE') {
                actions = `<button class="btn-sm btn-primary" onclick="openReturnAssetModal(${asg.id})">🔄 Kembalikan</button>`;
            } else {
                actions = `<span style="color: var(--text-muted); font-size: 0.8rem;">Selesai (${(asg.actual_return_date || '').substring(0, 10)})</span>`;
            }

            return `
                <tr>
                    <td><span style="font-family: monospace; color: #a5b4fc;">${asgNum}</span></td>
                    <td>${assetInfo}</td>
                    <td>${assignee}</td>
                    <td>${asgDate}</td>
                    <td>${expDate}</td>
                    <td>${condOut}</td>
                    <td>${condIn}</td>
                    <td>${statusBadge}</td>
                    <td style="text-align: right; white-space: nowrap;">${actions}</td>
                </tr>
            `;
        }).join('');
    } catch (e) {
        console.error('Failed to load asset assignments', e);
        tbody.innerHTML = `<tr><td colspan="9" class="error-td">Gagal memuat penugasan aset: ${escapeHtml(e.message)}</td></tr>`;
    }
}

async function loadAssetMaintenances() {
    const tbody = document.getElementById('assetMaintenancesTableBody');
    if (!tbody) return;

    try {
        const status = document.getElementById('assetMaintenanceStatusFilter')?.value || '';
        const params = new URLSearchParams();
        if (status) params.append('status', status);

        const res = await apiFetch(`/assets/maintenances?${params.toString()}`);
        if (!res || !res.success || !res.data.length) {
            tbody.innerHTML = `<tr><td colspan="9" style="text-align: center; color: var(--text-muted); padding: 2.5rem;">Tidak ada catatan pemeliharaan atau tiket servis aset.</td></tr>`;
            return;
        }

        tbody.innerHTML = res.data.map(m => {
            const num = escapeHtml(m.maintenance_number);
            const assetCode = escapeHtml(m.asset?.asset_code || '-');
            const type = `<span class="badge" style="background: rgba(99, 102, 241, 0.15); color: #a5b4fc;">${escapeHtml(m.maintenance_type)}</span>`;
            const issue = escapeHtml(m.issue_description);
            const vendor = escapeHtml(m.vendor || 'Internal IT');
            const cost = Number(m.cost || 0).toLocaleString('id-ID');
            const openedAt = m.opened_at ? m.opened_at.substring(0, 10) : '-';
            const statusBadge = m.status === 'COMPLETED'
                ? '<span class="badge badge-success" style="background: rgba(16, 185, 129, 0.2); color: #34d399;">COMPLETED</span>'
                : '<span class="badge badge-warning" style="background: rgba(245, 158, 11, 0.2); color: #fcd34d;">OPEN</span>';

            let actions = '';
            if (m.status !== 'COMPLETED') {
                actions = `<button class="btn-sm btn-primary" onclick="openCompleteMaintenanceModal(${m.id})">✅ Selesaikan</button>`;
            } else {
                actions = `<span style="color: var(--text-muted); font-size: 0.8rem;">Tuntas</span>`;
            }

            return `
                <tr>
                    <td><span style="font-family: monospace; color: #fcd34d;">${num}</span></td>
                    <td><strong>${assetCode}</strong></td>
                    <td>${type}</td>
                    <td style="max-width: 250px;">${issue}</td>
                    <td>${vendor}</td>
                    <td>Rp ${cost}</td>
                    <td>${openedAt}</td>
                    <td>${statusBadge}</td>
                    <td style="text-align: right; white-space: nowrap;">${actions}</td>
                </tr>
            `;
        }).join('');
    } catch (e) {
        console.error('Failed to load asset maintenances', e);
        tbody.innerHTML = `<tr><td colspan="9" class="error-td">Gagal memuat catatan servis: ${escapeHtml(e.message)}</td></tr>`;
    }
}

async function loadAssetIncidents() {
    const tbody = document.getElementById('assetIncidentsTableBody');
    if (!tbody) return;

    try {
        const type = document.getElementById('assetIncidentTypeFilter')?.value || '';
        const status = document.getElementById('assetIncidentStatusFilter')?.value || '';
        const params = new URLSearchParams();
        if (type) params.append('incident_type', type);
        if (status) params.append('status', status);

        const res = await apiFetch(`/assets/incidents?${params.toString()}`);
        if (!res || !res.success || !res.data.length) {
            tbody.innerHTML = `<tr><td colspan="8" style="text-align: center; color: var(--text-muted); padding: 2.5rem;">Tidak ada laporan insiden aset yang tercatat.</td></tr>`;
            return;
        }

        tbody.innerHTML = res.data.map(inc => {
            const incNum = escapeHtml(inc.incident_number);
            const assetCode = escapeHtml(inc.asset?.asset_code || '-');
            const incType = `<span class="badge" style="background: rgba(239, 68, 68, 0.2); color: #f87171;">${escapeHtml(inc.incident_type)}</span>`;
            const reporter = escapeHtml(inc.employee?.name || inc.reported_by_admin?.name || 'Staf Operasional');
            const desc = `${escapeHtml(inc.description)}<br><small style="color: var(--text-dim);">${escapeHtml(inc.location || '-')}</small>`;
            const date = inc.incident_date ? inc.incident_date.substring(0, 10) : '-';
            const statusBadge = inc.status === 'RESOLVED'
                ? '<span class="badge badge-success" style="background: rgba(16, 185, 129, 0.2); color: #34d399;">RESOLVED</span>'
                : '<span class="badge badge-danger" style="background: rgba(239, 68, 68, 0.2); color: #f87171;">REPORTED</span>';

            let actions = '';
            if (inc.status !== 'RESOLVED') {
                actions = `<button class="btn-sm btn-primary" onclick="openResolveIncidentModal(${inc.id})">🛡️ Selesaikan</button>`;
            } else {
                actions = `<span style="color: var(--text-muted); font-size: 0.8rem;">Resolved</span>`;
            }

            return `
                <tr>
                    <td><span style="font-family: monospace; color: #f87171;">${incNum}</span></td>
                    <td><strong>${assetCode}</strong></td>
                    <td>${incType}</td>
                    <td>${reporter}</td>
                    <td style="max-width: 250px;">${desc}</td>
                    <td>${date}</td>
                    <td>${statusBadge}</td>
                    <td style="text-align: right; white-space: nowrap;">${actions}</td>
                </tr>
            `;
        }).join('');
    } catch (e) {
        console.error('Failed to load asset incidents', e);
        tbody.innerHTML = `<tr><td colspan="8" class="error-td">Gagal memuat insiden aset: ${escapeHtml(e.message)}</td></tr>`;
    }
}

// Populate Asset Dropdowns (Available assets, active employees, active interns)
async function populateAssetDropdowns() {
    try {
        const res = await apiFetch('/assets?status=AVAILABLE');
        const asgSelect = document.getElementById('asgAssetSelect');
        const mntSelect = document.getElementById('mntAssetSelect');
        const incSelect = document.getElementById('incAssetSelect');

        if (res && res.success && Array.isArray(res.data)) {
            const opts = res.data.map(a => `<option value="${a.id}">[${escapeHtml(a.asset_code)}] ${escapeHtml(a.asset_name)} (${escapeHtml(a.building_name)})</option>`).join('');
            if (asgSelect) asgSelect.innerHTML = '<option value="">-- Pilih Aset Tersedia --</option>' + opts;
        }

        const resAll = await apiFetch('/assets');
        if (resAll && resAll.success && Array.isArray(resAll.data)) {
            const allOpts = resAll.data.map(a => `<option value="${a.id}">[${escapeHtml(a.asset_code)}] ${escapeHtml(a.asset_name)} (${escapeHtml(a.status)})</option>`).join('');
            if (mntSelect) mntSelect.innerHTML = '<option value="">-- Pilih Aset --</option>' + allOpts;
            if (incSelect) incSelect.innerHTML = '<option value="">-- Pilih Aset --</option>' + allOpts;
        }

        const empSelect = document.getElementById('asgEmployeeSelect');
        if (empSelect && state.employees && state.employees.length) {
            empSelect.innerHTML = '<option value="">-- Tidak Ada / Kosongkan Jika Pemagang --</option>' +
                state.employees.filter(e => e.employment_status === 'ACTIVE' || e.employment_status === 'PROBATION')
                    .map(e => `<option value="${e.id}">${escapeHtml(e.name)} (${escapeHtml(e.employee_id)})</option>`).join('');
        }

        const internSelect = document.getElementById('asgInternSelect');
        if (internSelect) {
            const intRes = await apiFetch('/internships?status=ACTIVE');
            if (intRes && intRes.status === 'success' && Array.isArray(intRes.data)) {
                internSelect.innerHTML = '<option value="">-- Tidak Ada / Kosongkan Jika Karyawan --</option>' +
                    intRes.data.map(i => `<option value="${i.id}">[${escapeHtml(i.intern_id)}] ${escapeHtml(i.employee?.name || i.institution)}</option>`).join('');
            }
        }
    } catch (e) {
        console.warn('Could not populate asset dropdowns', e);
    }
}

// Form & Modal Submissions
function openAddAssetModal() {
    document.getElementById('assetEditId').value = '';
    document.getElementById('assetModalTitle').innerText = 'Daftarkan Aset Baru';
    document.getElementById('formAddAsset').reset();
    document.getElementById('assetFormBuilding').value = 'Kantor Pusat PKP';
    openModal('modalAddAsset');
}

async function openEditAssetModal(id) {
    try {
        const res = await apiFetch(`/assets/${id}`);
        if (!res || !res.success) return;
        const a = res.data;

        document.getElementById('assetEditId').value = a.id;
        document.getElementById('assetModalTitle').innerText = `Edit Data Aset (${a.asset_code})`;
        document.getElementById('assetFormName').value = a.asset_name || '';
        document.getElementById('assetFormCategory').value = a.category_id || '';
        document.getElementById('assetFormBrand').value = a.brand || '';
        document.getElementById('assetFormModel').value = a.model || '';
        document.getElementById('assetFormSerial').value = a.serial_number || '';
        document.getElementById('assetFormBuilding').value = a.building_name || 'Kantor Pusat PKP';
        document.getElementById('assetFormLocation').value = a.location || '';
        document.getElementById('assetFormCondition').value = a.condition || 'GOOD';
        document.getElementById('assetFormPurchaseDate').value = a.purchase_date ? a.purchase_date.substring(0, 10) : '';
        document.getElementById('assetFormWarrantyEnd').value = a.warranty_end ? a.warranty_end.substring(0, 10) : '';
        document.getElementById('assetFormNotes').value = a.notes || '';

        openModal('modalAddAsset');
    } catch (e) {
        showToast(e.message, 'error');
    }
}

async function submitAssetForm(e) {
    e.preventDefault();
    const editId = document.getElementById('assetEditId').value;
    const isEdit = Boolean(editId);

    const payload = {
        asset_name: document.getElementById('assetFormName').value,
        category_id: document.getElementById('assetFormCategory').value || null,
        brand: document.getElementById('assetFormBrand').value || null,
        model: document.getElementById('assetFormModel').value || null,
        serial_number: document.getElementById('assetFormSerial').value || null,
        building_name: document.getElementById('assetFormBuilding').value || null,
        location: document.getElementById('assetFormLocation').value || null,
        condition: document.getElementById('assetFormCondition').value || 'GOOD',
        purchase_date: document.getElementById('assetFormPurchaseDate').value || null,
        warranty_end: document.getElementById('assetFormWarrantyEnd').value || null,
        notes: document.getElementById('assetFormNotes').value || null,
    };

    try {
        const url = isEdit ? `/assets/${editId}` : '/assets';
        const method = isEdit ? 'PUT' : 'POST';

        const res = await apiFetch(url, {
            method: method,
            body: JSON.stringify(payload)
        });

        if (res && res.success) {
            showToast(res.message || 'Data aset berhasil disimpan', 'success');
            closeModal('modalAddAsset');
            loadAssetsInventory();
            loadAssetsMetrics();
            populateAssetDropdowns();
        } else {
            showToast(res?.message || 'Gagal menyimpan aset', 'error');
        }
    } catch (err) {
        showToast(err.message, 'error');
    }
}

function openAssignAssetModal() {
    document.getElementById('formAssignAsset').reset();
    populateAssetDropdowns();
    openModal('modalAssignAsset');
}

function openAssignSpecificAsset(assetId) {
    openAssignAssetModal();
    setTimeout(() => {
        const sel = document.getElementById('asgAssetSelect');
        if (sel) sel.value = assetId;
    }, 200);
}

async function submitAssignAsset(e) {
    e.preventDefault();
    const assetId = document.getElementById('asgAssetSelect').value;
    if (!assetId) {
        showToast('Pilih aset terlebih dahulu.', 'warning');
        return;
    }

    const payload = {
        employee_id: document.getElementById('asgEmployeeSelect').value || null,
        internship_id: document.getElementById('asgInternSelect').value || null,
        expected_return_date: document.getElementById('asgExpectedReturn').value || null,
        condition_out: document.getElementById('asgConditionOut').value || 'GOOD',
        handover_notes: document.getElementById('asgNotes').value || null,
    };

    try {
        const res = await apiFetch(`/assets/${assetId}/assign`, {
            method: 'POST',
            body: JSON.stringify(payload)
        });

        if (res && res.success) {
            showToast('Aset berhasil diserahkan kepada pemegang', 'success');
            closeModal('modalAssignAsset');
            loadAssetsInventory();
            loadAssetAssignments();
            loadAssetsMetrics();
            populateAssetDropdowns();
        } else {
            showToast(res?.message || 'Gagal menyerahkan aset', 'error');
        }
    } catch (err) {
        showToast(err.message, 'error');
    }
}

function openReturnAssetModal(assignmentId) {
    document.getElementById('retAssignmentId').value = assignmentId;
    document.getElementById('formReturnAsset').reset();
    openModal('modalReturnAsset');
}

async function submitReturnAsset(e) {
    e.preventDefault();
    const assignmentId = document.getElementById('retAssignmentId').value;

    const payload = {
        condition_in: document.getElementById('retConditionIn').value,
        return_notes: document.getElementById('retNotes').value || null,
    };

    try {
        const res = await apiFetch(`/assets/assignments/${assignmentId}/return`, {
            method: 'POST',
            body: JSON.stringify(payload)
        });

        if (res && res.success) {
            showToast('Pengembalian aset berhasil diproses', 'success');
            closeModal('modalReturnAsset');
            loadAssetsInventory();
            loadAssetAssignments();
            loadAssetsMetrics();
            populateAssetDropdowns();
        } else {
            showToast(res?.message || 'Gagal memproses pengembalian', 'error');
        }
    } catch (err) {
        showToast(err.message, 'error');
    }
}

function openMaintenanceModal() {
    document.getElementById('formMaintenance').reset();
    populateAssetDropdowns();
    openModal('modalMaintenance');
}

function openMaintenanceSpecificAsset(assetId) {
    openMaintenanceModal();
    setTimeout(() => {
        const sel = document.getElementById('mntAssetSelect');
        if (sel) sel.value = assetId;
    }, 200);
}

async function submitMaintenance(e) {
    e.preventDefault();
    const assetId = document.getElementById('mntAssetSelect').value;

    const payload = {
        maintenance_type: document.getElementById('mntType').value,
        issue_description: document.getElementById('mntIssue').value,
        vendor: document.getElementById('mntVendor').value || null,
        cost: document.getElementById('mntCost').value || 0,
    };

    try {
        const res = await apiFetch(`/assets/${assetId}/maintenance`, {
            method: 'POST',
            body: JSON.stringify(payload)
        });

        if (res && res.success) {
            showToast('Tiket servis aset berhasil dibuka', 'success');
            closeModal('modalMaintenance');
            loadAssetsInventory();
            loadAssetMaintenances();
            loadAssetsMetrics();
        } else {
            showToast(res?.message || 'Gagal membuka tiket servis', 'error');
        }
    } catch (err) {
        showToast(err.message, 'error');
    }
}

function openCompleteMaintenanceModal(maintenanceId) {
    document.getElementById('compMntId').value = maintenanceId;
    document.getElementById('formCompleteMnt').reset();
    openModal('modalCompleteMaintenance');
}

async function submitCompleteMaintenance(e) {
    e.preventDefault();
    const maintenanceId = document.getElementById('compMntId').value;

    const payload = {
        result: document.getElementById('compMntResult').value,
        condition: document.getElementById('compMntCondition').value,
        cost: document.getElementById('compMntCost').value || null,
    };

    try {
        const res = await apiFetch(`/assets/maintenances/${maintenanceId}/complete`, {
            method: 'POST',
            body: JSON.stringify(payload)
        });

        if (res && res.success) {
            showToast('Tiket servis selesai; aset kembali tersedia di inventaris', 'success');
            closeModal('modalCompleteMaintenance');
            loadAssetsInventory();
            loadAssetMaintenances();
            loadAssetsMetrics();
            populateAssetDropdowns();
        } else {
            showToast(res?.message || 'Gagal menyelesaikan servis', 'error');
        }
    } catch (err) {
        showToast(err.message, 'error');
    }
}

function openIncidentModal() {
    document.getElementById('formIncident').reset();
    document.getElementById('incDate').value = new Date().toISOString().slice(0, 10);
    populateAssetDropdowns();
    openModal('modalIncident');
}

function openIncidentSpecificAsset(assetId) {
    openIncidentModal();
    setTimeout(() => {
        const sel = document.getElementById('incAssetSelect');
        if (sel) sel.value = assetId;
    }, 200);
}

async function submitIncident(e) {
    e.preventDefault();
    const assetId = document.getElementById('incAssetSelect').value;

    const payload = {
        incident_type: document.getElementById('incType').value,
        description: document.getElementById('incDescription').value,
        location: document.getElementById('incLocation').value || null,
        incident_date: document.getElementById('incDate').value || null,
    };

    try {
        const res = await apiFetch(`/assets/${assetId}/incident`, {
            method: 'POST',
            body: JSON.stringify(payload)
        });

        if (res && res.success) {
            showToast('Laporan insiden aset berhasil diajukan', 'warning');
            closeModal('modalIncident');
            loadAssetsInventory();
            loadAssetIncidents();
            loadAssetsMetrics();
        } else {
            showToast(res?.message || 'Gagal mengajukan laporan insiden', 'error');
        }
    } catch (err) {
        showToast(err.message, 'error');
    }
}

function openResolveIncidentModal(incidentId) {
    document.getElementById('resIncId').value = incidentId;
    document.getElementById('formResolveIncident').reset();
    openModal('modalResolveIncident');
}

async function submitResolveIncident(e) {
    e.preventDefault();
    const incidentId = document.getElementById('resIncId').value;

    const payload = {
        resolution: document.getElementById('resIncResolution').value,
    };

    try {
        const res = await apiFetch(`/assets/incidents/${incidentId}/resolve`, {
            method: 'POST',
            body: JSON.stringify(payload)
        });

        if (res && res.success) {
            showToast('Insiden aset berhasil diselesaikan', 'success');
            closeModal('modalResolveIncident');
            loadAssetIncidents();
            loadAssetsInventory();
            loadAssetsMetrics();
        } else {
            showToast(res?.message || 'Gagal menyelesaikan insiden', 'error');
        }
    } catch (err) {
        showToast(err.message, 'error');
    }
}

function openDisposeAssetModal(assetId) {
    document.getElementById('dispAssetId').value = assetId;
    document.getElementById('formDisposeAsset').reset();
    openModal('modalDisposeAsset');
}

async function submitDisposeAsset(e) {
    e.preventDefault();
    const assetId = document.getElementById('dispAssetId').value;

    const payload = {
        disposal_reason: document.getElementById('dispReason').value,
        disposal_method: document.getElementById('dispMethod').value,
    };

    try {
        const res = await apiFetch(`/assets/${assetId}/dispose`, {
            method: 'POST',
            body: JSON.stringify(payload)
        });

        if (res && res.success) {
            showToast('Aset berhasil dihapusbukukan (Disposed)', 'success');
            closeModal('modalDisposeAsset');
            loadAssetsInventory();
            loadAssetsMetrics();
            populateAssetDropdowns();
        } else {
            showToast(res?.message || 'Gagal menghapusbukukan aset', 'error');
        }
    } catch (err) {
        showToast(err.message, 'error');
    }
}

async function showAssetDetail(id) {
    const titleEl = document.getElementById('detailAssetTitle');
    const codeEl = document.getElementById('detailAssetCode');
    const bodyEl = document.getElementById('assetDetailBody');

    bodyEl.innerHTML = '<div class="spinner"></div> Memuat rincian aset...';
    openModal('modalAssetDetail');

    try {
        const res = await apiFetch(`/assets/${id}`);
        if (!res || !res.success) {
            bodyEl.innerHTML = '<div class="error-td">Aset tidak ditemukan.</div>';
            return;
        }

        const a = res.data;
        titleEl.innerText = a.asset_name;
        codeEl.innerText = a.asset_code;

        const assignmentsHtml = (a.assignments && a.assignments.length)
            ? a.assignments.map(asg => {
                const holder = asg.employee ? asg.employee.name : (asg.internship ? `Intern ${asg.internship.intern_id}` : '-');
                const period = `${(asg.assigned_at || '').substring(0, 10)} s/d ${asg.actual_return_date ? asg.actual_return_date.substring(0, 10) : 'Saat ini'}`;
                return `<li><strong>${escapeHtml(asg.assignment_number)}</strong>: ${escapeHtml(holder)} (${period}) [Status: ${asg.status}]</li>`;
            }).join('')
            : '<li style="color: var(--text-muted);">Belum pernah dialokasikan.</li>';

        const maintenancesHtml = (a.maintenances && a.maintenances.length)
            ? a.maintenances.map(m => `<li><strong>${escapeHtml(m.maintenance_number)}</strong>: ${escapeHtml(m.maintenance_type)} - ${escapeHtml(m.issue_description)} [${m.status}]</li>`).join('')
            : '<li style="color: var(--text-muted);">Belum ada riwayat perbaikan/servis.</li>';

        const incidentsHtml = (a.incidents && a.incidents.length)
            ? a.incidents.map(inc => `<li><strong>${escapeHtml(inc.incident_number)}</strong>: ${escapeHtml(inc.incident_type)} - ${escapeHtml(inc.description)} [${inc.status}]</li>`).join('')
            : '<li style="color: var(--text-muted);">Tidak ada catatan insiden.</li>';

        bodyEl.innerHTML = `
            <div style="display: grid; grid-template-columns: 1fr 1fr; gap: 1rem; margin-bottom: 1.25rem;">
                <div style="background: rgba(15, 23, 42, 0.6); padding: 1rem; border-radius: 0.75rem; border: 1px solid var(--border-color);">
                    <div style="font-weight: 700; color: #ffffff; margin-bottom: 0.5rem;">Spesifikasi Perangkat</div>
                    <div>Brand / Model: <strong>${escapeHtml(a.brand || '-')} / ${escapeHtml(a.model || '-')}</strong></div>
                    <div>Nomor Seri (Masked): <code>${escapeHtml(a.masked_serial_number || '-')}</code></div>
                    <div>Kategori: <strong>${escapeHtml(a.category?.name || '-')}</strong></div>
                    <div>Kondisi Fisik: <strong>${escapeHtml(a.condition)}</strong></div>
                    <div>Status Inventaris: <strong>${escapeHtml(a.status)}</strong></div>
                </div>
                <div style="background: rgba(15, 23, 42, 0.6); padding: 1rem; border-radius: 0.75rem; border: 1px solid var(--border-color);">
                    <div style="font-weight: 700; color: #ffffff; margin-bottom: 0.5rem;">Lokasi & Pembelian</div>
                    <div>Gedung: <strong>${escapeHtml(a.building_name || '-')}</strong></div>
                    <div>Lokasi Ruangan: <strong>${escapeHtml(a.location || '-')}</strong></div>
                    <div>Tgl Pembelian: <strong>${(a.purchase_date || '').substring(0, 10) || '-'}</strong></div>
                    <div>Batas Garansi: <strong>${(a.warranty_end || '').substring(0, 10) || '-'}</strong></div>
                    <div>Harga Pembelian: <strong>Rp ${Number(a.purchase_price || 0).toLocaleString('id-ID')}</strong></div>
                </div>
            </div>
            <div style="margin-bottom: 1rem;">
                <div style="font-weight: 700; color: #ffffff; margin-bottom: 0.25rem;">Riwayat Penugasan (Assignment History)</div>
                <ul style="padding-left: 1.25rem; font-size: 0.825rem; color: var(--text-muted);">${assignmentsHtml}</ul>
            </div>
            <div style="margin-bottom: 1rem;">
                <div style="font-weight: 700; color: #ffffff; margin-bottom: 0.25rem;">Riwayat Pemeliharaan & Servis</div>
                <ul style="padding-left: 1.25rem; font-size: 0.825rem; color: var(--text-muted);">${maintenancesHtml}</ul>
            </div>
            <div>
                <div style="font-weight: 700; color: #ffffff; margin-bottom: 0.25rem;">Catatan Insiden (Rusak / Hilang)</div>
                <ul style="padding-left: 1.25rem; font-size: 0.825rem; color: var(--text-muted);">${incidentsHtml}</ul>
            </div>
        `;
    } catch (e) {
        bodyEl.innerHTML = `<div class="error-td">Gagal memuat detail aset: ${escapeHtml(e.message)}</div>`;
    }
}

function debounceAssetSearch() {
    clearTimeout(state.searchDebounceTimer);
    state.searchDebounceTimer = setTimeout(loadAssetsInventory, 350);
}

// ==========================================
// SPRINT 8: WORK CALENDAR & ATTENDANCE CORE
// ==========================================

async function loadAttendanceData() {
    loadAttendanceMetrics();
    loadAttendanceReport();
    const tbody = document.getElementById('attendanceTableBody');
    if (!tbody) return;

    tbody.innerHTML = '<tr><td colspan="10" class="loading-td"><div class="spinner"></div> Memuat data kehadiran...</td></tr>';
    try {
        const res = await apiFetch('/attendance/records');
        if (!res.success) throw new Error(res.message || 'Gagal memuat kehadiran');
        
        const records = res.data.data || res.data;
        if (!records.length) {
            tbody.innerHTML = '<tr><td colspan="10" class="empty-td" style="text-align:center; padding: 2rem; color: var(--text-muted);">Belum ada kehadiran yang diproses untuk scope Anda.</td></tr>';
            return;
        }

        tbody.innerHTML = records.map(r => {
            const empName = r.employee ? r.employee.name : '-';
            const calName = r.work_calendar ? r.work_calendar.name : '-';
            const sourceLog = r.access_log_out || r.access_log_in;
            const doorName = sourceLog?.door?.door_name || '-';
            const credential = sourceLog?.verify_method || '-';
            const processingState = sourceLog ? 'Diproses perangkat' : 'Input terverifikasi';
            
            let statusBadge = '';
            switch (r.status) {
                case 'PRESENT': statusBadge = '<span class="status-badge status-active">Hadir</span>'; break;
                case 'LATE': statusBadge = '<span class="status-badge status-warning">Terlambat</span>'; break;
                case 'ABSENT': statusBadge = '<span class="status-badge status-inactive">Mangkir</span>'; break;
                case 'OFF': statusBadge = '<span class="status-badge" style="background:#475569;color:#fff;">Libur / OFF</span>'; break;
                case 'LEAVE': statusBadge = '<span class="status-badge status-info">Cuti</span>'; break;
                default: statusBadge = `<span class="status-badge">${escapeHtml(r.status)}</span>`;
            }

            const lateSpan = r.late_minutes > 0 
                ? `<span style="color: #ef4444; font-weight: 700;">+${r.late_minutes} min</span>` 
                : '<span style="color: var(--text-muted);">-</span>';

            return `
                <tr>
                    <td>${escapeHtml(r.attendance_date)}</td>
                    <td><strong>${escapeHtml(empName)}</strong></td>
                    <td>${escapeHtml(calName)}</td>
                    <td>${r.clock_in_at ? r.clock_in_at.substring(11, 16) : '-'}</td>
                    <td>${r.clock_out_at ? r.clock_out_at.substring(11, 16) : '-'}</td>
                    <td>${escapeHtml(doorName)}</td>
                    <td>${escapeHtml(credential)}</td>
                    <td><span class="status-badge ${sourceLog ? 'status-active' : 'status-info'}">${escapeHtml(processingState)}</span></td>
                    <td>${statusBadge}</td>
                    <td>${lateSpan}</td>
                </tr>
            `;
        }).join('');
    } catch (e) {
        tbody.innerHTML = `<tr><td colspan="10" class="error-td">Gagal memuat kehadiran. ${escapeHtml(e.message)}</td></tr>`;
    }
}

async function loadAttendanceMetrics() {
    const container = document.getElementById('attendanceMetricsContainer');
    if (!container) return;

    try {
        const res = await apiFetch('/attendance/metrics');
        if (!res.success) return;
        
        const m = res.data;
        const t = m.today;
        const live = m.live || {};
        const total = t.present + t.late + t.absent + t.off + t.leave;
        const presentRate = total > 0 ? Math.round(((t.present + t.late) / total) * 100) : 0;

        container.innerHTML = `
            <div class="stat-card">
                <div class="stat-title">Tingkat Kehadiran Harian</div>
                <div class="stat-value" style="color: #10b981;">${presentRate}%</div>
                <div class="stat-desc">Hari Ini: ${escapeHtml(t.date)}</div>
            </div>
            <div class="stat-card">
                <div class="stat-title">Hadir & Terlambat</div>
                <div class="stat-value" style="color: #f59e0b;">${t.present + t.late} <span style="font-size:1rem;font-weight:400;color:var(--text-muted);">karyawan</span></div>
                <div class="stat-desc">(${t.late} terlambat)</div>
            </div>
            <div class="stat-card">
                <div class="stat-title">Mangkir (Tanpa Keterangan)</div>
                <div class="stat-value" style="color: #ef4444;">${t.absent} <span style="font-size:1rem;font-weight:400;color:var(--text-muted);">karyawan</span></div>
                <div class="stat-desc">Potensi pelanggaran</div>
            </div>
            <div class="stat-card">
                <div class="stat-title">Kalender Kerja Aktif</div>
                <div class="stat-value" style="color: #3b82f6;">${m.calendars_count}</div>
                <div class="stat-desc">Dikelola oleh sistem</div>
            </div>
            <div class="stat-card">
                <div class="stat-title">Live Office Attendance</div>
                <div class="stat-value" style="font-size:1.15rem;color:#10b981;">${live.latest_event_at ? new Date(live.latest_event_at).toLocaleTimeString('id-ID', {hour:'2-digit', minute:'2-digit'}) : '-'}</div>
                <div class="stat-desc">${escapeHtml(live.latest_door || 'Belum ada event hari ini')}</div>
            </div>
            <div class="stat-card">
                <div class="stat-title">Event Belum Terpetakan</div>
                <div class="stat-value" style="color:#f59e0b;">${Number(live.unmatched_events || 0)}</div>
                <div class="stat-desc">Hanya terlihat oleh pengguna berwenang</div>
            </div>
        `;
    } catch (e) {
        console.error('Failed to load attendance metrics', e);
    }
}

async function loadAttendanceReport() {
    const tbody = document.getElementById('attendanceReportBody');
    if (!tbody) return;
    const month = document.getElementById('attendanceReportMonth');
    if (!month.value) month.value = new Date().toISOString().slice(0, 7);
    const building = document.getElementById('attendanceReportBuilding');
    if (building.options.length === 1) {
        try {
            const lookup = await apiFetch('/user-management/organization/lookup');
            (lookup.data?.buildings || []).forEach(item => building.add(new Option(item.name, item.id)));
        } catch (_) {}
    }
    const query = new URLSearchParams({ month: month.value });
    if (building.value) query.set('building_id', building.value);
    try {
        const res = await apiFetch(`/attendance/reports/monthly?${query}`);
        const totals = res.data.totals;
        document.getElementById('attendanceReportMetrics').innerHTML = `<div class="stat-card"><div class="stat-title">Employees</div><div class="stat-value">${totals.employees}</div></div><div class="stat-card"><div class="stat-title">Present</div><div class="stat-value" style="color:#10b981">${totals.present}</div></div><div class="stat-card"><div class="stat-title">Late</div><div class="stat-value" style="color:#f59e0b">${totals.late}</div></div><div class="stat-card"><div class="stat-title">Absent</div><div class="stat-value" style="color:#ef4444">${totals.absent}</div></div><div class="stat-card"><div class="stat-title">Attendance Rate</div><div class="stat-value" style="color:#38bdf8">${totals.attendance_rate}%</div></div>`;
        tbody.innerHTML = res.data.rows.length ? res.data.rows.map(row => `<tr><td><strong>${escapeHtml(row.employee_name)}</strong><br><small>${escapeHtml(row.employee_code)}</small></td><td>${escapeHtml(row.building)}</td><td>${row.present}</td><td>${row.late}</td><td>${row.absent}</td><td>${row.attendance_rate}%</td><td>${row.late_minutes}</td></tr>`).join('') : '<tr><td colspan="7" class="empty-td">No attendance data for this period.</td></tr>';
    } catch (error) {
        tbody.innerHTML = `<tr><td colspan="7" class="error-td">${escapeHtml(error.message)}</td></tr>`;
    }
}

async function exportAttendanceReport() {
    const query = new URLSearchParams({ month: document.getElementById('attendanceReportMonth').value });
    const building = document.getElementById('attendanceReportBuilding').value;
    if (building) query.set('building_id', building);
    const response = await fetch(`${API_BASE}/attendance/reports/monthly/export?${query}`, { headers: { Accept: 'text/csv', ...(APP_TOKEN ? { Authorization: `Bearer ${APP_TOKEN}` } : {}) } });
    if (!response.ok) return showToast('Attendance export failed.', 'error');
    const url = URL.createObjectURL(await response.blob());
    const link = document.createElement('a');
    link.href = url; link.download = `attendance-report-${query.get('month')}.csv`; link.click(); URL.revokeObjectURL(url);
}

async function openFacilityModal(doorId = null) {
    openModal('facilityModal');
    const response = await apiFetch('/admin/buildings');
    const select = document.getElementById('facilityDoorBuilding');
    select.innerHTML = '<option value="">Select building</option>' + response.data.map(item => `<option value="${item.id}">${escapeHtml(item.name)}</option>`).join('');
    const door = doorId ? state.doors.find(item => item.door_id === doorId) : null;
    document.getElementById('facilityOriginalDoorId').value = door?.door_id || '';
    document.getElementById('facilityDoorId').value = door?.door_id || '';
    document.getElementById('facilityDoorName').value = door?.door_name || '';
    select.value = door?.building_id || '';
    document.getElementById('facilityDoorIp').value = door?.device_ip || '';
    document.getElementById('facilityDoorGateway').value = door?.gateway || '';
    document.getElementById('facilityDoorModel').value = door?.device_model || 'DS-K1T804AMF';
    document.getElementById('facilityDoorSubmit').textContent = door ? 'Update Door' : 'Register Door';
}

async function submitBuildingConfig(event) {
    event.preventDefault();
    try {
        await apiFetch('/admin/buildings', { method: 'POST', body: JSON.stringify({ code: document.getElementById('facilityBuildingCode').value, name: document.getElementById('facilityBuildingName').value, description: document.getElementById('facilityBuildingDescription').value || null }) });
        event.target.reset();
        await openFacilityModal(document.getElementById('facilityOriginalDoorId').value || null);
        showToast('Building registered.', 'success');
    } catch (error) { showToast(error.message, 'error'); }
}

async function submitDoorConfig(event) {
    event.preventDefault();
    const original = document.getElementById('facilityOriginalDoorId').value;
    const payload = { door_id: document.getElementById('facilityDoorId').value, name: document.getElementById('facilityDoorName').value, building_id: Number(document.getElementById('facilityDoorBuilding').value), device_ip: document.getElementById('facilityDoorIp').value, gateway: document.getElementById('facilityDoorGateway').value || null, device_model: document.getElementById('facilityDoorModel').value };
    try {
        await apiFetch(original ? `/admin/doors/${encodeURIComponent(original)}` : '/admin/doors', { method: original ? 'PUT' : 'POST', body: JSON.stringify(payload) });
        closeModal('facilityModal'); await loadDoors(); showToast(original ? 'Door configuration updated.' : 'Door registered offline pending verification.', 'success');
    } catch (error) { showToast(error.message, 'error'); }
}

// ==========================================
// SPRINT 10: FIELD ATTENDANCE + GPS + PHOTO
// ==========================================

let currentFieldAssignment = null;
let currentGpsCoords = null;
let currentFieldPhotoFile = null;
let isSubmittingFieldAttendance = false;
let todayAttendanceData = null;

async function loadFieldAttendanceData() {
    const assignBadge = document.getElementById('fieldAssignmentStatusBadge');
    const assignDetails = document.getElementById('fieldAssignmentDetails');
    const todayCard = document.getElementById('fieldTodayAttendanceCard');
    const tbody = document.getElementById('fieldAttendanceTableBody');

    if (assignDetails) {
        assignDetails.innerHTML = '<div class="spinner"></div> Memeriksa penugasan lapangan...';
    }

    try {
        // 1. Fetch Today's status & assignment
        const statusRes = await apiFetch('/api/v1/field-attendance/status-today');
        const data = statusRes.data || statusRes;

        currentFieldAssignment = data.assignment;
        todayAttendanceData = data.attendance;

        if (currentFieldAssignment) {
            const loc = currentFieldAssignment.field_location;
            if (assignBadge) {
                assignBadge.className = 'status-badge status-active';
                assignBadge.innerText = 'PENUGASAN AKTIF';
            }
            if (assignDetails) {
                assignDetails.innerHTML = `
                    <div style="font-weight: 700; font-size: 1.05rem; color: #fff; margin-bottom: 0.25rem;">
                        ${escapeHtml(loc?.name || 'Lokasi Lapangan')}
                    </div>
                    <div style="font-size: 0.8rem; color: var(--primary); margin-bottom: 0.5rem;">
                        Proyek: ${escapeHtml(loc?.project_name || '-')} • Klien: ${escapeHtml(loc?.client_name || '-')}
                    </div>
                    <div style="font-size: 0.8rem; color: var(--text-muted); margin-bottom: 0.5rem;">
                        📍 ${escapeHtml(loc?.site_address || 'Alamat tidak ditentukan')}
                    </div>
                    <div style="display: flex; gap: 0.5rem; flex-wrap: wrap;">
                        <span class="status-badge" style="background: rgba(56, 189, 248, 0.15); color: var(--primary);">
                            Radius Geofence: ${loc?.radius_meters || 100} m
                        </span>
                        <span class="status-badge" style="background: rgba(148, 163, 184, 0.15); color: var(--text-muted);">
                            Masa Tugas: ${escapeHtml(currentFieldAssignment.start_date || '')} s/d ${escapeHtml(currentFieldAssignment.end_date || '')}
                        </span>
                    </div>
                `;
            }
        } else {
            if (assignBadge) {
                assignBadge.className = 'status-badge status-inactive';
                assignBadge.innerText = 'TIDAK ADA PENUGASAN';
            }
            if (assignDetails) {
                assignDetails.innerHTML = `
                    <div style="color: var(--text-muted); font-size: 0.85rem; padding: 0.5rem 0;">
                        Anda belum memiliki penugasan lapangan aktif untuk hari ini. Hubungi HRD atau Supervisor untuk penerbitan surat tugas lapangan.
                    </div>
                `;
            }
        }

        // 2. Render Today's Attendance State Card
        if (todayCard) {
            if (todayAttendanceData && (todayAttendanceData.clock_in_at || todayAttendanceData.clock_out_at)) {
                todayCard.style.display = 'block';
                const inTime = todayAttendanceData.clock_in_at ? todayAttendanceData.clock_in_at.substring(11, 16) : '-';
                const outTime = todayAttendanceData.clock_out_at ? todayAttendanceData.clock_out_at.substring(11, 16) : 'Belum Check-Out';
                const duration = todayAttendanceData.effective_work_minutes ? `${Math.floor(todayAttendanceData.effective_work_minutes / 60)}j ${todayAttendanceData.effective_work_minutes % 60}m` : '-';

                let badge = '<span class="status-badge status-active">HADIR</span>';
                if (todayAttendanceData.status === 'LATE') {
                    badge = `<span class="status-badge status-warning">TERLAMBAT (+${todayAttendanceData.late_minutes}m)</span>`;
                }

                todayCard.innerHTML = `
                    <div style="display: flex; justify-content: space-between; align-items: center; flex-wrap: wrap; gap: 0.5rem;">
                        <div>
                            <div style="font-size: 0.8rem; font-weight: 600; color: var(--text-muted); text-transform: uppercase;">
                                Ringkasan Kehadiran Lapangan Hari Ini (${escapeHtml(data.date)})
                            </div>
                            <div style="font-size: 1.1rem; font-weight: 700; color: #fff; margin-top: 0.25rem;">
                                Check-In: <span style="color: var(--success);">${inTime}</span> • Check-Out: <span style="color: #818cf8;">${outTime}</span>
                            </div>
                        </div>
                        <div style="display: flex; gap: 0.5rem; align-items: center;">
                            <span style="font-size: 0.85rem; color: var(--text-muted);">Durasi Kerja: <strong>${duration}</strong></span>
                            ${badge}
                        </div>
                    </div>
                `;
            } else {
                todayCard.style.display = 'none';
            }
        }

        // 3. Fetch Evidence Records
        if (tbody) {
            tbody.innerHTML = '<tr><td colspan="9" class="loading-td"><div class="spinner"></div> Memuat riwayat...</td></tr>';
            const recRes = await apiFetch('/api/v1/field-attendance/records');
            const records = recRes.data?.data || recRes.data || [];

            if (!records.length) {
                tbody.innerHTML = '<tr><td colspan="9" class="empty-td" style="text-align:center; padding: 2rem; color: var(--text-muted);">Belum ada bukti presensi lapangan yang tercatat.</td></tr>';
                return;
            }

            const canManage = window.APP_CONFIG?.admin?.role === 'hrd' || window.APP_CONFIG?.admin?.role === 'super_admin';

            tbody.innerHTML = records.map(ev => {
                const empName = ev.employee ? ev.employee.name : '-';
                const locName = ev.field_location ? ev.field_location.name : '-';
                const dateStr = ev.attendance_date ? ev.attendance_date.substring(0, 10) : '';
                const timeStr = ev.captured_at ? ev.captured_at.substring(11, 16) : '';

                let typeBadge = ev.type === 'CHECK_IN'
                    ? '<span class="status-badge" style="background:rgba(16,185,129,0.15);color:#10b981;">CHECK-IN</span>'
                    : '<span class="status-badge" style="background:rgba(99,102,241,0.15);color:#818cf8;">CHECK-OUT</span>';

                let geoBadge = '';
                switch (ev.geofence_result) {
                    case 'VALID':
                        geoBadge = '<span class="status-badge status-active">VALID</span>';
                        break;
                    case 'OUTSIDE_GEOFENCE':
                        geoBadge = '<span class="status-badge status-inactive">LUAR RADIUS</span>';
                        break;
                    case 'LOW_ACCURACY':
                        geoBadge = '<span class="status-badge status-warning">AKURASI RENDAH</span>';
                        break;
                    default:
                        geoBadge = `<span class="status-badge">${escapeHtml(ev.geofence_result)}</span>`;
                }

                let statusBadge = ev.is_override
                    ? '<span class="status-badge" style="background:rgba(245,158,11,0.2);color:#f59e0b;" title="' + escapeHtml(ev.override_reason || '') + '">OVERRIDE HRD</span>'
                    : (ev.geofence_result === 'VALID' ? '<span class="status-badge status-active">TERVERIFIKASI</span>' : '<span class="status-badge status-inactive">TERTUNDA</span>');

                let actionBtns = `
                    <button class="btn-action btn-ping" style="padding: 0.3rem 0.6rem; font-size: 0.75rem;" onclick="viewFieldPhoto(${ev.id})">
                        📷 Foto
                    </button>
                `;

                if (canManage && !ev.is_override && ev.geofence_result !== 'VALID') {
                    actionBtns += `
                        <button class="btn-action btn-override" style="padding: 0.3rem 0.6rem; font-size: 0.75rem; margin-left: 0.25rem;" onclick="openFieldOverrideModal(${ev.id})">
                            ⚖️ Override
                        </button>
                    `;
                }

                return `
                    <tr>
                        <td><strong>${escapeHtml(dateStr)}</strong> ${escapeHtml(timeStr)}</td>
                        <td>${escapeHtml(empName)}</td>
                        <td>${escapeHtml(locName)}</td>
                        <td>${typeBadge}</td>
                        <td>${ev.distance_meters != null ? Math.round(ev.distance_meters) + ' m' : '-'}</td>
                        <td>±${ev.accuracy_meters != null ? Math.round(ev.accuracy_meters) + ' m' : '-'}</td>
                        <td>${geoBadge}</td>
                        <td>${statusBadge}</td>
                        <td>${actionBtns}</td>
                    </tr>
                `;
            }).join('');
        }

        updateFieldActionButtons();
    } catch (e) {
        console.error('Error loading field attendance', e);
        if (assignDetails) {
            assignDetails.innerHTML = `<div style="color:var(--danger);font-size:0.85rem;">Gagal memuat data: ${escapeHtml(e.message)}</div>`;
        }
    }
}

function acquireFieldGps() {
    const badge = document.getElementById('fieldGpsStatusBadge');
    const details = document.getElementById('fieldGpsDetails');
    const btn = document.getElementById('btnAcquireGps');

    if (!navigator.geolocation) {
        showToast('Browser Anda tidak mendukung Geolocation API.', 'error');
        if (badge) { badge.className = 'status-badge status-inactive'; badge.innerText = 'UNAVAILABLE'; }
        return;
    }

    if (badge) {
        badge.className = 'status-badge status-info';
        badge.innerText = 'GPS ACQUIRING...';
    }
    if (btn) { btn.disabled = true; btn.innerText = '⏳ Mendeteksi Satelit...'; }

    navigator.geolocation.getCurrentPosition(
        pos => {
            currentGpsCoords = {
                latitude: pos.coords.latitude,
                longitude: pos.coords.longitude,
                accuracy: pos.coords.accuracy,
                timestamp: new Date().toISOString()
            };

            const lat = currentGpsCoords.latitude.toFixed(6);
            const lon = currentGpsCoords.longitude.toFixed(6);
            const acc = Math.round(currentGpsCoords.accuracy);

            let distanceMeters = null;
            let isInside = false;

            if (currentFieldAssignment?.field_location) {
                const targetLat = currentFieldAssignment.field_location.latitude;
                const targetLon = currentFieldAssignment.field_location.longitude;
                const radius = currentFieldAssignment.field_location.radius_meters || 100;

                // Client-side Haversine estimate
                const R = 6371000;
                const dLat = (targetLat - currentGpsCoords.latitude) * Math.PI / 180;
                const dLon = (targetLon - currentGpsCoords.longitude) * Math.PI / 180;
                const a = Math.sin(dLat/2) * Math.sin(dLat/2) +
                          Math.cos(currentGpsCoords.latitude * Math.PI / 180) * Math.cos(targetLat * Math.PI / 180) *
                          Math.sin(dLon/2) * Math.sin(dLon/2);
                const c = 2 * Math.atan2(Math.sqrt(a), Math.sqrt(1-a));
                distanceMeters = Math.round(R * c);
                isInside = (distanceMeters <= radius);
            }

            if (acc > 50) {
                if (badge) { badge.className = 'status-badge status-warning'; badge.innerText = 'LOW ACCURACY'; }
            } else if (distanceMeters !== null && !isInside) {
                if (badge) { badge.className = 'status-badge status-inactive'; badge.innerText = 'OUTSIDE GEOFENCE'; }
            } else {
                if (badge) { badge.className = 'status-badge status-active'; badge.innerText = 'GPS READY'; }
            }

            if (details) {
                details.innerHTML = `
                    <div style="color: #fff; font-weight: 600;">Koordinat Terkunci:</div>
                    <div>Lat: <code>${lat}</code>, Lon: <code>${lon}</code></div>
                    <div>Akurasi GPS: <strong style="color:${acc <= 50 ? 'var(--success)' : '#f59e0b'}">±${acc} meter</strong></div>
                    ${distanceMeters !== null ? `<div>Jarak ke Pusat Proyek: <strong style="color:${isInside ? 'var(--success)' : '#ef4444'}">${distanceMeters} meter</strong> (Radius: ${currentFieldAssignment.field_location.radius_meters}m)</div>` : ''}
                `;
            }

            if (btn) { btn.disabled = false; btn.innerText = '🔄 Perbarui Titik GPS'; }
            showToast('Posisi GPS berhasil dideteksi', 'info');
            updateFieldActionButtons();
        },
        err => {
            console.error('Geolocation error', err);
            if (badge) { badge.className = 'status-badge status-inactive'; badge.innerText = 'ERROR'; }
            if (details) {
                details.innerHTML = `<span style="color:var(--danger);">Gagal mendapatkan GPS: ${escapeHtml(err.message)}. Pastikan izin lokasi aktif pada browser.</span>`;
            }
            if (btn) { btn.disabled = false; btn.innerText = '📡 Coba Lagi Ambil GPS'; }
            showToast('Gagal memperoleh titik GPS: ' + err.message, 'error');
            updateFieldActionButtons();
        },
        { enableHighAccuracy: true, timeout: 15000, maximumAge: 0 }
    );
}

function handleFieldPhotoSelected(e) {
    const file = e.target.files?.[0];
    const previewBox = document.getElementById('fieldPhotoPreviewBox');
    const previewImg = document.getElementById('fieldPhotoPreviewImg');
    const placeholder = document.getElementById('fieldPhotoPlaceholderText');
    const badge = document.getElementById('fieldPhotoStatusBadge');

    if (!file) {
        currentFieldPhotoFile = null;
        if (badge) { badge.className = 'status-badge status-warning'; badge.innerText = 'FOTO DIPERLUKAN'; }
        if (previewImg) previewImg.style.display = 'none';
        if (placeholder) placeholder.style.display = 'block';
        updateFieldActionButtons();
        return;
    }

    if (file.size > 5 * 1024 * 1024) {
        showToast('Ukuran foto melebihi batas maksimal 5 MB.', 'error');
        e.target.value = '';
        currentFieldPhotoFile = null;
        updateFieldActionButtons();
        return;
    }

    currentFieldPhotoFile = file;
    if (badge) { badge.className = 'status-badge status-active'; badge.innerText = 'FOTO SIAP'; }

    const reader = new FileReader();
    reader.onload = evt => {
        if (previewImg) {
            previewImg.src = evt.target.result;
            previewImg.style.display = 'block';
        }
        if (placeholder) {
            placeholder.style.display = 'none';
        }
    };
    reader.readAsDataURL(file);

    showToast('Foto bukti berhasil dipilih', 'success');
    updateFieldActionButtons();
}

function updateFieldActionButtons() {
    const btnIn = document.getElementById('btnFieldCheckIn');
    const btnOut = document.getElementById('btnFieldCheckOut');

    const hasGps = currentGpsCoords !== null;
    const hasPhoto = currentFieldPhotoFile !== null;
    const hasAssignment = currentFieldAssignment !== null;

    const canSubmit = hasGps && hasPhoto && hasAssignment && !isSubmittingFieldAttendance;

    const hasCheckedInToday = todayAttendanceData && todayAttendanceData.clock_in_at;
    const hasCheckedOutToday = todayAttendanceData && todayAttendanceData.clock_out_at;

    if (btnIn) {
        btnIn.disabled = !canSubmit || !!hasCheckedInToday;
        if (hasCheckedInToday) {
            btnIn.title = 'Anda sudah melakukan check-in hari ini.';
        }
    }

    if (btnOut) {
        btnOut.disabled = !canSubmit || !hasCheckedInToday || !!hasCheckedOutToday;
        if (!hasCheckedInToday) {
            btnOut.title = 'Lakukan check-in terlebih dahulu.';
        } else if (hasCheckedOutToday) {
            btnOut.title = 'Anda sudah melakukan check-out hari ini.';
        }
    }
}

async function submitFieldAttendance(type) {
    if (isSubmittingFieldAttendance) return;

    if (!currentGpsCoords) {
        showToast('Ambil titik GPS Anda terlebih dahulu.', 'warning');
        return;
    }
    if (!currentFieldPhotoFile) {
        showToast('Ambil atau pilih foto bukti kehadiran terlebih dahulu.', 'warning');
        return;
    }

    isSubmittingFieldAttendance = true;
    updateFieldActionButtons();

    const btnIn = document.getElementById('btnFieldCheckIn');
    const btnOut = document.getElementById('btnFieldCheckOut');
    const notesInput = document.getElementById('fieldAttendanceNotes');

    const activeBtn = type === 'CHECK_IN' ? btnIn : btnOut;
    const origText = activeBtn ? activeBtn.innerText : '';
    if (activeBtn) activeBtn.innerText = '⏳ Mengirim...';

    const formData = new FormData();
    formData.append('latitude', currentGpsCoords.latitude);
    formData.append('longitude', currentGpsCoords.longitude);
    formData.append('accuracy_meters', currentGpsCoords.accuracy);
    formData.append('captured_at', currentGpsCoords.timestamp || new Date().toISOString());
    formData.append('photo', currentFieldPhotoFile);
    if (notesInput && notesInput.value.trim()) {
        formData.append('notes', notesInput.value.trim());
    }

    const endpoint = type === 'CHECK_IN'
        ? '/api/v1/field-attendance/check-in'
        : '/api/v1/field-attendance/check-out';

    try {
        const csrfToken = document.querySelector('meta[name="csrf-token"]')?.getAttribute('content') || '';
        const appToken = window.APP_CONFIG?.apiToken || sessionStorage.getItem('api_token') || '';

        const headers = {
            'Accept': 'application/json',
            'X-CSRF-TOKEN': csrfToken,
        };
        if (appToken) headers['Authorization'] = `Bearer ${appToken}`;

        const res = await fetch(endpoint, {
            method: 'POST',
            headers: headers,
            body: formData
        });

        const data = await res.json();

        if (res.ok) {
            showToast(data.message || 'Presensi lapangan berhasil dicatat!', 'success');

            // Reset photo
            currentFieldPhotoFile = null;
            const photoInput = document.getElementById('fieldPhotoInput');
            if (photoInput) photoInput.value = '';
            const previewImg = document.getElementById('fieldPhotoPreviewImg');
            if (previewImg) previewImg.style.display = 'none';
            const placeholder = document.getElementById('fieldPhotoPlaceholderText');
            if (placeholder) placeholder.style.display = 'block';
            const photoBadge = document.getElementById('fieldPhotoStatusBadge');
            if (photoBadge) { photoBadge.className = 'status-badge status-warning'; photoBadge.innerText = 'FOTO DIPERLUKAN'; }

            if (notesInput) notesInput.value = '';

            await loadFieldAttendanceData();
        } else {
            showToast(data.message || 'Presensi lapangan gagal disimpan.', 'error');
        }
    } catch (e) {
        showToast('Terjadi kesalahan pengiriman: ' + e.message, 'error');
    } finally {
        isSubmittingFieldAttendance = false;
        if (activeBtn) activeBtn.innerText = origText;
        updateFieldActionButtons();
    }
}

async function viewFieldPhoto(evidenceId) {
    const modal = document.getElementById('fieldPhotoModal');
    const body = document.getElementById('fieldPhotoModalBody');
    if (!modal || !body) return;

    body.innerHTML = '<div class="spinner"></div> Mengambil foto secara terotorisasi...';
    modal.classList.add('active');

    try {
        const appToken = window.APP_CONFIG?.apiToken || sessionStorage.getItem('api_token') || '';
        const headers = appToken ? { 'Authorization': `Bearer ${appToken}` } : {};

        const res = await fetch(`/api/v1/field-attendance/records/${evidenceId}/photo`, { headers });
        if (!res.ok) {
            const err = await res.json().catch(() => ({}));
            body.innerHTML = `<div style="color:var(--danger);padding:1rem;">Gagal memuat foto: ${escapeHtml(err.message || 'Akses ditolak.')}</div>`;
            return;
        }

        const blob = await res.blob();
        const objUrl = URL.createObjectURL(blob);

        body.innerHTML = `
            <img src="${objUrl}" style="max-width: 100%; max-height: 420px; border-radius: 0.75rem; box-shadow: 0 4px 20px rgba(0,0,0,0.5); object-fit: contain;" />
            <div style="margin-top: 0.75rem; font-size: 0.8rem; color: var(--text-muted);">
                Foto bukti kehadiran terenkripsi lokal • ID Rekaman #${evidenceId}
            </div>
        `;
    } catch (e) {
        body.innerHTML = `<div style="color:var(--danger);padding:1rem;">Kesalahan koneksi: ${escapeHtml(e.message)}</div>`;
    }
}

function openFieldOverrideModal(evidenceId) {
    const modal = document.getElementById('fieldOverrideModal');
    const idInput = document.getElementById('overrideEvidenceId');
    const reasonInput = document.getElementById('overrideReasonInput');

    if (idInput) idInput.value = evidenceId;
    if (reasonInput) reasonInput.value = '';
    if (modal) modal.classList.add('active');
}

async function submitFieldOverride(e) {
    e.preventDefault();
    const evidenceId = document.getElementById('overrideEvidenceId')?.value;
    const reason = document.getElementById('overrideReasonInput')?.value?.trim();
    const btn = document.getElementById('btnSubmitOverride');

    if (!evidenceId || !reason) {
        showToast('Alasan override wajib diisi.', 'warning');
        return;
    }

    const origText = btn ? btn.innerText : '';
    if (btn) { btn.disabled = true; btn.innerText = 'Menyimpan...'; }

    try {
        const res = await apiFetch(`/api/v1/field-attendance/records/${evidenceId}/override`, {
            method: 'POST',
            body: JSON.stringify({ reason })
        });

        if (res.success || res.message) {
            showToast(res.message || 'Override berhasil diterapkan.', 'success');
            closeModal('fieldOverrideModal');
            await loadFieldAttendanceData();
        }
    } catch (err) {
        showToast('Gagal override: ' + err.message, 'error');
    } finally {
        if (btn) { btn.disabled = false; btn.innerText = origText; }
    }
}

// =========================================================================
// SPRINT 11: ATTENDANCE REQUESTS (WFH, LEAVE, PERMISSION, SICK)
// =========================================================================

let attendanceRequestsCache = [];

async function loadAttendanceRequestsData() {
    const tbody = document.getElementById('attendanceRequestsTableBody');
    if (!tbody) return;

    tbody.innerHTML = '<tr><td colspan="9" class="loading-td"><div class="spinner"></div> Memuat daftar pengajuan absensi...</td></tr>';

    try {
        // 1. Fetch Metrics
        const metricsRes = await apiFetch('/api/v1/attendance-requests/metrics').catch(() => null);
        if (metricsRes && metricsRes.data) {
            const m = metricsRes.data;
            const pEl = document.getElementById('reqMetricPending');
            const aEl = document.getElementById('reqMetricApproved');
            const rEl = document.getElementById('reqMetricRejected');
            const wEl = document.getElementById('reqMetricWfh');
            const lsEl = document.getElementById('reqMetricLeaveSick');

            if (pEl) pEl.innerText = m.submitted || 0;
            if (aEl) aEl.innerText = m.approved || 0;
            if (rEl) rEl.innerText = m.rejected || 0;
            if (wEl) wEl.innerText = m.wfh_count || 0;
            if (lsEl) lsEl.innerText = (m.leave_count || 0) + (m.sick_count || 0);
        }

        // 2. Fetch Requests
        const type = document.getElementById('reqFilterType')?.value || '';
        const status = document.getElementById('reqFilterStatus')?.value || '';
        const fromDate = document.getElementById('reqFilterFrom')?.value || '';
        const toDate = document.getElementById('reqFilterTo')?.value || '';

        let url = '/api/v1/attendance-requests?per_page=50';
        if (type) url += `&request_type=${encodeURIComponent(type)}`;
        if (status) url += `&status=${encodeURIComponent(status)}`;
        if (fromDate) url += `&from_date=${encodeURIComponent(fromDate)}`;
        if (toDate) url += `&to_date=${encodeURIComponent(toDate)}`;

        const res = await apiFetch(url);
        const items = res.data || [];
        attendanceRequestsCache = items;

        if (items.length === 0) {
            tbody.innerHTML = '<tr><td colspan="9" style="text-align: center; color: var(--text-muted); padding: 2rem;">Belum ada data pengajuan absensi untuk filter ini.</td></tr>';
            return;
        }

        const currentAdminId = window.APP_CONFIG?.adminId || null;
        const currentEmpId = window.APP_CONFIG?.employeeId || null;
        const currentRole = (window.APP_CONFIG?.role || '').toLowerCase();
        const canManage = ['super_admin', 'hrd', 'management', 'supervisor'].includes(currentRole);

        tbody.innerHTML = items.map(req => {
            const empName = req.employee ? req.employee.name : `Emp #${req.employee_id}`;
            const empDept = req.employee?.employee_id ? `(${req.employee.employee_id})` : '';

            // Status badge
            let badgeClass = 'status-badge';
            let badgeLabel = req.status;
            if (req.status === 'SUBMITTED') {
                badgeClass += ' status-pending';
                badgeLabel = 'Menunggu';
            } else if (req.status === 'APPROVED') {
                badgeClass += ' status-active';
                badgeLabel = 'Disetujui';
            } else if (req.status === 'REJECTED') {
                badgeClass += ' status-expired';
                badgeLabel = 'Ditolak';
            } else if (req.status === 'CANCELLED') {
                badgeClass += ' status-badge';
                badgeLabel = 'Dibatalkan';
            }

            // Type badge
            const typeColor = {
                'WFH': '#3b82f6',
                'LEAVE': '#8b5cf6',
                'PERMISSION': '#f59e0b',
                'SICK': '#ef4444'
            }[req.request_type] || '#10b981';

            const period = (req.start_date === req.end_date)
                ? req.start_date
                : `${req.start_date} s/d ${req.end_date}`;

            const timeInfo = (req.start_time || req.end_time)
                ? `${req.start_time || ''} - ${req.end_time || ''}`
                : (req.category || '-');

            // Attachment link
            const docLink = req.attachment_path
                ? `<a href="/api/v1/attendance-requests/${req.id}/attachment" target="_blank" class="btn-table-action" title="Unduh Dokumen">📎 Berkas</a>`
                : '-';

            // Actions
            let actions = [];
            const isSelf = currentEmpId && (parseInt(currentEmpId, 10) === parseInt(req.employee_id, 10));
            if (req.status === 'SUBMITTED' && canManage && !isSelf) {
                actions.push(`<button class="btn-table-action" style="color: var(--success);" onclick="approveAttendanceRequest(${req.id})">✓ Setujui</button>`);
                actions.push(`<button class="btn-table-action" style="color: var(--danger);" onclick="openRejectAttendanceRequestModal(${req.id})">✕ Tolak</button>`);
            }

            if ((req.status === 'SUBMITTED' && isSelf) || (['super_admin', 'hrd'].includes(currentRole) && ['SUBMITTED', 'APPROVED'].includes(req.status))) {
                actions.push(`<button class="btn-table-action" style="color: var(--text-muted);" onclick="cancelAttendanceRequest(${req.id})">Batal</button>`);
            }

            return `
                <tr>
                    <td>#${req.id}</td>
                    <td><strong>${escapeHtml(empName)}</strong> <small style="color:var(--text-muted);">${escapeHtml(empDept)}</small></td>
                    <td><span class="status-badge" style="background: ${typeColor}22; color: ${typeColor}; border: 1px solid ${typeColor}66;">${req.request_type}</span></td>
                    <td>${period}</td>
                    <td><small>${escapeHtml(timeInfo)}</small></td>
                    <td><small title="${escapeHtml(req.reason)}">${escapeHtml(req.reason.length > 30 ? req.reason.substring(0, 30) + '...' : req.reason)}</small></td>
                    <td>${docLink}</td>
                    <td><span class="${badgeClass}">${badgeLabel}</span></td>
                    <td><div style="display: flex; gap: 0.25rem;">${actions.join('') || '-'}</div></td>
                </tr>
            `;
        }).join('');
    } catch (err) {
        tbody.innerHTML = `<tr><td colspan="9" style="text-align: center; color: var(--danger); padding: 2rem;">Gagal memuat permohonan: ${escapeHtml(err.message)}</td></tr>`;
    }
}

function onReqTypeChanged() {
    const type = document.getElementById('newReqType')?.value;
    const timeRow = document.getElementById('newReqTimeRow');
    const catRow = document.getElementById('newReqCategoryRow');
    const attRow = document.getElementById('newReqAttachmentRow');

    if (timeRow) {
        timeRow.style.display = (type === 'PERMISSION') ? 'flex' : 'none';
    }
    if (catRow) {
        catRow.style.display = (type === 'LEAVE' || type === 'PERMISSION') ? 'block' : 'none';
    }
    if (attRow) {
        attRow.style.display = (type === 'SICK' || type === 'LEAVE') ? 'block' : 'none';
    }
}

function openNewAttendanceRequestModal() {
    const form = document.getElementById('newAttendanceRequestForm');
    if (form) form.reset();

    const today = new Date().toISOString().split('T')[0];
    const startEl = document.getElementById('newReqStartDate');
    const endEl = document.getElementById('newReqEndDate');
    if (startEl) startEl.value = today;
    if (endEl) endEl.value = today;

    onReqTypeChanged();
    const modal = document.getElementById('newAttendanceRequestModal');
    if (modal) modal.classList.add('active');
}

async function submitNewAttendanceRequest(e) {
    e.preventDefault();
    const btn = document.getElementById('btnSubmitNewReq');
    const origText = btn ? btn.innerText : '';
    if (btn) { btn.disabled = true; btn.innerText = 'Mengirim...'; }

    const form = document.getElementById('newAttendanceRequestForm');
    const formData = new FormData();

    formData.append('request_type', document.getElementById('newReqType')?.value || '');
    formData.append('start_date', document.getElementById('newReqStartDate')?.value || '');
    formData.append('end_date', document.getElementById('newReqEndDate')?.value || '');
    formData.append('reason', document.getElementById('newReqReason')?.value || '');

    const cat = document.getElementById('newReqCategory')?.value;
    if (cat) formData.append('category', cat);

    const startTime = document.getElementById('newReqStartTime')?.value;
    if (startTime) formData.append('start_time', startTime);

    const endTime = document.getElementById('newReqEndTime')?.value;
    if (endTime) formData.append('end_time', endTime);

    const attFile = document.getElementById('newReqAttachment')?.files[0];
    if (attFile) formData.append('attachment', attFile);

    try {
        const csrfToken = document.querySelector('meta[name="csrf-token"]')?.getAttribute('content') || '';
        const appToken = window.APP_CONFIG?.apiToken || sessionStorage.getItem('api_token') || '';

        const headers = {
            'X-CSRF-TOKEN': csrfToken,
            'Accept': 'application/json',
            'X-Requested-With': 'XMLHttpRequest'
        };
        if (appToken) headers['Authorization'] = `Bearer ${appToken}`;

        const res = await fetch('/api/v1/attendance-requests', {
            method: 'POST',
            headers: headers,
            body: formData
        });

        const data = await res.json();
        if (res.ok && data.success) {
            showToast(data.message || 'Permohonan absensi berhasil dikirim.', 'success');
            closeModal('newAttendanceRequestModal');
            await loadAttendanceRequestsData();
        } else {
            showToast(data.message || (data.errors ? Object.values(data.errors).flat().join(', ') : 'Gagal mengirim permohonan.'), 'error');
        }
    } catch (err) {
        showToast('Kesalahan koneksi: ' + err.message, 'error');
    } finally {
        if (btn) { btn.disabled = false; btn.innerText = origText; }
    }
}

async function approveAttendanceRequest(id) {
    if (!confirm('Konfirmasi: Setujui permohonan absensi ini?')) return;

    try {
        const res = await apiFetch(`/api/v1/attendance-requests/${id}/approve`, {
            method: 'POST'
        });
        if (res.success) {
            showToast(res.message || 'Permohonan berhasil disetujui.', 'success');
            await loadAttendanceRequestsData();
        }
    } catch (err) {
        showToast('Gagal menyetujui: ' + err.message, 'error');
    }
}

function openRejectAttendanceRequestModal(id) {
    const idInput = document.getElementById('rejectReqId');
    const reasonInput = document.getElementById('rejectReasonInput');
    if (idInput) idInput.value = id;
    if (reasonInput) reasonInput.value = '';

    const modal = document.getElementById('rejectAttendanceRequestModal');
    if (modal) modal.classList.add('active');
}

async function submitRejectAttendanceRequest(e) {
    e.preventDefault();
    const id = document.getElementById('rejectReqId')?.value;
    const reason = document.getElementById('rejectReasonInput')?.value?.trim();
    const btn = document.getElementById('btnSubmitRejectReq');

    if (!id || !reason) {
        showToast('Alasan penolakan wajib diisi.', 'warning');
        return;
    }

    const origText = btn ? btn.innerText : '';
    if (btn) { btn.disabled = true; btn.innerText = 'Menyimpan...'; }

    try {
        const res = await apiFetch(`/api/v1/attendance-requests/${id}/reject`, {
            method: 'POST',
            body: JSON.stringify({ reason })
        });
        if (res.success) {
            showToast(res.message || 'Permohonan berhasil ditolak.', 'success');
            closeModal('rejectAttendanceRequestModal');
            await loadAttendanceRequestsData();
        }
    } catch (err) {
        showToast('Gagal menolak: ' + err.message, 'error');
    } finally {
        if (btn) { btn.disabled = false; btn.innerText = origText; }
    }
}

async function cancelAttendanceRequest(id) {
    const reason = prompt('Alasan pembatalan (opsional):');
    if (reason === null) return; // cancelled prompt

    try {
        const res = await apiFetch(`/api/v1/attendance-requests/${id}/cancel`, {
            method: 'POST',
            body: JSON.stringify({ reason })
        });
        if (res.success) {
            showToast(res.message || 'Permohonan berhasil dibatalkan.', 'success');
            await loadAttendanceRequestsData();
        }
    } catch (err) {
        showToast('Gagal membatalkan: ' + err.message, 'error');
    }
}

window.loadAttendanceRequestsData = loadAttendanceRequestsData;
window.openNewAttendanceRequestModal = openNewAttendanceRequestModal;
window.onReqTypeChanged = onReqTypeChanged;
window.submitNewAttendanceRequest = submitNewAttendanceRequest;
window.approveAttendanceRequest = approveAttendanceRequest;
window.openRejectAttendanceRequestModal = openRejectAttendanceRequestModal;
window.submitRejectAttendanceRequest = submitRejectAttendanceRequest;
window.cancelAttendanceRequest = cancelAttendanceRequest;

// =========================================================================
// SPRINT 12: ATTENDANCE CORRECTIONS (CLIENT CONTROLLER)
// =========================================================================
async function loadAttendanceCorrectionsData() {
    const tbody = document.getElementById('attendanceCorrectionsTableBody');
    if (!tbody) return;

    tbody.innerHTML = `<tr><td colspan="10" class="loading-td"><div class="spinner"></div> Memuat data koreksi presensi...</td></tr>`;

    // 1. Load metrics
    try {
        const mRes = await apiFetch('/api/v1/attendance-corrections/metrics');
        if (mRes.success && mRes.data) {
            const d = mRes.data;
            const setVal = (id, v) => { const el = document.getElementById(id); if (el) el.innerText = v; };
            setVal('corrMetricPending', d.submitted || 0);
            setVal('corrMetricApproved', d.approved || 0);
            setVal('corrMetricRejected', d.rejected || 0);
            setVal('corrMetricCancelled', d.cancelled || 0);
        }
    } catch (e) {
        console.warn('Failed to load correction metrics:', e);
    }

    // 2. Load list
    const type = document.getElementById('corrFilterType')?.value || '';
    const status = document.getElementById('corrFilterStatus')?.value || '';
    const from = document.getElementById('corrFilterFrom')?.value || '';
    const to = document.getElementById('corrFilterTo')?.value || '';

    const params = new URLSearchParams();
    if (type) params.append('request_type', type);
    if (status) params.append('status', status);
    if (from) params.append('from_date', from);
    if (to) params.append('to_date', to);

    try {
        const res = await apiFetch('/api/v1/attendance-corrections?' + params.toString());
        if (!res.success || !res.data || res.data.length === 0) {
            tbody.innerHTML = `<tr><td colspan="10" style="text-align: center; padding: 2rem; color: var(--text-muted);">Tidak ada pengajuan koreksi presensi yang sesuai.</td></tr>`;
            return;
        }

        const role = (window.currentUserRole || '').toLowerCase();
        const canApprove = ['super_admin', 'hrd', 'management', 'supervisor'].includes(role);

        tbody.innerHTML = res.data.map(item => {
            const empName = item.employee?.name || `Karyawan #${item.employee_id}`;
            const corrDate = item.correction_date ? item.correction_date.substring(0, 10) : '-';

            // Original snapshot
            const origIn = item.original_check_in ? item.original_check_in.substring(11, 16) : '-';
            const origOut = item.original_check_out ? item.original_check_out.substring(11, 16) : '-';
            const origStat = item.original_status || '-';
            const origSummary = `<span style="font-size: 0.75rem; color: var(--text-muted);">${origIn} - ${origOut} [${origStat}]</span>`;

            // Requested snapshot
            const reqIn = item.requested_check_in ? item.requested_check_in.substring(11, 16) : origIn;
            const reqOut = item.requested_check_out ? item.requested_check_out.substring(11, 16) : origOut;
            const reqStat = item.requested_status || (item.status === 'APPROVED' ? item.corrected_status : '-');
            const reqSummary = `<span style="font-size: 0.8rem; font-weight: 600; color: #fff;">${reqIn} - ${reqOut}</span>` + (reqStat !== '-' ? ` <span class="badge" style="font-size: 0.65rem; background: var(--border-color);">${reqStat}</span>` : '');

            // Status Badge
            let statusBadge = `<span class="badge badge-warning">SUBMITTED</span>`;
            if (item.status === 'APPROVED') statusBadge = `<span class="badge badge-success">APPROVED</span>`;
            if (item.status === 'REJECTED') statusBadge = `<span class="badge badge-danger">REJECTED</span>`;
            if (item.status === 'CANCELLED') statusBadge = `<span class="badge" style="background: var(--text-muted); color: #fff;">CANCELLED</span>`;

            // Attachment link
            let docLink = `<span style="color: var(--text-muted); font-size: 0.75rem;">-</span>`;
            if (item.attachment_path) {
                docLink = `<a href="/api/v1/attendance-corrections/${item.id}/attachment" target="_blank" class="btn-secondary" style="padding: 0.25rem 0.5rem; font-size: 0.75rem; text-decoration: none;">📎 Unduh</a>`;
            }

            // Action buttons
            let actions = [];
            if (item.status === 'SUBMITTED') {
                if (canApprove) {
                    actions.push(`<button class="btn-primary" style="padding: 0.25rem 0.6rem; font-size: 0.75rem; background: var(--success); border-color: var(--success);" onclick="approveAttendanceCorrection(${item.id})">✔ Setujui</button>`);
                    actions.push(`<button class="btn-secondary" style="padding: 0.25rem 0.6rem; font-size: 0.75rem; color: var(--danger); border-color: var(--danger);" onclick="openRejectAttendanceCorrectionModal(${item.id})">✖ Tolak</button>`);
                }
                actions.push(`<button class="btn-secondary" style="padding: 0.25rem 0.5rem; font-size: 0.75rem;" onclick="cancelAttendanceCorrection(${item.id})">Batalkan</button>`);
            } else {
                actions.push(`<span style="color: var(--text-muted); font-size: 0.75rem;">Selesai</span>`);
            }

            return `
                <tr>
                    <td style="font-family: monospace; font-size: 0.8rem;">#${item.id}</td>
                    <td><strong>${escapeHtml(empName)}</strong></td>
                    <td>${corrDate}</td>
                    <td><span class="badge" style="background: var(--bg-base); border: 1px solid var(--border-color); font-size: 0.7rem;">${item.request_type}</span></td>
                    <td>${origSummary}</td>
                    <td>${reqSummary}</td>
                    <td style="max-width: 220px; font-size: 0.8rem; line-height: 1.2;">
                        ${escapeHtml(item.reason || '-')}
                        ${item.rejection_reason ? `<div style="color: var(--danger); font-size: 0.75rem; margin-top: 0.25rem;">Alasan tolak: ${escapeHtml(item.rejection_reason)}</div>` : ''}
                    </td>
                    <td>${docLink}</td>
                    <td>${statusBadge}</td>
                    <td><div style="display: flex; gap: 0.35rem; flex-wrap: wrap;">${actions.join('')}</div></td>
                </tr>
            `;
        }).join('');
    } catch (err) {
        tbody.innerHTML = `<tr><td colspan="10" style="text-align: center; color: var(--danger); padding: 2rem;">Gagal memuat koreksi presensi: ${escapeHtml(err.message)}</td></tr>`;
    }
}

function openNewAttendanceCorrectionModal() {
    const modal = document.getElementById('newAttendanceCorrectionModal');
    if (!modal) return;
    const form = document.getElementById('newAttendanceCorrectionForm');
    if (form) form.reset();

    const dateInput = document.getElementById('newCorrDate');
    if (dateInput) {
        const today = new Date().toISOString().substring(0, 10);
        dateInput.value = today;
    }
    openModal('newAttendanceCorrectionModal');
}

async function submitNewAttendanceCorrection(e) {
    e.preventDefault();
    const btn = document.getElementById('btnSubmitNewCorr');
    const origText = btn ? btn.innerText : '';
    if (btn) { btn.disabled = true; btn.innerText = 'Mengirim...'; }

    const formData = new FormData();
    formData.append('correction_date', document.getElementById('newCorrDate').value);
    formData.append('request_type', document.getElementById('newCorrType').value);

    const checkInVal = document.getElementById('newCorrCheckIn').value;
    if (checkInVal) formData.append('requested_check_in', checkInVal);

    const checkOutVal = document.getElementById('newCorrCheckOut').value;
    if (checkOutVal) formData.append('requested_check_out', checkOutVal);

    const statusVal = document.getElementById('newCorrStatus').value;
    if (statusVal) formData.append('requested_status', statusVal);

    formData.append('reason', document.getElementById('newCorrReason').value);

    const noteVal = document.getElementById('newCorrEvidenceNote').value;
    if (noteVal) formData.append('evidence_note', noteVal);

    const attInput = document.getElementById('newCorrAttachment');
    if (attInput && attInput.files && attInput.files[0]) {
        formData.append('attachment', attInput.files[0]);
    }

    try {
        const res = await apiFetchForm('/api/v1/attendance-corrections', formData);
        if (res.success) {
            showToast(res.message || 'Pengajuan koreksi presensi berhasil dibuat.', 'success');
            closeModal('newAttendanceCorrectionModal');
            await loadAttendanceCorrectionsData();
        }
    } catch (err) {
        showToast('Gagal mengajukan koreksi: ' + err.message, 'error');
    } finally {
        if (btn) { btn.disabled = false; btn.innerText = origText; }
    }
}

async function approveAttendanceCorrection(id) {
    if (!confirm(`Setujui pengajuan koreksi presensi #${id}? Perubahan akan langsung diaplikasikan pada rekap presensi harian.`)) return;

    try {
        const res = await apiFetch(`/api/v1/attendance-corrections/${id}/approve`, { method: 'POST' });
        if (res.success) {
            showToast(res.message || 'Koreksi presensi berhasil disetujui.', 'success');
            await loadAttendanceCorrectionsData();
        }
    } catch (err) {
        showToast('Gagal menyetujui koreksi: ' + err.message, 'error');
    }
}

function openRejectAttendanceCorrectionModal(id) {
    const modal = document.getElementById('rejectAttendanceCorrectionModal');
    if (!modal) return;
    document.getElementById('rejectCorrId').value = id;
    document.getElementById('rejectCorrReasonInput').value = '';
    openModal('rejectAttendanceCorrectionModal');
}

async function submitRejectAttendanceCorrection(e) {
    e.preventDefault();
    const id = document.getElementById('rejectCorrId').value;
    const reason = document.getElementById('rejectCorrReasonInput').value;
    const btn = document.getElementById('btnSubmitRejectCorr');

    if (!reason || reason.trim().length < 3) {
        showToast('Alasan penolakan minimal 3 karakter.', 'warning');
        return;
    }

    const origText = btn ? btn.innerText : '';
    if (btn) { btn.disabled = true; btn.innerText = 'Menyimpan...'; }

    try {
        const res = await apiFetch(`/api/v1/attendance-corrections/${id}/reject`, {
            method: 'POST',
            body: JSON.stringify({ reason })
        });
        if (res.success) {
            showToast(res.message || 'Koreksi presensi berhasil ditolak.', 'success');
            closeModal('rejectAttendanceCorrectionModal');
            await loadAttendanceCorrectionsData();
        }
    } catch (err) {
        showToast('Gagal menolak: ' + err.message, 'error');
    } finally {
        if (btn) { btn.disabled = false; btn.innerText = origText; }
    }
}

async function cancelAttendanceCorrection(id) {
    if (!confirm(`Batalkan pengajuan koreksi presensi #${id}?`)) return;

    try {
        const res = await apiFetch(`/api/v1/attendance-corrections/${id}/cancel`, { method: 'POST' });
        if (res.success) {
            showToast(res.message || 'Pengajuan koreksi berhasil dibatalkan.', 'success');
            await loadAttendanceCorrectionsData();
        }
    } catch (err) {
        showToast('Gagal membatalkan: ' + err.message, 'error');
    }
}

// =========================================================================
// SPRINT 12: OVERTIME REQUESTS (CLIENT CONTROLLER)
// =========================================================================
async function loadOvertimeRequestsData() {
    const tbody = document.getElementById('overtimeRequestsTableBody');
    if (!tbody) return;

    tbody.innerHTML = `<tr><td colspan="10" class="loading-td"><div class="spinner"></div> Memuat data pengajuan lembur...</td></tr>`;

    // 1. Metrics
    try {
        const mRes = await apiFetch('/api/v1/overtime-requests/metrics');
        if (mRes.success && mRes.data) {
            const d = mRes.data;
            const setVal = (id, v) => { const el = document.getElementById(id); if (el) el.innerText = v; };
            setVal('otMetricPending', d.submitted || 0);
            setVal('otMetricApproved', d.approved || 0);
            setVal('otMetricRejected', d.rejected || 0);
            const totalHours = ((d.total_approved_minutes || 0) / 60).toFixed(1);
            setVal('otMetricTotalHours', `${totalHours} Jam`);
        }
    } catch (e) {
        console.warn('Failed to load overtime metrics:', e);
    }

    // 2. List
    const status = document.getElementById('otFilterStatus')?.value || '';
    const from = document.getElementById('otFilterFrom')?.value || '';
    const to = document.getElementById('otFilterTo')?.value || '';

    const params = new URLSearchParams();
    if (status) params.append('status', status);
    if (from) params.append('from_date', from);
    if (to) params.append('to_date', to);

    try {
        const res = await apiFetch('/api/v1/overtime-requests?' + params.toString());
        if (!res.success || !res.data || res.data.length === 0) {
            tbody.innerHTML = `<tr><td colspan="10" style="text-align: center; padding: 2rem; color: var(--text-muted);">Tidak ada pengajuan lembur yang sesuai.</td></tr>`;
            return;
        }

        const role = (window.currentUserRole || '').toLowerCase();
        const canApprove = ['super_admin', 'hrd', 'management', 'supervisor'].includes(role);

        tbody.innerHTML = res.data.map(item => {
            const empName = item.employee?.name || `Karyawan #${item.employee_id}`;
            const otDate = item.overtime_date ? item.overtime_date.substring(0, 10) : '-';
            const timeRange = `${item.requested_start?.substring(0, 5) || '-'} s/d ${item.requested_end?.substring(0, 5) || '-'}`;
            const reqDuration = `${item.requested_minutes || 0} mnt`;
            const appDuration = item.status === 'APPROVED' ? `<strong style="color: var(--success);">${item.approved_minutes || 0} mnt</strong>` : `<span style="color: var(--text-muted);">-</span>`;

            // Status Badge
            let statusBadge = `<span class="badge badge-warning">SUBMITTED</span>`;
            if (item.status === 'APPROVED') statusBadge = `<span class="badge badge-success">APPROVED</span>`;
            if (item.status === 'REJECTED') statusBadge = `<span class="badge badge-danger">REJECTED</span>`;
            if (item.status === 'CANCELLED') statusBadge = `<span class="badge" style="background: var(--text-muted); color: #fff;">CANCELLED</span>`;

            // Attachment link
            let docLink = `<span style="color: var(--text-muted); font-size: 0.75rem;">-</span>`;
            if (item.attachment_path) {
                docLink = `<a href="/api/v1/overtime-requests/${item.id}/attachment" target="_blank" class="btn-secondary" style="padding: 0.25rem 0.5rem; font-size: 0.75rem; text-decoration: none;">📎 Unduh</a>`;
            }

            // Action buttons
            let actions = [];
            if (item.status === 'SUBMITTED') {
                if (canApprove) {
                    actions.push(`<button class="btn-primary" style="padding: 0.25rem 0.6rem; font-size: 0.75rem; background: var(--success); border-color: var(--success);" onclick="openApproveOvertimeModal(${item.id}, ${item.requested_minutes || 0})">✔ Setujui</button>`);
                    actions.push(`<button class="btn-secondary" style="padding: 0.25rem 0.6rem; font-size: 0.75rem; color: var(--danger); border-color: var(--danger);" onclick="openRejectOvertimeModal(${item.id})">✖ Tolak</button>`);
                }
                actions.push(`<button class="btn-secondary" style="padding: 0.25rem 0.5rem; font-size: 0.75rem;" onclick="cancelOvertimeRequest(${item.id})">Batalkan</button>`);
            } else {
                actions.push(`<span style="color: var(--text-muted); font-size: 0.75rem;">Selesai</span>`);
            }

            return `
                <tr>
                    <td style="font-family: monospace; font-size: 0.8rem;">#${item.id}</td>
                    <td><strong>${escapeHtml(empName)}</strong></td>
                    <td>${otDate}</td>
                    <td><span style="font-size: 0.8rem; font-weight: 600;">${timeRange}</span></td>
                    <td>${reqDuration}</td>
                    <td>${appDuration}</td>
                    <td style="max-width: 220px; font-size: 0.8rem; line-height: 1.2;">
                        ${escapeHtml(item.reason || '-')}
                        ${item.project_task_reference ? `<div style="color: #6366f1; font-size: 0.75rem; margin-top: 0.2rem;">Ref: ${escapeHtml(item.project_task_reference)}</div>` : ''}
                        ${item.rejection_reason ? `<div style="color: var(--danger); font-size: 0.75rem; margin-top: 0.25rem;">Alasan tolak: ${escapeHtml(item.rejection_reason)}</div>` : ''}
                    </td>
                    <td>${docLink}</td>
                    <td>${statusBadge}</td>
                    <td><div style="display: flex; gap: 0.35rem; flex-wrap: wrap;">${actions.join('')}</div></td>
                </tr>
            `;
        }).join('');
    } catch (err) {
        tbody.innerHTML = `<tr><td colspan="10" style="text-align: center; color: var(--danger); padding: 2rem;">Gagal memuat pengajuan lembur: ${escapeHtml(err.message)}</td></tr>`;
    }
}

function openNewOvertimeRequestModal() {
    const modal = document.getElementById('newOvertimeRequestModal');
    if (!modal) return;
    const form = document.getElementById('newOvertimeRequestForm');
    if (form) form.reset();

    const dateInput = document.getElementById('newOtDate');
    if (dateInput) {
        dateInput.value = new Date().toISOString().substring(0, 10);
    }
    openModal('newOvertimeRequestModal');
}

async function submitNewOvertimeRequest(e) {
    e.preventDefault();
    const btn = document.getElementById('btnSubmitNewOt');
    const origText = btn ? btn.innerText : '';
    if (btn) { btn.disabled = true; btn.innerText = 'Mengirim...'; }

    const formData = new FormData();
    formData.append('overtime_date', document.getElementById('newOtDate').value);
    formData.append('requested_start', document.getElementById('newOtStartTime').value);
    formData.append('requested_end', document.getElementById('newOtEndTime').value);
    formData.append('reason', document.getElementById('newOtReason').value);

    const refVal = document.getElementById('newOtTaskRef').value;
    if (refVal) formData.append('project_task_reference', refVal);

    const attInput = document.getElementById('newOtAttachment');
    if (attInput && attInput.files && attInput.files[0]) {
        formData.append('attachment', attInput.files[0]);
    }

    try {
        const res = await apiFetchForm('/api/v1/overtime-requests', formData);
        if (res.success) {
            showToast(res.message || 'Pengajuan lembur berhasil dibuat.', 'success');
            closeModal('newOvertimeRequestModal');
            await loadOvertimeRequestsData();
        }
    } catch (err) {
        showToast('Gagal mengajukan lembur: ' + err.message, 'error');
    } finally {
        if (btn) { btn.disabled = false; btn.innerText = origText; }
    }
}

function openApproveOvertimeModal(id, requestedMinutes) {
    const modal = document.getElementById('approveOvertimeModal');
    if (!modal) return;
    document.getElementById('approveOtId').value = id;
    document.getElementById('approveOtRequestedMinutes').value = requestedMinutes;
    document.getElementById('approveOtMinutesInput').value = requestedMinutes;
    document.getElementById('approveOtMinutesInput').max = requestedMinutes;
    openModal('approveOvertimeModal');
}

async function submitApproveOvertime(e) {
    e.preventDefault();
    const id = document.getElementById('approveOtId').value;
    const minutes = parseInt(document.getElementById('approveOtMinutesInput').value, 10);
    const btn = document.getElementById('btnSubmitApproveOt');

    if (!minutes || minutes <= 0) {
        showToast('Durasi lembur harus lebih dari 0 menit.', 'warning');
        return;
    }

    const origText = btn ? btn.innerText : '';
    if (btn) { btn.disabled = true; btn.innerText = 'Memproses...'; }

    try {
        const res = await apiFetch(`/api/v1/overtime-requests/${id}/approve`, {
            method: 'POST',
            body: JSON.stringify({ approved_minutes: minutes })
        });
        if (res.success) {
            showToast(res.message || 'Pengajuan lembur berhasil disetujui.', 'success');
            closeModal('approveOvertimeModal');
            await loadOvertimeRequestsData();
        }
    } catch (err) {
        showToast('Gagal menyetujui lembur: ' + err.message, 'error');
    } finally {
        if (btn) { btn.disabled = false; btn.innerText = origText; }
    }
}

function openRejectOvertimeModal(id) {
    const modal = document.getElementById('rejectOvertimeModal');
    if (!modal) return;
    document.getElementById('rejectOtId').value = id;
    document.getElementById('rejectOtReasonInput').value = '';
    openModal('rejectOvertimeModal');
}

async function submitRejectOvertime(e) {
    e.preventDefault();
    const id = document.getElementById('rejectOtId').value;
    const reason = document.getElementById('rejectOtReasonInput').value;
    const btn = document.getElementById('btnSubmitRejectOt');

    if (!reason || reason.trim().length < 3) {
        showToast('Alasan penolakan minimal 3 karakter.', 'warning');
        return;
    }

    const origText = btn ? btn.innerText : '';
    if (btn) { btn.disabled = true; btn.innerText = 'Menyimpan...'; }

    try {
        const res = await apiFetch(`/api/v1/overtime-requests/${id}/reject`, {
            method: 'POST',
            body: JSON.stringify({ reason })
        });
        if (res.success) {
            showToast(res.message || 'Pengajuan lembur berhasil ditolak.', 'success');
            closeModal('rejectOvertimeModal');
            await loadOvertimeRequestsData();
        }
    } catch (err) {
        showToast('Gagal menolak: ' + err.message, 'error');
    } finally {
        if (btn) { btn.disabled = false; btn.innerText = origText; }
    }
}

async function cancelOvertimeRequest(id) {
    if (!confirm(`Batalkan pengajuan lembur #${id}?`)) return;

    try {
        const res = await apiFetch(`/api/v1/overtime-requests/${id}/cancel`, { method: 'POST' });
        if (res.success) {
            showToast(res.message || 'Pengajuan lembur berhasil dibatalkan.', 'success');
            await loadOvertimeRequestsData();
        }
    } catch (err) {
        showToast('Gagal membatalkan: ' + err.message, 'error');
    }
}

// Register global window hooks
window.loadAttendanceCorrectionsData = loadAttendanceCorrectionsData;
window.openNewAttendanceCorrectionModal = openNewAttendanceCorrectionModal;
window.submitNewAttendanceCorrection = submitNewAttendanceCorrection;
window.approveAttendanceCorrection = approveAttendanceCorrection;
window.openRejectAttendanceCorrectionModal = openRejectAttendanceCorrectionModal;
window.submitRejectAttendanceCorrection = submitRejectAttendanceCorrection;
window.cancelAttendanceCorrection = cancelAttendanceCorrection;

window.loadOvertimeRequestsData = loadOvertimeRequestsData;
window.openNewOvertimeRequestModal = openNewOvertimeRequestModal;
window.submitNewOvertimeRequest = submitNewOvertimeRequest;
window.openApproveOvertimeModal = openApproveOvertimeModal;
window.submitApproveOvertime = submitApproveOvertime;
window.openRejectOvertimeModal = openRejectOvertimeModal;
window.submitRejectOvertime = submitRejectOvertime;
window.cancelOvertimeRequest = cancelOvertimeRequest;

// ==========================================
// Setup Gedung & Facility Hierarchy Manager
// ==========================================
async function loadBuildingHierarchy() {
    const container = document.getElementById('buildingHierarchyContainer');
    if (!container) return;

    container.innerHTML = `<div class="table-container" style="padding: 2.5rem; text-align: center;"><div class="spinner"></div> Memuat hierarki gedung dan perangkat pintu...</div>`;

    try {
        const [bldRes, doorRes] = await Promise.all([
            apiFetch('/admin/buildings'),
            apiFetch('/admin/doors')
        ]);

        const buildings = (bldRes.status === 'success' && Array.isArray(bldRes.data)) ? bldRes.data : [];
        const doors = (doorRes.status === 'success' && Array.isArray(doorRes.data)) ? doorRes.data : [];

        if (buildings.length === 0 && doors.length === 0) {
            container.innerHTML = `
                <div class="table-container" style="padding: 3rem 2rem; text-align: center; background: var(--card-bg); border: 1px solid var(--border-color); border-radius: 1rem;">
                    <div style="font-size: 3rem; margin-bottom: 0.75rem;">🏢</div>
                    <h3 style="color: var(--text-main); margin-bottom: 0.5rem;">Belum Ada Master Gedung Registered</h3>
                    <p style="color: var(--text-muted); font-size: 0.875rem; margin-bottom: 1.25rem;">Tambahkan gedung induk untuk mulai menyusun hierarki lokasi pintu fisik.</p>
                    <button class="btn-primary" onclick="openAddBuildingModal()">+ Tambah Gedung Pertama</button>
                </div>
            `;
            return;
        }

        const doorsByBuilding = {};
        doors.forEach(d => {
            const bKey = d.building_id || d.location || 'UNASSIGNED';
            if (!doorsByBuilding[bKey]) doorsByBuilding[bKey] = [];
            doorsByBuilding[bKey].push(d);
        });

        let html = '';

        buildings.forEach(bld => {
            const bldDoors = doorsByBuilding[bld.id] || doorsByBuilding[bld.name] || doors.filter(d => d.building_id === bld.id || d.location === bld.name);
            const zones = Array.isArray(bld.zones) ? bld.zones : [];

            html += `
                <div style="margin-bottom: 2rem; background: var(--card-bg); border: 1px solid var(--border-color); border-radius: 1rem; padding: 1.5rem; transition: border-color 0.2s ease;">
                    <!-- LEVEL 1: BUILDING HEADER -->
                    <div style="display: flex; justify-content: space-between; align-items: flex-start; border-bottom: 1px solid var(--border-color); padding-bottom: 1rem; margin-bottom: 1.25rem; flex-wrap: wrap; gap: 0.75rem;">
                        <div>
                            <div style="display: flex; align-items: center; gap: 0.65rem; margin-bottom: 0.25rem;">
                                <span style="font-size: 1.35rem;">🏢</span>
                                <h3 style="font-size: 1.25rem; font-weight: 700; color: #ffffff; margin: 0;">${escapeHtml(bld.name)}</h3>
                                <span class="badge badge-neutral" style="font-size: 0.75rem;">Kode: ${escapeHtml(bld.code || 'BLD')}</span>
                                ${bld.is_active ? '<span class="badge badge-granted">Aktif</span>' : '<span class="badge badge-dim">Non-Aktif</span>'}
                            </div>
                            <div style="font-size: 0.85rem; color: var(--text-muted); margin-left: 2rem;">
                                ${escapeHtml(bld.description || 'Gedung fasilitas operasional PKP SecureGate')}
                            </div>
                        </div>
                        <div style="display: flex; gap: 0.5rem; align-items: center;">
                            <span class="badge badge-info">${zones.length} Zona</span>
                            <span class="badge badge-success">${bldDoors.length} Perangkat Pintu</span>
                        </div>
                    </div>

                    <!-- LEVEL 2: FLOOR (DERIVED / PROPOSAL BLUEPRINT) -->
                    <div style="margin-left: 1rem; padding-left: 1.25rem; border-left: 2px dashed rgba(56, 189, 248, 0.3); margin-bottom: 1.25rem;">
                        <div style="display: flex; align-items: center; gap: 0.5rem; margin-bottom: 0.75rem;">
                            <span style="font-size: 1.1rem;">🧱</span>
                            <strong style="color: var(--primary); font-size: 0.95rem;">Lantai 1 (Floor 1 - Main Floor)</strong>
                            <span class="badge badge-neutral" style="font-size: 0.65rem; background: rgba(255, 255, 255, 0.05);">[Floor Schema Proposal Pending]</span>
                        </div>

                        <!-- LEVEL 3: ZONES -->
                        <div style="display: flex; flex-direction: column; gap: 1rem; margin-left: 1.25rem;">
                            ${zones.length > 0 ? zones.map(z => {
                                const zDoors = bldDoors.filter(d => d.zone_id === z.id);
                                return renderZoneBlock(z, zDoors);
                            }).join('') : renderDefaultZoneBlock(bldDoors)}
                        </div>
                    </div>
                </div>
            `;
        });

        container.innerHTML = html;
    } catch (err) {
        container.innerHTML = `<div class="table-container" style="padding: 2rem; color: var(--danger); text-align: center;">Gagal memuat hierarki gedung: ${escapeHtml(err.message)}</div>`;
    }
}

function renderZoneBlock(zone, zoneDoors) {
    return `
        <div style="background: rgba(15, 23, 42, 0.6); border: 1px solid rgba(255, 255, 255, 0.06); border-radius: 0.75rem; padding: 1rem 1.25rem;">
            <div style="display: flex; align-items: center; justify-content: space-between; margin-bottom: 0.75rem;">
                <div style="display: flex; align-items: center; gap: 0.5rem;">
                    <span style="font-size: 1rem;">📍</span>
                    <strong style="font-size: 0.9rem; color: #f8fafc;">${escapeHtml(zone.name)}</strong>
                    <span class="badge badge-dim" style="font-size: 0.65rem;">${escapeHtml(zone.code)}</span>
                </div>
                <span class="badge badge-neutral" style="font-size: 0.7rem;">${zoneDoors.length} Terminal</span>
            </div>

            <!-- LEVEL 4: DEVICES / DOORS -->
            <div style="display: grid; grid-template-columns: repeat(auto-fill, minmax(280px, 1fr)); gap: 0.75rem; margin-top: 0.5rem;">
                ${zoneDoors.length > 0 ? zoneDoors.map(d => renderDoorDeviceChip(d)).join('') : '<div style="font-size: 0.8rem; color: var(--text-dim); font-style: italic;">Belum ada perangkat pintu dialokasikan pada zona ini.</div>'}
            </div>
        </div>
    `;
}

function renderDefaultZoneBlock(doors) {
    return `
        <div style="background: rgba(15, 23, 42, 0.6); border: 1px solid rgba(255, 255, 255, 0.06); border-radius: 0.75rem; padding: 1rem 1.25rem;">
            <div style="display: flex; align-items: center; justify-content: space-between; margin-bottom: 0.75rem;">
                <div style="display: flex; align-items: center; gap: 0.5rem;">
                    <span style="font-size: 1rem;">📍</span>
                    <strong style="font-size: 0.9rem; color: #f8fafc;">Zona Akses Pintu Standar</strong>
                    <span class="badge badge-dim" style="font-size: 0.65rem;">ZN-DEFAULT</span>
                </div>
                <span class="badge badge-neutral" style="font-size: 0.7rem;">${doors.length} Terminal</span>
            </div>

            <!-- LEVEL 4: DEVICES / DOORS -->
            <div style="display: grid; grid-template-columns: repeat(auto-fill, minmax(280px, 1fr)); gap: 0.75rem; margin-top: 0.5rem;">
                ${doors.length > 0 ? doors.map(d => renderDoorDeviceChip(d)).join('') : '<div style="font-size: 0.8rem; color: var(--text-dim); font-style: italic;">Belum ada perangkat pintu dialokasikan.</div>'}
            </div>
        </div>
    `;
}

function renderDoorDeviceChip(door) {
    const isOnline = (door.connection_status === 'online' || door.status === 'online');
    const isSourceOfTruth = (door.door_id === 'DOOR-B' || door.device_ip === '192.168.90.15');

    return `
        <div style="background: rgba(30, 41, 59, 0.8); border: 1px solid ${isOnline ? 'rgba(16, 185, 129, 0.3)' : 'var(--border-color)'}; border-radius: 0.5rem; padding: 0.75rem; display: flex; flex-direction: column; gap: 0.35rem;">
            <div style="display: flex; justify-content: space-between; align-items: center;">
                <div style="font-size: 0.85rem; font-weight: 700; color: #ffffff; display: flex; align-items: center; gap: 0.35rem;">
                    🚪 ${escapeHtml(door.door_name || door.name || door.door_id)}
                </div>
                ${isOnline ? '<span class="badge badge-granted" style="font-size: 0.65rem;">ONLINE</span>' : '<span class="badge badge-denied" style="font-size: 0.65rem;">OFFLINE</span>'}
            </div>
            <div style="font-size: 0.75rem; color: var(--text-muted); display: flex; flex-direction: column; gap: 0.15rem;">
                <div>🆔 Code: <code>${escapeHtml(door.door_id)}</code> ${isSourceOfTruth ? '<span class="badge badge-warning" style="font-size: 0.6rem; padding: 1px 4px;">SOURCE OF TRUTH</span>' : ''}</div>
                <div>🌐 IP: <code>${escapeHtml(door.device_ip || door.ip_address || '-')}</code></div>
                <div>📠 Hardware: ${escapeHtml(door.device_model || door.model || 'DS-K1T804AMF')}</div>
            </div>
        </div>
    `;
}

function openAddBuildingModal() {
    const modal = document.getElementById('addBuildingModal');
    if (modal) modal.classList.add('active');
}

async function submitAddBuilding(e) {
    e.preventDefault();
    const code = document.getElementById('buildingCodeInput')?.value.trim();
    const name = document.getElementById('buildingNameInput')?.value.trim();
    const description = document.getElementById('buildingDescInput')?.value.trim();

    if (!code || !name) return;

    try {
        const res = await apiFetch('/admin/buildings', {
            method: 'POST',
            body: JSON.stringify({ code, name, description })
        });

        if (res.status === 'success') {
            showToast(`Gedung ${escapeHtml(name)} berhasil ditambahkan`, 'success');
            closeModal('addBuildingModal');
            document.getElementById('addBuildingForm')?.reset();
            await Promise.all([loadBuildingHierarchy(), loadDoors()]);
        }
    } catch (err) {
        showToast(`Gagal menyimpan gedung: ${err.message}`, 'error');
    }
}

async function openAddZoneModal() {
    const modal = document.getElementById('addZoneModal');
    const select = document.getElementById('zoneBuildingSelect');

    if (!modal || !select) return;

    select.innerHTML = '<option value="">Memuat data gedung...</option>';

    try {
        const res = await apiFetch('/admin/buildings');
        const buildings = (res.status === 'success' && Array.isArray(res.data)) ? res.data : [];

        select.innerHTML = '<option value="">Pilih Gedung Induk...</option>' + buildings.map(b => `
            <option value="${b.id}">${escapeHtml(b.name)} (${escapeHtml(b.code)})</option>
        `).join('');

        modal.classList.add('active');
    } catch (err) {
        showToast('Gagal memuat opsi gedung', 'error');
    }
}

async function submitAddZone(e) {
    e.preventDefault();
    const building_id = document.getElementById('zoneBuildingSelect')?.value;
    const code = document.getElementById('zoneCodeInput')?.value.trim();
    const name = document.getElementById('zoneNameInput')?.value.trim();

    if (!building_id || !code || !name) return;

    try {
        const res = await apiFetch('/admin/zones', {
            method: 'POST',
            body: JSON.stringify({ building_id: parseInt(building_id), code, name })
        });

        if (res.status === 'success') {
            showToast(`Zona ${escapeHtml(name)} berhasil ditambahkan`, 'success');
            closeModal('addZoneModal');
            document.getElementById('addZoneForm')?.reset();
            await loadBuildingHierarchy();
        }
    } catch (err) {
        showToast(`Gagal menyimpan zona: ${err.message}`, 'error');
    }
}

window.loadBuildingHierarchy = loadBuildingHierarchy;
window.openAddBuildingModal = openAddBuildingModal;
window.submitAddBuilding = submitAddBuilding;
window.openAddZoneModal = openAddZoneModal;
window.submitAddZone = submitAddZone;

async function loadSystemHealth() {
    try {
        const json = await apiFetch('/admin/system-health');
        if (json.status !== 'success' || !json.data) return;

        const data = json.data;

        // App Info
        if (data.app) {
            const elBadge = document.getElementById('shAppBadge');
            const elName = document.getElementById('shAppName');
            const elSub = document.getElementById('shAppSubtext');
            const elDetName = document.getElementById('shDetAppName');
            const elDetEnv = document.getElementById('shDetEnv');
            const elDetTime = document.getElementById('shDetServerTime');

            if (elBadge) elBadge.textContent = (data.app.status || 'HEALTHY').toUpperCase();
            if (elName) elName.textContent = data.app.app_name || 'PKP SecureGate';
            if (elSub) elSub.textContent = `PHP ${data.app.php_version || ''} | v${data.app.laravel_version || ''}`;
            if (elDetName) elDetName.textContent = data.app.app_name || 'PKP SecureGate';
            if (elDetEnv) elDetEnv.textContent = (data.app.environment || 'production').toUpperCase();
            if (elDetTime) elDetTime.textContent = data.app.server_time ? new Date(data.app.server_time).toLocaleString('id-ID') : '-';
        }

        // DB Info
        if (data.database) {
            const elBadge = document.getElementById('shDbBadge');
            const elDriver = document.getElementById('shDbDriver');
            const elSub = document.getElementById('shDbSubtext');

            const isConn = data.database.connected;
            if (elBadge) {
                elBadge.textContent = isConn ? 'TERHUBUNG' : 'TERPUTUS';
                elBadge.className = isConn ? 'badge badge-success' : 'badge badge-danger';
            }
            if (elDriver) elDriver.textContent = `${(data.database.driver || 'DB').toUpperCase()} Database`;
            if (elSub) elSub.textContent = `Connection State: ${isConn ? 'Normal' : 'Error'}`;
        }

        // Doors Info
        if (data.doors) {
            const elBadge = document.getElementById('shDoorBadge');
            const elIp = document.getElementById('shDoorIp');
            const elSub = document.getElementById('shDoorSubtext');
            const elDetStatus = document.getElementById('shDetDoorBStatus');
            const elDetHealth = document.getElementById('shDetDoorBHealth');
            const elDetRatio = document.getElementById('shDetDoorsRatio');

            const doorB = data.doors.door_b;
            if (doorB) {
                const isOnline = doorB.connection_status === 'online';
                if (elBadge) {
                    elBadge.textContent = isOnline ? 'ONLINE' : 'OFFLINE';
                    elBadge.className = isOnline ? 'badge badge-success' : 'badge badge-warning';
                }
                if (elIp) elIp.textContent = doorB.device_ip || '192.168.90.15';
                if (elSub) elSub.textContent = `${doorB.door_id || 'DOOR-B'} (${doorB.name || 'Door B'})`;
                if (elDetStatus) {
                    elDetStatus.textContent = (doorB.connection_status || 'offline').toUpperCase();
                    elDetStatus.className = isOnline ? 'badge badge-success' : 'badge badge-warning';
                }
                if (elDetHealth) elDetHealth.textContent = (doorB.health_status || 'unknown').toUpperCase();
            }
            if (elDetRatio) {
                elDetRatio.textContent = `${data.doors.online || 0} / ${data.doors.total || 0} Pintu Online`;
            }
        }

        // Queue Info
        if (data.queue) {
            const elBadge = document.getElementById('shQueueBadge');
            const elMode = document.getElementById('shQueueMode');
            const elSub = document.getElementById('shQueueSubtext');
            const elDetQueue = document.getElementById('shDetQueueDriver');

            if (elBadge) elBadge.textContent = (data.queue.driver || 'sync').toUpperCase();
            if (elMode) elMode.textContent = data.queue.mode || 'Direct Execution';
            if (elSub) elSub.textContent = `${data.queue.pending_jobs || 0} Pending / ${data.queue.failed_jobs || 0} Failed`;
            if (elDetQueue) elDetQueue.textContent = `${data.queue.driver || 'sync'} (${data.queue.mode || 'Direct Execution'})`;
        }

        // Last Event & Webhook Info
        if (data.last_access_event) {
            const elDetLast = document.getElementById('shDetLastLog');
            if (elDetLast) {
                if (data.last_access_event.timestamp) {
                    const dtStr = new Date(data.last_access_event.timestamp).toLocaleString('id-ID');
                    const emp = data.last_access_event.employee_name || '';
                    elDetLast.textContent = `${dtStr} ${emp ? '(' + emp + ')' : ''}`;
                } else {
                    elDetLast.textContent = 'Belum ada log';
                }
            }
        }

    } catch (e) {
        console.error('Error loading system health:', e);
    }
}

window.loadSystemHealth = loadSystemHealth;
