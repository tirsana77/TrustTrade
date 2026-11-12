# TrustTrade

TrustTrade is a student marketplace for buying and selling second hand items safely. It was built for my BSc final year project at De Montfort University (student P2863787, Tirsana Darlami Thapa). It uses PHP, MySQL, HTML, CSS and JavaScript.

Main features: guest browsing, student ID verification, listings with photos, an AI recommender, a price hint with category suggestion, a risk score for listings, purchase requests, escrow payment (Stripe test mode), disputes, reports, notifications, automatic rules and an administrator area.

## What you need

- XAMPP (Apache, MySQL and PHP 7.4 or newer), or any similar PHP and MySQL setup
- A web browser
- The PHP cURL extension turned on (it is on by default in XAMPP)

## How to install and run it (XAMPP)

1. Open XAMPP and start **Apache** and **MySQL**.
2. Put the project folder in the XAMPP `htdocs` folder and name it `TrustTrade`. On a Mac this is `/Applications/XAMPP/xamppfiles/htdocs/TrustTrade`. On Windows it is `C:\xampp\htdocs\TrustTrade`.
3. Open phpMyAdmin at http://localhost/phpmyadmin.
4. Click **New** and create an empty database called `trusttrade` (collation `utf8mb4_general_ci`).
5. Click the `trusttrade` database, choose **Import**, select `database/trusttrade.sql` and click **Go**. This creates the 10 tables and adds the demo data.
6. Check the database settings in `config/database.php`. The defaults are host `localhost`, database `trusttrade`, user `root` and an empty password, which match a normal XAMPP install.
7. Open http://localhost/TrustTrade/ in the browser. You will be sent to the dashboard.

## Logging in

The demo data already has these accounts. You can also create a new account from the register page. A new account is an unverified student until an administrator approves the uploaded student ID photo.

| Role | Email | Password |
|---|---|---|
| Administrator | john@example.com | mypassword123 |
| Verified student | test@example.com | password123 |
| Verified student | anna.demo@example.com | Anna2026Demo |
| Verified student | ben.demo@example.com | Ben2026Demo |

## Stripe payments (optional)

The escrow payment uses Stripe in test mode only. No real money is used.

1. Copy `config/stripe.example.php` to `config/stripe.php`.
2. Replace `sk_test_PUT_YOUR_KEY_HERE` with your own Stripe test secret key (it starts with `sk_test_`).

The real `config/stripe.php` is not included in the repository or the ZIP because it holds a secret key. Without it the rest of the application still works.

## Running the automated tests

Log in as the administrator in the browser, then open these pages:

- http://localhost/TrustTrade/run_all_tests.php (runs every test and shows one result table)
- http://localhost/TrustTrade/test.php
- http://localhost/TrustTrade/test_ai.php
- http://localhost/TrustTrade/test_payment.php
- http://localhost/TrustTrade/test_automation.php

The tests use temporary accounts and remove them at the end.

## Folder guide

- `controllers/` the pages and actions
- `models/` database code and the AI recommender
- `includes/` shared code, including the Stripe client
- `config/` database and Stripe settings
- `public/` CSS, JavaScript, images and uploaded listing photos
- `storage/` student ID photos and dispute evidence (kept empty in the repository)
- `database/trusttrade.sql` the database script with the demo data
- `seed_demo.php`, `recommender_report.php`, `recommender_simulation.php` administrator tools for demo data and the AI recommender

## Notes

- All data in the database is fake demo data. No real personal data is stored.
- The `php-docker-starter/` folder is a separate starter template and is not needed to run TrustTrade.