# CRM Company Profile (Mines & Minerals)

When you click a company/party in CRM (e.g. from **Admin → Parties** → CRM icon, or from the CRM dashboard), a **Company profile** page opens with all customer details a sales owner can view and edit.

## At a glance

- **Year with us** – Year of association  
- **Order frequency** – Regular / Occasional / Trial  
- **Last order date** – Last order placed  
- **Last visit date** – Last sales visit  
- **Next follow-up date** – Planned next follow-up  
- **Sales owner** – Assigned user  
- **Payment track** – Good / Delayed / Overdue / N/A  

## Company profile sections

### Overview
- Region (e.g. Morbi, Export)
- Product category (tiles, sanitary, tableware, other)
- Year of association
- Order frequency
- Number of plants
- Last order / last visit / next follow-up dates
- Assigned sales owner
- Payment track

### Products & capacity
- **Products introduced** – Your products introduced to this customer (Ball Clay, Kaolin, Feldspar, etc.)
- **Monthly production** – Customer’s production capacity (e.g. 50,000 sq m/day)
- **Monthly consumption** – Consumption of your products (e.g. 50 MT, 100 trucks)
- **Target volume** – Sales target for this account (e.g. 200 MT/year)

### Technical
- **Factory locations** – Plant addresses or areas
- **Technical notes** – Body formulation, clay requirements, R&D notes

### Commercial
- Credit limit (₹)
- Payment terms (days, e.g. 90, 180)

### Notes
- General notes about the customer

## Other blocks on the same page

- **Contacts** – Multiple contact persons (name, role, phone, email, primary)
- **Deals** – Pipeline opportunities linked to this party
- **Samples & trials** – Sample sent, trial dates, outcome, technical feedback
- **Receivables & credit** – Invoice/payment entries, outstanding, credit limit
- **Activities** – Calls, meetings, visits, WhatsApp, notes
- **Orders** – Link to orders list filtered by this party

## Database

New fields are in the `parties` table (migration `014_crm_company_profile.sql`):

- `products_introduced`, `monthly_consumption`, `year_of_association`
- `order_frequency`, `last_order_date`, `last_visit_date`, `payment_track`
- `target_volume`, `next_followup_date`, `assigned_sales_owner`
- `number_of_plants`, `general_notes`

Run migration: `php scripts/run_migration.php 014`
