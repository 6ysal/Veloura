# Local setup

## Requirements

- PHP with PDO and the PDO MySQL driver enabled
- MySQL
- Composer

## Database

Import `database/schema.sql` into MySQL. The script creates `veloura_db`, the
tables used by the application, and sample categories and products.

For an existing database, apply `database/migrations/20261119_remaining_features.sql`
once. Existing active accounts are marked verified during this migration so their
current access is preserved.

For an existing database, also apply
`database/migrations/20261119_admin_dashboard.sql` once, after the remaining
features migration. It adds the admin audit log, store settings, category fields,
and coupon creation timestamps used by the dashboard. Fresh installations already
get these from `database/schema.sql`.

If your local MySQL credentials differ from the defaults, update the connection
settings in `config/database.php`.

## Dependencies and email

Install PHP dependencies from the project root:

```text
composer install
```

Set these environment variables for the PHP/Apache process. Do not commit SMTP
credentials:

- `APP_BASE_URL` (for example `http://localhost:8000`)
- `SMTP_HOST`
- `SMTP_PORT` (587 for TLS or 465 for SSL)
- `SMTP_USERNAME`
- `SMTP_PASSWORD`
- `SMTP_ENCRYPTION` (`tls` or `ssl`)
- `MAIL_FROM_ADDRESS`
- `MAIL_FROM_NAME`

Restart Apache after changing its environment. SMTP settings are required to send
verification links and newsletter coupons. If newsletter email is unavailable,
the welcome coupon is displayed on the homepage confirmation instead.

## Coupons and offers

Administrators create and manage coupons from the Admin dashboard. Eligible,
active offers appear dynamically on the homepage and at `public/offers.php`.
Customers can copy an offer code there and apply it at checkout; checkout
revalidates eligibility, expiration, minimum order, and usage limits before
saving the order. The Offers link also shows a once-per-session notice when new
eligible coupons become available.

Newsletter subscriptions receive one email-bound welcome coupon. Re-submitting
an existing address reuses its existing coupon rather than creating another.
The existing coupon and newsletter tables are reused; no additional migration
is required beyond the migrations above.

## Administrator access

Create an account, verify its email, then run this command from the project root
to grant that existing account administrator access:

```text
php tools/promote_admin.php verified-address@example.com
```

Sign out and back in afterward. The command only runs in the CLI and will not
promote an unverified or inactive account. Administrators can then open the
Admin link in the site navigation to manage products, categories, orders,
customers, coupons, newsletter subscribers, store credit, and store settings.

The dashboard checks the current database role on every request, protects
state-changing forms with CSRF tokens, uses prepared queries and escaped output,
and records important changes in `admin_audit_logs`. Product image uploads are
restricted to validated JPEG, PNG, or WebP files up to 3 MB. Order status changes
follow explicit transitions; cancelling a pending order restores its reserved
stock. Credit transactions are append-only in the dashboard.

Suggested admin checks after setup:

1. Sign in as a regular customer and verify `public/admin.php` returns 403; sign
   out and verify it redirects to login.
2. As an administrator, create and edit a product, upload a valid image, and
   verify invalid files and invalid CSRF tokens are rejected.
3. Verify a category with products cannot be deleted, while an empty category
   can be deleted.
4. Create, edit, and archive a coupon; confirm archived coupon history remains.
5. Change a customer's store credit and confirm a new transaction appears in the
   read-only credit ledger.
6. Cancel a pending test order and confirm stock is restored; try an invalid
   status transition and confirm it is rejected.
7. Open `public/offers.php`, copy an eligible coupon, and apply it at checkout;
   verify the discount is recalculated when placing the order.
8. Subscribe with a new test email and confirm the welcome coupon is displayed.
   If SMTP is configured, confirm delivery; otherwise, confirm no sent-email
   message is shown.
9. Export newsletter subscribers and confirm the download is CSV.
10. Change shipping settings and verify checkout uses the configured fee and
   free-shipping threshold.

## Run

From the project root, start PHP's built-in server:

```text
php -S 127.0.0.1:8000
```

Open `http://127.0.0.1:8000/public/index.php`.
