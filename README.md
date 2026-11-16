# Pickle Trip

Pickle Trip is a PHP booking website backed by Supabase. PHP calls Supabase's REST API from the server; the Supabase service-role key is never sent to the browser. The PHP app still needs a PHP-capable web host, but it no longer needs XAMPP, MySQL, or a local database.

## Supabase setup

1. Create a Supabase project.
2. In the Supabase SQL Editor, run [`database.sql`](./database.sql). This creates the tables, booking/availability transaction, row-level security, and four sample courts.
3. Generate a password hash on the PHP server with `php -r "echo password_hash('YOUR-STRONG-PASSWORD', PASSWORD_DEFAULT), PHP_EOL;"`.
4. In the SQL Editor, create the first admin using that hash:

   ```sql
   insert into public.admins (username, password_hash, full_name)
   values ('admin', 'PASTE_THE_GENERATED_HASH_HERE', 'Administrator');
   ```

5. Configure these environment variables on the PHP host:

   | Variable | Value |
   | --- | --- |
   | `PICKLE_SUPABASE_URL` | Supabase project URL, such as `https://your-project.supabase.co` |
   | `PICKLE_SUPABASE_SERVICE_ROLE_KEY` | Supabase service-role key; keep private and never commit it |
   | `PICKLE_GCASH_NUMBER` | The venue's real GCash mobile number |
   | `PICKLE_OPEN_PLAY_PRICE` | Open-play fee per player in PHP (defaults to `100`) |
   | `PICKLE_COURT_RENTAL_HOURLY_PRICE` | Court-rental fee per court per hour in PHP (defaults to `250`) |

6. Deploy the PHP files to a host with PHP 7.4 or newer, HTTPS stream support, and `allow_url_fopen` enabled. Keep the Supabase URL and service-role key in the host's private environment settings, not in GitHub or browser JavaScript.

## Booking and payments

Bookings are confirmed immediately and occupy their court/time slot. Open play costs are calculated per player; court rentals are calculated per hour. The confirmation shows the configured GCash number, exact amount, and booking reference to include with the payment. Customers can submit a GCash transaction number, and an admin verifies it manually under **Manage Bookings**. This is manual payment verification—not an automatic GCash payment gateway or QRPh integration.

The public booking forms are collapsed by default. Customers can expand either form, check the calculated price, and submit a booking. Availability is checked again inside a Supabase transaction to prevent overlapping bookings from being accepted concurrently.
