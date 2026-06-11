<?php
$lines = file('assets/css/crm-theme.css');
$new = array_slice($lines, 0, 4308);

$override = <<<'CSS'
/* ============================================
   PREMIUM APPLE-STYLE OVERRIDE SECTION
   Soft shadows, rounded-xl, glassmorphism
   ============================================ */

/* ── Font stack ── */
body.crm-theme,
body.crm-theme.dark {
    font-family: 'Inter', ui-sans-serif, system-ui, -apple-system, sans-serif;
}

body.crm-theme .crm-mono,
body.crm-theme .crm-metric-value,
body.crm-theme .crm-screen-title,
body.crm-theme .crm-stat-value,
body.crm-theme .crm-count-badge,
body.crm-theme .crm-summary-value {
    font-family: 'JetBrains Mono', ui-monospace, SFMono-Regular, Menlo, Monaco, Consolas, monospace;
}

/* ── Glassmorphism panels ── */
body.crm-theme .liquid-glass-pro,
body.crm-theme [class*="liquid-glass"],
body.crm-theme [class*="ios-glass"],
body.crm-theme .crm-panel-block,
body.crm-theme .crm-card {
    background: var(--crm-surface) !important;
    border: 1px solid var(--crm-border) !important;
    border-radius: 12px !important;
    box-shadow: var(--crm-shadow-sm) !important;
    backdrop-filter: blur(20px) !important;
    -webkit-backdrop-filter: blur(20px) !important;
}

body.crm-theme .crm-panel-block:hover,
body.crm-theme .crm-card:hover {
    box-shadow: var(--crm-shadow-md) !important;
    transform: translateY(-1px);
    transition: all 0.2s ease;
}

/* ── Inputs ── */
body.crm-theme .crm-control-input,
body.crm-theme .crm-control-select,
body.crm-theme .crm-control-textarea,
body.crm-theme .ios-glass-input,
body.crm-theme input[type="text"],
body.crm-theme input[type="email"],
body.crm-theme input[type="password"],
body.crm-theme input[type="number"],
body.crm-theme input[type="tel"],
body.crm-theme input[type="date"],
body.crm-theme input[type="time"],
body.crm-theme select,
body.crm-theme textarea {
    background: var(--crm-surface) !important;
    border: 1px solid var(--crm-border) !important;
    border-radius: 10px !important;
    color: var(--crm-text) !important;
    box-shadow: inset 0 1px 2px rgba(15, 23, 42, 0.03) !important;
}

body.crm-theme .crm-control-input:focus,
body.crm-theme .crm-control-select:focus,
body.crm-theme .crm-control-textarea:focus,
body.crm-theme .ios-glass-input:focus,
body.crm-theme input:focus,
body.crm-theme select:focus,
body.crm-theme textarea:focus {
    border-color: var(--crm-accent) !important;
    outline: none !important;
    box-shadow: 0 0 0 3px rgba(59, 130, 246, 0.15), inset 0 1px 2px rgba(15, 23, 42, 0.03) !important;
}

/* ── Buttons ── */
body.crm-theme .crm-btn-primary {
    background: var(--crm-accent) !important;
    color: #FFFFFF !important;
    border: 1px solid var(--crm-accent) !important;
    border-radius: 10px !important;
    box-shadow: 0 4px 12px rgba(59, 130, 246, 0.25) !important;
    font-weight: 600 !important;
    transition: all 0.2s ease !important;
}

body.crm-theme .crm-btn-primary:hover {
    background: var(--crm-accent-strong) !important;
    border-color: var(--crm-accent-strong) !important;
    box-shadow: 0 6px 16px rgba(59, 130, 246, 0.35) !important;
    transform: translateY(-1px);
}

body.crm-theme .crm-btn-secondary {
    background: var(--crm-surface-alt) !important;
    color: var(--crm-text) !important;
    border: 1px solid var(--crm-border) !important;
    border-radius: 10px !important;
    box-shadow: var(--crm-shadow-sm) !important;
    transition: all 0.2s ease !important;
}

body.crm-theme .crm-btn-secondary:hover {
    background: var(--crm-surface) !important;
    border-color: var(--crm-border-strong) !important;
    box-shadow: var(--crm-shadow-md) !important;
}

