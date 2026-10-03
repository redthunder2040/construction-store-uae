\# Construction Store — Inventory \& Stock Movement System- uae




A small but complete \*\*PHP + MySQL\*\* inventory application for a construction-site store:

item master, multi-project stock locations, receipts / issues / transfers, delivery notes to

sites with printing, multi-company data separation, three user levels, reports with print /

PDF / Excel export and an audit trail.



No Composer, no frameworks, no libraries — plain PHP (PDO) plus Bootstrap from a CDN.

It runs on a normal \*\*XAMPP\*\* installation by unzipping one folder and importing one SQL file.



\---



\## 1. Requirements



| Component | Version |

|---|---|

| XAMPP (Apache + MySQL/MariaDB + PHP) | 8.0 or newer (PHP 7.4 also works) |

| PHP extensions | `pdo\_mysql`, `mbstring`, `fileinfo` (all enabled in XAMPP by default) |

| Browser | any modern browser (Chrome, Edge, Firefox) |



\---



\## 2. Installation on XAMPP (5 minutes)



1\. \*\*Copy the folder.\*\*

&#x20;  Unzip `construction-store` into your XAMPP web root so you get

&#x20;  `C:\\xampp\\htdocs\\construction-store` (Windows) or `/Applications/XAMPP/htdocs/construction-store` (macOS).



2\. \*\*Start Apache and MySQL\*\* from the XAMPP Control Panel.



3\. \*\*Create the database.\*\*

&#x20;  Open <http://localhost/phpmyadmin> → \*\*New\*\* → database name `construction\_store`,

&#x20;  collation `utf8mb4\_unicode\_ci` → \*\*Create\*\*.

&#x20;  Select the new database → \*\*Import\*\* tab → choose

&#x20;  `htdocs/construction-store/install.sql` → \*\*Import\*\*.

&#x20;  The file creates all 12 tables \*\*and\*\* loads the sample data

&#x20;  (3 companies, 200 items, 32 projects, 18 suppliers, \~1 300 stock movements, 30 delivery notes).



4\. \*\*Check the connection settings\*\* in `config.php` (XAMPP defaults are already set):



&#x20;  ```php

&#x20;  define('DB\_HOST', '127.0.0.1');

&#x20;  define('DB\_NAME', 'construction\_store');

&#x20;  define('DB\_USER', 'root');

&#x20;  define('DB\_PASS', '');        // XAMPP default is an empty password

&#x20;  ```



5\. \*\*Open the app:\*\* <http://localhost/construction-store/>



6\. \*\*Sign in\*\* with one of the accounts below and change the passwords

&#x20;  (Users → key icon, or My account → Change password).



\### Default accounts



| Role | Username | Password | Company scope |

|---|---|---|---|

| Administrator | `admin` | `Admin@123` | all companies |

| Store Manager | `manager1` | `Manager@123` | Al Noor Contracting LLC |

| Store Keeper | `keeper1` | `Keeper@123` | Al Noor Contracting LLC |

| Store Manager | `manager2` | `Manager@123` | Gulf Build Construction Co. W.L.L. |

| Store Keeper | `keeper2` | `Keeper@123` | Gulf Build Construction Co. W.L.L. |

| Store Manager | `manager3` | `Manager@123` | Desert Rose Contracting \& Trading |

| Store Keeper | `keeper3` | `Keeper@123` | Desert Rose Contracting \& Trading |



\---



\## 3. What each user level can do



| Ability | Administrator | Store Manager | Store Keeper |

|---|:--:|:--:|:--:|

| Dashboard, item list, reports | ✔ | ✔ | ✔ |

| Record movements (IN / OUT / ADJUST) | ✔ | ✔ | ✔ |

| Transfer stock between stores/sites | ✔ | ✔ | ✔ |

| Create / edit delivery notes | ✔ | ✔ | ✔ |

| Issue or receive a delivery note (moves stock) | ✔ | ✔ | — |

| Add / edit / delete items \& masters | ✔ | ✔ | — |

| Delete movements, transfers, delivery notes | ✔ | ✔ | — |

| Export to Excel / CSV | ✔ | ✔ | — |

| Manage users, companies, view audit trail | ✔ | — | — |



Authorisation is enforced \*\*server-side on every page\*\* (`require\_can()` / `guard\_company()`),

not just by hiding menu items.



\---



\## 4. Feature list



\*\*Item master\*\*

\- Item code, name, category, UOM, quantity on hand, reorder level, unit cost, storage location

&#x20; (main store or any project), preferred supplier, rack/bin, active flag.

\- Full CRUD: add, edit, deactivate and delete (items with history are deactivated, never deleted).

\- Editing the quantity posts an `ADJUST` movement so the ledger always explains the balance.



\*\*Movements\*\*

\- Five types: `IN` (receipt), `OUT` (issue to site), `TRANSFER\_OUT`, `TRANSFER\_IN`, `ADJUST`.

\- Stock is updated \*\*transactionally\*\* (row locks + `stock\_locations` per project) and

&#x20; `items.quantity` always stays equal to the total of its locations.

\- Filter by company, location, type, date range, item or reference; delete reverses the balance.



\*\*Transfers\*\*

\- One click posts the paired out/in legs with a shared transfer reference.

\- Register with printable version and Excel export; reversing a transfer reverses both legs.



\*\*Delivery notes to sites\*\*

