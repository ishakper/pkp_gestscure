#!/usr/bin/env node
// Builds a standalone, self-contained design prototype of the Card Access module.
//
//   node docs/card-access/build-prototype.mjs [output.html]
//
// The prototype reuses the real SecureGate styles (the <style> block of
// resources/views/dashboard.blade.php) plus public/css/card-access.css and
// public/js/card-access.js, so it is always in sync with the code under review.
// It runs the module in preview mode with fictional fixtures only.
import { readFileSync, writeFileSync } from 'node:fs';
import { dirname, resolve } from 'node:path';
import { fileURLToPath } from 'node:url';

const root = resolve(dirname(fileURLToPath(import.meta.url)), '../..');
const out = resolve(process.argv[2] || resolve(root, 'docs/card-access/prototype.html'));

const blade = readFileSync(resolve(root, 'resources/views/dashboard.blade.php'), 'utf8');
const styleMatch = blade.match(/<style>([\s\S]*?)<\/style>/);
if (!styleMatch) throw new Error('Could not find the dashboard <style> block.');
const baseCss = styleMatch[1];
const moduleCss = readFileSync(resolve(root, 'public/css/card-access.css'), 'utf8');
const moduleJs = readFileSync(resolve(root, 'public/js/card-access.js'), 'utf8');

const ROLES = {
    super_admin: { label: 'Super Admin', role: 'super_admin', permissions: [] },
    infra_admin: { label: 'Infra Admin', role: 'admin', permissions: ['credential.view', 'credential.manage', 'credential.sync', 'credential.revoke', 'access.manage', 'audit.view'] },
    infra_operator: { label: 'Infra Operator', role: 'admin', permissions: ['credential.view', 'credential.manage', 'credential.sync'] },
    viewer: { label: 'Viewer (read-only)', role: 'admin', permissions: ['credential.view'] },
};

const html = `<!DOCTYPE html>
<html lang="id" class="sidebar-open">
<head>
<meta charset="UTF-8">
<meta name="viewport" content="width=device-width, initial-scale=1.0">
<title>Card Access Prototype</title>
<link rel="preconnect" href="https://fonts.googleapis.com">
<link rel="preconnect" href="https://fonts.gstatic.com" crossorigin>
<link href="https://fonts.googleapis.com/css2?family=Plus+Jakarta+Sans:wght@300;400;500;600;700;800&display=swap" rel="stylesheet">
<script>if (window.innerWidth <= 768) document.documentElement.classList.remove('sidebar-open');</script>
<style>${baseCss}</style>
<style>${moduleCss}</style>
<style>
  .proto-bar { display:flex; gap:0.75rem; align-items:center; flex-wrap:wrap; margin-bottom:1.25rem; font-size:0.8rem; color:var(--text-muted); }
  .proto-bar select { background:rgba(15,23,42,0.8); border:1px solid var(--border-color); color:#fff; border-radius:0.5rem; padding:0.4rem 0.6rem; }
  .proto-toast { position:fixed; right:1rem; bottom:1rem; z-index:9999; max-width:420px; background:var(--sidebar-bg); border:1px solid var(--border-color); border-radius:0.75rem; padding:0.8rem 1rem; font-size:0.82rem; box-shadow:0 10px 25px rgba(0,0,0,0.4); display:none; }
  .proto-toast.show { display:block; }
  .proto-nav-open { position:fixed; top:14px; left:14px; z-index:99; }
  @media (min-width: 769px) { .proto-nav-open { display:none; } }
</style>
</head>
<body class="sidebar-open">
<aside class="sidebar" id="sidebar">
  <div class="sidebar-logo-placeholder" style="height:48px;display:flex;align-items:center;font-weight:800;padding-left:0.5rem">PKP Secure</div>
  <ul class="nav-list">
    <li class="nav-section-label">OPERASIONAL</li>
    <li class="nav-item"><button><span class="nav-icon">📊</span><span class="nav-text">Dashboard</span></button></li>
    <li class="nav-item"><button><span class="nav-icon">👥</span><span class="nav-text">Pengguna</span></button></li>
    <li class="nav-item"><button><span class="nav-icon">🌐</span><span class="nav-text">Perangkat Pintu</span></button></li>
    <li class="nav-item"><button><span class="nav-icon">🔑</span><span class="nav-text">Hak Akses</span></button></li>
    <li class="nav-item"><button class="active"><span class="nav-icon">💳</span><span class="nav-text">Card Access</span></button></li>
    <li class="nav-item"><button><span class="nav-icon">⏰</span><span class="nav-text">Rekap Kehadiran</span></button></li>
    <li class="nav-item"><button><span class="nav-icon">👆</span><span class="nav-text">Log Akses</span></button></li>
    <li class="nav-item"><button><span class="nav-icon">🛡️</span><span class="nav-text">Audit Log</span></button></li>
  </ul>
</aside>
<button class="btn-secondary proto-nav-open" onclick="document.documentElement.classList.toggle('sidebar-open');document.body.classList.toggle('sidebar-open')">☰</button>
<main class="main-content" id="mainContent">
  <div class="proto-bar">
    <b style="color:var(--text-main)">Design prototype</b>
    <label>Simulasi peran:
      <select id="protoRole">${Object.entries(ROLES).map(([k, r]) => `<option value="${k}">${r.label}</option>`).join('')}</select>
    </label>
    <span>Data fiktif · tidak terhubung ke backend · ${new Date().toISOString().slice(0, 10)}</span>
  </div>
  <section class="tab-content active" id="cardAccessTab"><div id="cardAccessRoot"></div></section>
  <section id="caGallery"></section>
</main>
<div class="proto-toast" id="protoToast" role="status"></div>
<script>${moduleJs.replace(/<\/script>/g, '<\\/script>')}</script>
<script>
  const ROLES = ${JSON.stringify(ROLES)};
  function toast(msg) {
    const t = document.getElementById('protoToast');
    t.textContent = msg; t.classList.add('show');
    clearTimeout(window.__t); window.__t = setTimeout(() => t.classList.remove('show'), 3500);
  }
  function boot(key) {
    const r = ROLES[key];
    window.APP_CONFIG = { cardAccessPreview: true, permissions: r.permissions, admin: { name: 'Operator Contoh', role: r.role } };
    const root = document.getElementById('cardAccessRoot');
    const fresh = root.cloneNode(false); root.replaceWith(fresh);
    CardAccess.mount(fresh, { adapter: CardAccess.createPreviewAdapter(), permissions: r.permissions, role: r.role, toast });
  }
  document.getElementById('protoRole').addEventListener('change', e => boot(e.target.value));
  boot('super_admin');
  CardAccess.renderStateGallery(document.getElementById('caGallery'));
</script>
</body>
</html>
`;

writeFileSync(out, html);
console.log('Prototype written to ' + out);
