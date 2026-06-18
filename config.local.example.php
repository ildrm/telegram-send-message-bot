<?php
/**
 * Local configuration — copy to config.local.php and fill in real values.
 * config.local.php is git-ignored and loaded automatically before defaults.
 *
 * Any constant defined here overrides the in-file defaults.
 */

define('BOT_TOKEN', '123456:YOUR_REAL_BOT_TOKEN');      // from @BotFather
define('OWNER_IDS', [123456789]);                        // your Telegram user id(s)

// Strong random secrets — generate with: php -r "echo bin2hex(random_bytes(24));"
define('CRON_SECRET', 'replace-with-long-random-string');
define('WEBHOOK_SECRET', 'replace-with-long-random-string');

// Optional overrides
// define('TIMEZONE', 'Europe/Berlin');
// define('MESSAGES_PER_MINUTE', 30);
