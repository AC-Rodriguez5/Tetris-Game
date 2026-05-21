# How to Run Cosmic Tetris

Use this guide after pulling the repo on a new machine.

## Requirements

- XAMPP with Apache and PHP installed
- A browser
- Supabase PostgreSQL database access
- Internet connection for CDN assets such as Bootstrap and Google Fonts

This app is a plain PHP app. You do not need `npm install` or `composer install`.

## 1. Put the Project in XAMPP

Recommended folder:

```text
C:\xampp\htdocs\Tetris
```

If you put the repo in a different folder name under `htdocs`, replace `Tetris` in the URLs below with your folder name.

## 2. Enable PostgreSQL Support in PHP

The app connects to Supabase PostgreSQL through PDO, so PHP must have PostgreSQL extensions enabled.

1. Open:

```text
C:\xampp\php\php.ini
```

2. Find these lines and make sure they are not commented with `;`:

```ini
extension=pdo_pgsql
extension=pgsql
```

3. Save the file.
4. Restart Apache from the XAMPP Control Panel.

## 3. Configure the Database Connection

Database settings are in:

```text
dbConnect\dbconnect.php
```

If your group is using the same shared Supabase database, leave the existing settings as-is.

If you are using your own Supabase project, update these values in `dbConnect\dbconnect.php`:

```php
private $host = "...";
private $port = "6543";
private $dbname = "postgres";
private $user = "...";
private $password = "...";
```

For Supabase, the transaction pooler usually uses port `6543`.

## 4. Create the Database Tables

Open your Supabase project, then go to:

```text
SQL Editor -> New query
```

Run the Blitz multiplayer tables from:

```text
blitz_tables.sql
```

Also make sure the main user/high-score table exists. Run this once if the table is missing:

```sql
CREATE TABLE IF NOT EXISTS "TetrisGame" (
    id BIGSERIAL PRIMARY KEY,
    email VARCHAR(255) UNIQUE NOT NULL,
    username VARCHAR(100) UNIQUE NOT NULL,
    password TEXT NOT NULL,
    score INTEGER DEFAULT 0
);
```

## 5. Start the App

1. Open XAMPP Control Panel.
2. Start Apache.
3. Open this URL:

```text
http://localhost/Tetris/dashboard/register.php
```

Create an account, then log in.

Main pages:

```text
Register:        http://localhost/Tetris/dashboard/register.php
Login:           http://localhost/Tetris/dashboard/login.php
Dashboard:       http://localhost/Tetris/dashboard/dashboard.php
Single Player:   http://localhost/Tetris/dashboard/game.php
Leaderboard:     http://localhost/Tetris/dashboard/leaderboard.php
Blitz Mode:      http://localhost/Tetris/dashboard/multiplayer.php
Blitz Rankings:  http://localhost/Tetris/dashboard/blitz_leaderboard.php
```

## 6. Test Blitz Multiplayer

Blitz needs two logged-in players.

Use one of these options:

- Two different browsers, such as Chrome and Edge
- One normal browser window and one incognito/private window
- Two different computers connected to the same deployed app/database

Test create/join:

1. Player 1 logs in.
2. Player 1 opens Blitz Mode and clicks Create Room.
3. Copy the room code.
4. Player 2 logs in from a different browser/session.
5. Player 2 opens Blitz Mode, clicks Join Room, and enters the room code.
6. Both players click Ready.

Test quick match:

1. Player 1 clicks Quick Match and waits.
2. Player 2 logs in from another browser/session.
3. Player 2 clicks Quick Match.

Do not test both players in the same browser tab/session. The app uses PHP sessions, so one browser session represents one logged-in user.

## Troubleshooting

### Page says `Database connection failed`

Check:

- Apache was restarted after editing `php.ini`
- `pdo_pgsql` and `pgsql` are enabled
- Supabase credentials in `dbConnect\dbconnect.php` are correct
- Your internet connection is working

### Error says `could not find driver`

PHP does not have the PostgreSQL PDO driver enabled. Recheck `extension=pdo_pgsql` in:

```text
C:\xampp\php\php.ini
```

Then restart Apache.

### Blitz says `Room Setup Failed`

Check:

- You are logged in before opening Blitz Mode
- The `blitz_rooms` table exists in Supabase
- The browser is not using an old cached JavaScript file; press `Ctrl + F5`
- DevTools -> Network -> `blitz_api.php` shows a successful JSON response

The browser warning below is usually not the cause of the room setup failure:

```text
Tracking Prevention blocked access to storage for https://cdn.jsdelivr.net/...
```

That warning is from browser privacy protection around CDN assets.

### `relation "blitz_rooms" does not exist`

Run `blitz_tables.sql` in Supabase SQL Editor.

### Register or login fails because `TetrisGame` does not exist

Run the `CREATE TABLE IF NOT EXISTS "TetrisGame"` SQL from step 4.

### CSS or Bootstrap looks broken

The app loads some assets from CDNs. Make sure the computer has internet access, then hard refresh the page with `Ctrl + F5`.

