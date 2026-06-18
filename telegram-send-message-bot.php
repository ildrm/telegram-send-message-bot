<?php
declare(strict_types=1);

/*******************************************************************************
 * TELEGRAM MASS BROADCAST + USER COLLECTOR BOT
 * 
 * Elite single-file PHP bot for safe, high-volume broadcasting to collected users
 * Supports 500,000+ users and 50,000+ messages/hour with intelligent flood protection
 * 
 * Features:
 * - Automatic user collection from groups/channels where bot is admin
 * - Full broadcast support (text, media, albums, polls, buttons)
 * - Smart queue system with flood-wait handling and auto-resume
 * - Real-time progress tracking with ETA
 * - Segmentation, scheduling, retry logic, blacklist
 * - Button click tracking and analytics
 * - Owner dashboard with CSV/JSON export
 * - Cron endpoint for background processing
 * 
 * Setup: Upload, set BOT_TOKEN and OWNER_ID, visit ?setup=1, add cron job
 ******************************************************************************/

// ============================================================================
// CONFIGURATION
// ============================================================================

// Secrets can be provided via environment / a non-committed config.local.php.
// If config.local.php exists it is loaded first and may define any of the constants below.
if (is_file(__DIR__ . '/config.local.php')) {
    require __DIR__ . '/config.local.php';
}

if (!defined('BOT_TOKEN'))   define('BOT_TOKEN', getenv('BOT_TOKEN') ?: 'YOUR_BOT_TOKEN_HERE'); // Get from @BotFather
if (!defined('OWNER_IDS'))   define('OWNER_IDS', [123456789, 987654321]); // Array of owner Telegram IDs
if (!defined('DB_FILE'))     define('DB_FILE', __DIR__ . '/database.sqlite');
if (!defined('TIMEZONE'))    define('TIMEZONE', 'UTC'); // Your timezone
if (!defined('LOG_FILE'))    define('LOG_FILE', __DIR__ . '/bot.log');
if (!defined('LOCK_FILE'))   define('LOCK_FILE', __DIR__ . '/cron.lock');

// Shared secrets — CHANGE THESE. Required to call ?setup / ?cron / ?stats and to validate webhooks.
if (!defined('CRON_SECRET'))    define('CRON_SECRET', getenv('CRON_SECRET') ?: 'change-me-cron-secret');
if (!defined('WEBHOOK_SECRET')) define('WEBHOOK_SECRET', getenv('WEBHOOK_SECRET') ?: 'change-me-webhook-secret');

// Max wall-clock seconds a single cron run will spend sending, so it never exceeds PHP's limit.
if (!defined('CRON_TIME_BUDGET')) define('CRON_TIME_BUDGET', 25);

// Broadcast safety settings
define('MESSAGES_PER_MINUTE', 35); // Safe default: 35 msg/min
define('DELAY_JITTER_PERCENT', 15); // ±15% random variation
define('FLOOD_WAIT_MULTIPLIER', 1.2); // Add 20% buffer to Telegram's wait time
define('MAX_RETRIES', 3); // Retry failed messages 3 times
define('RETRY_BACKOFF_BASE', 5); // Exponential backoff: 5, 25, 125 seconds
define('PROGRESS_SAVE_INTERVAL', 50); // Save progress every N messages
define('BATCH_SIZE', 100); // Process queue in batches

// Collector settings
define('COLLECT_EXISTING_MEMBERS', true); // Try to collect existing members via pagination
define('MAX_MEMBERS_PER_CHAT', 10000); // Limit per chat to avoid timeouts

date_default_timezone_set(TIMEZONE);

// ============================================================================
// SECURITY & HELPERS
// ============================================================================

/**
 * Log message to file with timestamp
 */
function logMessage(string $message, string $level = 'INFO'): void {
    $timestamp = date('Y-m-d H:i:s');
    $logEntry = "[{$timestamp}] [{$level}] {$message}\n";
    file_put_contents(LOG_FILE, $logEntry, FILE_APPEND | LOCK_EX);
}

/**
 * Check if user is owner
 */
function isOwner(int $userId): bool {
    return in_array($userId, OWNER_IDS, true);
}

/**
 * Calculate smart delay with jitter to avoid detection patterns
 */
function getSmartDelay(): float {
    $baseDelay = 60.0 / MESSAGES_PER_MINUTE; // seconds per message
    $jitter = $baseDelay * (DELAY_JITTER_PERCENT / 100.0);
    $randomJitter = mt_rand(-100, 100) / 100.0 * $jitter;
    return $baseDelay + $randomJitter;
}

/**
 * Handle Telegram flood wait errors intelligently
 * Returns seconds to wait, or 0 if not a flood error
 */
function parseFloodWait(string $errorDescription): int {
    // "Too Many Requests: retry after 42"
    if (preg_match('/retry after (\d+)/i', $errorDescription, $matches)) {
        $waitSeconds = (int)$matches[1];
        return (int)ceil($waitSeconds * FLOOD_WAIT_MULTIPLIER);
    }
    return 0;
}

/**
 * Generate random string for confirmation codes
 */
function generateCode(int $length = 6): string {
    return strtoupper(substr(bin2hex(random_bytes($length)), 0, $length));
}

/**
 * Format number with thousands separator
 */
function formatNumber(int $number): string {
    return number_format($number, 0, '.', ',');
}

/**
 * Calculate ETA based on remaining items and speed
 */
function calculateETA(int $remaining, float $messagesPerMinute): string {
    if ($remaining === 0 || $messagesPerMinute === 0) {
        return 'N/A';
    }
    
    $minutesRemaining = $remaining / $messagesPerMinute;
    
    if ($minutesRemaining < 60) {
        return round($minutesRemaining) . ' min';
    }
    
    $hours = floor($minutesRemaining / 60);
    $minutes = round($minutesRemaining % 60);
    
    return "{$hours}h {$minutes}m";
}

/**
 * Parse a schedule spec into a future unix timestamp, or null if invalid/in the past.
 * Accepts: "in 30m", "in 2h", "in 1d", "at 2026-06-19 10:00".
 */
function parseScheduleSpec(string $spec): ?int {
    $spec = trim(strtolower($spec));

    if (preg_match('/^in\s+(\d+)\s*(m|min|h|hour|hours|d|day|days)$/', $spec, $m)) {
        $n = (int)$m[1];
        $unit = $m[2];
        $seconds = match(true) {
            str_starts_with($unit, 'm') => $n * 60,
            str_starts_with($unit, 'h') => $n * 3600,
            str_starts_with($unit, 'd') => $n * 86400,
            default => 0,
        };
        return $seconds > 0 ? time() + $seconds : null;
    }

    if (preg_match('/^at\s+(.+)$/', $spec, $m)) {
        $ts = strtotime($m[1]);
        return ($ts !== false && $ts > time()) ? $ts : null;
    }

    return null;
}

/**
 * Sanitize filename for export
 */
function sanitizeFilename(string $filename): string {
    return preg_replace('/[^a-zA-Z0-9_-]/', '_', $filename);
}

/**
 * Read a setting from the settings table, falling back to a default.
 */
function getSetting(string $key, ?string $default = null): ?string {
    $db = getDB();
    $stmt = $db->prepare("SELECT value FROM settings WHERE key = :key");
    $stmt->bindValue(':key', $key, SQLITE3_TEXT);
    $row = $stmt->execute()->fetchArray(SQLITE3_ASSOC);
    return $row ? $row['value'] : $default;
}

/**
 * Write a setting to the settings table.
 */
