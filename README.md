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

Open-play customers must pay the displayed total by GCash before submitting a booking and provide the transaction number with the request. The booking occupies its court/session spot after submission, while the venue manually verifies the payment under **Manage Bookings**. Court rentals can still be booked first and paid afterward. Open play costs are calculated per player; court rentals are calculated per hour. This is manual payment verification—not an automatic GCash payment gateway or QRPh integration.

The one-page public interface uses navigation tabs to switch sections without scrolling the whole page; long content can scroll within the content area. Both booking forms are collapsed by default. Customers can expand either form and check the calculated price. Open-play submission requires a GCash transaction number; availability is checked again inside a Supabase transaction to prevent overlapping bookings from being accepted concurrently. Re-run `database.sql` in the Supabase SQL Editor to update the booking function to require and save the open-play transaction number.

The public site shows only aggregate court availability. Customer names, contact details, and booking records are not listed publicly; those details remain available only in the admin area and the individual booking response.
