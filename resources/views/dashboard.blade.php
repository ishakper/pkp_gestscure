<!DOCTYPE html>
<html lang="id">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>PKP SecureGate - Centralized Multi-Building Access Control</title>
    <link rel="icon" type="image/svg+xml" href="{{ asset('favicon.svg') }}">
    <link rel="apple-touch-icon" href="{{ asset('favicon.svg') }}">
    <link rel="preconnect" href="https://fonts.googleapis.com">
    <link rel="preconnect" href="https://fonts.gstatic.com" crossorigin>
    <link href="https://fonts.googleapis.com/css2?family=Plus+Jakarta+Sans:wght@300;400;500;600;700;800&display=swap" rel="stylesheet">
    <script>
        (function() {
            try {
                var isOpen = localStorage.getItem('pkp_sidebar_open');
                if (isOpen === null) {
                    isOpen = (localStorage.getItem('pkp_sidebar_collapsed') !== 'true');
                } else {
                    isOpen = (isOpen === 'true');
                }
                if (isOpen && window.innerWidth >= 768) {
                    document.documentElement.classList.add('sidebar-open');
                } else {
                    document.documentElement.classList.remove('sidebar-open');
                }
            } catch(e) {}
        })();
    </script>
    <style>
        :root {
            --bg-base: #090d16;
            --sidebar-bg: #0f172a;
            --card-bg: #1e293b;
            --card-hover: #27354a;
            --border-color: rgba(255, 255, 255, 0.08);
            --border-focus: rgba(56, 189, 248, 0.4);
            --primary: #38bdf8;
            --primary-hover: #0284c7;
            --accent: #6366f1;
            --success: #10b981;
            --warning: #f59e0b;
            --danger: #ef4444;
            --text-main: #f8fafc;
            --text-muted: #94a3b8;
            --text-dim: #64748b;
        }

        * {
            box-sizing: border-box;
            margin: 0;
            padding: 0;
            font-family: 'Plus Jakarta Sans', -apple-system, BlinkMacSystemFont, sans-serif;
        }

        body {
            background-color: var(--bg-base);
            color: var(--text-main);
            min-height: 100vh;
            display: flex;
            overflow-x: hidden;
        }

        /* Anti-flicker pre-load state */
        html:not(.sidebar-open) .sidebar {
            transform: translateX(-100%) !important;
            visibility: hidden !important;
            pointer-events: none !important;
            overflow: hidden !important;
            transition: none !important;
        }

        html:not(.sidebar-open) .main-content {
            margin-left: 0 !important;
            max-width: 100vw !important;
            width: 100% !important;
            transition: none !important;
        }

        html:not(.sidebar-open) .nav-list,
        html:not(.sidebar-open) .user-profile {
            opacity: 0 !important;
            visibility: hidden !important;
            pointer-events: none !important;
            transition: none !important;
        }

        html.sidebar-open .sidebar {
            transform: translateX(0) !important;
            visibility: visible !important;
            pointer-events: auto !important;
        }

        html.sidebar-open .main-content {
            margin-left: 270px !important;
            max-width: calc(100vw - 270px) !important;
        }

        /* Sidebar Backdrop (Mobile Overlay) */
        .sidebar-backdrop {
            position: fixed;
            top: 0;
            left: 0;
            right: 0;
            bottom: 0;
            background: rgba(3, 7, 18, 0.75);
            backdrop-filter: blur(4px);
            -webkit-backdrop-filter: blur(4px);
            z-index: 95;
            opacity: 0;
            pointer-events: none;
            transition: opacity 300ms cubic-bezier(0.4, 0, 0.2, 1);
        }

        .sidebar-backdrop.active {
            opacity: 1;
            pointer-events: auto;
        }

        /* ==========================================
           Floating Logo Architecture
           ========================================== */
        .cursor-pointer {
            cursor: pointer;
        }

        .floating-logo {
            position: fixed;
            z-index: 999;
            display: inline-flex;
            align-items: center;
            gap: 0.85rem;
            padding: 0.35rem 0.65rem 0.35rem 0.35rem;
            border-radius: 0.85rem;
            border: 1px solid transparent;
            user-select: none;
            cursor: pointer;
            white-space: nowrap;
            /* Default (Sidebar Closed): Aligned to Topbar */
            top: 34px;
            left: 40px;
            transform: scale(0.92);
            transform-origin: left center;
            transition: all 0.4s cubic-bezier(0.25, 1, 0.5, 1);
        }

        /* Hover effects on Floating Logo */
        .floating-logo:hover {
            background: rgba(56, 189, 248, 0.08);
            border-color: rgba(56, 189, 248, 0.25);
        }

        .floating-logo:hover .floating-logo-icon {
            border-color: #38bdf8;
            box-shadow: 0 0 20px rgba(56, 189, 248, 0.5);
            transform: scale(1.06);
        }

        .floating-logo:hover .floating-logo-title {
            color: #38bdf8;
            background: linear-gradient(to right, #38bdf8, #818cf8);
            -webkit-background-clip: text;
            -webkit-text-fill-color: transparent;
        }

        .floating-logo:active {
            transform: scale(0.88);
        }

        /* Sidebar Open State: Logo glides smoothly into the Sidebar header */
        body.sidebar-open .floating-logo,
        html.sidebar-open .floating-logo {
            top: 24px;
            left: 20px;
            transform: scale(1);
            background: transparent;
            border-color: transparent;
        }

        body.sidebar-open .floating-logo:hover {
            background: rgba(56, 189, 248, 0.06);
            border-color: rgba(56, 189, 248, 0.2);
        }

        body.sidebar-open .floating-logo:active {
            transform: scale(0.96);
        }

        .floating-logo-icon {
            width: 44px;
            height: 44px;
            border-radius: 50%;
            border: 1.5px solid rgba(56, 189, 248, 0.6);
            background: radial-gradient(circle at 35% 30%, rgba(56, 189, 248, 0.22), rgba(15, 23, 42, 0.95));
            display: flex;
            align-items: center;
            justify-content: center;
            box-shadow: 0 0 12px rgba(56, 189, 248, 0.25);
            overflow: hidden;
            flex-shrink: 0;
            padding: 3px;
            transition: all 0.3s cubic-bezier(0.25, 1, 0.5, 1);
        }

        .floating-logo-img {
            width: 100%;
            height: 100%;
            border-radius: 50%;
            object-fit: contain;
            filter: drop-shadow(0 2px 4px rgba(0, 173, 255, 0.35));
        }

        .floating-logo-text {
            display: flex;
            flex-direction: column;
        }

        .floating-logo-title {
            font-size: 1.15rem;
            font-weight: 800;
            letter-spacing: -0.02em;
            background: linear-gradient(to right, #ffffff, #cbd5e1);
            -webkit-background-clip: text;
            -webkit-text-fill-color: transparent;
            line-height: 1.2;
            transition: all 0.3s ease;
        }

        .floating-logo-subtitle {
            font-size: 0.72rem;
            color: var(--primary);
            font-weight: 600;
            margin-top: 2px;
            line-height: 1.2;
            opacity: 0.95;
            transition: color 0.3s ease;
        }

        /* Placeholders to preserve layout without collisions */
        .top-bar-logo-placeholder {
            width: 250px;
            height: 48px;
            flex-shrink: 0;
            pointer-events: none;
            visibility: hidden;
        }

        .sidebar-logo-placeholder {
            height: 52px;
            margin-bottom: 1.5rem;
            padding-bottom: 1.5rem;
            border-bottom: 1px solid var(--border-color);
            display: flex;
            align-items: center;
            justify-content: flex-end;
            position: relative;
            flex-shrink: 0;
        }

        /* Sidebar Structure & Smooth Transitions */
        .sidebar {
            width: 270px;
            background: var(--sidebar-bg);
            border-right: 1px solid var(--border-color);
            display: flex;
            flex-direction: column;
            padding: 1.5rem 1rem;
            position: fixed;
            top: 0;
            bottom: 0;
            left: 0;
            z-index: 50;
            transform: translateX(-100%);
            visibility: hidden;
            pointer-events: none;
            overflow-x: hidden;
            overflow-y: auto;
            white-space: nowrap;
            box-shadow: 10px 0 35px rgba(0, 0, 0, 0.35);
            transition: transform 0.4s cubic-bezier(0.25, 1, 0.5, 1), visibility 0.4s ease;
        }

        body.sidebar-open .sidebar,
        html.sidebar-open .sidebar {
            transform: translateX(0);
            visibility: visible;
            pointer-events: auto;
        }

        .sidebar::-webkit-scrollbar {
            width: 4px;
        }
        .sidebar::-webkit-scrollbar-thumb {
            background: rgba(255, 255, 255, 0.1);
            border-radius: 4px;
        }

        /* Main Content Area */
        .main-content {
            margin-left: 0;
            flex: 1;
            padding: 2rem 2.5rem;
            max-width: 100vw;
            width: 100%;
            min-height: 100vh;
            box-sizing: border-box;
            transition: margin-left 0.4s cubic-bezier(0.25, 1, 0.5, 1), max-width 0.4s cubic-bezier(0.25, 1, 0.5, 1);
        }

        body.sidebar-open .main-content,
        html.sidebar-open .main-content {
            margin-left: 270px;
            max-width: calc(100vw - 270px);
        }

        /* Smooth fade-out of internal navigation items when closed */
        .sidebar .nav-list,
        .sidebar .nav-item,
        .sidebar .nav-item button,
        .sidebar .nav-icon,
        .sidebar .nav-text,
        .sidebar .user-profile,
        .sidebar .user-avatar,
        .sidebar .user-info,
        .sidebar .btn-logout {
            opacity: 0;
            visibility: hidden;
            pointer-events: none;
            transition: opacity 0.25s cubic-bezier(0.25, 1, 0.5, 1), visibility 0.25s;
        }

        body.sidebar-open .sidebar .nav-list,
        body.sidebar-open .sidebar .nav-item,
        body.sidebar-open .sidebar .nav-item button,
        body.sidebar-open .sidebar .nav-icon,
        body.sidebar-open .sidebar .nav-text,
        body.sidebar-open .sidebar .user-profile,
        body.sidebar-open .sidebar .user-avatar,
        body.sidebar-open .sidebar .user-info,
        body.sidebar-open .sidebar .btn-logout,
        html.sidebar-open .sidebar .nav-list,
        html.sidebar-open .sidebar .nav-item,
        html.sidebar-open .sidebar .nav-item button,
        html.sidebar-open .sidebar .nav-icon,
        html.sidebar-open .sidebar .nav-text,
        html.sidebar-open .sidebar .user-profile,
        html.sidebar-open .sidebar .user-avatar,
        html.sidebar-open .sidebar .user-info,
        html.sidebar-open .sidebar .btn-logout {
            opacity: 1;
            visibility: visible;
            pointer-events: auto;
        }

        /* Close button inside Sidebar header (Mobile Only) */
        .btn-sidebar-close {
            margin-left: auto;
            background: rgba(255, 255, 255, 0.05);
            border: 1px solid var(--border-color);
            border-radius: 0.5rem;
            color: var(--text-muted);
            width: 32px;
            height: 32px;
            display: none;
            align-items: center;
            justify-content: center;
            cursor: pointer;
            transition: all 0.2s cubic-bezier(0.4, 0, 0.2, 1);
            flex-shrink: 0;
        }

        .btn-sidebar-close:hover {
            background: rgba(239, 68, 68, 0.18);
            color: #f87171;
            border-color: rgba(239, 68, 68, 0.4);
            transform: scale(1.08);
        }

        .nav-list {
            list-style: none;
            display: flex;
            flex-direction: column;
            gap: 0.4rem;
        }

        .nav-item button {
            width: 100%;
            display: flex;
            align-items: center;
            gap: 0.85rem;
            padding: 0.8rem 1rem;
            background: transparent;
            border: none;
            border-radius: 0.65rem;
            color: var(--text-muted);
            font-size: 0.9rem;
            font-weight: 500;
            cursor: pointer;
            text-align: left;
            position: relative;
            white-space: nowrap;
            transition: background 200ms ease, color 200ms ease;
        }

        .nav-icon {
            font-size: 1.15rem;
            display: inline-flex;
            align-items: center;
            justify-content: center;
            flex-shrink: 0;
            width: 24px;
        }

        .nav-text {
            display: inline-block;
            white-space: nowrap;
            overflow: hidden;
            text-overflow: ellipsis;
            color: inherit;
            vertical-align: middle;
        }

        .nav-item button:hover {
            background: rgba(255, 255, 255, 0.04);
            color: var(--text-main);
        }

        .nav-item button.active {
            background: rgba(56, 189, 248, 0.12);
            color: var(--primary);
            font-weight: 600;
            box-shadow: inset 3px 0 0 var(--primary);
        }

        /* User Profile */
        .user-profile {
            margin-top: auto;
            padding-top: 1.2rem;
            border-top: 1px solid var(--border-color);
            display: flex;
            align-items: center;
            justify-content: space-between;
            gap: 0.5rem;
        }

        .user-avatar {
            width: 36px;
            height: 36px;
            border-radius: 0.65rem;
            background: linear-gradient(135deg, var(--primary), var(--accent));
            color: #ffffff;
            font-weight: 800;
            font-size: 0.95rem;
            display: flex;
            align-items: center;
            justify-content: center;
            flex-shrink: 0;
            box-shadow: 0 4px 12px rgba(56, 189, 248, 0.3);
            cursor: default;
        }

        .user-info {
            font-size: 0.825rem;
            overflow: hidden;
            display: block;
            max-width: 140px;
        }

        .user-name {
            font-weight: 600;
            color: #ffffff;
            text-overflow: ellipsis;
            overflow: hidden;
            white-space: nowrap;
        }

        .user-role {
            color: var(--primary);
            font-size: 0.75rem;
            font-weight: 500;
            white-space: nowrap;
        }

        .btn-logout {
            background: rgba(239, 68, 68, 0.12);
            border: 1px solid rgba(239, 68, 68, 0.3);
            color: #fca5a5;
            padding: 0.45rem 0.75rem;
            border-radius: 0.5rem;
            cursor: pointer;
            font-size: 0.75rem;
            font-weight: 600;
            transition: all 0.2s ease;
            display: inline-flex;
            align-items: center;
            gap: 0.35rem;
            position: relative;
        }

        .btn-logout:hover {
            background: rgba(239, 68, 68, 0.25);
            color: #ffffff;
            border-color: rgba(239, 68, 68, 0.5);
        }

        /* Top Bar Layout & Logo Sidebar Toggle Trigger */
        .top-bar {
            display: flex;
            align-items: center;
            justify-content: space-between;
            margin-bottom: 2rem;
            gap: 1rem;
            flex-wrap: wrap;
            position: sticky;
            top: 0;
            z-index: 45;
            background: rgba(9, 13, 22, 0.88);
            backdrop-filter: blur(8px);
            -webkit-backdrop-filter: blur(8px);
            padding: 0.75rem 0 1rem 0;
        }

        .top-bar-left {
            display: flex;
            align-items: center;
            gap: 1rem;
        }

        .top-bar-actions {
            display: flex;
            gap: 0.75rem;
            align-items: center;
            flex-wrap: wrap;
        }

        .page-title {
            font-size: 1.55rem;
            font-weight: 800;
            letter-spacing: -0.02em;
            background: linear-gradient(to right, #ffffff, #cbd5e1);
            -webkit-background-clip: text;
            -webkit-text-fill-color: transparent;
            line-height: 1.2;
        }

        .page-subtitle {
            font-size: 0.85rem;
            color: var(--text-muted);
            margin-top: 0.15rem;
            font-weight: 500;
        }

        /* Top Metric Cards */
        .metrics-grid {
            display: grid;
            grid-template-columns: repeat(auto-fit, minmax(220px, 1fr));
            gap: 1.25rem;
            margin-bottom: 2.25rem;
        }

        .metric-card {
            background: var(--card-bg);
            border: 1px solid var(--border-color);
            border-radius: 1rem;
            padding: 1.25rem 1.5rem;
            display: flex;
            align-items: center;
            gap: 1.25rem;
            transition: transform 0.2s ease, border-color 0.2s ease;
        }

        .metric-card:hover {
            transform: translateY(-2px);
            border-color: rgba(255, 255, 255, 0.15);
        }

        .metric-icon-box {
            width: 52px;
            height: 52px;
            border-radius: 0.85rem;
            display: flex;
            align-items: center;
            justify-content: center;
            font-size: 1.5rem;
            flex-shrink: 0;
        }

        .icon-blue { background: rgba(56, 189, 248, 0.12); color: var(--primary); }
        .icon-green { background: rgba(16, 185, 129, 0.12); color: var(--success); }
        .icon-indigo { background: rgba(99, 102, 241, 0.12); color: var(--accent); }
        .icon-red { background: rgba(239, 68, 68, 0.12); color: var(--danger); }

        .metric-label {
            font-size: 0.8rem;
            font-weight: 500;
            color: var(--text-muted);
            text-transform: uppercase;
            letter-spacing: 0.05em;
        }

        .metric-value {
            font-size: 1.65rem;
            font-weight: 800;
            color: #ffffff;
            line-height: 1.2;
            margin-top: 0.2rem;
        }

        /* Section Containers */
        .section-header {
            display: flex;
            align-items: center;
            justify-content: space-between;
            margin-bottom: 1.25rem;
            gap: 1rem;
        }

        .section-title {
            font-size: 1.15rem;
            font-weight: 700;
            display: flex;
            align-items: center;
            gap: 0.5rem;
        }

        .section-desc {
            font-size: 0.825rem;
            color: var(--text-muted);
            margin-top: 0.2rem;
        }

        /* Door Cards Grid */
        .doors-grid {
            display: grid;
            grid-template-columns: repeat(auto-fit, minmax(280px, 1fr));
            gap: 1.25rem;
            margin-bottom: 2.5rem;
        }

        .door-card {
            background: var(--card-bg);
            border: 1px solid var(--border-color);
            border-radius: 1rem;
            padding: 1.4rem;
            position: relative;
            transition: all 0.2s ease;
        }

        .door-card:hover {
            border-color: rgba(56, 189, 248, 0.3);
            background: var(--card-hover);
        }

        .card-header {
            display: flex;
            align-items: center;
            justify-content: space-between;
            margin-bottom: 1rem;
        }

        .door-code-badge {
            display: flex;
            align-items: center;
            gap: 0.5rem;
        }

        .door-chip {
            background: rgba(56, 189, 248, 0.15);
            color: var(--primary);
            font-weight: 700;
            font-size: 0.8rem;
            padding: 0.2rem 0.55rem;
            border-radius: 0.4rem;
            border: 1px solid rgba(56, 189, 248, 0.3);
        }

        .door-loc {
            font-size: 0.8rem;
            color: var(--text-muted);
            font-weight: 500;
        }

        .status-badge {
            display: inline-flex;
            align-items: center;
            gap: 0.35rem;
            padding: 0.25rem 0.65rem;
            border-radius: 2rem;
            font-size: 0.75rem;
            font-weight: 700;
        }

        .status-online {
            background: rgba(16, 185, 129, 0.15);
            color: #6ee7b7;
            border: 1px solid rgba(16, 185, 129, 0.3);
            box-shadow: 0 0 10px rgba(16, 185, 129, 0.15);
        }

        .status-offline {
            background: rgba(239, 68, 68, 0.15);
            color: #fca5a5;
            border: 1px solid rgba(239, 68, 68, 0.3);
        }

        .status-dot {
            width: 7px;
            height: 7px;
            border-radius: 50%;
            background: currentColor;
        }

        .door-name-title {
            font-size: 1.1rem;
            font-weight: 700;
            color: #ffffff;
            margin-bottom: 0.85rem;
        }

        .door-specs {
            background: rgba(15, 23, 42, 0.6);
            border: 1px solid var(--border-color);
            border-radius: 0.65rem;
            padding: 0.75rem 0.9rem;
            margin-bottom: 1rem;
            font-size: 0.8rem;
            display: flex;
            flex-direction: column;
            gap: 0.35rem;
        }

        .spec-item {
            display: flex;
            justify-content: space-between;
            align-items: center;
        }

        .spec-label { color: var(--text-muted); }
        .spec-code { color: #a5f3fc; font-family: monospace; font-size: 0.8rem; }
        .spec-val { color: var(--text-main); font-weight: 500; }
        .spec-val.highlight { color: var(--primary); font-weight: 700; }

        .door-actions {
            display: grid;
            grid-template-columns: 1fr auto;
            gap: 0.5rem;
        }

        /* Buttons */
        .btn-primary {
            background: linear-gradient(135deg, var(--primary), var(--accent));
            border: none;
            border-radius: 0.6rem;
            padding: 0.65rem 1.25rem;
            color: #ffffff;
            font-weight: 600;
            font-size: 0.875rem;
            cursor: pointer;
            display: inline-flex;
            align-items: center;
            gap: 0.5rem;
            transition: all 0.2s ease;
            box-shadow: 0 4px 12px rgba(56, 189, 248, 0.2);
        }

        .btn-primary:hover {
            opacity: 0.92;
            transform: translateY(-1px);
        }

        .btn-secondary {
            background: rgba(255, 255, 255, 0.06);
            border: 1px solid var(--border-color);
            border-radius: 0.6rem;
            padding: 0.6rem 1.1rem;
            color: #ffffff;
            font-weight: 600;
            font-size: 0.875rem;
            cursor: pointer;
            transition: all 0.2s ease;
        }

        .btn-secondary:hover {
            background: rgba(255, 255, 255, 0.12);
        }

        .btn-sm {
            padding: 0.4rem 0.75rem;
            font-size: 0.75rem;
            border-radius: 0.45rem;
            border: none;
            cursor: pointer;
            font-weight: 600;
            display: inline-flex;
            align-items: center;
            gap: 0.35rem;
            transition: all 0.15s ease;
        }

        .btn-override {
            background: rgba(245, 158, 11, 0.15);
            color: #fcd34d;
            border: 1px solid rgba(245, 158, 11, 0.3);
        }
        .btn-override:hover { background: rgba(245, 158, 11, 0.25); }

        .btn-ping {
            background: rgba(56, 189, 248, 0.15);
            color: var(--primary);
            border: 1px solid rgba(56, 189, 248, 0.3);
        }
        .btn-ping:hover { background: rgba(56, 189, 248, 0.25); }

        .btn-assign {
            background: rgba(99, 102, 241, 0.15);
            color: #a5b4fc;
            border: 1px solid rgba(99, 102, 241, 0.3);
        }
        .btn-assign:hover { background: rgba(99, 102, 241, 0.3); }

        .btn-edit {
            background: rgba(56, 189, 248, 0.15);
            color: var(--primary);
            border: 1px solid rgba(56, 189, 248, 0.3);
        }
        .btn-edit:hover { background: rgba(56, 189, 248, 0.25); }

        .btn-delete {
            background: rgba(239, 68, 68, 0.15);
            color: #fca5a5;
            border: 1px solid rgba(239, 68, 68, 0.3);
        }
        .btn-delete:hover { background: rgba(239, 68, 68, 0.25); }

        /* Tables & Data Containers */
        .table-container {
            background: var(--card-bg);
            border: 1px solid var(--border-color);
            border-radius: 1rem;
            overflow: hidden;
            margin-bottom: 2.5rem;
            box-shadow: 0 4px 20px rgba(0, 0, 0, 0.2);
        }

        .table-toolbar {
            padding: 1.1rem 1.4rem;
            border-bottom: 1px solid var(--border-color);
            display: flex;
            align-items: center;
            justify-content: space-between;
            gap: 1rem;
            flex-wrap: wrap;
            background: rgba(15, 23, 42, 0.4);
        }

        .toolbar-left, .toolbar-right {
            display: flex;
            align-items: center;
            gap: 0.75rem;
            flex-wrap: wrap;
        }

        .search-box {
            display: flex;
            align-items: center;
            gap: 0.5rem;
            background: rgba(15, 23, 42, 0.8);
            border: 1px solid var(--border-color);
            border-radius: 0.5rem;
            padding: 0.45rem 0.85rem;
            transition: border-color 0.2s ease;
        }

        .search-box:focus-within {
            border-color: var(--primary);
        }

        .search-box input, .search-box select {
            background: transparent;
            border: none;
            color: #ffffff;
            font-size: 0.85rem;
            outline: none;
        }

        .search-box input::placeholder {
            color: var(--text-dim);
        }

        .search-box select option {
            background: var(--sidebar-bg);
            color: #ffffff;
        }

        table {
            width: 100%;
            border-collapse: collapse;
            text-align: left;
            font-size: 0.85rem;
        }

        th {
            background: rgba(15, 23, 42, 0.7);
            padding: 0.9rem 1.25rem;
            color: var(--text-muted);
            font-weight: 600;
            border-bottom: 1px solid var(--border-color);
            font-size: 0.775rem;
            text-transform: uppercase;
            letter-spacing: 0.05em;
        }

        td {
            padding: 1rem 1.25rem;
            border-bottom: 1px solid var(--border-color);
            color: var(--text-main);
            vertical-align: middle;
        }

        tr:hover td {
            background: rgba(255, 255, 255, 0.02);
        }

        /* Badges & Pills */
        .badge {
            display: inline-flex;
            align-items: center;
            gap: 0.35rem;
            padding: 0.25rem 0.55rem;
            border-radius: 0.4rem;
            font-size: 0.75rem;
            font-weight: 600;
        }

        .badge-dot { width: 6px; height: 6px; border-radius: 50%; background: currentColor; }

        .badge-success { background: rgba(16, 185, 129, 0.15); color: #6ee7b7; border: 1px solid rgba(16, 185, 129, 0.3); }
        .badge-info { background: rgba(56, 189, 248, 0.15); color: #7dd3fc; border: 1px solid rgba(56, 189, 248, 0.3); }
        .badge-warning { background: rgba(245, 158, 11, 0.15); color: #fcd34d; border: 1px solid rgba(245, 158, 11, 0.3); }
        .badge-danger { background: rgba(239, 68, 68, 0.15); color: #fca5a5; border: 1px solid rgba(239, 68, 68, 0.3); }
        .badge-dim { background: rgba(255, 255, 255, 0.06); color: var(--text-muted); border: 1px solid var(--border-color); }

        .badge-synced { background: rgba(16, 185, 129, 0.18); color: #6ee7b7; border: 1px solid rgba(16, 185, 129, 0.35); }
        .badge-pending { background: rgba(245, 158, 11, 0.18); color: #fcd34d; border: 1px solid rgba(245, 158, 11, 0.35); }
        .badge-failed { background: rgba(239, 68, 68, 0.18); color: #fca5a5; border: 1px solid rgba(239, 68, 68, 0.35); }

        .badge-granted { background: rgba(16, 185, 129, 0.2); color: #6ee7b7; border: 1px solid rgba(16, 185, 129, 0.4); font-weight: 700; }
        .badge-denied { background: rgba(239, 68, 68, 0.2); color: #fca5a5; border: 1px solid rgba(239, 68, 68, 0.4); font-weight: 700; }
        .badge-alarm { background: rgba(239, 68, 68, 0.25); color: #f87171; border: 1px solid #ef4444; font-weight: 700; box-shadow: 0 0 8px rgba(239, 68, 68, 0.35); }
        .badge-duress { background: rgba(245, 158, 11, 0.25); color: #fbbf24; border: 1px solid #f59e0b; font-weight: 700; box-shadow: 0 0 8px rgba(245, 158, 11, 0.35); }

        .door-pills-wrap {
            display: flex;
            flex-wrap: wrap;
            gap: 0.35rem;
        }

        .bio-pill-group {
            display: flex;
            gap: 0.4rem;
        }

        .user-id-box strong { color: #ffffff; }
        .user-id-box .user-nik { color: var(--text-muted); font-size: 0.75rem; display: block; font-family: monospace; }
        .card-no-sub { font-size: 0.75rem; color: var(--text-muted); display: block; font-family: monospace; }

        .method-chip {
            display: inline-flex;
            align-items: center;
            gap: 0.3rem;
            padding: 0.2rem 0.5rem;
            border-radius: 0.35rem;
            font-size: 0.75rem;
            font-weight: 600;
        }
        .method-card { background: rgba(56, 189, 248, 0.12); color: #38bdf8; }
        .method-fp { background: rgba(99, 102, 241, 0.12); color: #818cf8; }

        .ip-code { font-family: monospace; color: #a5f3fc; font-size: 0.8rem; }
        .unknown-user { color: #fca5a5; font-style: italic; font-size: 0.8rem; }

        /* Modals */
        .modal-overlay {
            position: fixed;
            top: 0; left: 0; right: 0; bottom: 0;
            background: rgba(0, 0, 0, 0.75);
            backdrop-filter: blur(6px);
            z-index: 100;
            display: none;
            align-items: center;
            justify-content: center;
            padding: 1.5rem;
        }

        .modal-overlay.active {
            display: flex;
            animation: fadeIn 0.2s ease-out;
        }

        .modal-card {
            background: var(--sidebar-bg);
            border: 1px solid var(--border-color);
            border-radius: 1.25rem;
            width: 100%;
            max-width: 540px;
            padding: 1.75rem;
            max-height: 90vh;
            overflow-y: auto;
            box-shadow: 0 20px 40px rgba(0, 0, 0, 0.4);
        }

        .modal-header {
            display: flex;
            justify-content: space-between;
            align-items: flex-start;
            margin-bottom: 1.25rem;
            padding-bottom: 1rem;
            border-bottom: 1px solid var(--border-color);
        }

        .modal-title { font-size: 1.2rem; font-weight: 700; color: #ffffff; }
        .modal-close-btn { background: none; border: none; color: var(--text-muted); font-size: 1.25rem; cursor: pointer; }
        .modal-close-btn:hover { color: #ffffff; }

        .form-row { margin-bottom: 1.1rem; }
        .form-row label { display: block; font-size: 0.8rem; color: var(--text-muted); margin-bottom: 0.4rem; font-weight: 500; }
        .form-row input, .form-row select {
            width: 100%;
            background: var(--card-bg);
            border: 1px solid var(--border-color);
            padding: 0.65rem 0.85rem;
            border-radius: 0.6rem;
            color: #ffffff;
            outline: none;
            font-size: 0.875rem;
            transition: border-color 0.2s;
        }
        .form-row input:focus, .form-row select:focus {
            border-color: var(--primary);
        }

        /* Door Selection Cards inside Assignment Modal */
        .door-checkboxes-grid {
            display: grid;
            grid-template-columns: 1fr 1fr;
            gap: 0.75rem;
            margin: 1.2rem 0;
        }

        .door-checkbox-card {
            background: var(--card-bg);
            border: 1px solid var(--border-color);
            border-radius: 0.75rem;
            padding: 0.85rem;
            display: flex;
            align-items: flex-start;
            gap: 0.65rem;
            cursor: pointer;
            transition: all 0.2s ease;
        }

        .door-checkbox-card:hover {
            border-color: rgba(56, 189, 248, 0.4);
            background: var(--card-hover);
        }

        .door-checkbox-card.selected {
            border-color: var(--primary);
            background: rgba(56, 189, 248, 0.08);
        }

        .door-checkbox-card input[type="checkbox"] {
            margin-top: 0.2rem;
            accent-color: var(--primary);
            cursor: pointer;
        }

        .checkbox-door-code { font-weight: 700; color: var(--primary); font-size: 0.85rem; }
        .checkbox-door-name { font-size: 0.8rem; font-weight: 600; color: #ffffff; }
        .checkbox-door-loc { font-size: 0.75rem; color: var(--text-muted); }

        /* Toast Container */
        .toast-container {
            position: fixed;
            top: 1.5rem;
            right: 1.5rem;
            z-index: 9999;
            display: flex;
            flex-direction: column;
            gap: 0.75rem;
            pointer-events: none;
        }

        .toast {
            pointer-events: auto;
            background: var(--sidebar-bg);
            border: 1px solid var(--border-color);
            border-radius: 0.75rem;
            padding: 0.85rem 1.1rem;
            display: flex;
            align-items: center;
            gap: 0.75rem;
            min-width: 320px;
            max-width: 440px;
            box-shadow: 0 10px 25px rgba(0, 0, 0, 0.4);
            transform: translateX(120%);
            opacity: 0;
            transition: all 0.3s cubic-bezier(0.4, 0, 0.2, 1);
        }

        .toast-show {
            transform: translateX(0);
            opacity: 1;
        }

        .toast-icon {
            width: 28px;
            height: 28px;
            border-radius: 50%;
            display: flex;
            align-items: center;
            justify-content: center;
            font-weight: 700;
            font-size: 0.85rem;
            flex-shrink: 0;
        }

        .toast-success .toast-icon { background: rgba(16, 185, 129, 0.2); color: #10b981; }
        .toast-error .toast-icon { background: rgba(239, 68, 68, 0.2); color: #ef4444; }
        .toast-info .toast-icon { background: rgba(56, 189, 248, 0.2); color: #38bdf8; }

        .toast-content { flex: 1; }
        .toast-title { font-size: 0.75rem; font-weight: 700; text-transform: uppercase; letter-spacing: 0.05em; color: var(--text-muted); }
        .toast-message { font-size: 0.85rem; color: #ffffff; margin-top: 2px; }
        .toast-close { background: none; border: none; color: var(--text-muted); font-size: 1rem; cursor: pointer; }

        /* Tabs Content */
        .tab-content { display: none; }
        .tab-content.active { display: block; animation: fadeIn 0.25s ease-out; }

        /* Simulator & Code */
        .simulator-box {
            background: var(--card-bg);
            border: 1px solid var(--border-color);
            border-radius: 1rem;
            padding: 1.5rem;
            margin-bottom: 2rem;
        }

        pre {
            background: #090d16;
            padding: 1rem;
            border-radius: 0.6rem;
            color: #a5f3fc;
            font-family: monospace;
            font-size: 0.825rem;
            overflow-x: auto;
            border: 1px solid var(--border-color);
            margin: 0.5rem 0 1.25rem 0;
        }

        .spinner {
            display: inline-block;
            width: 18px;
            height: 18px;
            border: 2px solid rgba(255,255,255,0.2);
            border-radius: 50%;
            border-top-color: var(--primary);
            animation: spin 0.8s linear infinite;
        }

        .spinner-sm {
            display: inline-block;
            width: 14px;
            height: 14px;
            border: 2px solid rgba(255,255,255,0.3);
            border-radius: 50%;
            border-top-color: #ffffff;
            animation: spin 0.8s linear infinite;
            vertical-align: middle;
            margin-right: 4px;
        }

        @keyframes spin { to { transform: rotate(360deg); } }
        @keyframes fadeIn { from { opacity: 0; transform: translateY(4px); } to { opacity: 1; transform: translateY(0); } }

        .loading-td, .empty-td, .error-td {
            text-align: center;
            padding: 2.5rem !important;
            color: var(--text-muted);
        }
        .error-td { color: #fca5a5; }

        /* Responsive Breakpoints (< 768px Mobile & Tablet) */
        @media (max-width: 768px) {
            .floating-logo {
                top: 18px;
                left: 16px;
                transform: scale(0.85);
            }

            body.sidebar-open .floating-logo,
            html.sidebar-open .floating-logo {
                top: 20px;
                left: 18px;
                transform: scale(0.95);
            }

            .sidebar {
                z-index: 100;
            }

            .main-content {
                margin-left: 0 !important;
                max-width: 100vw !important;
                width: 100% !important;
                padding: 1.25rem 1rem !important;
            }

            body.sidebar-open .main-content,
            html.sidebar-open .main-content {
                margin-left: 0 !important;
                max-width: 100vw !important;
            }

            .btn-sidebar-close {
                display: flex;
            }

            .top-bar {
                flex-direction: column;
                align-items: flex-start;
                gap: 1.25rem;
            }

            .top-bar-left {
                width: 100%;
            }

            .top-bar-actions {
                width: 100%;
                justify-content: flex-start;
            }

            .table-container {
                overflow-x: auto;
            }
        }
    </style>
</head>
<body>

<!-- Floating Logo Architecture (Extracted Fixed Standalone Trigger) -->
<div id="floatingLogo" class="floating-logo cursor-pointer" role="button" tabindex="0" title="Klik Logo untuk Buka/Tutup Sidebar (Ctrl+B)" aria-label="Toggle Sidebar Navigation">
    <div class="brand-icon floating-logo-icon">
        <img src="{{ asset('images/pkp-logo.png') }}" alt="PKP SecureGate" class="brand-logo-img floating-logo-img" onerror="this.src='{{ asset('favicon.svg') }}'">
    </div>
    <div class="floating-logo-text">
        <span class="floating-logo-title">PKP SecureGate</span>
        <span class="floating-logo-subtitle">Central Access Control</span>
    </div>
</div>

<!-- Toast Notification Container -->
<div class="toast-container" id="toastContainer"></div>

<!-- Off-Canvas Dark Backdrop Overlay -->
<div class="sidebar-backdrop" id="sidebarBackdrop"></div>

<!-- Sidebar Navigation -->
<aside class="sidebar" id="sidebar">
    <!-- Sidebar Header Placeholder for Floating Logo -->
    <div class="sidebar-logo-placeholder">
        <button type="button" class="btn-sidebar-close" id="sidebarCloseBtn" title="Tutup Sidebar (Esc)" aria-label="Close Sidebar">
            <svg viewBox="0 0 24 24" width="18" height="18" fill="none" stroke="currentColor" stroke-width="2.5" stroke-linecap="round" stroke-linejoin="round">
                <line x1="18" y1="6" x2="6" y2="18"></line>
                <line x1="6" y1="6" x2="18" y2="18"></line>
            </svg>
        </button>
    </div>

    <ul class="nav-list">
        <li class="nav-item">
            <button class="active" data-tooltip="Dashboard Terpusat" onclick="switchTab('overviewTab', this)">
                <span class="nav-icon">📊</span>
                <span class="nav-text">Dashboard Terpusat</span>
            </button>
        </li>
        <li class="nav-item">
            <button data-tooltip="4 Terminal Pintu (Doors)" onclick="switchTab('doorsTab', this)">
                <span class="nav-icon">🌐</span>
                <span class="nav-text">4 Terminal Pintu (Doors)</span>
            </button>
        </li>
        <li class="nav-item">
            <button data-tooltip="Hak Akses Karyawan" onclick="switchTab('employeesTab', this)">
                <span class="nav-icon">👥</span>
                <span class="nav-text">Hak Akses Karyawan</span>
            </button>
        </li>
        <li class="nav-item">
            <button data-tooltip="Security Access Logs" onclick="switchTab('logsTab', this)">
                <span class="nav-icon">📋</span>
                <span class="nav-text">Security Access Logs</span>
            </button>
        </li>
        <li class="nav-item">
            <button data-tooltip="Hardware Event Simulator" onclick="switchTab('simulatorTab', this)">
                <span class="nav-icon">🧪</span>
                <span class="nav-text">Hardware Event Simulator</span>
            </button>
        </li>
        <li class="nav-item">
            <button data-tooltip="cURL / Postman Specs" onclick="switchTab('apiDocsTab', this)">
                <span class="nav-icon">📖</span>
                <span class="nav-text">cURL / Postman Specs</span>
            </button>
        </li>
    </ul>

    <div class="user-profile">
        <div class="user-avatar" data-tooltip="{{ Auth::user()->name ?? 'Administrator' }} ({{ ucfirst(Auth::user()->role ?? 'Super Admin') }})">
            {{ strtoupper(substr(Auth::user()->name ?? 'A', 0, 1)) }}
        </div>
        <div class="user-info">
            <div class="user-name">{{ Auth::user()->name ?? 'Administrator' }}</div>
            <div class="user-role">{{ ucfirst(Auth::user()->role ?? 'Super Admin') }} {{ Auth::user()->assigned_building ? '('.Auth::user()->assigned_building.')' : '' }}</div>
        </div>
        <form method="POST" action="/logout">
            @csrf
            <button type="submit" class="btn-logout" data-tooltip="Keluar dari sesi dashboard" title="Keluar dari sesi dashboard">
                <span class="logout-icon">🚪</span>
                <span class="logout-text">Logout</span>
            </button>
        </form>
    </div>
</aside>

<!-- Main Workspace -->
<main class="main-content" id="mainContent">

    <!-- Top Bar -->
    <div class="top-bar">
        <div class="top-bar-left">
            <div class="top-bar-logo-placeholder" aria-hidden="true"></div>
        </div>
        <div class="top-bar-actions">
            <button class="btn-secondary" onclick="loadDoors(); loadEmployees(); loadAccessLogs(); showToast('Data dashboard disinkronkan', 'info');">
                🔄 Refresh Live Data
            </button>
            <button class="btn-primary" onclick="openAddEmployeeModal()">
                + Tambah Karyawan
            </button>
        </div>
    </div>

    <!-- TOP METRIC CARDS -->
    <div class="metrics-grid">
        <div class="metric-card">
            <div class="metric-icon-box icon-blue">👥</div>
            <div>
                <div class="metric-label">Total Karyawan</div>
                <div class="metric-value" id="metricTotalUsers">-</div>
            </div>
        </div>
        <div class="metric-card">
            <div class="metric-icon-box icon-green">🚪</div>
            <div>
                <div class="metric-label">Terminal Online</div>
                <div class="metric-value" id="metricActiveDoors">-</div>
            </div>
        </div>
        <div class="metric-card">
            <div class="metric-icon-box icon-indigo">✓</div>
            <div>
                <div class="metric-label">Access Granted (Tap)</div>
                <div class="metric-value" id="metricAccessGranted">-</div>
            </div>
        </div>
        <div class="metric-card">
            <div class="metric-icon-box icon-red">✕</div>
            <div>
                <div class="metric-label">Access Denied</div>
                <div class="metric-value" id="metricAccessDenied">-</div>
            </div>
        </div>
    </div>

    <!-- TAB 1: OVERVIEW (UNIFIED DASHBOARD) -->
    <section id="overviewTab" class="tab-content active">
        
        <!-- SECTION 1: 4 CENTRALIZED ACCESS DOORS -->
        <div class="section-header">
            <div>
                <h2 class="section-title">🌐 4 Centralized Access Doors</h2>
                <p class="section-desc">Status real-time 4 terminal fisik Hikvision DS-K1T804AMF (Gedung A, B, C, D)</p>
            </div>
            <button class="btn-secondary" onclick="checkAllDoors(this)" title="Audit semua koneksi terminal melalui ISAPI">
                📡 Cek Semua Koneksi Terminal
            </button>
        </div>
        <div class="doors-grid" id="overviewDoorsGrid">
            <div class="loading-td"><div class="spinner"></div> Memuat status perangkat...</div>
        </div>

        <!-- SECTION 2: USER & ACCESS PRIVILEGE MANAGEMENT -->
        <div class="section-header">
            <div>
                <h2 class="section-title">👥 User & Access Privilege Management</h2>
                <p class="section-desc">Hak akses pintu, status biometrik, dan sinkronisasi hardware per karyawan</p>
            </div>
        </div>
        <div class="table-container">
            <div class="table-toolbar">
                <div class="toolbar-left">
                    <div class="search-box">
                        🔍 <input type="text" id="employeeSearch" placeholder="Cari Nama / NIK..." oninput="handleEmployeeSearch()">
                    </div>
                    <div class="search-box">
                        🚪
                        <select id="employeeDoorFilter" onchange="loadEmployees()">
                            <option value="">Semua Hak Akses Pintu</option>
                            <option value="DOOR-A">Akses DOOR-A</option>
                            <option value="DOOR-B">Akses DOOR-B</option>
                            <option value="DOOR-C">Akses DOOR-C</option>
                            <option value="DOOR-D">Akses DOOR-D</option>
                        </select>
                    </div>
                </div>
                <div class="toolbar-right">
                    <span style="font-size: 0.85rem; color: var(--text-muted);" id="employeeCountText">Total: - Karyawan</span>
                </div>
            </div>
            <table>
                <thead>
                    <tr>
                        <th>User ID / NIK</th>
                        <th>Nama Karyawan</th>
                        <th>Departemen</th>
                        <th>Jabatan</th>
                        <th>Status Biometrik</th>
                        <th>Akses Pintu (Sync Status)</th>
                        <th style="text-align: right;">Aksi</th>
                    </tr>
                </thead>
                <tbody id="employeesTableBody">
                    <tr><td colspan="7" class="loading-td"><div class="spinner"></div> Memuat data...</td></tr>
                </tbody>
            </table>
        </div>

        <!-- SECTION 3: SECURITY ACCESS LOGS & AUDIT TRAIL -->
        <div class="section-header">
            <div>
                <h2 class="section-title">📋 Security Access Logs & Audit Trail</h2>
                <p class="section-desc">Riwayat event tap kartu / sidik jari real-time dari seluruh terminal pintu</p>
            </div>
        </div>
        <div class="table-container">
            <div class="table-toolbar">
                <div class="toolbar-left">
                    <div class="search-box">
                        🚪
                        <select id="logDoorFilter" onchange="loadAccessLogs()">
                            <option value="">Semua Pintu</option>
                            <option value="DOOR-A">DOOR-A (Gedung A)</option>
                            <option value="DOOR-B">DOOR-B (Gedung B)</option>
                            <option value="DOOR-C">DOOR-C (Gedung C)</option>
                            <option value="DOOR-D">DOOR-D (Gedung D)</option>
                        </select>
                    </div>
                    <div class="search-box">
                        ⚡
                        <select id="logStatusFilter" onchange="loadAccessLogs()">
                            <option value="">Semua Status & Alarm</option>
                            <option value="Granted">Granted (Akses Diterima)</option>
                            <option value="Denied">Denied (Akses Ditolak)</option>
                            <option value="Alarm">🚨 Alarm / Intrusion / Sabotase</option>
                            <option value="Duress">⚠️ Duress Emergency</option>
                        </select>
                    </div>
                    <div class="search-box">
                        👤 <input type="text" id="logUserSearch" placeholder="Cari NIK / Nama..." onchange="loadAccessLogs()">
                    </div>
                    <div class="search-box">
                        📅 <input type="date" id="logStartDate" onchange="loadAccessLogs()" title="Mulai Tanggal">
                    </div>
                    <div class="search-box">
                        📅 <input type="date" id="logEndDate" onchange="loadAccessLogs()" title="Sampai Tanggal">
                    </div>
                </div>
                <div class="toolbar-right" style="display: flex; gap: 0.5rem; align-items: center;">
                    <button class="btn-primary" onclick="syncHardwareLogs(this)" title="Tarik riwayat tap akses terbaru dari terminal ISAPI">
                        🔄 Sinkronkan Log Pintu
                    </button>
                    <button class="btn-secondary" onclick="resetLogFilters()">Reset Filter</button>
                </div>
            </div>
            <table>
                <thead>
                    <tr>
                        <th>Log ID</th>
                        <th>Terminal Pintu</th>
                        <th>IP Perangkat</th>
                        <th>Pengguna / Kartu</th>
                        <th>Metode</th>
                        <th>Status Akses</th>
                        <th>Waktu Tap (WIB)</th>
                    </tr>
                </thead>
                <tbody id="overviewLogsTableBody">
                    <tr><td colspan="7" class="loading-td"><div class="spinner"></div> Memuat access logs...</td></tr>
                </tbody>
            </table>
        </div>
    </section>

    <!-- TAB 2: DOORS ONLY -->
    <section id="doorsTab" class="tab-content">
        <div class="section-header">
            <div>
                <h2 class="section-title">🌐 Centralized Door Terminal Monitoring</h2>
                <p class="section-desc">Audit hardware konektivitas IP & kontrol manual status online/offline</p>
            </div>
            <button class="btn-secondary" onclick="checkAllDoors(this)" title="Audit semua koneksi terminal melalui ISAPI">
                📡 Cek Semua Koneksi Terminal
            </button>
        </div>
        <div class="doors-grid" id="doorsGrid">
            <!-- Populated via JS -->
        </div>
    </section>

    <!-- TAB 3: EMPLOYEES ONLY -->
    <section id="employeesTab" class="tab-content">
        <!-- Reuses table in Overview or full view -->
        <div class="section-header">
            <div>
                <h2 class="section-title">👥 Manajemen Karyawan & Hak Akses Pintu</h2>
                <p class="section-desc">Daftar lengkap karyawan terdaftar dan distribusi izin pintu</p>
            </div>
            <button class="btn-primary" onclick="openAddEmployeeModal()">+ Tambah Karyawan</button>
        </div>
        <div class="table-container">
            <table>
                <thead>
                    <tr>
                        <th>User ID / NIK</th>
                        <th>Nama Karyawan</th>
                        <th>Departemen</th>
                        <th>Jabatan</th>
                        <th>Status Biometrik</th>
                        <th>Akses Pintu (Sync Status)</th>
                        <th style="text-align: right;">Aksi</th>
                    </tr>
                </thead>
                <tbody id="fullEmployeesTableBody">
                    <!-- Synced with employees list -->
                </tbody>
            </table>
        </div>
    </section>

    <!-- TAB 4: ACCESS LOGS ONLY -->
    <section id="logsTab" class="tab-content">
        <div class="section-header">
            <div>
                <h2 class="section-title">📋 Riwayat Lengkap Access Logs</h2>
                <p class="section-desc">Audit trail keamanan akses pintu fisik seluruh gedung</p>
            </div>
            <button class="btn-primary" onclick="syncHardwareLogs(this)" title="Tarik riwayat tap akses terbaru dari terminal ISAPI">
                🔄 Sinkronkan Log Pintu
            </button>
        </div>
        <div class="table-container">
            <table>
                <thead>
                    <tr>
                        <th>Log ID</th>
                        <th>Terminal Pintu</th>
                        <th>IP Perangkat</th>
                        <th>Pengguna / Kartu</th>
                        <th>Metode</th>
                        <th>Status Akses</th>
                        <th>Waktu Tap (WIB)</th>
                    </tr>
                </thead>
                <tbody id="logsTableBody">
                    <!-- Populated via JS -->
                </tbody>
            </table>
        </div>
    </section>

    <!-- TAB 5: ISAPI HARDWARE EVENT SIMULATOR -->
    <section id="simulatorTab" class="tab-content">
        <div class="simulator-box">
            <h3 style="margin-bottom: 0.5rem; font-size: 1.25rem;">🧪 Hikvision ISAPI Hardware Webhook Simulator</h3>
            <p style="color: var(--text-muted); font-size: 0.85rem; margin-bottom: 1.5rem;">
                Simulasikan sinyal event perangkat keras terminal kontrol akses pintu Hikvision DS-K1T804AMF (termasuk skenario normal tap dan skenario darurat/alarm).
                Payload dikirimkan secara aman ke webhook <code>POST /api/v1/isapi/event-notification</code> dengan <code>X-Device-Secret</code>.
            </p>

            <form id="simulatorForm" onsubmit="runEventSimulation(event)">
                <div style="display: grid; grid-template-columns: repeat(auto-fit, minmax(220px, 1fr)); gap: 1.2rem; margin-bottom: 1.5rem;">
                    <div class="form-row">
                        <label>Terminal Pintu (Origin Device)</label>
                        <select id="simDoorId">
                            <option value="DOOR-A">DOOR-A (192.168.90.11 - Gedung A)</option>
                            <option value="DOOR-B">DOOR-B (192.168.90.12 - Gedung B)</option>
                            <option value="DOOR-C">DOOR-C (192.168.90.13 - Gedung C)</option>
                            <option value="DOOR-D">DOOR-D (192.168.90.14 - Gedung D)</option>
                        </select>
                    </div>

                    <div class="form-row">
                        <label>Tipe Skenario Event (Event Type)</label>
                        <select id="simEventType" onchange="handleSimEventTypeChange(this.value)">
                            <option value="STANDARD_TAP">🟢 Standard Tap Access (Normal)</option>
                            <option value="DOOR_FORCED_OPEN">🚨 DOOR_FORCED_OPEN (Pembobolan Pintu / Alarm)</option>
                            <option value="TAMPER_ALARM">🔧 TAMPER_ALARM (Sensor Sabotase Terminal)</option>
                            <option value="DURESS_FINGERPRINT">⚠️ DURESS_FINGERPRINT (Sidik Jari Darurat / Ancaman)</option>
                        </select>
                    </div>

                    <div class="form-row" id="simUserGroup">
                        <label id="simUserLabel">NIK / User ID / Nomor Kartu</label>
                        <input type="text" id="simUserNik" value="NIK-882101" placeholder="Misal: NIK-882101 atau CARD-1001">
                    </div>

                    <div class="form-row" id="simMethodGroup">
                        <label>Metode Verifikasi</label>
                        <select id="simMethod">
                            <option value="Fingerprint">Fingerprint (Sidik Jari)</option>
                            <option value="Card">Card (Kartu RFID)</option>
                            <option value="Sensor">Sensor (Physical Trigger)</option>
                            <option value="Duress_Fingerprint">Duress_Fingerprint (Sidik Jari Darurat)</option>
                        </select>
                    </div>

                    <div class="form-row" id="simStatusGroup">
                        <label>Status Akses Terminal</label>
                        <select id="simStatus">
                            <option value="Granted">Granted (Akses Diterima)</option>
                            <option value="Denied">Denied (Akses Ditolak)</option>
                            <option value="Alarm">Alarm (🚨 Alarm / Intrusion)</option>
                            <option value="Duress">Duress (⚠️ Silent Duress Alert)</option>
                        </select>
                    </div>
                </div>

                <div id="simScenarioInfo" style="margin-bottom: 1.25rem; padding: 0.85rem 1rem; background: rgba(56, 189, 248, 0.08); border-left: 4px solid var(--accent); border-radius: 4px; font-size: 0.85rem; color: var(--text-main);">
                    <strong>💡 Skenario Terpilih:</strong> <span>Simulasi tap kartu / sidik jari reguler pegawai. Akses diberikan jika NIK terdaftar dan memiliki izin ke pintu tersebut.</span>
                </div>

                <button type="submit" class="btn-primary" id="btnSendSimulation">
                    ⚡ Kirim Sinyal Event Hardware
                </button>
            </form>

            <div id="simResult" style="margin-top: 1.25rem; display: none;"></div>
        </div>
    </section>

    <!-- TAB 6: API SPECS -->
    <section id="apiDocsTab" class="tab-content">
        <div class="simulator-box">
            <h3 style="margin-bottom: 0.5rem;">📖 Dokumentasi cURL & Backend Endpoints V1</h3>
            <p style="color: var(--text-muted); font-size: 0.85rem; margin-bottom: 1.5rem;">
                Gunakan cURL berikut untuk memverifikasi API langsung dengan Sanctum Bearer Token:
            </p>

            <h4 style="color: var(--primary);">1. User Management Listing (GET /api/v1/user-management/users)</h4>
            <pre>curl -X GET "http://localhost:8000/api/v1/user-management/users?per_page=10" \
  -H "Authorization: Bearer <span class="active-token-text">{{ $apiToken ?? session('api_token') ?? 'SANCTUM_TOKEN' }}</span>" \
  -H "Accept: application/json"</pre>

            <h4 style="margin-top: 1.5rem; color: var(--primary);">2. Admin Doors Status (GET /api/v1/admin/doors)</h4>
            <pre>curl -X GET "http://localhost:8000/api/v1/admin/doors" \
  -H "Authorization: Bearer <span class="active-token-text">{{ $apiToken ?? session('api_token') ?? 'SANCTUM_TOKEN' }}</span>" \
  -H "Accept: application/json"</pre>

            <h4 style="margin-top: 1.5rem; color: var(--primary);">3. Security Access Logs (GET /api/v1/admin/access-logs)</h4>
            <pre>curl -X GET "http://localhost:8000/api/v1/admin/access-logs?door_id=DOOR-A&limit=10" \
  -H "Authorization: Bearer <span class="active-token-text">{{ $apiToken ?? session('api_token') ?? 'SANCTUM_TOKEN' }}</span>" \
  -H "Accept: application/json"</pre>

            <h4 style="margin-top: 1.5rem; color: var(--primary);">4. ISAPI Webhook Tap Push (POST /api/v1/isapi/event-notification)</h4>
            <pre>curl -X POST "http://localhost:8000/api/v1/isapi/event-notification" \
  -H "X-Device-Secret: secret_door_a_9981" \
  -H "Content-Type: application/json" \
  -d '{
    "door_id": "DOOR-A",
    "user": "NIK-882101",
    "verify_method": "Fingerprint",
    "access_status": "Granted"
  }'</pre>
        </div>
    </section>

</main>

<!-- MODAL 1: DOOR ASSIGNMENT MODAL -->
<div class="modal-overlay" id="doorAssignModal">
    <div class="modal-card">
        <div class="modal-header">
            <div>
                <h3 class="modal-title">🚪 Atur Akses Pintu Fisik Karyawan</h3>
                <div style="font-size: 0.85rem; color: var(--primary); font-weight: 600; margin-top: 2px;" id="assignModalEmpName">Nama Karyawan</div>
                <div style="font-size: 0.775rem; color: var(--text-muted);" id="assignModalEmpDept">Departemen</div>
            </div>
            <button class="modal-close-btn" onclick="closeModal('doorAssignModal')">✖</button>
        </div>

        <form id="doorAssignForm" onsubmit="submitDoorAssignment(event)">
            <p style="font-size: 0.85rem; color: var(--text-muted); margin-bottom: 0.75rem;">
                Pilih terminal pintu yang diizinkan untuk diakses oleh karyawan ini. Perubahan akan langsung disinkronkan ke hardware melalui ISAPI job queue.
            </p>

            <div class="door-checkboxes-grid" id="doorCheckboxesContainer">
                <div class="spinner"></div> Memuat daftar pintu...
            </div>

            <div style="display: flex; justify-content: space-between; align-items: center; gap: 0.75rem; margin-top: 1.5rem;">
                <button type="button" class="btn-secondary" style="color: var(--danger); border-color: rgba(239, 68, 68, 0.4);" onclick="revokeAllEmployeeDoors()">
                    🚫 Cabut Semua Akses
                </button>
                <div style="display: flex; gap: 0.75rem;">
                    <button type="button" class="btn-secondary" onclick="closeModal('doorAssignModal')">Batal</button>
                    <button type="submit" class="btn-primary" id="btnSaveDoorAssignment">
                        Simpan Hak Akses Pintu
                    </button>
                </div>
            </div>
        </form>
    </div>
</div>

<!-- MODAL 2: ADD / EDIT EMPLOYEE MODAL -->
<div class="modal-overlay" id="employeeModal">
    <div class="modal-card">
        <div class="modal-header">
            <h3 class="modal-title" id="employeeModalTitle">Tambah Karyawan Baru</h3>
            <button class="modal-close-btn" onclick="closeModal('employeeModal')">✖</button>
        </div>
        <form id="employeeForm" onsubmit="saveEmployee(event)">
            <input type="hidden" id="empDbId">
            <div class="form-row">
                <label>User ID (Kode Identitas)</label>
                <input type="text" id="empUserId" placeholder="USR-1001" required>
            </div>
            <div class="form-row">
                <label>NIK Karyawan</label>
                <input type="text" id="empNik" placeholder="NIK-882101" required>
            </div>
            <div class="form-row">
                <label>Nama Lengkap</label>
                <input type="text" id="empName" placeholder="Nama Karyawan" required>
            </div>
            <div class="form-row">
                <label>Nomor Kartu RFID</label>
                <input type="text" id="empCardNo" placeholder="CARD-1001">
            </div>
            <div class="form-row">
                <label>Departemen</label>
                <select id="empDept">
                    <option value="IT Support">IT Support</option>
                    <option value="Produksi">Produksi</option>
                    <option value="Operasional">Operasional</option>
                    <option value="HR & Admin">HR & Admin</option>
                    <option value="Security">Security</option>
                </select>
            </div>
            <div class="form-row">
                <label>Jabatan</label>
                <input type="text" id="empRole" placeholder="Staff / Operator / Supervisor" required>
            </div>
            <div class="form-row">
                <label>Enrollment Biometrik</label>
                <div style="display: flex; gap: 1.5rem; margin-top: 0.35rem;">
                    <label style="cursor: pointer; display: flex; align-items: center; gap: 0.4rem; color: #ffffff;">
                        <input type="checkbox" id="empFp" checked> Fingerprint Enrolled
                    </label>
                    <label style="cursor: pointer; display: flex; align-items: center; gap: 0.4rem; color: #ffffff;">
                        <input type="checkbox" id="empCard" checked> Card Enrolled
                    </label>
                </div>
            </div>
            <div style="display: flex; justify-content: flex-end; gap: 0.75rem; margin-top: 1.5rem;">
                <button type="button" class="btn-secondary" onclick="closeModal('employeeModal')">Batal</button>
                <button type="submit" class="btn-primary">Simpan Data Karyawan</button>
            </div>
        </form>
    </div>
</div>

<!-- Configuration & Global Variables -->
<script>
    window.APP_CONFIG = {
        apiToken: @json($apiToken ?? session('api_token')),
        admin: {
            id: @json(Auth::id() ?? 1),
            name: @json(Auth::user()->name ?? 'Administrator'),
            role: @json(Auth::user()->role ?? 'super_admin')
        }
    };
    if (window.APP_CONFIG.apiToken) {
        sessionStorage.setItem('api_token', window.APP_CONFIG.apiToken);
        localStorage.setItem('api_token', window.APP_CONFIG.apiToken);
    }
</script>
<script src="/js/dashboard.js"></script>

</body>
</html>
