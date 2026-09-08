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
function updateMetricCards() {
    // Total Users
    const userMetric = document.getElementById('metricTotalUsers');
    if (userMetric) userMetric.innerText = state.metrics.totalUsers;

    // Active Doors
    const onlineCount = state.doors.filter(d => (d.connection_status === 'online' || d.status === 'online')).length;
    const doorMetric = document.getElementById('metricActiveDoors');
    if (doorMetric) doorMetric.innerText = `${onlineCount} / ${state.doors.length || 4}`;

    // Recent Granted / Denied
    const grantedCount = state.accessLogs.filter(l => (l.access_status === 'Granted' || l.status === 'Granted')).length;
    const deniedCount = state.accessLogs.filter(l => (l.access_status === 'Denied' || l.status === 'Denied')).length;
    
    const grantedMetric = document.getElementById('metricAccessGranted');
    if (grantedMetric) grantedMetric.innerText = grantedCount;

    const deniedMetric = document.getElementById('metricAccessDenied');
    if (deniedMetric) deniedMetric.innerText = deniedCount;
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
        const overrideText = isOnline ? 'Set Offline' : 'Restore Online';
        const targetOverride = isOnline ? 'offline' : 'online';

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
                    <button class="btn-action btn-override" onclick="toggleDoorStatus('${safeDoorId}', '${targetOverride}')" title="Manual Override Maintenance Mode">
                        ⚡ ${overrideText}
                    </button>
                    <button class="btn-action btn-unlock" onclick="remoteUnlockDoor('${safeDoorId}', this)" title="Buka Pintu Jarak Jauh">
                        🔓 Buka Pintu
                    </button>
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
    const originalText = btn ? btn.innerHTML : '';
    if (btn) {
        btn.disabled = true;
        btn.innerHTML = `<span style="display:inline-block;width:11px;height:11px;border:2px solid currentColor;border-right-color:transparent;border-radius:50%;animation:spin 0.6s linear infinite;vertical-align:middle;margin-right:4px;"></span> Membuka...`;
    }

    try {
        const res = await apiFetch(`/admin/doors/${doorId}/open`, {
            method: 'POST',
        });

        if (res.status === 'success') {
            showToast(res.message || `✓ Pintu ${doorId} berhasil dibuka secara fisik!`, 'success');
            await loadDoors();
            if (typeof loadActivityLogs === 'function') {
                await loadActivityLogs();
            }
        } else {
            showToast(`Gagal membuka pintu: ${res.message || 'Error hardware'}`, 'error');
        }
    } catch (err) {
        showToast(`Gagal membuka pintu: ${err.message}`, 'error');
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
                'X-Device-Secret': 'secret_simulator_key_2026',
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
// Initial Boot
// ==========================================
document.addEventListener('DOMContentLoaded', () => {
    // Initialize Collapsible Sidebar Controller
    initSidebar();

    // Initial data loading
    loadDoors();
    loadEmployees();
    loadAccessLogs();

    // Auto-refresh doors and logs periodically every 30 seconds
    setInterval(() => {
        if (state.activeTab === 'doorsTab' || state.activeTab === 'overviewTab') {
            loadDoors();
        }
        if (state.activeTab === 'logsTab' || state.activeTab === 'overviewTab') {
            loadAccessLogs();
        }
    }, 30000);
});
