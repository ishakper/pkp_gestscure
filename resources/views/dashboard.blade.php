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

        /* ------------------------------------------
           Floating Logo Architecture
           ------------------------------------------ */
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
            border: 1px solid var(--border-color);
            /* Bug fix: this fixed logo has no opaque backdrop, so scrolled page content
               (which is otherwise normal document flow) shows through/behind it and looks
               like it "tertimpa" the logo. Give it a solid background matching the sidebar
               so it reads as a docked header chip instead of a transparent overlay. */
            background: var(--sidebar-bg);
            box-shadow: 0 6px 18px rgba(0, 0, 0, 0.35);
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

        .floating-logo:focus-visible {
            outline: 2px solid #38bdf8;
            outline-offset: 4px;
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
            display: flex;
            flex-direction: column;
            gap: 0.35rem;
            padding: 0 0.75rem;
            list-style: none;
            overflow: visible;
        }

        .nav-section-label {
            margin: 1rem 0.65rem 0.2rem;
            color: #64748b;
            font-size: 0.66rem;
            font-weight: 800;
            letter-spacing: 0.12em;
            line-height: 1;
        }

        .nav-section-label:first-child { margin-top: 0.25rem; }
        html:not(.sidebar-open) .nav-section-label { display: none; }

        .door-card button:disabled {
            cursor: not-allowed;
            filter: grayscale(0.8);
            opacity: 0.48;
        }

        .maintenance-note {
            color: #f59e0b;
            font-size: 0.72rem;
            font-weight: 700;
        }

        .audit-description {
            max-width: 520px;
            white-space: normal;
            line-height: 1.45;
        }

        .physical-warning {
            background: rgba(245, 158, 11, 0.1);
            border: 1px solid rgba(245, 158, 11, 0.35);
            border-radius: 0.75rem;
            color: #fbbf24;
            font-size: 0.82rem;
            line-height: 1.5;
            margin: 1rem 0;
            padding: 0.9rem;
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

        .advanced-nav { display: none !important; }

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

        .status-warning, .status-maintenance {
            background: rgba(245, 158, 11, 0.15);
            color: #fcd34d;
            border: 1px solid rgba(245, 158, 11, 0.3);
            box-shadow: 0 0 10px rgba(245, 158, 11, 0.15);
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

        .btn-secondary:disabled,
        .btn-secondary[disabled] {
            opacity: 0.35;
            cursor: not-allowed;
            pointer-events: none;
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
        .ats-nav-pill,
        .ats-subnav .subnav-btn {
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
        .ats-nav-pill:hover,
        .ats-subnav .subnav-btn:hover {
            background: rgba(255, 255, 255, 0.05);
            color: #ffffff;
        }
        .ats-nav-pill.active,
        .ats-subnav .subnav-btn.active {
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
        .stats-grid { display: grid; grid-template-columns: repeat(auto-fit, minmax(190px, 1fr)); gap: 1rem; }
        .stat-card { min-width: 0; padding: 1.25rem; border: 1px solid var(--border-color); border-radius: 0.875rem; background: var(--card-bg); display: flex; flex-direction: column; gap: 0.4rem; }
        .stat-title, .stat-desc { color: var(--text-muted); font-size: 0.8rem; overflow-wrap: anywhere; }
        .stat-value { color: var(--text-main); font-size: 1.35rem; overflow-wrap: anywhere; }

        .table-container:has(> .employee-pagination) {
            min-height: 680px;
            display: flex;
            flex-direction: column;
        }

        .table-container:has(> .employee-pagination) > table {
            flex: 1 0 auto;
            transition: opacity 180ms ease;
        }

        .table-container.employee-table-loading > table {
            opacity: 0.55;
        }

        .employee-pagination {
            position: sticky;
            bottom: 0;
            z-index: 3;
            margin-top: auto;
            border-top: 1px solid var(--border-color);
            background: rgba(15, 23, 42, 0.98);
        }

        .badge-fingerprint-expected {
            background: rgba(139, 92, 246, 0.18);
            color: #c4b5fd;
            border: 1px solid rgba(139, 92, 246, 0.35);
        }

        /* === PAGINATION RESPONSIVE & ACCESSIBILITY === */
        .pagination-container {
            display: block;
            padding: 1rem;
        }

        .pagination-wrapper {
            display: flex;
            justify-content: center;
            align-items: center;
            gap: 0.75rem;
            flex-wrap: wrap;
            max-width: 100%;
        }

        .pagination-btn {
            min-width: 44px;
            min-height: 44px;
            padding: 0.5rem 0.75rem;
            white-space: nowrap;
            transition: all 0.2s ease;
        }

        .pagination-btn:disabled {
            pointer-events: none;
            opacity: 0.5;
            cursor: not-allowed;
        }

        .pagination-btn:focus-visible {
            outline: 2px solid #38bdf8;
            outline-offset: 2px;
        }

        .pagination-status {
            font-size: 0.82rem;
            color: var(--text-muted);
            padding: 0.5rem 0.25rem;
            min-width: 120px;
            text-align: center;
        }

        /* Responsive Breakpoints (< 768px Mobile & Tablet) */
        @media (max-width: 768px) {
            .table-container { overflow-x: auto; -webkit-overflow-scrolling: touch; }
            .stats-grid { grid-template-columns: 1fr; }
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
                padding-bottom: calc(1.25rem + env(safe-area-inset-bottom)) !important;
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

            .pagination-wrapper {
                flex-direction: column;
                gap: 0.5rem;
            }

            .pagination-status {
                order: -1;
                width: 100%;
                margin-bottom: 0.5rem;
            }

            .pagination-btn {
                flex: 1;
                min-width: 120px;
            }
        }

        /* Extra small screens (< 400px) */
        @media (max-width: 400px) {
            .pagination-wrapper {
                gap: 0.5rem;
            }

            .pagination-btn {
                font-size: 0.85rem;
                padding: 0.5rem 0.5rem;
                min-width: 100px;
            }

            .pagination-status {
                font-size: 0.75rem;
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
        <!-- GROUP 1: OPERASIONAL -->
        <li class="nav-section-label" aria-hidden="true">OPERASIONAL</li>
        <li class="nav-item"><button class="active" data-tooltip="Dashboard" onclick="switchTab('overviewTab', this)"><span class="nav-icon">📊</span><span class="nav-text">Dashboard</span></button></li>
        @if(in_array('employee.view', $permissions ?? []))
        <li class="nav-item"><button data-tooltip="Pengguna" onclick="switchTab('employeesTab', this)"><span class="nav-icon">👥</span><span class="nav-text">Pengguna</span></button></li>
        @endif
        @if(in_array('device.view', $permissions ?? []))
        <li class="nav-item"><button data-tooltip="Perangkat Pintu" onclick="switchTab('doorsTab', this)"><span class="nav-icon">🌐</span><span class="nav-text">Perangkat Pintu</span></button></li>
        @endif
        @if(in_array('access.view', $permissions ?? []) || in_array('access.request', $permissions ?? []) || in_array('credential.view', $permissions ?? []))
        <li class="nav-item"><button data-tooltip="Hak Akses" onclick="switchTab('accessTab', this)"><span class="nav-icon">🔑</span><span class="nav-text">Hak Akses</span></button></li>
        @endif
        @if(in_array('attendance.view', $permissions ?? []) || in_array('attendance.self', $permissions ?? []))
        <li class="nav-item"><button data-tooltip="Rekap Kehadiran" onclick="switchTab('attendanceTab', this)"><span class="nav-icon">⏰</span><span class="nav-text">Rekap Kehadiran</span></button></li>
        @endif
        @if(in_array('security.view', $permissions ?? []))
        <li class="nav-item"><button data-tooltip="Log Akses" onclick="switchTab('logsTab', this)"><span class="nav-icon">👆</span><span class="nav-text">Log Akses</span></button></li>
        @endif
        @if(($admin?->role ?? null) === 'super_admin' && in_array('audit.view', $permissions ?? []))
        <li class="nav-item"><button data-tooltip="Audit Log" onclick="switchTab('auditLogTab', this)"><span class="nav-icon">🛡️</span><span class="nav-text">Audit Log</span></button></li>
        @endif

        <!-- GROUP 2: KONFIGURASI -->
        <li class="nav-section-label" aria-hidden="true">KONFIGURASI</li>
        @if(in_array('organization.view', $permissions ?? []))
        <li class="nav-item"><button data-tooltip="Setup Gedung" onclick="switchTab('buildingSetupTab', this)"><span class="nav-icon">🏢</span><span class="nav-text">Setup Gedung</span></button></li>
        @endif
        @if(in_array('system.manage', $permissions ?? []) || in_array('system.view', $permissions ?? []))
        <li class="nav-item"><button data-tooltip="Akun Sistem" onclick="switchTab('systemAccountsTab', this)"><span class="nav-icon">👤</span><span class="nav-text">Akun Sistem</span></button></li>
        @endif
        @if(in_array('system.view', $permissions ?? []) || in_array('device.view', $permissions ?? []))
        <li class="nav-item"><button data-tooltip="Status Sistem" onclick="switchTab('systemStatusTab', this)"><span class="nav-icon">📡</span><span class="nav-text">Status Sistem</span></button></li>
        @endif

        <!-- ADVANCED / FUTURE MODULES (PRESERVED) -->
        @if(in_array('recruitment.view', $permissions ?? []) || in_array('internship.view', $permissions ?? []) || in_array('onboarding.view', $permissions ?? []) || in_array('asset.view', $permissions ?? []) || in_array('asset.manage', $permissions ?? []) || in_array('asset.self', $permissions ?? []))
        <li class="nav-section-label advanced-nav" aria-hidden="true">MODUL TAMBAHAN</li>
        @endif
        @if(in_array('recruitment.view', $permissions ?? []))
        <li class="nav-item advanced-nav"><button data-tooltip="Recruitment" onclick="switchTab('recruitmentTab', this)"><span class="nav-icon">🎯</span><span class="nav-text">Recruitment</span></button></li>
        @endif
        @if(in_array('internship.view', $permissions ?? []))
        <li class="nav-item advanced-nav"><button data-tooltip="Internship" onclick="switchTab('internshipTab', this)"><span class="nav-icon">🎓</span><span class="nav-text">Internship</span></button></li>
        @endif
        @if(in_array('onboarding.view', $permissions ?? []))
        <li class="nav-item advanced-nav"><button data-tooltip="Onboarding" onclick="switchTab('onboardingTab', this)"><span class="nav-icon">📑</span><span class="nav-text">Onboarding</span></button></li>
        @endif
        @if(in_array('asset.view', $permissions ?? []) || in_array('asset.manage', $permissions ?? []) || in_array('asset.self', $permissions ?? []))
        <li class="nav-item advanced-nav"><button data-tooltip="Assets" onclick="switchTab('assetsTab', this)"><span class="nav-icon">💻</span><span class="nav-text">Assets</span></button></li>
        @endif
        <li class="nav-item advanced-nav"><button data-tooltip="Tasks & Worklogs" onclick="switchTab('tasksTab', this)"><span class="nav-icon">✅</span><span class="nav-text">Tasks &amp; Worklogs</span></button></li>
        @if(in_array('field_attendance.view', $permissions ?? []) || in_array('field_attendance.self', $permissions ?? []))
        <li class="nav-item advanced-nav"><button data-tooltip="Presensi Lapangan" onclick="switchTab('fieldAttendanceTab', this)"><span class="nav-icon">📍</span><span class="nav-text">Presensi Lapangan</span></button></li>
        @endif
        @if(in_array('attendance_request.view', $permissions ?? []) || in_array('attendance_request.self', $permissions ?? []) || in_array('attendance.view', $permissions ?? []) || in_array('attendance.self', $permissions ?? []))
        <li class="nav-item advanced-nav"><button data-tooltip="Pengajuan Absensi" onclick="switchTab('attendanceRequestsTab', this)"><span class="nav-icon">📝</span><span class="nav-text">Pengajuan Absensi</span></button></li>
        @endif
        @if(in_array('attendance_correction.view', $permissions ?? []) || in_array('attendance_correction.self', $permissions ?? []) || in_array('attendance.view', $permissions ?? []) || in_array('attendance.self', $permissions ?? []))
        <li class="nav-item advanced-nav"><button data-tooltip="Koreksi Presensi" onclick="switchTab('attendanceCorrectionsTab', this)"><span class="nav-icon">✏️</span><span class="nav-text">Koreksi Presensi</span></button></li>
        @endif
        @if(in_array('overtime.view', $permissions ?? []) || in_array('overtime.self', $permissions ?? []) || in_array('attendance.view', $permissions ?? []) || in_array('attendance.self', $permissions ?? []))
        <li class="nav-item advanced-nav"><button data-tooltip="Pengajuan Lembur" onclick="switchTab('overtimeRequestsTab', this)"><span class="nav-icon">⚡</span><span class="nav-text">Pengajuan Lembur</span></button></li>
        @endif
        @if (app()->environment('local', 'testing'))
        <li class="nav-item advanced-nav"><button data-tooltip="Hardware Event Simulator" onclick="switchTab('simulatorTab', this)"><span class="nav-icon">🧪</span><span class="nav-text">Hardware Event Simulator</span></button></li>
        <li class="nav-item advanced-nav"><button data-tooltip="cURL / Postman Specs" onclick="switchTab('apiDocsTab', this)"><span class="nav-icon">📖</span><span class="nav-text">cURL / Postman Specs</span></button></li>
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
            <select id="dashboardBuildingFilter" class="form-control" style="min-width:12rem;" onchange="onDashboardBuildingChange(this.value)" aria-label="Filter dashboard per gedung" title="Tampilkan data Dashboard untuk satu gedung">
                <option value="">🏢 Semua Gedung</option>
            </select>
            <button class="btn-secondary" onclick="refreshOperationalData(this)">
                🔄 Refresh Live Data
            </button>
            <button class="btn-primary" onclick="openAddEmployeeModal()">
                + Tambah Karyawan
            </button>
        </div>
    </div>

    <!-- TOP METRIC CARDS (4 TARGET CARDS) -->
    <div class="metrics-grid">
        <div class="metric-card">
            <div class="metric-icon-box icon-blue">👥</div>
            <div>
                <div class="metric-label">Pengguna Aktif</div>
                <div class="metric-value" id="metricActiveEmployees">-</div>
                <span id="metricTotalUsers" style="display:none;">-</span>
            </div>
        </div>
        <div class="metric-card">
            <div class="metric-icon-box icon-indigo">💳</div>
            <div>
                <div class="metric-label">Terdaftar di Perangkat</div>
                <div class="metric-value" id="metricRegisteredCredentials">-</div>
            </div>
        </div>
        <div class="metric-card">
            <div class="metric-icon-box icon-green">🌐</div>
            <div>
                <div class="metric-label">Perangkat Online</div>
                <div class="metric-value" id="metricActiveDoors">-</div>
            </div>
        </div>
        <div class="metric-card">
            <div class="metric-icon-box icon-red">🚨</div>
            <div>
                <div class="metric-label">Akses Ditolak</div>
                <div class="metric-value" id="metricDeniedLogs">-</div>
            </div>
        </div>
        <!-- Preserved Hidden Elements for Background Script Compatibility -->
        <span id="metricAttendancePresent" style="display:none;">-</span>
        <span id="metricAttendanceLate" style="display:none;">-</span>
        <span id="metricAttendanceAbsent" style="display:none;">-</span>
        <span id="metricAttendanceCheckout" style="display:none;">-</span>
    </div>

    <!-- TAB 1: OVERVIEW (UNIFIED DASHBOARD) -->
    <section id="overviewTab" class="tab-content active">

        <!-- SECTION 1: AKTIVITAS TERAKHIR (REUSE SECURITY ACCESS LOGS) -->
        <div class="section-header">
            <div>
                <h2 class="section-title">📋 Aktivitas Terakhir</h2>
                <p class="section-desc">Riwayat event tap kartu / biometrik real-time dari seluruh terminal pintu.</p>
            </div>
            <div style="display: flex; gap: 0.5rem; align-items: center; flex-wrap: wrap;">
                <button class="btn-primary" onclick="syncHardwareLogs(this)" title="Tarik riwayat tap akses terbaru dari terminal ISAPI">
                    🔄 Sinkronkan Log Pintu
                </button>
                <button class="btn-secondary" onclick="resetLogFilters()">Reset Filter</button>
            </div>
        </div>
        <div class="table-container">
            <div class="table-toolbar">
                <div class="toolbar-left">
                    <div class="search-box">
                        🚪
                        <select id="logDoorFilter" onchange="syncLogFilters(this); loadAccessLogs()">
                            <option value="">Semua Pintu</option>
                        </select>
                    </div>
                    <div class="search-box">
                        ⚡
                        <select id="logStatusFilter" onchange="syncLogFilters(this); loadAccessLogs()">
                            <option value="">Semua Status & Alarm</option>
                            <option value="Granted">Granted (Akses Diterima)</option>
                            <option value="Denied">Denied (Akses Ditolak)</option>
                            <option value="Alarm">🚨 Alarm / Intrusion / Sabotase</option>
                            <option value="Duress">⚠️ Duress Emergency</option>
                        </select>
                    </div>
                    <div class="search-box">
                        📋
                        <select id="logAttendanceStateFilter" onchange="syncLogFilters(this); loadAccessLogs()">
                            <option value="">Semua Result Kehadiran</option>
                            <option value="PRESENT">PRESENT (Hadir)</option>
                            <option value="LATE">LATE (Terlambat)</option>
                            <option value="OFF">OFF (Hari Libur)</option>
                            <option value="LEAVE">LEAVE (Izin/Cuti)</option>
                            <option value="ABSENT">ABSENT (Alpa)</option>
                            <option value="Belum diproses">Belum diproses</option>
                            <option value="Ditolak">Ditolak</option>
                        </select>
                    </div>
                    <div class="search-box">
                        👤 <input type="text" id="logUserSearch" placeholder="Cari NIK / Nama..." oninput="onLogSearchInput(this)">
                    </div>
                    <!-- Tanggal mulai/sampai sengaja tidak ada di sini: sudah tersedia dan berfungsi
                         di tab Log Akses (logStartDateTab/logEndDateTab), jadi tidak diduplikasi
                         di ringkasan Dashboard ini. -->
                </div>
            </div>
            <table>
                <thead>
                    <tr>
                        <th>Waktu</th>
                        <th>NIK</th>
                        <th>Nama</th>
                        <th>Metode</th>
                        <th>Terminal</th>
                        <th>Event</th>
                        <th>Status</th>
                        <th>Attendance Result</th>
                    </tr>
                </thead>
                <tbody id="overviewLogsTableBody">
                    <tr><td colspan="8" class="loading-td"><div class="spinner"></div> Memuat fingerprint logs...</td></tr>
                </tbody>
            </table>
        </div>

        <!-- SECTION 2: STATUS PERANGKAT (REUSE CENTRALIZED ACCESS DOORS) -->
        <div class="section-header" style="margin-top: 2rem;">
            <div>
                <h2 class="section-title">🌐 Status Perangkat</h2>
                <p class="section-desc">Ringkasan kondisi konektivitas real-time seluruh terminal pintu.</p>
            </div>
            <div style="display:flex;gap:.75rem;flex-wrap:wrap;">
                @if(in_array('device.manage', $permissions ?? []))
                <button class="btn-primary" onclick="openFacilityModal()">＋ Tambah Gedung / Pintu</button>
                @endif
                @if(in_array('device.manage', $permissions ?? []))<button class="btn-secondary" onclick="checkAllDoors(this)" title="Pemeriksaan fisik eksplisit ke seluruh terminal">📡 Cek Semua Koneksi Terminal (Diagnose)</button>@endif
                <button class="btn-primary" onclick="refreshOperationalData(this)">↻ Refresh Live Data</button>
            </div>
        </div>
        <div class="doors-grid" id="overviewDoorsGrid"><div class="loading-td"><div class="spinner"></div> Memuat terminal terkonfigurasi...</div></div>

        <!-- SECTION 3: RINGKASAN PENGGUNA -->
        <div class="section-header" style="margin-top: 2rem;">
            <div>
                <h2 class="section-title">👥 Ringkasan Pengguna</h2>
                <p class="section-desc">Hak akses pintu, status biometrik, dan sinkronisasi hardware per pengguna</p>
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
                        <select id="employeeDoorFilter" onchange="loadEmployees(1)">
                            <option value="">Semua Hak Akses Pintu</option>
                        </select>
                    </div>
                </div>
                <div class="toolbar-right">
                    <span style="font-size: 0.85rem; color: var(--text-muted);" id="employeeCountText" aria-live="polite">Menampilkan - pengguna</span>
                </div>
            </div>
            <table>
                <thead>
                    <tr>
                        <th>User ID / NIK</th>
                        <th>Nama Pengguna</th>
                        <th>Departemen</th>
                        <th>Jabatan &amp; Status</th>
                        <th>Status Biometrik</th>
                        <th>Akses Pintu (Sync Status)</th>
                        <th style="text-align: right;">Aksi</th>
                    </tr>
                </thead>
                <tbody id="employeesTableBody">
                    <tr><td colspan="7" class="loading-td"><div class="spinner"></div> Memuat data...</td></tr>
                </tbody>
            </table>
            <div class="employee-pagination" aria-live="polite"></div>
        </div>
    </section>

    <!-- TAB 2: DOORS ONLY -->
    <section id="doorsTab" class="tab-content">
        <div class="section-header">
            <div>
                <h2 class="section-title">🌐 Monitoring Perangkat Pintu</h2>
                <p class="section-desc">Audit hardware konektivitas IP &amp; status real-time terminal pintu Hikvision DS-K1T804AMF</p>
            </div>
            <div style="display:flex;gap:.75rem;flex-wrap:wrap;">
                @if(in_array('device.manage', $permissions ?? []))
                <button class="btn-primary" onclick="openFacilityModal()">＋ Tambah Gedung / Pintu</button>
                @endif
                @if(in_array('device.manage', $permissions ?? []))<button class="btn-secondary" onclick="checkAllDoors(this)" title="Pemeriksaan fisik eksplisit ke seluruh terminal">📡 Cek Semua Koneksi Terminal (Diagnose)</button>@endif
                <button class="btn-primary" onclick="refreshOperationalData(this)">↻ Refresh Live Data</button>
            </div>
        </div>
        <div class="doors-grid" id="doorsGrid"><div class="loading-td"><div class="spinner"></div> Memuat terminal terkonfigurasi...</div></div>
    </section>

    <!-- TAB 3: EMPLOYEES / PENGGUNA -->
    <section id="employeesTab" class="tab-content">
        <!-- Reuses table in Overview or full view -->
        <div class="section-header">
            <div>
                <h2 class="section-title">👥 Manajemen Pengguna</h2>
                <p class="section-desc">Daftar lengkap pengguna terdaftar, status biometrik, dan distribusi izin pintu</p>
                <span style="display: block; margin-top: 0.75rem; font-size: 0.95rem; font-weight: 500;" id="employeeTotalSummary" aria-live="polite">Total Pengguna: <strong>—</strong></span>
            </div>
            <button class="btn-primary" onclick="openAddEmployeeModal()">+ Tambah Pengguna</button>
        </div>
        <div class="table-container">
            <table>
                <thead>
                    <tr>
                        <th>User ID / NIK</th>
                        <th>Nama Pengguna</th>
                        <th>Departemen</th>
                        <th>Jabatan &amp; Status</th>
                        <th>Status Biometrik</th>
                        <th>Akses Pintu (Sync Status)</th>
                        <th style="text-align: right;">Aksi</th>
                    </tr>
                </thead>
                <tbody id="fullEmployeesTableBody">
                    <!-- Synced with employees list -->
                </tbody>
            </table>
            <div class="employee-pagination" aria-live="polite"></div>
        </div>
    </section>

    <!-- TAB 4: TASKS & WORKLOGS -->
    <section id="tasksTab" class="tab-content">
        <div class="section-header">
            <div>
                <h2 class="section-title">✅ Tasks &amp; Worklogs</h2>
                <p class="section-desc">Tugas operasional, kepemilikan karyawan, progres, dan rekam durasi kerja.</p>
            </div>
            <button class="btn-secondary" onclick="loadTasks()">↻ Refresh Tasks</button>
        </div>
        <div class="metrics-grid" id="taskMetricsGrid">
            <div class="metric-card"><div class="metric-icon-box icon-blue">▣</div><div><div class="metric-label">Total Tasks</div><div class="metric-value" id="taskMetricTotal">-</div></div></div>
            <div class="metric-card"><div class="metric-icon-box icon-indigo">◷</div><div><div class="metric-label">In Progress</div><div class="metric-value" id="taskMetricProgress">-</div></div></div>
            <div class="metric-card"><div class="metric-icon-box icon-red">!</div><div><div class="metric-label">Blocked</div><div class="metric-value" id="taskMetricBlocked">-</div></div></div>
            <div class="metric-card"><div class="metric-icon-box icon-green">✓</div><div><div class="metric-label">Completed</div><div class="metric-value" id="taskMetricDone">-</div></div></div>
        </div>
        <div class="table-container">
            <div class="table-toolbar">
                <div class="toolbar-left">
                    <div class="search-box">🔍 <input id="taskSearch" type="search" placeholder="Cari task / project..." oninput="loadTasks(1)"></div>
                    <select id="taskStatusFilter" onchange="loadTasks(1)"><option value="">Semua Status</option><option value="TODO">TODO</option><option value="IN_PROGRESS">IN PROGRESS</option><option value="BLOCKED">BLOCKED</option><option value="DONE">DONE</option></select>
                    <select id="taskPriorityFilter" onchange="loadTasks(1)"><option value="">Semua Prioritas</option><option value="URGENT">URGENT</option><option value="HIGH">HIGH</option><option value="MEDIUM">MEDIUM</option><option value="LOW">LOW</option></select>
                </div>
            </div>
            <table>
                <thead><tr><th>Task</th><th>Employee</th><th>Project</th><th>Priority</th><th>Status</th><th>Progress</th><th>Due Date</th><th>Action</th></tr></thead>
                <tbody id="tasksTableBody"><tr><td colspan="8" class="loading-td"><div class="spinner"></div> Memuat tasks...</td></tr></tbody>
            </table>
            <div class="task-pagination" id="taskPagination" aria-live="polite"></div>
        </div>
    </section>

    <!-- TAB 5: ACCESS LOGS ONLY -->
    <section id="logsTab" class="tab-content">
        <div class="section-header">
            <div>
                <h2 class="section-title">📋 Riwayat Lengkap Access Logs</h2>
                <p class="section-desc">Audit trail keamanan akses pintu fisik seluruh gedung</p>
            </div>
            <div style="display: flex; gap: 0.5rem; align-items: center; flex-wrap: wrap;">
                <button class="btn-primary" onclick="syncHardwareLogs(this)" title="Tarik riwayat tap akses terbaru dari terminal ISAPI">
                    🔄 Sinkronkan Log Pintu
                </button>
                <button class="btn-secondary" onclick="resetLogFilters()">Reset Filter</button>
            </div>
        </div>
        <div class="table-container">
            <div class="table-toolbar">
                <div class="toolbar-left">
                    <div class="search-box">
                        🚪
                        <select id="logDoorFilterTab" onchange="syncLogFilters(this); loadAccessLogs()">
                            <option value="">Semua Pintu</option>
                        </select>
                    </div>
                    <div class="search-box">
                        ⚡
                        <select id="logStatusFilterTab" onchange="syncLogFilters(this); loadAccessLogs()">
                            <option value="">Semua Status & Alarm</option>
                            <option value="Granted">Granted (Akses Diterima)</option>
                            <option value="Denied">Denied (Akses Ditolak)</option>
                            <option value="Alarm">🚨 Alarm / Intrusion / Sabotase</option>
                            <option value="Duress">⚠️ Duress Emergency</option>
                        </select>
                    </div>
                    <div class="search-box">
                        📋
                        <select id="logAttendanceStateFilterTab" onchange="syncLogFilters(this); loadAccessLogs()">
                            <option value="">Semua Result Kehadiran</option>
                            <option value="PRESENT">PRESENT (Hadir)</option>
                            <option value="LATE">LATE (Terlambat)</option>
                            <option value="OFF">OFF (Hari Libur)</option>
                            <option value="LEAVE">LEAVE (Izin/Cuti)</option>
                            <option value="ABSENT">ABSENT (Alpa)</option>
                            <option value="Belum diproses">Belum diproses</option>
                            <option value="Ditolak">Ditolak</option>
                        </select>
                    </div>
                    <div class="search-box">
                        👤 <input type="text" id="logUserSearchTab" placeholder="Cari NIK / Nama..." oninput="onLogSearchInput(this)">
                    </div>
                    <div class="search-box">
                        📅 <input type="date" id="logStartDateTab" onchange="syncLogFilters(this); loadAccessLogs()" title="Mulai Tanggal">
                    </div>
                    <div class="search-box">
                        📅 <input type="date" id="logEndDateTab" onchange="syncLogFilters(this); loadAccessLogs()" title="Sampai Tanggal">
                    </div>
                </div>
            </div>
            <table>
                <thead>
                    <tr>
                        <th>Waktu</th>
                        <th>NIK</th>
                        <th>Nama</th>
                        <th>Metode</th>
                        <th>Terminal</th>
                        <th>Event</th>
                        <th>Status</th>
                        <th>Attendance Result</th>
                    </tr>
                </thead>
                <tbody id="logsTableBody">
                    <tr><td colspan="8" class="loading-td"><div class="spinner"></div> Memuat fingerprint logs...</td></tr>
                </tbody>
            </table>
        </div>
    </section>

    @if(($admin?->role ?? null) === 'super_admin' && in_array('audit.view', $permissions ?? []))
    <section id="auditLogTab" class="tab-content">
        <div class="section-header">
            <div>
                <h2 class="section-title">🛡️ Audit Log</h2>
                <p class="section-desc">Aktivitas administratif terstruktur; payload mentah dan kredensial tidak pernah ditampilkan.</p>
            </div>
            <button class="btn-secondary" onclick="loadActivityLogs()">↻ Refresh Audit</button>
        </div>
        <div class="table-container">
            <table aria-label="Operational audit timeline">
                <thead><tr><th>Waktu</th><th>Aktor</th><th>Aksi</th><th>Objek</th><th>Ringkasan</th></tr></thead>
                <tbody id="activityLogsTableBody"><tr><td colspan="5" class="loading-td"><div class="spinner"></div> Memuat audit timeline...</td></tr></tbody>
            </table>
        </div>
    </section>
    @endif

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

    <!-- TAB: SETUP GEDUNG -->
    <section id="buildingSetupTab" class="tab-content">
        <div class="section-header">
            <div>
                <h2 class="section-title">🏢 Setup Gedung & Hierarki Lokasi Pintu</h2>
                <p class="section-desc">Tata kelola Master Gedung, Lantai, Zona Akses, dan Pemetaan Perangkat Kontrol Pintu (Hikvision DS-K1T804AMF).</p>
            </div>
            <div style="display: flex; gap: 0.5rem; align-items: center; flex-wrap: wrap;">
                @if(in_array('organization.manage', $permissions ?? []))
                <button class="btn-primary" onclick="openAddBuildingModal()">
                    + Tambah Gedung
                </button>
                <button class="btn-secondary" onclick="openAddZoneModal()">
                    + Tambah Zona Akses
                </button>
                @endif
                <button class="btn-secondary" onclick="loadBuildingHierarchy()">
                    🔄 Refresh Hierarki
                </button>
            </div>
        </div>

        <!-- Schema Status & Blueprint Callout -->
        <div style="margin-bottom: 1.5rem; background: rgba(56, 189, 248, 0.08); border: 1px solid rgba(56, 189, 248, 0.25); border-radius: 0.75rem; padding: 1rem 1.25rem; display: flex; align-items: flex-start; gap: 0.75rem;">
            <span style="font-size: 1.25rem;">ℹ️</span>
            <div style="font-size: 0.85rem; line-height: 1.5; color: var(--text-main);">
                <strong>Status Skema Hierarki Database:</strong> Level <strong>Gedung</strong> (Building), <strong>Zona</strong> (Zone), dan <strong>Perangkat</strong> (Device/Door) aktif menggunakan skema database terintegrasi.<br>
                <span class="text-muted">Proposal migration untuk entitas <code>Floors</code> (Lantai) telah disiapkan sebagai blueprint terstruktur tanpa eksekusi langsung ke database sistem.</span>
            </div>
        </div>

        <!-- Dynamic Hierarchy Container -->
        <div id="buildingHierarchyContainer">
            <div class="table-container" style="padding: 2.5rem; text-align: center;">
                <div class="spinner"></div> Memuat hierarki gedung dan perangkat pintu...
            </div>
        </div>
    </section>

    <!-- TAB: AKUN SISTEM -->
    <section id="systemAccountsTab" class="tab-content">
        <div class="section-header">
            <div>
                <h2 class="section-title">👤 Akun Sistem</h2>
                <p class="section-desc">Inventaris akun portal. Data ditampilkan read-only tanpa kredensial.</p>
            </div>
            <span id="systemAccountsLifecycle" class="badge-warning">Lifecycle: PLANNED · Tambah, ubah, role assignment, dan reset kredensial belum tersedia</span>
        </div>
        <div class="table-container">
            <table aria-label="Inventaris akun sistem">
                <thead><tr><th>Nama</th><th>Email</th><th>Role</th><th>Gedung</th><th>Employee ID</th><th>Status</th><th>Login Terakhir</th><th>Dibuat</th><th>Aksi Lifecycle</th></tr></thead>
                <tbody id="systemAccountsTableBody"><tr><td colspan="9" class="loading-td"><div class="spinner"></div> Memuat akun sistem...</td></tr></tbody>
            </table>
        </div>
    </section>

    <!-- TAB: STATUS SISTEM -->
    <section id="systemStatusTab" class="tab-content">
        <div class="section-header">
            <div>
                <h2 class="section-title">📡 Status Sistem</h2>
                <p class="section-desc">Status berbasis bukti terakhir; UNKNOWN dan STALE bukan ONLINE.</p>
            </div>
            <button class="btn-secondary" onclick="loadSystemHealth()">↻ Refresh Status</button>
        </div>
        <div class="stats-grid" id="systemHealthCards">
            <div class="stat-card"><span class="stat-title">Aplikasi</span><strong class="stat-value" id="healthApp">Memuat...</strong><span class="stat-desc" id="healthTime">-</span></div>
            <div class="stat-card"><span class="stat-title">Database</span><strong class="stat-value" id="healthDatabase">UNKNOWN</strong></div>
            <div class="stat-card"><span class="stat-title">DOOR-B</span><strong class="stat-value" id="healthDoor">UNKNOWN</strong><span class="stat-desc" id="healthDoorFreshness">Belum diperiksa</span></div>
            <div class="stat-card"><span class="stat-title">Semua Pintu</span><strong class="stat-value" id="healthDoorsTotal">0</strong><span class="stat-desc" id="healthDoorsAggregate">Healthy: 0 · Offline: 0 · Stale: 0 · Unknown: 0</span></div>
            <div class="stat-card"><span class="stat-title">Webhook</span><strong class="stat-value" id="healthWebhook">UNKNOWN</strong><span class="stat-desc" id="healthWebhookFreshness">Belum ada bukti penerimaan</span></div>
            <div class="stat-card"><span class="stat-title">Queue</span><strong class="stat-value" id="healthQueue">UNKNOWN</strong><span class="stat-desc" id="healthQueueCounts">-</span></div>
            <div class="stat-card"><span class="stat-title">Event Terakhir</span><strong class="stat-value" id="healthLastEvent">-</strong><span class="stat-desc" id="healthLastEventDetail">Belum ada data</span></div>
        </div>
        <div class="error-td" id="systemHealthError" hidden></div>
    </section>

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

    <!-- SECTION 10: ACCESS PROVISIONING, CREDENTIAL CENTER & E-MONEY REGISTRY (SPRINT 6) -->
    <section class="tab-content" id="accessTab">
        <!-- Header & Action -->
        <div class="table-toolbar" style="margin-bottom: 1.5rem; background: var(--card-bg); border: 1px solid var(--border-color); border-radius: 1rem; padding: 1.25rem 1.5rem;">
            <div class="toolbar-left">
                <h2 style="margin: 0; font-size: 1.35rem; font-weight: 700; color: #ffffff; display: flex; align-items: center; gap: 0.65rem;">
                    <span>🔑</span> Provisi Hak Akses, Kredensial & Registri E-Money
                </h2>
                <div style="font-size: 0.85rem; color: var(--text-muted); margin-top: 0.25rem;">
                    Pusat manajemen hak akses pintu fisik enterprise, registri kartu RFID, monitoring status biometrik terenkripsi, antrean sinkronisasi ISAPI, dan inventaris instrumen E-Money.
                </div>
            </div>
            <div class="toolbar-right" style="display: flex; gap: 0.75rem; flex-wrap: wrap;">
                <button class="btn-secondary" onclick="refreshAccessData(this)">
                    🔄 Refresh
                </button>
                <button class="btn-primary" onclick="openAddAccessRequestModal()">
                    + Ajukan Permintaan Akses
                </button>
                <button class="btn-secondary" onclick="openAddAccessProfileModal()">
                    + Buat Profil Akses
                </button>
                <button class="btn-secondary" onclick="openAddEmoneyModal()">
                    + Registrasi E-Money
                </button>
            </div>
        </div>

        <!-- 4 KPI Metrics -->
        <div class="metrics-grid" style="margin-bottom: 2rem;">
            <div class="metric-card">
                <div class="metric-icon-box icon-yellow">⏳</div>
                <div>
                    <div class="metric-label">Permintaan Akses Menunggu</div>
                    <div class="metric-value" id="metricPendingAccessRequests">-</div>
                </div>
            </div>
            <div class="metric-card">
                <div class="metric-icon-box icon-green">🛡️</div>
                <div>
                    <div class="metric-label">Kredensial Aktif Terbit</div>
                    <div class="metric-value" id="metricActiveCredentials">-</div>
                </div>
            </div>
            <div class="metric-card">
                <div class="metric-icon-box icon-blue">⚡</div>
                <div>
                    <div class="metric-label">Antrean Sinkronisasi ISAPI</div>
                    <div class="metric-value" id="metricPendingDeviceSyncs">-</div>
                </div>
            </div>
            <div class="metric-card">
                <div class="metric-icon-box icon-purple">💳</div>
                <div>
                    <div class="metric-label">Kartu E-Money Terdaftar</div>
                    <div class="metric-value" id="metricTotalEmoneyCards">-</div>
                </div>
            </div>
        </div>

        <!-- Sub-Navigation Pills -->
        <div class="ats-subnav" style="display: flex; gap: 0.5rem; margin-bottom: 1.5rem; border-bottom: 1px solid var(--border-color); padding-bottom: 0.75rem; overflow-x: auto;">
            <button class="subnav-btn active" onclick="switchAccessSubTab('requests', this)">🔑 Permintaan Hak Akses</button>
            <button class="subnav-btn" onclick="switchAccessSubTab('profiles', this)">🛡️ Profil Akses Pintu</button>
            <button class="subnav-btn" onclick="switchAccessSubTab('credentials', this)">💳 Credential Center</button>
            <button class="subnav-btn" onclick="switchAccessSubTab('syncs', this)">⚡ Antrean Perangkat ISAPI</button>
            <button class="subnav-btn" onclick="switchAccessSubTab('emoney', this)">🏧 Registri E-Money (Admin)</button>
        </div>

        <!-- SUB-TAB 1: PERMINTAAN HAK AKSES -->
        <div id="accessSubRequests" class="ats-sub-content" style="display: block;">
            <div class="table-container">
                <div class="table-toolbar">
                    <div class="toolbar-left" style="display: flex; gap: 0.75rem; align-items: center; flex-wrap: wrap;">
                        <input type="text" id="accessRequestSearch" placeholder="Cari nomor permohonan / nama..." oninput="debounceAccessRequestSearch()" style="width: 250px;">
                        <select id="accessRequestStatusFilter" onchange="loadAccessRequests()" style="width: 170px;">
                            <option value="">Semua Status</option>
                            <option value="PENDING_APPROVAL">PENDING_APPROVAL</option>
                            <option value="PROVISIONING">PROVISIONING</option>
                            <option value="APPROVED">APPROVED</option>
                            <option value="ACTIVE">ACTIVE</option>
                            <option value="REJECTED">REJECTED</option>
                            <option value="REVOKED">REVOKED</option>
                        </select>
                    </div>
                </div>
                <table>
                    <thead>
                        <tr>
                            <th>Nomor Permohonan</th>
                            <th>Pemohon / Karyawan</th>
                            <th>Profil Hak Akses</th>
                            <th>Gedung Operasional</th>
                            <th>Masa Berlaku</th>
                            <th>Status Approval</th>
                            <th style="text-align: right;">Aksi</th>
                        </tr>
                    </thead>
                    <tbody id="accessRequestsTableBody">
                        <tr><td colspan="7" class="loading-td"><div class="spinner"></div> Memuat permohonan akses...</td></tr>
                    </tbody>
                </table>
            </div>
        </div>

        <!-- SUB-TAB 2: PROFIL HAK AKSES -->
        <div id="accessSubProfiles" class="ats-sub-content" style="display: none;">
            <div class="table-container">
                <div class="table-toolbar">
                    <div class="toolbar-left" style="display: flex; gap: 0.75rem; align-items: center; flex-wrap: wrap;">
                        <input type="text" id="accessProfileSearch" placeholder="Cari kode profil / nama..." oninput="debounceAccessProfileSearch()" style="width: 250px;">
                    </div>
                </div>
                <table>
                    <thead>
                        <tr>
                            <th>Kode Profil</th>
                            <th>Nama Profil Akses</th>
                            <th>Gedung</th>
                            <th>Jadwal / Jam Kerja</th>
                            <th>Pintu Terotorisasi</th>
                            <th>Status</th>
                        </tr>
                    </thead>
                    <tbody id="accessProfilesTableBody">
                        <tr><td colspan="6" class="loading-td"><div class="spinner"></div> Memuat profil hak akses...</td></tr>
                    </tbody>
                </table>
            </div>
        </div>

        <!-- SUB-TAB 3: CREDENTIAL CENTER -->
        <div id="accessSubCredentials" class="ats-sub-content" style="display: none;">
            <div class="table-container">
                <div class="table-toolbar">
                    <div class="toolbar-left" style="display: flex; gap: 0.75rem; align-items: center; flex-wrap: wrap;">
                        <input type="text" id="credentialSearch" placeholder="Cari nomor kredensial / identitas..." oninput="debounceCredentialSearch()" style="width: 260px;">
                        <select id="credentialTypeFilter" onchange="loadCredentials()" style="width: 170px;">
                            <option value="">Semua Tipe</option>
                            <option value="CARD">RFID Smart Card</option>
                            <option value="FINGERPRINT_STATUS">Status Sidik Jari</option>
                            <option value="FACE_STATUS">Status Wajah Biometrik</option>
                            <option value="QR">QR Code Dinamis</option>
                        </select>
                    </div>
                    <div class="toolbar-right">
                        <button class="btn-primary" onclick="openAddCredentialModal()">+ Terbitkan Kredensial Baru</button>
                    </div>
                </div>
                <table>
                    <thead>
                        <tr>
                            <th>No. Kredensial</th>
                            <th>Pemilik Identitas</th>
                            <th>Tipe Instrumen</th>
                            <th>Pengenal Terenkripsi (Masked)</th>
                            <th>Status Biometrik</th>
                            <th>Status Kredensial</th>
                            <th>Tgl Terbit</th>
                            <th style="text-align: right;">Aksi</th>
                        </tr>
                    </thead>
                    <tbody id="credentialsTableBody">
                        <tr><td colspan="8" class="loading-td"><div class="spinner"></div> Memuat data kredensial...</td></tr>
                    </tbody>
                </table>
            </div>
        </div>

        <!-- SUB-TAB 4: ANTREAN PERANGKAT ISAPI -->
        <div id="accessSubSyncs" class="ats-sub-content" style="display: none;">
            <div class="table-container">
                <div class="table-toolbar">
                    <div class="toolbar-left" style="display: flex; gap: 0.75rem; align-items: center; flex-wrap: wrap;">
                        <select id="deviceSyncStatusFilter" onchange="loadDeviceSyncs()" style="width: 180px;">
                            <option value="">Semua Status Antrean</option>
                            <option value="QUEUED">QUEUED</option>
                            <option value="PROCESSING">PROCESSING</option>
                            <option value="SUCCESS">SUCCESS</option>
                            <option value="FAILED">FAILED</option>
                            <option value="RETRY_PENDING">RETRY_PENDING</option>
                        </select>
                    </div>
                </div>
                <table>
                    <thead>
                        <tr>
                            <th>Terminal Pintu</th>
                            <th>Operasi Hardware</th>
                            <th>Kredensial Terkait</th>
                            <th>Status Job</th>
                            <th>Percobaan</th>
                            <th>Idempotency Key</th>
                            <th>Waktu Sinkron</th>
                            <th style="text-align: right;">Aksi</th>
                        </tr>
                    </thead>
                    <tbody id="deviceSyncsTableBody">
                        <tr><td colspan="8" class="loading-td"><div class="spinner"></div> Memuat antrean sinkronisasi perangkat...</td></tr>
                    </tbody>
                </table>
            </div>
        </div>

        <!-- SUB-TAB 5: REGISTRI E-MONEY (ADMIN-ONLY) -->
        <div id="accessSubEmoney" class="ats-sub-content" style="display: none;">
            <div class="table-container">
                <div class="table-toolbar">
                    <div class="toolbar-left" style="display: flex; gap: 0.75rem; align-items: center; flex-wrap: wrap;">
                        <input type="text" id="emoneySearch" placeholder="Cari UUID / nomor kartu..." oninput="debounceEmoneySearch()" style="width: 250px;">
                        <select id="emoneyProviderFilter" onchange="loadEmoneyCards()" style="width: 180px;">
                            <option value="">Semua Provider</option>
                            <option value="MANDIRI_EMONEY">Mandiri e-Money</option>
                            <option value="BCA_FLAZZ">BCA Flazz</option>
                            <option value="BNI_TAPCASH">BNI TapCash</option>
                            <option value="BRI_BRIZZI">BRI Brizzi</option>
                            <option value="JAKCARD">JakCard</option>
                        </select>
                    </div>
                </div>
                <table>
                    <thead>
                        <tr>
                            <th>UUID Kartu</th>
                            <th>Penerbit / Provider</th>
                            <th>Nomor Kartu (Masked)</th>
                            <th>Pemegang Kartu</th>
                            <th>Status Instrumen</th>
                            <th>Tgl Penerbitan</th>
                            <th style="text-align: right;">Aksi</th>
                        </tr>
                    </thead>
                    <tbody id="emoneyTableBody">
                        <tr><td colspan="7" class="loading-td"><div class="spinner"></div> Memuat data registri E-Money...</td></tr>
                    </tbody>
                </table>
            </div>
        </div>
    </section>

    <!-- SECTION 11: ASSET MANAGEMENT (SPRINT 7) -->
    <section class="tab-content" id="assetsTab">
        <!-- Header & Action -->
        <div class="table-toolbar" style="margin-bottom: 1.5rem; background: var(--card-bg); border: 1px solid var(--border-color); border-radius: 1rem; padding: 1.25rem 1.5rem;">
            <div class="toolbar-left">
                <h2 style="margin: 0; font-size: 1.35rem; font-weight: 700; color: #ffffff; display: flex; align-items: center; gap: 0.65rem;">
                    <span>💻</span> Manajemen Aset & Inventaris Enterprise
                </h2>
                <div style="font-size: 0.85rem; color: var(--text-muted); margin-top: 0.25rem;">
                    Pusat siklus hidup perangkat keras, klasifikasi inventaris, serah-terima karyawan & pemagang, pemeliharaan teknis, penanganan insiden, dan penghapusan buku aman.
                </div>
            </div>
            <div class="toolbar-right" style="display: flex; gap: 0.75rem; flex-wrap: wrap;">
                <button class="btn-secondary" onclick="loadAssetsData(); showToast('Data Manajemen Aset disinkronkan', 'info');">
                    🔄 Refresh
                </button>
                <button class="btn-primary" onclick="openAddAssetModal()">
                    + Daftarkan Aset Baru
                </button>
                <button class="btn-secondary" onclick="openAssignAssetModal()">
                    + Alokasikan Aset
                </button>
                <button class="btn-secondary" onclick="openMaintenanceModal()">
                    + Buka Servis
                </button>
                <button class="btn-secondary" onclick="openIncidentModal()">
                    + Lapor Insiden
                </button>
            </div>
        </div>

        <!-- 6 KPI Metrics -->
        <div class="metrics-grid" style="margin-bottom: 2rem; grid-template-columns: repeat(auto-fit, minmax(180px, 1fr));">
            <div class="metric-card">
                <div class="metric-icon-box icon-blue">📦</div>
                <div>
                    <div class="metric-label">Total Aset Aktif</div>
                    <div class="metric-value" id="metricTotalAssets">-</div>
                </div>
            </div>
            <div class="metric-card">
                <div class="metric-icon-box icon-green">✅</div>
                <div>
                    <div class="metric-label">Tersedia (Available)</div>
                    <div class="metric-value" id="metricAvailableAssets">-</div>
                </div>
            </div>
            <div class="metric-card">
                <div class="metric-icon-box icon-purple">👤</div>
                <div>
                    <div class="metric-label">Dialokasikan (Assigned)</div>
                    <div class="metric-value" id="metricAssignedAssets">-</div>
                </div>
            </div>
            <div class="metric-card">
                <div class="metric-icon-box icon-yellow">🔧</div>
                <div>
                    <div class="metric-label">Servis / Perbaikan</div>
                    <div class="metric-value" id="metricMaintenanceAssets">-</div>
                </div>
            </div>
            <div class="metric-card">
                <div class="metric-icon-box" style="background: rgba(239, 68, 68, 0.15); color: #f87171;">⚠️</div>
                <div>
                    <div class="metric-label">Hilang / Rusak</div>
                    <div class="metric-value" id="metricLostDamagedAssets">-</div>
                </div>
            </div>
            <div class="metric-card">
                <div class="metric-icon-box icon-yellow">🛡️</div>
                <div>
                    <div class="metric-label">Garansi Segera Habis</div>
                    <div class="metric-value" id="metricExpiringWarranties">-</div>
                </div>
            </div>
        </div>

        <!-- Sub-Navigation Pills -->
        <div class="ats-subnav" style="display: flex; gap: 0.5rem; margin-bottom: 1.5rem; border-bottom: 1px solid var(--border-color); padding-bottom: 0.75rem; overflow-x: auto;">
            <button class="subnav-btn active" onclick="switchAssetSubTab('inventory', this)">📦 Inventaris Aset</button>
            <button class="subnav-btn" onclick="switchAssetSubTab('assignments', this)">📋 Penugasan & Handover</button>
            <button class="subnav-btn" onclick="switchAssetSubTab('maintenances', this)">🔧 Pemeliharaan & Servis</button>
            <button class="subnav-btn" onclick="switchAssetSubTab('incidents', this)">⚠️ Laporan Insiden</button>
        </div>

        <!-- SUB-TAB 1: INVENTARIS ASET -->
        <div id="assetSubInventory" class="ats-sub-content">
            <div class="table-container">
                <div class="table-toolbar">
                    <div class="toolbar-left" style="display: flex; gap: 0.75rem; align-items: center; flex-wrap: wrap;">
                        <input type="text" id="assetSearch" placeholder="Cari kode aset / nama / serial..." oninput="debounceAssetSearch()" style="width: 250px;">
                        <select id="assetCategoryFilter" onchange="loadAssetsInventory()" style="width: 170px;">
                            <option value="">Semua Kategori</option>
                        </select>
                        <select id="assetStatusFilter" onchange="loadAssetsInventory()" style="width: 150px;">
                            <option value="">Semua Status</option>
                            <option value="AVAILABLE">AVAILABLE</option>
                            <option value="ASSIGNED">ASSIGNED</option>
                            <option value="MAINTENANCE">MAINTENANCE</option>
                            <option value="REPAIR">REPAIR</option>
                            <option value="LOST">LOST</option>
                            <option value="DAMAGED">DAMAGED</option>
                            <option value="DISPOSED">DISPOSED</option>
                        </select>
                        <select id="assetConditionFilter" onchange="loadAssetsInventory()" style="width: 140px;">
                            <option value="">Semua Kondisi</option>
                            <option value="NEW">NEW</option>
                            <option value="GOOD">GOOD</option>
                            <option value="FAIR">FAIR</option>
                            <option value="POOR">POOR</option>
                            <option value="DAMAGED">DAMAGED</option>
                        </select>
                    </div>
                </div>
                <table>
                    <thead>
                        <tr>
                            <th>Kode Aset</th>
                            <th>Nama Aset & Kategori</th>
                            <th>Brand / Model</th>
                            <th>Nomor Seri (Masked)</th>
                            <th>Gedung & Lokasi</th>
                            <th>Kondisi</th>
                            <th>Status</th>
                            <th style="text-align: right;">Aksi</th>
                        </tr>
                    </thead>
                    <tbody id="assetsTableBody">
                        <tr><td colspan="8" class="loading-td"><div class="spinner"></div> Memuat inventaris aset...</td></tr>
                    </tbody>
                </table>
            </div>
        </div>

        <!-- SUB-TAB 2: PENUGASAN & HANDOVER -->
        <div id="assetSubAssignments" class="ats-sub-content" style="display: none;">
            <div class="table-container">
                <div class="table-toolbar">
                    <div class="toolbar-left" style="display: flex; gap: 0.75rem; align-items: center; flex-wrap: wrap;">
                        <select id="assetAssignmentStatusFilter" onchange="loadAssetAssignments()" style="width: 170px;">
                            <option value="">Semua Status</option>
                            <option value="ACTIVE">ACTIVE (Sedang Dipinjam)</option>
                            <option value="RETURNED">RETURNED (Sudah Kembali)</option>
                        </select>
                    </div>
                </div>
                <table>
                    <thead>
                        <tr>
                            <th>No. Penugasan</th>
                            <th>Aset Terkait</th>
                            <th>Penerima (Karyawan / Intern)</th>
                            <th>Tgl Alokasi</th>
                            <th>Target Kembali</th>
                            <th>Kondisi Awal</th>
                            <th>Kondisi Masuk</th>
                            <th>Status</th>
                            <th style="text-align: right;">Aksi</th>
                        </tr>
                    </thead>
                    <tbody id="assetAssignmentsTableBody">
                        <tr><td colspan="9" class="loading-td"><div class="spinner"></div> Memuat daftar penugasan aset...</td></tr>
                    </tbody>
                </table>
            </div>
        </div>

        <!-- SUB-TAB 3: PEMELIHARAAN & SERVIS -->
        <div id="assetSubMaintenances" class="ats-sub-content" style="display: none;">
            <div class="table-container">
                <div class="table-toolbar">
                    <div class="toolbar-left" style="display: flex; gap: 0.75rem; align-items: center; flex-wrap: wrap;">
                        <select id="assetMaintenanceStatusFilter" onchange="loadAssetMaintenances()" style="width: 170px;">
                            <option value="">Semua Status Servis</option>
                            <option value="OPEN">OPEN</option>
                            <option value="IN_PROGRESS">IN_PROGRESS</option>
                            <option value="COMPLETED">COMPLETED</option>
                        </select>
                    </div>
                </div>
                <table>
                    <thead>
                        <tr>
                            <th>No. Tiket</th>
                            <th>Aset Terkait</th>
                            <th>Tipe Pemeliharaan</th>
                            <th>Deskripsi Kendala</th>
                            <th>Vendor / Teknisi</th>
                            <th>Biaya Servis</th>
                            <th>Tgl Buka</th>
                            <th>Status</th>
                            <th style="text-align: right;">Aksi</th>
                        </tr>
                    </thead>
                    <tbody id="assetMaintenancesTableBody">
                        <tr><td colspan="9" class="loading-td"><div class="spinner"></div> Memuat tiket pemeliharaan aset...</td></tr>
                    </tbody>
                </table>
            </div>
        </div>

        <!-- SUB-TAB 4: LAPORAN INSIDEN -->
        <div id="assetSubIncidents" class="ats-sub-content" style="display: none;">
            <div class="table-container">
                <div class="table-toolbar">
                    <div class="toolbar-left" style="display: flex; gap: 0.75rem; align-items: center; flex-wrap: wrap;">
                        <select id="assetIncidentTypeFilter" onchange="loadAssetIncidents()" style="width: 170px;">
                            <option value="">Semua Jenis Insiden</option>
                            <option value="DAMAGED">DAMAGED (Rusak)</option>
                            <option value="LOST">LOST (Hilang)</option>
                            <option value="STOLEN">STOLEN (Dicuri)</option>
                            <option value="MISSING_ACCESSORY">Aksesoris Hilang</option>
                            <option value="OTHER">Lain-Lain</option>
                        </select>
                        <select id="assetIncidentStatusFilter" onchange="loadAssetIncidents()" style="width: 150px;">
                            <option value="">Semua Status</option>
                            <option value="REPORTED">REPORTED</option>
                            <option value="INVESTIGATING">INVESTIGATING</option>
                            <option value="RESOLVED">RESOLVED</option>
                        </select>
                    </div>
                </div>
                <table>
                    <thead>
                        <tr>
                            <th>No. Insiden</th>
                            <th>Aset Terkait</th>
                            <th>Jenis Insiden</th>
                            <th>Pelapor / Pemegang</th>
                            <th>Deskripsi & Lokasi</th>
                            <th>Tgl Kejadian</th>
                            <th>Status</th>
                            <th style="text-align: right;">Aksi</th>
                        </tr>
                    </thead>
                    <tbody id="assetIncidentsTableBody">
                        <tr><td colspan="8" class="loading-td"><div class="spinner"></div> Memuat laporan insiden aset...</td></tr>
                    </tbody>
                </table>
            </div>
        </div>
    </section>

    <!-- SECTION 12: WORK CALENDAR & ATTENDANCE CORE (SPRINT 8) -->
    <section class="tab-content" id="attendanceTab">
        <!-- Header & Action -->
        <div class="table-toolbar" style="margin-bottom: 1.5rem; background: var(--card-bg); border: 1px solid var(--border-color); border-radius: 1rem; padding: 1.25rem 1.5rem;">
            <div class="toolbar-left">
                <h2 style="margin: 0; font-size: 1.35rem; font-weight: 700; color: #ffffff; display: flex; align-items: center; gap: 0.65rem;">
                    <span>⏰</span> Kehadiran & Kalender Kerja
                </h2>
                <div style="font-size: 0.85rem; color: var(--text-muted); margin-top: 0.25rem;">
                    Pantau bukti kehadiran kantor yang telah diproses dari terminal pintu, kalender kerja, dan jam kerja tanpa menampilkan payload perangkat.
                </div>
            </div>
            <div class="toolbar-right" style="display: flex; gap: 0.75rem; flex-wrap: wrap;">
                <button class="btn-secondary" onclick="loadAttendanceData(); showToast('Data Kehadiran disinkronkan', 'info');">
                    🔄 Refresh
                </button>
            </div>
        </div>

        <div class="stats-grid" id="attendanceMetricsContainer" style="margin-bottom: 1.5rem;">
            <!-- Metrics populated by JS -->
        </div>

        <div class="table-container" style="margin-bottom:1.5rem;padding:1rem;">
            <div class="table-toolbar" style="margin-bottom:1rem;">
                <div class="toolbar-left"><h3 style="margin:0;color:#fff;">Laporan Kehadiran Bulanan</h3><div class="section-desc">Ringkasan hadir, terlambat, dan absen per karyawan serta gedung.</div></div>
                <div class="toolbar-right" style="display:flex;gap:.65rem;flex-wrap:wrap;">
                    <input type="month" id="attendanceReportMonth" class="form-control" onchange="loadAttendanceReport()" aria-label="Bulan laporan">
                    <select id="attendanceReportBuilding" class="form-control" onchange="loadAttendanceReport()" aria-label="Filter gedung"><option value="">Semua Gedung</option></select>
                    <button class="btn-secondary" onclick="exportAttendanceReport(this)">⬇ Export CSV</button>
                    <button class="btn-secondary" onclick="printAttendanceReport()" title="Buka dialog cetak, lalu pilih 'Simpan sebagai PDF'">🖨 Cetak / PDF</button>
                </div>
            </div>
            <div class="stats-grid" id="attendanceReportMetrics" style="margin-bottom:1rem;"></div>
            <div style="overflow:auto;"><table class="data-table"><thead><tr><th>Karyawan</th><th>Gedung</th><th>Hadir</th><th>Terlambat</th><th>Absen</th><th>Attendance Rate</th><th>Menit Terlambat</th></tr></thead><tbody id="attendanceReportBody"><tr><td colspan="7" class="loading-td">Memuat laporan bulanan...</td></tr></tbody></table></div>
        </div>

        <!-- Attendance Content -->
        <div class="table-container">
            <table class="data-table">
                <thead>
                    <tr>
                        <th>Tgl Kehadiran</th>
                        <th>Karyawan</th>
                        <th>Kalender</th>
                        <th title="Waktu tap pertama, ditampilkan dalam WIB (Asia/Jakarta)">Jam Masuk (WIB)</th>
                        <th title="Waktu tap terakhir, ditampilkan dalam WIB (Asia/Jakarta)">Jam Keluar (WIB)</th>
                        <th>Pintu</th>
                        <th>Kredensial</th>
                        <th>Status Proses</th>
                        <th>Status</th>
                        <th>Keterlambatan</th>
                    </tr>
                </thead>
                <tbody id="attendanceTableBody">
                    <tr><td colspan="10" class="loading-td"><div class="spinner"></div> Memuat data kehadiran...</td></tr>
                </tbody>
            </table>
        </div>
    </section>

    <!-- SPRINT 10: FIELD ATTENDANCE TAB -->
    <section class="tab-content" id="fieldAttendanceTab">
        <!-- Header & Action -->
        <div class="table-toolbar" style="margin-bottom: 1.5rem; background: var(--card-bg); border: 1px solid var(--border-color); border-radius: 1rem; padding: 1.25rem 1.5rem;">
            <div class="toolbar-left">
                <h2 style="margin: 0; font-size: 1.35rem; font-weight: 700; color: #ffffff; display: flex; align-items: center; gap: 0.65rem;">
                    <span>📍</span> Presensi Lapangan (GPS & Foto)
                </h2>
                <div style="font-size: 0.85rem; color: var(--text-muted); margin-top: 0.25rem;">
                    Verifikasi presensi mobile-first berbasis validasi geofence server-side dan bukti foto terenkripsi lokal.
                </div>
            </div>
            <div class="toolbar-right" style="display: flex; gap: 0.75rem; flex-wrap: wrap;">
                <button class="btn-secondary" onclick="loadFieldAttendanceData(); showToast('Data Presensi Lapangan diperbarui', 'info');">
                    🔄 Refresh
                </button>
            </div>
        </div>

        <!-- Mobile-First Self-Service Grid -->
        <div style="display: grid; grid-template-columns: repeat(auto-fit, minmax(320px, 1fr)); gap: 1.25rem; margin-bottom: 2rem;">

            <!-- 1. Assignment & Geofence Target Card -->
            <div class="metric-card" style="display: block; padding: 1.5rem;">
                <div style="display: flex; justify-content: space-between; align-items: center; margin-bottom: 1rem;">
                    <div style="font-size: 0.85rem; font-weight: 700; color: var(--text-muted); text-transform: uppercase; letter-spacing: 0.05em;">
                        📋 Penugasan Hari Ini
                    </div>
                    <span id="fieldAssignmentStatusBadge" class="status-badge status-info">Memeriksa...</span>
                </div>
                <div id="fieldAssignmentDetails">
                    <div class="spinner"></div> Memuat penugasan...
                </div>
            </div>

            <!-- 2. Live GPS Validation Card -->
            <div class="metric-card" style="display: block; padding: 1.5rem;">
                <div style="display: flex; justify-content: space-between; align-items: center; margin-bottom: 1rem;">
                    <div style="font-size: 0.85rem; font-weight: 700; color: var(--text-muted); text-transform: uppercase; letter-spacing: 0.05em;">
                        🛰️ Status Geofence GPS
                    </div>
                    <span id="fieldGpsStatusBadge" class="status-badge" style="background: rgba(148, 163, 184, 0.2); color: var(--text-muted);">BELUM TERDETEKSI</span>
                </div>
                <div id="fieldGpsDetails" style="font-size: 0.85rem; color: var(--text-muted); line-height: 1.6; margin-bottom: 1rem;">
                    Tekan tombol di bawah untuk mendeteksi posisi GPS Anda dari browser perangkat.
                </div>
                <button type="button" class="btn-primary" id="btnAcquireGps" style="width: 100%; justify-content: center;" onclick="acquireFieldGps()">
                    📡 Ambil Titik GPS Saya
                </button>
            </div>

            <!-- 3. Photo Evidence & Action Card -->
            <div class="metric-card" style="display: block; padding: 1.5rem;">
                <div style="display: flex; justify-content: space-between; align-items: center; margin-bottom: 1rem;">
                    <div style="font-size: 0.85rem; font-weight: 700; color: var(--text-muted); text-transform: uppercase; letter-spacing: 0.05em;">
                        📸 Bukti Foto Lapangan
                    </div>
                    <span id="fieldPhotoStatusBadge" class="status-badge status-warning">FOTO DIPERLUKAN</span>
                </div>

                <div id="fieldPhotoPreviewBox" style="margin-bottom: 1rem; border: 2px dashed var(--border-color); border-radius: 0.75rem; padding: 1rem; text-align: center; cursor: pointer; min-height: 120px; display: flex; flex-direction: column; align-items: center; justify-content: center;" onclick="document.getElementById('fieldPhotoInput').click()">
                    <span style="font-size: 2rem; margin-bottom: 0.25rem;">📷</span>
                    <div style="font-size: 0.825rem; color: var(--text-muted);" id="fieldPhotoPlaceholderText">
                        Klik untuk mengambil foto selfie / lokasi
                    </div>
                    <img id="fieldPhotoPreviewImg" style="display: none; max-width: 100%; max-height: 140px; border-radius: 0.5rem; margin-top: 0.5rem; object-fit: cover;" />
                </div>
                <input type="file" id="fieldPhotoInput" accept="image/*" capture="user" style="display: none;" onchange="handleFieldPhotoSelected(event)">

                <div style="margin-bottom: 1rem;">
                    <input type="text" id="fieldAttendanceNotes" placeholder="Catatan / keterangan tambahan (opsional)..." class="form-control" style="width: 100%; background: var(--bg-base); border: 1px solid var(--border-color); color: #fff; padding: 0.6rem 0.85rem; border-radius: 0.5rem; font-size: 0.85rem;">
                </div>

                <div style="display: grid; grid-template-columns: 1fr 1fr; gap: 0.75rem;">
                    <button type="button" class="btn-primary" id="btnFieldCheckIn" style="justify-content: center; background: #10b981; border-color: #059669;" onclick="submitFieldAttendance('CHECK_IN')" disabled>
                        📥 Check-In
                    </button>
                    <button type="button" class="btn-primary" id="btnFieldCheckOut" style="justify-content: center; background: #6366f1; border-color: #4f46e5;" onclick="submitFieldAttendance('CHECK_OUT')" disabled>
                        📤 Check-Out
                    </button>
                </div>
            </div>
        </div>

        <!-- Today's Attendance Core Status Card -->
        <div id="fieldTodayAttendanceCard" style="margin-bottom: 1.5rem; background: var(--card-bg); border: 1px solid var(--border-color); border-radius: 1rem; padding: 1.25rem 1.5rem; display: none;">
            <!-- Populated via JS -->
        </div>

        <!-- Evidence History Table -->
        <div class="table-container">
            <div style="padding: 1rem 1.5rem; border-bottom: 1px solid var(--border-color); display: flex; justify-content: space-between; align-items: center; flex-wrap: wrap; gap: 0.5rem;">
                <h3 style="font-size: 1.05rem; font-weight: 700; margin: 0; color: #fff;">
                    📋 Log Bukti & Riwayat Presensi Lapangan
                </h3>
                <div style="font-size: 0.8rem; color: var(--text-muted);">
                    Menampilkan rekaman presensi lapangan sesuai hak akses Anda
                </div>
            </div>
            <table class="data-table">
                <thead>
                    <tr>
                        <th>Waktu Presensi</th>
                        <th>Karyawan</th>
                        <th>Lokasi Proyek</th>
                        <th>Tipe</th>
                        <th>Jarak Geofence</th>
                        <th>Akurasi GPS</th>
                        <th>Hasil Geofence</th>
                        <th>Status Bukti</th>
                        <th>Aksi</th>
                    </tr>
                </thead>
                <tbody id="fieldAttendanceTableBody">
                    <tr><td colspan="9" class="loading-td"><div class="spinner"></div> Memuat riwayat presensi lapangan...</td></tr>
                </tbody>
            </table>
        </div>
    </section>

    <!-- -------------------------------------------------------------------------  -->
    <!-- SPRINT 11: WFH + LEAVE + PERMISSION + SICK (ATTENDANCE REQUESTS) -->
    <!-- -------------------------------------------------------------------------  -->
    <section class="tab-content" id="attendanceRequestsTab">
        <div class="content-header">
            <div>
                <h2>📝 Pengajuan Absensi & Izin Kerja</h2>
                <p class="section-desc">Pusat permohonan WFH, Cuti, Izin, dan Sakit terintegrasi dengan Attendance Core & Dokumen Privat.</p>
            </div>
            <div style="display: flex; gap: 0.5rem; flex-wrap: wrap;">
                <button class="btn-secondary" onclick="loadAttendanceRequestsData()">🔄 Refresh</button>
                <button class="btn-primary" onclick="openNewAttendanceRequestModal()">➕ Buat Pengajuan Baru</button>
            </div>
        </div>

        <!-- METRICS COUNTERS -->
        <div class="metrics-grid" style="grid-template-columns: repeat(auto-fit, minmax(180px, 1fr)); margin-bottom: 1.5rem;">
            <div class="metric-card">
                <div class="metric-title">Menunggu Persetujuan</div>
                <div class="metric-value" style="color: var(--warning, #f59e0b);" id="reqMetricPending">0</div>
                <div class="metric-sub">Pending review</div>
            </div>
            <div class="metric-card">
                <div class="metric-title">Disetujui</div>
                <div class="metric-value" style="color: var(--success, #10b981);" id="reqMetricApproved">0</div>
                <div class="metric-sub">Approved exceptions</div>
            </div>
            <div class="metric-card">
                <div class="metric-title">Ditolak</div>
                <div class="metric-value" style="color: var(--danger, #ef4444);" id="reqMetricRejected">0</div>
                <div class="metric-sub">Rejected requests</div>
            </div>
            <div class="metric-card">
                <div class="metric-title">WFH Aktif</div>
                <div class="metric-value" style="color: var(--primary, #3b82f6);" id="reqMetricWfh">0</div>
                <div class="metric-sub">Work From Home</div>
            </div>
            <div class="metric-card">
                <div class="metric-title">Cuti & Sakit</div>
                <div class="metric-value" style="color: #a855f7;" id="reqMetricLeaveSick">0</div>
                <div class="metric-sub">Leave & Sick days</div>
            </div>
        </div>

        <!-- FILTER BAR -->
        <div class="glass-panel" style="padding: 1rem; margin-bottom: 1.25rem; display: flex; gap: 1rem; flex-wrap: wrap; align-items: flex-end;">
            <div style="flex: 1; min-width: 140px;">
                <label style="display: block; font-size: 0.75rem; color: var(--text-muted); margin-bottom: 0.35rem;">Tipe Permohonan</label>
                <select id="reqFilterType" class="form-control" onchange="loadAttendanceRequestsData()" style="width: 100%; background: var(--bg-base); border: 1px solid var(--border-color); color: #fff; padding: 0.5rem; border-radius: 0.5rem;">
                    <option value="">Semua Tipe</option>
                    <option value="WFH">WFH (Work From Home)</option>
                    <option value="LEAVE">Cuti (Annual/Unpaid)</option>
                    <option value="PERMISSION">Izin (Permission)</option>
                    <option value="SICK">Sakit (Sick Leave)</option>
                </select>
            </div>
            <div style="flex: 1; min-width: 140px;">
                <label style="display: block; font-size: 0.75rem; color: var(--text-muted); margin-bottom: 0.35rem;">Status</label>
                <select id="reqFilterStatus" class="form-control" onchange="loadAttendanceRequestsData()" style="width: 100%; background: var(--bg-base); border: 1px solid var(--border-color); color: #fff; padding: 0.5rem; border-radius: 0.5rem;">
                    <option value="">Semua Status</option>
                    <option value="SUBMITTED">Menunggu Persetujuan</option>
                    <option value="APPROVED">Disetujui</option>
                    <option value="REJECTED">Ditolak</option>
                    <option value="CANCELLED">Dibatalkan</option>
                </select>
            </div>
            <div style="flex: 1; min-width: 140px;">
                <label style="display: block; font-size: 0.75rem; color: var(--text-muted); margin-bottom: 0.35rem;">Dari Tanggal</label>
                <input type="date" id="reqFilterFrom" class="form-control" onchange="loadAttendanceRequestsData()" style="width: 100%; background: var(--bg-base); border: 1px solid var(--border-color); color: #fff; padding: 0.5rem; border-radius: 0.5rem;" />
            </div>
            <div style="flex: 1; min-width: 140px;">
                <label style="display: block; font-size: 0.75rem; color: var(--text-muted); margin-bottom: 0.35rem;">Sampai Tanggal</label>
                <input type="date" id="reqFilterTo" class="form-control" onchange="loadAttendanceRequestsData()" style="width: 100%; background: var(--bg-base); border: 1px solid var(--border-color); color: #fff; padding: 0.5rem; border-radius: 0.5rem;" />
            </div>
        </div>

        <!-- DATA TABLE -->
        <div class="table-responsive glass-panel">
            <table class="data-table" id="attendanceRequestsTable">
                <thead>
                    <tr>
                        <th>ID</th>
                        <th>Karyawan</th>
                        <th>Tipe</th>
                        <th>Periode</th>
                        <th>Jam / Ket</th>
                        <th>Alasan</th>
                        <th>Dokumen</th>
                        <th>Status</th>
                        <th>Aksi</th>
                    </tr>
                </thead>
                <tbody id="attendanceRequestsTableBody">
                    <tr><td colspan="9" class="loading-td"><div class="spinner"></div> Memuat daftar pengajuan absensi...</td></tr>
                </tbody>
            </table>
        </div>
    </section>

    <!-- MODAL: NEW ATTENDANCE REQUEST -->
    <div class="modal-overlay" id="newAttendanceRequestModal">
        <div class="modal-card" style="max-width: 550px;">
            <div class="modal-header">
                <h3 class="modal-title">📝 Buat Pengajuan Absensi Baru</h3>
                <button class="modal-close-btn" onclick="closeModal('newAttendanceRequestModal')">✖</button>
            </div>
            <form id="newAttendanceRequestForm" onsubmit="submitNewAttendanceRequest(event)">
                <div class="form-row">
                    <label>Tipe Permohonan *</label>
                    <select id="newReqType" required onchange="onReqTypeChanged()" class="form-control" style="width: 100%;">
                        <option value="WFH">WFH — Work From Home</option>
                        <option value="LEAVE">LEAVE — Cuti Tahunan / Khusus</option>
                        <option value="PERMISSION">PERMISSION — Izin (Keterlambatan/Pulang Awal/Keperluan)</option>
                        <option value="SICK">SICK — Sakit (Disertai Surat Dokter)</option>
                    </select>
                </div>

                <div class="form-row" id="newReqCategoryRow">
                    <label>Kategori Khusus (Opsional)</label>
                    <input type="text" id="newReqCategory" placeholder="Misal: ANNUAL, MATERNITY, LATE_ARRIVAL, EARLY_DEPARTURE" class="form-control" style="width: 100%;" />
                </div>

                <div style="display: flex; gap: 0.75rem;" class="form-row">
                    <div style="flex: 1;">
                        <label>Tanggal Mulai *</label>
                        <input type="date" id="newReqStartDate" required class="form-control" style="width: 100%;" />
                    </div>
                    <div style="flex: 1;">
                        <label>Tanggal Selesai *</label>
                        <input type="date" id="newReqEndDate" required class="form-control" style="width: 100%;" />
                    </div>
                </div>

                <div style="display: flex; gap: 0.75rem;" class="form-row" id="newReqTimeRow">
                    <div style="flex: 1;">
                        <label>Jam Mulai (Khusus Izin Jam Kerja)</label>
                        <input type="time" id="newReqStartTime" class="form-control" style="width: 100%;" />
                    </div>
                    <div style="flex: 1;">
                        <label>Jam Selesai</label>
                        <input type="time" id="newReqEndTime" class="form-control" style="width: 100%;" />
                    </div>
                </div>

                <div class="form-row">
                    <label>Alasan Permohonan *</label>
                    <textarea id="newReqReason" required minlength="5" rows="3" class="form-control" style="width: 100%;" placeholder="Jelaskan keperluan atau keterangan permohonan..."></textarea>
                </div>

                <div class="form-row" id="newReqAttachmentRow">
                    <label>Dokumen Pendukung (Surat Dokter / Bukti, maks 5MB, PDF/JPG/PNG)</label>
                    <input type="file" id="newReqAttachment" accept=".pdf,.jpg,.jpeg,.png,.webp" class="form-control" style="width: 100%;" />
                </div>

                <div style="display: flex; justify-content: flex-end; gap: 0.75rem; margin-top: 1.5rem;">
                    <button type="button" class="btn-secondary" onclick="closeModal('newAttendanceRequestModal')">Batal</button>
                    <button type="submit" class="btn-primary" id="btnSubmitNewReq">Kirim Permohonan</button>
                </div>
            </form>
        </div>
    </div>

    <!-- MODAL: REJECT REASON -->
    <div class="modal-overlay" id="rejectAttendanceRequestModal">
        <div class="modal-card" style="max-width: 480px;">
            <div class="modal-header">
                <h3 class="modal-title">❌ Tolak Pengajuan Absensi</h3>
                <button class="modal-close-btn" onclick="closeModal('rejectAttendanceRequestModal')">✖</button>
            </div>
            <form id="rejectAttendanceRequestForm" onsubmit="submitRejectAttendanceRequest(event)">
                <input type="hidden" id="rejectAttendanceReqId" />
                <p style="font-size: 0.85rem; color: var(--text-muted); margin-bottom: 1rem;">
                    Berikan alasan penolakan secara jelas. Karyawan akan melihat catatan ini pada timeline status permohonan.
                </p>
                <div class="form-row">
                    <label>Alasan Penolakan *</label>
                    <textarea id="rejectReasonInput" required minlength="3" rows="3" class="form-control" style="width: 100%;" placeholder="Contoh: Jadwal bertabrakan dengan agenda audit pabrik..."></textarea>
                </div>
                <div style="display: flex; justify-content: flex-end; gap: 0.75rem; margin-top: 1rem;">
                    <button type="button" class="btn-secondary" onclick="closeModal('rejectAttendanceRequestModal')">Batal</button>
                    <button type="submit" class="btn-primary" style="background: var(--danger); border-color: var(--danger);" id="btnSubmitRejectReq">Tolak Permohonan</button>
                </div>
            </form>
        </div>
    </div>

    <!-- --------------------------------------------------------------------------
         SPRINT 12: KOREKSI PRESENSI (ATTENDANCE CORRECTION)
         -------------------------------------------------------------------------- -->
    <section class="tab-content" id="attendanceCorrectionsTab">
        <div class="content-header">
            <div>
                <h2>✏️ Koreksi Presensi & Kehadiran</h2>
                <p class="section-desc">Pusat permohonan koreksi jam scan, status, dan riwayat presensi yang dapat diaudit secara transparan.</p>
            </div>
            <div style="display: flex; gap: 0.5rem; flex-wrap: wrap;">
                <button class="btn-secondary" onclick="loadAttendanceCorrectionsData()">🔄 Refresh</button>
                <button class="btn-primary" onclick="openNewAttendanceCorrectionModal()">➕ Ajukan Koreksi Presensi</button>
            </div>
        </div>

        <!-- METRICS COUNTERS -->
        <div class="metrics-grid" style="grid-template-columns: repeat(auto-fit, minmax(180px, 1fr)); margin-bottom: 1.5rem;">
            <div class="metric-card">
                <div class="metric-title">Menunggu Persetujuan</div>
                <div class="metric-value" style="color: var(--warning, #f59e0b);" id="corrMetricPending">0</div>
                <div class="metric-sub">Pending review</div>
            </div>
            <div class="metric-card">
                <div class="metric-title">Disetujui</div>
                <div class="metric-value" style="color: var(--success, #10b981);" id="corrMetricApproved">0</div>
                <div class="metric-sub">Approved corrections</div>
            </div>
            <div class="metric-card">
                <div class="metric-title">Ditolak</div>
                <div class="metric-value" style="color: var(--danger, #ef4444);" id="corrMetricRejected">0</div>
                <div class="metric-sub">Rejected corrections</div>
            </div>
            <div class="metric-card">
                <div class="metric-title">Dibatalkan</div>
                <div class="metric-value" style="color: var(--text-muted);" id="corrMetricCancelled">0</div>
                <div class="metric-sub">Cancelled by user</div>
            </div>
        </div>

        <!-- FILTER BAR -->
        <div class="glass-panel" style="padding: 1rem; margin-bottom: 1.25rem; display: flex; gap: 1rem; flex-wrap: wrap; align-items: flex-end;">
            <div style="flex: 1; min-width: 140px;">
                <label style="display: block; font-size: 0.75rem; color: var(--text-muted); margin-bottom: 0.35rem;">Tipe Koreksi</label>
                <select id="corrFilterType" class="form-control" onchange="loadAttendanceCorrectionsData()" style="width: 100%; background: var(--bg-base); border: 1px solid var(--border-color); color: #fff; padding: 0.5rem; border-radius: 0.5rem;">
                    <option value="">Semua Tipe</option>
                    <option value="CHECK_IN">Jam Masuk (Check-In)</option>
                    <option value="CHECK_OUT">Jam Keluar (Check-Out)</option>
                    <option value="CHECK_IN_AND_OUT">Masuk & Keluar</option>
                    <option value="STATUS">Status Kehadiran</option>
                    <option value="ATTENDANCE_TYPE">Tipe Presensi (Kantor/Lapangan/WFH)</option>
                </select>
            </div>
            <div style="flex: 1; min-width: 140px;">
                <label style="display: block; font-size: 0.75rem; color: var(--text-muted); margin-bottom: 0.35rem;">Status</label>
                <select id="corrFilterStatus" class="form-control" onchange="loadAttendanceCorrectionsData()" style="width: 100%; background: var(--bg-base); border: 1px solid var(--border-color); color: #fff; padding: 0.5rem; border-radius: 0.5rem;">
                    <option value="">Semua Status</option>
                    <option value="SUBMITTED">Menunggu Persetujuan</option>
                    <option value="APPROVED">Disetujui</option>
                    <option value="REJECTED">Ditolak</option>
                    <option value="CANCELLED">Dibatalkan</option>
                </select>
            </div>
            <div style="flex: 1; min-width: 140px;">
                <label style="display: block; font-size: 0.75rem; color: var(--text-muted); margin-bottom: 0.35rem;">Dari Tanggal</label>
                <input type="date" id="corrFilterFrom" class="form-control" onchange="loadAttendanceCorrectionsData()" style="width: 100%; background: var(--bg-base); border: 1px solid var(--border-color); color: #fff; padding: 0.5rem; border-radius: 0.5rem;" />
            </div>
            <div style="flex: 1; min-width: 140px;">
                <label style="display: block; font-size: 0.75rem; color: var(--text-muted); margin-bottom: 0.35rem;">Sampai Tanggal</label>
                <input type="date" id="corrFilterTo" class="form-control" onchange="loadAttendanceCorrectionsData()" style="width: 100%; background: var(--bg-base); border: 1px solid var(--border-color); color: #fff; padding: 0.5rem; border-radius: 0.5rem;" />
            </div>
        </div>

        <!-- DATA TABLE -->
        <div class="table-responsive glass-panel">
            <table class="data-table" id="attendanceCorrectionsTable">
                <thead>
                    <tr>
                        <th>ID</th>
                        <th>Karyawan</th>
                        <th>Tgl Presensi</th>
                        <th>Tipe</th>
                        <th>Data Awal (Asli)</th>
                        <th>Koreksi Diajukan</th>
                        <th>Alasan & Catatan</th>
                        <th>Dokumen</th>
                        <th>Status</th>
                        <th>Aksi</th>
                    </tr>
                </thead>
                <tbody id="attendanceCorrectionsTableBody">
                    <tr><td colspan="10" class="loading-td"><div class="spinner"></div> Memuat daftar koreksi presensi...</td></tr>
                </tbody>
            </table>
        </div>
    </section>

    <!-- MODAL: NEW ATTENDANCE CORRECTION -->
    <div class="modal-overlay" id="newAttendanceCorrectionModal">
        <div class="modal-card" style="max-width: 550px;">
            <div class="modal-header">
                <h3 class="modal-title">✏️ Ajukan Koreksi Presensi</h3>
                <button class="modal-close-btn" onclick="closeModal('newAttendanceCorrectionModal')">✖</button>
            </div>
            <form id="newAttendanceCorrectionForm" onsubmit="submitNewAttendanceCorrection(event)">
                <div class="form-row">
                    <label>Tanggal Presensi yang Dikoreksi *</label>
                    <input type="date" id="newCorrDate" required max="{{ now()->toDateString() }}" class="form-control" style="width: 100%;" />
                </div>

                <div class="form-row">
                    <label>Tipe Koreksi *</label>
                    <select id="newCorrType" required class="form-control" style="width: 100%;">
                        <option value="CHECK_IN_AND_OUT">Koreksi Jam Masuk & Keluar</option>
                        <option value="CHECK_IN">Hanya Jam Masuk (Check-In)</option>
                        <option value="CHECK_OUT">Hanya Jam Keluar (Check-Out)</option>
                        <option value="STATUS">Koreksi Status Kehadiran</option>
                        <option value="ATTENDANCE_TYPE">Koreksi Tipe Presensi</option>
                    </select>
                </div>

                <div style="display: flex; gap: 0.75rem;" class="form-row">
                    <div style="flex: 1;">
                        <label>Waktu Masuk Baru (Check-In)</label>
                        <input type="datetime-local" id="newCorrCheckIn" class="form-control" style="width: 100%;" />
                    </div>
                    <div style="flex: 1;">
                        <label>Waktu Keluar Baru (Check-Out)</label>
                        <input type="datetime-local" id="newCorrCheckOut" class="form-control" style="width: 100%;" />
                    </div>
                </div>

                <div class="form-row">
                    <label>Status Kehadiran yang Diusulkan (Opsional)</label>
                    <select id="newCorrStatus" class="form-control" style="width: 100%;">
                        <option value="">Otomatis dari Sistem / Jadwal</option>
                        <option value="PRESENT">PRESENT (Hadir Tepat Waktu)</option>
                        <option value="LATE">LATE (Terlambat)</option>
                        <option value="HALF_DAY">HALF_DAY (Setengah Hari)</option>
                        <option value="WFH">WFH (Work From Home)</option>
                        <option value="FIELD">FIELD (Tugas Lapangan)</option>
                    </select>
                </div>

                <div class="form-row">
                    <label>Alasan Koreksi *</label>
                    <textarea id="newCorrReason" required minlength="5" rows="3" class="form-control" style="width: 100%;" placeholder="Jelaskan alasan pengajuan koreksi (cth: kartu tertinggal, perbaikan reader pintu)..."></textarea>
                </div>

                <div class="form-row">
                    <label>Catatan Bukti / Keterangan Tambahan</label>
                    <input type="text" id="newCorrEvidenceNote" class="form-control" placeholder="Contoh: Terkonfirmasi oleh atasan / security pos 1" style="width: 100%;" />
                </div>

                <div class="form-row">
                    <label>Dokumen Pendukung (Foto logbook, surat tugas, maks 5MB)</label>
                    <input type="file" id="newCorrAttachment" accept=".pdf,.jpg,.jpeg,.png,.webp" class="form-control" style="width: 100%;" />
                </div>

                <div style="display: flex; justify-content: flex-end; gap: 0.75rem; margin-top: 1.5rem;">
                    <button type="button" class="btn-secondary" onclick="closeModal('newAttendanceCorrectionModal')">Batal</button>
                    <button type="submit" class="btn-primary" id="btnSubmitNewCorr">Kirim Koreksi</button>
                </div>
            </form>
        </div>
    </div>

    <!-- MODAL: REJECT CORRECTION -->
    <div class="modal-overlay" id="rejectAttendanceCorrectionModal">
        <div class="modal-card" style="max-width: 480px;">
            <div class="modal-header">
                <h3 class="modal-title">❌ Tolak Koreksi Presensi</h3>
                <button class="modal-close-btn" onclick="closeModal('rejectAttendanceCorrectionModal')">✖</button>
            </div>
            <form id="rejectAttendanceCorrectionForm" onsubmit="submitRejectAttendanceCorrection(event)">
                <input type="hidden" id="rejectCorrId" />
                <p style="font-size: 0.85rem; color: var(--text-muted); margin-bottom: 1rem;">
                    Berikan alasan penolakan koreksi presensi ini secara objektif.
                </p>
                <div class="form-row">
                    <label>Alasan Penolakan *</label>
                    <textarea id="rejectCorrReasonInput" required minlength="3" rows="3" class="form-control" style="width: 100%;" placeholder="Contoh: Log akses fisik tidak menunjukkan kehadiran..."></textarea>
                </div>
                <div style="display: flex; justify-content: flex-end; gap: 0.75rem; margin-top: 1rem;">
                    <button type="button" class="btn-secondary" onclick="closeModal('rejectAttendanceCorrectionModal')">Batal</button>
                    <button type="submit" class="btn-primary" style="background: var(--danger); border-color: var(--danger);" id="btnSubmitRejectCorr">Tolak Koreksi</button>
                </div>
            </form>
        </div>
    </div>

    <!-- --------------------------------------------------------------------------
         SPRINT 12: PENGAJUAN LEMBUR (OVERTIME REQUEST)
         -------------------------------------------------------------------------- -->
    <section class="tab-content" id="overtimeRequestsTab">
        <div class="content-header">
            <div>
                <h2>⚡ Pengajuan Lembur (Overtime)</h2>
                <p class="section-desc">Manajemen penugasan dan pengajuan lembur terverifikasi dengan perhitungan durasi otomatis dan persetujuan bertingkat.</p>
            </div>
            <div style="display: flex; gap: 0.5rem; flex-wrap: wrap;">
                <button class="btn-secondary" onclick="loadOvertimeRequestsData()">🔄 Refresh</button>
                <button class="btn-primary" onclick="openNewOvertimeRequestModal()">➕ Ajukan Lembur Baru</button>
            </div>
        </div>

        <!-- METRICS COUNTERS -->
        <div class="metrics-grid" style="grid-template-columns: repeat(auto-fit, minmax(180px, 1fr)); margin-bottom: 1.5rem;">
            <div class="metric-card">
                <div class="metric-title">Menunggu Persetujuan</div>
                <div class="metric-value" style="color: var(--warning, #f59e0b);" id="otMetricPending">0</div>
                <div class="metric-sub">Pending review</div>
            </div>
            <div class="metric-card">
                <div class="metric-title">Disetujui</div>
                <div class="metric-value" style="color: var(--success, #10b981);" id="otMetricApproved">0</div>
                <div class="metric-sub">Approved overtime</div>
            </div>
            <div class="metric-card">
                <div class="metric-title">Ditolak</div>
                <div class="metric-value" style="color: var(--danger, #ef4444);" id="otMetricRejected">0</div>
                <div class="metric-sub">Rejected requests</div>
            </div>
            <div class="metric-card">
                <div class="metric-title">Total Jam Lembur</div>
                <div class="metric-value" style="color: #6366f1;" id="otMetricTotalHours">0 Jam</div>
                <div class="metric-sub">Approved duration</div>
            </div>
        </div>

        <!-- FILTER BAR -->
        <div class="glass-panel" style="padding: 1rem; margin-bottom: 1.25rem; display: flex; gap: 1rem; flex-wrap: wrap; align-items: flex-end;">
            <div style="flex: 1; min-width: 140px;">
                <label style="display: block; font-size: 0.75rem; color: var(--text-muted); margin-bottom: 0.35rem;">Status</label>
                <select id="otFilterStatus" class="form-control" onchange="loadOvertimeRequestsData()" style="width: 100%; background: var(--bg-base); border: 1px solid var(--border-color); color: #fff; padding: 0.5rem; border-radius: 0.5rem;">
                    <option value="">Semua Status</option>
                    <option value="SUBMITTED">Menunggu Persetujuan</option>
                    <option value="APPROVED">Disetujui</option>
                    <option value="REJECTED">Ditolak</option>
                    <option value="CANCELLED">Dibatalkan</option>
                </select>
            </div>
            <div style="flex: 1; min-width: 140px;">
                <label style="display: block; font-size: 0.75rem; color: var(--text-muted); margin-bottom: 0.35rem;">Dari Tanggal</label>
                <input type="date" id="otFilterFrom" class="form-control" onchange="loadOvertimeRequestsData()" style="width: 100%; background: var(--bg-base); border: 1px solid var(--border-color); color: #fff; padding: 0.5rem; border-radius: 0.5rem;" />
            </div>
            <div style="flex: 1; min-width: 140px;">
                <label style="display: block; font-size: 0.75rem; color: var(--text-muted); margin-bottom: 0.35rem;">Sampai Tanggal</label>
                <input type="date" id="otFilterTo" class="form-control" onchange="loadOvertimeRequestsData()" style="width: 100%; background: var(--bg-base); border: 1px solid var(--border-color); color: #fff; padding: 0.5rem; border-radius: 0.5rem;" />
            </div>
        </div>

        <!-- DATA TABLE -->
        <div class="table-responsive glass-panel">
            <table class="data-table" id="overtimeRequestsTable">
                <thead>
                    <tr>
                        <th>ID</th>
                        <th>Karyawan</th>
                        <th>Tanggal</th>
                        <th>Waktu Lembur</th>
                        <th>Pengajuan</th>
                        <th>Disetujui</th>
                        <th>Alasan & Referensi Tugas</th>
                        <th>Dokumen</th>
                        <th>Status</th>
                        <th>Aksi</th>
                    </tr>
                </thead>
                <tbody id="overtimeRequestsTableBody">
                    <tr><td colspan="10" class="loading-td"><div class="spinner"></div> Memuat daftar pengajuan lembur...</td></tr>
                </tbody>
            </table>
        </div>
    </section>

    <!-- MODAL: NEW OVERTIME REQUEST -->
    <div class="modal-overlay" id="newOvertimeRequestModal">
        <div class="modal-card" style="max-width: 550px;">
            <div class="modal-header">
                <h3 class="modal-title">⚡ Ajukan Lembur Baru</h3>
                <button class="modal-close-btn" onclick="closeModal('newOvertimeRequestModal')">✖</button>
            </div>
            <form id="newOvertimeRequestForm" onsubmit="submitNewOvertimeRequest(event)">
                <div class="form-row">
                    <label>Tanggal Lembur *</label>
                    <input type="date" id="newOtDate" required class="form-control" style="width: 100%;" />
                </div>

                <div style="display: flex; gap: 0.75rem;" class="form-row">
                    <div style="flex: 1;">
                        <label>Jam Mulai *</label>
                        <input type="time" id="newOtStartTime" required class="form-control" style="width: 100%;" />
                    </div>
                    <div style="flex: 1;">
                        <label>Jam Selesai *</label>
                        <input type="time" id="newOtEndTime" required class="form-control" style="width: 100%;" />
                    </div>
                </div>

                <div class="form-row">
                    <label>Referensi Tugas / Proyek (Opsional)</label>
                    <input type="text" id="newOtTaskRef" placeholder="Contoh: Deployment Sprint 12 / Maintenance Server" class="form-control" style="width: 100%;" />
                </div>

                <div class="form-row">
                    <label>Uraian Alasan / Pekerjaan Lembur *</label>
                    <textarea id="newOtReason" required minlength="5" rows="3" class="form-control" style="width: 100%;" placeholder="Jelaskan kebutuhan mendesak lembur dan rincian pekerjaan..."></textarea>
                </div>

                <div class="form-row">
                    <label>Dokumen / Surat Perintah Lembur (Opsional, PDF/JPG/PNG maks 5MB)</label>
                    <input type="file" id="newOtAttachment" accept=".pdf,.jpg,.jpeg,.png,.webp" class="form-control" style="width: 100%;" />
                </div>

                <div style="display: flex; justify-content: flex-end; gap: 0.75rem; margin-top: 1.5rem;">
                    <button type="button" class="btn-secondary" onclick="closeModal('newOvertimeRequestModal')">Batal</button>
                    <button type="submit" class="btn-primary" id="btnSubmitNewOt">Kirim Pengajuan Lembur</button>
                </div>
            </form>
        </div>
    </div>

    <!-- MODAL: APPROVE OVERTIME (WITH DURATION SPECIFICATION) -->
    <div class="modal-overlay" id="approveOvertimeModal">
        <div class="modal-card" style="max-width: 480px;">
            <div class="modal-header">
                <h3 class="modal-title">✅ Setujui Pengajuan Lembur</h3>
                <button class="modal-close-btn" onclick="closeModal('approveOvertimeModal')">✖</button>
            </div>
            <form id="approveOvertimeForm" onsubmit="submitApproveOvertime(event)">
                <input type="hidden" id="approveOtId" />
                <p style="font-size: 0.85rem; color: var(--text-muted); margin-bottom: 1rem;">
                    Konfirmasikan durasi lembur yang disetujui (dalam menit). Durasi tidak boleh melebihi durasi yang diajukan.
                </p>
                <div class="form-row">
                    <label>Durasi Diajukan (Menit)</label>
                    <input type="number" id="approveOtRequestedMinutes" readonly class="form-control" style="width: 100%; background: var(--border-color);" />
                </div>
                <div class="form-row">
                    <label>Durasi Disetujui (Menit) *</label>
                    <input type="number" id="approveOtMinutesInput" required min="1" class="form-control" style="width: 100%;" />
                </div>
                <div style="display: flex; justify-content: flex-end; gap: 0.75rem; margin-top: 1rem;">
                    <button type="button" class="btn-secondary" onclick="closeModal('approveOvertimeModal')">Batal</button>
                    <button type="submit" class="btn-primary" id="btnSubmitApproveOt">Setujui Lembur</button>
                </div>
            </form>
        </div>
    </div>

    <!-- MODAL: REJECT OVERTIME -->
    <div class="modal-overlay" id="rejectOvertimeModal">
        <div class="modal-card" style="max-width: 480px;">
            <div class="modal-header">
                <h3 class="modal-title">❌ Tolak Pengajuan Lembur</h3>
                <button class="modal-close-btn" onclick="closeModal('rejectOvertimeModal')">✖</button>
            </div>
            <form id="rejectOvertimeForm" onsubmit="submitRejectOvertime(event)">
                <input type="hidden" id="rejectOtId" />
                <p style="font-size: 0.85rem; color: var(--text-muted); margin-bottom: 1rem;">
                    Berikan alasan penolakan pengajuan lembur ini secara objektif.
                </p>
                <div class="form-row">
                    <label>Alasan Penolakan *</label>
                    <textarea id="rejectOtReasonInput" required minlength="3" rows="3" class="form-control" style="width: 100%;" placeholder="Contoh: Target pekerjaan dapat diselesaikan pada jam kerja normal..."></textarea>
                </div>
                <div style="display: flex; justify-content: flex-end; gap: 0.75rem; margin-top: 1rem;">
                    <button type="button" class="btn-secondary" onclick="closeModal('rejectOvertimeModal')">Batal</button>
                    <button type="submit" class="btn-primary" style="background: var(--danger); border-color: var(--danger);" id="btnSubmitRejectOt">Tolak Lembur</button>
                </div>
            </form>
        </div>
    </div>

    <!-- MODAL: PHOTO VIEWER (SECURE PRIVATE STREAM) -->
    <div class="modal-overlay" id="fieldPhotoModal">
        <div class="modal-card" style="max-width: 500px;">
            <div class="modal-header">
                <h3 class="modal-title">🖼️ Foto Bukti Presensi Lapangan</h3>
                <button class="modal-close-btn" onclick="closeModal('fieldPhotoModal')">✖</button>
            </div>
            <div style="text-align: center; padding: 1rem 0;" id="fieldPhotoModalBody">
                <div class="spinner"></div> Mengambil foto secara aman...
            </div>
        </div>
    </div>

    <!-- MODAL: MANUAL OVERRIDE -->
    <div class="modal-overlay" id="fieldOverrideModal">
        <div class="modal-card" style="max-width: 500px;">
            <div class="modal-header">
                <h3 class="modal-title">⚖️ Manual Override Presensi Lapangan</h3>
                <button class="modal-close-btn" onclick="closeModal('fieldOverrideModal')">✖</button>
            </div>
            <form id="fieldOverrideForm" onsubmit="submitFieldOverride(event)">
                <input type="hidden" id="overrideEvidenceId" />
                <p style="font-size: 0.85rem; color: var(--text-muted); margin-bottom: 1rem;">
                    Sebagai HRD / Administrator, Anda dapat memverifikasi presensi lapangan yang berada di luar geofence atau berakurasi rendah. Tindakan ini akan dicatat dalam audit log.
                </p>
                <div style="margin-bottom: 1.25rem;">
                    <label style="display: block; font-size: 0.8rem; color: var(--text-muted); margin-bottom: 0.4rem;">Alasan Override *</label>
                    <textarea id="overrideReasonInput" required minlength="5" rows="3" class="form-control" style="width: 100%; background: var(--bg-base); border: 1px solid var(--border-color); color: #fff; padding: 0.6rem; border-radius: 0.5rem; font-size: 0.85rem;" placeholder="Contoh: Karyawan ditugaskan inspeksi di luar batas gerbang proyek..."></textarea>
                </div>
                <div style="display: flex; justify-content: flex-end; gap: 0.75rem;">
                    <button type="button" class="btn-secondary" onclick="closeModal('fieldOverrideModal')">Batal</button>
                    <button type="submit" class="btn-primary" id="btnSubmitOverride">Terapkan Override</button>
                </div>
            </form>
        </div>
    </div>

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
<div class="modal-overlay" id="taskDetailModal"><div class="modal-card"><div class="modal-header"><h3 class="modal-title">Task Detail</h3><button class="modal-close-btn" onclick="closeModal('taskDetailModal')">✖</button></div><div id="taskDetailContent" class="section-desc">Memuat task...</div></div></div>

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

<!-- ------------------------------------------ -->
<!-- INTERNSHIP MANAGEMENT MODALS (SPRINT 4)   -->
<!-- ------------------------------------------ -->

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

<!-- SPRINT 6 MODALS: ACCESS PROVISIONING, CREDENTIAL CENTER & E-MONEY -->

<!-- MODAL: AJUKAN PERMINTAAN HAK AKSES -->
<div class="modal-overlay" id="modalAddAccessRequest">
    <div class="modal-card" style="max-width: 600px;">
        <div class="modal-header">
            <h3 class="modal-title">🔑 Pengajuan Hak Akses Pintu Fisik</h3>
            <button class="modal-close-btn" onclick="closeModal('modalAddAccessRequest')">✖</button>
        </div>
        <form id="formAddAccessRequest" onsubmit="submitAccessRequest(event)">
            <div class="form-row">
                <label>Karyawan Pemohon / Penerima Hak Akses</label>
                <select id="accessReqEmployeeId" style="width: 100%; background: var(--card-bg); border: 1px solid var(--border-color); color: #fff; padding: 0.65rem; border-radius: 0.6rem;">
                    <option value="">-- Pilih Karyawan Terdaftar --</option>
                </select>
            </div>
            <div class="form-row">
                <label>Profil Hak Akses Reusable</label>
                <select id="accessReqProfileId" onchange="onAccessProfileSelected()" style="width: 100%; background: var(--card-bg); border: 1px solid var(--border-color); color: #fff; padding: 0.65rem; border-radius: 0.6rem;">
                    <option value="">-- Pilih Profil Akses (Opsional) --</option>
                </select>
            </div>
            <div style="display: grid; grid-template-columns: 1fr 1fr; gap: 0.75rem;">
                <div class="form-row">
                    <label>Gedung Operasional</label>
                    <input type="text" id="accessReqBuilding" value="Kantor Pusat PKP" required>
                </div>
                <div class="form-row">
                    <label>Berlaku Mulai</label>
                    <input type="date" id="accessReqValidFrom" required>
                </div>
            </div>
            <div class="form-row">
                <label>Berlaku Hingga (Kosongkan jika Permanen)</label>
                <input type="date" id="accessReqValidUntil">
            </div>
            <div class="form-row">
                <label>Alasan Bisnis / Justifikasi Kebutuhan Akses</label>
                <textarea id="accessReqReason" rows="2" style="width: 100%; background: var(--card-bg); border: 1px solid var(--border-color); color: #fff; padding: 0.65rem; border-radius: 0.6rem;" placeholder="Keperluan operasional harian..." required></textarea>
            </div>
            <div style="display: flex; justify-content: flex-end; gap: 0.75rem; margin-top: 1.25rem;">
                <button type="button" class="btn-secondary" onclick="closeModal('modalAddAccessRequest')">Batal</button>
                <button type="submit" class="btn-primary">Kirim Permohonan Akses</button>
            </div>
        </form>
    </div>
</div>

<!-- MODAL: APPROVE PERMINTAAN AKSES -->
<div class="modal-overlay" id="modalApproveAccessRequest">
    <div class="modal-card">
        <div class="modal-header">
            <h3 class="modal-title">✓ Setujui Permohonan Hak Akses</h3>
            <button class="modal-close-btn" onclick="closeModal('modalApproveAccessRequest')">✖</button>
        </div>
        <form id="formApproveAccessRequest" onsubmit="submitApproveAccessRequest(event)">
            <input type="hidden" id="approveReqId">
            <p style="font-size: 0.85rem; color: var(--text-muted); margin-bottom: 0.75rem;">
                Persetujuan akan memicu pembuatan kredensial otomatis dan menjadwalkan sinkronisasi hardware terminal secara asinkron.
            </p>
            <div class="form-row">
                <label>Catatan Persetujuan (Opsional)</label>
                <textarea id="approveReqNotes" rows="2" style="width: 100%; background: var(--card-bg); border: 1px solid var(--border-color); color: #fff; padding: 0.65rem; border-radius: 0.6rem;" placeholder="Disetujui untuk penugasan operasional..."></textarea>
            </div>
            <div style="display: flex; justify-content: flex-end; gap: 0.75rem; margin-top: 1.25rem;">
                <button type="button" class="btn-secondary" onclick="closeModal('modalApproveAccessRequest')">Batal</button>
                <button type="submit" class="btn-primary" style="background: #10b981; border-color: #059669;">Setujui & Jadwalkan Sync</button>
            </div>
        </form>
    </div>
</div>

<!-- MODAL: REJECT PERMINTAAN AKSES -->
<div class="modal-overlay" id="modalRejectAccessRequest">
    <div class="modal-card">
        <div class="modal-header">
            <h3 class="modal-title">✕ Tolak Permohonan Hak Akses</h3>
            <button class="modal-close-btn" onclick="closeModal('modalRejectAccessRequest')">✖</button>
        </div>
        <form id="formRejectAccessRequest" onsubmit="submitRejectAccessRequest(event)">
            <input type="hidden" id="rejectReqId">
            <div class="form-row">
                <label>Alasan Penolakan</label>
                <textarea id="rejectReqReason" rows="3" style="width: 100%; background: var(--card-bg); border: 1px solid var(--border-color); color: #fff; padding: 0.65rem; border-radius: 0.6rem;" placeholder="Alasan penolakan pengajuan..." required></textarea>
            </div>
            <div style="display: flex; justify-content: flex-end; gap: 0.75rem; margin-top: 1.25rem;">
                <button type="button" class="btn-secondary" onclick="closeModal('modalRejectAccessRequest')">Batal</button>
                <button type="submit" class="btn-primary" style="background: #ef4444; border-color: #dc2626;">Tolak Permohonan</button>
            </div>
        </form>
    </div>
</div>

<!-- MODAL: BUAT PROFIL AKSES BARU -->
<div class="modal-overlay" id="modalAddAccessProfile">
    <div class="modal-card">
        <div class="modal-header">
            <h3 class="modal-title">🛡️ Tambah Profil Hak Akses</h3>
            <button class="modal-close-btn" onclick="closeModal('modalAddAccessProfile')">✖</button>
        </div>
        <form id="formAddAccessProfile" onsubmit="submitAccessProfile(event)">
            <div class="form-row">
                <label>Kode Profil (Singkat & Unik)</label>
                <input type="text" id="profCode" placeholder="contoh: ENG_RND_ROOM" style="text-transform: uppercase;" required>
            </div>
            <div class="form-row">
                <label>Nama Profil Akses</label>
                <input type="text" id="profName" placeholder="Akses Lab R&D dan Hardware Engineering" required>
            </div>
            <div style="display: grid; grid-template-columns: 1fr 1fr; gap: 0.75rem;">
                <div class="form-row">
                    <label>Gedung</label>
                    <input type="text" id="profBuilding" value="Kantor Pusat PKP" required>
                </div>
                <div class="form-row">
                    <label>Jadwal Akses</label>
                    <select id="profSchedule">
                        <option value="BUSINESS_HOURS">Jam Kerja Reguler (08:00 - 18:00)</option>
                        <option value="ALL_DAY">24 Jam Penuh (All-Day)</option>
                        <option value="CUSTOM_WINDOW">Jadwal Khusus</option>
                    </select>
                </div>
            </div>
            <div class="form-row">
                <label>Deskripsi</label>
                <textarea id="profDescription" rows="2" style="width: 100%; background: var(--card-bg); border: 1px solid var(--border-color); color: #fff; padding: 0.65rem; border-radius: 0.6rem;" placeholder="Deskripsi peruntukan akses..."></textarea>
            </div>
            <div style="display: flex; justify-content: flex-end; gap: 0.75rem; margin-top: 1.25rem;">
                <button type="button" class="btn-secondary" onclick="closeModal('modalAddAccessProfile')">Batal</button>
                <button type="submit" class="btn-primary">Simpan Profil Akses</button>
            </div>
        </form>
    </div>
</div>

<!-- MODAL: TERBITKAN KREDENSIAL BARU -->
<div class="modal-overlay" id="modalAddCredential">
    <div class="modal-card">
        <div class="modal-header">
            <h3 class="modal-title">💳 Terbitkan Kredensial Akses</h3>
            <button class="modal-close-btn" onclick="closeModal('modalAddCredential')">✖</button>
        </div>
        <form id="formAddCredential" onsubmit="submitCredential(event)">
            <div style="background: rgba(56, 189, 248, 0.08); border: 1px solid rgba(56, 189, 248, 0.25); border-radius: 0.6rem; padding: 0.75rem; margin-bottom: 1rem; font-size: 0.8rem; color: #38bdf8;">
                🔒 <strong>Prinsip Keamanan:</strong> Sistem hanya menyimpan status pendaftaran & identifikasi aman terenkripsi (Masked). Tidak ada template mentah biometrik yang disimpan pada server.
            </div>
            <div class="form-row">
                <label>Karyawan Terkait</label>
                <select id="crdEmployeeId" style="width: 100%; background: var(--card-bg); border: 1px solid var(--border-color); color: #fff; padding: 0.65rem; border-radius: 0.6rem;">
                    <option value="">-- Pilih Karyawan --</option>
                </select>
            </div>
            <div style="display: grid; grid-template-columns: 1fr 1fr; gap: 0.75rem;">
                <div class="form-row">
                    <label>Tipe Kredensial</label>
                    <select id="crdType" required>
                        <option value="CARD">RFID Smart Card</option>
                        <option value="FINGERPRINT_STATUS">Status Sidik Jari</option>
                        <option value="FACE_STATUS">Status Wajah Biometrik</option>
                        <option value="QR">QR Code Dinamis</option>
                    </select>
                </div>
                <div class="form-row">
                    <label>Nomor Fisik Kartu / ID</label>
                    <input type="text" id="crdCardNumber" placeholder="contoh: 1234567890">
                </div>
            </div>
            <div class="form-row">
                <label>Catatan Tambahan</label>
                <textarea id="crdNotes" rows="2" style="width: 100%; background: var(--card-bg); border: 1px solid var(--border-color); color: #fff; padding: 0.65rem; border-radius: 0.6rem;" placeholder="Catatan penerbitan kredensial..."></textarea>
            </div>
            <div style="display: flex; justify-content: flex-end; gap: 0.75rem; margin-top: 1.25rem;">
                <button type="button" class="btn-secondary" onclick="closeModal('modalAddCredential')">Batal</button>
                <button type="submit" class="btn-primary">Terbitkan Kredensial</button>
            </div>
        </form>
    </div>
</div>

<!-- MODAL: CABUT KREDENSIAL -->
<div class="modal-overlay" id="modalRevokeCredential">
    <div class="modal-card">
        <div class="modal-header">
            <h3 class="modal-title">🚫 Cabut Kredensial Akses</h3>
            <button class="modal-close-btn" onclick="closeModal('modalRevokeCredential')">✖</button>
        </div>
        <form id="formRevokeCredential" onsubmit="submitRevokeCredential(event)">
            <input type="hidden" id="revokeCrdId">
            <p style="font-size: 0.85rem; color: var(--text-muted); margin-bottom: 0.75rem;">
                Pencabutan kredensial akan mengubah status menjadi REVOKED dan secara otomatis menjadwalkan pencabutan akses di seluruh terminal pintu ISAPI.
            </p>
            <div class="form-row">
                <label>Alasan Pencabutan Kredensial</label>
                <textarea id="revokeCrdReason" rows="2" style="width: 100%; background: var(--card-bg); border: 1px solid var(--border-color); color: #fff; padding: 0.65rem; border-radius: 0.6rem;" placeholder="Kartu hilang / karyawan nonaktif / pergantian kartu..." required></textarea>
            </div>
            <div style="display: flex; justify-content: flex-end; gap: 0.75rem; margin-top: 1.25rem;">
                <button type="button" class="btn-secondary" onclick="closeModal('modalRevokeCredential')">Batal</button>
                <button type="submit" class="btn-primary" style="background: #ef4444; border-color: #dc2626;">Cabut Kredensial</button>
            </div>
        </form>
    </div>
</div>

<!-- MODAL: REGISTRASI E-MONEY -->
<div class="modal-overlay" id="modalAddEmoney">
    <div class="modal-card">
        <div class="modal-header">
            <h3 class="modal-title">🏧 Registrasi Kartu E-Money (Admin)</h3>
            <button class="modal-close-btn" onclick="closeModal('modalAddEmoney')">✖</button>
        </div>
        <form id="formAddEmoney" onsubmit="submitEmoneyCard(event)">
            <div style="background: rgba(245, 158, 11, 0.08); border: 1px solid rgba(245, 158, 11, 0.25); border-radius: 0.6rem; padding: 0.75rem; margin-bottom: 1rem; font-size: 0.8rem; color: #fbbf24;">
                🛡️ <strong>Kerahasiaan Finansial:</strong> Hanya diperuntukkan untuk mencatat instrumen kartu fisik perusahaan. Dilarang menyimpan PIN, CVV, atau credential pembayaran perbankan.
            </div>
            <div class="form-row">
                <label>Pemegang Kartu (Karyawan)</label>
                <select id="emnEmployeeId" style="width: 100%; background: var(--card-bg); border: 1px solid var(--border-color); color: #fff; padding: 0.65rem; border-radius: 0.6rem;">
                    <option value="">-- Tersedia / Belum Ditetapkan --</option>
                </select>
            </div>
            <div style="display: grid; grid-template-columns: 1fr 1fr; gap: 0.75rem;">
                <div class="form-row">
                    <label>Penerbit / Provider</label>
                    <select id="emnProvider" required>
                        <option value="MANDIRI_EMONEY">Mandiri e-Money</option>
                        <option value="BCA_FLAZZ">BCA Flazz</option>
                        <option value="BNI_TAPCASH">BNI TapCash</option>
                        <option value="BRI_BRIZZI">BRI Brizzi</option>
                        <option value="JAKCARD">JakCard</option>
                        <option value="OTHER">Lainnya</option>
                    </select>
                </div>
                <div class="form-row">
                    <label>Nomor Kartu (Min 8 Digit)</label>
                    <input type="text" id="emnCardNumber" placeholder="contoh: 603212345678" required>
                </div>
            </div>
            <div class="form-row">
                <label>Catatan Penggunaan</label>
                <textarea id="emnNotes" rows="2" style="width: 100%; background: var(--card-bg); border: 1px solid var(--border-color); color: #fff; padding: 0.65rem; border-radius: 0.6rem;" placeholder="Operasional dinas, tiket parkir kantor..."></textarea>
            </div>
            <div style="display: flex; justify-content: flex-end; gap: 0.75rem; margin-top: 1.25rem;">
                <button type="button" class="btn-secondary" onclick="closeModal('modalAddEmoney')">Batal</button>
                <button type="submit" class="btn-primary">Daftarkan Kartu</button>
            </div>
        </form>
    </div>
</div>

<!-- MODAL: TAMBAH / EDIT ASET -->
<div class="modal-overlay" id="modalAddAsset">
    <div class="modal-card" style="max-width: 650px;">
        <div class="modal-header">
            <h3 class="modal-title" id="assetModalTitle">Daftarkan Aset Baru</h3>
            <button class="modal-close-btn" onclick="closeModal('modalAddAsset')">✖</button>
        </div>
        <form id="formAddAsset" onsubmit="submitAssetForm(event)">
            <input type="hidden" id="assetEditId">
            <div style="display: grid; grid-template-columns: 1fr 1fr; gap: 0.75rem;">
                <div class="form-row">
                    <label>Nama Aset / Perangkat *</label>
                    <input type="text" id="assetFormName" placeholder="MacBook Pro 16 M3 Max" required>
                </div>
                <div class="form-row">
                    <label>Kategori Inventaris *</label>
                    <select id="assetFormCategory" required></select>
                </div>
            </div>
            <div style="display: grid; grid-template-columns: 1fr 1fr 1fr; gap: 0.75rem;">
                <div class="form-row">
                    <label>Brand / Merk</label>
                    <input type="text" id="assetFormBrand" placeholder="Apple / Lenovo">
                </div>
                <div class="form-row">
                    <label>Model / Tipe</label>
                    <input type="text" id="assetFormModel" placeholder="T14 Gen 4">
                </div>
                <div class="form-row">
                    <label>Nomor Seri (Serial No)</label>
                    <input type="text" id="assetFormSerial" placeholder="C02ABC123XYZ">
                </div>
            </div>
            <div style="display: grid; grid-template-columns: 1fr 1fr; gap: 0.75rem;">
                <div class="form-row">
                    <label>Gedung Operasional</label>
                    <input type="text" id="assetFormBuilding" placeholder="Kantor Pusat PKP" value="Kantor Pusat PKP">
                </div>
                <div class="form-row">
                    <label>Lokasi / Ruangan</label>
                    <input type="text" id="assetFormLocation" placeholder="Lantai 3 - IT Room">
                </div>
            </div>
            <div style="display: grid; grid-template-columns: 1fr 1fr 1fr; gap: 0.75rem;">
                <div class="form-row">
                    <label>Kondisi Aset</label>
                    <select id="assetFormCondition">
                        <option value="NEW">NEW (Baru)</option>
                        <option value="GOOD" selected>GOOD (Bagus / Siap Pakai)</option>
                        <option value="FAIR">FAIR (Cukup Baik)</option>
                        <option value="POOR">POOR (Butuh Perbaikan)</option>
                        <option value="DAMAGED">DAMAGED (Rusak)</option>
                    </select>
                </div>
                <div class="form-row">
                    <label>Tgl Pembelian</label>
                    <input type="date" id="assetFormPurchaseDate">
                </div>
                <div class="form-row">
                    <label>Batas Garansi (Warranty End)</label>
                    <input type="date" id="assetFormWarrantyEnd">
                </div>
            </div>
            <div class="form-row">
                <label>Catatan Inventaris</label>
                <textarea id="assetFormNotes" rows="2" placeholder="Informasi kelengkapan, garansi distributor, aset dinas..."></textarea>
            </div>
            <div style="display: flex; justify-content: flex-end; gap: 0.75rem; margin-top: 1.25rem;">
                <button type="button" class="btn-secondary" onclick="closeModal('modalAddAsset')">Batal</button>
                <button type="submit" class="btn-primary" id="btnSubmitAsset">Simpan Aset</button>
            </div>
        </form>
    </div>
</div>

<!-- MODAL: ALOKASI / HANDOVER ASET -->
<div class="modal-overlay" id="modalAssignAsset">
    <div class="modal-card" style="max-width: 580px;">
        <div class="modal-header">
            <h3 class="modal-title">📋 Alokasi & Penyerahan Aset (Handover)</h3>
            <button class="modal-close-btn" onclick="closeModal('modalAssignAsset')">✖</button>
        </div>
        <form id="formAssignAsset" onsubmit="submitAssignAsset(event)">
            <div class="form-row">
                <label>Pilih Aset Tersedia *</label>
                <select id="asgAssetSelect" required></select>
            </div>
            <div class="form-row">
                <label>Penerima Aset (Karyawan Aktif)</label>
                <select id="asgEmployeeSelect"></select>
            </div>
            <div class="form-row">
                <label>Atau Pemagang (Intern Aktif)</label>
                <select id="asgInternSelect"></select>
            </div>
            <div style="display: grid; grid-template-columns: 1fr 1fr; gap: 0.75rem;">
                <div class="form-row">
                    <label>Target Tanggal Pengembalian</label>
                    <input type="date" id="asgExpectedReturn">
                </div>
                <div class="form-row">
                    <label>Kondisi Saat Keluar</label>
                    <select id="asgConditionOut">
                        <option value="NEW">NEW (Baru)</option>
                        <option value="GOOD" selected>GOOD (Baik)</option>
                        <option value="FAIR">FAIR (Cukup)</option>
                    </select>
                </div>
            </div>
            <div class="form-row">
                <label>Catatan Serah Terima (Handover Notes)</label>
                <textarea id="asgNotes" rows="2" placeholder="Kelengkapan adaptor charger, tas ransel, mouse, kartu akses dinas..."></textarea>
            </div>
            <div style="display: flex; justify-content: flex-end; gap: 0.75rem; margin-top: 1.25rem;">
                <button type="button" class="btn-secondary" onclick="closeModal('modalAssignAsset')">Batal</button>
                <button type="submit" class="btn-primary">Konfirmasi Penyerahan Aset</button>
            </div>
        </form>
    </div>
</div>

<!-- MODAL: PENGEMBALIAN ASET (RETURN) -->
<div class="modal-overlay" id="modalReturnAsset">
    <div class="modal-card" style="max-width: 500px;">
        <div class="modal-header">
            <h3 class="modal-title">🔄 Pengembalian Aset (Asset Return)</h3>
            <button class="modal-close-btn" onclick="closeModal('modalReturnAsset')">✖</button>
        </div>
        <form id="formReturnAsset" onsubmit="submitReturnAsset(event)">
            <input type="hidden" id="retAssignmentId">
            <div class="form-row">
                <label>Kondisi Aset Saat Dikembalikan *</label>
                <select id="retConditionIn" required>
                    <option value="GOOD" selected>GOOD (Normal / Bagus)</option>
                    <option value="FAIR">FAIR (Ada baret wajar / pemakaian)</option>
                    <option value="POOR">POOR (Perlu pembersihan / servis)</option>
                    <option value="DAMAGED">DAMAGED (Rusak / Butuh perbaikan teknis)</option>
                </select>
            </div>
            <div class="form-row">
                <label>Catatan Pemeriksaan Pengembalian</label>
                <textarea id="retNotes" rows="3" placeholder="Kelengkapan charger, kondisi fisik, layar, keyboard..."></textarea>
            </div>
            <div style="display: flex; justify-content: flex-end; gap: 0.75rem; margin-top: 1.25rem;">
                <button type="button" class="btn-secondary" onclick="closeModal('modalReturnAsset')">Batal</button>
                <button type="submit" class="btn-primary">Proses Pengembalian</button>
            </div>
        </form>
    </div>
</div>

<!-- MODAL: BUKA PEMELIHARAAN / SERVIS -->
<div class="modal-overlay" id="modalMaintenance">
    <div class="modal-card" style="max-width: 550px;">
        <div class="modal-header">
            <h3 class="modal-title">🔧 Buka Tiket Pemeliharaan / Perbaikan</h3>
            <button class="modal-close-btn" onclick="closeModal('modalMaintenance')">✖</button>
        </div>
        <form id="formMaintenance" onsubmit="submitMaintenance(event)">
            <div class="form-row">
                <label>Pilih Aset *</label>
                <select id="mntAssetSelect" required></select>
            </div>
            <div style="display: grid; grid-template-columns: 1fr 1fr; gap: 0.75rem;">
                <div class="form-row">
                    <label>Tipe Pemeliharaan *</label>
                    <select id="mntType" required>
                        <option value="PREVENTIVE">PREVENTIVE (Rutin / Berkala)</option>
                        <option value="REPAIR">REPAIR (Perbaikan Kerusakan)</option>
                        <option value="INSPECTION">INSPECTION (Pemeriksaan Teknis)</option>
                        <option value="WARRANTY">WARRANTY (Klaim Garansi Vendor)</option>
                    </select>
                </div>
                <div class="form-row">
                    <label>Estimasi Biaya (IDR)</label>
                    <input type="number" id="mntCost" placeholder="0" min="0">
                </div>
            </div>
            <div class="form-row">
                <label>Vendor / Bengkel / Teknisi Servis</label>
                <input type="text" id="mntVendor" placeholder="Contoh: Service Center Resmi Lenovo">
            </div>
            <div class="form-row">
                <label>Deskripsi Kendala & Rencana Servis *</label>
                <textarea id="mntIssue" rows="3" placeholder="Keyboard tidak merespons, baterai drop, instalasi firmware..." required></textarea>
            </div>
            <div style="display: flex; justify-content: flex-end; gap: 0.75rem; margin-top: 1.25rem;">
                <button type="button" class="btn-secondary" onclick="closeModal('modalMaintenance')">Batal</button>
                <button type="submit" class="btn-primary">Buka Tiket Servis</button>
            </div>
        </form>
    </div>
</div>

<!-- MODAL: SELESAIKAN PEMELIHARAAN -->
<div class="modal-overlay" id="modalCompleteMaintenance">
    <div class="modal-card" style="max-width: 500px;">
        <div class="modal-header">
            <h3 class="modal-title">✅ Selesaikan Tiket Servis</h3>
            <button class="modal-close-btn" onclick="closeModal('modalCompleteMaintenance')">✖</button>
        </div>
        <form id="formCompleteMnt" onsubmit="submitCompleteMaintenance(event)">
            <input type="hidden" id="compMntId">
            <div class="form-row">
                <label>Hasil Perbaikan / Tindakan *</label>
                <textarea id="compMntResult" rows="3" placeholder="Penggantian suku cadang selesai, perangkat berfungsi normal..." required></textarea>
            </div>
            <div style="display: grid; grid-template-columns: 1fr 1fr; gap: 0.75rem;">
                <div class="form-row">
                    <label>Kondisi Aset Akhir</label>
                    <select id="compMntCondition">
                        <option value="GOOD" selected>GOOD (Siap Pakai)</option>
                        <option value="NEW">NEW (Seperti Baru)</option>
                        <option value="FAIR">FAIR (Cukup)</option>
                    </select>
                </div>
                <div class="form-row">
                    <label>Biaya Akhir (IDR)</label>
                    <input type="number" id="compMntCost" placeholder="0" min="0">
                </div>
            </div>
            <div style="display: flex; justify-content: flex-end; gap: 0.75rem; margin-top: 1.25rem;">
                <button type="button" class="btn-secondary" onclick="closeModal('modalCompleteMaintenance')">Batal</button>
                <button type="submit" class="btn-primary">Selesaikan & Kembalikan ke Inventaris</button>
            </div>
        </form>
    </div>
</div>

<!-- MODAL: LAPOR INSIDEN ASET -->
<div class="modal-overlay" id="modalIncident">
    <div class="modal-card" style="max-width: 550px;">
        <div class="modal-header">
            <h3 class="modal-title">⚠️ Laporkan Insiden Aset</h3>
            <button class="modal-close-btn" onclick="closeModal('modalIncident')">✖</button>
        </div>
        <form id="formIncident" onsubmit="submitIncident(event)">
            <div class="form-row">
                <label>Pilih Aset Terkait *</label>
                <select id="incAssetSelect" required></select>
            </div>
            <div style="display: grid; grid-template-columns: 1fr 1fr; gap: 0.75rem;">
                <div class="form-row">
                    <label>Jenis Insiden *</label>
                    <select id="incType" required>
                        <option value="DAMAGED">DAMAGED (Rusak Fisik/Jatuh)</option>
                        <option value="LOST">LOST (Hilang / Tertinggal)</option>
                        <option value="STOLEN">STOLEN (Dicuri / Kehilangan)</option>
                        <option value="MISSING_ACCESSORY">MISSING_ACCESSORY (Aksesoris Hilang)</option>
                        <option value="OTHER">OTHER (Lain-Lain)</option>
                    </select>
                </div>
                <div class="form-row">
                    <label>Tanggal Insiden</label>
                    <input type="date" id="incDate">
                </div>
            </div>
            <div class="form-row">
                <label>Lokasi Kejadian</label>
                <input type="text" id="incLocation" placeholder="Contoh: Kantor Cabang, Kendaraan Operasional, Lokasi Proyek">
            </div>
            <div class="form-row">
                <label>Kronologi / Deskripsi Kejadian *</label>
                <textarea id="incDescription" rows="3" placeholder="Jelaskan secara rinci kronologi kejadian insiden..." required></textarea>
            </div>
            <div style="display: flex; justify-content: flex-end; gap: 0.75rem; margin-top: 1.25rem;">
                <button type="button" class="btn-secondary" onclick="closeModal('modalIncident')">Batal</button>
                <button type="submit" class="btn-primary" style="background: #ef4444; border-color: #dc2626;">Kirim Laporan Insiden</button>
            </div>
        </form>
    </div>
</div>

<!-- MODAL: SELESAIKAN INSIDEN ASET -->
<div class="modal-overlay" id="modalResolveIncident">
    <div class="modal-card" style="max-width: 500px;">
        <div class="modal-header">
            <h3 class="modal-title">🛡️ Selesaikan Laporan Insiden</h3>
            <button class="modal-close-btn" onclick="closeModal('modalResolveIncident')">✖</button>
        </div>
        <form id="formResolveIncident" onsubmit="submitResolveIncident(event)">
            <input type="hidden" id="resIncId">
            <div class="form-row">
                <label>Tindakan Penyelesaian / Resolusi *</label>
                <textarea id="resIncResolution" rows="3" placeholder="Klaim asuransi selesai / surat kehilangan polisi telah dibuat / penggantian unit disetujui..." required></textarea>
            </div>
            <div style="display: flex; justify-content: flex-end; gap: 0.75rem; margin-top: 1.25rem;">
                <button type="button" class="btn-secondary" onclick="closeModal('modalResolveIncident')">Batal</button>
                <button type="submit" class="btn-primary">Tandai Selesai (Resolved)</button>
            </div>
        </form>
    </div>
</div>

<!-- MODAL: HAPUS BUKU / DISPOSAL ASET -->
<div class="modal-overlay" id="modalDisposeAsset">
    <div class="modal-card" style="max-width: 500px;">
        <div class="modal-header">
            <h3 class="modal-title">🗑️ Penghapusan Buku Aset (Disposal)</h3>
            <button class="modal-close-btn" onclick="closeModal('modalDisposeAsset')">✖</button>
        </div>
        <form id="formDisposeAsset" onsubmit="submitDisposeAsset(event)">
            <input type="hidden" id="dispAssetId">
            <div style="background: rgba(239, 68, 68, 0.1); border: 1px solid rgba(239, 68, 68, 0.3); border-radius: 0.6rem; padding: 0.75rem; margin-bottom: 1rem; font-size: 0.8rem; color: #f87171;">
                ⚠️ <strong>Perhatian:</strong> Tindakan ini menandai aset sebagai DISPOSED. Riwayat penugasan, pemeliharaan, dan audit tetap tersimpan permanen di sistem.
            </div>
            <div class="form-row">
                <label>Metode Penghapusan *</label>
                <select id="dispMethod" required>
                    <option value="SCRAP">SCRAP (Dihancurkan / Daur Ulang)</option>
                    <option value="SOLD">SOLD (Dijual sebagai Bekas)</option>
                    <option value="DONATED">DONATED (Dihibahkan / Donasi)</option>
                    <option value="WRITTEN_OFF">WRITTEN_OFF (Habis Masa Manfaat)</option>
                </select>
            </div>
            <div class="form-row">
                <label>Alasan Penghapusan Buku *</label>
                <textarea id="dispReason" rows="3" placeholder="Perangkat usang / rusak total tidak ekonomis untuk diperbaiki..." required></textarea>
            </div>
            <div style="display: flex; justify-content: flex-end; gap: 0.75rem; margin-top: 1.25rem;">
                <button type="button" class="btn-secondary" onclick="closeModal('modalDisposeAsset')">Batal</button>
                <button type="submit" class="btn-primary" style="background: #ef4444; border-color: #dc2626;">Konfirmasi Penghapusan Buku</button>
            </div>
        </form>
    </div>
</div>

<!-- MODAL: ASSET DETAIL / 360 -->
<div class="modal-overlay" id="modalAssetDetail">
    <div class="modal-card" style="max-width: 750px;">
        <div class="modal-header">
            <div>
                <h3 class="modal-title" id="detailAssetTitle">Detail Aset Enterprise</h3>
                <div style="font-size: 0.8rem; color: var(--primary); font-weight: 600;" id="detailAssetCode">AST-CODE</div>
            </div>
            <button class="modal-close-btn" onclick="closeModal('modalAssetDetail')">✖</button>
        </div>
        <div id="assetDetailBody" style="font-size: 0.85rem; color: var(--text-main);">
            <div class="spinner"></div> Memuat rincian aset...
        </div>
        <div style="display: flex; justify-content: flex-end; gap: 0.75rem; margin-top: 1.5rem; border-top: 1px solid var(--border-color); padding-top: 1rem;">
            <button type="button" class="btn-secondary" onclick="closeModal('modalAssetDetail')">Tutup</button>
        </div>
    </div>
</div>

<!-- MODAL: SAFE REMOTE DOOR UNLOCK -->
<div class="modal-overlay" id="remoteUnlockModal" role="dialog" aria-modal="true" aria-labelledby="remoteUnlockTitle">
    <div class="modal-card" style="max-width:520px;">
        <div class="modal-header">
            <div><h3 class="modal-title" id="remoteUnlockTitle">🔓 Konfirmasi Remote Unlock</h3><div class="section-desc" id="remoteUnlockDoorIdentity">Pilih terminal pintu.</div></div>
            <button type="button" class="modal-close-btn" onclick="cancelRemoteUnlock()" aria-label="Tutup">✖</button>
        </div>
        <div class="physical-warning">Perintah ini mengaktifkan relay pintu fisik. Pastikan identitas pintu dan kondisi area sudah diverifikasi sebelum melanjutkan.</div>
        <form id="remoteUnlockForm" onsubmit="event.preventDefault(); confirmRemoteUnlock();" style="margin-top:1rem;">
            <div class="form-row" style="margin-bottom:1rem;">
                <label style="display:block;font-size:0.85rem;color:var(--text-muted);margin-bottom:0.35rem;">Pilih Terminal Pintu *</label>
                <select id="remoteUnlockDoorSelect" class="form-control" required onchange="onRemoteUnlockDoorChange(this.value)" style="width:100%;padding:0.5rem;background:var(--card-bg);border:1px solid var(--border-color);color:#fff;border-radius:0.5rem;">
                </select>
            </div>
            <dl class="door-specs" style="margin-bottom:1rem;">
                <div class="spec-item"><dt class="spec-label">Terminal</dt><dd class="spec-val" id="remoteUnlockDoorCode">-</dd></div>
                <div class="spec-item"><dt class="spec-label">Lokasi</dt><dd class="spec-val" id="remoteUnlockDoorLocation">-</dd></div>
                <div class="spec-item"><dt class="spec-label">Status</dt><dd class="spec-val" id="remoteUnlockDoorStatus">-</dd></div>
            </dl>
            <div class="form-row" style="margin-bottom:1rem;">
                <label style="display:block;font-size:0.85rem;color:var(--text-muted);margin-bottom:0.35rem;">Alasan Pembukaan Remote *</label>
                <textarea id="remoteUnlockReason" class="form-control" rows="3" required placeholder="Contoh: Kunjungan VIP Tamu, Pemeliharaan Darurat, Kartu Akses Tertinggal..." style="width:100%;padding:0.5rem;background:var(--card-bg);border:1px solid var(--border-color);color:#fff;border-radius:0.5rem;box-sizing:border-box;"></textarea>
            </div>
            <div style="display:flex;justify-content:flex-end;gap:.75rem;margin-top:1.25rem;">
                <button type="button" class="btn-secondary" onclick="cancelRemoteUnlock()">Batal</button>
                <button type="submit" class="btn-primary" id="confirmRemoteUnlockButton">Konfirmasi & Buka Pintu</button>
            </div>
        </form>
    </div>
</div>

<!-- MODAL: DYNAMIC FACILITY CONFIGURATION -->
<div class="modal-overlay" id="facilityModal">
    <div class="modal-card" style="max-width:720px;">
        <div class="modal-header"><div><h3 class="modal-title">Facility & Door Configuration</h3><div class="section-desc">Register a building or terminal without changing existing hardware records.</div></div><button class="modal-close-btn" onclick="closeModal('facilityModal')">✖</button></div>
        <div style="display:grid;grid-template-columns:repeat(auto-fit,minmax(260px,1fr));gap:1.25rem;">
            <form id="buildingConfigForm" onsubmit="submitBuildingConfig(event)"><h4 style="color:#fff;margin:0 0 1rem;">New Building</h4><div class="form-row"><label>Code *</label><input id="facilityBuildingCode" required maxlength="100" placeholder="BLD-C"></div><div class="form-row"><label>Name *</label><input id="facilityBuildingName" required maxlength="255" placeholder="Gedung C"></div><div class="form-row"><label>Description</label><textarea id="facilityBuildingDescription" maxlength="1000"></textarea></div><button class="btn-secondary" type="submit">Save Building</button></form>
            <form id="doorConfigForm" onsubmit="submitDoorConfig(event)"><h4 style="color:#fff;margin:0 0 1rem;">New / Edit Door Terminal</h4><input type="hidden" id="facilityOriginalDoorId"><div class="form-row"><label>Door ID *</label><input id="facilityDoorId" required maxlength="50" placeholder="DOOR-C"></div><div class="form-row"><label>Door Name *</label><input id="facilityDoorName" required maxlength="120" placeholder="Main Lobby"></div><div class="form-row"><label>Building *</label><select id="facilityDoorBuilding" required></select></div><div class="form-row"><label>Device IP *</label><input id="facilityDoorIp" required placeholder="192.168.90.13"></div><div class="form-row"><label>Gateway</label><input id="facilityDoorGateway" placeholder="192.168.90.1"></div><div class="form-row"><label>Device Model *</label><input id="facilityDoorModel" required value="DS-K1T804AMF"></div><button class="btn-primary" id="facilityDoorSubmit" type="submit">Register Door</button></form>
        </div>
    </div>
</div>

<!-- MODAL: ADD BUILDING -->
<div class="modal-overlay" id="addBuildingModal">
    <div class="modal-card" style="max-width: 500px;">
        <div class="modal-header">
            <h3 class="modal-title">🏢 Registrasi Gedung Baru</h3>
            <button class="modal-close-btn" onclick="closeModal('addBuildingModal')">✖</button>
        </div>
        <form id="addBuildingForm" onsubmit="submitAddBuilding(event)">
            <div class="form-row" style="margin-bottom: 1rem;">
                <label style="display: block; font-size: 0.85rem; color: var(--text-muted); margin-bottom: 0.35rem;">Kode Gedung (e.g. BLD-C, BLD-HQ)</label>
                <input type="text" id="buildingCodeInput" class="form-control" required placeholder="BLD-C">
            </div>
            <div class="form-row" style="margin-bottom: 1rem;">
                <label style="display: block; font-size: 0.85rem; color: var(--text-muted); margin-bottom: 0.35rem;">Nama Gedung</label>
                <input type="text" id="buildingNameInput" class="form-control" required placeholder="Gedung C - Operasional">
            </div>
            <div class="form-row" style="margin-bottom: 1.5rem;">
                <label style="display: block; font-size: 0.85rem; color: var(--text-muted); margin-bottom: 0.35rem;">Keterangan / Deskripsi Lokasi</label>
                <textarea id="buildingDescInput" class="form-control" rows="3" placeholder="Fasilitas gedung operasional pendukung & data center"></textarea>
            </div>
            <div style="display: flex; justify-content: flex-end; gap: 0.75rem;">
                <button type="button" class="btn-secondary" onclick="closeModal('addBuildingModal')">Batal</button>
                <button type="submit" class="btn-primary">Simpan Gedung</button>
            </div>
        </form>
    </div>
</div>

<!-- MODAL: ADD ZONE -->
<div class="modal-overlay" id="addZoneModal">
    <div class="modal-card" style="max-width: 500px;">
        <div class="modal-header">
            <h3 class="modal-title">📍 Registrasi Zona Akses Baru</h3>
            <button class="modal-close-btn" onclick="closeModal('addZoneModal')">✖</button>
        </div>
        <form id="addZoneForm" onsubmit="submitAddZone(event)">
            <div class="form-row" style="margin-bottom: 1rem;">
                <label style="display: block; font-size: 0.85rem; color: var(--text-muted); margin-bottom: 0.35rem;">Pilih Gedung Induk *</label>
                <select id="zoneBuildingSelect" class="form-control" required>
                    <option value="">Pilih Gedung...</option>
                </select>
            </div>
            <div class="form-row" style="margin-bottom: 1rem;">
                <label style="display: block; font-size: 0.85rem; color: var(--text-muted); margin-bottom: 0.35rem;">Kode Zona (e.g. ZN-MAIN, ZN-SERVER) *</label>
                <input type="text" id="zoneCodeInput" class="form-control" required placeholder="ZN-MAIN">
            </div>
            <div class="form-row" style="margin-bottom: 1.5rem;">
                <label style="display: block; font-size: 0.85rem; color: var(--text-muted); margin-bottom: 0.35rem;">Nama Zona Akses *</label>
                <input type="text" id="zoneNameInput" class="form-control" required placeholder="Pintu Akses Utama / Area Lobby">
            </div>
            <div style="display: flex; justify-content: flex-end; gap: 0.75rem;">
                <button type="button" class="btn-secondary" onclick="closeModal('addZoneModal')">Batal</button>
                <button type="submit" class="btn-primary">Simpan Zona</button>
            </div>
        </form>
    </div>
</div>

<!-- Configuration & Global Variables -->
<script>
    window.APP_CONFIG = {
        apiToken: @json($apiToken ?? session('api_token')),
        permissions: @json($permissions ?? []),
        sseEnabled: @json(!app()->environment('testing')),
        admin: {
            id: @json(Auth::id() ?? 1),
            name: @json(Auth::user()->name ?? 'Administrator'),
            role: @json(Auth::user()->role ?? 'super_admin')
        }
    };
</script>
<script src="/js/dashboard.js"></script>

</body>
</html>
