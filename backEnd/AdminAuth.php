<?php
require_once __DIR__ . '/admin_session.php';
include_once __DIR__ . '/../dbConnect/dbconnect.php';

// Change this key to something secret before deploying
define('ADMIN_REGISTER_KEY', 'CosmicAdmin20');

class AdminAuth {
    private $db;

    public function __construct() {
        $dbcon = new dbcon();
        $this->db = $dbcon->dbconnect();
        if ($this->db === null) {
            error_log('[AdminAuth] Database connection failed.');
            die('Service temporarily unavailable. Please try again later.');
        }
    }

    public function registerAdmin(string $username, string $email, string $password, string $repassword, string $secretKey): array {
        if ($secretKey !== ADMIN_REGISTER_KEY) {
            return ['success' => false, 'message' => 'Invalid access key.'];
        }
        $username = trim($username);
        $email    = trim($email);

        if (strlen($username) < 3) {
            return ['success' => false, 'message' => 'Username must be at least 3 characters.'];
        }
        if (!filter_var($email, FILTER_VALIDATE_EMAIL)) {
            return ['success' => false, 'message' => 'Enter a valid email address.'];
        }
        if (strlen($password) < 6) {
            return ['success' => false, 'message' => 'Password must be at least 6 characters.'];
        }
        if ($password !== $repassword) {
            return ['success' => false, 'message' => 'Passwords do not match.'];
        }

        $stmt = $this->db->prepare('SELECT id FROM "AdminUsers" WHERE email = ? OR username = ? LIMIT 1');
        $stmt->execute([$email, $username]);
        if ($stmt->fetch()) {
            return ['success' => false, 'message' => 'Username or email already taken.'];
        }

        $hash = password_hash($password, PASSWORD_DEFAULT);
        $stmt = $this->db->prepare('INSERT INTO "AdminUsers" (username, email, password) VALUES (?, ?, ?)');
        $stmt->execute([$username, $email, $hash]);
        return ['success' => true, 'message' => 'Admin account created successfully.'];
    }

    public function loginAdmin(string $email, string $password): array {
        $email = trim($email);
        $stmt  = $this->db->prepare('SELECT * FROM "AdminUsers" WHERE email = ? LIMIT 1');
        $stmt->execute([$email]);
        $admin = $stmt->fetch(PDO::FETCH_ASSOC);

        if (!$admin || !password_verify($password, $admin['password'])) {
            return ['success' => false, 'message' => 'Invalid credentials.'];
        }
        return ['success' => true, 'admin' => $admin];
    }

    // ── Stats ─────────────────────────────────────────────────────────────────

    public function getTotalPlayers(): int {
        try {
            return (int)$this->db->query('SELECT COUNT(*) FROM "TetrisGame"')->fetchColumn();
        } catch (Exception $e) {
            return 0;
        }
    }

    public function getActivePlayersCount(): int {
        try {
            $stmt = $this->db->query(
                "SELECT COUNT(*) FROM \"TetrisGame\" WHERE last_login > NOW() - INTERVAL '24 hours'"
            );
            return (int)$stmt->fetchColumn();
        } catch (Exception $e) {
            return 0;
        }
    }

    public function getBlockedCount(): int {
        try {
            return (int)$this->db->query('SELECT COUNT(*) FROM "TetrisGame" WHERE is_blocked = TRUE')->fetchColumn();
        } catch (Exception $e) {
            return 0;
        }
    }

    public function getTotalBlitzGames(): int {
        try {
            $stmt = $this->db->query("SELECT COUNT(*) FROM blitz_rooms WHERE status = 'finished'");
            return (int)$stmt->fetchColumn();
        } catch (Exception $e) {
            return 0;
        }
    }

    // ── Player management ────────────────────────────────────────────────────

    public function getAllPlayers(string $search = '', int $page = 1, int $perPage = 25): array {
        // LIMIT/OFFSET are derived from server-controlled integers, so we inline
        // them as ints. With PDO::ATTR_EMULATE_PREPARES enabled, bound params are
        // sent to PostgreSQL as quoted strings (e.g. LIMIT '20'), which some
        // configurations reject or mis-handle — inlining the cast ints avoids that
        // and guarantees every player row is returned.
        $perPage = max(1, (int)$perPage);
        $page    = max(1, (int)$page);
        $offset  = ($page - 1) * $perPage;

        $columns = 'id, username, email, score, is_blocked, last_login';

        if ($search !== '') {
            $like = '%' . $search . '%';
            $stmt = $this->db->prepare(
                "SELECT $columns
                 FROM \"TetrisGame\"
                 WHERE username ILIKE ? OR email ILIKE ?
                 ORDER BY id ASC LIMIT $perPage OFFSET $offset"
            );
            $stmt->execute([$like, $like]);

            $cStmt = $this->db->prepare(
                'SELECT COUNT(*) FROM "TetrisGame" WHERE username ILIKE ? OR email ILIKE ?'
            );
            $cStmt->execute([$like, $like]);
        } else {
            $stmt = $this->db->query(
                "SELECT $columns FROM \"TetrisGame\" ORDER BY id ASC LIMIT $perPage OFFSET $offset"
            );
            $cStmt = $this->db->query('SELECT COUNT(*) FROM "TetrisGame"');
        }

        return [
            'players' => $stmt->fetchAll(PDO::FETCH_ASSOC),
            'total'   => (int)$cStmt->fetchColumn(),
        ];
    }

    public function blockPlayer(string $username): bool {
        $stmt = $this->db->prepare('UPDATE "TetrisGame" SET is_blocked = TRUE WHERE username = ?');
        return $stmt->execute([$username]);
    }

    public function unblockPlayer(string $username): bool {
        $stmt = $this->db->prepare('UPDATE "TetrisGame" SET is_blocked = FALSE WHERE username = ?');
        return $stmt->execute([$username]);
    }

    // ── Leaderboards ─────────────────────────────────────────────────────────

    public function getSoloLeaderboard(int $limit = 10): array {
        try {
            $limit = max(1, (int)$limit);
            $stmt = $this->db->query(
                "SELECT username, score FROM \"TetrisGame\"
                 WHERE score IS NOT NULL ORDER BY score DESC LIMIT $limit"
            );
            return $stmt->fetchAll(PDO::FETCH_ASSOC);
        } catch (Exception $e) {
            return [];
        }
    }

    public function getBlitzLeaderboard(int $limit = 10): array {
        try {
            $limit = max(1, (int)$limit);
            $stmt = $this->db->query(
                "SELECT username, wins, losses, best_score, total_games
                 FROM blitz_leaderboard ORDER BY wins DESC, best_score DESC LIMIT $limit"
            );
            return $stmt->fetchAll(PDO::FETCH_ASSOC);
        } catch (Exception $e) {
            return [];
        }
    }
}
