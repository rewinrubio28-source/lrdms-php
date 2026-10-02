# Section 7 — UI, UX and accessibility

Local implementation and focused Chrome verification, 2026-10-02. This is not a whole-system accessibility certification or a deployed-user acceptance report.

## Changes

- Shared authenticated layout has a keyboard-visible skip link, focusable main landmark, named navigation and current-page indication.
- Account and notification disclosures use ordinary links/buttons, named regions and expanded/control relationships. Escape restores focus; leaving the disclosure closes it. They no longer claim application-menu semantics without the corresponding arrow-key behavior.
- Opening the mobile sidebar makes main content inert; existing Tab wrapping and Escape handling remain. Closing or changing to desktop removes inertness. The navigation wrapper preserves scrolling in the sidebar.
- Visible two-color focus indicators and reduced-motion overrides are shared across authenticated modules. Data-table scroll containers are named and keyboard reachable.
- Error and success banners remain inline. The old four-second toast conversion hid feedback and reconstructed message text as HTML; that conversion has been removed.
- Shared POST feedback retains the clicked button label and its name/value. The previous handler disabled the first submit button, which could omit an action value or indicate the wrong operation. New feedback preserves native payloads, marks busy state, announces progress, rejects repeat submissions while pending, and resets on canceled submission or Back/Forward page restoration. AJAX handlers that prevent the native submission continue owning their own loading/error state.
- Shared light-theme muted/subtle text, dark-theme subtle text and primary-button colors were adjusted for contrast.

## Checklist evidence and remaining work

| Criterion | Evidence collected | Still needs verification |
|---|---|---|
| Responsive layout | Synthetic Reports and dataset Import previews in Chrome at 390, 768 and 1440px, both themes; no document-wide horizontal overflow. Wide tables scroll within their containers. | Physical devices, other modules, long production content, browser zoom and all modals. |
| Navigation | Skip link, account disclosure and mobile sidebar keyboard checks passed; shared navigation landmark/current state added. | Role-specific user task trials and discoverability feedback. |
| Visual consistency | Shared focus, feedback and color rules; twelve screenshots of report/import previews. | Full module-by-module visual review, including standalone login/2FA pages. |
| Form validation | Browser required-field validation blocks invalid submissions without entering a busy state; report/import server endpoint suites passed. | All fields/boundaries on other forms; field-label/error associations across the entire application. |
| Loading indicators | Native POST status, submitter payload preservation, duplicate guard and cancellation/page restoration tested. Existing AJAX handlers remain separate. | Live slow connections, downloads and every OCR/AJAX failure scenario. |
| Error messages | Server banners remain visible beyond the old four-second dismissal; error/success semantics added when absent. | Clarity testing with actual users; coverage of every failure path. |
| Keyboard accessibility | Chrome skip link, account Enter/Tab/Escape, mobile focus confinement/return passed. | Entire workflow without a mouse, all modals and other browsers. |
| Screen reader support | Semantic landmarks, disclosure relationships, live notification/status markup and persistent feedback implemented. | Actual NVDA/VoiceOver testing; markup tests alone do not establish screen-reader support. |
| Color contrast | 34 shared solid text/surface combinations, including primary-button white text, measured at least 4.5:1. | Page-specific hardcoded colors, hover/disabled states, translucent backgrounds, charts, standalone pages and non-text contrast. |

## Reproduce

Run from the repository root using the local test database:

```powershell
php database/test_report_access.php
php -d extension=zip database/test_dataset_access.php
npm.cmd install --prefix .runtime/ui-test --cache .runtime/npm-cache --no-audit --no-fund playwright
node database/test_ui_accessibility.js
php -l includes/layout_top.php
php -l includes/layout_bottom.php
node --check assets/js/accessibility.js
```

Chrome must be installed. The PHP suites use synthetic temporary-table fixtures and generate ignored HTML snapshots. Browser routes serve these snapshots and local assets; notification responses are stubbed. No live account or hosting endpoint is accessed. Remote fonts are omitted, so this does not validate CDN availability or font delivery. The native form checks use browser FormData/events without making a real server mutation; endpoint validation is covered separately by the PHP suites.

Evidence is in `.runtime/ui-test/`: `results.json`, both `contrast-*.json` files and twelve PNGs. These are intentionally ignored by Git. The repeatable test and this report are versioned. The suites passed 13 report endpoint cases, 11 import/export endpoint cases, and eight browser check groups. Syntax checks also passed.

## Panel demonstration to perform after deployment

1. With a representative permitted account, use only Tab, Shift+Tab, Enter and Escape to open a record, filter it, and return to navigation. Test modals and mobile menu; verify focus is never lost or covered.
2. On actual desktop/mobile devices, review Dashboard, Intake, Repository, Search, Reports, Users, Audit and Profile. Include a long title, wide table, empty result and validation error. Repeat at browser zoom and both themes.
3. With NVDA or VoiceOver, identify page landmarks and field labels, then trigger validation and loading feedback. Record missing or confusing announcements and fix before claiming conformance.
4. Ask intended users to find a record and generate a report without coaching. Record completion, hesitation points, device/browser and date; no usability success is claimed until these trials occur.
5. Measure remaining rendered colors and control boundaries. Keep failed pairs as open findings, rather than treating the shared-token measurements as a site-wide pass.

References: [W3C disclosure navigation guidance](https://www.w3.org/WAI/ARIA/apg/patterns/disclosure/examples/disclosure-navigation/) and [W3C minimum text contrast guidance](https://www.w3.org/WAI/WCAG21/Understanding/contrast-minimum.html).

No database migration or new hosting configuration is required for these UI changes. Deployment and live verification remain pending.
