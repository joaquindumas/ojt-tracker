<?php
define('APP_NAME', 'Practica');
define('APP_VERSION', 'v0.5.0');

// Default values
define('DEFAULT_REQUIRED_HOURS', 500);

// Use PH local time for greetings, logs, and date displays across all pages.
date_default_timezone_set('Asia/Manila');

// ── Database config ───────────────────────────────────────────
$dbUrl = getenv('DATABASE_URL');
if ($dbUrl) {
    $parsedUrl = parse_url($dbUrl);
    define('DB_HOST', $parsedUrl['host'] ?? 'localhost');
    define('DB_PORT', $parsedUrl['port'] ?? '5432');
    define('DB_NAME', ltrim($parsedUrl['path'] ?? '/postgres', '/'));
    define('DB_USER', $parsedUrl['user'] ?? 'postgres');
    define('DB_PASS', $parsedUrl['pass'] ?? '');
} else {
    define('DB_HOST', getenv('DB_HOST') ?: 'localhost');
    define('DB_PORT', getenv('DB_PORT') ?: '5432');
    define('DB_NAME', getenv('DB_NAME') ?: 'postgres');
    define('DB_USER', getenv('DB_USER') ?: 'postgres');
    define('DB_PASS', getenv('DB_PASS') ?: '');
}

if (session_status() === PHP_SESSION_NONE) {
    session_start();
}

// ── Database connection ───────────────────────────────────────
function db(): PDO {
    static $pdo = null;
    if ($pdo === null) {
        try {
            $dsn = 'pgsql:host=' . DB_HOST . ';port=' . DB_PORT . ';dbname=' . DB_NAME;
            $pdo = new PDO(
                $dsn,
                DB_USER,
                DB_PASS,
                [
                    PDO::ATTR_ERRMODE            => PDO::ERRMODE_EXCEPTION,
                    PDO::ATTR_DEFAULT_FETCH_MODE => PDO::FETCH_ASSOC,
                ]
            );
            ensure_users_schema($pdo);
        } catch (PDOException $e) {
            die('Database connection failed: ' . $e->getMessage());
        }
    }
    return $pdo;
}

function ensure_users_schema(PDO $pdo): void {
    static $checked = false;
    if ($checked) return;
    $checked = true;

    // Only attempt migrations when users table already exists.
    $tableStmt = $pdo->prepare("SELECT 1 FROM information_schema.tables WHERE table_schema = 'public' AND table_name = ?");
    $tableStmt->execute(['users']);
    if (!$tableStmt->fetch()) return;

    $requiredColumns = [
        'required_hours' => 'ALTER TABLE users ADD COLUMN required_hours DECIMAL(10,2) NOT NULL DEFAULT ' . DEFAULT_REQUIRED_HOURS,
        'allowance_per_day' => 'ALTER TABLE users ADD COLUMN allowance_per_day DECIMAL(10,2) NOT NULL DEFAULT 0',
        'currency' => "ALTER TABLE users ADD COLUMN currency VARCHAR(3) NOT NULL DEFAULT 'PHP'",
        'security_question' => 'ALTER TABLE users ADD COLUMN security_question VARCHAR(255) NULL',
        'security_answer' => 'ALTER TABLE users ADD COLUMN security_answer VARCHAR(255) NULL',
        'email' => 'ALTER TABLE users ADD COLUMN email VARCHAR(255) NULL',
    ];

    foreach ($requiredColumns as $column => $sql) {
        $colStmt = $pdo->prepare("SELECT 1 FROM information_schema.columns WHERE table_schema = 'public' AND table_name = 'users' AND column_name = ?");
        $colStmt->execute([$column]);
        if (!$colStmt->fetch()) {
            $pdo->exec($sql);
        }
    }
}

