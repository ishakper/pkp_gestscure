/**
 * Centralized Access Door Management System - Dashboard Client Controller
 * Interfaces with Laravel Sanctum & RESTful API v1
 */

const API_BASE = '/api/v1';
let APP_TOKEN = window.APP_CONFIG?.apiToken || sessionStorage.getItem('api_token') || localStorage.getItem('api_token') || '';

// State Cache
let state = {
    doors: [],
    doorsLookup: [],
    employees: [],
    accessLogs: [],
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

        // Handle 401 Unauthorized -> redirect to login
        if (response.status === 401) {
            showToast('Sesi autentikasi telah berakhir. Mengalihkan ke halaman login...', 'error');
            setTimeout(() => {
                window.location.href = '/login';
            }, 1200);
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
        const res = await apiFetch('/admin/dashboard-metrics');
        if (res.status === 'success') {
            const data = res.data;
            const userMetric = document.getElementById('metricTotalUsers');
            if (userMetric) userMetric.innerText = data.totalUsers;

            const doorMetric = document.getElementById('metricActiveDoors');
            if (doorMetric) doorMetric.innerText = `${data.activeDoors} / ${data.totalDoors}`;

            const grantedMetric = document.getElementById('metricAccessGranted');
            if (grantedMetric) grantedMetric.innerText = data.grantedLogs;

            const deniedMetric = document.getElementById('metricAccessDenied');
            if (deniedMetric) deniedMetric.innerText = data.deniedLogs;
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

    try {
        const res = await apiFetch('/admin/doors');
        if (res.status === 'success') {
            state.doors = res.data;
            renderDoorCards(state.doors);
            updateMetricCards();
        }
    } catch (err) {
        if (grid) grid.innerHTML = `<div class="error-placeholder">Gagal memuat status pintu: ${err.message}</div>`;
        if (overviewGrid) overviewGrid.innerHTML = `<div class="error-placeholder">Gagal memuat status pintu: ${err.message}</div>`;
    }
}

function renderDoorCards(doors) {
    const renderTargets = [
        document.getElementById('doorsGrid'),
        document.getElementById('overviewDoorsGrid')
    ].filter(Boolean);

    if (renderTargets.length === 0) return;

    const html = doors.map(door => {
        const isOnline = (door.connection_status === 'online' || door.status === 'online');
        const badgeClass = isOnline ? 'status-online' : 'status-offline';
        const statusLabel = isOnline ? 'ONLINE' : 'OFFLINE';

        const safeDoorId = escapeHtml(door.door_id);
        const safeDoorName = escapeHtml(door.door_name || door.name || '');
        const safeLocation = escapeHtml(door.location || '');
        const safeDeviceIp = escapeHtml(door.device_ip || '-');
        const safeModel = escapeHtml(door.device_model || 'DS-K1T804AMF');
        const safeTotalUsers = Number(door.total_assigned_users) || 0;
        const lastCheckedStr = door.last_checked_at 
            ? new Date(door.last_checked_at).toLocaleTimeString('id-ID', { hour: '2-digit', minute: '2-digit', second: '2-digit' })
            : 'Belum dicek';

        return `
            <div class="card door-card" id="door-card-${safeDoorId}">
                <div class="card-header">
                    <div class="door-code-badge">
                        <span class="door-chip">${safeDoorId}</span>
                        <span class="door-loc">${safeLocation}</span>
                    </div>
                    <span class="status-badge ${badgeClass}">
                        <span class="status-dot"></span> ${statusLabel}
                    </span>
                </div>
                <div class="card-value door-name-title">${safeDoorName}</div>
                <div class="door-specs">
                    <div class="spec-item">
                        <span class="spec-label">IP Address:</span>
                        <code class="spec-code">${safeDeviceIp}</code>
                    </div>
                    <div class="spec-item">
                        <span class="spec-label">Hardware:</span>
                        <span class="spec-val">${safeModel}</span>
                    </div>
                    <div class="spec-item">
                        <span class="spec-label">Assigned Users:</span>
                        <span class="spec-val highlight">${safeTotalUsers} Pegawai</span>
                    </div>
                    <div class="spec-item">
                        <span class="spec-label">Status Pintu:</span>
                        <span class="spec-val">${isOnline ? '🟢 Closed (Normal)' : '🔴 Device Offline'}</span>
                    </div>
                    <div class="spec-item">
                        <span class="spec-label">Last Checked:</span>
                        <span class="spec-val" style="font-size: 0.775rem; color: var(--text-dim);">${lastCheckedStr}</span>
                    </div>
                </div>
                <div class="door-actions">
                    <button class="btn-action btn-unlock" style="background:#059669;color:#fff;font-weight:600;" onclick="remoteUnlockDoor('${safeDoorId}', this)">🔓 Buka Pintu</button>
                    <button class="btn-action btn-ping" onclick="pingSingleDoor('${safeDoorId}', this)" title="Cek status ISAPI getDeviceStatus">
                        📡 Cek Koneksi
                    </button>
                </div>
            </div>
        `;
    }).join('');

    renderTargets.forEach(target => target.innerHTML = html);
}

async function toggleDoorStatus(doorId, newStatus) {
    try {
        const res = await apiFetch(`/admin/doors/${doorId}/status`, {
            method: 'PATCH',
            body: JSON.stringify({
                connection_status: newStatus,
                is_manual_override: true,
            })
        });

        if (res.status === 'success') {
            showToast(`Status terminal ${doorId} diubah menjadi ${newStatus.toUpperCase()}`, 'success');
            await loadDoors();
        }
    } catch (err) {
        showToast(`Gagal override status ${doorId}: ${err.message}`, 'error');
    }
}

async function remoteUnlockDoor(doorId, btn) {
    if (!confirm(`Konfirmasi: Apakah Anda yakin ingin membuka relay pintu ${doorId} secara remote?`)) {
        return;
    }

    const originalText = btn ? btn.innerHTML : '';
    if (btn) {
        btn.disabled = true;
        btn.innerHTML = '⏳ Membuka...';
    }

    const csrfToken = document.querySelector('meta[name="csrf-token"]')?.getAttribute('content') || '';
    const token = window.APP_CONFIG?.apiToken || sessionStorage.getItem('api_token') || localStorage.getItem('api_token') || '';

    const headers = {
        'Content-Type': 'application/json',
        'Accept': 'application/json',
        'X-CSRF-TOKEN': csrfToken,
        'X-Requested-With': 'XMLHttpRequest'
    };
    if (token) {
        headers['Authorization'] = `Bearer ${token}`;
    }

    try {
        const response = await fetch(`/api/v1/doors/${doorId}/unlock`, {
            method: 'POST',
            headers: headers,
            body: JSON.stringify({ command: 'open' })
        });

        const data = await response.json().catch(() => ({}));

        if (response.ok && data.status === 'success') {
            alert(`✓ Berhasil: ${data.message || 'Relay pintu berhasil dibuka!'}`);
            await loadDoors();
            if (typeof loadActivityLogs === 'function') {
                await loadActivityLogs();
            }
        } else {
            alert(`✕ Gagal: ${data.message || 'Relay pintu gagal dibuka oleh hardware.'}`);
        }
    } catch (err) {
        alert(`✕ Terjadi kesalahan koneksi: ${err.message}`);
    } finally {
        if (btn) {
            btn.disabled = false;
            btn.innerHTML = originalText;
        }
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

async function loadEmployees() {
    const tbody = document.getElementById('employeesTableBody');
    const countBadge = document.getElementById('employeeCountText');
    const searchVal = document.getElementById('employeeSearch')?.value.trim() || '';
    const doorFilter = document.getElementById('employeeDoorFilter')?.value || '';

    if (tbody) {
        tbody.innerHTML = `<tr><td colspan="7" class="loading-td"><div class="spinner"></div> Memuat data karyawan & hak akses...</td></tr>`;
    }

    try {
        let url = `/user-management/users?per_page=50`;
        if (searchVal) url += `&search=${encodeURIComponent(searchVal)}`;
        if (doorFilter) url += `&door_id=${encodeURIComponent(doorFilter)}`;

        const res = await apiFetch(url);
        if (res.status === 'success') {
            state.employees = res.data;
            state.metrics.totalUsers = res.pagination?.total_records ?? state.employees.length;
            updateMetricCards();

            if (countBadge) {
                countBadge.innerText = `Total: ${state.metrics.totalUsers} Karyawan`;
            }

            renderEmployeesTable(state.employees);
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

        const safeUserId = escapeHtml(emp.user_id || '-');
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
                <td>${safeRole}</td>
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
                            🚪 Assign Doors
                        </button>
                        <button class="btn-sm btn-edit" onclick="openEditEmployeeModal(${empId})" title="Edit Profil & Biometrik">
                            ✏️ Edit
                        </button>
                        <button class="btn-sm btn-delete" onclick="handleDeleteEmployeeBtn(${empId}, this)" data-name="${safeName}" title="Hapus Karyawan">
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
        loadEmployees();
    }, 300);
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

        return `
            <label class="door-checkbox-card ${isChecked ? 'selected' : ''}">
                <input type="checkbox" name="assign_door_ids" value="${safeDoorId}" ${isChecked ? 'checked' : ''} onchange="this.parentElement.classList.toggle('selected', this.checked)">
                <div class="checkbox-door-info">
                    <div class="checkbox-door-code">${safeDoorId}</div>
                    <div class="checkbox-door-name">${safeDoorName}</div>
                    <div class="checkbox-door-loc">${safeDoorLoc}</div>
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
async function loadAccessLogs() {
    const tbody = document.getElementById('logsTableBody');
    const recentTbody = document.getElementById('overviewLogsTableBody');
    const doorFilter = document.getElementById('logDoorFilter')?.value || '';
    const statusFilter = document.getElementById('logStatusFilter')?.value || '';
    const userSearch = document.getElementById('logUserSearch')?.value.trim() || '';
    const startDate = document.getElementById('logStartDate')?.value || '';
    const endDate = document.getElementById('logEndDate')?.value || '';

    if (tbody) {
        tbody.innerHTML = `<tr><td colspan="7" class="loading-td"><div class="spinner"></div> Memuat event logs akses pintu...</td></tr>`;
    }

    try {
        let url = `/admin/access-logs?limit=40`;
        if (doorFilter) url += `&door_id=${encodeURIComponent(doorFilter)}`;
        if (statusFilter) url += `&status=${encodeURIComponent(statusFilter)}`;
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
        if (tbody) {
            tbody.innerHTML = `<tr><td colspan="7" class="error-td">Gagal memuat log akses: ${err.message}</td></tr>`;
        }
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
            target.innerHTML = `<tr><td colspan="7" class="empty-td">Tidak ada data log yang sesuai dengan filter.</td></tr>`;
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

        const safeLogId = escapeHtml(log.log_id || 'N/A');
        const safeDoorId = escapeHtml(log.door_id || '-');
        const safeDoorName = escapeHtml(log.door_name || '');
        const safeDeviceIp = escapeHtml(log.device_ip || '-');
        const safeTime = escapeHtml(timestampStr);
        const safeSource = escapeHtml(log.source || 'SEED');

        let sourceBadge = `<span class="badge badge-dim">${safeSource}</span>`;
        if (safeSource === 'HIKVISION') {
            sourceBadge = `<span class="badge badge-success">HIKVISION</span>`;
        } else if (safeSource === 'SIMULATOR') {
            sourceBadge = `<span class="badge badge-warning">SIMULATOR</span>`;
        }

        return `
            <tr>
                <td><code>${safeLogId}</code></td>
                <td>
                    <strong>${safeDoorId}</strong>
                    ${safeDoorName ? `<br><small class="text-muted">${safeDoorName}</small>` : ''}
                </td>
                <td><code class="ip-code">${safeDeviceIp}</code></td>
                <td>${userHtml}</td>
                <td>${methodBadge}</td>
                <td>${statusBadge}</td>
                <td>${sourceBadge}</td>
                <td>${safeTime}</td>
            </tr>
        `;
    }).join('');

    renderTargets.forEach(target => target.innerHTML = html);
}

function resetLogFilters() {
    if (document.getElementById('logDoorFilter')) document.getElementById('logDoorFilter').value = '';
    if (document.getElementById('logStatusFilter')) document.getElementById('logStatusFilter').value = '';
    if (document.getElementById('logUserSearch')) document.getElementById('logUserSearch').value = '';
    if (document.getElementById('logStartDate')) document.getElementById('logStartDate').value = '';
    if (document.getElementById('logEndDate')) document.getElementById('logEndDate').value = '';
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
        const res = await fetch('/api/v1/isapi/event-notification', {
            method: 'POST',
            headers: {
                'Content-Type': 'application/json',
                'Accept': 'application/json',
                'X-Device-Secret': window.APP_CONFIG?.deviceSecret || window.SECUREGATE_DEVICE_SECRET || '',
                'X-Simulator': 'true',
            },
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
    if (window.innerWidth < 768) {
        toggleSidebar(false);
    }

    if (tabId === 'doorsTab' || tabId === 'overviewTab') loadDoors();
    if (tabId === 'employeesTab' || tabId === 'overviewTab') loadEmployees();
    if (tabId === 'logsTab' || tabId === 'overviewTab') loadAccessLogs();
    if (tabId === 'recruitmentTab') loadRecruitmentData();
    if (tabId === 'internshipTab') loadInternshipData();
    if (tabId === 'onboardingTab') loadOnboardingData();
    if (tabId === 'accessTab') loadAccessData();
}

// ==========================================
// Floating Logo & Sidebar Controller
// ==========================================
function initSidebar() {
    const isMobile = () => window.innerWidth < 768;
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
    const isMobile = window.innerWidth < 768;
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
window.remoteUnlockDoor = remoteUnlockDoor;
window.checkAllDoors = checkAllDoors;
window.syncHardwareLogs = syncHardwareLogs;
window.revokeAllEmployeeDoors = revokeAllEmployeeDoors;
window.revokeSingleDoor = revokeSingleDoor;
window.openDoorAssignmentModal = openDoorAssignmentModal;
window.submitDoorAssignment = submitDoorAssignment;
window.handleSimEventTypeChange = handleSimEventTypeChange;
window.runEventSimulation = runEventSimulation;

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
    if (state.activeTab === 'logsTab' || state.activeTab === 'overviewTab') {
        loadAccessLogs();
    }
    
    // Also refresh door status
    if (state.activeTab === 'doorsTab' || state.activeTab === 'overviewTab') {
        loadDoors();
    }
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

    // Initialize Real-time SSE connection
    initLiveAccessStream();

    // Auto-refresh doors and logs periodically every 60 seconds (fallback)
    setInterval(() => {
        if (state.activeTab === 'doorsTab' || state.activeTab === 'overviewTab') {
            loadDoors();
        }
        if (state.activeTab === 'logsTab' || state.activeTab === 'overviewTab') {
            loadAccessLogs();
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
        const appToken = window.APP_CONFIG?.apiToken || sessionStorage.getItem('api_token') || localStorage.getItem('api_token') || '';

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
        const appToken = window.APP_CONFIG?.apiToken || sessionStorage.getItem('api_token') || localStorage.getItem('api_token') || '';
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
                    <button class="btn-primary" style="padding: 0.35rem 0.65rem; font-size: 0.75rem; background: #10b981; border-color: #059669;" onclick="openApproveRequestModal(${r.id})">✓ Setujui</button>
                    <button class="btn-secondary" style="padding: 0.35rem 0.65rem; font-size: 0.75rem; color: #ef4444; border-color: #ef4444;" onclick="openRejectRequestModal(${r.id})">✕ Tolak</button>
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
                actions = `<button class="btn-secondary" style="padding: 0.35rem 0.65rem; font-size: 0.75rem; color: #ef4444; border-color: #ef4444;" onclick="openRevokeCredentialModal(${c.id})">Cabut</button>`;
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
                actions = `<button class="btn-primary" style="padding: 0.35rem 0.65rem; font-size: 0.75rem;" onclick="retryDeviceSyncItem(${s.id})">🔄 Sync Ulang</button>`;
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