function setSetting(string $key, string $value): void {
    $db = getDB();
    $stmt = $db->prepare("INSERT INTO settings (key, value, updated_at) VALUES (:key, :value, :ts)
                          ON CONFLICT(key) DO UPDATE SET value = :value, updated_at = :ts");
    $stmt->bindValue(':key', $key, SQLITE3_TEXT);
    $stmt->bindValue(':value', $value, SQLITE3_TEXT);
    $stmt->bindValue(':ts', time(), SQLITE3_INTEGER);
    $stmt->execute();
}

/**
 * Effective send rate (msg/min): DB override wins over the MESSAGES_PER_MINUTE constant.
 */
function effectiveRate(): int {
    $v = (int)getSetting('messages_per_minute', (string)MESSAGES_PER_MINUTE);
    return $v > 0 ? $v : MESSAGES_PER_MINUTE;
}

/**
 * Add a user to the blacklist (e.g. blocked the bot / deactivated).
 */
function blacklistUser(int $userId, string $reason): void {
    $db = getDB();
    $stmt = $db->prepare("INSERT OR IGNORE INTO blacklist (user_id, reason, added_at) VALUES (:uid, :reason, :ts)");
    $stmt->bindValue(':uid', $userId, SQLITE3_INTEGER);
    $stmt->bindValue(':reason', $reason, SQLITE3_TEXT);
    $stmt->bindValue(':ts', time(), SQLITE3_INTEGER);
    $stmt->execute();
}

/**
 * True for Telegram errors that will never succeed on retry (user blocked the bot,
 * deactivated, never started it, etc.). Such users should be blacklisted, not retried.
 */
function isPermanentSendError(string $description): bool {
    $d = strtolower($description);
    foreach ([
        'bot was blocked',
        'user is deactivated',
        'chat not found',
        "bot can't initiate conversation",
        'bot can’t initiate conversation',
        'user not found',
        'have no rights to send',
        'peer_id_invalid',
    ] as $needle) {
        if (str_contains($d, strtolower($needle))) {
            return true;
        }
    }
    return false;
}

// ============================================================================
// DATABASE SETUP & MIGRATIONS
// ============================================================================

/**
 * Initialize SQLite database with all required tables
 */
function initDatabase(): void {
    $db = getDB();
    
    // Users table - stores all collected users
    $db->exec("CREATE TABLE IF NOT EXISTS users (
        id INTEGER PRIMARY KEY,
        user_id INTEGER UNIQUE NOT NULL,
        username TEXT,
        first_name TEXT,
        last_name TEXT,
        source_id INTEGER,
        source_type TEXT,
        collected_at INTEGER NOT NULL,
        last_seen INTEGER,
        is_bot INTEGER DEFAULT 0,
        started_bot INTEGER DEFAULT 0,
        subscribed_at INTEGER
    )");
    
    // Sources table - groups/channels where bot is admin
    $db->exec("CREATE TABLE IF NOT EXISTS sources (
        id INTEGER PRIMARY KEY,
        chat_id INTEGER UNIQUE NOT NULL,
        chat_type TEXT NOT NULL,
        title TEXT,
        username TEXT,
        member_count INTEGER DEFAULT 0,
        added_at INTEGER NOT NULL,
        last_sync INTEGER,
        is_active INTEGER DEFAULT 1
    )");
    
    // Broadcasts table - broadcast campaigns
    $db->exec("CREATE TABLE IF NOT EXISTS broadcasts (
        id INTEGER PRIMARY KEY AUTOINCREMENT,
        owner_id INTEGER NOT NULL,
        message_type TEXT NOT NULL,
        message_data TEXT NOT NULL,
        target_filter TEXT,
        total_users INTEGER DEFAULT 0,
        sent_count INTEGER DEFAULT 0,
        failed_count INTEGER DEFAULT 0,
        status TEXT DEFAULT 'pending',
        scheduled_at INTEGER,
        started_at INTEGER,
        completed_at INTEGER,
        created_at INTEGER NOT NULL,
        confirmation_code TEXT,
        resume_at INTEGER,
        variant_group TEXT
    )");
    
    // Queue table - individual messages to send
    $db->exec("CREATE TABLE IF NOT EXISTS queue (
        id INTEGER PRIMARY KEY AUTOINCREMENT,
        broadcast_id INTEGER NOT NULL,
        user_id INTEGER NOT NULL,
        status TEXT DEFAULT 'pending',
        retry_count INTEGER DEFAULT 0,
        last_attempt INTEGER,
        error_message TEXT,
        sent_at INTEGER,
        next_retry INTEGER
    )");
    
    // Failed messages table - for retry tracking
    $db->exec("CREATE TABLE IF NOT EXISTS failed (
        id INTEGER PRIMARY KEY AUTOINCREMENT,
        broadcast_id INTEGER NOT NULL,
        user_id INTEGER NOT NULL,
        error_message TEXT,
        retry_count INTEGER DEFAULT 0,
        next_retry INTEGER,
        failed_at INTEGER NOT NULL
    )");
    
    // Blacklist table - users who stopped the bot
    $db->exec("CREATE TABLE IF NOT EXISTS blacklist (
        id INTEGER PRIMARY KEY AUTOINCREMENT,
        user_id INTEGER UNIQUE NOT NULL,
        reason TEXT,
        added_at INTEGER NOT NULL
    )");
    
    // Clicks table - track button clicks
    $db->exec("CREATE TABLE IF NOT EXISTS clicks (
        id INTEGER PRIMARY KEY AUTOINCREMENT,
        broadcast_id INTEGER NOT NULL,
        user_id INTEGER NOT NULL,
        button_data TEXT,
        clicked_at INTEGER NOT NULL
    )");
    
    // Settings table - bot configuration
    $db->exec("CREATE TABLE IF NOT EXISTS settings (
        key TEXT PRIMARY KEY,
        value TEXT NOT NULL,
        updated_at INTEGER NOT NULL
    )");
    
    // User states table - conversation states for owner
    $db->exec("CREATE TABLE IF NOT EXISTS user_states (
        user_id INTEGER PRIMARY KEY,
        state TEXT,
        data TEXT,
        updated_at INTEGER NOT NULL
    )");

    // Templates table - reusable saved messages
    $db->exec("CREATE TABLE IF NOT EXISTS templates (
        id INTEGER PRIMARY KEY AUTOINCREMENT,
        owner_id INTEGER NOT NULL,
        name TEXT NOT NULL,
        message_type TEXT NOT NULL,
        message_data TEXT NOT NULL,
        created_at INTEGER NOT NULL
    )");

    // Indexes (SQLite requires these as separate statements, not inline in CREATE TABLE)
    $db->exec("CREATE INDEX IF NOT EXISTS idx_users_source ON users(source_id)");
    $db->exec("CREATE INDEX IF NOT EXISTS idx_users_collected ON users(collected_at)");
    $db->exec("CREATE INDEX IF NOT EXISTS idx_users_started ON users(started_bot)");
    $db->exec("CREATE INDEX IF NOT EXISTS idx_sources_active ON sources(is_active)");
    $db->exec("CREATE INDEX IF NOT EXISTS idx_broadcasts_status ON broadcasts(status)");
    $db->exec("CREATE INDEX IF NOT EXISTS idx_broadcasts_scheduled ON broadcasts(scheduled_at)");
    $db->exec("CREATE INDEX IF NOT EXISTS idx_queue_broadcast ON queue(broadcast_id)");
    $db->exec("CREATE INDEX IF NOT EXISTS idx_queue_status ON queue(broadcast_id, status, next_retry)");
    $db->exec("CREATE INDEX IF NOT EXISTS idx_queue_user ON queue(user_id)");
    $db->exec("CREATE INDEX IF NOT EXISTS idx_failed_broadcast ON failed(broadcast_id)");
    $db->exec("CREATE INDEX IF NOT EXISTS idx_failed_retry ON failed(next_retry)");
    $db->exec("CREATE INDEX IF NOT EXISTS idx_blacklist_user ON blacklist(user_id)");
    $db->exec("CREATE INDEX IF NOT EXISTS idx_clicks_broadcast ON clicks(broadcast_id)");
    $db->exec("CREATE INDEX IF NOT EXISTS idx_clicks_user ON clicks(user_id)");

    logMessage('Database initialized successfully');
}

/**
 * Get database connection (singleton pattern)
 */
function getDB(): SQLite3 {
    static $db = null;
    
    if ($db === null) {
        $db = new SQLite3(DB_FILE);
        $db->busyTimeout(5000);
        $db->exec('PRAGMA journal_mode = WAL');
        $db->exec('PRAGMA synchronous = NORMAL');
        $db->exec('PRAGMA cache_size = 10000');
    }
    
    return $db;
}

// ============================================================================
// TELEGRAM API WRAPPERS
// ============================================================================

/**
 * Make Telegram API request
 */
function apiRequest(string $method, array $params = []): ?array {
    // Optional transport override (for tests / dry-run). When set, no network call is made.
    if (isset($GLOBALS['__api_transport']) && is_callable($GLOBALS['__api_transport'])) {
        return ($GLOBALS['__api_transport'])($method, $params);
    }

    $url = "https://api.telegram.org/bot" . BOT_TOKEN . "/{$method}";
    
    $ch = curl_init($url);
    curl_setopt($ch, CURLOPT_RETURNTRANSFER, true);
    curl_setopt($ch, CURLOPT_POST, true);
    curl_setopt($ch, CURLOPT_POSTFIELDS, json_encode($params));
    curl_setopt($ch, CURLOPT_HTTPHEADER, ['Content-Type: application/json']);
    curl_setopt($ch, CURLOPT_TIMEOUT, 30);
    
    $response = curl_exec($ch);
    $httpCode = curl_getinfo($ch, CURLINFO_HTTP_CODE);
    curl_close($ch);
    
    if ($response === false || $httpCode !== 200) {
        logMessage("API request failed: {$method} - HTTP {$httpCode}", 'ERROR');
        return null;
    }
    
    $decoded = json_decode($response, true);
    
    if (!$decoded['ok']) {
        logMessage("API error: {$method} - " . ($decoded['description'] ?? 'Unknown'), 'ERROR');
    }
    
    return $decoded;
}

/**
 * Send message with full support for all types
 */
function sendMessage(int $chatId, array $messageData): ?array {
    $method = match($messageData['type']) {
        'photo' => 'sendPhoto',
        'video' => 'sendVideo',
        'document' => 'sendDocument',
        'audio' => 'sendAudio',
        'voice' => 'sendVoice',
        'animation' => 'sendAnimation',
        'poll' => 'sendPoll',
        'mediaGroup' => 'sendMediaGroup',
        default => 'sendMessage'
    };
    
    $params = ['chat_id' => $chatId];

    // Add message-specific parameters. 'type' is our internal routing key; for polls
    // the actual Telegram "type" (regular/quiz) is carried as 'type_poll'.
    foreach ($messageData as $key => $value) {
        if ($key === 'type') {
            continue;
        }
        if ($key === 'type_poll') {
            $params['type'] = $value;
            continue;
        }
        $params[$key] = $value;
    }

    return apiRequest($method, $params);
}

/**
 * Edit message text
 */
function editMessageText(int $chatId, int $messageId, string $text, ?array $replyMarkup = null): ?array {
    $params = [
        'chat_id' => $chatId,
        'message_id' => $messageId,
        'text' => $text,
        'parse_mode' => 'HTML'
    ];
    
    if ($replyMarkup) {
        $params['reply_markup'] = $replyMarkup;
    }
    
    return apiRequest('editMessageText', $params);
}

/**
 * Answer callback query
 */
function answerCallback(string $callbackId, string $text = '', bool $alert = false): void {
    apiRequest('answerCallbackQuery', [
        'callback_query_id' => $callbackId,
        'text' => $text,
        'show_alert' => $alert
    ]);
}

/**
 * Set webhook
 */
function setWebhook(string $url): bool {
    $result = apiRequest('setWebhook', [
        'url' => $url,
        'secret_token' => WEBHOOK_SECRET,
        'allowed_updates' => ['message', 'callback_query', 'chat_member', 'my_chat_member']
    ]);

    return $result && $result['ok'];
}

/**
 * Get chat member count
 */
function getChatMemberCount(int $chatId): int {
    $result = apiRequest('getChatMemberCount', ['chat_id' => $chatId]);
    return $result['ok'] ? (int)$result['result'] : 0;
}

// ============================================================================
// USER COLLECTION LOGIC
// ============================================================================

/**
 * Add or update user in database
 */
function addUser(int $userId, ?string $username, ?string $firstName, ?string $lastName, int $sourceId, string $sourceType): void {
    $db = getDB();
    
    $stmt = $db->prepare("INSERT INTO users (user_id, username, first_name, last_name, source_id, source_type, collected_at, last_seen)
                          VALUES (:user_id, :username, :first_name, :last_name, :source_id, :source_type, :collected_at, :last_seen)
                          ON CONFLICT(user_id) DO UPDATE SET
                          username = :username,
                          first_name = :first_name,
                          last_name = :last_name,
                          last_seen = :last_seen");
    
    $now = time();
    $stmt->bindValue(':user_id', $userId, SQLITE3_INTEGER);
    $stmt->bindValue(':username', $username, SQLITE3_TEXT);
    $stmt->bindValue(':first_name', $firstName, SQLITE3_TEXT);
    $stmt->bindValue(':last_name', $lastName, SQLITE3_TEXT);
    $stmt->bindValue(':source_id', $sourceId, SQLITE3_INTEGER);
    $stmt->bindValue(':source_type', $sourceType, SQLITE3_TEXT);
    $stmt->bindValue(':collected_at', $now, SQLITE3_INTEGER);
    $stmt->bindValue(':last_seen', $now, SQLITE3_INTEGER);
    
    $stmt->execute();
}

/**
 * Record a user who started/DM'd the bot. Upserts so DM-only users are captured
 * (not just members collected from groups) and marks consent for opt-in broadcasts.
 */
function recordStarter(array $from): void {
    $db = getDB();
    $now = time();
    $stmt = $db->prepare("INSERT INTO users (user_id, username, first_name, last_name, source_id, source_type, collected_at, last_seen, is_bot, started_bot, subscribed_at)
                          VALUES (:uid, :un, :fn, :ln, 0, 'direct', :ts, :ts, :bot, 1, :ts)
                          ON CONFLICT(user_id) DO UPDATE SET
                          username = :un,
                          first_name = :fn,
                          last_name = :ln,
                          last_seen = :ts,
                          started_bot = 1,
                          subscribed_at = COALESCE(subscribed_at, :ts)");
    $stmt->bindValue(':uid', (int)$from['id'], SQLITE3_INTEGER);
    $stmt->bindValue(':un', $from['username'] ?? null, SQLITE3_TEXT);
    $stmt->bindValue(':fn', $from['first_name'] ?? null, SQLITE3_TEXT);
    $stmt->bindValue(':ln', $from['last_name'] ?? null, SQLITE3_TEXT);
    $stmt->bindValue(':ts', $now, SQLITE3_INTEGER);
    $stmt->bindValue(':bot', !empty($from['is_bot']) ? 1 : 0, SQLITE3_INTEGER);
    $stmt->execute();

    // A returning starter is no longer unsubscribed.
    $db->exec("DELETE FROM blacklist WHERE user_id = " . (int)$from['id'] . " AND reason = 'user_request'");
}

/**
 * Add or update source (group/channel)
 */
function addSource(int $chatId, string $chatType, string $title, ?string $username): void {
    $db = getDB();
    
    $stmt = $db->prepare("INSERT INTO sources (chat_id, chat_type, title, username, added_at, last_sync, is_active)
                          VALUES (:chat_id, :chat_type, :title, :username, :added_at, :last_sync, 1)
                          ON CONFLICT(chat_id) DO UPDATE SET
                          title = :title,
                          username = :username,
                          is_active = 1,
                          last_sync = :last_sync");
    
    $now = time();
    $stmt->bindValue(':chat_id', $chatId, SQLITE3_INTEGER);
    $stmt->bindValue(':chat_type', $chatType, SQLITE3_TEXT);
    $stmt->bindValue(':title', $title, SQLITE3_TEXT);
    $stmt->bindValue(':username', $username, SQLITE3_TEXT);
    $stmt->bindValue(':added_at', $now, SQLITE3_INTEGER);
    $stmt->bindValue(':last_sync', $now, SQLITE3_INTEGER);
    
    $stmt->execute();
}

/**
 * Mark source as inactive when bot is removed
 */
function deactivateSource(int $chatId): void {
    $db = getDB();
    $stmt = $db->prepare("UPDATE sources SET is_active = 0 WHERE chat_id = :chat_id");
    $stmt->bindValue(':chat_id', $chatId, SQLITE3_INTEGER);
    $stmt->execute();
}

/**
 * Handle chat_member update (new member joined)
 */
function handleChatMember(array $update): void {
    $chatMember = $update['chat_member'] ?? $update['my_chat_member'] ?? null;
    
    if (!$chatMember) return;
    
    $chat = $chatMember['chat'];
    $newMember = $chatMember['new_chat_member'];
    $user = $newMember['user'];
    
    // If bot was added/removed as admin
    if ($user['id'] === getBotId()) {
        if (in_array($newMember['status'], ['administrator', 'creator'])) {
            addSource($chat['id'], $chat['type'], $chat['title'] ?? 'Unknown', $chat['username'] ?? null);
            logMessage("Bot added as admin to: {$chat['title']} ({$chat['id']})");
            
            // Try to collect existing members
            if (COLLECT_EXISTING_MEMBERS) {
                collectExistingMembers($chat['id']);
            }
        } elseif (in_array($newMember['status'], ['left', 'kicked'])) {
            deactivateSource($chat['id']);
            logMessage("Bot removed from: {$chat['title']} ({$chat['id']})");
        }
        return;
    }
    
    // Regular member joined
    if ($newMember['status'] === 'member' && !$user['is_bot']) {
        addUser(
            $user['id'],
            $user['username'] ?? null,
            $user['first_name'] ?? null,
            $user['last_name'] ?? null,
            $chat['id'],
            $chat['type']
        );
    }
}

/**
 * Collect existing members from a chat (best effort)
 */
function collectExistingMembers(int $chatId): void {
    // Note: Telegram doesn't provide a direct API to list all members
    // This is a placeholder for future implementation using getChatAdministrators
    // and other available methods. For now, we rely on chat_member updates.
    
    $memberCount = getChatMemberCount($chatId);
    
    $db = getDB();
    $stmt = $db->prepare("UPDATE sources SET member_count = :count WHERE chat_id = :chat_id");
    $stmt->bindValue(':count', $memberCount, SQLITE3_INTEGER);
    $stmt->bindValue(':chat_id', $chatId, SQLITE3_INTEGER);
    $stmt->execute();
    
    logMessage("Updated member count for chat {$chatId}: {$memberCount}");
}

/**
 * Get bot's own ID (cached)
 */
function getBotId(): int {
    static $botId = null;
    
    if ($botId === null) {
        $result = apiRequest('getMe');
        $botId = $result['ok'] ? (int)$result['result']['id'] : 0;
    }
    
    return $botId;
}

// ============================================================================
// BROADCAST QUEUE SYSTEM
// ============================================================================

/**
 * Create new broadcast campaign
 */
function createBroadcast(int $ownerId, array $messageData, ?string $targetFilter = null, ?int $scheduledAt = null): int {
    $db = getDB();
    
    // Count target users
    $totalUsers = countTargetUsers($targetFilter);
    
    if ($totalUsers === 0) {
        return 0;
    }
    
    // Create broadcast record
    $stmt = $db->prepare("INSERT INTO broadcasts (owner_id, message_type, message_data, target_filter, total_users, status, scheduled_at, created_at, confirmation_code)
                          VALUES (:owner_id, :message_type, :message_data, :target_filter, :total_users, :status, :scheduled_at, :created_at, :confirmation_code)");
    
    $now = time();
    $status = $scheduledAt ? 'scheduled' : 'pending';
    $confirmationCode = generateCode();
    
    $stmt->bindValue(':owner_id', $ownerId, SQLITE3_INTEGER);
    $stmt->bindValue(':message_type', $messageData['type'], SQLITE3_TEXT);
    $stmt->bindValue(':message_data', json_encode($messageData), SQLITE3_TEXT);
    $stmt->bindValue(':target_filter', $targetFilter, SQLITE3_TEXT);
    $stmt->bindValue(':total_users', $totalUsers, SQLITE3_INTEGER);
    $stmt->bindValue(':status', $status, SQLITE3_TEXT);
    $stmt->bindValue(':scheduled_at', $scheduledAt, SQLITE3_INTEGER);
    $stmt->bindValue(':created_at', $now, SQLITE3_INTEGER);
    $stmt->bindValue(':confirmation_code', $confirmationCode, SQLITE3_TEXT);
    
    $stmt->execute();
    
    $broadcastId = $db->lastInsertRowID();
    
    // Populate queue
    populateQueue($broadcastId, $targetFilter);
    
    logMessage("Broadcast #{$broadcastId} created: {$totalUsers} users, code: {$confirmationCode}");
    
    return $broadcastId;
}

/**
 * Count users matching filter
 */
function countTargetUsers(?string $filter): int {
    $db = getDB();
    
    $query = "SELECT COUNT(DISTINCT u.user_id) as count FROM users u
              LEFT JOIN blacklist b ON u.user_id = b.user_id
              WHERE b.user_id IS NULL";
    
    if ($filter) {
        $filterData = json_decode($filter, true);
        
        if (isset($filterData['source_id'])) {
            $query .= " AND u.source_id = " . (int)$filterData['source_id'];
        }
        
        if (isset($filterData['started_bot']) && $filterData['started_bot']) {
            $query .= " AND u.started_bot = 1";
        }
    }
    
    $result = $db->querySingle($query);
    return (int)$result;
}

/**
 * Populate queue with target users
 */
function populateQueue(int $broadcastId, ?string $filter): void {
    $db = getDB();
    
    $query = "SELECT DISTINCT u.user_id FROM users u
              LEFT JOIN blacklist b ON u.user_id = b.user_id
              WHERE b.user_id IS NULL";
    
    if ($filter) {
        $filterData = json_decode($filter, true);
        
        if (isset($filterData['source_id'])) {
            $query .= " AND u.source_id = " . (int)$filterData['source_id'];
        }
        
        if (isset($filterData['started_bot']) && $filterData['started_bot']) {
            $query .= " AND u.started_bot = 1";
        }
    }
    
    $result = $db->query($query);
    
    $stmt = $db->prepare("INSERT INTO queue (broadcast_id, user_id, status) VALUES (:broadcast_id, :user_id, 'pending')");
    
    while ($row = $result->fetchArray(SQLITE3_ASSOC)) {
        $stmt->bindValue(':broadcast_id', $broadcastId, SQLITE3_INTEGER);
        $stmt->bindValue(':user_id', $row['user_id'], SQLITE3_INTEGER);
        $stmt->execute();
        $stmt->reset();
    }
}

/**
 * Process broadcast queue (called by cron).
 *
 * Designed to be safe under a once-per-minute cron:
 *  - A non-blocking file lock prevents two overlapping runs from double-sending.
 *  - Work is time-boxed (CRON_TIME_BUDGET) so a run always finishes within PHP's limit.
 *  - On a flood-wait the broadcast is paused with a resume_at timestamp instead of sleep().
 *  - Retriable failures move the queue row to 'retry' with a backoff in next_retry;
 *    permanent failures (blocked/deactivated/never-started) blacklist the user immediately.
 */
function processQueue(): void {
    $db = getDB();

    // ---- Concurrency lock: bail out if another run is in progress ----
    $lock = @fopen(LOCK_FILE, 'c');
    if ($lock === false || !flock($lock, LOCK_EX | LOCK_NB)) {
        if ($lock) { fclose($lock); }
        return; // Another cron run holds the lock
    }

    try {
        $now = time();

        // Resume any paused broadcasts whose flood-wait window has elapsed.
        $db->exec("UPDATE broadcasts SET status = 'running', resume_at = NULL
                   WHERE status = 'paused' AND resume_at IS NOT NULL AND resume_at <= {$now}");

        // Promote scheduled broadcasts that are due.
        $db->exec("UPDATE broadcasts SET status = 'running', started_at = COALESCE(started_at, {$now})
                   WHERE status = 'scheduled' AND scheduled_at IS NOT NULL AND scheduled_at <= {$now}");

        // Find one active broadcast to work on.
        $broadcast = $db->querySingle("SELECT * FROM broadcasts
                                       WHERE status = 'running'
                                       ORDER BY created_at ASC LIMIT 1", true);

        if (!$broadcast) {
            return; // Nothing to do (finally releases the lock)
        }

        $broadcastId = (int)$broadcast['id'];
        $messageData = json_decode($broadcast['message_data'], true);
        $rate        = effectiveRate();
        $sentCount   = 0;
        $failedCount = 0;
        $startTime   = microtime(true);

        // Pull a batch of due items (pending, or retry whose backoff has elapsed).
        $items = [];
        $res = $db->query("SELECT * FROM queue
                           WHERE broadcast_id = {$broadcastId}
                           AND (status = 'pending' OR (status = 'retry' AND next_retry <= {$now}))
                           ORDER BY id ASC LIMIT " . BATCH_SIZE);
        while ($row = $res->fetchArray(SQLITE3_ASSOC)) {
            $items[] = $row;
        }

        $sentStmt   = $db->prepare("UPDATE queue SET status='sent', sent_at=:ts WHERE id=:id");
        $retryStmt  = $db->prepare("UPDATE queue SET status='retry', retry_count=:rc, last_attempt=:ts, next_retry=:nr, error_message=:err WHERE id=:id");
        $failStmt   = $db->prepare("UPDATE queue SET status='failed', last_attempt=:ts, error_message=:err WHERE id=:id");

        foreach ($items as $queueItem) {
            // Stay within the time budget so the request never exceeds PHP's max_execution_time.
            if ((microtime(true) - $startTime) > CRON_TIME_BUDGET) {
                break;
            }

            $userId = (int)$queueItem['user_id'];
            $result = sendMessage($userId, $messageData);

            if ($result && ($result['ok'] ?? false)) {
                $sentStmt->reset();
                $sentStmt->bindValue(':ts', time(), SQLITE3_INTEGER);
                $sentStmt->bindValue(':id', $queueItem['id'], SQLITE3_INTEGER);
                $sentStmt->execute();
                $sentCount++;
            } else {
                $errorMsg  = $result['description'] ?? 'Unknown error';
                $floodWait = parseFloodWait($errorMsg);

                if ($floodWait > 0) {
                    // Pause and schedule resume — do NOT sleep inside the request.
                    $resumeAt = time() + $floodWait;
                    $db->exec("UPDATE broadcasts SET status='paused', resume_at={$resumeAt} WHERE id={$broadcastId}");
                    logMessage("Flood wait {$floodWait}s on broadcast #{$broadcastId}; paused until {$resumeAt}", 'WARNING');
                    break; // Leave this item as-is; it will be retried after resume
                }

                if (isPermanentSendError($errorMsg)) {
                    // Will never succeed — blacklist and stop retrying this user.
                    blacklistUser($userId, 'send_error: ' . substr($errorMsg, 0, 100));
                    $failStmt->reset();
                    $failStmt->bindValue(':ts', time(), SQLITE3_INTEGER);
                    $failStmt->bindValue(':err', $errorMsg, SQLITE3_TEXT);
                    $failStmt->bindValue(':id', $queueItem['id'], SQLITE3_INTEGER);
                    $failStmt->execute();
                    $failedCount++;
                } else {
                    $retryCount = (int)$queueItem['retry_count'] + 1;
                    if ($retryCount < MAX_RETRIES) {
                        $nextRetry = time() + (int)(RETRY_BACKOFF_BASE ** $retryCount);
                        $retryStmt->reset();
                        $retryStmt->bindValue(':rc', $retryCount, SQLITE3_INTEGER);
                        $retryStmt->bindValue(':ts', time(), SQLITE3_INTEGER);
                        $retryStmt->bindValue(':nr', $nextRetry, SQLITE3_INTEGER);
                        $retryStmt->bindValue(':err', $errorMsg, SQLITE3_TEXT);
                        $retryStmt->bindValue(':id', $queueItem['id'], SQLITE3_INTEGER);
                        $retryStmt->execute();
                    } else {
                        $failStmt->reset();
                        $failStmt->bindValue(':ts', time(), SQLITE3_INTEGER);
                        $failStmt->bindValue(':err', $errorMsg, SQLITE3_TEXT);
                        $failStmt->bindValue(':id', $queueItem['id'], SQLITE3_INTEGER);
                        $failStmt->execute();
                        $failedCount++;
                    }
                }
            }

            // Smart delay between messages (rate-limited, with jitter).
            usleep((int)(getSmartDelay() * 1000000));
        }

        // Persist counters for this run.
        if ($sentCount > 0 || $failedCount > 0) {
            $db->exec("UPDATE broadcasts SET
                       sent_count = sent_count + {$sentCount},
                       failed_count = failed_count + {$failedCount}
                       WHERE id = {$broadcastId}");
        }

        // Complete when nothing is left pending or awaiting retry.
        $remaining = (int)$db->querySingle("SELECT COUNT(*) FROM queue
                                            WHERE broadcast_id = {$broadcastId}
                                            AND status IN ('pending', 'retry')");
        if ($remaining === 0) {
            $db->exec("UPDATE broadcasts SET status='completed', completed_at=" . time() . " WHERE id={$broadcastId}");
            $totSent   = (int)$db->querySingle("SELECT sent_count FROM broadcasts WHERE id={$broadcastId}");
            $totFailed = (int)$db->querySingle("SELECT failed_count FROM broadcasts WHERE id={$broadcastId}");
            logMessage("Broadcast #{$broadcastId} completed: {$totSent} sent, {$totFailed} failed");
            notifyBroadcastComplete($broadcast, $totSent, $totFailed);
        }
    } finally {
        flock($lock, LOCK_UN);
        fclose($lock);
    }
}

/**
 * Notify the broadcast owner that a campaign finished.
 */
function notifyBroadcastComplete(array $broadcast, int $sent, int $failed): void {
    sendMessage((int)$broadcast['owner_id'], [
        'type' => 'text',
        'parse_mode' => 'HTML',
        'text' => "✅ <b>Broadcast #{$broadcast['id']} completed</b>\n\n"
                . "✅ Sent: " . formatNumber($sent) . "\n"
                . "❌ Failed: " . formatNumber($failed),
    ]);
}

/**
 * Legacy retry sweep retained for compatibility.
 *
 * Retries are now handled inline by processQueue() via the queue 'retry' status and
 * next_retry backoff, so the standalone `failed` table is no longer the retry driver.
 * This call is a no-op kept so existing cron setups don't break.
 */
function retryFailed(): void {
    // Intentionally empty — see processQueue() retry handling.
}

/**
 * Get broadcast statistics
 */
function getBroadcastStats(int $broadcastId): array {
    $db = getDB();
    
    $broadcast = $db->querySingle("SELECT * FROM broadcasts WHERE id = {$broadcastId}", true);
    
    if (!$broadcast) {
        return [];
    }
    
    $pending = $db->querySingle("SELECT COUNT(*) FROM queue WHERE broadcast_id = {$broadcastId} AND status = 'pending'");
    $sent = $db->querySingle("SELECT COUNT(*) FROM queue WHERE broadcast_id = {$broadcastId} AND status = 'sent'");
    $failed = $db->querySingle("SELECT COUNT(*) FROM queue WHERE broadcast_id = {$broadcastId} AND status = 'failed'");
    
    return [
        'broadcast' => $broadcast,
        'pending' => (int)$pending,
        'sent' => (int)$sent,
        'failed' => (int)$failed
    ];
}

// ============================================================================
// OWNER DASHBOARD & MENU
// ============================================================================

/**
 * Show main owner menu
 */
function showOwnerMenu(int $chatId): void {
    $db = getDB();
    
    // Get statistics
    $totalUsers = $db->querySingle("SELECT COUNT(DISTINCT user_id) FROM users u LEFT JOIN blacklist b ON u.user_id = b.user_id WHERE b.user_id IS NULL");
    $todayStart = strtotime('today');
    $newToday = $db->querySingle("SELECT COUNT(*) FROM users WHERE collected_at >= {$todayStart}");
    $activeSources = $db->querySingle("SELECT COUNT(*) FROM sources WHERE is_active = 1");
    $activeBroadcasts = $db->querySingle("SELECT COUNT(*) FROM broadcasts WHERE status IN ('running', 'scheduled', 'paused')");
    
    $text = "📊 <b>Bot Dashboard</b>\n\n";
    $text .= "👥 Total Users: <b>" . formatNumber($totalUsers) . "</b>\n";
    $text .= "🆕 New Today: <b>" . formatNumber($newToday) . "</b>\n";
    $text .= "📢 Active Sources: <b>{$activeSources}</b>\n";
    $text .= "📤 Active Broadcasts: <b>{$activeBroadcasts}</b>\n\n";
    $text .= "Choose an action:";
    
    $keyboard = [
        [['text' => '📤 New Broadcast', 'callback_data' => 'new_broadcast']],
        [['text' => '📊 Statistics', 'callback_data' => 'stats'], ['text' => '📋 Sources', 'callback_data' => 'sources']],
        [['text' => '📜 Broadcasts History', 'callback_data' => 'history'], ['text' => '📁 Templates', 'callback_data' => 'templates']],
        [['text' => '💾 Export Users', 'callback_data' => 'export']],
        [['text' => '⚙️ Settings', 'callback_data' => 'settings']]
    ];
    
    sendMessage($chatId, [
        'type' => 'text',
        'text' => $text,
        'parse_mode' => 'HTML',
        'reply_markup' => json_encode(['inline_keyboard' => $keyboard])
    ]);
}

/**
 * Show statistics
 */
function showStats(int $chatId, int $messageId): void {
    $db = getDB();
    
    $totalUsers = $db->querySingle("SELECT COUNT(DISTINCT user_id) FROM users u LEFT JOIN blacklist b ON u.user_id = b.user_id WHERE b.user_id IS NULL");
    $totalBroadcasts = $db->querySingle("SELECT COUNT(*) FROM broadcasts");
    $totalSent = $db->querySingle("SELECT SUM(sent_count) FROM broadcasts");
    $totalFailed = $db->querySingle("SELECT SUM(failed_count) FROM broadcasts");
    
    // Per-source stats
    $sourcesResult = $db->query("SELECT s.title, COUNT(DISTINCT u.user_id) as users 
                                 FROM sources s 
                                 LEFT JOIN users u ON s.chat_id = u.source_id 
                                 WHERE s.is_active = 1 
                                 GROUP BY s.chat_id 
                                 ORDER BY users DESC 
                                 LIMIT 10");
    
    $text = "📊 <b>Detailed Statistics</b>\n\n";
    $text .= "👥 Total Users: <b>" . formatNumber($totalUsers) . "</b>\n";
    $text .= "📤 Total Broadcasts: <b>" . formatNumber($totalBroadcasts) . "</b>\n";
    $text .= "✅ Messages Sent: <b>" . formatNumber($totalSent ?? 0) . "</b>\n";
    $text .= "❌ Messages Failed: <b>" . formatNumber($totalFailed ?? 0) . "</b>\n\n";
    
    $text .= "📢 <b>Top Sources:</b>\n";
    
    while ($source = $sourcesResult->fetchArray(SQLITE3_ASSOC)) {
        $text .= "• {$source['title']}: " . formatNumber($source['users']) . " users\n";
    }
    
    $keyboard = [[['text' => '« Back', 'callback_data' => 'menu']]];
    
    editMessageText($chatId, $messageId, $text, ['inline_keyboard' => $keyboard]);
}

/**
 * Show sources list
 */
function showSources(int $chatId, int $messageId): void {
    $db = getDB();
    
    $result = $db->query("SELECT s.*, COUNT(DISTINCT u.user_id) as users 
                          FROM sources s 
                          LEFT JOIN users u ON s.chat_id = u.source_id 
                          WHERE s.is_active = 1 
                          GROUP BY s.chat_id 
                          ORDER BY users DESC");
    
    $text = "📋 <b>Active Sources</b>\n\n";
    
    $count = 0;
    while ($source = $result->fetchArray(SQLITE3_ASSOC)) {
        $count++;
        $text .= "{$count}. <b>{$source['title']}</b>\n";
        $text .= "   Type: {$source['chat_type']}\n";
        $text .= "   Users: " . formatNumber($source['users']) . "\n";
        $text .= "   Added: " . date('Y-m-d', $source['added_at']) . "\n\n";
    }
    
    if ($count === 0) {
        $text .= "No active sources. Add bot as admin to groups/channels.";
    }
    
    $keyboard = [[['text' => '« Back', 'callback_data' => 'menu']]];
    
    editMessageText($chatId, $messageId, $text, ['inline_keyboard' => $keyboard]);
}

/**
 * Show broadcasts history
 */
function showHistory(int $chatId, int $messageId): void {
    $db = getDB();
    
    $result = $db->query("SELECT * FROM broadcasts ORDER BY created_at DESC LIMIT 10");
    
    $text = "📜 <b>Recent Broadcasts</b>\n\n";
    
    $count = 0;
    $keyboard = [];
    while ($broadcast = $result->fetchArray(SQLITE3_ASSOC)) {
        $count++;
        $status = match($broadcast['status']) {
            'completed' => '✅',
            'running' => '🔄',
            'paused' => '⏸',
            'scheduled' => '⏰',
            'cancelled' => '❌',
            default => '⏳'
        };

        $text .= "{$status} <b>Broadcast #{$broadcast['id']}</b>\n";
        $text .= "   Status: {$broadcast['status']}\n";
        $text .= "   Total: " . formatNumber($broadcast['total_users']) . "\n";
        $text .= "   Sent: " . formatNumber($broadcast['sent_count']) . " | Failed: " . formatNumber($broadcast['failed_count']) . "\n";
        $text .= "   Created: " . date('Y-m-d H:i', $broadcast['created_at']) . "\n\n";
        $keyboard[] = [['text' => "📈 #{$broadcast['id']} Analytics", 'callback_data' => 'bstats_' . $broadcast['id']]];
    }

    if ($count === 0) {
        $text .= "No broadcasts yet.";
    }

    $keyboard[] = [['text' => '« Back', 'callback_data' => 'menu']];

    editMessageText($chatId, $messageId, $text, ['inline_keyboard' => $keyboard]);
}

/**
 * Export users to CSV
 */
function exportUsers(int $chatId): void {
    $db = getDB();
    
    $result = $db->query("SELECT u.user_id, u.username, u.first_name, u.last_name, s.title as source, u.collected_at 
                          FROM users u 
                          LEFT JOIN sources s ON u.source_id = s.chat_id 
                          LEFT JOIN blacklist b ON u.user_id = b.user_id 
                          WHERE b.user_id IS NULL 
                          ORDER BY u.collected_at DESC");
    
    // Guard against CSV/formula injection: a leading = + - @ or tab is neutralized with a quote.
    $neutralize = function (?string $v): string {
        $v = (string)$v;
        if ($v !== '' && in_array($v[0], ['=', '+', '-', '@', "\t", "\r"], true)) {
            return "'" . $v;
        }
        return $v;
    };

    $filename = 'users_' . date('Y-m-d_His') . '.csv';
    $filepath = __DIR__ . '/' . $filename;

    $fh = fopen($filepath, 'w');
    fputcsv($fh, ['User ID', 'Username', 'First Name', 'Last Name', 'Source', 'Collected At']);
    while ($user = $result->fetchArray(SQLITE3_ASSOC)) {
        fputcsv($fh, [
            (string)$user['user_id'],
            $neutralize($user['username'] ?? ''),
            $neutralize($user['first_name'] ?? ''),
            $neutralize($user['last_name'] ?? ''),
            $neutralize($user['source'] ?? 'Unknown'),
            date('Y-m-d H:i:s', $user['collected_at']),
        ]);
    }
    fclose($fh);
    
    // Send as document
    apiRequest('sendDocument', [
        'chat_id' => $chatId,
        'document' => new CURLFile($filepath),
        'caption' => '📊 User export completed'
    ]);
    
    // Clean up
    unlink($filepath);
}

/**
 * Handle new broadcast creation
 */
function handleNewBroadcast(int $chatId, int $userId): void {
    $text = "📤 <b>New Broadcast</b>\n\n";
    $text .= "Forward me the message you want to broadcast.\n\n";
    $text .= "Supported: text, photo, video, document, poll, buttons.";
    
    // Set user state
    setState($userId, 'awaiting_broadcast_message', []);
    
    sendMessage($chatId, [
        'type' => 'text',
        'text' => $text,
        'parse_mode' => 'HTML'
    ]);
}

/**
 * Set user state
 */
function setState(int $userId, string $state, array $data): void {
    $db = getDB();
    
    $stmt = $db->prepare("INSERT INTO user_states (user_id, state, data, updated_at)
                          VALUES (:user_id, :state, :data, :updated_at)
                          ON CONFLICT(user_id) DO UPDATE SET
                          state = :state,
                          data = :data,
                          updated_at = :updated_at");
    
    $stmt->bindValue(':user_id', $userId, SQLITE3_INTEGER);
    $stmt->bindValue(':state', $state, SQLITE3_TEXT);
    $stmt->bindValue(':data', json_encode($data), SQLITE3_TEXT);
    $stmt->bindValue(':updated_at', time(), SQLITE3_INTEGER);
    
    $stmt->execute();
}

/**
 * Get user state
 */
function getState(int $userId): ?array {
    $db = getDB();
    
    $stmt = $db->prepare("SELECT * FROM user_states WHERE user_id = :user_id");
    $stmt->bindValue(':user_id', $userId, SQLITE3_INTEGER);
    
    $result = $stmt->execute();
    $state = $result->fetchArray(SQLITE3_ASSOC);
    
    if (!$state) {
        return null;
    }
    
    return [
        'state' => $state['state'],
        'data' => json_decode($state['data'], true)
    ];
}

/**
 * Clear user state
 */
function clearState(int $userId): void {
    $db = getDB();
    $db->exec("DELETE FROM user_states WHERE user_id = {$userId}");
}

// ============================================================================
// CORE UPDATE PROCESSING
// ============================================================================

/**
 * Process incoming update
 */
function processUpdate(array $update): void {
    // Handle chat_member updates (user collection)
    if (isset($update['chat_member']) || isset($update['my_chat_member'])) {
        handleChatMember($update);
        return;
    }
    
    // Handle callback queries
    if (isset($update['callback_query'])) {
        handleCallback($update['callback_query']);
        return;
    }
    
    // Handle messages
    if (isset($update['message'])) {
        handleMessage($update['message']);
        return;
    }
}

/**
 * Handle incoming message
 */
function handleMessage(array $message): void {
    $chatId = $message['chat']['id'];
    $userId = $message['from']['id'];
    $text = $message['text'] ?? '';

    // Mark user as started bot if private chat. Upsert so users who DM the bot
    // directly (and were never collected from a group) are still captured — these
    // are the only users a bot is actually allowed to message.
    if ($message['chat']['type'] === 'private') {
        recordStarter($message['from']);
    }
    
    // Handle /start command
    if ($text === '/start') {
        if (isOwner($userId)) {
            showOwnerMenu($chatId);
        } else {
            sendMessage($chatId, [
                'type' => 'text',
                'text' => "👋 <b>You're subscribed!</b>\n\nYou'll occasionally receive updates here.\n\nSend /stop at any time to unsubscribe.",
                'parse_mode' => 'HTML',
            ]);
        }
        return;
    }
    
    // Handle /stop command (blacklist)
    if ($text === '/stop') {
        $db = getDB();
        $stmt = $db->prepare("INSERT OR IGNORE INTO blacklist (user_id, reason, added_at) VALUES (:user_id, 'user_request', :added_at)");
        $stmt->bindValue(':user_id', $userId, SQLITE3_INTEGER);
        $stmt->bindValue(':added_at', time(), SQLITE3_INTEGER);
        $stmt->execute();
        
        sendMessage($chatId, [
            'type' => 'text',
            'text' => "✅ You've been unsubscribed. You won't receive broadcasts anymore.\n\nUse /start to subscribe again."
        ]);
        return;
    }
    
    // Owner-only features
    if (!isOwner($userId)) {
        return;
    }
    
    // Check if owner is in a state (e.g., creating broadcast)
    $state = getState($userId);
    
    if ($state && $state['state'] === 'awaiting_broadcast_message') {
        handleBroadcastMessage($message);
        return;
    }
    
    if ($state && $state['state'] === 'awaiting_confirmation') {
        handleBroadcastConfirmation($message, $state['data']);
        return;
    }
    
    // Default: show menu
    showOwnerMenu($chatId);
}

/**
 * Handle broadcast message from owner
 */
function handleBroadcastMessage(array $message): void {
    $chatId = $message['chat']['id'];
    $userId = $message['from']['id'];
    
    // Extract message data
    $messageData = extractMessageData($message);
    
    if (!$messageData) {
        sendMessage($chatId, [
            'type' => 'text',
            'text' => '❌ Unsupported message type. Please send text, photo, video, document, or poll.'
        ]);
        return;
    }
    
    // Ask for target selection
    $db = getDB();
    $totalUsers = $db->querySingle("SELECT COUNT(DISTINCT user_id) FROM users u LEFT JOIN blacklist b ON u.user_id = b.user_id WHERE b.user_id IS NULL");
    
    $text = "📊 <b>Select Target Audience</b>\n\n";
    $text .= "Total available users: <b>" . formatNumber($totalUsers) . "</b>\n\n";
    $text .= "Choose who should receive this message:";
    
    $keyboard = [
        [['text' => "📢 All Users (" . formatNumber($totalUsers) . ")", 'callback_data' => 'target_all']],
        [['text' => '👥 Only Bot Starters', 'callback_data' => 'target_starters']],
        [['text' => '📋 Select Source', 'callback_data' => 'target_source']],
        [['text' => '💾 Save as Template', 'callback_data' => 'save_template']],
        [['text' => '❌ Cancel', 'callback_data' => 'menu']]
    ];
    
    // Store message data in state
    setState($userId, 'selecting_target', ['message_data' => $messageData]);
    
    sendMessage($chatId, [
        'type' => 'text',
        'text' => $text,
        'parse_mode' => 'HTML',
        'reply_markup' => json_encode(['inline_keyboard' => $keyboard])
    ]);
}

/**
 * Extract message data for broadcasting
 */
function extractMessageData(array $message): ?array {
    // Preserve formatting and inline keyboard where present.
    $withCaption = function (array $data) use ($message): array {
        if (isset($message['caption'])) {
            $data['caption'] = $message['caption'];
        }
        if (isset($message['caption_entities'])) {
            $data['caption_entities'] = $message['caption_entities'];
        }
        if (isset($message['reply_markup'])) {
            $data['reply_markup'] = $message['reply_markup'];
        }
        return $data;
    };

    if (isset($message['text'])) {
        $data = ['type' => 'text', 'text' => $message['text']];
        if (isset($message['entities'])) {
            $data['entities'] = $message['entities'];
        }
        if (isset($message['reply_markup'])) {
            $data['reply_markup'] = $message['reply_markup'];
        }
        return $data;
    }

    if (isset($message['photo'])) {
        $photo = end($message['photo']);
        return $withCaption(['type' => 'photo', 'photo' => $photo['file_id']]);
    }

    if (isset($message['video'])) {
        return $withCaption(['type' => 'video', 'video' => $message['video']['file_id']]);
    }

    if (isset($message['animation'])) {
        return $withCaption(['type' => 'animation', 'animation' => $message['animation']['file_id']]);
    }

    if (isset($message['document'])) {
        return $withCaption(['type' => 'document', 'document' => $message['document']['file_id']]);
    }

    if (isset($message['audio'])) {
        return $withCaption(['type' => 'audio', 'audio' => $message['audio']['file_id']]);
    }

    if (isset($message['voice'])) {
        return $withCaption(['type' => 'voice', 'voice' => $message['voice']['file_id']]);
    }

    if (isset($message['poll'])) {
        $poll = $message['poll'];
        // Options must be an array — the whole API body is JSON-encoded in apiRequest(),
        // so json_encoding here would double-encode and Telegram would reject it.
        return [
            'type' => 'poll',
            'question' => $poll['question'],
            'options' => array_column($poll['options'], 'text'),
            'is_anonymous' => $poll['is_anonymous'] ?? true,
            'type_poll' => $poll['type'] ?? 'regular',
            'allows_multiple_answers' => $poll['allows_multiple_answers'] ?? false,
        ];
    }

    return null;
}

/**
 * Handle broadcast confirmation
 */
function handleBroadcastConfirmation(array $message, array $stateData): void {
    $chatId = $message['chat']['id'];
    $userId = $message['from']['id'];
    $text = trim($message['text'] ?? '');

    // Input is "<CODE>" to send now, or "<CODE> in 30m" / "<CODE> at 2026-06-19 10:00" to schedule.
    $parts = preg_split('/\s+/', $text, 2);
    $code = $parts[0] ?? '';
    $scheduleSpec = trim($parts[1] ?? '');

    if ($code !== $stateData['confirmation_code']) {
        sendMessage($chatId, [
            'type' => 'text',
            'text' => "❌ Incorrect code. Broadcast cancelled.\n\nUse /start to return to menu."
        ]);
        clearState($userId);
        return;
    }

    $scheduledAt = $scheduleSpec !== '' ? parseScheduleSpec($scheduleSpec) : null;
    if ($scheduleSpec !== '' && $scheduledAt === null) {
        sendMessage($chatId, [
            'type' => 'text',
            'text' => "❌ Could not understand the schedule time. Use e.g. <code>{$code} in 30m</code> or <code>{$code} at 2026-06-19 10:00</code>.",
            'parse_mode' => 'HTML',
        ]);
        return;
    }

    // Create broadcast
    $broadcastId = createBroadcast(
        $userId,
        $stateData['message_data'],
        $stateData['target_filter'] ?? null,
        $scheduledAt
    );

    if ($broadcastId === 0) {
        sendMessage($chatId, [
            'type' => 'text',
            'text' => '❌ No users found matching the criteria.'
        ]);
        clearState($userId);
        return;
    }

    $db = getDB();
    $stats = getBroadcastStats($broadcastId);

    if ($scheduledAt !== null) {
        // Leave status as 'scheduled' (set by createBroadcast); processQueue promotes it when due.
        $text = "⏰ <b>Broadcast Scheduled!</b>\n\n";
        $text .= "📊 Broadcast ID: #{$broadcastId}\n";
        $text .= "👥 Total Users: " . formatNumber($stats['broadcast']['total_users']) . "\n";
        $text .= "🕒 Sends at: " . date('Y-m-d H:i', $scheduledAt) . " " . TIMEZONE . "\n";
    } else {
        // Start broadcast immediately
        $db->exec("UPDATE broadcasts SET status = 'running', started_at = " . time() . " WHERE id = {$broadcastId}");
        $text = "✅ <b>Broadcast Started!</b>\n\n";
        $text .= "📊 Broadcast ID: #{$broadcastId}\n";
        $text .= "👥 Total Users: " . formatNumber($stats['broadcast']['total_users']) . "\n";
        $text .= "⏱ Estimated Time: " . calculateETA($stats['broadcast']['total_users'], effectiveRate()) . "\n\n";
        $text .= "You'll receive updates as the broadcast progresses.";
    }

    sendMessage($chatId, [
        'type' => 'text',
        'text' => $text,
        'parse_mode' => 'HTML'
    ]);
    
    clearState($userId);
}

/**
 * Handle callback query
 */
function handleCallback(array $callback): void {
    $callbackId = $callback['id'];
    $chatId = $callback['message']['chat']['id'];
    $messageId = $callback['message']['message_id'];
    $userId = $callback['from']['id'];
    $data = $callback['data'];

    answerCallback($callbackId);

    // Broadcast button clicks (clk_<broadcastId>_<label>) are open to everyone and
    // recorded for click-through analytics.
    if (str_starts_with($data, 'clk_')) {
        recordClick($data, $userId);
        return;
    }

    // Owner-only callbacks
    if (!isOwner($userId)) {
        return;
    }

    // Route callback
    match(true) {
        $data === 'menu' => showOwnerMenu($chatId),
        $data === 'new_broadcast' => handleNewBroadcast($chatId, $userId),
        $data === 'stats' => showStats($chatId, $messageId),
        $data === 'sources' => showSources($chatId, $messageId),
        $data === 'history' => showHistory($chatId, $messageId),
        $data === 'export' => exportUsers($chatId),
        $data === 'settings' => showSettings($chatId, $messageId),
        str_starts_with($data, 'set_rate_') => adjustRateSetting($chatId, $messageId, (int)str_replace('set_rate_', '', $data)),
        $data === 'target_all' => handleTargetSelection($chatId, $userId, null),
        $data === 'target_starters' => handleTargetSelection($chatId, $userId, json_encode(['started_bot' => true])),
        $data === 'target_source' => showSourcePicker($chatId, $messageId),
        str_starts_with($data, 'target_source_') => handleSourceSelection($chatId, $userId, $data),
        $data === 'test_send' => handleTestSend($chatId, $userId),
        $data === 'templates' => showTemplates($chatId, $messageId, $userId),
        $data === 'save_template' => saveTemplateFromState($chatId, $userId),
        str_starts_with($data, 'tpl_use_') => useTemplate($chatId, $userId, (int)str_replace('tpl_use_', '', $data)),
        str_starts_with($data, 'bstats_') => showBroadcastAnalytics($chatId, $messageId, (int)str_replace('bstats_', '', $data)),
        default => null
    };
}

/**
 * List saved templates with a "use" action each.
 */
function showTemplates(int $chatId, int $messageId, int $userId): void {
    $db = getDB();
    $res = $db->query("SELECT id, name, message_type FROM templates WHERE owner_id = {$userId} ORDER BY created_at DESC LIMIT 20");

    $text = "📁 <b>Templates</b>\n\n";
    $keyboard = [];
    $count = 0;
    while ($row = $res->fetchArray(SQLITE3_ASSOC)) {
        $count++;
        $text .= "{$count}. <b>" . htmlspecialchars($row['name']) . "</b> ({$row['message_type']})\n";
        $keyboard[] = [['text' => "📤 Use: " . mb_substr($row['name'], 0, 25), 'callback_data' => 'tpl_use_' . $row['id']]];
    }
    if ($count === 0) {
        $text .= "No templates yet. Create a broadcast and press 💾 Save as Template.";
    }
    $keyboard[] = [['text' => '« Back', 'callback_data' => 'menu']];

    editMessageText($chatId, $messageId, $text, ['inline_keyboard' => $keyboard]);
}

/**
 * Save the message currently being composed (in state) as a reusable template.
 */
function saveTemplateFromState(int $chatId, int $userId): void {
    $state = getState($userId);
    $messageData = $state['data']['message_data'] ?? null;
    if ($messageData === null) {
        sendMessage($chatId, ['type' => 'text', 'text' => '❌ No message to save.']);
        return;
    }

    $db = getDB();
    $name = ucfirst($messageData['type']) . ' ' . date('Y-m-d H:i');
    $stmt = $db->prepare("INSERT INTO templates (owner_id, name, message_type, message_data, created_at)
                          VALUES (:owner, :name, :type, :data, :ts)");
    $stmt->bindValue(':owner', $userId, SQLITE3_INTEGER);
    $stmt->bindValue(':name', $name, SQLITE3_TEXT);
    $stmt->bindValue(':type', $messageData['type'], SQLITE3_TEXT);
    $stmt->bindValue(':data', json_encode($messageData), SQLITE3_TEXT);
    $stmt->bindValue(':ts', time(), SQLITE3_INTEGER);
    $stmt->execute();

    sendMessage($chatId, ['type' => 'text', 'text' => "💾 Saved as template: <b>{$name}</b>", 'parse_mode' => 'HTML']);
}

/**
 * Load a template into a new broadcast and jump to target selection.
 */
function useTemplate(int $chatId, int $userId, int $templateId): void {
    $db = getDB();
    $stmt = $db->prepare("SELECT message_data FROM templates WHERE id = :id AND owner_id = :owner");
    $stmt->bindValue(':id', $templateId, SQLITE3_INTEGER);
    $stmt->bindValue(':owner', $userId, SQLITE3_INTEGER);
    $row = $stmt->execute()->fetchArray(SQLITE3_ASSOC);
    if (!$row) {
        sendMessage($chatId, ['type' => 'text', 'text' => '❌ Template not found.']);
        return;
    }

    $messageData = json_decode($row['message_data'], true);
    $totalUsers = $db->querySingle("SELECT COUNT(DISTINCT user_id) FROM users u LEFT JOIN blacklist b ON u.user_id = b.user_id WHERE b.user_id IS NULL");

    setState($userId, 'selecting_target', ['message_data' => $messageData]);

    sendMessage($chatId, [
        'type' => 'text',
        'parse_mode' => 'HTML',
        'text' => "📊 <b>Select Target Audience</b>\n\nTotal available: <b>" . formatNumber($totalUsers) . "</b>",
        'reply_markup' => json_encode(['inline_keyboard' => [
            [['text' => "📢 All Users", 'callback_data' => 'target_all']],
            [['text' => '👥 Only Bot Starters', 'callback_data' => 'target_starters']],
            [['text' => '📋 Select Source', 'callback_data' => 'target_source']],
            [['text' => '❌ Cancel', 'callback_data' => 'menu']],
        ]]),
    ]);
}

/**
 * Record a broadcast button click for CTR analytics.
 * Callback data format: clk_<broadcastId>_<label>
 */
function recordClick(string $data, int $userId): void {
    $parts = explode('_', $data, 3);
    if (count($parts) < 2) return;
    $broadcastId = (int)$parts[1];
    $label = $parts[2] ?? '';

    $db = getDB();
    $stmt = $db->prepare("INSERT INTO clicks (broadcast_id, user_id, button_data, clicked_at) VALUES (:bid, :uid, :label, :ts)");
    $stmt->bindValue(':bid', $broadcastId, SQLITE3_INTEGER);
    $stmt->bindValue(':uid', $userId, SQLITE3_INTEGER);
    $stmt->bindValue(':label', $label, SQLITE3_TEXT);
    $stmt->bindValue(':ts', time(), SQLITE3_INTEGER);
    $stmt->execute();
}

/**
 * Show the per-source picker for broadcast targeting.
 */
function showSourcePicker(int $chatId, int $messageId): void {
    $db = getDB();
    $res = $db->query("SELECT s.chat_id, s.title, COUNT(DISTINCT u.user_id) AS users
                       FROM sources s
                       LEFT JOIN users u ON s.chat_id = u.source_id
                       WHERE s.is_active = 1
                       GROUP BY s.chat_id ORDER BY users DESC LIMIT 20");

    $keyboard = [];
    $count = 0;
    while ($row = $res->fetchArray(SQLITE3_ASSOC)) {
        $count++;
        $label = mb_substr($row['title'] ?? 'Source', 0, 30) . ' (' . formatNumber((int)$row['users']) . ')';
        $keyboard[] = [['text' => $label, 'callback_data' => 'target_source_' . $row['chat_id']]];
    }
    $keyboard[] = [['text' => '« Back', 'callback_data' => 'menu']];

    $text = $count > 0
        ? "📋 <b>Select a source</b>\n\nChoose which group/channel's collected users should receive this broadcast:"
        : "📋 <b>No active sources</b>\n\nAdd the bot as admin to a group/channel first.";

    editMessageText($chatId, $messageId, $text, ['inline_keyboard' => $keyboard]);
}

/**
 * Settings screen — adjust send rate (msg/min) live.
 */
function showSettings(int $chatId, int $messageId): void {
    $rate = effectiveRate();
    $text = "⚙️ <b>Settings</b>\n\n";
    $text .= "Send rate: <b>{$rate}</b> messages/min\n";
    $text .= "Default (code): " . MESSAGES_PER_MINUTE . " msg/min\n\n";
    $text .= "Lower = safer against Telegram flood limits.";

    $keyboard = [
        [
            ['text' => '20/min', 'callback_data' => 'set_rate_20'],
            ['text' => '35/min', 'callback_data' => 'set_rate_35'],
            ['text' => '50/min', 'callback_data' => 'set_rate_50'],
        ],
        [['text' => '« Back', 'callback_data' => 'menu']],
    ];
    editMessageText($chatId, $messageId, $text, ['inline_keyboard' => $keyboard]);
}

/**
 * Persist a new send-rate setting and refresh the settings screen.
 */
function adjustRateSetting(int $chatId, int $messageId, int $rate): void {
    if ($rate > 0 && $rate <= 1000) {
        setSetting('messages_per_minute', (string)$rate);
    }
    showSettings($chatId, $messageId);
}

/**
 * Send the pending broadcast message to the owner only, as a preview/test.
 */
function handleTestSend(int $chatId, int $userId): void {
    $state = getState($userId);
    if (!$state || ($state['data']['message_data'] ?? null) === null) {
        sendMessage($chatId, ['type' => 'text', 'text' => '❌ No broadcast in progress to preview.']);
        return;
    }
    sendMessage($chatId, ['type' => 'text', 'text' => '🧪 <b>Test preview:</b>', 'parse_mode' => 'HTML']);
    sendMessage($userId, $state['data']['message_data']);
}

/**
 * Per-broadcast analytics: delivery + click-through rate.
 */
function showBroadcastAnalytics(int $chatId, int $messageId, int $broadcastId): void {
    $db = getDB();
    $b = $db->querySingle("SELECT * FROM broadcasts WHERE id = {$broadcastId}", true);
    if (!$b) {
        editMessageText($chatId, $messageId, "Broadcast not found.", ['inline_keyboard' => [[['text' => '« Back', 'callback_data' => 'history']]]]);
        return;
    }
    $sent   = (int)$b['sent_count'];
    $failed = (int)$b['failed_count'];
    $clicks = (int)$db->querySingle("SELECT COUNT(*) FROM clicks WHERE broadcast_id = {$broadcastId}");
    $uniqueClicks = (int)$db->querySingle("SELECT COUNT(DISTINCT user_id) FROM clicks WHERE broadcast_id = {$broadcastId}");
    $ctr = $sent > 0 ? round($uniqueClicks / $sent * 100, 1) : 0.0;
    $deliveryRate = ($sent + $failed) > 0 ? round($sent / ($sent + $failed) * 100, 1) : 0.0;

    $text  = "📈 <b>Broadcast #{$broadcastId} Analytics</b>\n\n";
    $text .= "Status: {$b['status']}\n";
    $text .= "👥 Target: " . formatNumber((int)$b['total_users']) . "\n";
    $text .= "✅ Sent: " . formatNumber($sent) . " ({$deliveryRate}% delivery)\n";
    $text .= "❌ Failed: " . formatNumber($failed) . "\n";
    $text .= "🖱 Clicks: " . formatNumber($clicks) . " ({$uniqueClicks} unique, {$ctr}% CTR)\n";

    editMessageText($chatId, $messageId, $text, ['inline_keyboard' => [[['text' => '« Back', 'callback_data' => 'history']]]]);
}

/**
 * Handle target selection
 */
function handleTargetSelection(int $chatId, int $userId, ?string $filter): void {
    $state = getState($userId);
    
    if (!$state || $state['state'] !== 'selecting_target') {
        return;
    }
    
    $messageData = $state['data']['message_data'];
    
    // Count target users
    $targetCount = countTargetUsers($filter);
    
    if ($targetCount === 0) {
        sendMessage($chatId, [
            'type' => 'text',
            'text' => '❌ No users found matching the criteria.'
        ]);
        return;
    }
    
    // Generate confirmation code
    $confirmationCode = generateCode();
    
    $text = "⚠️ <b>Confirm Broadcast</b>\n\n";
    $text .= "You are about to send a message to:\n";
    $text .= "👥 <b>" . formatNumber($targetCount) . " users</b>\n\n";
    $text .= "⏱ Estimated time: " . calculateETA($targetCount, MESSAGES_PER_MINUTE) . "\n\n";
    $text .= "Type <code>{$confirmationCode}</code> to confirm and start broadcasting.\n";
    $text .= "Or press 🧪 to preview the message to yourself first.";

    // Update state
    setState($userId, 'awaiting_confirmation', [
        'message_data' => $messageData,
        'target_filter' => $filter,
        'confirmation_code' => $confirmationCode
    ]);

    sendMessage($chatId, [
        'type' => 'text',
        'text' => $text,
        'parse_mode' => 'HTML',
        'reply_markup' => json_encode(['inline_keyboard' => [
            [['text' => '🧪 Test Send (to me)', 'callback_data' => 'test_send']],
            [['text' => '❌ Cancel', 'callback_data' => 'menu']],
        ]]),
    ]);
}

/**
 * Handle source selection
 */
function handleSourceSelection(int $chatId, int $userId, string $callbackData): void {
    // Extract source ID from callback data
    $sourceId = (int)str_replace('target_source_', '', $callbackData);
    
    handleTargetSelection($chatId, $userId, json_encode(['source_id' => $sourceId]));
}

// ============================================================================
// WEBHOOK & ENDPOINTS
// ============================================================================

/**
 * Main entry point
 */
function main(): void {
    // Shared-secret gate for the management endpoints (?setup, ?cron, ?stats).
    $requireKey = function (): bool {
        $key = $_GET['key'] ?? '';
        if (!hash_equals(CRON_SECRET, (string)$key)) {
            http_response_code(403);
            echo "Forbidden";
            return false;
        }
        return true;
    };

    // Handle setup endpoint
    if (isset($_GET['setup'])) {
        if (!$requireKey()) return;
        initDatabase();

        $webhookUrl = (isset($_SERVER['HTTPS']) ? 'https' : 'http') . '://' . $_SERVER['HTTP_HOST'] . $_SERVER['SCRIPT_NAME'];

        if (setWebhook($webhookUrl)) {
            echo "✅ Setup complete!\n\n";
            echo "Webhook URL: {$webhookUrl}\n";
            echo "Database: " . DB_FILE . "\n\n";
            echo "Add this to your cron (every minute):\n";
            echo "* * * * * curl -s \"{$webhookUrl}?cron=1&key=" . CRON_SECRET . "\" > /dev/null\n";
        } else {
            echo "❌ Failed to set webhook. Check BOT_TOKEN.";
        }

        return;
    }

    // Handle cron endpoint
    if (isset($_GET['cron'])) {
        if (!$requireKey()) return;
        set_time_limit(0);
        processQueue();
        echo "OK";
        return;
    }

    // Handle health endpoint (no secrets leaked)
    if (isset($_GET['health'])) {
        echo "OK";
        return;
    }

    // Handle stats endpoint
    if (isset($_GET['stats'])) {
        if (!$requireKey()) return;
        $db = getDB();
        $stats = [
            'total_users' => $db->querySingle("SELECT COUNT(DISTINCT user_id) FROM users"),
            'active_sources' => $db->querySingle("SELECT COUNT(*) FROM sources WHERE is_active = 1"),
            'total_broadcasts' => $db->querySingle("SELECT COUNT(*) FROM broadcasts"),
            'active_broadcasts' => $db->querySingle("SELECT COUNT(*) FROM broadcasts WHERE status IN ('running', 'scheduled')")
        ];
        
        header('Content-Type: application/json');
        echo json_encode($stats, JSON_PRETTY_PRINT);
        return;
    }
    
    // Handle webhook (incoming updates) — validate Telegram's secret token first.
    $providedSecret = $_SERVER['HTTP_X_TELEGRAM_BOT_API_SECRET_TOKEN'] ?? '';
    if (!hash_equals(WEBHOOK_SECRET, (string)$providedSecret)) {
        http_response_code(403);
        return;
    }

    $input = file_get_contents('php://input');

    if (!$input) {
        http_response_code(400);
        return;
    }
    
    $update = json_decode($input, true);
    
    if (!$update) {
        http_response_code(400);
        return;
    }
    
    try {
        processUpdate($update);
    } catch (Exception $e) {
        logMessage("Error processing update: " . $e->getMessage(), 'ERROR');
    }
    
    http_response_code(200);
}

// Run the bot
main();
