#!/usr/bin/env python3
"""
Payment reminders – read bills/receivables CSV and send email/WhatsApp reminders.
Called from the app (Admin > Reminders) with optional CSV path as first argument.

CSV format (same as CRM receivables import):
  - Party/Customer name (required)
  - Amount / Due / Balance (required)
  - Reference / Invoice no (optional)
  - Date / Due date (optional)
  - Email, Phone (optional – for delivery; otherwise add your own lookup)

Usage:
  python send_reminders.py              # run without file (e.g. test)
  python send_reminders.py /path/to.csv # run with uploaded CSV from app
"""
import csv
import sys
from datetime import datetime
from pathlib import Path

# Flexible header names (lowercase) -> our key
PARTY_HEADERS = ['party name', 'customer', 'party', 'customer name', 'name', 'buyer', 'consignee']
AMOUNT_HEADERS = ['amount', 'due', 'balance', 'outstanding', 'amount due', 'balance due', 'pending']
REFERENCE_HEADERS = ['invoice no', 'invoice #', 'bill no', 'invoice', 'reference', 'inv no']
DATE_HEADERS = ['date', 'due date', 'invoice date', 'bill date']
EMAIL_HEADERS = ['email', 'e-mail', 'email id']
PHONE_HEADERS = ['phone', 'mobile', 'contact', 'whatsapp']


def find_col(headers, candidates):
    headers_lower = [h.strip().lower() for h in headers]
    for c in candidates:
        if c in headers_lower:
            return headers_lower.index(c)
    return None


def parse_amount(s):
    s = (s or '').strip().replace(',', '')
    try:
        return float(s)
    except ValueError:
        return 0.0


def load_csv(path):
    rows = []
    with open(path, newline='', encoding='utf-8-sig', errors='replace') as f:
        content = f.read()
    if content.startswith('\xef\xbb\xbf'):
        content = content[3:]
    reader = csv.reader(content.splitlines())
    headers = next(reader, None)
    if not headers:
        return [], 'CSV has no header row'
    party_col = find_col(headers, PARTY_HEADERS)
    amount_col = find_col(headers, AMOUNT_HEADERS)
    if party_col is None:
        return [], 'Could not find Party/Customer name column'
    if amount_col is None:
        return [], 'Could not find Amount/Due column'
    ref_col = find_col(headers, REFERENCE_HEADERS)
    date_col = find_col(headers, DATE_HEADERS)
    email_col = find_col(headers, EMAIL_HEADERS)
    phone_col = find_col(headers, PHONE_HEADERS)
    for row in reader:
        if len(row) <= max(party_col, amount_col):
            continue
        party = (row[party_col] or '').strip()
        amount_raw = (row[amount_col] or '').strip()
        if not party or not amount_raw:
            continue
        amount = parse_amount(amount_raw)
        if amount <= 0:
            continue
        ref = (row[ref_col] or '').strip() if ref_col is not None else ''
        date_val = (row[date_col] or '').strip() if date_col is not None else ''
        email = (row[email_col] or '').strip() if email_col is not None else ''
        phone = (row[phone_col] or '').strip() if phone_col is not None else ''
        rows.append({
            'party': party,
            'amount': amount,
            'reference': ref,
            'date': date_val,
            'email': email,
            'phone': phone,
        })
    return rows, None


def send_email(email, party, amount, reference, date_val):
    """Placeholder: replace with your email logic (SMTP, SendGrid, etc.)."""
    print(f"  [EMAIL] To: {email or '(no email)'} | {party} | Amount: {amount} | Ref: {reference}")


def send_whatsapp(phone, party, amount, reference, date_val):
    """Placeholder: replace with your WhatsApp logic (Twilio, API, etc.)."""
    print(f"  [WHATSAPP] To: {phone or '(no phone)'} | {party} | Amount: {amount} | Ref: {reference}")


def main():
    csv_path = sys.argv[1] if len(sys.argv) > 1 else None
    print(f"[{datetime.now().isoformat()}] Payment reminders started.")
    if csv_path:
        if not Path(csv_path).is_file():
            print(f"Error: CSV file not found: {csv_path}")
            return 1
        rows, err = load_csv(csv_path)
        if err:
            print(f"Error: {err}")
            return 1
        print(f"Loaded {len(rows)} rows from CSV.")
        for i, r in enumerate(rows, 1):
            print(f"Row {i}: {r['party']} | {r['amount']} | {r['reference']} | {r['date']}")
            send_email(r['email'], r['party'], r['amount'], r['reference'], r['date'])
            send_whatsapp(r['phone'], r['party'], r['amount'], r['reference'], r['date'])
        print(f"Done. Processed {len(rows)} records.")
    else:
        print("No CSV path provided. Run with a file path to process bills/receivables.")
    return 0


if __name__ == "__main__":
    sys.exit(main())
