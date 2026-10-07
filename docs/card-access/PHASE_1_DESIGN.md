# PKP SecureGate — Card Access & NFC Provisioning
## Phase 1 + 1.5 · Design Foundation & Refinement

| | |
|---|---|
| Owner | YAZIED — UI/UX / Frontend |
| Status | **DESIGN READY** (integration blocked on Backend Contract Lock — ISHAK) |
| Date | 07 Oct 2026 |
| Code | `public/js/card-access.js`, `public/css/card-access.css`, `resources/views/dashboard.blade.php` (gated), `tests/Feature/CardAccessPreviewTest.php` |
| Prototype | `node docs/card-access/build-prototype.mjs` → `docs/card-access/prototype.html` (standalone, fictional data) |

The module is built as a native extension of the existing dashboard: same Blade shell, same
`switchTab()` routing, same tokens (`--card-bg`, `--primary`, `--success`, `--warning`, `--danger`,
`--text-*`), same primitives (`.metric-card`, `.table-container`, `.table-toolbar`, `.search-box`,
`.badge-*`, `.btn-primary/secondary/sm`, `.modal-overlay/.modal-card`, `.form-row`, `.ats-subnav`).
No new colours were introduced.

**Guardrails in this phase**

- Rendered only in `local`/`testing` **and** only for users with `credential.view`. Production never
  loads the CSS, JS, nav item or section (covered by `CardAccessPreviewTest`).
- All data flows through an adapter. The default `ContractPendingAdapter` rejects every call with
  `CONTRACT_PENDING`. The `PreviewAdapter` (fictional "Karyawan Contoh NN" records, masked card
  identifiers only) is used only when `APP_CONFIG.cardAccessPreview` is true.
- No `fetch`, no `apiFetch`, no `DELETE`, no NFC `scan()`, no raw `card_number` in the module
  (asserted by test). Mutating actions in preview are simulated and say so in the toast.
- No backend, migration, model, RBAC or Hikvision changes.

---

## 1. Information Architecture

```
SecureGate (existing dashboard shell)
└── OPERASIONAL (existing section)
    ├── Dashboard
    ├── Pengguna                ← Employee Identity source (unchanged)
    ├── Perangkat Pintu         ← Device health source (unchanged)
    ├── Hak Akses               ← existing provisioning / credential center (unchanged)
    ├── Card Access  ★ NEW      ← "Access Management › Card Access"
    │   ├── Overview            KPI · Perlu Tindakan · Recent Card Activity · Sync Health
    │   ├── Cards               employee-card table → Employee Access Profile (drawer)
    │   ├── Access Permissions  building/door entitlement editor → Review Changes
    │   └── Device Sync         app vs device vs verification monitor
    ├── Rekap Kehadiran
    └── …

Overlays (not navigation levels):
  • Employee Access Profile drawer (full-screen sheet on phone)
  • Register NFC Card flow (new card)      ─┐ separate flows,
  • Replace Card wizard (existing card)    ─┘ never one form
  • Disable / Revoke / Review Changes / Diagnostics / Audit dialogs
```

Data domains, always shown separately and never merged into one "Active":

```
EMPLOYEE IDENTITY  →  APPLICATION CREDENTIAL  →  ACCESS PERMISSION  →  HIKVISION DEVICE STATE
(Pengguna)            (CredentialRecord)          (profile/doors)        (device read + verification)
```

## 2. Navigation Structure

- One new sidebar item, **Card Access** (💳), placed directly after **Hak Akses** inside the existing
  `OPERASIONAL` section. There is no "Access Management" section label in SecureGate today; adding
  one would split OPERASIONAL and break the established order. The breadcrumb inside the page reads
  `ACCESS MANAGEMENT / CARD ACCESS` so the conceptual grouping is still visible.
- Four sub-tabs use the existing `.ats-subnav .subnav-btn` pill pattern (same as Hak Akses).
- KPI tiles are shortcuts: clicking one opens **Cards** with the matching filter.
- Max depth: sidebar → tab → drawer/flow. No third navigation level.
- Order assertion in `SecureGateUiRefactorTest` (`Dashboard … Hak Akses, Rekap Kehadiran …`) still
  passes; the new test asserts `Hak Akses → Card Access → Rekap Kehadiran`.

## 3. Card Access Overview Design

