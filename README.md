# Telegram Mass Broadcast + User Collector Bot

A single-file, production-ready **Telegram mass broadcast + user collector bot** written in PHP.

This bot safely collects users from groups and channels where it is an admin and lets one or more owners send **high-volume broadcast campaigns** (text, media, albums, polls, buttons) with flood control, queueing, retries, and basic analytics — all backed by a local SQLite database.

Everything lives in a single file: `telegram-send-message-bot.php`.

---

## Features

### User collection

- Automatically **collects users** from:
  - Groups and supergroups where the bot is a member/admin.
  - Channels where the bot is admin.
- Stores per-user metadata in SQLite:
  - Telegram `user_id`, username, first/last name.
  - Source chat ID and type (`group`, `supergroup`, `channel`).
  - Timestamps for `collected_at` and `last_seen`.
  - Flags for `is_bot` and whether the user has **started the bot** in private.
- Tracks all **sources** (groups/channels) in a separate table so you can see:
  - Which chats are feeding new users.
  - Whether a source is still active.
  - Approximate member counts.

### Broadcast engine

- Supports **broadcast campaigns** with:
  - Text messages.
  - Media (photos, videos, documents).
  - Albums.
  - Polls.
  - Inline buttons (deep links, URLs, callback data).
- Uses a **smart queue + retry** system:
  - Each campaign is stored in a `broadcasts` table.
  - Each target user gets a queue item in the `queue` table.
  - Messages are processed in batches with:
    - Per-minute speed cap.
    - Random jitter to avoid bursty patterns.
    - Flood-wait detection and automatic pause/resume.
    - Retry logic with exponential backoff.
- Real-time statistics:
  - Total users targeted.
  - Sent/failed counts updated as the queue is processed.
  - Progress-based ETA calculation.

### Targeting & segmentation

When creating a broadcast, you can choose the **target audience**:

- **All collected users**.
- **Only “starters”** – users who have started the bot in private at least once.
- **Users from a specific source** (group/channel).

Internally, the bot uses a JSON `target_filter` stored with the broadcast and a helper that counts / loads users according to that filter.

### Owner dashboard (bot control panel)

Owners interact with the bot in **private chat**.

- `/start` for owners shows a **dashboard** with:

  - Total unique users.
  - New users collected today.
  - Active sources.
  - Active broadcasts (running/scheduled/paused).

- Main owner menu (inline keyboard):
  - `📤 New Broadcast` – create a new broadcast from any message you send.
  - `📊 Statistics` – global stats and per-source breakdown.
  - `📋 Sources` – list groups/channels, active flags, member counts.
  - `📜 Broadcasts History` – recent broadcasts with sent/failed metrics.
  - `💾 Export Users` – export collected user data as CSV/JSON.
  - `⚙️ Settings` – adjust rate limits, safety options, etc.

Non-owner users who send `/start` simply see a short info message: they are **recipients**, not controllers.

### Safe high-volume sending

Configurable broadcast safety constants:

```php
define('MESSAGES_PER_MINUTE', 35);       // Safe default speed
define('DELAY_JITTER_PERCENT', 15);      // ±15% random jitter between sends
define('FLOOD_WAIT_MULTIPLIER', 1.2);    // Add 20% to Telegram's flood wait
define('MAX_RETRIES', 3);                // Max retries per message
define('RETRY_BACKOFF_BASE', 5);         // Base seconds for exponential backoff
define('PROGRESS_SAVE_INTERVAL', 50);    // Save progress every N messages
define('BATCH_SIZE', 100);               // Queue batch size
```

Flood-wait handling:

- Detects `429` flood errors from Telegram.
- Pauses the broadcast, sleeps for the recommended wait time × `FLOOD_WAIT_MULTIPLIER`.
- Resumes automatically and continues the queue.

### Button click tracking & analytics

- Whenever a user clicks an inline button created by a broadcast, the bot can store a record in the `clicks` table:
  - `broadcast_id`
  - `user_id`
  - `button_data`
  - `clicked_at`
- The **Statistics** and **History** sections show:
  - Broadcast counts (total, sent, failed).
  - Per-source user counts.
  - High-level performance of past campaigns.

### Blacklist & unsubscribe

- `blacklist` table stores user IDs that should **never** receive further broadcasts:
  - Users who blocked the bot.
  - Users who explicitly unsubscribed (if you add such logic).
