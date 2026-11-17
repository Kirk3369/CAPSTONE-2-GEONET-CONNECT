# GeoNet accessibility review

Target: WCAG 2.2 Level AA. Review date: November 17, 2026 (Asia/Manila).

This is an implementation and test record, not a certificate of conformance. Automated scans do not establish full WCAG compliance. Recheck with realistic accounts, records, validation errors and assistive technology before making a public conformance claim.

## Implemented and checked

| Area | Implementation / evidence | WCAG criteria |
| --- | --- | --- |
| Visual consistency | Shared design system across sign-in, signup, administrator, technician, subscriber, interruption, profile and SMS pages | Supports consistency and readability |
| Contrast | Darker text/status colors, contrast-tested controls, meaningful status text | 1.4.1, 1.4.3, 1.4.11 |
| Forms | Associated labels, accessible password toggle, autocomplete for sign-in, native validation with associated inline errors | 1.3.1, 1.3.5, 2.5.3, 3.3.1, 3.3.2, 4.1.2 |
| Keyboard | Skip link, visible focus, keyboard-operable table detail actions, focusable scrolling lists | 2.1.1, 2.4.1, 2.4.7 |
| Dialogs | Dialog names, focus management, focus containment and Escape cancellation; subscriber dialog exercised in Chrome | 2.1.2, 2.4.3, 4.1.2 |
| Charts | Accessible names and expandable HTML tables generated from each chart's actual data | 1.1.1, 1.3.1 |
| Maps | Named markers; at least 28px marker boxes; coordinate alternatives for placement, NAP/barangay movement and existing coverage boundaries | 1.1.1, 2.1.1, 2.5.7 |
| Layout | Dashboard reflow checked at 390px and 320px; mobile sidebar navigation stays available | 1.4.10 |
| Targets | Primary controls normally at least 44px high; native checkbox targets and labelled hit areas | 2.5.8 |
| Updates | Polite live regions for feedback and reports; active navigation identified | 4.1.3 |
| Preferences | Reduced-motion and forced-colors styling | Supports user preferences |
| Reliability | Leaflet, Chart.js and icon font served locally, including upstream licenses | Avoids external library load failures |

## Map pin exception

When many real locations are close together, map pins can overlap even with 28px boxes. The raw axe findings are retained in `runtime/accessibility-audit.json` under `reviewedMapExceptions`. W3C's [SC 2.5.8 explanation](https://www.w3.org/WAI/WCAG22/Understanding/target-size-minimum.html#exceptions) explicitly identifies geographically accurate dense map pins as an Essential exception. This exception is limited to map pins; the audit continues checking target sizes for other controls. Dropdown selections, subscriber lists and coordinate controls provide alternatives. Do not move saved geographical locations to satisfy target spacing.

## Manual checks still required

- Test complete workflows with NVDA and Chrome/Firefox: sign-in, signup, subscriber details, map alternatives, technician work, SMS and repair confirmation. Confirm names, reading order and announcements make sense.
- Complete each workflow using keyboard only, including all dialogs, native dropdowns, marker popups and validation errors. Check focus returns appropriately and is never obscured.
- Check browser zoom at 200% and 400%, larger default fonts, text spacing overrides and long names/messages on every page and expanded state.
- Test iOS/Android touch, portrait/landscape, forced colors and reduced motion. Verify maps still have usable text alternatives when tiles cannot load.
- Review server-side error association, correction and account/password recovery with real validation cases. Native validation is enhanced, but all business-rule errors need a human review.
- Review data visualization meaning, status terminology and repair metrics for nonvisual understanding.
- Verify third-party basemap behavior and any browser/OS dialogs. Basemap tiles still need internet access.

## Repeating the automated audit

Install test dependencies with `npm.cmd install --no-save --no-package-lock playwright-core axe-core` (not needed to run the website). Use an installed Chrome at the path in the test script, or update that path for the test machine.

Start a local PHP server with a dedicated test session directory:

```powershell
New-Item -ItemType Directory -Force runtime/a11y-sessions
C:/xampp/php/php.exe -d session.save_path=C:/xampp/htdocs/geonet_connect/runtime/a11y-sessions -S 127.0.0.1:8099 -t C:/xampp/htdocs/geonet_connect
```

In another terminal run `node tests/accessibility-audit.cjs`. The script creates temporary role sessions, reads existing records, scans WCAG A/AA rules using axe-core, checks the subscriber dialog and narrow layouts, and saves a detailed report and screenshots in ignored `runtime/`. It removes its generated role sessions when finished. It does not send SMS, create accounts or submit maintenance work.

Reference: [W3C WCAG 2.2](https://www.w3.org/TR/WCAG22/).