```
┌ ACCESS MANAGEMENT / CARD ACCESS ─────────────────────────────────────────────┐
│ 💳 Card Access                                  [🔄 Refresh] [📶 Daftarkan Kartu NFC] │
└──────────────────────────────────────────────────────────────────────────────┘
[📊 Overview] [💳 Cards] [🔑 Access Permissions] [⚡ Device Sync]

┌TOTAL CARDS┐┌ACTIVE┐┌PENDING SYNC┐┌SYNC FAILED┐┌NEEDS VERIFICATION┐┌DISABLED┐
│    —      ││  —   ││     —      ││     —     ││        —         ││   —    │
└───────────┘└──────┘└────────────┘└───────────┘└──────────────────┘└────────┘
Per <as_of> WIB · sumber: endpoint KPI backend

┌ PERLU TINDAKAN ───────────────────────────┐ ┌ HIKVISION SYNC HEALTH ─┐
│ Karyawan …  Card Mismatch     [ACTIVE·NOT SYNCED] Detail→│ │ ● Healthy          n │
│ Karyawan …  Access Mismatch   [ACTIVE·NOT SYNCED] Detail→│ │ ● Warning          n │
│ Karyawan …  Person Mismatch   [NEEDS VERIFICATION] …     │ │ ● Offline          n │
├ RECENT CARD ACTIVITY ─────────────────────┤ │ ● Sync Failed      n │
│ 07 Okt 08:51  Device Verified · Karyawan … │ │ ● Needs Verification n│
│               Operator: Sistem · Target: B-01 │ └──────────────────────┘
│                                   Verified │ ┌ Model status ─────────┐
└───────────────────────────────────────────┘ └───────────────────────┘
```

- KPI tiles: left accent bar in tone colour (success/attention/critical/neutral/info). Values are
  rendered **only** from the KPI endpoint response (`getKpis()`); the frontend never counts rows.
  The "as of" timestamp and source line make provenance explicit.
- **Perlu Tindakan** (attention queue) is an addition: it surfaces mismatches and unverified records
  first, because that is the operational job of Infra. Its contents come from the backend
  (`getAttentionQueue()`), not from the loaded table.
- **Recent Card Activity** items carry: timestamp, employee, operator, action, target/device, result.
  Supported types: Card Registered, Access Assigned, Access Changed, Card Replaced, Card Disabled,
  Card Revoked, Sync Started, Sync Completed, Sync Failed, Device Verified.
- **Sync Health**: Healthy / Warning / Offline / Sync Failed / Needs Verification with backend counts.

## 4. Cards Management Design

Desktop columns: Employee · Employee ID · Card · Building · Access Profile · Card Status ·
Device Sync · Last Verified · Actions (⋯).

- Search: one field for name, Employee ID, or card digits (debounced 300 ms; sent to backend).
- Filters: Building (from facility catalog, not hardcoded), Card Status (lifecycle), Sync Status,
  Verification Status. Mobile: filters move into a bottom sheet behind **⚙ Filter**.
- Card identifiers are always masked: `••••3163`. The module masks again defensively (`maskId`).
- Mismatch chips (`≠ CARD`, `≠ ACCESS`, `≠ PERSON`) sit under the employee name so mismatches are
  visible without opening the record.
- Pagination footer shows `Menampilkan x–y dari total (total dari backend)`; totals come from `meta`.
- Row click opens the Employee Access Profile drawer; the action menu does not trigger the row.

**Card actions (⋯ menu)** — View Detail · Edit Access · Replace Card · Re-Sync · Verify on Device ·
Disable Card · Revoke Card · View Audit History. A footnote in every menu states that permanent delete
is not available. Archive is not shown until backend policy allows it.

Action availability by state:

| State | Actions |
|---|---|
| CARD_NOT_REGISTERED | View, **Daftarkan Kartu NFC** (opens the enrollment flow pre-selected), Edit Access, Audit |
| ACTIVE_SYNCED / ACTIVE_NOT_SYNCED / NEEDS_VERIFICATION | View, Edit Access, Replace, Re-Sync, Verify, Disable, Revoke, Audit |
| DISABLED | View, Edit Access, Replace, Re-Sync, Verify, Revoke, Audit |
| REVOKED | View, Re-Sync (push removal to device), Audit |

Actions the user lacks permission for stay visible but disabled with a reason tooltip, so operators
understand why. If backend later returns `allowed_actions` per record, it is intersected with this table.

## 5. Employee Access Detail Design

Title: **EMPLOYEE ACCESS PROFILE**, right-hand drawer (620 px) on desktop, full-screen sheet on phone.

```
EMPLOYEE ACCESS PROFILE
Karyawan Contoh 03  [ACTIVE · NOT SYNCED]                                    ✕
┌ ≠ ACCESS MISMATCH ─────────────────────────────────────────────── ACCESS ┐
│ APP ACCESS · SECUREGATE   ≠   DEVICE ACCESS · HIKVISION                    │
│ Gedung B                      Gedung A + Gedung B                          │
└────────────────────────────────────────────────────────────────────────────┘
A EMPLOYEE IDENTITY         Name · Employee ID · Employment Status · Building · Department · Position
B APPLICATION CREDENTIAL    Masked Card · Type · Status · Registered At · Registered By · Asal Data
C ACCESS PERMISSION         Building Access · Door Access · Access Profile · Valid From · Valid Until
D HIKVISION DEVICE STATE    Person Match ✓ · Credential Match ✓ · Access Match ✕ · Last Sync ·
                            Last Verification · Device Status
ACCESS VERIFICATION         (component §8)              Overall: OUT OF SYNC
AUDIT HISTORY               (timeline)
──────────────────────────────────────────────────────────────────────────────
[Edit Access] [Replace Card] [Re-Sync] [Verify on Device] [Disable] [Revoke]
```

- Fields the backend does not send are omitted rather than shown as empty (Department, Position,
  Registered By).
