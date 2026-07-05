# Design

## Source of truth
- Status: Draft
- Last refreshed: 2026-07-05
- Primary product surfaces: public route pricing/registration flow, parent dashboard, parent pickup-location editor, finance student/payment dashboard, manager fleet route-monitoring dashboard.
- Evidence reviewed:
  - `README.md`: still Laravel starter documentation; no product-specific design guidance.
  - `tailwind.config.js`: Tailwind CSS with Figtree and forms plugin; no custom tokens beyond the default theme.
  - `resources/js/Layouts/AuthenticatedLayout.vue`: authenticated app shell, top navigation, responsive mobile menu, flash messages.
  - `resources/js/Pages/Admin/Dashboard.vue`: manager fleet monitoring surface with stats, route mode controls, Leaflet map, route generation actions, and fleet/student assignment list.
  - `resources/js/Pages/Dashboard.vue`: parent dashboard cards for subscription, payment, route assignment, and pickup-location management.
  - `resources/js/Pages/Finance/StudentList.vue`: finance dashboard with status chips, filters, table, and payment actions.
  - `resources/js/Pages/Parent/EditLocation.vue`: responsive map-plus-form location editor.
  - `resources/js/Pages/Welcome.vue`: public pricing estimator and registration entry point with map, estimates, and call to action.
  - `resources/js/Components/PrimaryButton.vue`: default button focus and hover pattern.

## Brand
- Personality: practical, trustworthy, operational, clear, school-transport focused.
- Trust signals: precise route/map data, payment and service status clarity, explicit route/session labels, visible generated-route progress, conservative admin controls.
- Avoid: decorative-heavy marketing UI inside dashboards, cramped operational controls, hidden status, unclear route direction labels, unnecessary animation that distracts from operations.

## Product goals
- Goals:
  - Help parents estimate cost, register pickup/dropoff details, and track student transport status.
  - Help finance staff verify payment and understand each student's service state.
  - Help managers generate, monitor, and inspect school transport fleet routes.
- Non-goals:
  - Do not turn operational dashboards into landing pages.
  - Do not change route optimization, pricing, payment, or map business rules for purely visual work.
  - Do not introduce a separate design-system library unless repeated UI drift becomes a clear maintenance problem.
- Success signals:
  - Core actions are visible and reachable on mobile and desktop.
  - No unintended horizontal scrolling on 360px to 430px mobile widths.
  - Status, route direction, trip, capacity, and payment state can be scanned quickly.
  - Build passes after UI changes.

## Personas and jobs
- Primary personas:
  - Parent: registers child transport details, updates pickup location when allowed, checks payment and route status.
  - Finance staff: finds students, reviews payment state, confirms payment.
  - Operations manager: generates morning/afternoon routes, switches route/session views, monitors fleet assignment on map and list.
- User jobs:
  - Estimate monthly transport fee from a map location.
  - Confirm whether a student is unpaid, waiting for route, or active.
  - Update pickup point and address details accurately.
  - Generate route assignments and inspect fleet/student ordering.
- Key contexts of use:
  - Mobile phones for parents and quick manager checks.
  - Tablet/desktop for admin monitoring, map inspection, and bulk review.
  - Potentially narrow browser windows during development or field use.

## Information architecture
- Primary navigation: role-based authenticated navigation with one primary section per role.
- Core routes/screens:
  - Public welcome/pricing estimator and registration entry.
  - Parent dashboard and pickup-location editor.
  - Finance student/payment list.
  - Manager fleet monitoring dashboard.
- Content hierarchy:
  - Dashboards should lead with current status and primary controls.
  - Detailed lists/tables sit below filters or beside maps only when width allows.
  - Maps should remain inspectable and should not be squeezed into narrow columns on mobile.

## Design principles
- Principle 1: Operational clarity first. Controls, statuses, and route direction labels must be readable before decorative polish.
- Principle 2: Mobile layouts stack by task order. Summary, mode controls, actions, map, and detail list should each get full width on small screens.
- Tradeoffs:
  - Dense desktop dashboards are acceptable for scanning, but mobile should favor vertical space over side-by-side density.
  - Tables can use horizontal scroll where data is genuinely tabular, but map/list workflows should reflow instead of causing page-level overflow.

## Visual language
- Color:
  - Blue for primary actions, route information, and neutral operational emphasis.
  - Green for paid/success/active/simulation.
  - Amber/yellow for waiting, selected trip, or caution.
  - Red for unpaid/error states.
  - Orange for afternoon/dropoff/pulang route context.
  - Slate/gray for structure, borders, helper text, and neutral panels.
- Typography:
  - Figtree via Tailwind default sans stack.
  - Use small uppercase labels for dashboard metrics and form/filter labels.
  - Keep mobile text readable; avoid text that depends on icon/emoji alone.
- Spacing/layout rhythm:
  - Use 4px-based Tailwind spacing.
  - Dashboard groups should use consistent `gap-3` to `gap-6`.
  - Mobile containers need horizontal padding and full-width controls.
- Shape/radius/elevation:
  - Existing UI uses `rounded-lg`, `rounded-xl`, `shadow-sm`, and thin borders.
  - Prefer modest radius and light elevation for operational surfaces.
- Motion:
  - Use standard hover/focus transitions for controls.
  - Route animation is meaningful only after a trip is selected; do not add unrelated motion.
