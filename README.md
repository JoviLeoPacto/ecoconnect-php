# EcoConnect – PHP + MySQL edition


Starter accounts: `official` / `Official123!` (Barangay Official, Tiniguiban) and `resident` / `Resident123!`.
Database login is in `config.php` (XAMPP default: root, no password).

## Files
| File | What it does |
|---|---|
| `database/ecoconnect.sql` | Full MySQL schema + barangay and category seed data |
| `config.php`, `lib.php` | DB connection (PDO), sessions, CSRF, points/titles, shared layout |
| `index.php`, `register.php`, `logout.php` | Login, resident sign-up, logout |
| `dashboard.php` | Residents: join/leave events, ticket codes. Officials: create/complete/cancel/delete events |
| `event.php` | Officials: check in by ticket code or manually, awards the event points |
| `rewards.php` | Residents reserve rewards; officials manage catalog and release/reject claims |
| `residents.php` | Officials verify residents, award points, decide transfer requests |
| `profile.php` | Profile, transfer request (points reset to 0), change password |
| `leaderboard.php` | Public top 20 by points |

Not included: SMS/OTP codes, QR scanning, AI assistant and reports (the `otp_codes` table exists but is unused).

## Hosting online
See `HOSTING.md` (InfinityFree). Netlify cannot run PHP or MySQL.
