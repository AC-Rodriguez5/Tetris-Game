<?php
if (session_status() === PHP_SESSION_NONE) {
    session_start();
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
            $message = "Database connection failed.";
            if (!empty($dbcon->lastError)) {
                $message .= " " . $dbcon->lastError;
            }
            die(htmlspecialchars($message, ENT_QUOTES, 'UTF-8'));
        }
    }

    // Function to register a new user in the database with email, username, and password.
    public function RegisterUser($email, $username, $password, $repassword) {
        // Check if email already exists
        $stmt = $this->db->prepare('SELECT email FROM "TetrisGame" WHERE email = ?');
        $stmt->execute([$email]);
        
        if($stmt->rowCount() > 0) {
            $_SESSION['msg'] = "Email already exists.";
            header("Location: ../dashboard/register.php");
            exit();
        }

        // Check if username already exists
        $stmt = $this->db->prepare('SELECT username FROM "TetrisGame" WHERE username = ?');
        $stmt->execute([$username]);
        
        if($stmt->rowCount() > 0) {
            $_SESSION['msg'] = "Username already exists.";
            header("Location: ../dashboard/register.php");
            exit();
        }

        // Check if passwords match and then hash the password before storing it in the database
        if($password === $repassword) {
            $hashedPassword = password_hash($password, PASSWORD_DEFAULT);
            $stmt = $this->db->prepare('INSERT INTO "TetrisGame" (email, username, password) VALUES (?, ?, ?)');
            $stmt->execute([$email, $username, $hashedPassword]);
            
            $_SESSION['msg'] = "Registration successful.";
            header("Location: ../dashboard/login.php");
            exit();
        } else {
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
        
        // If email exists, verify the password using password_verify() function
        if($stmt->rowCount() > 0) {
            $user = $stmt->fetch(PDO::FETCH_ASSOC);
            // Verify the password
            if(password_verify($password, $user['password'])) {
                $_SESSION['user_id'] = $user['id'];
                $_SESSION['username'] = $user['username'];
                $_SESSION['is_logged_in'] = true;
                $_SESSION['score'] = $user['score'] ?? 0; // Default to 0 if NULL
                header("Location: ../dashboard/dashboard.php");
                exit();
            } else {
                $_SESSION['msg'] = "Incorrect password.";
                header("Location: ../dashboard/login.php");
                exit();
            }
        } else {
            $_SESSION['msg'] = "Email not found.";
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

        // Get the user's ranking based on their score
        // Count how many users have a higher score than the current user and add 1 to get the rank
        $stmt = $this->db->prepare('SELECT COUNT(*) + 1 AS rank FROM "TetrisGame" WHERE score > ?');
        $stmt->execute([$userScore]);
        return $stmt->fetchColumn();
    }

}