body.crm-theme .crm-btn-ghost {
    background: transparent !important;
    color: var(--crm-text-muted) !important;
    border: none !important;
    transition: all 0.2s ease !important;
}

body.crm-theme .crm-btn-ghost:hover {
    color: var(--crm-text) !important;
    background: rgba(59, 130, 246, 0.06) !important;
}

body.crm-theme .crm-btn-danger {
    background: transparent !important;
    color: var(--crm-danger-text) !important;
    border: 1px solid var(--crm-danger-border) !important;
    border-radius: 10px !important;
    transition: all 0.2s ease !important;
}

body.crm-theme .crm-btn-danger:hover {
    background: var(--crm-danger-soft) !important;
}

/* ── Tabs ── */
body.crm-theme .crm-tabbar-button {
    border-radius: 10px !important;
    background: transparent !important;
    border: 1px solid transparent !important;
    color: var(--crm-text-muted) !important;
    font-weight: 500 !important;
    font-size: 0.8rem !important;
    transition: all 0.2s ease !important;
}

body.crm-theme .crm-tabbar-button:hover {
    background: rgba(59, 130, 246, 0.06) !important;
    color: var(--crm-text) !important;
}

body.crm-theme .crm-tabbar-button.active,
body.crm-theme .crm-tabbar-button[aria-selected="true"],
body.crm-theme .crm-tabbar-button-active {
    background: var(--crm-surface) !important;
    border-color: var(--crm-border) !important;
    color: var(--crm-text) !important;
    box-shadow: var(--crm-shadow-sm) !important;
}

body.crm-theme .crm-tabbar-button.active::before,
body.crm-theme .crm-tabbar-button[aria-selected="true"]::before,
body.crm-theme .crm-tabbar-button-active::before {
    content: '';
    display: inline-block;
    width: 6px;
    height: 6px;
    background: var(--crm-accent);
    border-radius: 50%;
    margin-right: 6px;
    vertical-align: middle;
}

/* ── Tables ── */
body.crm-theme table,
body.crm-theme .crm-table,
body.crm-theme [class*="table"] {
    border-collapse: separate !important;
    border-spacing: 0;
}

body.crm-theme .crm-table tbody tr:nth-child(even) {
    background: transparent !important;
}

body.crm-theme .crm-table tbody tr:hover {
    background: var(--crm-surface-alt) !important;
}

body.crm-theme .crm-table th,
body.crm-theme .crm-table td {
    border-bottom: 1px solid var(--crm-border) !important;
    padding: 10px 14px !important;
}

body.crm-theme .crm-table thead th {
    border-bottom: 1px solid var(--crm-border-strong) !important;
    color: var(--crm-text-faint) !important;
    text-transform: uppercase !important;
    font-size: 0.65rem !important;
    letter-spacing: 0.08em !important;
    font-weight: 600 !important;
    font-family: 'JetBrains Mono', monospace !important;
}

/* ── Status dots ── */
body.crm-theme .crm-status-dot {
    display: inline-flex;
    align-items: center;
    gap: 8px;
    font-size: 0.8rem;
    color: var(--crm-text-muted);
}

body.crm-theme .crm-status-dot::before {
    content: '';
    display: inline-block;
    width: 8px;
    height: 8px;
    border-radius: 50%;
    background: var(--crm-text-faint);
    flex-shrink: 0;
}

body.crm-theme .crm-status-dot.active::before,
body.crm-theme .crm-status-dot.success::before { background: var(--lg-success); }
body.crm-theme .crm-status-dot.error::before,
body.crm-theme .crm-status-dot.danger::before { background: var(--lg-error); }
body.crm-theme .crm-status-dot.warning::before { background: var(--lg-warning); }
body.crm-theme .crm-status-dot.info::before { background: var(--lg-info); }

/* ── Badges ── */
body.crm-theme .crm-badge,
body.crm-theme [class*="badge"] {
    border-radius: 8px !important;
    font-family: 'JetBrains Mono', monospace !important;
    font-size: 0.7rem !important;
    letter-spacing: 0.03em !important;
    font-weight: 600 !important;
    padding: 3px 8px !important;
}

/* ── Scrollbar ── */
body.crm-theme ::-webkit-scrollbar {
    width: 8px;
    height: 8px;
}

