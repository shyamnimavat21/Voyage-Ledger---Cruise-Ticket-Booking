# Voyage Ledger — Setup

## Files
- `index.html` — the site (frontend)
- `api.php` — backend API (login, bookings, Razorpay order + verification)
- `config.php` — your DB credentials + Razorpay keys (keep this private)
- `schema.sql` — run this once to create the database tables

## 1. Database
Import `schema.sql` (phpMyAdmin → Import, or `mysql -u root -p < schema.sql`).
It creates `voyage_ledger_db` with `users`, `bookings`, and `pending_orders`.

Two admin accounts are seeded:
- `shyam@gmail.com`
- `jeet@gmail.com`

Both use the password **`ChangeMe123!`** — log in and change it (or run
`php -r "echo password_hash('yournewpass', PASSWORD_DEFAULT);"` and update
the row in `users`) before this goes live.

> A note on that hash: it was generated with Python's `bcrypt` library and
> self-verified (hash it, then immediately check it against the same
> plaintext) right before being written into `schema.sql`, so you can trust
> it unlocks exactly `ChangeMe123!` and nothing else. If you're ever handed
> a password hash from somewhere else, the same rule applies in reverse —
> a bcrypt hash can't be "decoded" by reading it, so there's no way to
> confirm what password it unlocks without generating it yourself.

## 2. Razorpay keys
1. Sign up at https://razorpay.com and go to **Settings → API Keys**.
2. Generate **Test Mode** keys first (`rzp_test_...`). Use these while building.
3. Open `config.php` and paste them in:
   ```php
   define('RAZORPAY_KEY_ID', 'rzp_test_...');
   define('RAZORPAY_KEY_SECRET', '...');
   ```
4. When you're ready to accept real payments, complete Razorpay's KYC/activation,
   switch to **Live Mode** keys, and swap them into `config.php`.

## 3. Deploy
Put all four files in the same folder on a PHP host (needs `mysqli` and
`curl` extensions, both on by default). `index.html` calls `api.php` using a
relative path, so as long as they're in the same folder on the same domain
you don't need to change anything.

If they'll live at different URLs, edit this line near the top of the
`<script>` block in `index.html`:
```js
const API_BASE = 'api.php'; // change to e.g. 'https://yourdomain.com/api.php'
```

## 4. Test the payment flow
Razorpay test mode never charges real money. Use their test card:
- Card number: `4111 1111 1111 1111`
- Any future expiry, any CVV, any name
More test cards/UPI IDs: https://razorpay.com/docs/payments/payments/test-card-upi-details/

## How payment works here
1. Client fills the booking form → **Search Sailings** → server prices the
   trip (never trusts a price from the browser) and opens a Razorpay order.
   A row is also written to `payments` with status `created`.
2. Razorpay's Checkout popup collects card/UPI details directly — your
   server and site never see or store card numbers.
3. On success, the browser sends Razorpay's payment ID + signature back to
   `api.php?action=verify_payment`, which recomputes the signature with your
   secret key. Only if it matches does the booking get written, the pending
   order get consumed, and the `payments` row flip to `paid`.
4. If the signature doesn't match, or the user cancels/declines in the
   Checkout popup, the `payments` row is marked `failed` instead — so
   abandoned or fraudulent attempts stay visible for auditing rather than
   disappearing. Nothing gets booked either way.

## Notes / things worth doing before going live
- Move `config.php` outside the web root (or block it via `.htaccess`) so it
  can never be downloaded directly.
- Consider Razorpay **webhooks** as a backup confirmation path, in case a
  user closes the tab right after paying but before `verify_payment` fires.
- The admin allow-list lives in `config.php` (`$allowedAdmins`) — update it
  when your team changes.
