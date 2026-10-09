# MilkBook 🥛
**Daily Milk Delivery & Monthly Billing Management Web Application**

A mobile-first web app tailored for an independent milkman who delivers milk to households daily and collects payments monthly.

---

## 🌟 Tech Stack
- **Frontend**: HTML5, CSS3, Bootstrap 5 (via CDN), Bootstrap Icons (via CDN), Vanilla JavaScript (`fetch` API, no jQuery).
- **Backend**: Core PHP 8 (no external frameworks, no Composer, PDO only).
- **Database**: MySQL / MariaDB (InnoDB, `utf8mb4_unicode_ci`, strict foreign keys, sensible indexes).
- **Architecture**: Decoupled JSON REST API served by PHP, consumed asynchronously by static HTML pages.
- **Localization**: Currency is **₹ (Indian Rupee)**, Timezone is **Asia/Kolkata**.
- **Design Philosophy**: Mobile-first touch-friendly design built for one-handed operation on smartphones in the field.

---

## 📁 Directory Structure
```
milkbook/
├── config/
│   ├── config.php          # Database credentials, constants (QTY_STEP=0.25), timezone
│   └── db.php              # Singleton PDO database connector with prepared statements
├── includes/
│   └── helpers.php         # JSON envelope, request decoder, validations, get_rate_for(), error handlers
├── api/
│   ├── customers.php       # CRUD operations for customer routes and delivery commitments
│   ├── rates.php           # Milk rate history and effective date management
│   └── settings.php        # Business profile and invoice settings
├── database/
│   ├── schema.sql          # Complete relational schema (7 tables) with foreign keys
│   └── seed.sql            # 8 sample route customers, starting milk rates, and business details
├── assets/
│   ├── css/
│   │   └── style.css       # Mobile-first custom dairy aesthetic, cards, pills, responsive tables
│   └── js/
│       ├── api.js          # Fetch wrapper managing JSON envelope and error toasts
│       └── ui.js           # Toast alerts, confirm modal, ₹ & date formatters, escapeHtml
├── index.html              # Dashboard with quick summary and nav
├── customers.html          # Searchable list (cards on mobile, table on desktop), add/edit modal
├── settings.html           # Business details & milk rate history / new rate form
├── deliveries.html         # Step 2: Daily Delivery Sheet (Coming Soon stub)
├── invoices.html           # Step 3: Monthly Billing & Invoices (Coming Soon stub)
├── payments.html           # Step 4: Payment Collections & Ledger (Coming Soon stub)
└── README.md               # Setup and usage documentation
```

---

## 🚀 Setup & Installation

### Option A: Local Development with XAMPP (Windows)
1. **Start MySQL in XAMPP**:
   Open XAMPP Control Panel and start the **MySQL** module (or verify MySQL is running on port 3306).

2. **Import Database Schema & Seed Data**:
   Open PowerShell or Command Prompt and run:
   ```powershell
   # Create database schema
   Get-Content database/schema.sql | C:\xampp\mysql\bin\mysql.exe -u root

   # Insert sample customers, rates, and default settings
   Get-Content database/seed.sql | C:\xampp\mysql\bin\mysql.exe -u root
   ```

3. **Start the PHP Development Server**:
   From the root `milkbook` directory, run:
   ```powershell
   C:\xampp\php\php.exe -S localhost:8000
   ```
   *(Or if PHP is in your system PATH: `php -S localhost:8000`)*