body.crm-theme ::-webkit-scrollbar-track {
    background: transparent;
}

body.crm-theme ::-webkit-scrollbar-thumb {
    background: var(--crm-border-strong);
    border-radius: 4px;
    border: 2px solid transparent;
    background-clip: padding-box;
}

body.crm-theme ::-webkit-scrollbar-thumb:hover {
    background: var(--crm-accent);
}

/* ── Rounded corner normalization ── */
body.crm-theme [class*="rounded-3xl"],
body.crm-theme [class*="rounded-2xl"],
body.crm-theme [class*="rounded-xl"] {
    border-radius: 12px !important;
}

body.crm-theme [class*="rounded-lg"] {
    border-radius: 10px !important;
}

/* ── Modal / Drawer ── */
body.crm-theme .crm-modal,
body.crm-theme .crm-drawer,
body.crm-theme [class*="modal"],
body.crm-theme [class*="drawer"] {
    background: var(--crm-surface) !important;
    border: 1px solid var(--crm-border) !important;
    border-radius: 16px !important;
    box-shadow: var(--crm-shadow-lg) !important;
}

body.crm-theme .crm-modal-overlay,
body.crm-theme [class*="overlay"] {
    background: rgba(15, 23, 42, 0.45) !important;
    backdrop-filter: blur(8px) !important;
    -webkit-backdrop-filter: blur(8px) !important;
}

/* ── Sidebar ── */
body.crm-theme .crm-sidebar,
body.crm-theme [class*="sidebar"] {
    background: var(--crm-surface) !important;
    border-right: 1px solid var(--crm-border) !important;
    box-shadow: var(--crm-shadow-md) !important;
}

body.crm-theme .crm-sidebar-item.active,
body.crm-theme .crm-sidebar-item[aria-current="page"],
body.crm-theme .crm-nav-item-active {
    background: rgba(59, 130, 246, 0.08) !important;
    border-left: 3px solid var(--crm-accent) !important;
}

/* ── Topbar ── */
body.crm-theme .crm-topbar,
body.crm-theme [class*="topbar"] {
    background: var(--crm-surface) !important;
    border-bottom: 1px solid var(--crm-border) !important;
    box-shadow: var(--crm-shadow-sm) !important;
}

/* ── Dropdowns ── */
body.crm-theme .crm-dropdown,
body.crm-theme [class*="dropdown"] {
    background: var(--crm-surface) !important;
    border: 1px solid var(--crm-border) !important;
    border-radius: 12px !important;
    box-shadow: var(--crm-shadow-lg) !important;
}

body.crm-theme .crm-dropdown-item:hover {
    background: var(--crm-surface-alt) !important;
    border-radius: 8px;
}

/* ── Utility overrides ── */
body.crm-theme .crm-border-subtle {
    border: 1px solid var(--crm-border) !important;
    background: var(--crm-surface) !important;
    border-radius: 12px !important;
}

body.crm-theme .crm-border-warn {
    border: 1px solid rgba(245, 158, 11, 0.25) !important;
    background: rgba(245, 158, 11, 0.05) !important;
    border-radius: 10px !important;
}

body.crm-theme .crm-border-success {
    border: 1px solid rgba(16, 185, 129, 0.20) !important;
    background: rgba(16, 185, 129, 0.05) !important;
    border-radius: 10px !important;
}

body.crm-theme .crm-bg-error-soft {
    background: rgba(239, 68, 68, 0.08) !important;
    border-radius: 10px !important;
}

body.crm-theme .crm-bg-dark-soft {
    border: 1px solid var(--crm-border) !important;
    background: var(--crm-surface-alt) !important;
    border-radius: 12px !important;
}

/* ── Links ── */
body.crm-theme a,
body.crm-theme .crm-link {
    color: var(--crm-text);
    text-decoration: none;
    transition: color 0.2s ease;
}

body.crm-theme a:hover,
body.crm-theme .crm-link:hover {
    color: var(--crm-accent);
}

/* ── Toggle / Switch ── */
body.crm-theme .crm-toggle:checked,
body.crm-theme input[type="checkbox"]:checked {
    background: var(--crm-accent) !important;
    border-color: var(--crm-accent) !important;
}