// ── User helpers ──────────────────────────────────────────────
function get_user(string $username): ?array {
    $stmt = db()->prepare('SELECT * FROM users WHERE username = ?');
    $stmt->execute([$username]);
    $user = $stmt->fetch();
    if (!$user) return null;

    // Attach logs
    $user['logs']           = get_logs($user['id']);
    $user['required_hours'] = (float) $user['required_hours'];
    $user['allowance_per_day'] = (float) ($user['allowance_per_day'] ?? 0);
    $user['currency']       = $user['currency'] ?? 'PHP';
    return $user;
}

function save_user(array $user): void {
    $db = db();

    if (isset($user['id']) && $user['id']) {
        $stmt = $db->prepare('
            UPDATE users SET
                name              = ?,
                password          = ?,
                required_hours    = ?,
                allowance_per_day = ?,
                currency          = ?,
                security_question = ?,
                security_answer   = ?,
                email             = ?
            WHERE id = ?
        ');
        $stmt->execute([
            $user['name'],
            $user['password'],
            $user['required_hours'] ?? DEFAULT_REQUIRED_HOURS,
            $user['allowance_per_day'] ?? 0,
            $user['currency'] ?? 'PHP',
            $user['security_question'] ?? null,
            $user['security_answer']   ?? null,
            $user['email']             ?? null,
            $user['id'],
        ]);
    } else {
        $stmt = $db->prepare('
            INSERT INTO users (name, username, password, required_hours, allowance_per_day, currency, security_question, security_answer, email)
            VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?)
        ');
        $stmt->execute([
            $user['name'],
            $user['username'],
            $user['password'],
            $user['required_hours']    ?? DEFAULT_REQUIRED_HOURS,
            $user['allowance_per_day'] ?? 0,
            $user['currency']          ?? 'PHP',
            $user['security_question'] ?? null,
            $user['security_answer']   ?? null,
            $user['email']             ?? null,
        ]);
    }
}

// ── Currency helpers ───────────────────────────────────────────
function get_currency_symbol(string $currency): string {
    return match ($currency) {
        'USD' => '$',
        'EUR' => '€',
        'GBP' => '£',
        'PHP' => '₱',
        default => '₱',
    };
}

// ── Log helpers ───────────────────────────────────────────────
function get_logs(int $user_id): array {
    $stmt = db()->prepare('SELECT * FROM time_logs WHERE user_id = ? ORDER BY date DESC');
    $stmt->execute([$user_id]);
    $rows = $stmt->fetchAll();
    // Normalize to match old format
    return array_map(fn($r) => [
        'id'          => $r['id'],
        'date'        => $r['date'],
        'description' => $r['description'] ?? '',
        'from'        => $r['time_from'],
        'to'          => $r['time_to'],
        'hours'       => (float) $r['hours'],
        'created_at'  => $r['created_at'],
    ], $rows);
}

function add_log(int $user_id, array $log): void {
    $stmt = db()->prepare('
        INSERT INTO time_logs (id, user_id, date, description, time_from, time_to, hours)
        VALUES (?, ?, ?, ?, ?, ?, ?)
    ');
    $stmt->execute([
        $log['id'],
        $user_id,
        $log['date'],
        $log['description'] ?? null,
        $log['from'],
        $log['to'],
        $log['hours'],
    ]);
}

function update_log(string $log_id, int $user_id, array $log): void {
    $stmt = db()->prepare('
        UPDATE time_logs SET
            date        = ?,
            description = ?,
            time_from   = ?,
            time_to     = ?,
            hours       = ?
        WHERE id = ? AND user_id = ?
    ');
    $stmt->execute([
        $log['date'],
        $log['description'] ?? null,
        $log['from'],
        $log['to'],
        $log['hours'],
        $log_id,
        $user_id,
    ]);
}

function delete_log(string $log_id, int $user_id): void {
    $stmt = db()->prepare('DELETE FROM time_logs WHERE id = ? AND user_id = ?');
    $stmt->execute([$log_id, $user_id]);
}

// ── Auth helpers ──────────────────────────────────────────────
function is_logged_in(): bool {
    return isset($_SESSION['username']);
}

function current_user(): ?array {
    if (!is_logged_in()) return null;
    return get_user($_SESSION['username']);
}

function require_login(): void {
    if (!is_logged_in()) { header('Location: auth.php'); exit; }
}

function require_guest(): void {
    if (is_logged_in()) { header('Location: dashboard.php'); exit; }
}

function hash_password(string $password): string {
    return password_hash($password, PASSWORD_DEFAULT);
}

function verify_password(string $password, string $hash): bool {
    return password_verify($password, $hash);
}

function set_flash(string $type, string $message): void {
    $_SESSION['flash'] = ['type' => $type, 'message' => $message];
}

function get_flash(): ?array {
    if (!isset($_SESSION['flash'])) return null;
    $flash = $_SESSION['flash'];
    unset($_SESSION['flash']);
    return $flash;
}

// ── Hours helpers ─────────────────────────────────────────────
function total_logged(array $user): float {
    return array_sum(array_column($user['logs'] ?? [], 'hours'));
}

function hours_remaining(array $user): float {
    return max(0, ($user['required_hours'] ?? DEFAULT_REQUIRED_HOURS) - total_logged($user));
}

function completion_percent(array $user): float {
    $req = $user['required_hours'] ?? DEFAULT_REQUIRED_HOURS;
    if ($req <= 0) return 100;
    return min(100, (total_logged($user) / $req) * 100);
}

function estimated_completion(array $user): ?string {
    $logs = $user['logs'] ?? [];
    if (count($logs) < 1) return null;
    $unique_days = count(array_unique(array_column($logs, 'date')));
    if ($unique_days < 1) return null;
    $avg = total_logged($user) / $unique_days;
    if ($avg <= 0) return null;
    $remaining = hours_remaining($user);
    if ($remaining <= 0) return 'Completed';
    $working_days_needed = (int) ceil($remaining / $avg);
    $current = strtotime('today');
    $days_counted = 0;
    while ($days_counted < $working_days_needed) {
        $current = strtotime('+1 day', $current);
        if ((int) date('N', $current) < 6) $days_counted++;
    }
    return date('F j, Y', $current);
}

function estimated_basis(array $user): ?string {
    $logs = $user['logs'] ?? [];
    if (count($logs) < 1) return null;
    $unique_days = count(array_unique(array_column($logs, 'date')));
    if ($unique_days < 1) return null;
    $avg = round(total_logged($user) / $unique_days, 1);
    return "Based on avg {$avg} hrs/day over {$unique_days} day" . ($unique_days > 1 ? 's' : '');
}

function get_security_question(string $username): ?string {
    $user = get_user($username);
    return $user['security_question'] ?? null;
}

function verify_security_answer(string $username, string $answer): bool {
    $user = get_user($username);
    if (!$user || !isset($user['security_answer'])) return false;

    $normalizedAnswer = strtolower(trim($answer));
    $stored = (string) $user['security_answer'];

    // Support both legacy plain-text answers and current hashed answers.
    if (str_starts_with($stored, '$2y$') || str_starts_with($stored, '$argon2')) {
        return verify_password($normalizedAnswer, $stored);
    }

    return hash_equals(strtolower(trim($stored)), $normalizedAnswer);
}

function e(string $str): string {
    return htmlspecialchars($str, ENT_QUOTES, 'UTF-8');
}

function generate_id(): string {
    return uniqid('', true);
}

function bulk_add_logs(int $user_id, array $logs): int {
    $count = 0;
    foreach ($logs as $log) {
        if (empty($log['date']) || empty($log['from']) || empty($log['to'])) continue;
        [$fh, $fm] = array_map('intval', explode(':', $log['from']));
        [$th, $tm] = array_map('intval', explode(':', $log['to']));
        $hours = (($th * 60 + $tm) - ($fh * 60 + $fm)) / 60;
        if ($hours <= 0) continue;

        // Skip if already logged for this date
        $stmt = db()->prepare('SELECT id FROM time_logs WHERE user_id = ? AND date = ?');
        $stmt->execute([$user_id, $log['date']]);
        if ($stmt->fetch()) continue;

        add_log($user_id, [
            'id'          => generate_id(),
            'date'        => $log['date'],
            'description' => $log['description'] ?? '',
            'from'        => $log['from'],
            'to'          => $log['to'],
            'hours'       => round($hours, 4),
            'created_at'  => date('Y-m-d H:i:s'),
        ]);
        $count++;
    }
    return $count;
}

// ── Note Tags ─────────────────────────────────────────────────
function ensure_note_tags_table(): void {
    db()->exec("CREATE TABLE IF NOT EXISTS note_tags (
        id SERIAL PRIMARY KEY,
        user_id INT NOT NULL,
        name VARCHAR(50) NOT NULL,
        created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
        CONSTRAINT unique_user_tag UNIQUE (user_id, name),
        CONSTRAINT fk_note_tags_user FOREIGN KEY (user_id) REFERENCES users(id) ON DELETE CASCADE
    )");
}

function get_user_tags(int $user_id): array {
    ensure_note_tags_table();
    // One-time reset: clear old tags and seed new defaults
    $stmt = db()->prepare('SELECT COUNT(*) FROM note_tags WHERE user_id = ? AND name IN (?, ?)');
    $stmt->execute([$user_id, 'Personal', 'Work']);
    $has_new = (int) $stmt->fetchColumn();
    if ($has_new === 0) {
        db()->prepare('DELETE FROM note_tags WHERE user_id = ?')->execute([$user_id]);
        $defaults = ['Personal', 'Work'];
        foreach ($defaults as $t) { add_user_tag($user_id, $t); }
        return $defaults;
    }
    $stmt = db()->prepare('SELECT name FROM note_tags WHERE user_id = ? ORDER BY created_at');
    $stmt->execute([$user_id]);
    $tags = $stmt->fetchAll(PDO::FETCH_COLUMN);
    return $tags ?: ['Personal', 'Work'];
}

function add_user_tag(int $user_id, string $name): bool {
    ensure_note_tags_table();
    try {
        $stmt = db()->prepare('INSERT INTO note_tags (user_id, name) VALUES (?, ?)');
        $stmt->execute([$user_id, $name]);
        return true;
    } catch (PDOException $e) { return false; }
}

function delete_user_tag(int $user_id, string $name): void {
    ensure_note_tags_table();
    $stmt = db()->prepare('DELETE FROM note_tags WHERE user_id = ? AND name = ?');
    $stmt->execute([$user_id, $name]);
}

function get_notes(int $user_id): array {
    $stmt = db()->prepare('SELECT * FROM notes WHERE user_id = ? ORDER BY date DESC, created_at DESC');
    $stmt->execute([$user_id]);
    return $stmt->fetchAll();
}
 
function add_note(int $user_id, array $note): void {
    $stmt = db()->prepare('
        INSERT INTO notes (id, user_id, title, body, tag, date)
        VALUES (?, ?, ?, ?, ?, ?)
    ');
    $stmt->execute([
        $note['id'],
        $user_id,
        $note['title'],
        $note['body'],
        $note['tag'] ?? 'General',
        $note['date'],
    ]);
}
 
function update_note(string $note_id, int $user_id, array $note): void {
    $stmt = db()->prepare('
        UPDATE notes SET
            title = ?,
            body  = ?,
            tag   = ?,
            date  = ?
        WHERE id = ? AND user_id = ?
    ');
    $stmt->execute([
        $note['title'],
        $note['body'],
        $note['tag'] ?? 'General',
        $note['date'],
        $note_id,
        $user_id,
    ]);
}
 
function delete_note(string $note_id, int $user_id): void {
    $stmt = db()->prepare('DELETE FROM notes WHERE id = ? AND user_id = ?');
    $stmt->execute([$note_id, $user_id]);
}