- **Asal Data**: `Data existing (sah — tanpa metadata NFC)` vs `Pendaftaran NFC`. Existing records are
  first-class; missing NFC metadata is never an error.
- If the backend's lifecycle state is healthy but its own device comparison disagrees, a
  "Ditampilkan konservatif" notice explains why the badge is downgraded (see §16 display guard).
- Overall verification states: VERIFIED · OUT OF SYNC · NEEDS VERIFICATION · DISABLED · REVOKED
  (+ NOT VERIFIED for records never pushed to a device).

## 6. Access Permission Design

```
EMPLOYEE  [Karyawan Contoh 01 · CONTOH-001 ▾]                       [ACTIVE · SYNCED]
┌ BUILDING ACCESS ─────────────┐ ┌ DOOR ACCESS · GEDUNG C ───────────────────┐
│ Gedung A · Disabled     ( )  │ │ C-01 Main Entrance · Enabled  diubah  (●) │
│ Gedung B · Enabled      (●)  │ │ C-02 Office Door   · Disabled          ( ) │
│▌Gedung C · Enabled diubah (●)│ └──────────────────────────────────────────┘
│ Gedung D · Disabled     ( )  │
└──────────────────────────────┘
Access Profile [Staf Standar ▾]  Valid From [ ]  Valid Until [ ]  Reason / Note * [          ]
──────────────────────────────────────────────────────────────────────────────
Perubahan belum disimpan: +1 pintu, −0 pintu.                [Batal] [Review Changes]
```

- Toggles only change a local draft. Changed rows are tagged **diubah**. Nothing is saved on toggle.
- Turning a building off also clears its doors; doors are disabled until their building is on.
- **Review Changes** is enabled only when there is a diff **and** a Reason/Note.
- Review dialog: CURRENT ACCESS · NEW ACCESS · ADDED · REMOVED, plus profile/validity/reason, then
  **[Cancel] [Apply & Synchronize]**. The dialog states that device status becomes *Pending* until
  Hikvision confirms ("Tersimpan ≠ terverifikasi"). Diff is requested from the backend
  (`previewAccessChange`) so business rules (profile-implied doors etc.) stay server-side.

## 7. Device Sync Design

Columns: Employee · Application State · Hikvision State · Credential · Access · Verification ·
Last Verified · Action.

- Status chips across the top: Semua · Verified · Syncing · Pending · Failed · Out of Sync ·
  Needs Verification.
- **Application State** = credential status + approved buildings (SecureGate).
  **Hikvision State** = device sync status + terminal status (device read).
  **Verification** = physical confirmation. Three separate columns, never combined.
- Row actions: one contextual primary button (Retry Sync for Failed/Out of Sync, Verify for
  Pending/Needs Verification, otherwise Diagnostics) and a ⋯ menu with Retry Sync · Verify ·
  Diagnostics · Audit.
- Diagnostics dialog: error code, terminal, attempts, last attempt, device status, what is saved,
  plus any mismatch comparisons.
- Footer copy: "Permintaan API yang berhasil ≠ akses fisik terverifikasi."

## 8. Access Verification Design

`VerificationSummary` component (used in the drawer and the state gallery):

```
ACCESS VERIFICATION                                   [VERIFIED]
Karyawan Contoh 01 · ••••3163 · Gedung B
Application Permission     ✓ Match
Device Credential          ✓ Match
Device Permission          ✓ Match
Verification               ✓ Match
Last Verified: 07 Okt 2026, 08:51 WIB

Mismatch:
Application Permission     ✓
Device Permission          ✕ Mismatch                        [OUT OF SYNC]
Last Verified: …                                             [⚡ Re-Sync]
```

Marks: ✓ match · ✕ mismatch/missing · ? unknown · … pending. Re-Sync only appears when the result
is Out of Sync or Failed, and is disabled without `CAN_SYNC`.

## 9. Existing Card Flow

Entry: Cards table / drawer. Never routed through NFC enrollment.

```
View (drawer) ──► Verify on Device ──► result updates Device State + Verification
     │
     ├─► Edit Access ──► Access Permissions tab (pre-selected) ──► Review Changes ──► Apply & Synchronize
     ├─► Re-Sync ──► Pending/Syncing ──► Verified | Failed (diagnostics)
     ├─► Disable ──► reason ──► Disable & Sync
     ├─► Replace Card ──► Replace wizard (§14)
     └─► Revoke ──► Confirm revoke (§15)
```

Existing employees appear naturally with their real state (ACTIVE_SYNCED, ACTIVE_NOT_SYNCED,
CARD_NOT_REGISTERED, NEEDS_VERIFICATION, DISABLED, REVOKED). Nothing prompts them to re-enroll.

## 10. Mobile NFC Flow

Full-screen on phone, centred 520 px panel on larger screens. Step indicator: **1 Employee · 2 Scan ·
3 Access · 4 Review · 5 Sync**. Validate lives inside Scan; Verify lives inside Sync.

```
SELECT EMPLOYEE → SCAN CARD → VALIDATE → SELECT ACCESS → REVIEW → REGISTER & SYNC → VERIFY
```