/* ── Progress ── */
body.crm-theme .crm-progress-bar,
body.crm-theme [class*="progress"] {
    background: var(--crm-surface-alt) !important;
    border-radius: 6px !important;
    overflow: hidden;
}

body.crm-theme .crm-progress-fill {
    background: var(--crm-accent) !important;
    border-radius: 6px !important;
}

/* ── Calendar ── */
body.crm-theme .fc-theme-standard .fc-scrollgrid,
body.crm-theme .fc-theme-standard td,
body.crm-theme .fc-theme-standard th {
    border-color: var(--crm-border) !important;
}

body.crm-theme .fc .fc-daygrid-day.fc-day-today {
    background: rgba(59, 130, 246, 0.08) !important;
}

/* ── Empty states ── */
body.crm-theme .crm-empty-state {
    color: var(--crm-text-faint);
    font-family: 'JetBrains Mono', monospace;
    text-transform: uppercase;
    letter-spacing: 0.08em;
    font-size: 0.8rem;
}

/* ── Fix: filter / summary tiles ── */
body.crm-theme .crm-summary-tile,
body.crm-theme .crm-summary-tile--compact,
body.crm-theme .crm-toolbar-summary .crm-summary-tile {
    border-radius: 12px !important;
    background: var(--crm-surface) !important;
    border: 1px solid var(--crm-border) !important;
    box-shadow: var(--crm-shadow-sm) !important;
    transition: all 0.2s ease;
}

body.crm-theme .crm-summary-tile:hover {
    box-shadow: var(--crm-shadow-md) !important;
    transform: translateY(-1px);
}

body.crm-theme .crm-inline-badge,
body.crm-theme .crm-inline-badge-accent {
    border-radius: 8px !important;
    background: var(--crm-surface-alt) !important;
    border: 1px solid var(--crm-border) !important;
    font-family: 'JetBrains Mono', monospace !important;
    font-size: 0.7rem !important;
    text-transform: uppercase !important;
    letter-spacing: 0.03em !important;
    font-weight: 600 !important;
    box-shadow: var(--crm-shadow-sm) !important;
}

body.crm-theme .crm-filter-field {
    border-radius: 12px !important;
    background: var(--crm-surface) !important;
    border: 1px solid var(--crm-border) !important;
    box-shadow: var(--crm-shadow-sm) !important;
}

/* ── Fix: priority pills ── */
body.crm-theme .crm-priority-pill,
body.crm-theme .priority-low,
body.crm-theme .priority-medium,
body.crm-theme .priority-high,
body.crm-theme .priority-urgent {
    border-radius: 8px !important;
    font-family: 'JetBrains Mono', monospace !important;
    font-size: 0.7rem !important;
    text-transform: uppercase !important;
    letter-spacing: 0.03em !important;
    font-weight: 600 !important;
}

/* ── Fix: project avatars ── */
body.crm-theme .crm-project-avatar,
body.crm-theme .crm-project-avatar--soft,
body.crm-theme .crm-project-avatar--compact {
    border-radius: 10px !important;
    background: var(--crm-accent) !important;
    box-shadow: var(--crm-shadow-sm) !important;
    color: #FFFFFF !important;
    font-family: 'JetBrains Mono', monospace !important;
}

body.crm-theme .crm-project-avatar--soft {
    background: var(--crm-surface-alt) !important;
    color: var(--crm-text) !important;
    border: 1px solid var(--crm-border) !important;
}

/* ── Fix: project metric chips ── */
body.crm-theme .crm-project-metric {
    border-radius: 12px !important;
    background: var(--crm-surface-alt) !important;
    border: 1px solid var(--crm-border) !important;
    box-shadow: var(--crm-shadow-sm) !important;
}

/* ── Fix: project cards ── */
body.crm-theme .crm-project-card::before {
    display: none !important;
}

body.crm-theme .crm-card-equal {
    height: auto !important;
}

body.crm-theme .crm-project-card {
    padding: 16px !important;
    border-radius: 12px !important;
    box-shadow: var(--crm-shadow-sm) !important;
    transition: all 0.2s ease;
}

body.crm-theme .crm-project-card:hover {
    box-shadow: var(--crm-shadow-md) !important;
    transform: translateY(-2px);
}

