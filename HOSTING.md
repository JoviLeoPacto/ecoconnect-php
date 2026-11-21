# Deploy to InfinityFree (free PHP + MySQL hosting)

Netlify cannot run PHP/MySQL, so use a PHP host such as InfinityFree.

1. Sign up at https://www.infinityfree.com and create a hosting account (pick a free subdomain).
2. Client Area > your account > **Control Panel (vPanel)** > **MySQL Databases** > create a database (e.g. `ecoconnect`).
   Write down: **MySQL host name**, **database name**, **user name**, and your vPanel password.
3. Control Panel > **Select PHP Version** > choose **8.1 or newer**.
4. Open `config.php` on your computer and fill in DB_HOST, DB_NAME, DB_USER, DB_PASS with the values from step 2.
5. Upload everything inside `ecoconnect-php` into the **htdocs** folder of your site (Online File Manager, or FTP with FileZilla).
   Upload the *contents* so that `index.php` sits directly in `htdocs`. Don't upload the zip itself.
6. Create the tables - pick ONE:
   - Open `https://YOUR-SITE/setup.php` and click the button, or
   - phpMyAdmin > select your database > **Import** > `database/ecoconnect_hosting.sql`
     (then create your own official account, so the setup page is easier).
7. **Delete `setup.php`** from htdocs (File Manager). Then log in at `https://YOUR-SITE/`.
8. Change the starter passwords (Profile > Change password) before sharing the link.

Notes: free hosting is slow at times and may show a short "browser check" page on the first visit.