**Step 1 — Employee**: search (16 px input to stop iOS/Android zoom), candidate list with Name,
Employee ID, Building, Employment Status, current card status. Ineligible employees are shown but
disabled with the **backend's reason** in red. An employee who already has an active card shows
"Gunakan alur Replace Card" and a button that opens the Replace wizard — the two flows stay separate.

## 11. NFC Validation Flow

**Step 2 — Scan** shows the selected employee summary and an NFC target panel:

| State | Visual | Copy |
|---|---|---|
| NFC READY | dashed border | Tekan Start NFC Scan… [START NFC SCAN] |
| WAITING FOR CARD | primary border + spinner | Tempelkan kartu dan tahan |
| CARD DETECTED | green border | Kartu terbaca. Memeriksa… |
| VALIDATING | primary border + spinner | Memeriksa duplikasi dan status |
| READY | green border ✓ | Kartu valid. Belum ada yang disimpan |
| ERROR | red border ✕ | [Scan Ulang] + error card |

Validation results (from backend):

- **READY** → `CARD DETECTED · ••••A82F · Validation Status: READY`. "Lanjut" enabled.
- **CARD ALREADY REGISTERED** → error card; owner details hidden; existing record untouched.
- **CARD REQUIRES VERIFICATION** → card exists on Hikvision but not in SecureGate; guidance to
  reconcile in Device Sync first; nothing is changed automatically.
- **CARD UNREADABLE / NFC DISABLED / NFC UNSUPPORTED** → error cards (§17).

Detection never registers a card. Registration only happens on the Review step.

## 12. Register & Sync Flow

**Step 3 — Access**: compact `AccessPermissionMatrix` + profile, validity and required reason.
**Step 4 — Review**: Employee, masked card, building access, door access, access profile, operator.
Primary **REGISTER & SYNC**, secondary **Kembali/Cancel**.
**Step 5 — Sync**: the close button is hidden while running; progress is announced via `aria-live`.

```
Registering Credential            ✓
Creating Access Assignment        ✓
Synchronizing Hikvision           ◌ (running)
Verifying Device                  —
```

Final success: **CARD ACTIVE · VERIFIED** → [Lihat Kartu].

## 13. Sync Failure Flow

```
◐ CARD REGISTERED · DEVICE SYNC FAILED
Kredensial berhasil disimpan di SecureGate, tetapi belum terverifikasi di Hikvision.
Akses fisik belum aktif.

Registering Credential         ✓
Creating Access Assignment     ✓
Synchronizing Hikvision        ✕  Terminal tidak merespons (HTTP 503)
Verifying Device               dilewati

[⚡ Retry Sync] [View Diagnostic] [Return to Card]
```

- **Retry Sync** re-runs only Synchronizing + Verifying; the credential is not registered twice.
- **Verification timeout** variant: "CARD REGISTERED · VERIFICATION TIMEOUT" with the same actions.
- "Something went wrong" is never used; every failure names the stage that failed.

## 14. Replace Card Flow

Steps: **Current Card → Reason → Scan New → Access → Review → Sync** (employee is fixed from the record).

- Current Card: shows OLD CARD with status and current access. Copy: old card is deactivated per
  backend policy when the new one succeeds; two active cards are never implied.
- Reason: Lost · Damaged · Replacement · Other (note required for Other). Lost adds a warning that
  the old card must be removed from devices and is only final after verification.
- Scan New: OLD CARD vs NEW CARD side by side; same scan/validation states as enrollment.
- Access: **Retain existing access?** — Pertahankan (default) or Atur ulang (shows the matrix).
- Review: OLD CARD · akan dinonaktifkan / NEW CARD, reason, access, operator → **REPLACE & SYNC**.
- Sync: Deactivating Old Card → Registering New Credential → Carrying Over Access /
  Creating Access Assignment → Synchronizing Hikvision → Verifying Device.

## 15. Revoke Flow

`ConfirmRevokeDialog` (red-titled modal):

- Warning block: revocation removes physical access; audit history is kept; this is not a delete.
- Employee + Employee ID, Masked Card, Current Access (profile), Affected Buildings,
  Affected Doors (with count).
- Required: Reason (Resign / kontrak berakhir · Kartu hilang · Pelanggaran keamanan · Lainnya),
  note, and an acknowledgement checkbox ("…akan kehilangan akses ke N pintu").
- **REVOKE CARD ACCESS** (solid danger button) is disabled until all three are filled.
- After submit: "Status Revoked final setelah perangkat terverifikasi."

Disable uses a lighter, amber dialog with a required reason ("Disable & Sync").
There is no hard delete anywhere in the module.

## 16. Mismatch States

`MismatchAlert` renders an explicit APP vs DEVICE comparison; never a generic warning icon.

| Kind | App side | Device side | Trigger |
|---|---|---|---|
| CARD MISMATCH | App Card `••••3163` | Device Card `••••9042` / "Tidak ada di perangkat" | `credential_match ∈ {MISMATCH, MISSING}` |
| ACCESS MISMATCH | App Access `Gedung B` | Device Access `Gedung A + Gedung B` | `access_match ∈ {MISMATCH, MISSING}` |
| PERSON MISMATCH | App Person `Name (ID)` | Device Person `name on terminal` | `person_match ∈ {MISMATCH, MISSING}` |