4. **Open in Browser**:
   Navigate to [http://localhost:8000](http://localhost:8000) or directly access:
   - Dashboard: `http://localhost:8000/index.html`
   - Customer Management: `http://localhost:8000/customers.html`
   - Settings & Rates: `http://localhost:8000/settings.html`

---

### Option B: Deployment on cPanel Shared Hosting
1. **Upload Files**:
   Upload all files and directories to your `public_html/` or a subfolder (e.g. `public_html/milkbook/`).
2. **Create MySQL Database**:
   - Go to **cPanel > MySQL Databases** and create a database (e.g. `user_milkbook`).
   - Create a database user, assign a password, and grant **ALL PRIVILEGES** to the database.
3. **Import SQL**:
   - Open **phpMyAdmin** in cPanel.
   - Select your database and import `database/schema.sql`, then `database/seed.sql`.
4. **Configure Credentials**:
   Edit `config/config.php` (or set environment variables in cPanel):
   ```php
   define('DB_HOST', 'localhost');
   define('DB_NAME', 'user_milkbook');
   define('DB_USER', 'user_dbuser');
   define('DB_PASS', 'your_strong_password');
   ```
5. **Access Application**:
   Open your domain: `https://yourdomain.com/milkbook/`.

---

## 📡 API Endpoints (Step 1)

Every response follows the unified JSON envelope:
- **Success**: `{ "success": true, "data": ... }`
- **Error**: `{ "success": false, "error": { "message": "...", "fields": { ... } } }`

### Customers (`/api/customers.php`)
- `GET /api/customers.php`: Lists customers with summary stats.
  - Query params: `search` (name, mobile, address), `status` (`active`, `inactive`, `all`).
- `GET /api/customers.php?id={id}`: Returns a single customer with delivery count and ledger balance.
- `POST /api/customers.php`: Creates a new customer.
  - Validates: `name` (required), `mobile` (10-digit Indian number, warns on duplicates), `committed_qty` (> 0 and multiple of `0.25`), optional `custom_rate`, `route_order`, `start_date`.
- `PUT /api/customers.php?id={id}`: Updates customer details.
- `DELETE /api/customers.php?id={id}`:
  - If deliveries exist: blocks hard deletion and **soft-deactivates** (`is_active = 0`).
  - If no deliveries exist: deletes the record permanently.

### Milk Rates (`/api/rates.php`)
- `GET /api/rates.php`: Returns the current active standard rate (`effective_from <= TODAY`) and complete rate change timeline.
- `POST /api/rates.php`: Registers a new standard milk rate with `rate_per_litre` and `effective_from`.

### Business Settings (`/api/settings.php`)
- `GET /api/settings.php`: Returns all key/value pairs (`business_name`, `business_phone`, `business_address`, `upi_id`, `invoice_prefix`, `invoice_footer`).
- `PUT /api/settings.php`: Updates business configuration.

### Payments & Collections (`/api/payments.php`)
- `GET /api/payments.php`: Lists payments with customer, date range, and payment mode filters.
- `POST /api/payments.php`: Atomically records payment: links customer's latest invoice, writes credit entry to `ledger`, recomputes invoice `paid_amount` & status (`unpaid`, `partial`, `paid`, `overpaid`), and returns updated balance with friendly explanation.
- `DELETE /api/payments.php?id={id}`: Deletes payment, reverses ledger credit, and recomputes attached invoice status.

### Special Adjustments (`/api/adjustments.php`)
- `POST /api/adjustments.php`: Records auditable adjustment in the ledger.
  - Types: `writeoff` / `discount` (reduces pending dues via ledger credit), `refund` (returns advance credit via ledger debit), `manual`.
  - Requires `reason` for audit integrity.

### Ledger Balances (`/api/balances.php`)
- `GET /api/balances.php`: Returns customer list with live ledger balance (`SUM(debit) - SUM(credit)`), status badge (`pending`, `advance`, `clear`), and latest invoice info.

### Customer Statement (`/api/statement.php`)
- `GET /api/statement.php?customer_id={id}&from={date}&to={date}`: Returns chronological ledger timeline with opening balance, debits, credits, and running balance for printable A4 statement.

### Dashboard Analytics (`/api/dashboard.php`)
- `GET /api/dashboard.php`: Real-time morning desk analytics: today's delivery progress, sheet saved status, monthly billed & collected, ledger receivables & prepaid advance held, top debtor list with WhatsApp reminder deep-links, and monthly route report.
- `GET /api/dashboard.php?export=csv`: Downloads complete monthly report as a UTF-8 CSV spreadsheet.

### Authentication & CSRF (`/api/auth.php`)
- `POST /api/auth.php?action=login`: Single-admin login with bcrypt verification and session initiation.
- `POST /api/auth.php?action=logout`: Destroys session.
- `GET /api/auth.php?action=status`: Checks login state and issues CSRF token.

---

## 🔒 Security Hardening & Optional Authentication

1. **Optional Single-Admin Login**:
   - Configured in `config/config.php` via `AUTH_ENABLED` (default `false` for instant local use).
   - When set to `true`:
     - Every API request checks session authentication.
     - Unauthenticated requests return `401 Unauthorized`.
     - State-modifying requests (`POST`, `PUT`, `DELETE`) require a valid `X-CSRF-Token` header.
     - Admin credentials: username `admin`, default password `milkman123` (stored as bcrypt hash).
     - Login page: `login.html`.

2. **Apache Hardening (`.htaccess`)**:
   - Denies direct browser access to `/config`, `/includes`, `/database`, and `/tests`.
   - Blocks direct downloads of `.sql`, `.md`, `.env`, and `.log` files.
   - Disables directory indexing (`Options -Indexes`).

3. **Database Security**:
   - Strictly prepared statements with parameter binding everywhere (`PDO::ATTR_EMULATE_PREPARES => false`).
   - Exception masking in production: detailed stack traces logged to error log, clean JSON envelopes returned to client.

---

## 🧪 Automated Scenario Test Suite (CLI)

MilkBook includes a comprehensive command-line test runner verifying all 10 core financial and business logic rules:
```bash
# Run using XAMPP PHP
C:\xampp\php\php.exe tests/scenarios.php

# Or standard PHP CLI
php tests/scenarios.php
```

### Verified Scenarios:
1. **Scenario 1**: 750 billed, 800 paid $\to$ customer balance becomes ₹-50, next bill shows -₹50 advance credit deducted.
2. **Scenario 2**: 750 billed, 500 paid $\to$ customer balance becomes ₹+250, next bill shows +₹250 carried forward.
3. **Scenario 3**: Mid-month rate change (e.g. ₹60 to ₹66) generates grouped line items on the bill.
4. **Scenario 4**: Customer custom rate overrides default standard milk rate.
5. **Scenario 5**: Unrecorded days handling (`fill` vs `ignore`).
6. **Scenario 6**: Void invoice unlocks delivery records and reverses ledger debit.
7. **Scenario 7**: Delivery rows locked by an invoice cannot be modified.
8. **Scenario 8**: Leap-year February (29 days in 2028).
9. **Scenario 9**: $0.25\text{ L}$ quantity step validation (allows $0.25, 0.50, 1.75$, rejects $0.33, 1.10$).
10. **Scenario 10**: Deleting a payment reverses ledger credit and recalculates invoice status.

---

## 💾 Database Backup Tip

To create an automatic or manual snapshot of the milk delivery records and financial ledger, run `mysqldump`:
```bash
# Backup on Windows / XAMPP
C:\xampp\mysql\bin\mysqldump.exe -u root milkbook > milkbook_backup.sql

# Backup on Linux / cPanel cron
mysqldump -u <db_user> -p'<db_password>' <db_name> > backup_$(date +\%Y\%m\%d).sql
```

---

## 🗺️ Project Milestones (All Steps Complete)
- **Step 1 (Complete)**: Relational schema, PDO singleton, Customers CRUD, Milk Rate history, Settings, mobile-first design system.
- **Step 2 (Complete)**: Daily Delivery Sheet, quick-tap delivery logging, quantity steppers, rate snapshots, invoice lock protection, and Monthly Register notebook matrix.
- **Step 3 (Complete)**: Monthly Invoicing engine, live calculation preview, ledger debit tracking, printable A4 statement modal, and WhatsApp bill links.
- **Step 4 (Complete)**: Payment collection center, live balance calculation hints, ledger credit synchronization, write-offs & discounts, customer statement print, dashboard KPIs with WhatsApp reminders & CSV export, optional admin auth, and CLI test suite.
