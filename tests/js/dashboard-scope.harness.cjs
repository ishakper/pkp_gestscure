// Executes the real building-scope logic from public/js/dashboard.js against a mocked API.
// Run by tests/Feature/DashboardBuildingScopeBehaviourTest.php; prints one JSON document.
const fs = require('fs');
const path = require('path');
const vm = require('vm');

const source = fs.readFileSync(path.join(__dirname, '../../public/js/dashboard.js'), 'utf8');
const start = source.indexOf("const BUILDING_FILTER_KEY");
const endMarker = 'function buildingEmptyText(what) {';
const end = source.indexOf('\n}\n', source.indexOf(endMarker)) + 3;
if (start < 0 || end < start) { console.error('scope block not found'); process.exit(2); }
const scopeCode = source.slice(start, end);

const calls = [];
const ctl = { mode: 'legacy', employeeTotals: { total: 5, active: 0 } }; // mode: legacy | honoured | wrong-echo
const doors = [
    { door_id: 'DOOR-A', building_id: 1, connection_status: 'online' },
    { door_id: 'DOOR-B', building_id: 2, connection_status: 'online' },
    { door_id: 'DOOR-C', building_id: 2, connection_status: 'offline' },
];

const sandbox = {
    window: { APP_CONFIG: { admin: { role: 'super_admin' } }, scrollY: 0, addEventListener() {}, scrollTo() {} },
    document: { getElementById: () => null },
    navigator: { onLine: true },
    localStorage: { getItem: () => null, setItem() {} },
    state: { buildingFilter: '2', allDoors: doors, doors: [], activeTab: 'overviewTab' },
    escapeHtml: value => String(value),
    renderDoorCards() {}, refreshDoorFilters() {}, loadDoors() {}, loadEmployees() {}, loadAccessLogs() {}, updateMetricCards() {}, loadAttendanceMetrics() {},
    apiFetch: async url => {
        calls.push(url);
        const scoped = /building_id=2/.test(url) && !/employees|access-logs\?door_id/.test(url);
        if (/\/user-management\/employees/.test(url)) {
            return { pagination: { total_records: /employment_status=ACTIVE/.test(url) ? ctl.employeeTotals.active : ctl.employeeTotals.total } };
        }
        if (/access-logs\?door_id=DOOR-B/.test(url)) return { pagination: { total_records: 7 } };
        if (/access-logs\?door_id=DOOR-C/.test(url)) return { pagination: { total_records: 3 } };
        if (/access-logs\?door_id=DOOR-A/.test(url)) return { pagination: { total_records: 99 } };
        const res = { status: 'success', data: { totalUsers: 12 } };
        if (ctl.mode === 'honoured' && scoped) res.scope = { building_id: 2 };
        if (ctl.mode === 'wrong-echo' && scoped) res.scope = { building_id: 1 };
        return res;
    },
    ctl, calls, Promise, Number, String, Set, Math, console,
};
vm.createContext(sandbox);

const harness = `
${scopeCode}
(async () => {
  const out = {};

  // 1. Legacy backend: building_id ignored, no scope echo.
  ctl.mode = 'legacy';
  calls.length = 0;
  out.legacyFirst = await fetchScoped('/admin/dashboard-metrics', 'metrics', '2');
  out.legacyFirstCalls = calls.length;
  calls.length = 0;
  out.legacySecond = await fetchScoped('/admin/dashboard-metrics', 'metrics', '2');
  out.legacySecondSentBuildingId = calls.some(url => url.includes('building_id'));
  out.legacyFlag = scopeFlags.legacy.has('metrics');

  // 2. Backend that honours building_id (echoes scope).
  scopeSupport.metrics = null; scopeFlags.legacy.clear(); ctl.mode = 'honoured'; calls.length = 0;
  const honoured = await fetchScoped('/admin/dashboard-metrics', 'metrics', '2');
  out.honouredHasRes = !!honoured.res && !honoured.legacy;
  out.honouredUrl = calls[0];
  out.honouredSupport = scopeSupport.metrics;
  out.honouredLegacyFlag = scopeFlags.legacy.has('metrics');

  // 3. Backend echoing a DIFFERENT building must not be trusted.
  scopeSupport.metrics = null; ctl.mode = 'wrong-echo';
  out.wrongEcho = (await fetchScoped('/admin/dashboard-metrics', 'metrics', '2')).legacy === true;

  // 4. Unscoped request never sends building_id.
  scopeSupport.metrics = null; ctl.mode = 'legacy'; calls.length = 0;
  await fetchScoped('/admin/dashboard-metrics', 'metrics', '');
  out.unscopedUrl = calls[0];

  // 5. Legacy fallback numbers: exact values only, unknown stays null.
  ctl.employeeTotals = { total: 5, active: 0 };
  out.fallbackStatusUnknown = await legacyBuildingMetrics('2');
  ctl.employeeTotals = { total: 5, active: 4 };
  out.fallbackKnown = await legacyBuildingMetrics('2');

  // 6. Building admins never send or remember a filter.
  window.APP_CONFIG.admin.role = 'building_admin';
  state.buildingFilter = '9';
  restoreBuildingFilter();
  out.buildingAdminFilter = state.buildingFilter;
  onDashboardBuildingChange('3');
  out.buildingAdminAfterChange = state.buildingFilter;

  return out;
})()
`;

vm.runInContext(harness, sandbox).then(out => {
    process.stdout.write(JSON.stringify(out));
}).catch(error => {
    console.error(error);
    process.exit(1);
});
