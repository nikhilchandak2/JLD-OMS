# UI Guide – How to Set and Keep the App UI Consistent

This document defines the **recommended way** to structure and style the app so every page looks and behaves like one product.

---

## 1. Design tokens (single source of truth)

All colors, spacing, and shadows come from **CSS variables** in one place. Use these everywhere instead of hardcoding hex or pixel values.

| Token | Use for |
|-------|--------|
| `--jld-primary` | Primary actions, headings, links, brand |
| `--jld-secondary` | Danger, alerts, delete, critical |
| `--jld-white` | Cards, nav background |
| `--jld-light-gray` | Page background, section headers |
| `--jld-gray` | Secondary text, labels |
| `--jld-dark-gray` | Body text |
| `--jld-border` | Borders, dividers |
| `--jld-shadow` | Card shadow (light) |
| `--jld-shadow-lg` | Modals, sidebar |

**Where:** Define in `public/css/design-system.css` (or in `templates/layout.php` inside `:root`). Any new style should use these variables.

---

## 2. Page structure (every page)

Use this order so all pages feel the same:

1. **Breadcrumb** (optional but good for CRM/Admin)  
   `CRM → Funnel → Company name`

2. **Page header** (one row)  
   - Left: **Title** (`.page-title`) + **Subtitle** (`.page-subtitle`)  
   - Right: **Primary action** (e.g. “Open Funnel”, “Add party”) + secondary actions (outline buttons)

3. **Content**  
   - **Cards** for sections (`.card`, `.card-header`, `.card-body`)  
   - **Spacing:** `mb-4` or `g-4` between sections; `g-3` inside rows

4. **No full-width raw tables** without a card; put tables inside `.card .card-body`.

---

## 3. Components to use

| Element | Class / pattern | Notes |
|--------|------------------|--------|
| Page title | `.page-title` | One per page, primary color |
| Subtitle | `.page-subtitle` | Muted, below title |
| Primary button | `.btn .btn-primary` | One main action per screen |
| Secondary action | `.btn .btn-outline-primary` or `.btn-outline-secondary` | Rest of actions |
| Section container | `.card` | White background, rounded, light shadow |
| Section header | `.card-header` | With optional right-aligned button/link |
| KPI / stat card | `.card .crm-kpi-card` (or similar) | Value + label, optional link |
| Nav tile (dashboard) | `.crm-nav-tile` | Icon + title + short description |
| Table | `.table .table-sm` inside `.card-body` | Use existing table styles |
| Form | `.form-control`, `.form-select`, `.form-label` | Already themed |
| Badge | `.badge .bg-primary`, `.bg-danger`, etc. | Status, counts |
| Alert | `.alert .alert-info`, `.alert-danger`, etc. | One-off messages |

Use **Bootstrap Icons** (`bi bi-*`) for icons so the style is consistent.

---

## 4. Spacing and layout

- **Between sections:** `mb-4` or `mt-4` (or `row g-4`).
- **Inside cards:** `card-body` default padding; use `g-2` / `g-3` for inner grids.
- **Buttons in header:** `d-flex flex-wrap gap-2` or `gap-3`.
- **Main content:** `.main-content` already has padding; avoid extra full-width margins.

Use Bootstrap’s **grid** (`row`, `col-*`) for columns; avoid fixed pixel widths for content.

---

## 5. Responsive behavior

- **Sidebar:** Collapses to overlay on small screens (already in layout).
- **Page header:** Title and actions wrap with `flex-wrap`; actions below title on narrow screens.
- **Tables:** Wrap in `.table-responsive` if many columns.
- **Cards:** Stack (e.g. `col-12`) on mobile, multi-column on `col-md-*` and up.

---

## 6. Naming and structure

- **Page-specific blocks:** Use a clear prefix, e.g. `.crm-profile-hero`, `.crm-funnel-board`, so they don’t clash with global styles.
- **Reusable patterns:** Prefer a single class (e.g. `.crm-section-card`) rather than long ad‑hoc combinations.
- **IDs:** Use for JS hooks (e.g. `#companyProfile`), not for styling.

---

## 7. The “perfect” workflow when adding a new page

1. Reuse **layout** (sidebar + main content); no new wrapper.
2. Add **breadcrumb** if the page is under a section (e.g. CRM, Admin).
3. Add **page header**: `.page-title` + `.page-subtitle` + actions on the right.
4. Put content in **cards**; use design tokens for any custom color or shadow.
5. Reuse existing components (same card style, same button styles, same table style).
6. Use **Bootstrap utilities** (margin, padding, flex, gap) for spacing; avoid one-off pixel values.

---

## 8. Where styles live

- **Global theme and tokens:** `public/css/design-system.css` (linked from layout).
- **Layout, sidebar, nav:** `templates/layout.php` (in `<style>` or in a dedicated layout CSS file).
- **Page-specific (e.g. CRM):** In layout’s `<style>` under a clear comment (e.g. `/* CRM */`), or in a separate `crm.css` if it grows.

Keeping **tokens and base components** in one place (design-system.css) and **section-specific** styles grouped in layout (or separate files) keeps the UI consistent and easier to change later.