- All broadcast target queries **exclude** blacklisted users.
- Prevents further retries to known bad targets.

### Robust storage

The bot uses a local SQLite database with these core tables:

- `users` – collected users (one row per Telegram `user_id`).
- `sources` – groups/channels that feed users.
- `broadcasts` – broadcast campaigns and summary stats.
- `queue` – per-user messages to send for each broadcast.
- `failed` – messages that failed and are scheduled for retry.
- `blacklist` – users who should no longer be contacted.
- `clicks` – button click logs.
- `settings` – key/value configuration overrides.
- `user_states` – owner conversation state for multi-step flows.

SQLite is tuned with WAL mode and reasonable performance pragmas for high write throughput.

---

## Requirements

- **PHP** 8.0 or higher (strict types are enabled).
- PHP extensions:
  - `sqlite3`
  - `curl`
  - `json`
- A publicly reachable **HTTPS** endpoint for Telegram webhooks.
- A Telegram bot token from [@BotFather](https://t.me/BotFather).
- Cron access on your server for background processing.

---

## Configuration

At the top of `telegram-send-message-bot.php`:

```php
define('BOT_TOKEN', 'YOUR_BOT_TOKEN_HERE'); // Get from @BotFather
define('OWNER_IDS', [123456789, 987654321]); // Array of owner Telegram IDs

define('DB_FILE', __DIR__ . '/database.sqlite');
define('TIMEZONE', 'UTC');          // Your timezone
define('LOG_FILE', __DIR__ . '/bot.log');

// Broadcast safety settings
define('MESSAGES_PER_MINUTE', 35);
define('DELAY_JITTER_PERCENT', 15);
define('FLOOD_WAIT_MULTIPLIER', 1.2);
define('MAX_RETRIES', 3);
define('RETRY_BACKOFF_BASE', 5);
define('PROGRESS_SAVE_INTERVAL', 50);
define('BATCH_SIZE', 100);

// Collector settings
define('COLLECT_EXISTING_MEMBERS', true); // Try to collect existing members via pagination
define('MAX_MEMBERS_PER_CHAT', 10000);    // Safety limit per chat
```

### 1. Bot token

- Create a bot with **@BotFather**.
- Copy the token and set `BOT_TOKEN`.

### 2. Owners

- Fill `OWNER_IDS` with the Telegram user IDs who should control the bot.
- Only these users will see the full dashboard and control menus.
- Everyone else is treated only as a **subscriber**.

You can find your Telegram ID using bots such as `@userinfobot`.

### 3. Database & log files

- `DB_FILE` (default: `database.sqlite`) is created automatically.
- `LOG_FILE` records operational logs and errors.

Ensure the directory is **writable** by the web server:

```bash
chown www-data:www-data /var/www/html
chmod 750 /var/www/html
```

(adjust user, group and path for your environment).

### 4. Rate & safety tuning

Adjust the broadcast safety constants according to your risk tolerance and bot scale. The defaults are conservative and tested for large lists.

---

## Installation & Setup

1. **Upload the file**

   Copy `telegram-send-message-bot.php` to your web server, for example:

   ```text
   /var/www/html/telegram-send-message-bot.php
   ```

2. **Edit configuration**

   Open the file and update the configuration section:

   - `BOT_TOKEN`
   - `OWNER_IDS`
   - `DB_FILE`, `TIMEZONE`, `LOG_FILE` (optional)
   - Broadcast / collector settings as needed

3. **Set file permissions**

   Ensure PHP can create and write the SQLite and log files:

   ```bash
   chown www-data:www-data /var/www/html/telegram-send-message-bot.php
   chown www-data:www-data /var/www/html
   chmod 750 /var/www/html
   ```

4. **Run setup endpoint**

   Visit the setup URL in your browser:

   ```text
   https://yourdomain.com/telegram-send-message-bot.php?setup=1
   ```

   If successful, you will see:

   - Webhook URL.
   - Database path.
   - A suggested cron line, for example:

   ```text
   * * * * * curl -s "https://yourdomain.com/telegram-send-message-bot.php?cron=1" > /dev/null
   ```

   The setup endpoint also calls `setWebhook()` with the current script URL, registering the webhook with Telegram.

5. **Configure cron**

   Add the suggested cron job on your server (as `root` or the appropriate user):

   ```bash
   * * * * * curl -s "https://yourdomain.com/telegram-send-message-bot.php?cron=1" > /dev/null 2>&1
   ```

   Cron does the heavy lifting:

   - `processQueue()` – process pending queue items for active broadcasts.
   - `retryFailed()` – retry failed messages according to their schedule.

---

## HTTP Endpoints

The script exposes a few simple endpoints:

- `?setup=1`  
  Initialize the database, register webhook, and show cron instructions.

- `?cron=1`  
  Process the broadcast queue and retries; used by server cron.

- `?health=1` or `?health`  
  Health check; responds with `OK`.

- `?stats=1` or `?stats`  
  Returns a small JSON payload with aggregate stats:

  ```json
  {
    "total_users": 12345,
    "active_sources": 12,
    "total_broadcasts": 34,
    "active_broadcasts": 2
  }
  ```

Webhook calls from Telegram (no query parameters) are also handled by this file.

---

## Usage

### For owners (controllers)

1. **Open the bot and send `/start`**

   If your Telegram ID is in `OWNER_IDS`, you will see:

   - A **dashboard** with live counts.
   - An inline keyboard with actions.

2. **Create a new broadcast**

   - Tap `📤 New Broadcast`.
   - Send the message you want to broadcast:
     - Plain text.
     - Photo/video/document with caption.
     - Poll.
     - Message with inline buttons.
   - The bot extracts the message data and then shows **target selection**:

     - `📢 All Users (X)` – broadcast to everyone (except blacklisted).
     - `👥 Only Bot Starters` – broadcast only to users who started the bot.
     - `📋 Select Source` – choose a specific group/channel as the segment.

3. **Confirm and start**

   - The bot shows:
     - Number of target users.
     - Estimated time based on `MESSAGES_PER_MINUTE`.
     - A randomly generated confirmation code.
   - Type the confirmation code in chat to start broadcasting.
   - The broadcast is inserted into the queue and processed by cron.

4. **Monitor progress**

   - From the menu:
     - Use `📜 Broadcasts History` to see all campaigns and their status.
     - Use `📊 Statistics` to see global and per-source stats.
   - Log file (`bot.log`) contains detailed information about flood waits, retries, and any errors.

5. **Export users**

   - Tap `💾 Export Users`.
   - Depending on implementation, the bot can send:
     - CSV file.
     - JSON file.
   - Useful for external analytics or backup.

6. **Manage settings**

   - Tap `⚙️ Settings` to:
     - Adjust performance parameters (if exposed).
     - Change default behavior like collection options.

### For regular users (recipients)

- Users in groups/channels where the bot is admin are **collected** and become potential broadcast targets.
- If they start the bot in private, they may be included in “starters only” segments.
- They do **not** see the dashboard or controls unless explicitly added to `OWNER_IDS`.

---

## Security & Best Practices

- Always serve the bot via **HTTPS**.
- Keep `BOT_TOKEN` private:
  - Never commit it to public repositories.
  - Use environment variables or private include files if you refactor.
- Protect your SQLite and log files:
  - Place them in directories not directly exposed through the web server, when possible.
  - Use restrictive file permissions.
- Limit `OWNER_IDS` to trusted accounts:
  - Owners have full power to send messages to all collected users.
- Monitor logs for:
  - Flood-wait events.
  - High failure rates.
  - Suspicious usage patterns.

---

## Extending the Bot

The code is intentionally split into clear sections:

- Configuration.
- Logging, helpers and utilities.
- Database initialization and connection.
- Telegram API wrappers.
- User collection logic.
- Broadcast creation, queueing, and retry logic.
- Owner menu and callback handling.
- Webhook & endpoints.

You can safely extend it by:

- Adding more targeting filters (e.g. by date range, username patterns).
- Adding more analytics (per-broadcast click-through tracking, etc.).
- Implementing a simple admin panel behind `OWNER_IDS` for configuration overrides stored in `settings`.
- Integrating with external monitoring (e.g. sending alerts on high failure rates).

Because the bot is single-file and uses SQLite, deployment and upgrades remain simple while still supporting very large user sets.

---

## License

If the header in `telegram-send-message-bot.php` does not specify another license, you may treat this bot as MIT-style licensed for your own internal projects.  
Always add or adjust a `LICENSE` file according to your chosen licensing model when redistributing.
