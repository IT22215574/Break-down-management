# Break-down Management (PHP + MySQL + Tailwind CSS)

1. Start Apache + MySQL in XAMPP.
2. Adjust `config.php` if needed, then run `php setup.php` (creates DB, tables, and admin `admin@example.com` / `Admin@12345`).
3. Open http://localhost/Break-down-management/ and change the admin password under Admin → Users.

Roles (all log in with email + password):
- **Admin** – creates sectors, users, and technicians; assigns IT support/users to sectors; views all records and makes printable quotations when a technician is required. Saved quotations receive globally sequential Job IDs across all sectors.
- **IT support** – records breakdowns (date/time, system, client, fixed by, status, note) in their assigned sectors.
- **User** – view-only for assigned sectors; download CSV or printable/PDF report.

When someone is editing an existing sector, company, user, technician, or breakdown, another user who tries to edit that same item waits until it is saved or the first editor leaves. If an editor closes unexpectedly, the lock expires automatically after 45 seconds.
