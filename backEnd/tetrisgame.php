<?php
if (session_status() === PHP_SESSION_NONE) {
    require_once __DIR__ . '/session_bootstrap.php';
}
if (empty($_SESSION['csrf_token'])) {
    $_SESSION['csrf_token'] = bin2hex(random_bytes(16));
}
include '../dbConnect/dbconnect.php';
class tetrisgame {
    private $db;

    public function __construct() {
        $dbcon = new dbcon();
        $this->db = $dbcon->dbconnect();
        if ($this->db === null) {
            error_log('[tetrisgame] Database connection failed. ' . ($dbcon->lastDebugError ?: $dbcon->lastError));
            die('Service temporarily unavailable. Please try again later.');
        }
    }

    // Function to register a new user in the database with email, username, and password.
    public function RegisterUser($email, $username, $password, $repassword) {
        $email = trim((string)$email);
        $username = trim((string)$username);
        $old = ['username' => $username, 'email' => $email];

        if (!filter_var($email, FILTER_VALIDATE_EMAIL)) {
            $_SESSION['old_register'] = $old;
            $_SESSION['msg'] = "Enter a valid email address.";
            header("Location: ../dashboard/register.php");
            exit();
        }

        if (strlen($username) < 3) {
            $_SESSION['old_register'] = $old;
            $_SESSION['msg'] = "Commander name needs at least 3 characters.";
            header("Location: ../dashboard/register.php");
            exit();
        }

        if (strlen((string)$password) < 6) {
            $_SESSION['old_register'] = $old;
            $_SESSION['msg'] = "Password needs at least 6 characters.";
            header("Location: ../dashboard/register.php");
            exit();
        }

        // Check if email already exists
        $stmt = $this->db->prepare('SELECT email FROM "TetrisGame" WHERE email = ?');
        $stmt->execute([$email]);
        
        if($stmt->rowCount() > 0) {
            $_SESSION['old_register'] = $old;
            $_SESSION['msg'] = "Email already exists.";
            header("Location: ../dashboard/register.php");
            exit();
        }

        // Check if username already exists
        $stmt = $this->db->prepare('SELECT username FROM "TetrisGame" WHERE username = ?');
        $stmt->execute([$username]);
        
        if($stmt->rowCount() > 0) {
            $_SESSION['old_register'] = $old;
            $_SESSION['msg'] = "Username already exists.";
            header("Location: ../dashboard/register.php");
            exit();
        }

        // Check if passwords match and then hash the password before storing it in the database
        if($password === $repassword) {
            $hashedPassword = password_hash($password, PASSWORD_DEFAULT);
            $stmt = $this->db->prepare('INSERT INTO "TetrisGame" (email, username, password) VALUES (?, ?, ?)');
            $stmt->execute([$email, $username, $hashedPassword]);
            
            unset($_SESSION['old_register']);
            $_SESSION['msg'] = "Registration successful.";
            header("Location: ../dashboard/login.php");
            exit();
        } else {
            $_SESSION['old_register'] = $old;
            $_SESSION['msg'] = "Passwords do not match.";
            header("Location: ../dashboard/register.php");
            exit();
        }
    }

    // Function to authenticate a user by checking the email and password against the database records.
    public function LoginUser($email, $password) {
        // Check if email exists in the database
        $stmt = $this->db->prepare('SELECT * FROM "TetrisGame" WHERE email = ?');
        $stmt->execute([$email]);

        $user = $stmt->fetch(PDO::FETCH_ASSOC);

        // If email exists, verify the password using password_verify() function
        if($user) {
            // Verify the password
            if(password_verify($password, $user['password'])) {
                // Deny access for blocked accounts
                if (!empty($user['is_blocked'])) {
                    $_SESSION['old_login_email'] = $email;
                    $_SESSION['msg'] = "Your account has been suspended. Contact an administrator.";
                    header("Location: ../dashboard/login.php");
                    exit();
                }
                // Record last login time (column added by admin_setup.sql)
                try {
                    $upd = $this->db->prepare('UPDATE "TetrisGame" SET last_login = NOW() WHERE id = ?');
                    $upd->execute([$user['id']]);
                } catch (Exception $e) { /* column may not exist yet — silently skip */ }

                session_regenerate_id(true);
                unset($_SESSION['old_login_email']);
                $_SESSION['user_id'] = $user['id'];
                $_SESSION['username'] = $user['username'];
                $_SESSION['is_logged_in'] = true;
                $_SESSION['score'] = $user['score'] ?? 0; // Default to 0 if NULL
                header("Location: ../dashboard/dashboard.php");
                exit();
            } else {
                $_SESSION['old_login_email'] = $email;
                $_SESSION['msg'] = "Invalid email or password.";
                header("Location: ../dashboard/login.php");
                exit();
            }
        } else {
            $_SESSION['old_login_email'] = $email;
            $_SESSION['msg'] = "Invalid email or password.";
            header("Location: ../dashboard/login.php");
            exit();
        }
    }

    // Function to update the user's score in the database if the new score is higher than the existing score.
    public function UpdateScore($username, $score) {
        // Update the user's score in the database if the new score is higher than the existing score
        $stmt = $this->db->prepare('SELECT score FROM "TetrisGame" WHERE username = ?');
        $stmt->execute([$username]);
        $currentScore = $stmt->fetchColumn();

        // If no current score exists (NULL), treat it as 0
        $currentScore = $currentScore ?? 0;

        // Only update if the new score is higher than the current score
        if ($score > $currentScore) {
            $stmt = $this->db->prepare('UPDATE "TetrisGame" SET score = ? WHERE username = ?');
            $stmt->execute([$score, $username]);
            $_SESSION['score'] = $score; // Update the session score with the new high score
            return $score;
        }

        // Keep the existing score if the new score is not higher
        return $currentScore;
    }

    // Function to retrieve the user's current score from the database.
    public function getScore($username) {
        // Retrieve the user's score from the database
        $stmt = $this->db->prepare('SELECT score FROM "TetrisGame" WHERE username = ?');
        $stmt->execute([$username]);
        return $stmt->fetchColumn() ?? 0; // Return 0 if no score is found
    }


    // Function to retrieve the top 10 scores from the database for the leaderboard
    public function getLeaderboard() {
        // Retrieve the top 10 scores from the database for the leaderboard 
        // descending order to show the highest scores at the top limit to 10 results
        $stmt = $this->db->query('SELECT username, score FROM "TetrisGame" ORDER BY score DESC LIMIT 10');
        return $stmt->fetchAll(PDO::FETCH_ASSOC);
    }

    // Function to get the user's ranking based on their score compared to other players in the database.
    public function getUserRanking($username) {
        // Get the user's current score
        $stmt = $this->db->prepare('SELECT score FROM "TetrisGame" WHERE username = ?');
        $stmt->execute([$username]);
        $userScore = $stmt->fetchColumn();

        if ($userScore === false) {
            return null; // User not found
        }

        // Players who have never saved a score have score = NULL. Comparing
        // `score > NULL` in SQL yields unknown, so the count would be 0 and
        // the user would be reported as rank 1. Normalize NULL to 0 so a
        // user with no score is correctly ranked below anyone with a score.
        $userScore = $userScore ?? 0;

        // Get the user's ranking based on their score
        // Count how many users have a higher score than the current user and add 1 to get the rank
        $stmt = $this->db->prepare('SELECT COUNT(*) + 1 AS rank FROM "TetrisGame" WHERE score > ?');
        $stmt->execute([$userScore]);
        return $stmt->fetchColumn();
    }

}