Mismatches appear in: Cards table chips, Overview attention queue, drawer header, Device Sync rows,
Diagnostics dialog.

**Display guard (`resolveDisplayState`)** — the backend owns the lifecycle state. The UI refuses to
*display* `ACTIVE_SYNCED` when the same record's device comparison disagrees: any mismatch →
shown as ACTIVE · NOT SYNCED; any unknown/pending comparison or non-VERIFIED verification → shown as
NEEDS VERIFICATION; unknown state values → NEEDS VERIFICATION. This only affects presentation; it
never feeds KPIs or writes data, and the drawer explains the downgrade.

Status vocabulary and tones:

| Tone (existing badge class) | States |
|---|---|
| Success (`badge-success`) | ACTIVE · SYNCED, VERIFIED, Match, Healthy |
| Attention (`badge-warning`) | ACTIVE · NOT SYNCED, NEEDS VERIFICATION, PENDING, Warning |
| Critical (`badge-danger`) | FAILED, OUT OF SYNC, REVOKED, Mismatch, Sync Failed |
| Neutral (`badge-dim`) | DISABLED, CARD NOT REGISTERED, Offline, Unknown, N/A |
| Info (`badge-info`) | SYNCING |

## 17. Error States

Every error card answers three questions: **Yang gagal / Yang tetap tersimpan / Langkah aman**.

| Error | What failed | What remains saved | Safe next action |
|---|---|---|---|
| Network Unavailable | Device offline | Nothing sent | Retry when connected |
| API Unavailable | Server not responding | Form input kept on screen | Retry · contact admin |
| Hikvision Offline | Terminal unreachable | Credential + access saved in SecureGate | Retry Sync when online |
| NFC Unsupported | No Web NFC support | Nothing created | Use supported Android device |
| NFC Disabled | NFC off / permission denied | Nothing created | Enable NFC, retry |
| Card Unreadable | UID unreadable | Nothing created | Hold longer / other card |
| Duplicate Card | Card owned by someone else | Existing owner untouched | Other card · report |
| Employee Inactive | Employment not active | Nothing changed | Check Pengguna |
| Employee Not Eligible | Backend rejected | Nothing changed | Read backend reason |
| Permission Denied | Missing permission | Nothing changed | Ask Super Admin |
| Card Mismatch | Device card differs | App data not auto-changed | Re-Sync / Verify |
| Device Mismatch | Person/access differs | App data not auto-changed | Verify, then Re-Sync |
| Partial Synchronization | Some terminals failed | Saved; failed terminals not updated | Retry failed · Diagnostics |
| Verification Timeout | No device confirmation | Saved + command sent, unverified | Verify again; not active yet |
| Contract Pending | Endpoint not locked yet | Nothing read or changed | Wait for contract lock |

## 18. Empty States

Text-only, matching SecureGate (no illustrations): No cards · No employees found (with Reset Filter) ·
No sync failures · No activity · No audit history · No assigned access. The credential and access
sections in the drawer use the same empty states, with a "Daftarkan Kartu NFC" action when allowed.

## 19. Loading States

- KPI: skeleton bar inside each tile (no numbers flash).
- Table: 5 skeleton rows; mobile: 3 skeleton cards.
- Detail: four skeleton domain sections.
- Sync progress: per-stage spinner (`.spinner-sm`, existing).
- Verification progress: "Verifying Device" stage + `aria-live`.
- NFC waiting: WAITING/VALIDATING state with spinner.
- Skeletons use a slow opacity pulse and stop entirely under `prefers-reduced-motion`.
- Stale responses are dropped (request counter on the Cards list).

## 20. Desktop Wireframes

Desktop ≥ 1025 px: sidebar 270 px + content. Table-first.

```
┌sidebar┐┌──────────────────────────────── content ───────────────────────────────┐
│OPERAS.││ [banner: DESIGN PREVIEW]                                                │
│ …     ││ ACCESS MANAGEMENT / CARD ACCESS                                         │
│Hak Akses│ 💳 Card Access                         [🔄 Refresh] [📶 Daftarkan Kartu NFC]│
│▌Card Access│[Overview][Cards][Access Permissions][Device Sync]                     │
│Rekap …││ ┌ search ──────────────┐ [Gedung▾][Status Kartu▾][Status Sync▾][Verif▾] │
│       ││ EMPLOYEE  EMP ID  CARD  BUILDING  PROFILE  CARD STATUS  DEVICE SYNC  LAST VERIFIED ⋯│
│       ││ Karyawan… CONTOH… ••••3163 Gedung B Staf… [ACTIVE·SYNCED] [VERIFIED] 07 Okt 08:51 ⋯│
│       ││ Karyawan… …      ••••5520 …        …     [ACTIVE·NOT SYNCED] [OUT OF SYNC]  …  ⋯│
│       ││   ≠ CARD                                                                │
│       ││ Menampilkan 1–10 dari 10 (total dari backend)        [‹ Sebelumnya][Berikutnya ›]│
└───────┘└────────────────────────────────────────────────────────────────────────┘
                                    drawer (620px) slides over from the right ─────►
```

