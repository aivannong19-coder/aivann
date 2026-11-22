# Kayasure

A PHP and MySQL website with CTPL information, username/password accounts,
a protected dashboard, and an AI chat integration. The frontend uses plain
HTML, CSS, and JavaScript; no Node.js build or package installation is needed.

You can store this project in a GitHub repository and clone or download it
onto another computer or PHP server. GitHub stores the source code; Apache,
PHP, and MySQL run the application.

## Run a fresh download with XAMPP

1. Clone or extract the project into XAMPP's `htdocs` directory, for example
   `C:\xampp\htdocs\my-website`. Keep the included `.htaccess` files.
2. Start Apache and MySQL in XAMPP. Use PHP 8.1 or later with PDO MySQL,
   mbstring, and cURL enabled.
3. For a new installation, copy `.env.example` to `.env.local`. Preserve an
   existing `.env.local` rather than replacing its credentials or application key.
   The example defaults match local XAMPP. Leave `DB_USER`, `DB_PASSWORD`, and
   `AUTH_APP_KEY` empty so setup can generate them.
4. From the project folder in PowerShell, run:

   ```powershell
   & 'C:\xampp\php\php.exe' 'scripts\setup-auth.php'
   ```

   If PHP is on your PATH, the equivalent command is
   `php scripts/setup-auth.php`. Setup creates the database and missing tables,
   generates restricted local database credentials and an application key,
   and initializes private account folders. It preserves existing records.
   If the local MySQL administrator has a password, supply `DB_SETUP_USER`
   and `DB_SETUP_PASSWORD` through the command's environment as described in
   [DATABASE.md](DATABASE.md).
5. Open <http://localhost/my-website/>. If you chose a different folder name,
   use that folder name in the URL. Serve the site through Apache so PHP runs.

Account registration and login work independently of live AI replies. Chat
also needs the server's API configuration described in [DATABASE.md](DATABASE.md).
See [AUTH.md](AUTH.md) for account behavior and the existing verification commands.

## What belongs in GitHub

Commit the application source, assets, `.htaccess` files, `.env.example`, and
`database/schema.sql`. The `.gitignore` excludes local environment files,
private account folders, logs, and temporary test data.

Use Git or GitHub Desktop to add files so `.gitignore` is respected. If you
upload files manually in GitHub's web interface, leave out `.env.local`, all
other real environment files, `storage/accounts/`, logs, database backups,
and `tests/runtime-*/`. `.gitignore` does not filter manual uploads or remove
files that were already committed.

Accounts and saved conversations live in MySQL, outside the repository.
Cloning the code creates a separate installation; moving an existing installation
also requires a private database backup, its original application key, and
its account storage. Keep those backups outside the repository.

## Use another PHP host

Deploy the same source to a host with PHP and MySQL/MariaDB. Set that host's
database settings in its private environment or `.env.local`. Local XAMPP's
automatic database-user creation assumes local administrator access; a managed
host may require its control panel to create the database and user instead.
On those hosts, import `database/schema.sql`, configure the supplied database
credentials, and set a new random `AUTH_APP_KEY` of at least 32 characters
privately. Account folders are created during registration. See
[DATABASE.md](DATABASE.md) for schema import and [AUTH.md](AUTH.md) for accounts.
Keep `AUTH_ACCOUNTS_PATH` empty to use this checkout's default storage path.

Use HTTPS in production. Apache must allow the project's `.htaccess` rules;
other web servers need equivalent rules denying access to environment files,
Git metadata, and the `includes`, `database`, `scripts`, `tests`, and `storage`
directories. PHP must be able to write its configured account storage.

GitHub Pages serves static files and cannot run this PHP/MySQL application.
Keeping the source in a GitHub repository works with the PHP hosting above.
