# Payment Reminders – BusyPayBot integration

The **Reminders** page (Admin / Accounts) lets you upload a **bills/receivables CSV** and run your existing BusyPayBot script so email and WhatsApp reminders are sent from the app.

**Two companies:** BusyPayBot has two company folders — **JLD Minerals Private Limited** and **Jaichand Lal Daga**. Both `main.py` scripts now accept an optional CSV path. Configure one or both in `.env`; if both are set, the Reminders page shows a **Company** dropdown.

## 1. BusyPayBot change (already done)

In both `C:\BusyPayBot\JLD Minerals Private Limited\main.py` and `C:\BusyPayBot\Jaichand Lal Daga\main.py`, the script accepts an **optional CSV path** as the first argument:

- **When called from the app:** `python main.py "C:\path\to\uploaded.csv"`  
  It uses that file as the receivables export and still uses `config.json` and `master_contacts.csv` from the BusyPayBot folder.

- **When run manually** (no argument): Behaviour is unchanged; it looks for a CSV in the current folder as before.

## 2. App configuration

In your Order Processing app `.env`:

**Option A – Both companies (dropdown on Reminders page):**

```env
REMINDERS_SCRIPT_JLD_MINERALS=C:/BusyPayBot/JLD Minerals Private Limited/main.py
REMINDERS_SCRIPT_JAICHAND=C:/BusyPayBot/Jaichand Lal Daga/main.py
PYTHON_PATH=python
```

**Option B – Single company (or fallback):**

```env
REMINDERS_SCRIPT=C:/BusyPayBot/JLD Minerals Private Limited/main.py
PYTHON_PATH=python
```

Use forward slashes or escaped backslashes. If both `REMINDERS_SCRIPT_JLD_MINERALS` and `REMINDERS_SCRIPT_JAICHAND` are set, the Reminders page shows a **Company** selector; the selected company’s script is run with the uploaded CSV.

## 3. CSV format

Upload a **Busy Bills Receivable export** in the same format as your existing `BillsReceivable.csv`:

- Header rows (company name, “Bills Receivable”, date range, etc.)
- A row with columns: **Account**, Dated, Type, **Ref. No.**, Ref. Amt., **Due Amt.**, Due Date, Due Days
- One or more data rows per account (Account can be blank on continuation rows)
- “Total” rows per account are skipped by the reader

BusyPayBot matches **Account** to **master_contacts.csv** (customer_name) to get email and mobile, then sends reminders according to your existing logic (credit period, reminder interval, history).

## 4. Flow

1. Accounts (or Admin) open **Administration → Reminders**.
2. They choose the Busy receivables CSV and click **Upload CSV and send reminders**.
3. The app saves the file temporarily and runs:  
   `python "C:\BusyPayBot\JLD Minerals Private Limited\main.py" "C:\temp\reminders_xxx.csv"`
4. Working directory is the BusyPayBot folder, so `config.json` and `master_contacts.csv` are used.
5. BusyPayBot loads the uploaded CSV, matches contacts, and sends email + WhatsApp as it does when run manually.
6. Script output is shown on the Reminders page.

No change is required to your email/WhatsApp or PDF logic; only the receivables file is passed in from the app.