The full set of desktop screens (Overview, Cards, action menu, drawer, permissions + review, Device
Sync, revoke) is in the prototype.

## 21. Tablet Behavior

769–1024 px (and laptops ≤ 1280 px):

- KPI grid 6 → 3 columns (≤ 1280 px).
- Overview becomes single column (attention + activity, then sync health).
- Cards / Device Sync tables hide optional columns (Building, Access Profile, Last Verified,
  Credential, Access) — those values remain in the drawer. Tables scroll horizontally inside their
  card if still too wide; row menus are `position: fixed` so they are never clipped.
- Access Permissions: building and door lists stack.
- Drawer stays a right-side panel (620 px max).

## 22. Mobile Wireframes

≤ 768 px — cards, sheets and full-screen flows; tables are not shrunk.

```
┌──────────────────────────┐  ┌──────────────────────────┐  ┌──────────────────────────┐
│ ACCESS MGMT / CARD ACCESS│  │ 🔍 Cari…        [⚙ Filter]│  │ Register NFC Card      ✕ │
│ 💳 Card Access           │  │┌────────────────────────┐│  │ 1EMP 2SCAN 3ACC 4REV 5SYNC│
│ [🔄 Refresh]              │  ││Karyawan Contoh 02    ⋯ ││  │━━━━ ━━━━ ──── ──── ──── │
│ [📶 Daftarkan Kartu NFC]  │  ││CONTOH-002 · Gedung A   ││  │ SELECTED EMPLOYEE        │
│ [Overview][Cards][Acc…] →│  ││[ACTIVE·NOT SYNCED][OOS]││  │ Karyawan Contoh 12       │
│┌TOTAL┐┌ACTIVE┐           │  ││≠ CARD                  ││  │ CONTOH-012 · Gedung A    │
││  —  ││  —   │           │  ││Kartu        ••••5520   ││  │ ┌──────────────────────┐ │
│└─────┘└──────┘           │  ││Profil   Staf Standar   ││  │ │         📶           │ │
│┌PENDING┐┌FAILED┐         │  ││Terverifikasi 06 Okt …  ││  │ │      NFC READY       │ │
││  —    ││  —   │         │  │└────────────────────────┘│  │ │  [ START NFC SCAN ]  │ │
│└───────┘└──────┘         │  │ …                         │  │ └──────────────────────┘ │
│ PERLU TINDAKAN           │  │ ┌ bottom sheet filters ─┐ │  │                          │
│ Karyawan… Card Mismatch ●│  │ │[Gedung ▾] [Status ▾]  │ │  │──────────────────────────│
│ …                        │  │ │[Sync ▾]  [Verif ▾]    │ │  │ [Kembali]  [  Lanjut  ]  │
└──────────────────────────┘  └─┴───────────────────────┴─┘  └──────────────────────────┘
```

- Drawer = full-screen sheet with sticky two-column action footer (44 px targets).
- Row ⋯ menu = bottom action sheet.
- NFC/Replace flows = full-screen, sticky footer with `safe-area-inset-bottom`.
- Comparison cells (APP ≠ DEVICE) stack vertically with a rotated ≠.

## 23. Component Map

| Component | Kind | Where used | Built on (existing) |
|---|---|---|---|
| `AccessStatusBadge` | badge | table, drawer, cards, flows | `.badge` + `.badge-*` |
| `CardStatusBadge` | badge | drawer B, Device Sync | `.badge` |
| `SyncStatusBadge` | badge | table, drawer D, Device Sync | `.badge` |
| `VerificationStatusBadge` | badge | Device Sync, verification | `.badge` |
| `CardAccessKpi` | KPI row | Overview | `.metric-label/.metric-value` |
| `CardAccessFilters` | toolbar / sheet | Cards | `.table-toolbar`, `.search-box` |
| `CardActionMenu` | overflow menu | table, mobile cards, Device Sync | `--sidebar-bg` surface |
| `EmployeeAccessCard` | mobile list item | Cards (≤768) | `--card-bg` card |
| `EmployeeAccessDetail` | drawer body | drawer | `.modal-title`, `.modal-close-btn` |
| `MismatchAlert` | comparison | drawer, gallery, diagnostics | tokens only |
| `VerificationSummary` | checklist | drawer, gallery | `.btn-primary.btn-sm` |
| `AccessPermissionMatrix` | toggles | Access Permissions, NFC/Replace Access step | `.form-row` |
| `AccessDiff` | review diff | Review Changes | `.modal-card` |
| `NfcScanPanel` | scan target | NFC/Replace Scan step | `.btn-primary`, `.spinner-sm` |
| `NfcValidationResult` | result card | Scan step | `ErrorState` |
| `SyncProgress` | stage list | Sync step | `.spinner-sm` |
| `AuditTimeline` | timeline | drawer, Audit dialog | tokens only |
| `ConfirmRevokeDialog` | dialog | Revoke | `.modal-overlay/.modal-card` |
| `ReplaceCardWizard` | flow | Replace | flow shell |
| `ErrorState` / `EmptyState` / `PermissionDenied` | feedback | everywhere | tokens only |
| `StepIndicator` | progress | flows | tokens only |