\- Header: DN number (auto-numbered per company), date, source store, destination project,

&#x20; supplier, issued-to, vehicle, driver, status (Draft / Issued / Received / Cancelled).

\- Item lines added with a live search picker; availability is validated against the source store.

\- Issuing the note posts the stock movement; editing or deleting reverses it automatically.

\- \*\*Print view\*\* with letterhead, signature blocks (store keeper / manager / site / stamp)

&#x20; and \*\*Excel + CSV export of the document\*\*.



\*\*Reports (all print-ready, all exportable)\*\*

1\. Stock movement register

2\. Item stock card / ledger with running balance and opening balance

3\. Stock by location \& valuation

4\. Reorder / low-stock report

5\. Transfer register

6\. Delivery note register

7\. Item master listing

8\. Stock valuation summary (by company / project / category)

9\. Audit trail



\*\*Printing \& letterhead\*\*

\- Every report is a print-CSS page: use \*\*Print\*\* for a direct print or \*Save as PDF\* in the

&#x20; browser dialog — no PDF library needed.

\- Upload a company letterhead image (PNG/JPG) on the company record and it is printed at the top

&#x20; of every report and delivery note instead of the text header.



\*\*Multi-company\*\*

\- Separate companies, each with its own projects, suppliers, items, users and document numbering.

\- Administrators can switch company or view all companies from the top bar; other users are locked

&#x20; to their own company on every query.



\*\*Audit trail\*\*

\- Sign-ins (successful and failed) plus every insert, update, delete and export, with user, record,

&#x20; details, IP address and timestamp.



\---



\## 5. Project structure



```

construction-store/

├── config.php                 database + app settings, session start

├── helpers.php                PDO, RBAC, CSRF, stock engine, exports, letterhead printing

├── install.sql                schema + full sample data (import this)

├── index.php                  dashboard (KPIs, 6-month flow chart, reorder alerts)

├── login.php / logout.php     authentication

├── item\_lookup.php            AJAX item search (JSON)

├── stock\_lookup.php           AJAX availability check (JSON)

├── items.php / item\_form.php

├── movements.php / movement\_form.php

├── transfers.php

├── delivery\_notes.php / delivery\_note\_form.php / delivery\_note\_print.php

├── companies.php / company\_form.php      letterhead upload lives here

├── projects.php / project\_form.php

├── suppliers.php / supplier\_form.php

├── settings.php               categories \& units of measure

├── users.php / user\_form.php

├── audit\_logs.php

├── profile.php                own account + change password

├── includes/

│   ├── header.php / footer.php        app chrome

│   ├── dn\_head.php / dn\_foot.php      print-first report layout

│   └── dn\_engine.php                  delivery-note posting engine

├── reports/                   index, movements, ledger, inventory, low\_stock,

│                              transfers, dn\_register, items\_list, valuation, audit

└── assets/

&#x20;   ├── css/style.css          the whole UI (light theme, print rules)

&#x20;   ├── js/app.js              item picker, DN builder, availability checks

&#x20;   └── letterheads/           uploaded letterhead images

```



\---



\## 6. How stock quantity stays correct



\* `stock\_locations` holds the quantity of each item \*\*per location\*\*

&#x20; (`project\_id = 0` means the main store / central warehouse).

\* `items.quantity` is the company-wide total and is maintained by

&#x20; `apply\_stock\_change()` inside a database transaction with `SELECT … FOR UPDATE` row locks.

\* Every quantity change writes a row in `movements`, so the balance can always be reconstructed

&#x20; from the ledger (that is exactly what the stock-card report does).

\* An issue that would take a location negative is rejected with a clear message.



\---



\## 7. Notes, tips and troubleshooting



\* \*\*Login loop / “Security token expired”?\*\* Make sure cookies are enabled and the session folder

&#x20; is writable; in a fresh XAMPP it is.

\* \*\*Letterhead upload fails:\*\* the web server needs write access to `assets/letterheads`.

&#x20; On Linux/macOS run `chmod 775 assets/letterheads`.

\* \*\*Excel export:\*\* the `.xls` download is an HTML table with an `.xls` extension — Excel, LibreOffice

&#x20; and Google Sheets all open it correctly, and it keeps formatting, colours and totals.

&#x20; Use the \*\*CSV\*\* button if you need a plain comma-separated file (UTF-8 BOM included so Excel

&#x20; shows Arabic/European characters correctly).

\* \*\*Resetting the demo data:\*\* re-import `install.sql` in phpMyAdmin — it drops and recreates

&#x20; every table, so start fresh any time.

\* \*\*Changing a UOM code\*\* in \*Categories \& UOM\* updates every item that uses it.

\* Before going live: delete the demo accounts, create your own, upload your real letterhead and

&#x20; keep only your own company records.



\---



\## 8. Sample data that ships in `install.sql`



| Table | Rows |

|---|---|

| companies | 3 |

| users | 7 (3 roles) |

| projects | 12 (4 per company) |

| suppliers | 18 |

| categories | 12 |

| units of measure | 16 |

| items | \*\*200\*\* |

| stock\_locations | 1 per item/location holding stock |

| movements | \~1 300 (IN, OUT, TRANSFER\_OUT, TRANSFER\_IN, ADJUST) |

| delivery\_notes / delivery\_note\_items | 30 / \~120 lines |

| audit\_logs | 25 seeded events |







