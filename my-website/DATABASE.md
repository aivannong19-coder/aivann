# Saved Kayasure conversations

The local MySQL/MariaDB database is `kayasure_chat`. In phpMyAdmin, open
<http://localhost/phpmyadmin/> and select this database:

- `chat_conversations` contains each conversation, a hash of its owning PHP session,
  and creation/update timestamps.
- `chat_messages` contains message text, the `user` or `assistant` role, request
  status (`pending`, `completed`, or `failed`), a safe error when a request fails,
  and a creation timestamp.

All timestamps written by the application use UTC. Text uses `utf8mb4`, including
emoji. The runtime account has only `SELECT`, `INSERT`, and `UPDATE` permissions
for this database. Its credentials are in the ignored, HTTP-protected `.env.local`
file as `DB_HOST`, `DB_PORT`, `DB_NAME`, `DB_USER`, and `DB_PASSWORD`. Keep that file
private; never put these values in JavaScript.

## Behavior

`POST chat.php` saves a valid user message before checking or calling OpenAI. On
success, the assistant reply and question completion are saved in one transaction.
On API failure, the question stays saved with a failed status and a safe error.
Interrupted requests may remain pending. SQL transactions are not held open during
the external API request.

`GET chat.php` restores up to the latest 100 saved messages belonging to the current
PHP session. Every message remains in MySQL; the display limit does not delete data.
Only the last 12 completed messages are sent as model context. Opening the website
does not call OpenAI. It only loads saved history.

History is tied to the current PHP session. Login, registration, and logout rotate
the session and start a fresh conversation; history is not shared across devices.
Another browser/session starts its own conversation. Clearing cookies or losing
the session prevents that browser from restoring its old history; records remain
in the database. There is no public endpoint to list everybody's conversations.
There is no automatic retention/deletion policy in this version.

For username/password accounts, private account folders, and setup, see [AUTH.md](AUTH.md).

## Setup and verification

MySQL must be running in XAMPP. From this project folder:

```powershell
& 'C:\xampp\php\php.exe' 'scripts\setup-database.php'
& 'C:\xampp\php\php.exe' 'tests\storage-test.php'
& 'C:\xampp\php\php.exe' 'tests\http-storage-test.php'
```

The setup command is repeatable. It creates missing tables and reuses configured
working runtime credentials. Initial setup defaults to local XAMPP administrative
access (`root` with an empty password). If your MySQL administration uses different
credentials, set `DB_SETUP_USER` and `DB_SETUP_PASSWORD` securely in the command's
environment; these administrative credentials are never written to `.env.local`.
The tests remove only their own randomly generated test conversations. The HTTP
test starts an isolated local PHP server with a synthetic invalid API key, checks
failure recording and history restoration, and stops it afterward. It never calls
the live OpenAI service or changes your configured API key.

The SQL schema is also available in `database/schema.sql` for importing after
selecting the target database. The `includes`, `database`, `scripts`, and `tests`
directories are blocked from direct HTTP access by Apache.

Successful live assistant replies still require a valid `OPENAI_API_KEY`. Database
history and failure recording work independently of OpenAI access.
