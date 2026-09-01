<?php
// ============================================================
// Voyage Ledger — Configuration
// Keep this file OUT of git / public zips. Add it to .gitignore.
// ============================================================

// ---- Database ----
$host   = "localhost";
$user   = "root";
$pass   = "";
$dbname = "voyage_ledger_db";

// ---- Razorpay ----
// Get these from https://dashboard.razorpay.com/app/keys
// Start with the TEST keys (they start with rzp_test_...) until you're
// ready to go live with rzp_live_... keys.
define('RAZORPAY_KEY_ID', 'rzp_test_XXXXXXXXXXXXXX');
define('RAZORPAY_KEY_SECRET', 'YOUR_TEST_KEY_SECRET');

// ---- Authorized administrators ----
// Emails (or plain usernames, for the demo logins) allowed to log in as admin.
$allowedAdmins = ['shyam@gmail.com', 'jeet@gmail.com', 'shyam', 'jeet'];