- Imagery/iconography:
  - Maps are the primary visual asset for route-related screens.
  - Existing UI uses occasional emoji/icons in labels; keep them supplemental and ensure text remains clear without them.

## Components
- Existing components to reuse:
  - `AuthenticatedLayout.vue` for role-based shell and flash messages.
  - Form components in `resources/js/Components/*` where already used by auth/profile flows.
  - Existing Tailwind card, chip, filter, and status patterns in dashboard pages.
- New/changed components:
  - Prefer local responsive class changes for the manager dashboard before extracting shared components.
  - Extract shared StatCard/StatusChip/Button variants only after repeated duplication becomes a maintenance issue.
- Variants and states:
  - Buttons need default, hover, focus, active, disabled, loading/progress text, and full-width mobile variants.
  - Select/input controls need readable labels and visible focus rings.
  - Cards need selected, empty, success, warning, and error states where relevant.
- Token/component ownership:
  - Tailwind classes are currently the token source.
  - Avoid adding new CSS token systems without broader refactor approval.

## Accessibility
- Target standard: practical WCAG 2.1 AA alignment for contrast, keyboard access, focus visibility, and readable text.
- Keyboard/focus behavior:
  - Buttons, links, selects, and map-related actions must remain keyboard reachable.
  - Preserve or add visible `focus:ring`/`focus:outline` states when touching controls.
- Contrast/readability:
  - Status colors should use light backgrounds with darker text and borders.
  - Avoid tiny text for critical actions or state.
- Screen-reader semantics:
  - Buttons should have clear text labels, not icon-only labels.
  - Dynamic statuses should use explicit visible text; consider `aria-live` only if future status updates need assistive announcement.
- Reduced motion and sensory considerations:
  - Do not auto-start vehicle animation.
  - Keep motion tied to an explicit user action.

## Responsive behavior
- Supported breakpoints/devices:
  - Mobile: 360px, 390px, 430px widths.
  - Tablet: 768px width.
  - Desktop: existing max-width `7xl` layouts.
- Layout adaptations:
  - Statistics cards stack vertically on small screens; they may use 2 columns on tablet and 4 on desktop.
  - Form/filter controls stack on mobile and align horizontally only when enough width exists.
  - Action button groups should use a 1-column mobile layout and can become horizontal on larger screens.
  - Map surfaces should be full width on mobile with a stable height and no sibling list beside them.
  - Manager route list/sidebar should appear below the map on mobile and beside it only at large widths.
  - Desktop side-by-side map/list is appropriate when the map remains readable.
- Touch/hover differences:
  - Touch targets should be at least roughly 40px high for primary controls.
  - Hover effects are desktop enhancements only; selected/active states must be visible without hover.

## Interaction states
- Loading:
  - Route generation should show clear current step, progress, and disabled action state.
  - Loading copy should identify whether morning or afternoon route generation is running.
- Empty:
  - Empty dashboards/lists should explain why content is absent and what action is possible.
- Error:
  - Error states should use red styling and actionable text, avoiding silent failures.
- Success:
  - Success states should use green styling and concise confirmation.
- Disabled:
  - Disabled buttons should visually reduce opacity and remain legible.
  - Disabled states should not remove explanatory context.
- Offline/slow network, if applicable:
  - Map and OSRM route requests may be slow or fail. Existing fallback behavior should remain intact, and any UI changes must not remove it.

## Content voice
- Tone: direct, instructional, and operational in Indonesian.
- Terminology:
  - Use "Rute Pagi" for pickup-to-school.
  - Use "Rute Pulang" or "Sesi Jam Pulang" for school-to-home.
  - Use "Armada", "Trip/Rit", "Siswa", "Supir", "Lunas", and "Belum Bayar" consistently.
- Microcopy rules:
  - Prefer clear action labels such as "Generate Rute Pagi" and "Simulasi Armada".
  - Keep helper text short and tied to the current control or state.
  - Avoid relying on emoji as the only meaning carrier.

## Implementation constraints
- Framework/styling system:
  - Laravel, Inertia, Vue 3, Vite, Tailwind CSS, Leaflet.
  - Use Tailwind utility classes and existing Vue file structure.
- Design-token constraints:
  - No custom design token layer currently exists.
  - Prefer Tailwind default colors and existing page-local patterns.
- Performance constraints:
  - Do not increase OSRM request volume or change route-cache behavior for layout changes.
  - Do not rerender or reinitialize maps unnecessarily when changing markup/classes.
- Compatibility constraints:
  - Preserve existing API calls, props, routes, state management, route generation, pricing, and map logic unless the task explicitly targets them.
  - Avoid adding dependencies for layout-only fixes.
- Test/screenshot expectations:
  - Run `npm run build` after frontend changes.
  - For responsive dashboard work, inspect widths 360px, 390px, 430px, 768px, and desktop.
  - Confirm no page-level horizontal scrolling and that map/list order is correct.

## Open questions
- [ ] Should `DESIGN.md` become an active source of truth after review, or remain draft until after the mobile dashboard fix? / owner: project owner / impact: future UI reviews may treat draft guidance differently.
- [ ] Are there official school/koperasi brand colors or logos beyond the current blue/green/orange operational palette? / owner: project owner / impact: branding consistency.
- [ ] What browser/device matrix is required for real users? / owner: project owner / impact: responsive QA depth.
