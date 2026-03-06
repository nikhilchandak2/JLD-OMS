# CRM Module – Workings (Order Processing JLD)

This document describes how a **CRM (Customer Relationship Management)** module will work inside this webapp, how it reuses existing data, and what will be built.

---

## 1. How the CRM Fits In

- **Parties** in your app are already customers/suppliers (name, contact person, phone, email, address). The CRM will treat **Party** as the **Account/Customer**.
- **Orders** and **Dispatches** stay as-is; the CRM adds **sales pipeline** (leads → opportunities → won/lost) and **activities** (calls, meetings, notes) around the same parties.
- Same stack: PHP, `Router` in `public/index.php`, Controllers + Repositories + Models, Bootstrap templates, existing auth (entry/admin).

---

## 2. CRM Entities (High Level)

| Entity        | Purpose | Links to existing |
|---------------|--------|-------------------|
| **Party**     | Account/Customer (already exists) | — |
| **Contact**   | Multiple people per party (name, role, phone, email, is_primary) | `party_id` → `parties.id` |
| **Lead**      | Incoming opportunity (title, source, value, stage, assigned_to) | Optional `party_id`; can create Party when converting |
| **Deal**      | Sales opportunity with value and stage (e.g. Qualified → Proposal → Won/Lost) | `party_id`, optional `lead_id` |
| **Activity**  | Call / Meeting / Note / Email log with date and description | `party_id`, optional `deal_id`, `contact_id`, `created_by` |

---

## 3. Data Model (New Tables)

### 3.1 `crm_contacts`

- One party can have many contacts (e.g. multiple people at same company).
- Fields: `id`, `party_id` (FK → parties), `name`, `role`, `phone`, `email`, `is_primary` (bool), `created_at`, `updated_at`.

### 3.2 `crm_leads`

- Leads are potential customers; can later be linked to a Party or create one when converting to Deal.
- Fields: `id`, `title`, `company_name`, `contact_name`, `phone`, `email`, `source` (e.g. website, referral), `value` (decimal), `stage` (e.g. new, contacted, qualified, converted, lost), `party_id` (nullable, set when linked to existing party), `assigned_to` (user_id), `notes`, `created_at`, `updated_at`.

### 3.3 `crm_deals`

- Deals are opportunities tied to a party (and optionally to a lead).
- Fields: `id`, `party_id` (FK → parties), `lead_id` (nullable FK → crm_leads), `title`, `value` (decimal), `stage` (e.g. qualified, proposal, negotiation, won, lost), `expected_close_date`, `assigned_to` (user_id), `notes`, `created_at`, `updated_at`.

### 3.4 `crm_activities`

- Log calls, meetings, notes, emails against a party (and optionally a deal/contact).
- Fields: `id`, `party_id` (FK), `deal_id` (nullable), `contact_id` (nullable), `type` (call, meeting, note, email), `subject`, `description` (text), `activity_date` (date/time), `created_by` (user_id), `created_at`, `updated_at`.

### 3.5 Pipeline stages (config, not new table)

- **Leads:** e.g. `new` → `contacted` → `qualified` → `converted` | `lost`.
- **Deals:** e.g. `qualified` → `proposal` → `negotiation` → `won` | `lost`.
- Stored in config (e.g. `config/crm_stages.php`) or in a small `crm_stages` table if you want them editable later.

---

## 4. API Design (REST, under `/api`, auth required)

- **Contacts**  
  - `GET /api/crm/parties/{partyId}/contacts` – list contacts for a party  
  - `GET /api/crm/contacts/{id}` – get one contact  
  - `POST /api/crm/parties/{partyId}/contacts` – create contact  
  - `PUT /api/crm/contacts/{id}` – update  
  - `DELETE /api/crm/contacts/{id}` – delete  

- **Leads**  
  - `GET /api/crm/leads` – list (filter by stage, assigned_to)  
  - `GET /api/crm/leads/{id}` – get one  
  - `POST /api/crm/leads` – create  
  - `PUT /api/crm/leads/{id}` – update (including stage, e.g. “convert to deal”)  
  - `DELETE /api/crm/leads/{id}` – delete (soft delete optional)  

- **Deals**  
  - `GET /api/crm/deals` – list (filter by party_id, stage, assigned_to)  
  - `GET /api/crm/deals/{id}` – get one (with party name, activities count, etc.)  
  - `POST /api/crm/deals` – create (party_id required; lead_id optional)  
  - `PUT /api/crm/deals/{id}` – update (e.g. stage, value)  
  - `DELETE /api/crm/deals/{id}` – delete (soft delete optional)  

- **Activities**  
  - `GET /api/crm/activities` – list (filter by party_id, deal_id, type, date range)  
  - `GET /api/crm/activities/{id}` – get one  
  - `POST /api/crm/activities` – create  
  - `PUT /api/crm/activities/{id}` – update  
  - `DELETE /api/crm/activities/{id}` – delete  