Supporting model (all in `card-access.js`): `LIFECYCLE`, `CREDENTIAL`, `SYNC`, `VERIFICATION`,
`MATCH`, `DEVICE_HEALTH`, `ACTIVITY`, `ERRORS`, `EMPTY`, `CAPABILITY_PERMISSION`,
`resolveDisplayState`, `collectMismatches`, `maskId`, `ADAPTER_METHODS`.

## 24. Responsive Strategy

| Breakpoint | Layout |
|---|---|
| > 1280 px | Desktop: 6 KPI columns, two-column Overview, full tables, right drawer |
| ≤ 1280 px | KPI 3 columns |
| ≤ 1024 px (tablet) | Single-column Overview, optional table columns hidden, stacked permission lists |
| ≤ 768 px (phone) | Tables → cards, filters → bottom sheet, menus → action sheet, drawer → full-screen, flows → full-screen, KPI 2 columns, 44 px touch targets |

Breakpoints match the existing dashboard (`768px` is SecureGate's only current breakpoint; the
module adds 1024/1280 for its own denser tables). Motion is limited to existing `fadeIn`, a 200 ms
drawer slide, and a skeleton pulse disabled under `prefers-reduced-motion`.

## 25. Expected Frontend File Changes

Done in this phase:

| File | Change |
|---|---|
| `resources/views/dashboard.blade.php` | `$cardAccessPreview` gate (local/testing + `credential.view`); CSS link, sidebar item after Hak Akses, `#cardAccessTab` section, `APP_CONFIG.cardAccessPreview`, script tag — all inside the gate |
| `public/js/dashboard.js` | one line in `switchTab()` to call `CardAccess.load('cardAccessRoot')` |
| `public/js/card-access.js` | **new** — state model, adapters, components, page controller, flows, state gallery |
| `public/css/card-access.css` | **new** — `.ca-*` styles on existing tokens, responsive rules |
| `tests/Feature/CardAccessPreviewTest.php` | **new** — nav placement, permission gate, production gate, no-direct-call guardrails |
| `docs/card-access/build-prototype.mjs` | **new** — standalone prototype builder |
| `docs/card-access/PHASE_1_DESIGN.md` | **new** — this document |

Expected in Phase 2 (after contract lock):

| File | Change |
|---|---|
| `public/js/card-access.js` | add `ApiCardAccessAdapter` implementing `ADAPTER_METHODS` via `apiFetch` (409/422 → error catalogue mapping); remove scenario pickers from production path |
| `public/js/dashboard.js` | `normalizeCapability()` entries for new endpoints, if the RBAC contract needs them |
| `resources/views/dashboard.blade.php` | replace the environment gate with the permission gate only |
| `public/js/card-access-nfc.js` (new, optional) | Web NFC (`NDEFReader`) or Android bridge reader, isolated from UI |
| Existing **Hak Akses → Credential Center / Antrean ISAPI** sub-tabs | decide whether they stay, link to Card Access, or are retired (product decision) |

Not touched: backend, models, migrations, policies, `PortalAccess`, Hikvision services.

## 26. Backend Dependencies

Each item below is a contract the UI needs; none is assumed final. Existing endpoints that look
related are listed for ISHAK's reference only.

| # | Contract | UI consumer (adapter method) | Notes / related today |
|---|---|---|---|
| 1 | Canonical card identity & masking | everywhere | `CredentialRecord.masked_identifier`, `card_number_hash`. UI only ever needs masked value + last-4 search |
| 2 | Lifecycle state semantics | `listCards`, `getEmployeeAccess` | Six states in §10 of brief; who computes ACTIVE_NOT_SYNCED vs NEEDS_VERIFICATION |
| 3 | Reconciliation fields | drawer D, mismatch | `person_match`, `credential_match`, `access_match` ∈ MATCH/MISMATCH/MISSING/UNKNOWN/PENDING + device-side masked card, access, person name. Related: `PhysicalUserReconciliationService` |
| 4 | Access entitlement | `getAccessCatalog`, `getAccessAssignment`, `previewAccessChange`, `applyAccessChange` | buildings → doors, profiles, validity, reason; server-side diff. Related: `/access/profiles`, door assignments |
| 5 | Device verification | `verifyOnDevice`, `verification`, `last_verified_at` | What "verified" means physically; timeout value |
| 6 | RBAC | all | Confirm `CAN_*` → `credential.view/manage/sync/revoke`, `access.manage`, `audit.view`; optional per-record `allowed_actions` |
| 7 | KPI endpoint | `getKpis`, `getSyncHealth`, `getAttentionQueue` | Authoritative totals + `as_of`. Related: `/access/metrics` |
| 8 | Pagination & filters | `listCards`, `listDeviceSync` | search fields, filter keys, `meta.total/page/per_page` |
| 9 | NFC enrollment | `searchEnrollmentCandidates`, `validateScannedCard`, `registerCard` | eligibility + reason codes; validate-before-register; idempotency key |
| 10 | Replace / disable / revoke | `replaceCard`, `disableCard`, `revokeCard` | old-card policy, reasons, whether two cards may coexist. Related: `/access/credentials/{id}/revoke`, `/employees/{id}/block-lost-card` |
| 11 | Sync / retry | `retrySync`, `listDeviceSync`, `getDiagnostics` | job status per terminal, partial success. Related: `/access/device-syncs`, `/device-syncs/{id}/retry` |
| 12 | Audit & activity | `getRecentActivity`, `getAuditHistory` | event types in §6 of brief, operator, target, result |
| 13 | NFC reader channel | — | Web NFC in Chrome Android vs native bridge; which UID format (hex, byte order) |

