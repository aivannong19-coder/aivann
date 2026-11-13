# Kayasure accounts

Create an account with a username and password, then use those same details to
log in later. Usernames contain 3–32 letters, numbers, dots, underscores, or
hyphens, starting with a letter or number. Username comparisons ignore case.
Passwords contain at least 12 characters and no more than 72 UTF-8 bytes.
Password confirmation is required when creating an account.

Accounts are saved in MySQL. Closing the account dialog or reloading the page
does not remove a registered account. The active login uses a server-side PHP
session; there is no permanent remember-login cookie. If the session ends, log
in again with the saved username and password. The optional remember-username
choice stores only the username in this browser, never a password.

Login and account creation open the protected `dashboard.php` page directly.
The dashboard shows the current account and links to CTPL information. Guests,
expired sessions, and logged-out browsers return to the login form. Both the
dashboard and account API use the same 12-hour session limit and disable caching.
Login and create-account dialogs stay open on outside clicks; the close button
and Escape key remain available.

## Local setup

1. Start Apache and MySQL in XAMPP. Enable PHP mbstring and PDO MySQL.
2. Preserve the existing `.env.local` values. `.env.example` documents the
   required database and application-key settings without credentials.
   Environment variables take precedence over the local file.
3. Run the repeatable account setup:

   ```powershell
   & 'C:\xampp\php\php.exe' 'scripts\setup-auth.php'
   ```

   Setup creates missing account tables, adds missing username/storage columns
   and unique indexes to existing databases, saves a random `AUTH_APP_KEY`
   privately in `.env.local` if needed, and initializes protected account storage.
   It preserves existing database records and working credentials. Keep the
   application key stable; it protects security identifiers and rate limits.
4. Visit <http://localhost/my-website/> and choose **Create account**.

## Account storage

Each new account receives a private folder at:

```text
storage/accounts/<32-character-random-hex-key>/
```

The folder key is randomly generated and stored in `users.storage_key`. A
username is never used as a filesystem path. The `users` table stores the
username and a one-way PHP password hash; passwords are never saved in account
folders or browser storage. The folder provides a private location for future
account files; it does not automatically copy conversation history into files.

Apache denies HTTP access to `storage/` through its `.htaccess` protection.
Keep that protection in place. If deploying with another web server, explicitly
deny public access to this directory. Back up the MySQL database, application
key, and account storage together.

Existing databases keep their older identity columns and verification tables
to preserve records during migration. The current account flow uses usernames
and passwords.

## Sessions and deployment

Serve the site over HTTPS in production. Keep `.env.local` private and outside
published source archives. Apache's project rules block direct access to local
environment files and server directories. PHP's built-in server is for local
development only.

Account login, registration, and logout rotate the PHP session. Existing chat
history remains linked to its original browser chat session; creating an account
does not make history available across devices. Account recovery, username or
password changes, and account deletion are separate features.

## Automated verification

With MySQL running, execute:

```powershell
& 'C:\xampp\php\php.exe' 'tests\auth-test.php'
& 'C:\xampp\php\php.exe' 'tests\http-auth-test.php'
```

The checks use disposable accounts and isolated sessions and remove their test
fixtures. They validate registration, password login, session behavior, and
private account storage without sending messages to external services.