- **CRM summary (for dashboard)**  
  - `GET /api/crm/summary` – counts: leads by stage, deals by stage, activities due today / overdue (optional).

---

## 5. Web Routes & Pages

- `GET /crm` – CRM dashboard (summary, recent leads/deals, quick links).
- `GET /crm/parties` – Party list with CRM lens (e.g. “Contacts”, “Deals”, “Last activity”). Can reuse existing Party list and add CRM columns/actions.
- `GET /crm/parties/{id}` – Party detail: contacts, deals, activities, link to Orders for this party.
- `GET /crm/leads` – Lead list + pipeline view (columns by stage).
- `GET /crm/leads/new` – New lead form.
- `GET /crm/leads/{id}` – Lead detail (edit, convert to deal → create Party if needed + create Deal).
- `GET /crm/deals` – Deal list + pipeline (board or table).
- `GET /crm/deals/new` – New deal (select party, optional lead).
- `GET /crm/deals/{id}` – Deal detail (stages, activities, value).
- Activities can be managed from Party detail and Deal detail (inline list + add modal), not necessarily a standalone “activities” page.

---

## 6. Flow Examples

### 6.1 New lead → convert to deal (new party)

1. User creates a **Lead** (company name, contact, source, value).
2. When ready, user clicks “Convert to deal”.
3. Backend: if no `party_id`, create a **Party** from lead’s company/contact; create **Deal** with `party_id` and `lead_id`; set lead stage to `converted`.

### 6.2 Existing party → deal

1. From **Party** detail, user clicks “Add deal”.
2. Form: title, value, stage, expected close date, optional link to a lead.
3. Backend: create **Deal** with `party_id`.

### 6.3 Log activity (call/meeting/note)

1. From **Party** or **Deal** page, user clicks “Log call” / “Log meeting” / “Add note”.
2. Form: type, subject, description, date/time, optional contact.
3. Backend: create **Activity** with `party_id`, optional `deal_id`, `contact_id`, `created_by`.

### 6.4 Party ↔ Orders (existing)

- Party detail page shows “Orders” for this party (existing `GET /api/orders?party_id=…` or similar). No change to Orders module; CRM only links to it.

---

## 7. Permissions

- Use existing roles: **entry** and **admin** can access CRM (create/edit leads, deals, contacts, activities). **view** can have read-only CRM if you add it.
- Same `AuthMiddleware` and `hasAnyRole(['entry','admin'])` (or view) in CRM controllers.

---

## 8. Tech Alignment With Current App

- **Router:** Add routes in `public/index.php` (e.g. under `/api/crm/*` and `/crm`).
- **Controllers:** e.g. `CrmController` (web), `CrmLeadController`, `CrmDealController`, `CrmContactController`, `CrmActivityController` (API). Or one `CrmController` for web and one resource per entity for API.
- **Repositories:** `CrmContactRepository`, `CrmLeadRepository`, `CrmDealRepository`, `CrmActivityRepository` (CRUD + filters).
- **Models:** `CrmContact`, `CrmLead`, `CrmDeal`, `CrmActivity` (like `Party`, `Order`).
- **Templates:** `templates/crm/dashboard.php`, `crm/parties.php`, `crm/leads.php`, `crm/deals.php`, `crm/party-detail.php`, etc., using existing `layout.php` and Bootstrap/Inter styling.
- **Frontend:** Fetch from API with JS (like parties/products), modals for forms, optional Kanban for deal pipeline.

---

## 9. Suggested Implementation Order

1. **Migration** – Create `crm_contacts`, `crm_leads`, `crm_deals`, `crm_activities`.
2. **Config** – `config/crm_stages.php` for lead and deal stages.
3. **Models + Repositories** – Contact, Lead, Deal, Activity.
4. **API** – Contacts, Leads, Deals, Activities + summary endpoint.
5. **Web** – CRM dashboard, then leads list/detail, deals list/detail, party detail CRM section (contacts + deals + activities).
6. **Convert lead to deal** – API + UI to create Party (if needed) and Deal from Lead.
7. **Navigation** – Add “CRM” to main nav (layout) pointing to `/crm`.

---

## 10. Optional Later Additions

- **Dashboard widgets:** Lead/deal pipeline charts, activities due.
- **Editable stages:** Store stages in DB and allow admin to rename/reorder.
- **Reminders:** Activities with “due date” and simple reminder list or email.
- **Reports:** Deal value by stage, win rate, lead source breakdown.

---

This gives you a clear picture of how the CRM will work and how it plugs into your existing Order Processing JLD app. If you want to proceed, the next step is implementing the migration and the first set of models/repos/controllers as above.
