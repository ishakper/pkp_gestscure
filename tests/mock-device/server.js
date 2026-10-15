const http = require('http');
const crypto = require('crypto');
const state = { mode: 'release', targetModes: {}, holdTarget: null, applyBeforeResponse: false, release: false, requests: [], mutations: [] };
const json = (res, code, body) => { res.writeHead(code, {'content-type':'application/json'}); res.end(JSON.stringify(body)); };
const read = req => new Promise((resolve, reject) => { let b=''; req.on('data', c => b += c); req.on('end', () => resolve(b)); req.on('error', reject); });
const server = http.createServer(async (req, res) => {
  if (req.url === '/__control/state') return json(res, 200, state);
  if (req.url === '/__control/release' && req.method === 'POST') { state.release = true; return json(res, 200, { released: true }); }
  if (req.url === '/__control/reset' && req.method === 'POST') { state.requests.length = 0; state.mutations.length = 0; state.mode = 'release'; state.targetModes = {}; state.holdTarget = null; state.applyBeforeResponse = false; state.release = false; return json(res, 200, { reset: true }); }
  if (req.url === '/__control/mode' && req.method === 'POST') { const body = JSON.parse(await read(req) || '{}'); state.mode = body.mode || 'release'; state.targetModes = body.target_modes || {}; state.holdTarget = body.hold_target || null; state.applyBeforeResponse = Boolean(body.apply_before_response); state.release = false; return json(res, 200, { mode: state.mode, target_modes: state.targetModes, apply_before_response: state.applyBeforeResponse }); }
  const target = req.headers['x-test-device-target'] || null;
  const targetMode = target && state.targetModes[target] ? state.targetModes[target] : state.mode;
  const id = crypto.randomUUID(); const started = new Date().toISOString(); const body = await read(req); const entry = { request_id: id, method: req.method, target: req.url, device_target: target, mode: targetMode, started_at: started, body: body ? '[REDACTED]' : null }; state.requests.push(entry);
  const isMutation = req.method !== 'GET';
  if (targetMode === 'timeout') { await new Promise(resolve => setTimeout(resolve, 30000)); entry.finished_at = new Date().toISOString(); return json(res, 504, { statusCode: 504, request_id: id, error: 'mock timeout' }); }
  if (targetMode === 'auth-error') { entry.finished_at = new Date().toISOString(); return json(res, 401, { statusCode: 401, statusString: 'Unauthorized', request_id: id }); }
  if (targetMode === 'invalid-response') { entry.finished_at = new Date().toISOString(); return json(res, 200, { invalid: true, request_id: id }); }
  if (isMutation && targetMode === 'hold-before-apply' && !state.release) await new Promise(resolve => { const timer = setInterval(() => { if (state.release) { clearInterval(timer); resolve(); } }, 10); });
  if (req.method !== 'GET' && (targetMode !== 'release' || req.url.includes('SetUp'))) state.mutations.push({ request_id: id, target: req.url, applied_at: new Date().toISOString() });
  if (isMutation && targetMode === 'hold-after-apply' && (!state.holdTarget || req.url.includes(state.holdTarget)) && !state.release) await new Promise(resolve => { const timer = setInterval(() => { if (state.release) { clearInterval(timer); resolve(); } }, 10); });
  entry.finished_at = new Date().toISOString(); json(res, 200, { statusCode: 1, request_id: id });
});
server.listen(80, '0.0.0.0');