---

# YAZIED PHASE 1 + 1.5

## DESIGN STATUS: **DESIGN READY**

Integration is **BLOCKED** on Backend Contract Lock — ISHAK (by design; nothing below needs it to proceed with review).

### A. Screens completed
Overview (KPI, attention queue, recent activity, sync health) · Cards (table, filters, search,
pagination, action menu, mobile cards, filter sheet) · Employee Access Profile drawer (4 domains,
mismatch, verification, audit) · Access Permissions (matrix, schedule, review changes) · Device Sync
(three-state monitor, diagnostics) · Register NFC Card flow (employee, scan/validate, access, review,
register & sync, success / sync failed / verification timeout, retry) · Replace Card wizard · Disable
dialog · Revoke dialog · Audit dialog · State gallery (all statuses, mismatches, verification, errors,
empty, loading, NFC, permission states).

### B. Components prepared
All components listed in §23, as pure render functions in `public/js/card-access.js`, plus the state model,
display guard, capability mapping, error/empty catalogues, and two adapters.

### C. Responsive behavior
Desktop table-first; ≤1280 KPI 3-col; ≤1024 compact tables + stacked layouts; ≤768 cards, bottom
sheets, action sheets, full-screen drawer and flows, 44 px targets, safe-area padding. Verified by
headless screenshots at 1440, 900 and 390 px.

### D. Existing SecureGate components reused
Shell, sidebar and `switchTab`; `.metric-label/.metric-value`; `.table-container`, `.table-toolbar`,
`.search-box`, table styles; `.badge` + `.badge-success/warning/danger/dim/info`;
`.btn-primary/.btn-secondary/.btn-sm`; `.modal-overlay/.modal-card/.modal-header/.modal-title/
.modal-close-btn`; `.form-row`; `.ats-subnav .subnav-btn`; `.spinner-sm`; `fadeIn`; `showToast`;
`APP_CONFIG.permissions/admin`; all colour tokens.

### E. Backend dependencies
§26, items 1–13.

### F. Remaining blockers
1. Backend Contract Lock for items 1–12 (§26).
2. Product decision on overlap with **Hak Akses → Credential Center / Antrean Perangkat ISAPI**.
3. NFC reader channel decision (Web NFC vs Android bridge) and UID format (§26-13).
4. RBAC confirmation for Infra roles; `audit.view` is currently super-admin-only in the sidebar, so
   Infra Admin may not see audit history unless the contract changes that.

### G. Files expected to change
See §25 (done now vs Phase 2).

### H. Recommended implementation sequence (Phase 2, after approval)
1. Lock contracts 2, 6, 7, 8 → implement read-only `ApiCardAccessAdapter` for Overview, Cards, drawer.
2. Contract 3 + 5 → Device Sync and verification with real reconciliation data.
3. Contract 4 → Access Permissions with server-side diff and apply.
4. Contract 11 → Re-Sync / Retry / Diagnostics.
5. Contract 10 → Disable, Revoke, Replace.
6. Contracts 9 + 13 → NFC enrollment on a real Android device (field test with Infra).
7. Remove the environment gate; keep the permission gate. Decide fate of overlapping Hak Akses tabs.
8. Accessibility pass (focus trap in drawer/dialogs, keyboard navigation of menus) and Infra UAT.

### I. Risks / UX concerns
- **Two places for credentials**: Hak Akses already has Credential Center and an ISAPI queue. Without
  a decision, operators may act in the wrong place.
- **"Active" KPI semantics**: whether ACTIVE counts ACTIVE_NOT_SYNCED must be defined by backend;
  the UI shows exactly what the endpoint returns.
- **Web NFC availability**: Chrome on Android only, HTTPS only, NDEF-centric; many access cards
  expose only a UID. A native bridge may be required.
- **UID format drift**: the same card can read differently (hex vs decimal, byte order) on phone vs
  Hikvision reader — a classic source of false CARD MISMATCH.
- **Verification latency**: if device confirmation is slow, operators may leave the Sync step before
  verification; the copy says access is not active until verified, but a background status update is needed.
- **Dense drawer footer**: up to six actions; acceptable on desktop, two-column sticky footer on phone.
- **Accessibility gaps** in this phase: no focus trap in drawer/dialogs; menus are click-only (Esc closes).
- **Language mix**: status vocabulary is English (as specified), explanatory copy is Indonesian,
  consistent with the rest of SecureGate.

**Phase 2 implementation will not start until explicitly approved.**
