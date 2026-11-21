# Deploy to Railway (PHP + MySQL)

1. Put this folder on GitHub: the repo root must contain `Dockerfile`, `index.php`, `lib.php`, ...
2. Railway > **New Project** > **Deploy from GitHub repo** > pick the repo. Railway builds the `Dockerfile`.
3. In the same project: **+ New** > **Database** > **Add MySQL**.
4. Open your web service > **Variables** > add (use *Add Reference* so they track the database):
   MYSQLHOST = ${{MySQL.MYSQLHOST}}   MYSQLPORT = ${{MySQL.MYSQLPORT}}
   MYSQLUSER = ${{MySQL.MYSQLUSER}}   MYSQLPASSWORD = ${{MySQL.MYSQLPASSWORD}}
   MYSQLDATABASE = ${{MySQL.MYSQLDATABASE}}
   (If your MySQL service is not named "MySQL", use its name.)
5. Web service > **Settings** > **Networking** > **Generate Domain**.
6. Open `https://YOUR-DOMAIN/setup.php`, click the button, then **delete `setup.php`** from the repo and push.
7. Log in at `https://YOUR-DOMAIN/` and change the starter passwords.