/* ── Notifications panel ── */
body.crm-theme .notifications-panel-shell {
    background: var(--crm-surface) !important;
    border: 1px solid var(--crm-border) !important;
    border-radius: 16px !important;
    box-shadow: var(--crm-shadow-lg) !important;
}

body.crm-theme .notification-card {
    background: var(--crm-surface-alt) !important;
    border: 1px solid var(--crm-border) !important;
    border-radius: 12px !important;
    box-shadow: var(--crm-shadow-sm) !important;
    transition: all 0.2s ease;
}

body.crm-theme .notification-card:hover {
    box-shadow: var(--crm-shadow-md) !important;
    transform: translateX(2px);
}

body.crm-theme .notification-card-unread {
    border-left: 3px solid var(--crm-accent) !important;
}

body.crm-theme .notification-card-icon {
    background: var(--crm-surface) !important;
    border: 1px solid var(--crm-border) !important;
    border-radius: 10px !important;
}

body.crm-theme .notification-type-chip,
body.crm-theme .notification-chip-info,
body.crm-theme .notification-chip-chat,
body.crm-theme .notification-chip-task,
body.crm-theme .notification-chip-system {
    border-radius: 6px !important;
    font-size: 0.65rem !important;
    font-weight: 600 !important;
    text-transform: uppercase !important;
    letter-spacing: 0.04em !important;
    padding: 2px 8px !important;
    background: var(--crm-surface) !important;
    border: 1px solid var(--crm-border) !important;
    color: var(--crm-text-muted) !important;
}

body.crm-theme .notification-status-chip {
    border-radius: 6px !important;
    font-size: 0.65rem !important;
    font-weight: 600 !important;
    background: rgba(59, 130, 246, 0.10) !important;
    color: var(--crm-accent) !important;
    padding: 2px 8px !important;
}

body.crm-theme .notification-action-primary {
    background: var(--crm-accent) !important;
    color: #FFFFFF !important;
    border-radius: 8px !important;
    box-shadow: 0 4px 12px rgba(59, 130, 246, 0.25) !important;
    font-weight: 600 !important;
    transition: all 0.2s ease;
}

body.crm-theme .notification-action-primary:hover {
    background: var(--crm-accent-strong) !important;
    box-shadow: 0 6px 16px rgba(59, 130, 246, 0.35) !important;
}

body.crm-theme .notification-action-secondary,
body.crm-theme .ios-glass-button {
    background: var(--crm-surface) !important;
    border: 1px solid var(--crm-border) !important;
    border-radius: 8px !important;
    color: var(--crm-text-muted) !important;
    transition: all 0.2s ease;
}

body.crm-theme .notification-action-secondary:hover,
body.crm-theme .ios-glass-button:hover {
    background: var(--crm-surface-alt) !important;
    color: var(--crm-text) !important;
}

body.crm-theme .notifications-empty-card {
    background: var(--crm-surface-alt) !important;
    border: 1px solid var(--crm-border) !important;
    border-radius: 12px !important;
    box-shadow: var(--crm-shadow-sm) !important;
}

body.crm-theme .notifications-empty-icon {
    color: var(--crm-accent);
    font-size: 2rem;
}

/* ── Toast ── */
body.crm-theme .lg-toast {
    background: var(--crm-surface) !important;
    border: 1px solid var(--crm-border) !important;
    border-radius: 12px !important;
    box-shadow: var(--crm-shadow-lg) !important;
    backdrop-filter: blur(20px) !important;
    -webkit-backdrop-filter: blur(20px) !important;
    padding: 14px 18px !important;
}

/* ── Section headings ── */
body.crm-theme .crm-section-head__title,
body.crm-theme .crm-toolbar-heading h2,
body.crm-theme .crm-toolbar-heading h3,
body.crm-theme h1,
body.crm-theme h2,
body.crm-theme h3 {
    font-family: 'JetBrains Mono', ui-monospace, SFMono-Regular, Menlo, Monaco, Consolas, monospace;
    letter-spacing: -0.02em;
}
CSS;

file_put_contents('assets/css/crm-theme.css', implode('', $new) . $override);
echo "Done: " . count($new) . " lines kept, override replaced\n";
