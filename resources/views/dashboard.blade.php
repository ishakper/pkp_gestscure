<!DOCTYPE html>
<html lang="id">
<head>
    <meta charset="UTF-8">
    <meta name="csrf-token" content="{{ csrf_token() }}">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>PKP Secure - Centralized Multi-Building Access Control</title>
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

        .btn-action {
            padding: 0.45rem 0.75rem;
            border-radius: 6px;
            font-size: 0.8rem;
            border: none;
            cursor: pointer;
            font-weight: 600;
            display: inline-flex;
            align-items: center;
            gap: 0.35rem;
            transition: all 0.15s ease;
        }

        .btn-unlock {
            background: #10b981;
            color: #ffffff !important;
            border: 1px solid #059669;
            border-radius: 6px;
            font-weight: 600;
            cursor: pointer;
            display: inline-flex;
            align-items: center;
            gap: 0.35rem;
            transition: all 0.15s ease;
        }
        .btn-unlock:hover { 
            background: #059669; 
            box-shadow: 0 0 12px rgba(16, 185, 129, 0.45);
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

        /* ATS Specific Styling */
        .ats-nav-pills {
            display: flex;
            gap: 0.5rem;
            margin-bottom: 1.5rem;
            border-bottom: 1px solid var(--border-color);
            padding-bottom: 0.85rem;
            flex-wrap: wrap;
        }
        .ats-nav-pill {
            background: transparent;
            border: 1px solid var(--border-color);
            color: var(--text-muted);
            padding: 0.5rem 1.1rem;
            border-radius: 0.5rem;
            font-size: 0.85rem;
            font-weight: 600;
            cursor: pointer;
            transition: all 0.2s ease;
        }
        .ats-nav-pill:hover {
            background: rgba(255, 255, 255, 0.05);
            color: #ffffff;
        }
        .ats-nav-pill.active {
            background: rgba(56, 189, 248, 0.15);
            border-color: var(--primary);
            color: var(--primary);
            box-shadow: 0 0 10px rgba(56, 189, 248, 0.2);
        }
        .ats-sub-content { display: none; }
        .ats-sub-content.active { display: block; animation: fadeIn 0.25s ease-out; }
        .stage-badge {
            display: inline-block;
            padding: 0.25rem 0.6rem;
            border-radius: 0.4rem;
            font-size: 0.725rem;
            font-weight: 700;
            letter-spacing: 0.03em;
            text-transform: uppercase;
        }
        .stage-APPLIED { background: rgba(148, 163, 184, 0.15); color: #94a3b8; }
        .stage-SCREENING { background: rgba(56, 189, 248, 0.15); color: #38bdf8; }
        .stage-HR_INTERVIEW { background: rgba(99, 102, 241, 0.15); color: #a5b4fc; }
        .stage-TECHNICAL_TEST { background: rgba(245, 158, 11, 0.15); color: #fcd34d; }
        .stage-USER_INTERVIEW { background: rgba(168, 85, 247, 0.15); color: #c084fc; }
        .stage-MANAGEMENT_REVIEW { background: rgba(236, 72, 153, 0.15); color: #f472b6; }
        .stage-OFFER { background: rgba(20, 184, 166, 0.15); color: #2dd4bf; }
        .stage-ACCEPTED { background: rgba(16, 185, 129, 0.2); color: #34d399; }
        .stage-REJECTED { background: rgba(239, 68, 68, 0.15); color: #f87171; }
        .stage-TALENT_POOL { background: rgba(139, 92, 246, 0.15); color: #a78bfa; }

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
        .badge-online { background: rgba(16, 185, 129, 0.2); color: #6ee7b7; border: 1px solid rgba(16, 185, 129, 0.4); font-weight: 700; }
        .badge-offline { background: rgba(100, 116, 139, 0.2); color: #94a3b8; border: 1px solid rgba(100, 116, 139, 0.4); font-weight: 700; }
        .badge-error { background: rgba(239, 68, 68, 0.2); color: #fca5a5; border: 1px solid rgba(239, 68, 68, 0.4); font-weight: 700; }

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
        <img src="{{ asset('images/pkp-logo.png') }}" alt="PKP Secure" class="brand-logo-img floating-logo-img" onerror="this.src='{{ asset('favicon.svg') }}'">
    </div>
    <div class="floating-logo-text">
        <span class="floating-logo-title">PKP Secure</span>
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
        @if(in_array('device.view', $permissions ?? []))
        <li class="nav-item"><button data-tooltip="4 Terminal Pintu (Doors)" onclick="switchTab('doorsTab', this)"><span class="nav-icon">🌐</span><span class="nav-text">{{ ($portal ?? '') === 'ADMIN_PORTAL' ? 'Devices & Doors' : 'Pintu Gedung' }}</span></button></li>
        @endif
        @if(in_array('employee.view', $permissions ?? []))
        <li class="nav-item"><button data-tooltip="Hak Akses Karyawan" onclick="switchTab('employeesTab', this)"><span class="nav-icon">👥</span><span class="nav-text">{{ ($portal ?? '') === 'MANAGEMENT_PORTAL' ? 'People & Organization' : 'Hak Akses Karyawan' }}</span></button></li>
        @endif
        @if(in_array('recruitment.view', $permissions ?? []))
        <li class="nav-item"><button data-tooltip="Recruitment & ATS" onclick="switchTab('recruitmentTab', this)"><span class="nav-icon">🎯</span><span class="nav-text">Recruitment / ATS</span></button></li>
        @endif
        @if(in_array('internship.view', $permissions ?? []))
        <li class="nav-item"><button data-tooltip="Internship & Mentorship" onclick="switchTab('internshipTab', this)"><span class="nav-icon">🎓</span><span class="nav-text">Internship / Magang</span></button></li>
        @endif
        @if(in_array('onboarding.view', $permissions ?? []))
        <li class="nav-item"><button data-tooltip="Onboarding & Kontrak" onclick="switchTab('onboardingTab', this)"><span class="nav-icon">📑</span><span class="nav-text">Onboarding & Dokumen</span></button></li>
        @endif
        @if(in_array('security.view', $permissions ?? []))
        <li class="nav-item"><button data-tooltip="Security Access Logs" onclick="switchTab('logsTab', this)"><span class="nav-icon">📋</span><span class="nav-text">{{ ($portal ?? '') === 'ADMIN_PORTAL' ? 'Security & Audit' : 'Access Logs' }}</span></button></li>
        @endif
        @if (app()->environment('local', 'testing'))
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
        @endif
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
            @if(isset($doors) && $doors->isNotEmpty())
                @foreach($doors as $door)
                    @php
                        $isOnline = ($door->connection_status === 'online' || $door->status === 'online');
                        $targetOverride = $isOnline ? 'offline' : 'online';
                        $overrideText = $isOnline ? 'Set Offline' : 'Restore Online';
                        $badgeClass = $isOnline ? 'badge-online' : 'badge-offline';
                        $badgeText = $isOnline ? 'ONLINE' : 'OFFLINE';
                    @endphp
                    <div class="door-card">
                        <div class="door-card-header">
                            <div>
                                <div class="door-code">{{ $door->door_id }}</div>
                                <div class="door-name">{{ $door->door_name ?? $door->name }}</div>
                            </div>
                            <span class="badge {{ $badgeClass }}">{{ $badgeText }}</span>
                        </div>
                        <div class="door-specs">
                            <div class="spec-item">
                                <span class="spec-label">Lokasi Gedung:</span>
                                <span class="spec-val">{{ $door->location }}</span>
                            </div>
                            <div class="spec-item">
                                <span class="spec-label">IP Terminal:</span>
                                <span class="spec-val ip-tag">{{ $door->device_ip ?? $door->ip_address }}</span>
                            </div>
                            <div class="spec-item">
                                <span class="spec-label">Model Hardware:</span>
                                <span class="spec-val">{{ $door->device_model ?? $door->model ?? 'DS-K1T804AMF' }}</span>
                            </div>
                            <div class="spec-item">
                                <span class="spec-label">Assigned Users:</span>
                                <span class="spec-val highlight">{{ $door->employees_count ?? $door->door_assignments_count ?? 0 }} Pegawai</span>
                            </div>
                            <div class="spec-item">
                                <span class="spec-label">Status Pintu:</span>
                                <span class="spec-val">{{ $isOnline ? '🟢 Closed (Normal)' : '🔴 Device Offline' }}</span>
                            </div>
                        </div>
                        <div class="door-actions">
                            <button type="button" class="btn-action btn-override" onclick="toggleDoorStatus('{{ $door->door_id }}', '{{ $targetOverride }}')" title="Manual Override Maintenance Mode">
                                ⚡ {{ $overrideText }}
                            </button>
                            <button type="button" class="btn-action btn-unlock" style="background:#059669;color:#fff;font-weight:600;" onclick="remoteUnlockDoor('{{ $door->door_id }}', this)">🔓 Buka Pintu</button>
                            <button type="button" class="btn-action btn-ping" onclick="pingSingleDoor('{{ $door->door_id }}', this)" title="Cek status ISAPI getDeviceStatus">
                                📡 Cek Koneksi
                            </button>
                        </div>
                    </div>
                @endforeach
            @else
                <div class="loading-td"><div class="spinner"></div> Memuat status perangkat...</div>
            @endif
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
                        <th>Sumber</th>
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
            @if(isset($doors) && $doors->isNotEmpty())
                @foreach($doors as $door)
                    @php
                        $isOnline = ($door->connection_status === 'online' || $door->status === 'online');
                        $isError = ($door->connection_status === 'error' || $door->status === 'error');
                        $targetOverride = $isOnline ? 'offline' : 'online';
                        $overrideText = $isOnline ? 'Set Offline' : 'Restore Online';
                        
                        $badgeClass = 'badge-offline';
                        $badgeText = 'OFFLINE';
                        $statusText = '🔴 Device Offline';

                        if ($isOnline) {
                            $badgeClass = 'badge-online';
                            $badgeText = 'ONLINE';
                            $statusText = '🟢 Closed (Normal)';
                        } elseif ($isError) {
                            $badgeClass = 'badge-error';
                            $badgeText = 'AUTH ERROR';
                            $statusText = '🟠 Network OK, ISAPI Auth Failed (401)';
                        }
                    @endphp
                    <div class="door-card">
                        <div class="door-card-header">
                            <div>
                                <div class="door-code">{{ $door->door_id }}</div>
                                <div class="door-name">{{ $door->door_name ?? $door->name }}</div>
                            </div>
                            <span class="badge {{ $badgeClass }}">{{ $badgeText }}</span>
                        </div>
                        <div class="door-specs">
                            <div class="spec-item">
                                <span class="spec-label">Lokasi Gedung:</span>
                                <span class="spec-val">{{ $door->location }}</span>
                            </div>
                            <div class="spec-item">
                                <span class="spec-label">IP Terminal:</span>
                                <span class="spec-val ip-tag">{{ $door->device_ip ?? $door->ip_address }}</span>
                            </div>
                            <div class="spec-item">
                                <span class="spec-label">Model Hardware:</span>
                                <span class="spec-val">{{ $door->device_model ?? $door->model ?? 'DS-K1T804AMF' }}</span>
                            </div>
                            <div class="spec-item">
                                <span class="spec-label">Assigned Users:</span>
                                <span class="spec-val highlight">{{ $door->employees_count ?? $door->door_assignments_count ?? 0 }} Pegawai</span>
                            </div>
                            <div class="spec-item">
                                <span class="spec-label">Status Pintu:</span>
                                <span class="spec-val">{{ $statusText }}</span>
                            </div>
                        </div>
                        <div class="door-actions">
                            <button type="button" class="btn-action btn-override" onclick="toggleDoorStatus('{{ $door->door_id }}', '{{ $targetOverride }}')" title="Manual Override Maintenance Mode">
                                ⚡ {{ $overrideText }}
                            </button>
                            <button type="button" class="btn-action btn-unlock" style="background:#059669;color:#fff;font-weight:600;" onclick="remoteUnlockDoor('{{ $door->door_id }}', this)">🔓 Buka Pintu</button>
                            <button type="button" class="btn-action btn-ping" onclick="pingSingleDoor('{{ $door->door_id }}', this)" title="Cek status ISAPI getDeviceStatus">
                                📡 Cek Koneksi
                            </button>
                        </div>
                    </div>
                @endforeach
            @else
                <div class="loading-td"><div class="spinner"></div> Memuat status perangkat...</div>
            @endif
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
                        <th>Sumber</th>
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
    @if (app()->environment('local', 'testing'))
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
                            <option value="DOOR-B">DOOR-B (192.168.90.15 - Gedung B)</option>
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
    @endif

    <!-- TAB 6: API SPECS -->
    @if (app()->environment('local', 'testing'))
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
  -H "X-Device-Secret: YOUR_DEVICE_SECRET" \
  -H "Content-Type: application/json" \
  -d '{
    "door_id": "DOOR-A",
    "user": "NIK-882101",
    "verify_method": "Fingerprint",
    "access_status": "Granted"
  }'</pre>
        </div>
    </section>
    @endif

    <!-- TAB: RECRUITMENT & ATS -->
    <section id="recruitmentTab" class="tab-content">
        <div class="section-header">
            <div>
                <h2 class="section-title">🎯 Recruitment & Applicant Tracking System (ATS)</h2>
                <div class="section-desc">Pusat tata kelola rekrutmen PKP SecureGate: kelola lowongan, pipeline pelamar, interview, offering, dan konversi ke master karyawan.</div>
            </div>
            <div style="display: flex; gap: 0.75rem; flex-wrap: wrap;">
                <button class="btn-secondary" onclick="loadRecruitmentData(); showToast('Memperbarui data rekrutmen...', 'info');">
                    🔄 Refresh Data
                </button>
                <button class="btn-primary" onclick="openAddVacancyModal()">
                    + Buat Lowongan
                </button>
                <button class="btn-action" style="background: rgba(99, 102, 241, 0.2); color: #a5b4fc; border: 1px solid rgba(99, 102, 241, 0.4);" onclick="openAddCandidateModal()">
                    + Daftar Kandidat
                </button>
            </div>
        </div>

        <!-- ATS Metrics Grid -->
        <div class="metrics-grid" style="margin-bottom: 2rem;">
            <div class="metric-card">
                <div class="metric-icon-box icon-blue">📋</div>
                <div>
                    <div class="metric-label">Lowongan Terbuka</div>
                    <div class="metric-value" id="atsMetricVacancies">-</div>
                </div>
            </div>
            <div class="metric-card">
                <div class="metric-icon-box icon-indigo">👤</div>
                <div>
                    <div class="metric-label">Total Kandidat</div>
                    <div class="metric-value" id="atsMetricCandidates">-</div>
                </div>
            </div>
            <div class="metric-card">
                <div class="metric-icon-box" style="background: rgba(245, 158, 11, 0.15); color: #f59e0b;">⏳</div>
                <div>
                    <div class="metric-label">Pelamar Aktif</div>
                    <div class="metric-value" id="atsMetricApplications">-</div>
                </div>
            </div>
            <div class="metric-card">
                <div class="metric-icon-box icon-green">✓</div>
                <div>
                    <div class="metric-label">Karyawan Lolos / Hired</div>
                    <div class="metric-value" id="atsMetricHired">-</div>
                </div>
            </div>
        </div>

        <!-- ATS Sub-Navigation Pills -->
        <div class="ats-nav-pills">
            <button class="ats-nav-pill active" id="pillPipeline" onclick="switchAtsPill('pipeline', this)">
                📊 Pipeline Lamaran
            </button>
            <button class="ats-nav-pill" id="pillVacancies" onclick="switchAtsPill('vacancies', this)">
                💼 Lowongan Pekerjaan
            </button>
            <button class="ats-nav-pill" id="pillCandidates" onclick="switchAtsPill('candidates', this)">
                👥 Talent Pool Kandidat
            </button>
            <button class="ats-nav-pill" id="pillInterviews" onclick="switchAtsPill('interviews', this)">
                📅 Jadwal Interview
            </button>
        </div>

        <!-- SUB-TAB 1: PIPELINE LAMARAN -->
        <div id="atsSubPipeline" class="ats-sub-content active">
            <div class="table-container">
                <div class="table-toolbar">
                    <div class="toolbar-left">
                        <div class="search-box">
                            <span>🔍</span>
                            <input type="text" id="searchAtsApplications" placeholder="Cari kandidat / posisi..." onkeyup="debounceAtsApplicationsSearch()">
                        </div>
                        <div class="search-box">
                            <span>📂</span>
                            <select id="filterAtsStage" onchange="loadAtsApplications()">
                                <option value="">Semua Tahapan</option>
                                <option value="APPLIED">Berkas Masuk</option>
                                <option value="SCREENING">Screening CV</option>
                                <option value="HR_INTERVIEW">Interview HR</option>
                                <option value="TECHNICAL_TEST">Tes Teknis</option>
                                <option value="USER_INTERVIEW">Interview User</option>
                                <option value="MANAGEMENT_REVIEW">Review Manajemen</option>
                                <option value="OFFER">Offering</option>
                                <option value="ACCEPTED">Hired / Diterima</option>
                                <option value="REJECTED">Ditolak</option>
                            </select>
                        </div>
                    </div>
                    <div class="toolbar-right">
                        <button class="btn-secondary" onclick="openApplyModal()">+ Lamar ke Lowongan</button>
                    </div>
                </div>
                <table>
                    <thead>
                        <tr>
                            <th>No. Lamaran</th>
                            <th>Kandidat</th>
                            <th>Posisi Lowongan</th>
                            <th>Tahapan (Stage)</th>
                            <th>Status</th>
                            <th>Tanggal Lamaran</th>
                            <th style="text-align: right;">Aksi</th>
                        </tr>
                    </thead>
                    <tbody id="atsApplicationsTableBody">
                        <tr><td colspan="7" class="loading-td"><div class="spinner"></div> Memuat pipeline pelamar...</td></tr>
                    </tbody>
                </table>
            </div>
        </div>

        <!-- SUB-TAB 2: LOWONGAN PEKERJAAN -->
        <div id="atsSubVacancies" class="ats-sub-content">
            <div class="table-container">
                <div class="table-toolbar">
                    <div class="toolbar-left">
                        <div class="search-box">
                            <span>🔍</span>
                            <input type="text" id="searchAtsVacancies" placeholder="Cari lowongan..." onkeyup="debounceAtsVacanciesSearch()">
                        </div>
                        <div class="search-box">
                            <span>🏷️</span>
                            <select id="filterAtsVacancyStatus" onchange="loadAtsVacancies()">
                                <option value="">Semua Status</option>
                                <option value="OPEN">Dibuka (OPEN)</option>
                                <option value="DRAFT">Draft</option>
                                <option value="CLOSED">Ditutup (CLOSED)</option>
                            </select>
                        </div>
                    </div>
                    <div class="toolbar-right">
                        <button class="btn-primary" onclick="openAddVacancyModal()">+ Lowongan Baru</button>
                    </div>
                </div>
                <table>
                    <thead>
                        <tr>
                            <th>Kode & Posisi</th>
                            <th>Divisi & Lokasi</th>
                            <th>Tipe / Level</th>
                            <th>Kuota</th>
                            <th>Pelamar</th>
                            <th>Status</th>
                            <th>Batas Waktu</th>
                            <th style="text-align: right;">Aksi</th>
                        </tr>
                    </thead>
                    <tbody id="atsVacanciesTableBody">
                        <tr><td colspan="8" class="loading-td"><div class="spinner"></div> Memuat daftar lowongan...</td></tr>
                    </tbody>
                </table>
            </div>
        </div>

        <!-- SUB-TAB 3: TALENT POOL KANDIDAT -->
        <div id="atsSubCandidates" class="ats-sub-content">
            <div class="table-container">
                <div class="table-toolbar">
                    <div class="toolbar-left">
                        <div class="search-box">
                            <span>🔍</span>
                            <input type="text" id="searchAtsCandidates" placeholder="Cari nama, email, NIK..." onkeyup="debounceAtsCandidatesSearch()">
                        </div>
                    </div>
                    <div class="toolbar-right">
                        <button class="btn-primary" onclick="openAddCandidateModal()">+ Tambah Kandidat</button>
                    </div>
                </div>
                <table>
                    <thead>
                        <tr>
                            <th>No. Kandidat</th>
                            <th>Nama Lengkap</th>
                            <th>Kontak</th>
                            <th>Perusahaan & Posisi</th>
                            <th>Sumber</th>
                            <th>Status Akun</th>
                            <th style="text-align: right;">Aksi</th>
                        </tr>
                    </thead>
                    <tbody id="atsCandidatesTableBody">
                        <tr><td colspan="7" class="loading-td"><div class="spinner"></div> Memuat database kandidat...</td></tr>
                    </tbody>
                </table>
            </div>
        </div>

        <!-- SUB-TAB 4: JADWAL INTERVIEW -->
        <div id="atsSubInterviews" class="ats-sub-content">
            <div class="table-container">
                <div class="table-toolbar">
                    <div class="toolbar-left">
                        <div style="font-weight: 600; font-size: 0.9rem; color: #ffffff;">📅 Jadwal Interview & Asesmen Terdaftar</div>
                    </div>
                </div>
                <table>
                    <thead>
                        <tr>
                            <th>No. Lamaran & Kandidat</th>
                            <th>Tahap</th>
                            <th>Pewawancara</th>
                            <th>Waktu & Durasi</th>
                            <th>Lokasi / Tautan</th>
                            <th>Status</th>
                            <th>Hasil & Skor</th>
                            <th style="text-align: right;">Aksi</th>
                        </tr>
                    </thead>
                    <tbody id="atsInterviewsTableBody">
                        <tr><td colspan="8" class="loading-td"><div class="spinner"></div> Memuat jadwal interview...</td></tr>
                    </tbody>
                </table>
            </div>
        </div>
    </section>

    <!-- TAB: INTERNSHIP MANAGEMENT -->
    <section id="internshipTab" class="tab-content">
        <div class="section-header">
            <div>
                <h2 class="section-title">🎓 Internship & Student Mentorship Management</h2>
                <div class="section-desc">Pusat tata kelola program magang PKP SecureGate: penugasan mentor, verifikasi logbook harian, laporan bulanan, evaluasi berkala, dan sertifikasi kelulusan.</div>
            </div>
            <div style="display: flex; gap: 0.75rem; flex-wrap: wrap;">
                <button class="btn-secondary" onclick="loadInternshipData(); showToast('Memperbarui data magang...', 'info');">
                    🔄 Refresh Data
                </button>
                <button class="btn-primary" onclick="openAddInternshipModal()">
                    + Tambah Pemagang
                </button>
                <button class="btn-action" style="background: rgba(16, 185, 129, 0.2); color: #34d399; border: 1px solid rgba(16, 185, 129, 0.4);" onclick="openConvertCandidateModal()">
                    👥 Konversi dari Pelamar
                </button>
            </div>
        </div>

        <!-- Internship Metrics Grid -->
        <div class="metrics-grid" style="margin-bottom: 2rem;">
            <div class="metric-card">
                <div class="metric-icon-box icon-blue">🎓</div>
                <div>
                    <div class="metric-label">Pemagang Aktif</div>
                    <div class="metric-value" id="internMetricActive">-</div>
                </div>
            </div>
            <div class="metric-card">
                <div class="metric-icon-box" style="background: rgba(245, 158, 11, 0.15); color: #f59e0b;">📑</div>
                <div>
                    <div class="metric-label">Menunggu Review Laporan</div>
                    <div class="metric-value" id="internMetricPendingReports">-</div>
                </div>
            </div>
            <div class="metric-card">
                <div class="metric-icon-box icon-green">✓</div>
                <div>
                    <div class="metric-label">Program Selesai</div>
                    <div class="metric-value" id="internMetricCompleted">-</div>
                </div>
            </div>
            <div class="metric-card">
                <div class="metric-icon-box icon-indigo">📝</div>
                <div>
                    <div class="metric-label">Total Log Aktivitas</div>
                    <div class="metric-value" id="internMetricTotalActivities">-</div>
                </div>
            </div>
        </div>

        <!-- Internship Sub-Navigation Pills -->
        <div class="ats-nav-pills">
            <button class="ats-nav-pill active" id="pillInterns" onclick="switchInternPill('interns', this)">
                👥 Program & Daftar Pemagang
            </button>
            <button class="ats-nav-pill" id="pillInternActivities" onclick="switchInternPill('activities', this)">
                📝 Logbook Aktivitas Harian
            </button>
            <button class="ats-nav-pill" id="pillInternReports" onclick="switchInternPill('reports', this)">
                📑 Laporan Bulanan & Akhir
            </button>
            <button class="ats-nav-pill" id="pillInternEvaluations" onclick="switchInternPill('evaluations', this)">
                ⭐ Evaluasi & Penilaian
            </button>
        </div>

        <!-- SUB-TAB 1: DAFTAR PEMAGANG -->
        <div id="internSubInterns" class="ats-sub-content active">
            <div class="table-container">
                <div class="table-toolbar">
                    <div class="toolbar-left">
                        <div class="search-box">
                            <span>🔍</span>
                            <input type="text" id="searchInternships" placeholder="Cari nama, institusi, jurusan..." onkeyup="debounceInternshipsSearch()">
                        </div>
                        <div class="search-box">
                            <span>🏷️</span>
                            <select id="filterInternshipStatus" onchange="loadInternships()">
                                <option value="">Semua Status</option>
                                <option value="ACTIVE" selected>Aktif (ACTIVE)</option>
                                <option value="PENDING">Menunggu (PENDING)</option>
                                <option value="COMPLETED">Selesai (COMPLETED)</option>
                                <option value="SUSPENDED">Ditangguhkan (SUSPENDED)</option>
                            </select>
                        </div>
                    </div>
                    <div class="toolbar-right">
                        <button class="btn-primary" onclick="openAddInternshipModal()">+ Tambah Pemagang</button>
                    </div>
                </div>
                <table>
                    <thead>
                        <tr>
                            <th>No. Magang</th>
                            <th>Nama & Kontak</th>
                            <th>Institusi & Jurusan</th>
                            <th>Divisi & Posisi</th>
                            <th>Mentor Perusahaan</th>
                            <th>Periode Magang</th>
                            <th>Status</th>
                            <th style="text-align: right;">Aksi</th>
                        </tr>
                    </thead>
                    <tbody id="internsTableBody">
                        <tr><td colspan="8" class="loading-td"><div class="spinner"></div> Memuat daftar pemagang...</td></tr>
                    </tbody>
                </table>
            </div>
        </div>

        <!-- SUB-TAB 2: LOGBOOK AKTIVITAS HARIAN -->
        <div id="internSubActivities" class="ats-sub-content">
            <div class="table-container">
                <div class="table-toolbar">
                    <div class="toolbar-left">
                        <div style="font-weight: 600; font-size: 0.9rem; color: #ffffff;">📝 Logbook Aktivitas Pemagang</div>
                    </div>
                    <div class="toolbar-right">
                        <button class="btn-primary" onclick="openLogActivityModal()">+ Catat Aktivitas Harian</button>
                    </div>
                </div>
                <table>
                    <thead>
                        <tr>
                            <th>Tanggal & Waktu</th>
                            <th>Pemagang</th>
                            <th>Judul Aktivitas & Deskripsi</th>
                            <th>Tugas Proyek</th>
                            <th>Progres</th>
                            <th>Status</th>
                            <th>Catatan Mentor</th>
                            <th style="text-align: right;">Aksi</th>
                        </tr>
                    </thead>
                    <tbody id="internActivitiesTableBody">
                        <tr><td colspan="8" class="loading-td"><div class="spinner"></div> Memuat aktivitas harian...</td></tr>
                    </tbody>
                </table>
            </div>
        </div>

        <!-- SUB-TAB 3: LAPORAN BULANAN & AKHIR -->
        <div id="internSubReports" class="ats-sub-content">
            <div class="table-container">
                <div class="table-toolbar">
                    <div class="toolbar-left">
                        <div style="font-weight: 600; font-size: 0.9rem; color: #ffffff;">📑 Laporan Bulanan & Laporan Akhir Magang</div>
                    </div>
                    <div class="toolbar-right">
                        <button class="btn-primary" onclick="openSubmitReportModal()">+ Ajukan Laporan</button>
                    </div>
                </div>
                <table>
                    <thead>
                        <tr>
                            <th>Tipe & Periode</th>
                            <th>Pemagang</th>
                            <th>Judul Laporan</th>
                            <th>Ringkasan & Capaian</th>
                            <th>Status</th>
                            <th>Catatan Mentor</th>
                            <th style="text-align: right;">Aksi</th>
                        </tr>
                    </thead>
                    <tbody id="internReportsTableBody">
                        <tr><td colspan="7" class="loading-td"><div class="spinner"></div> Memuat laporan magang...</td></tr>
                    </tbody>
                </table>
            </div>
        </div>

        <!-- SUB-TAB 4: EVALUASI & PENILAIAN -->
        <div id="internSubEvaluations" class="ats-sub-content">
            <div class="table-container">
                <div class="table-toolbar">
                    <div class="toolbar-left">
                        <div style="font-weight: 600; font-size: 0.9rem; color: #ffffff;">⭐ Evaluasi & Lembar Penilaian Magang</div>
                    </div>
                    <div class="toolbar-right">
                        <button class="btn-primary" onclick="openSubmitEvaluationModal()">+ Beri Evaluasi</button>
                    </div>
                </div>
                <table>
                    <thead>
                        <tr>
                            <th>Pemagang</th>
                            <th>Tipe Evaluasi</th>
                            <th>Penilai & Role</th>
                            <th>Nilai Rata-rata</th>
                            <th>Rekomendasi</th>
                            <th>Tanggal Evaluasi</th>
                            <th style="text-align: right;">Aksi</th>
                        </tr>
                    </thead>
                    <tbody id="internEvaluationsTableBody">
                        <tr><td colspan="7" class="loading-td"><div class="spinner"></div> Memuat data evaluasi...</td></tr>
                    </tbody>
                </table>
            </div>
        </div>
    </section>

    <!-- SECTION 9: ONBOARDING, CONTRACTS & HR DOCUMENTS -->
    <section class="tab-content" id="onboardingTab">
        <!-- Header & Action -->
        <div class="table-toolbar" style="margin-bottom: 1.5rem; background: var(--card-bg); border: 1px solid var(--border-color); border-radius: 1rem; padding: 1.25rem 1.5rem;">
            <div class="toolbar-left">
                <h2 style="margin: 0; font-size: 1.35rem; font-weight: 700; color: #ffffff; display: flex; align-items: center; gap: 0.65rem;">
                    <span>📑</span> Onboarding, Kontrak & Dokumen HR
                </h2>
                <div style="font-size: 0.85rem; color: var(--text-muted); margin-top: 0.25rem;">
                    Platform terpadu orientasi karyawan baru, manajemen kontrak kerja, verifikasi berkas privat, dan checklist kesiapan kerja.
                </div>
            </div>
            <div class="toolbar-right" style="display: flex; gap: 0.75rem;">
                <button class="btn-secondary" onclick="loadOnboardingData(); showToast('Data Onboarding disinkronkan', 'info');">
                    🔄 Refresh Data
                </button>
                <button class="btn-primary" onclick="openAddOnboardingCaseModal()">
                    + Buat Kasus Onboarding
                </button>
            </div>
        </div>

        <!-- 4 KPI Metrics -->
        <div class="metrics-grid" style="margin-bottom: 2rem;">
            <div class="metric-card">
                <div class="metric-icon-box icon-blue">📋</div>
                <div>
                    <div class="metric-label">Kasus Onboarding Aktif</div>
                    <div class="metric-value" id="metricActiveOnboardings">-</div>
                </div>
            </div>
            <div class="metric-card">
                <div class="metric-icon-box icon-red">⚠️</div>
                <div>
                    <div class="metric-label">Tugas Terkendala (Blocked)</div>
                    <div class="metric-value" id="metricBlockedTasks">-</div>
                </div>
            </div>
            <div class="metric-card">
                <div class="metric-icon-box icon-green">📜</div>
                <div>
                    <div class="metric-label">Kontrak Kerja Aktif</div>
                    <div class="metric-value" id="metricActiveContracts">-</div>
                </div>
            </div>
            <div class="metric-card">
                <div class="metric-icon-box icon-yellow">🛡️</div>
                <div>
                    <div class="metric-label">Dokumen Menunggu Verifikasi</div>
                    <div class="metric-value" id="metricPendingDocuments">-</div>
                </div>
            </div>
        </div>

        <!-- Sub-Navigation Pills -->
        <div class="ats-subnav" style="display: flex; gap: 0.5rem; margin-bottom: 1.5rem; border-bottom: 1px solid var(--border-color); padding-bottom: 0.75rem;">
            <button class="subnav-btn active" onclick="switchOnboardingSubTab('cases', this)">📋 Kasus & Checklist Onboarding</button>
            <button class="subnav-btn" onclick="switchOnboardingSubTab('contracts', this)">📜 Kontrak Kerja (PKWT/PKWTT)</button>
            <button class="subnav-btn" onclick="switchOnboardingSubTab('documents', this)">📁 Repositori Dokumen HR</button>
            <button class="subnav-btn" onclick="switchOnboardingSubTab('expiring', this)">⏰ Peringatan Jatuh Tempo</button>
        </div>

        <!-- SUB-TAB 1: KASUS ONBOARDING -->
        <div id="onbSubCases" class="ats-sub-content">
            <div class="table-container">
                <div class="table-toolbar">
                    <div class="toolbar-left" style="display: flex; gap: 0.75rem; align-items: center; flex-wrap: wrap;">
                        <input type="text" id="onbCaseSearch" placeholder="Cari nomor kasus / nama..." oninput="debounceOnboardingSearch()" style="width: 250px;">
                        <select id="onbCaseStatusFilter" onchange="loadOnboardingCases()" style="width: 170px;">
                            <option value="">Semua Status</option>
                            <option value="PENDING">PENDING</option>
                            <option value="IN_PROGRESS">IN_PROGRESS</option>
                            <option value="BLOCKED">BLOCKED</option>
                            <option value="COMPLETED">COMPLETED</option>
                        </select>
                        <select id="onbCaseTypeFilter" onchange="loadOnboardingCases()" style="width: 170px;">
                            <option value="">Semua Tipe</option>
                            <option value="PERMANENT">Karyawan Tetap</option>
                            <option value="FIXED_TERM">Kontrak (PKWT)</option>
                            <option value="PROBATION">Probation</option>
                            <option value="INTERNSHIP">Magang / Intern</option>
                        </select>
                    </div>
                </div>
                <table>
                    <thead>
                        <tr>
                            <th>No. Kasus</th>
                            <th>Karyawan / Pemagang</th>
                            <th>Divisi & Jabatan</th>
                            <th>Tipe & Lokasi</th>
                            <th>Tgl Mulai</th>
                            <th>Progres Checklist</th>
                            <th>Status</th>
                            <th style="text-align: right;">Aksi</th>
                        </tr>
                    </thead>
                    <tbody id="onboardingCasesTableBody">
                        <tr><td colspan="8" class="loading-td"><div class="spinner"></div> Memuat kasus onboarding...</td></tr>
                    </tbody>
                </table>
            </div>
        </div>

        <!-- SUB-TAB 2: KONTRAK KERJA -->
        <div id="onbSubContracts" class="ats-sub-content" style="display: none;">
            <div class="table-container">
                <div class="table-toolbar">
                    <div class="toolbar-left" style="display: flex; gap: 0.75rem; align-items: center;">
                        <input type="text" id="onbContractSearch" placeholder="Cari nomor kontrak / karyawan..." oninput="debounceContractSearch()" style="width: 260px;">
                        <select id="onbContractTypeFilter" onchange="loadOnboardingContracts()" style="width: 180px;">
                            <option value="">Semua Jenis Kontrak</option>
                            <option value="PERMANENT">Tetap (PKWTT)</option>
                            <option value="FIXED_TERM">Waktu Tertentu (PKWT)</option>
                            <option value="PROBATION">Masa Percobaan</option>
                            <option value="INTERNSHIP">Perjanjian Magang</option>
                            <option value="NDA">Kerahasiaan (NDA)</option>
                        </select>
                    </div>
                    <div class="toolbar-right">
                        <button class="btn-primary" onclick="openAddContractModal()">+ Buat Kontrak Baru</button>
                    </div>
                </div>
                <table>
                    <thead>
                        <tr>
                            <th>No. Kontrak</th>
                            <th>Karyawan</th>
                            <th>Jenis Kontrak & Judul</th>
                            <th>Periode Efektif</th>
                            <th>Penandatangan</th>
                            <th>Status</th>
                            <th>Status Perpanjangan</th>
                            <th style="text-align: right;">Aksi</th>
                        </tr>
                    </thead>
                    <tbody id="contractsTableBody">
                        <tr><td colspan="8" class="loading-td"><div class="spinner"></div> Memuat data kontrak kerja...</td></tr>
                    </tbody>
                </table>
            </div>
        </div>

        <!-- SUB-TAB 3: REPOSITORI DOKUMEN HR -->
        <div id="onbSubDocuments" class="ats-sub-content" style="display: none;">
            <div class="table-container">
                <div class="table-toolbar">
                    <div class="toolbar-left" style="display: flex; gap: 0.75rem; align-items: center;">
                        <input type="text" id="onbDocSearch" placeholder="Cari nomor berkas / nama dokumen..." oninput="debounceDocSearch()" style="width: 260px;">
                        <select id="onbDocCategoryFilter" onchange="loadOnboardingDocuments()" style="width: 180px;">
                            <option value="">Semua Kategori</option>
                            <option value="IDENTITY">Identitas (KTP/KK)</option>
                            <option value="CONTRACT">Kontrak Kerja</option>
                            <option value="NDA">NDA & Kebijakan</option>
                            <option value="EDUCATION">Ijazah / Pendidikan</option>
                            <option value="CERTIFICATION">Sertifikasi Keahlian</option>
                            <option value="ASSIGNMENT">Surat Tugas</option>
                            <option value="MEDICAL">Kesehatan (Medical)</option>
                            <option value="INTERNSHIP">Berkas Magang</option>
                            <option value="OTHER">Lainnya</option>
                        </select>
                    </div>
                    <div class="toolbar-right">
                        <button class="btn-primary" onclick="openUploadDocumentModal()">+ Unggah Dokumen Privat</button>
                    </div>
                </div>
                <table>
                    <thead>
                        <tr>
                            <th>No. Dokumen</th>
                            <th>Pemilik Berkas</th>
                            <th>Kategori & Judul</th>
                            <th>Versi & Ukuran</th>
                            <th>Visibilitas</th>
                            <th>Status Verifikasi</th>
                            <th>Tgl Unggah</th>
                            <th style="text-align: right;">Aksi Unduh / Cek</th>
                        </tr>
                    </thead>
                    <tbody id="documentsTableBody">
                        <tr><td colspan="8" class="loading-td"><div class="spinner"></div> Memuat repositori dokumen...</td></tr>
                    </tbody>
                </table>
            </div>
        </div>

        <!-- SUB-TAB 4: PERINGATAN JATUH TEMPO -->
        <div id="onbSubExpiring" class="ats-sub-content" style="display: none;">
            <div class="table-container">
                <div class="table-toolbar">
                    <div class="toolbar-left">
                        <div style="font-weight: 600; font-size: 0.95rem; color: #f59e0b;">⚠️ Peringatan Kontrak Kerja Berakhir Dalam 30 Hari</div>
                    </div>
                </div>
                <table>
                    <thead>
                        <tr>
                            <th>No. Kontrak</th>
                            <th>Karyawan</th>
                            <th>Jenis Kontrak</th>
                            <th>Tanggal Berakhir</th>
                            <th>Sisa Hari</th>
                            <th>Status Perpanjangan</th>
                            <th style="text-align: right;">Aksi Tindak Lanjut</th>
                        </tr>
                    </thead>
                    <tbody id="expiringContractsTableBody">
                        <tr><td colspan="7" class="loading-td"><div class="spinner"></div> Memeriksa masa berlaku kontrak...</td></tr>
                    </tbody>
                </table>
            </div>
        </div>
    </section>

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
            <div class="form-row"><label>Email</label><input type="email" id="empEmail" placeholder="name@company.com"></div>
            <div class="form-row"><label>Telepon</label><input type="text" id="empPhone" placeholder="08..."></div>
            <div class="form-row"><label>Gedung</label><select id="empBuilding"><option value="">Pilih Gedung</option></select></div>
            <div class="form-row"><label>Divisi</label><select id="empDivision"><option value="">Pilih Divisi</option></select></div>
            <div class="form-row"><label>Posisi</label><select id="empPosition"><option value="">Pilih Posisi</option></select></div>
            <div class="form-row"><label>Tipe / Status Kerja</label><div style="display:flex;gap:.5rem"><select id="empEmploymentType"><option value="">Pilih Tipe</option><option>PERMANENT</option><option>CONTRACT</option><option>OUTSOURCE</option></select><select id="empEmploymentStatus"><option value="ACTIVE">ACTIVE</option><option value="INACTIVE">INACTIVE</option></select></div></div>
            <div class="form-row"><label>Tanggal Masuk</label><input type="date" id="empHireDate"></div>            <div class="form-row">
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

<div class="modal-overlay" id="employee360Modal"><div class="modal-card"><div class="modal-header"><h3 class="modal-title">Employee 360</h3><button class="modal-close-btn" onclick="closeModal(&quot;employee360Modal&quot;)">✖</button></div><div id="employee360Content" class="section-desc">Memuat profil…</div></div></div>

<!-- RECRUITMENT MODAL 1: BUAT LOWONGAN -->
<div class="modal-overlay" id="modalAddVacancy">
    <div class="modal-card">
        <div class="modal-header">
            <h3 class="modal-title">💼 Buat Lowongan Pekerjaan Baru</h3>
            <button class="modal-close-btn" onclick="closeModal('modalAddVacancy')">✖</button>
        </div>
        <form id="formAddVacancy" onsubmit="saveVacancy(event)">
            <div class="form-row">
                <label>Judul Lowongan / Posisi</label>
                <input type="text" id="vacTitle" placeholder="Contoh: Senior Backend Engineer" required>
            </div>
            <div style="display: grid; grid-template-columns: 1fr 1fr; gap: 0.75rem;">
                <div class="form-row">
                    <label>Tipe Pekerjaan</label>
                    <select id="vacEmploymentType">
                        <option value="FULL_TIME">Full Time</option>
                        <option value="CONTRACT">Contract</option>
                        <option value="INTERNSHIP">Internship / Magang</option>
                        <option value="PART_TIME">Part Time</option>
                    </select>
                </div>
                <div class="form-row">
                    <label>Level Pengalaman</label>
                    <select id="vacExperienceLevel">
                        <option value="ENTRY">Entry Level</option>
                        <option value="JUNIOR">Junior</option>
                        <option value="MID" selected>Mid Level</option>
                        <option value="SENIOR">Senior</option>
                        <option value="LEAD">Lead / Managerial</option>
                    </select>
                </div>
            </div>
            <div style="display: grid; grid-template-columns: 1fr 1fr; gap: 0.75rem;">
                <div class="form-row">
                    <label>Divisi Organisasi</label>
                    <select id="vacDivisionId"><option value="">Pilih Divisi</option></select>
                </div>
                <div class="form-row">
                    <label>Gedung Penempatan</label>
                    <select id="vacBuildingId"><option value="">Pilih Gedung</option></select>
                </div>
            </div>
            <div style="display: grid; grid-template-columns: 1fr 1fr 1fr; gap: 0.75rem;">
                <div class="form-row">
                    <label>Kuota Posisi</label>
                    <input type="number" id="vacQuota" value="1" min="1" required>
                </div>
                <div class="form-row">
                    <label>Gaji Min (IDR)</label>
                    <input type="number" id="vacSalaryMin" placeholder="8000000">
                </div>
                <div class="form-row">
                    <label>Gaji Max (IDR)</label>
                    <input type="number" id="vacSalaryMax" placeholder="15000000">
                </div>
            </div>
            <div class="form-row">
                <label>Deskripsi Tanggung Jawab</label>
                <textarea id="vacDescription" rows="3" style="width: 100%; background: var(--card-bg); border: 1px solid var(--border-color); color: #fff; padding: 0.65rem; border-radius: 0.6rem;" placeholder="Jelaskan peran kerja..." required></textarea>
            </div>
            <div class="form-row">
                <label>Persyaratan & Kualifikasi</label>
                <textarea id="vacRequirements" rows="2" style="width: 100%; background: var(--card-bg); border: 1px solid var(--border-color); color: #fff; padding: 0.65rem; border-radius: 0.6rem;" placeholder="Daftar keahlian yang dibutuhkan..."></textarea>
            </div>
            <div style="display: flex; justify-content: flex-end; gap: 0.75rem; margin-top: 1.25rem;">
                <button type="button" class="btn-secondary" onclick="closeModal('modalAddVacancy')">Batal</button>
                <button type="submit" class="btn-primary">Publikasikan Lowongan</button>
            </div>
        </form>
    </div>
</div>

<!-- RECRUITMENT MODAL 2: DAFTARKAN KANDIDAT -->
<div class="modal-overlay" id="modalAddCandidate">
    <div class="modal-card">
        <div class="modal-header">
            <h3 class="modal-title">👤 Daftarkan Kandidat Baru</h3>
            <button class="modal-close-btn" onclick="closeModal('modalAddCandidate')">✖</button>
        </div>
        <form id="formAddCandidate" onsubmit="saveCandidate(event)">
            <div style="display: grid; grid-template-columns: 1fr 1fr; gap: 0.75rem;">
                <div class="form-row">
                    <label>Nama Depan</label>
                    <input type="text" id="candFirstName" placeholder="Nama Depan" required>
                </div>
                <div class="form-row">
                    <label>Nama Belakang</label>
                    <input type="text" id="candLastName" placeholder="Nama Belakang">
                </div>
            </div>
            <div style="display: grid; grid-template-columns: 1fr 1fr; gap: 0.75rem;">
                <div class="form-row">
                    <label>Email</label>
                    <input type="email" id="candEmail" placeholder="email@domain.com" required>
                </div>
                <div class="form-row">
                    <label>Nomor Telepon / WhatsApp</label>
                    <input type="text" id="candPhone" placeholder="08..." required>
                </div>
            </div>
            <div style="display: grid; grid-template-columns: 1fr 1fr; gap: 0.75rem;">
                <div class="form-row">
                    <label>NIK / KTP</label>
                    <input type="text" id="candNationalId" placeholder="3201...">
                </div>
                <div class="form-row">
                    <label>Sumber Pelamar</label>
                    <select id="candSource">
                        <option value="CAREER_SITE">Career Website PKP</option>
                        <option value="LINKEDIN">LinkedIn</option>
                        <option value="REFERRAL">Referral Karyawan</option>
                        <option value="JOB_FAIR">Job Fair / Kampus</option>
                        <option value="INTERNAL">Internal</option>
                    </select>
                </div>
            </div>
            <div style="display: grid; grid-template-columns: 1fr 1fr; gap: 0.75rem;">
                <div class="form-row">
                    <label>Perusahaan Terakhir</label>
                    <input type="text" id="candCompany" placeholder="Nama Perusahaan">
                </div>
                <div class="form-row">
                    <label>Posisi Terakhir</label>
                    <input type="text" id="candPosition" placeholder="Contoh: Backend Developer">
                </div>
            </div>
            <div style="display: flex; justify-content: flex-end; gap: 0.75rem; margin-top: 1.25rem;">
                <button type="button" class="btn-secondary" onclick="closeModal('modalAddCandidate')">Batal</button>
                <button type="submit" class="btn-primary">Simpan Kandidat</button>
            </div>
        </form>
    </div>
</div>

<!-- RECRUITMENT MODAL 3: LAMAR KE LOWONGAN -->
<div class="modal-overlay" id="modalApplyVacancy">
    <div class="modal-card">
        <div class="modal-header">
            <h3 class="modal-title">📝 Daftarkan Lamaran Kandidat</h3>
            <button class="modal-close-btn" onclick="closeModal('modalApplyVacancy')">✖</button>
        </div>
        <form id="formApplyVacancy" onsubmit="saveApplication(event)">
            <div class="form-row">
                <label>Pilih Kandidat</label>
                <select id="applyCandidateId" required></select>
            </div>
            <div class="form-row">
                <label>Pilih Posisi Lowongan</label>
                <select id="applyVacancyId" required></select>
            </div>
            <div class="form-row">
                <label>Ekspektasi Gaji (IDR)</label>
                <input type="number" id="applyExpectedSalary" placeholder="10000000">
            </div>
            <div class="form-row">
                <label>Catatan Tambahan</label>
                <textarea id="applyNotes" rows="2" style="width: 100%; background: var(--card-bg); border: 1px solid var(--border-color); color: #fff; padding: 0.65rem; border-radius: 0.6rem;"></textarea>
            </div>
            <div style="display: flex; justify-content: flex-end; gap: 0.75rem; margin-top: 1.25rem;">
                <button type="button" class="btn-secondary" onclick="closeModal('modalApplyVacancy')">Batal</button>
                <button type="submit" class="btn-primary">Kirimkan Lamaran</button>
            </div>
        </form>
    </div>
</div>

<!-- RECRUITMENT MODAL 4: UBAH TAHAPAN PIPELINE -->
<div class="modal-overlay" id="modalTransitionStage">
    <div class="modal-card">
        <div class="modal-header">
            <h3 class="modal-title">🔄 Ubah Tahapan Pelamar</h3>
            <button class="modal-close-btn" onclick="closeModal('modalTransitionStage')">✖</button>
        </div>
        <form id="formTransitionStage" onsubmit="submitTransitionStage(event)">
            <input type="hidden" id="transAppId">
            <div class="form-row">
                <label>Pelamar</label>
                <div id="transCandName" style="font-weight: 700; color: var(--primary); margin-bottom: 0.5rem;"></div>
            </div>
            <div class="form-row">
                <label>Pindah ke Tahapan</label>
                <select id="transNewStage" required>
                    <option value="SCREENING">Screening CV</option>
                    <option value="HR_INTERVIEW">Interview HR</option>
                    <option value="TECHNICAL_TEST">Tes Teknis / Assessment</option>
                    <option value="USER_INTERVIEW">Interview User / Supervisor</option>
                    <option value="MANAGEMENT_REVIEW">Review Manajemen</option>
                    <option value="OFFER">Offering & Kontrak</option>
                    <option value="REJECTED">Tolak Lamaran (Reject)</option>
                    <option value="TALENT_POOL">Simpan ke Talent Pool</option>
                </select>
            </div>
            <div class="form-row">
                <label>Alasan / Catatan Perubahan</label>
                <textarea id="transReason" rows="2" style="width: 100%; background: var(--card-bg); border: 1px solid var(--border-color); color: #fff; padding: 0.65rem; border-radius: 0.6rem;"></textarea>
            </div>
            <div style="display: flex; justify-content: flex-end; gap: 0.75rem; margin-top: 1.25rem;">
                <button type="button" class="btn-secondary" onclick="closeModal('modalTransitionStage')">Batal</button>
                <button type="submit" class="btn-primary">Update Tahapan</button>
            </div>
        </form>
    </div>
</div>

<!-- RECRUITMENT MODAL 5: JADWALKAN INTERVIEW -->
<div class="modal-overlay" id="modalScheduleInterview">
    <div class="modal-card">
        <div class="modal-header">
            <h3 class="modal-title">📅 Jadwalkan Interview</h3>
            <button class="modal-close-btn" onclick="closeModal('modalScheduleInterview')">✖</button>
        </div>
        <form id="formScheduleInterview" onsubmit="saveInterviewSchedule(event)">
            <input type="hidden" id="schAppId">
            <div class="form-row">
                <label>Pelamar</label>
                <div id="schCandName" style="font-weight: 700; color: var(--primary); margin-bottom: 0.5rem;"></div>
            </div>
            <div style="display: grid; grid-template-columns: 1fr 1fr; gap: 0.75rem;">
                <div class="form-row">
                    <label>Tahap Interview</label>
                    <select id="schStageCode" required>
                        <option value="HR_INTERVIEW">Interview HR</option>
                        <option value="TECHNICAL_TEST">Tes Teknis</option>
                        <option value="USER_INTERVIEW">Interview User</option>
                        <option value="MANAGEMENT_REVIEW">Review Manajemen</option>
                    </select>
                </div>
                <div class="form-row">
                    <label>Durasi (Menit)</label>
                    <input type="number" id="schDuration" value="45" min="15" max="180">
                </div>
            </div>
            <div class="form-row">
                <label>Waktu Pelaksanaan</label>
                <input type="datetime-local" id="schDateTime" required>
            </div>
            <div class="form-row">
                <label>Lokasi Fisik / Tautan Google Meet</label>
                <input type="text" id="schLocation" placeholder="Ruang Meeting Gedung A / https://meet.google.com/..." required>
            </div>
            <div style="display: flex; justify-content: flex-end; gap: 0.75rem; margin-top: 1.25rem;">
                <button type="button" class="btn-secondary" onclick="closeModal('modalScheduleInterview')">Batal</button>
                <button type="submit" class="btn-primary">Tetapkan Jadwal</button>
            </div>
        </form>
    </div>
</div>

<!-- RECRUITMENT MODAL 6: FEEDBACK & SKOR INTERVIEW -->
<div class="modal-overlay" id="modalInterviewFeedback">
    <div class="modal-card">
        <div class="modal-header">
            <h3 class="modal-title">📝 Evaluasi & Nilai Interview</h3>
            <button class="modal-close-btn" onclick="closeModal('modalInterviewFeedback')">✖</button>
        </div>
        <form id="formInterviewFeedback" onsubmit="saveInterviewFeedback(event)">
            <input type="hidden" id="fbInterviewId">
            <div class="form-row">
                <label>Skor Evaluasi (1 - 100)</label>
                <input type="number" id="fbScore" min="1" max="100" placeholder="85" required>
            </div>
            <div class="form-row">
                <label>Rekomendasi Pewawancara</label>
                <select id="fbRecommendation" required>
                    <option value="PROCEED">PROCEED (Lolos ke Tahap Berikutnya)</option>
                    <option value="HOLD">HOLD (Pertimbangkan / Cadangan)</option>
                    <option value="REJECT">REJECT (Tidak Memenuhi Syarat)</option>
                </select>
            </div>
            <div class="form-row">
                <label>Catatan & Feedback Lengkap</label>
                <textarea id="fbNotes" rows="3" style="width: 100%; background: var(--card-bg); border: 1px solid var(--border-color); color: #fff; padding: 0.65rem; border-radius: 0.6rem;" placeholder="Catatan teknis, soft skill, dan pertimbangan..." required></textarea>
            </div>
            <div style="display: flex; justify-content: flex-end; gap: 0.75rem; margin-top: 1.25rem;">
                <button type="button" class="btn-secondary" onclick="closeModal('modalInterviewFeedback')">Batal</button>
                <button type="submit" class="btn-primary">Simpan Hasil Evaluasi</button>
            </div>
        </form>
    </div>
</div>

<!-- RECRUITMENT MODAL 7: BUAT OFFERING LETTER -->
<div class="modal-overlay" id="modalCreateOffer">
    <div class="modal-card">
        <div class="modal-header">
            <h3 class="modal-title">📄 Buat Surat Penawaran Kerja (Offering)</h3>
            <button class="modal-close-btn" onclick="closeModal('modalCreateOffer')">✖</button>
        </div>
        <form id="formCreateOffer" onsubmit="saveOffer(event)">
            <input type="hidden" id="offAppId">
            <div class="form-row">
                <label>Pelamar</label>
                <div id="offCandName" style="font-weight: 700; color: var(--primary); margin-bottom: 0.5rem;"></div>
            </div>
            <div class="form-row">
                <label>Gaji Pokok Ditawarkan (IDR / Bulan)</label>
                <input type="number" id="offSalary" placeholder="12000000" required>
            </div>
            <div style="display: grid; grid-template-columns: 1fr 1fr; gap: 0.75rem;">
                <div class="form-row">
                    <label>Tanggal Mulai Masuk (Start Date)</label>
                    <input type="date" id="offStartDate" required>
                </div>
                <div class="form-row">
                    <label>Batas Respon Offering</label>
                    <input type="date" id="offExpiryDate" required>
                </div>
            </div>
            <div class="form-row">
                <label>Ketentuan & Syarat Khusus</label>
                <textarea id="offTerms" rows="2" style="width: 100%; background: var(--card-bg); border: 1px solid var(--border-color); color: #fff; padding: 0.65rem; border-radius: 0.6rem;" placeholder="Ketentuan probation, fasilitas, tunjangan..."></textarea>
            </div>
            <div style="display: flex; justify-content: flex-end; gap: 0.75rem; margin-top: 1.25rem;">
                <button type="button" class="btn-secondary" onclick="closeModal('modalCreateOffer')">Batal</button>
                <button type="submit" class="btn-primary">Kirimkan Offering</button>
            </div>
        </form>
    </div>
</div>

<!-- RECRUITMENT MODAL 8: CONVERT TO EMPLOYEE MASTER (HIRE) -->
<div class="modal-overlay" id="modalConvertToEmployee">
    <div class="modal-card">
        <div class="modal-header">
            <h3 class="modal-title">🎉 Pengangkatan Karyawan Baru (Hiring)</h3>
            <button class="modal-close-btn" onclick="closeModal('modalConvertToEmployee')">✖</button>
        </div>
        <form id="formConvertToEmployee" onsubmit="submitConvertToEmployee(event)">
            <input type="hidden" id="hireAppId">
            <div class="form-row">
                <label>Kandidat Diterima</label>
                <div id="hireCandName" style="font-weight: 700; color: #10b981; font-size: 1.05rem; margin-bottom: 0.5rem;"></div>
            </div>
            <div class="form-row">
                <label>Nomor Induk Karyawan (Kosongkan untuk otomatis)</label>
                <input type="text" id="hireEmployeeNo" placeholder="EMP-2026-XXXX">
            </div>
            <div class="form-row">
                <label>Status Hubungan Kerja</label>
                <select id="hireEmploymentStatus">
                    <option value="permanent">Karyawan Tetap (Permanent)</option>
                    <option value="contract" selected>Kontrak (PKWT)</option>
                    <option value="probation">Probation / Masa Percobaan</option>
                    <option value="internship">Internship / Magang</option>
                </select>
            </div>
            <div style="display: flex; justify-content: flex-end; gap: 0.75rem; margin-top: 1.25rem;">
                <button type="button" class="btn-secondary" onclick="closeModal('modalConvertToEmployee')">Batal</button>
                <button type="submit" class="btn-primary" style="background: #10b981; border-color: #059669;">
                    ✓ Konfirmasi Pengangkatan Karyawan
                </button>
            </div>
        </form>
    </div>
</div>

<!-- ========================================== -->
<!-- INTERNSHIP MANAGEMENT MODALS (SPRINT 4)   -->
<!-- ========================================== -->

<!-- INTERNSHIP MODAL 1: TAMBAH PEMAGANG BARU -->
<div class="modal-overlay" id="modalAddInternship">
    <div class="modal-card">
        <div class="modal-header">
            <h3 class="modal-title">🎓 Pendaftaran Program Magang Baru</h3>
            <button class="modal-close-btn" onclick="closeModal('modalAddInternship')">✖</button>
        </div>
        <form id="formAddInternship" onsubmit="saveInternship(event)">
            <div style="display: grid; grid-template-columns: 1fr 1fr; gap: 0.75rem;">
                <div class="form-row">
                    <label>Institusi / Kampus / Sekolah</label>
                    <input type="text" id="intInstitution" placeholder="Contoh: Institut Teknologi Bandung" required>
                </div>
                <div class="form-row">
                    <label>Jurusan / Program Studi</label>
                    <input type="text" id="intMajor" placeholder="Contoh: Teknik Informatika" required>
                </div>
            </div>
            <div style="display: grid; grid-template-columns: 1fr 1fr 1fr; gap: 0.75rem;">
                <div class="form-row">
                    <label>Jenjang Pendidikan</label>
                    <select id="intEducationLevel">
                        <option value="S1" selected>S1 / Sarjana</option>
                        <option value="D3">D3 / Diploma</option>
                        <option value="SMK">SMK</option>
                        <option value="S2">S2 / Pascasarjana</option>
                    </select>
                </div>
                <div class="form-row">
                    <label>Semester</label>
                    <input type="number" id="intSemester" value="6" min="1" max="14">
                </div>
                <div class="form-row">
                    <label>Posisi / Peran Magang</label>
                    <input type="text" id="intPositionTitle" value="Intern" placeholder="Full Stack Intern" required>
                </div>
            </div>
            <div style="display: grid; grid-template-columns: 1fr 1fr; gap: 0.75rem;">
                <div class="form-row">
                    <label>Tanggal Mulai</label>
                    <input type="date" id="intStartDate" required>
                </div>
                <div class="form-row">
                    <label>Tanggal Selesai</label>
                    <input type="date" id="intEndDate" required>
                </div>
            </div>
            <div style="display: grid; grid-template-columns: 1fr 1fr; gap: 0.75rem;">
                <div class="form-row">
                    <label>Divisi Penempatan</label>
                    <select id="intDivisionId"><option value="">Pilih Divisi</option></select>
                </div>
                <div class="form-row">
                    <label>Mentor Perusahaan</label>
                    <select id="intMentorId"><option value="">Pilih Mentor</option></select>
                </div>
            </div>
            <div style="display: grid; grid-template-columns: 1fr 1fr; gap: 0.75rem;">
                <div class="form-row">
                    <label>Nama Dosen/Pembimbing Kampus</label>
                    <input type="text" id="intCampusSupervisor" placeholder="Nama Dosen Pembimbing">
                </div>
                <div class="form-row">
                    <label>Kontak Pembimbing Kampus</label>
                    <input type="text" id="intCampusContact" placeholder="Email / WhatsApp Pembimbing">
                </div>
            </div>
            <div class="form-row">
                <label>Rencana Proyek & Tugas</label>
                <textarea id="intProjectAssignment" rows="2" style="width: 100%; background: var(--card-bg); border: 1px solid var(--border-color); color: #fff; padding: 0.65rem; border-radius: 0.6rem;" placeholder="Penugasan proyek selama masa magang..."></textarea>
            </div>
            <div style="display: flex; justify-content: flex-end; gap: 0.75rem; margin-top: 1.25rem;">
                <button type="button" class="btn-secondary" onclick="closeModal('modalAddInternship')">Batal</button>
                <button type="submit" class="btn-primary">Daftarkan Program Magang</button>
            </div>
        </form>
    </div>
</div>

<!-- INTERNSHIP MODAL 2: KONVERSI KANDIDAT KE MAGANG -->
<div class="modal-overlay" id="modalConvertCandidateToIntern">
    <div class="modal-card">
        <div class="modal-header">
            <h3 class="modal-title">👥 Konversi Pelamar Menjadi Pemagang Resmi</h3>
            <button class="modal-close-btn" onclick="closeModal('modalConvertCandidateToIntern')">✖</button>
        </div>
        <form id="formConvertCandidateToIntern" onsubmit="submitConvertCandidateToIntern(event)">
            <div class="form-row">
                <label>Pilih Kandidat / Pelamar</label>
                <select id="convCandidateId" required></select>
            </div>
            <div style="display: grid; grid-template-columns: 1fr 1fr; gap: 0.75rem;">
                <div class="form-row">
                    <label>Institusi / Kampus</label>
                    <input type="text" id="convInstitution" placeholder="Nama Kampus / Sekolah" required>
                </div>
                <div class="form-row">
                    <label>Jurusan</label>
                    <input type="text" id="convMajor" placeholder="Program Studi" required>
                </div>
            </div>
            <div style="display: grid; grid-template-columns: 1fr 1fr; gap: 0.75rem;">
                <div class="form-row">
                    <label>Tanggal Mulai</label>
                    <input type="date" id="convStartDate" required>
                </div>
                <div class="form-row">
                    <label>Tanggal Selesai</label>
                    <input type="date" id="convEndDate" required>
                </div>
            </div>
            <div style="display: grid; grid-template-columns: 1fr 1fr; gap: 0.75rem;">
                <div class="form-row">
                    <label>Divisi Penempatan</label>
                    <select id="convDivisionId"><option value="">Pilih Divisi</option></select>
                </div>
                <div class="form-row">
                    <label>Mentor Perusahaan</label>
                    <select id="convMentorId"><option value="">Pilih Mentor</option></select>
                </div>
            </div>
            <div class="form-row">
                <label>Posisi / Peran Magang</label>
                <input type="text" id="convPositionTitle" value="Intern" placeholder="Full Stack Intern" required>
            </div>
            <div style="display: flex; justify-content: flex-end; gap: 0.75rem; margin-top: 1.25rem;">
                <button type="button" class="btn-secondary" onclick="closeModal('modalConvertCandidateToIntern')">Batal</button>
                <button type="submit" class="btn-primary" style="background: #10b981; border-color: #059669;">
                    ✓ Konfirmasi Penerimaan Magang
                </button>
            </div>
        </form>
    </div>
</div>

<!-- INTERNSHIP MODAL 3: CATAT AKTIVITAS HARIAN -->
<div class="modal-overlay" id="modalLogInternActivity">
    <div class="modal-card">
        <div class="modal-header">
            <h3 class="modal-title">📝 Catat Logbook Aktivitas Harian</h3>
            <button class="modal-close-btn" onclick="closeModal('modalLogInternActivity')">✖</button>
        </div>
        <form id="formLogInternActivity" onsubmit="saveInternActivity(event)">
            <input type="hidden" id="actInternshipId">
            <div class="form-row">
                <label>Pemagang</label>
                <div id="actInternName" style="font-weight: 700; color: var(--primary); margin-bottom: 0.5rem;"></div>
            </div>
            <div style="display: grid; grid-template-columns: 1fr 1fr 1fr; gap: 0.75rem;">
                <div class="form-row">
                    <label>Tanggal Aktivitas</label>
                    <input type="date" id="actDate" required>
                </div>
                <div class="form-row">
                    <label>Jam Mulai</label>
                    <input type="time" id="actStartTime" value="08:30" required>
                </div>
                <div class="form-row">
                    <label>Jam Selesai</label>
                    <input type="time" id="actEndTime" value="17:00" required>
                </div>
            </div>
            <div class="form-row">
                <label>Judul Aktivitas</label>
                <input type="text" id="actTitle" placeholder="Contoh: Implementasi modul database and unit tests" required>
            </div>
            <div class="form-row">
                <label>Deskripsi Rinci Pekerjaan</label>
                <textarea id="actDescription" rows="3" style="width: 100%; background: var(--card-bg); border: 1px solid var(--border-color); color: #fff; padding: 0.65rem; border-radius: 0.6rem;" placeholder="Rincian hasil, problem yang diselesaikan, tools..." required></textarea>
            </div>
            <div style="display: grid; grid-template-columns: 1fr 1fr; gap: 0.75rem;">
                <div class="form-row">
                    <label>Referensi Tiket / Proyek</label>
                    <input type="text" id="actProjectRef" placeholder="SEC-104 / Dashboard UI">
                </div>
                <div class="form-row">
                    <label>Capaian Progres (%)</label>
                    <input type="number" id="actProgress" value="100" min="0" max="100" required>
                </div>
            </div>
            <div style="display: flex; justify-content: flex-end; gap: 0.75rem; margin-top: 1.25rem;">
                <button type="button" class="btn-secondary" onclick="closeModal('modalLogInternActivity')">Batal</button>
                <button type="submit" class="btn-primary">Kirimkan Logbook</button>
            </div>
        </form>
    </div>
</div>

<!-- INTERNSHIP MODAL 4: REVIEW AKTIVITAS HARIAN -->
<div class="modal-overlay" id="modalReviewInternActivity">
    <div class="modal-card">
        <div class="modal-header">
            <h3 class="modal-title">🔍 Verifikasi & Catatan Mentor</h3>
            <button class="modal-close-btn" onclick="closeModal('modalReviewInternActivity')">✖</button>
        </div>
        <form id="formReviewInternActivity" onsubmit="submitReviewInternActivity(event)">
            <input type="hidden" id="revActivityId">
            <div class="form-row">
                <label>Keputusan Verifikasi</label>
                <select id="revActivityStatus" required>
                    <option value="REVIEWED">REVIEWED (Disetujui & Diverifikasi)</option>
                    <option value="REJECTED">REJECTED (Perlu Diperbaiki / Ditolak)</option>
                </select>
            </div>
            <div class="form-row">
                <label>Catatan & Masukan Mentor</label>
                <textarea id="revActivityNotes" rows="3" style="width: 100%; background: var(--card-bg); border: 1px solid var(--border-color); color: #fff; padding: 0.65rem; border-radius: 0.6rem;" placeholder="Berikan arahan, koreksi, atau apresiasi..." required></textarea>
            </div>
            <div style="display: flex; justify-content: flex-end; gap: 0.75rem; margin-top: 1.25rem;">
                <button type="button" class="btn-secondary" onclick="closeModal('modalReviewInternActivity')">Batal</button>
                <button type="submit" class="btn-primary">Simpan Verifikasi</button>
            </div>
        </form>
    </div>
</div>

<!-- INTERNSHIP MODAL 5: AJUKAN LAPORAN BULANAN / AKHIR -->
<div class="modal-overlay" id="modalSubmitInternReport">
    <div class="modal-card">
        <div class="modal-header">
            <h3 class="modal-title">📑 Ajukan Laporan Magang</h3>
            <button class="modal-close-btn" onclick="closeModal('modalSubmitInternReport')">✖</button>
        </div>
        <form id="formSubmitInternReport" onsubmit="saveInternReport(event)">
            <input type="hidden" id="repInternshipId">
            <div class="form-row">
                <label>Pemagang</label>
                <div id="repInternName" style="font-weight: 700; color: var(--primary); margin-bottom: 0.5rem;"></div>
            </div>
            <div style="display: grid; grid-template-columns: 1fr 1fr; gap: 0.75rem;">
                <div class="form-row">
                    <label>Jenis Laporan</label>
                    <select id="repType" required>
                        <option value="MONTHLY">Laporan Bulanan (Monthly Report)</option>
                        <option value="MID_TERM">Laporan Tengah Periode (Mid-term)</option>
                        <option value="FINAL">Laporan Akhir Magang (Final Report)</option>
                    </select>
                </div>
                <div class="form-row">
                    <label>Periode Bulan (YYYY-MM)</label>
                    <input type="month" id="repPeriodMonth" required>
                </div>
            </div>
            <div class="form-row">
                <label>Judul Laporan</label>
                <input type="text" id="repTitle" placeholder="Contoh: Laporan Kinerja dan Pencapaian Bulan September 2026" required>
            </div>
            <div class="form-row">
                <label>Ringkasan Pekerjaan & Aktivitas</label>
                <textarea id="repSummary" rows="3" style="width: 100%; background: var(--card-bg); border: 1px solid var(--border-color); color: #fff; padding: 0.65rem; border-radius: 0.6rem;" placeholder="Rangkuman kontribusi dan deliverables..." required></textarea>
            </div>
            <div class="form-row">
                <label>Pencapaian Kunci (Achievements)</label>
                <textarea id="repAchievements" rows="2" style="width: 100%; background: var(--card-bg); border: 1px solid var(--border-color); color: #fff; padding: 0.65rem; border-radius: 0.6rem;" placeholder="Modul yang selesai dibangun, target yang dicapai..."></textarea>
            </div>
            <div class="form-row">
                <label>Kendala & Solusi (Issues & Blockers)</label>
                <textarea id="repBlockers" rows="2" style="width: 100%; background: var(--card-bg); border: 1px solid var(--border-color); color: #fff; padding: 0.65rem; border-radius: 0.6rem;" placeholder="Tantangan teknis atau operasional yang dihadapi..."></textarea>
            </div>
            <div style="display: flex; justify-content: flex-end; gap: 0.75rem; margin-top: 1.25rem;">
                <button type="button" class="btn-secondary" onclick="closeModal('modalSubmitInternReport')">Batal</button>
                <button type="submit" class="btn-primary">Kirimkan Laporan</button>
            </div>
        </form>
    </div>
</div>

<!-- INTERNSHIP MODAL 6: REVIEW LAPORAN MAGANG -->
<div class="modal-overlay" id="modalReviewInternReport">
    <div class="modal-card">
        <div class="modal-header">
            <h3 class="modal-title">🔍 Evaluasi & Persetujuan Laporan Magang</h3>
            <button class="modal-close-btn" onclick="closeModal('modalReviewInternReport')">✖</button>
        </div>
        <form id="formReviewInternReport" onsubmit="submitReviewInternReport(event)">
            <input type="hidden" id="revReportId">
            <div class="form-row">
                <label>Status Keputusan</label>
                <select id="revReportStatus" required>
                    <option value="APPROVED">APPROVED (Laporan Disetujui)</option>
                    <option value="REVISION_REQUIRED">REVISION_REQUIRED (Perlu Perbaikan / Revisi)</option>
                </select>
            </div>
            <div class="form-row">
                <label>Umpan Balik & Catatan Evaluasi Mentor / HR</label>
                <textarea id="revReportNotes" rows="3" style="width: 100%; background: var(--card-bg); border: 1px solid var(--border-color); color: #fff; padding: 0.65rem; border-radius: 0.6rem;" placeholder="Catatan dan rekomendasi atas laporan..." required></textarea>
            </div>
            <div style="display: flex; justify-content: flex-end; gap: 0.75rem; margin-top: 1.25rem;">
                <button type="button" class="btn-secondary" onclick="closeModal('modalReviewInternReport')">Batal</button>
                <button type="submit" class="btn-primary">Simpan Keputusan</button>
            </div>
        </form>
    </div>
</div>

<!-- INTERNSHIP MODAL 7: LEMBAR PENILAIAN & EVALUASI -->
<div class="modal-overlay" id="modalSubmitInternEvaluation">
    <div class="modal-card">
        <div class="modal-header">
            <h3 class="modal-title">⭐ Lembar Evaluasi & Penilaian Kinerja Magang</h3>
            <button class="modal-close-btn" onclick="closeModal('modalSubmitInternEvaluation')">✖</button>
        </div>
        <form id="formSubmitInternEvaluation" onsubmit="saveInternEvaluation(event)">
            <input type="hidden" id="evalInternshipId">
            <div class="form-row">
                <label>Pemagang</label>
                <div id="evalInternName" style="font-weight: 700; color: var(--primary); margin-bottom: 0.5rem;"></div>
            </div>
            <div class="form-row">
                <label>Jenis Evaluasi</label>
                <select id="evalType" required>
                    <option value="FINAL" selected>Evaluasi Akhir Magang (Final Evaluation)</option>
                    <option value="MID_TERM">Evaluasi Tengah Periode (Mid-term Evaluation)</option>
                </select>
            </div>
            <div style="display: grid; grid-template-columns: 1fr 1fr 1fr 1fr; gap: 0.75rem;">
                <div class="form-row">
                    <label>Kedisiplinan (1-100)</label>
                    <input type="number" id="evalDiscipline" value="85" min="1" max="100" required>
                </div>
                <div class="form-row">
                    <label>Komunikasi (1-100)</label>
                    <input type="number" id="evalCommunication" value="85" min="1" max="100" required>
                </div>
                <div class="form-row">
                    <label>Teknis/Skill (1-100)</label>
                    <input type="number" id="evalTechnical" value="90" min="1" max="100" required>
                </div>
                <div class="form-row">
                    <label>Inisiatif (1-100)</label>
                    <input type="number" id="evalInitiative" value="85" min="1" max="100" required>
                </div>
            </div>
            <div style="display: grid; grid-template-columns: 1fr 1fr 1fr 1fr; gap: 0.75rem;">
                <div class="form-row">
                    <label>Kerjasama Tim (1-100)</label>
                    <input type="number" id="evalTeamwork" value="85" min="1" max="100" required>
                </div>
                <div class="form-row">
                    <label>Kehadiran (1-100)</label>
                    <input type="number" id="evalAttendance" value="90" min="1" max="100" required>
                </div>
                <div class="form-row">
                    <label>Ketepatan Tugas (1-100)</label>
                    <input type="number" id="evalTaskCompletion" value="90" min="1" max="100" required>
                </div>
                <div class="form-row">
                    <label>Profesionalisme (1-100)</label>
                    <input type="number" id="evalProfessionalism" value="88" min="1" max="100" required>
                </div>
            </div>
            <div class="form-row">
                <label>Kekuatan & Keunggulan (Strengths)</label>
                <textarea id="evalStrengths" rows="2" style="width: 100%; background: var(--card-bg); border: 1px solid var(--border-color); color: #fff; padding: 0.65rem; border-radius: 0.6rem;" placeholder="Kemampuan teknis menonjol, etos kerja..."></textarea>
            </div>
            <div class="form-row">
                <label>Area Peningkatan (Areas of Improvement)</label>
                <textarea id="evalImprovements" rows="2" style="width: 100%; background: var(--card-bg); border: 1px solid var(--border-color); color: #fff; padding: 0.65rem; border-radius: 0.6rem;" placeholder="Saran pengembangan diri dan profesional..."></textarea>
            </div>
            <div class="form-row">
                <label>Rekomendasi Akhir Mentor / Perusahaan</label>
                <select id="evalRecommendation" required>
                    <option value="HIRE_AS_EMPLOYEE">Direkomendasikan Diangkat Sebagai Karyawan Resmi (Hire)</option>
                    <option value="COMPLETE" selected>Menyelesaikan Program Magang dengan Baik (Complete)</option>
                    <option value="EXTEND_INTERNSHIP">Perpanjang Masa Magang (Extend)</option>
                    <option value="NOT_RECOMMENDED">Tidak Direkomendasikan (Not Recommended)</option>
                </select>
            </div>
            <div style="display: flex; justify-content: flex-end; gap: 0.75rem; margin-top: 1.25rem;">
                <button type="button" class="btn-secondary" onclick="closeModal('modalSubmitInternEvaluation')">Batal</button>
                <button type="submit" class="btn-primary">Simpan Lembar Evaluasi</button>
            </div>
        </form>
    </div>
</div>

<!-- INTERNSHIP MODAL 8: SELESAIKAN PROGRAM MAGANG -->
<div class="modal-overlay" id="modalCompleteInternship">
    <div class="modal-card">
        <div class="modal-header">
            <h3 class="modal-title">🎉 Penyelesaian & Kelulusan Program Magang</h3>
            <button class="modal-close-btn" onclick="closeModal('modalCompleteInternship')">✖</button>
        </div>
        <form id="formCompleteInternship" onsubmit="submitCompleteInternship(event)">
            <input type="hidden" id="compInternshipId">
            <div class="form-row">
                <label>Pemagang</label>
                <div id="compInternName" style="font-weight: 700; color: #10b981; font-size: 1.05rem; margin-bottom: 0.5rem;"></div>
            </div>
            <div class="form-row">
                <label>Nomor Sertifikat Kelulusan (Otomatis jika kosong)</label>
                <input type="text" id="compCertificateNo" placeholder="CERT-INT-2026-XXXX">
            </div>
            <div class="form-row">
                <label>Catatan Kelulusan / Penghargaan</label>
                <textarea id="compNotes" rows="2" style="width: 100%; background: var(--card-bg); border: 1px solid var(--border-color); color: #fff; padding: 0.65rem; border-radius: 0.6rem;" placeholder="Telah menyelesaikan seluruh program magang dengan predikat sangat memuaskan..."></textarea>
            </div>
            <div style="display: flex; justify-content: flex-end; gap: 0.75rem; margin-top: 1.25rem;">
                <button type="button" class="btn-secondary" onclick="closeModal('modalCompleteInternship')">Batal</button>
                <button type="submit" class="btn-primary" style="background: #10b981; border-color: #059669;">
                    ✓ Konfirmasi Kelulusan Magang
                </button>
            </div>
        </form>
    </div>
</div>

<!-- ONBOARDING MODAL 1: BUAT KASUS ONBOARDING -->
<div class="modal-overlay" id="modalAddOnboardingCase">
    <div class="modal-card">
        <div class="modal-header">
            <h3 class="modal-title">📋 Buat Kasus Onboarding Baru</h3>
            <button class="modal-close-btn" onclick="closeModal('modalAddOnboardingCase')">✖</button>
        </div>
        <form id="formAddOnboardingCase" onsubmit="saveOnboardingCase(event)">
            <div class="form-row">
                <label>Pilih Karyawan yang Akan Di-onboard</label>
                <select id="onbEmployeeSelect" required>
                    <option value="">Memuat daftar karyawan...</option>
                </select>
            </div>
            <div style="display: grid; grid-template-columns: 1fr 1fr; gap: 0.75rem;">
                <div class="form-row">
                    <label>Tipe Karyawan</label>
                    <select id="onbEmploymentType" required>
                        <option value="PERMANENT">Karyawan Tetap (PKWTT)</option>
                        <option value="FIXED_TERM" selected>Kontrak (PKWT)</option>
                        <option value="PROBATION">Masa Percobaan (Probation)</option>
                        <option value="INTERNSHIP">Program Magang (Internship)</option>
                    </select>
                </div>
                <div class="form-row">
                    <label>Lokasi Kerja</label>
                    <input type="text" id="onbWorkLocation" value="Kantor Pusat PKP" required>
                </div>
            </div>
            <div style="display: grid; grid-template-columns: 1fr 1fr; gap: 0.75rem;">
                <div class="form-row">
                    <label>Tanggal Mulai Kerja (Start Date)</label>
                    <input type="date" id="onbStartDate" required>
                </div>
                <div class="form-row">
                    <label>Target Penyelesaian Onboarding</label>
                    <input type="date" id="onbTargetDate" required>
                </div>
            </div>
            <div class="form-row">
                <label>Catatan / Instruksi Khusus Onboarding</label>
                <textarea id="onbNotes" rows="2" style="width: 100%; background: var(--card-bg); border: 1px solid var(--border-color); color: #fff; padding: 0.65rem; border-radius: 0.6rem;" placeholder="Prioritas pengurusan kartu akses lantai 3, laptop spec engineer..."></textarea>
            </div>
            <div style="display: flex; justify-content: flex-end; gap: 0.75rem; margin-top: 1.25rem;">
                <button type="button" class="btn-secondary" onclick="closeModal('modalAddOnboardingCase')">Batal</button>
                <button type="submit" class="btn-primary">Buat Kasus & Inisialisasi Checklist</button>
            </div>
        </form>
    </div>
</div>

<!-- ONBOARDING MODAL 2: DETAIL CHECKLIST ONBOARDING -->
<div class="modal-overlay" id="modalViewOnboardingCase">
    <div class="modal-card" style="max-width: 800px; max-height: 90vh; overflow-y: auto;">
        <div class="modal-header">
            <div>
                <h3 class="modal-title" id="viewOnbCaseTitle">📋 Kasus Onboarding</h3>
                <div style="font-size: 0.85rem; color: var(--primary);" id="viewOnbCaseSubtitle">Detail checklist tugas & kepatuhan</div>
            </div>
            <button class="modal-close-btn" onclick="closeModal('modalViewOnboardingCase')">✖</button>
        </div>
        <div style="margin-bottom: 1.25rem; background: var(--card-bg); border: 1px solid var(--border-color); border-radius: 0.75rem; padding: 1rem;">
            <div style="display: flex; justify-content: space-between; align-items: center; margin-bottom: 0.5rem;">
                <span style="font-weight: 600; font-size: 0.9rem;">Progres Kesiapan Onboarding</span>
                <span id="viewOnbProgressText" style="font-weight: 700; color: #38bdf8;">0%</span>
            </div>
            <div style="background: rgba(255,255,255,0.1); border-radius: 999px; height: 10px; overflow: hidden;">
                <div id="viewOnbProgressBar" style="background: linear-gradient(90deg, #38bdf8, #10b981); height: 100%; width: 0%; transition: width 0.3s ease;"></div>
            </div>
        </div>
        <div id="viewOnbTasksList" style="display: flex; flex-direction: column; gap: 0.75rem; margin-bottom: 1.5rem;">
            <!-- Rendered by JS -->
        </div>
        <div style="display: flex; justify-content: space-between; align-items: center;">
            <button type="button" class="btn-secondary" onclick="closeModal('modalViewOnboardingCase')">Tutup</button>
            <button type="button" class="btn-primary" id="btnCompleteCaseAction" onclick="submitCompleteCaseDirect()" style="background: #10b981; border-color: #059669;">
                ✓ Selesaikan Kasus Onboarding
            </button>
        </div>
    </div>
</div>

<!-- ONBOARDING MODAL 3: UPDATE TUGAS CHECKLIST -->
<div class="modal-overlay" id="modalUpdateOnboardingTask">
    <div class="modal-card">
        <div class="modal-header">
            <h3 class="modal-title">✏️ Perbarui Status Tugas Checklist</h3>
            <button class="modal-close-btn" onclick="closeModal('modalUpdateOnboardingTask')">✖</button>
        </div>
        <form id="formUpdateOnboardingTask" onsubmit="submitTaskUpdate(event)">
            <input type="hidden" id="taskUpdateId">
            <div class="form-row">
                <label>Nama Tugas</label>
                <div id="taskUpdateTitle" style="font-weight: 600; color: #fff; margin-bottom: 0.5rem;"></div>
            </div>
            <div class="form-row">
                <label>Status Tugas</label>
                <select id="taskUpdateStatus" onchange="toggleBlockerReasonField(this.value)" required>
                    <option value="PENDING">PENDING - Belum Dimulai</option>
                    <option value="IN_PROGRESS">IN_PROGRESS - Sedang Dikerjakan</option>
                    <option value="COMPLETED">COMPLETED - Selesai & Terverifikasi</option>
                    <option value="BLOCKED">BLOCKED - Terkendala / Terblokir</option>
                    <option value="NOT_REQUIRED">NOT_REQUIRED - Tidak Diperlukan</option>
                </select>
            </div>
            <div class="form-row" id="taskBlockerReasonContainer" style="display: none;">
                <label style="color: #f87171;">Alasan Kendala (Blocker Reason)</label>
                <input type="text" id="taskBlockerReason" placeholder="Contoh: Menunggu KTP / tanda tangan pimpinan">
            </div>
            <div class="form-row">
                <label>Catatan Tindak Lanjut</label>
                <textarea id="taskUpdateNotes" rows="2" style="width: 100%; background: var(--card-bg); border: 1px solid var(--border-color); color: #fff; padding: 0.65rem; border-radius: 0.6rem;" placeholder="Catatan progress..."></textarea>
            </div>
            <div style="display: flex; justify-content: flex-end; gap: 0.75rem; margin-top: 1.25rem;">
                <button type="button" class="btn-secondary" onclick="closeModal('modalUpdateOnboardingTask')">Batal</button>
                <button type="submit" class="btn-primary">Simpan Perubahan</button>
            </div>
        </form>
    </div>
</div>

<!-- ONBOARDING MODAL 4: BUAT KONTRAK KERJA BARU -->
<div class="modal-overlay" id="modalAddContract">
    <div class="modal-card">
        <div class="modal-header">
            <h3 class="modal-title">📜 Buat Kontrak Kerja Baru</h3>
            <button class="modal-close-btn" onclick="closeModal('modalAddContract')">✖</button>
        </div>
        <form id="formAddContract" onsubmit="saveContract(event)">
            <div class="form-row">
                <label>Karyawan Terkait</label>
                <select id="contractEmployeeSelect" required>
                    <option value="">Memuat daftar karyawan...</option>
                </select>
            </div>
            <div style="display: grid; grid-template-columns: 1fr 1fr; gap: 0.75rem;">
                <div class="form-row">
                    <label>Jenis Kontrak</label>
                    <select id="contractTypeSelect" required>
                        <option value="FIXED_TERM" selected>PKWT (Waktu Tertentu)</option>
                        <option value="PERMANENT">PKWTT (Karyawan Tetap)</option>
                        <option value="PROBATION">Masa Percobaan (Probation)</option>
                        <option value="INTERNSHIP">Perjanjian Magang</option>
                        <option value="NDA">Non-Disclosure Agreement (NDA)</option>
                    </select>
                </div>
                <div class="form-row">
                    <label>Nomor Kontrak (Otomatis jika kosong)</label>
                    <input type="text" id="contractNumber" placeholder="CTR-2026-XXXX">
                </div>
            </div>
            <div class="form-row">
                <label>Judul Kontrak</label>
                <input type="text" id="contractTitle" placeholder="Contoh: Perjanjian Kerja Waktu Tertentu (PKWT) Software Engineer" required>
            </div>
            <div style="display: grid; grid-template-columns: 1fr 1fr; gap: 0.75rem;">
                <div class="form-row">
                    <label>Tanggal Mulai (Start Date)</label>
                    <input type="date" id="contractStartDate" required>
                </div>
                <div class="form-row">
                    <label>Tanggal Berakhir (End Date)</label>
                    <input type="date" id="contractEndDate">
                </div>
            </div>
            <div style="display: grid; grid-template-columns: 1fr 1fr; gap: 0.75rem;">
                <div class="form-row">
                    <label>Penandatangan Karyawan</label>
                    <input type="text" id="contractSigneeEmployee" placeholder="Nama Karyawan">
                </div>
                <div class="form-row">
                    <label>Penandatangan Perusahaan</label>
                    <input type="text" id="contractSigneeCompany" value="Direktur HR & Operasional PKP">
                </div>
            </div>
            <div class="form-row">
                <label>Status Awal</label>
                <select id="contractStatusSelect">
                    <option value="DRAFT">DRAFT</option>
                    <option value="PENDING_SIGNATURE">PENDING_SIGNATURE</option>
                    <option value="ACTIVE" selected>ACTIVE - Berlaku</option>
                </select>
            </div>
            <div style="display: flex; justify-content: flex-end; gap: 0.75rem; margin-top: 1.25rem;">
                <button type="button" class="btn-secondary" onclick="closeModal('modalAddContract')">Batal</button>
                <button type="submit" class="btn-primary">Terbitkan Kontrak</button>
            </div>
        </form>
    </div>
</div>

<!-- ONBOARDING MODAL 5: UNGGAH DOKUMEN HR PRIVAT -->
<div class="modal-overlay" id="modalUploadDocument">
    <div class="modal-card">
        <div class="modal-header">
            <h3 class="modal-title">📁 Unggah Dokumen Privat & Terenkripsi</h3>
            <button class="modal-close-btn" onclick="closeModal('modalUploadDocument')">✖</button>
        </div>
        <form id="formUploadDocument" onsubmit="submitUploadDocument(event)">
            <div class="form-row">
                <label>Pilih Karyawan Pemilik Dokumen</label>
                <select id="docEmployeeSelect" required>
                    <option value="">Memuat daftar karyawan...</option>
                </select>
            </div>
            <div style="display: grid; grid-template-columns: 1fr 1fr; gap: 0.75rem;">
                <div class="form-row">
                    <label>Kategori Dokumen</label>
                    <select id="docCategorySelect" required>
                        <option value="IDENTITY">Identitas (KTP, KK, NPWP)</option>
                        <option value="CONTRACT" selected>Kontrak Kerja Resmi</option>
                        <option value="NDA">NDA & Pakta Integritas</option>
                        <option value="EDUCATION">Ijazah / Transkrip</option>
                        <option value="CERTIFICATION">Sertifikasi Keahlian</option>
                        <option value="ASSIGNMENT">Surat Perintah Kerja (SPK)</option>
                        <option value="MEDICAL">Hasil Tes Kesehatan (MCU)</option>
                        <option value="INTERNSHIP">Dokumen Magang</option>
                        <option value="OTHER">Lainnya</option>
                    </select>
                </div>
                <div class="form-row">
                    <label>Tingkat Visibilitas</label>
                    <select id="docVisibilitySelect" required>
                        <option value="CONFIDENTIAL_HR" selected>HRD Only (Kerahasiaan Tinggi)</option>
                        <option value="SUPERVISOR_SHARED">Dibagikan ke Supervisor Tim</option>
                        <option value="EMPLOYEE_VISIBLE">Dapat Dilihat Karyawan Sendiri</option>
                    </select>
                </div>
            </div>
            <div class="form-row">
                <label>Judul / Keterangan Dokumen</label>
                <input type="text" id="docTitle" placeholder="Contoh: KTP Asli Terverifikasi" required>
            </div>
            <div class="form-row">
                <label>Berkas Fisik (PDF, JPG, PNG, DOC, DOCX - Maks 10MB)</label>
                <input type="file" id="docFileInput" accept=".pdf,.jpg,.jpeg,.png,.doc,.docx" required style="padding: 0.5rem; background: var(--card-bg); border: 1px dashed var(--border-color); border-radius: 0.6rem; width: 100%;">
            </div>
            <div style="display: flex; justify-content: flex-end; gap: 0.75rem; margin-top: 1.25rem;">
                <button type="button" class="btn-secondary" onclick="closeModal('modalUploadDocument')">Batal</button>
                <button type="submit" class="btn-primary">Unggah ke Private Storage</button>
            </div>
        </form>
    </div>
</div>

<!-- ONBOARDING MODAL 6: VERIFIKASI DOKUMEN HR -->
<div class="modal-overlay" id="modalVerifyDocument">
    <div class="modal-card">
        <div class="modal-header">
            <h3 class="modal-title">🛡️ Verifikasi Dokumen Karyawan</h3>
            <button class="modal-close-btn" onclick="closeModal('modalVerifyDocument')">✖</button>
        </div>
        <form id="formVerifyDocument" onsubmit="submitVerifyDocument(event)">
            <input type="hidden" id="verifyDocId">
            <div class="form-row">
                <label>Dokumen</label>
                <div id="verifyDocTitle" style="font-weight: 600; color: #38bdf8; margin-bottom: 0.5rem;"></div>
            </div>
            <div class="form-row">
                <label>Keputusan Verifikasi</label>
                <select id="verifyDocDecision" required>
                    <option value="VERIFIED">✓ SETUJUI (VERIFIED) - Dokumen Sah & Valid</option>
                    <option value="REJECTED">✕ TOLAK (REJECTED) - Berkas Tidak Memenuhi Syarat</option>
                </select>
            </div>
            <div class="form-row">
                <label>Catatan Verifikasi</label>
                <textarea id="verifyDocNotes" rows="2" style="width: 100%; background: var(--card-bg); border: 1px solid var(--border-color); color: #fff; padding: 0.65rem; border-radius: 0.6rem;" placeholder="Catatan keabsahan dokumen..."></textarea>
            </div>
            <div style="display: flex; justify-content: flex-end; gap: 0.75rem; margin-top: 1.25rem;">
                <button type="button" class="btn-secondary" onclick="closeModal('modalVerifyDocument')">Batal</button>
                <button type="submit" class="btn-primary">Simpan Keputusan</button>
            </div>
        </form>
    </div>
</div>
<!-- Configuration & Global Variables -->
<script>
    window.APP_CONFIG = {
        apiToken: @json($apiToken ?? session('api_token')),
        deviceSecret: @json(config('services.hikvision.device_secret') ?? ''),
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

<script>
/**
 * Remote Unlock Door Function
 * Sends POST request to /api/v1/doors/${doorId}/unlock with CSRF-Token
 */
async function remoteUnlockDoor(doorId, btn) {
    if (!confirm(`Konfirmasi: Apakah Anda yakin ingin membuka relay pintu ${doorId} secara remote?`)) {
        return;
    }

    const originalText = btn ? btn.innerHTML : '';
    if (btn) {
        btn.disabled = true;
        btn.innerHTML = '⏳ Membuka...';
    }

    const csrfToken = document.querySelector('meta[name="csrf-token"]')?.getAttribute('content') 
        || '{{ csrf_token() }}';
    const appToken = window.APP_CONFIG?.apiToken 
        || sessionStorage.getItem('api_token') 
        || localStorage.getItem('api_token') 
        || '';

    const headers = {
        'Content-Type': 'application/json',
        'Accept': 'application/json',
        'X-CSRF-TOKEN': csrfToken,
        'X-Requested-With': 'XMLHttpRequest'
    };

    if (appToken) {
        headers['Authorization'] = `Bearer ${appToken}`;
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
            if (typeof loadDoors === 'function') {
                loadDoors();
            }
            if (typeof loadActivityLogs === 'function') {
                loadActivityLogs();
            }
        } else {
            alert(`✕ Gagal: ${data.message || 'Relay pintu gagal dibuka oleh hardware.'}`);
        }
    } catch (error) {
        alert(`✕ Terjadi kesalahan koneksi: ${error.message}`);
    } finally {
        if (btn) {
            btn.disabled = false;
            btn.innerHTML = originalText;
        }
    }
}
window.remoteUnlockDoor = remoteUnlockDoor;
</script>

</body>
</html>
