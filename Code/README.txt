LPG DELIVERY PHP CONVERSION
===========================

Files:
- index.php              Login
- register.php           Signup
- forgot_password.php    Forgot password + verification code
- customer_panel.php     Customer dashboard
- admin_panel.php        Admin dashboard
- rider_panel.php        Rider dashboard

Required support files:
- config.php
- database.sql
- styles.css
- place_order.php
- claim_order.php

SETUP WITH XAMPP
1. Copy this folder to:
   C:\xampp\htdocs\lpg_delivery

2. Start Apache and MySQL.

3. Open phpMyAdmin and import database.sql.

4. Open config.php and verify:
   database = lpg_delivery
   username = root
   password = your MySQL password

5. Create an admin account. Generate a password hash with:
   php -r "echo password_hash('YourPasswordHere', PASSWORD_DEFAULT);"

   Then insert the hash into the users table.

6. Open:
   http://localhost/lpg_delivery/index.php

IMPORTANT
- The forgot-password page stores the generated code as a secure hash in the session and writes the plaintext code to PHP's error log for local development.
- For production, connect it to an SMTP email service instead of relying on error_log().
- The original styles.css and script.js from the HTML were not included in the supplied source, so styles.css here is a basic compatible replacement.
- Leaflet was present in the original HTML but no map functionality was included in the supplied markup. Add your Leaflet map code to the relevant panel when you have the original script.js/map logic.
- Admin and rider actions should be protected with CSRF tokens before production deployment.
