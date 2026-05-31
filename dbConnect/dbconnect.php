<?php
class dbcon{
    private $host = "aws-1-ap-southeast-2.pooler.supabase.com";
    private $port = "6543"; // Supabase transaction pooler
    private $dbname = "postgres";
    private $user = "postgres.ealbozosgfjcqqmxjuzw";
    private $password = "@TeTris123_04";
    public $con;
    public $lastError = '';

    public function dbconnect(){
        if (!extension_loaded('pdo_pgsql')) {
            $this->lastError = 'PHP PostgreSQL PDO driver is not enabled. Enable extension=pdo_pgsql and extension=pgsql in C:\\xampp\\php\\php.ini, then restart Apache.';
            error_log('[dbconnect] ' . $this->lastError);
            return null;
        }

        try{
            // connect_timeout=5 keeps a paused / unreachable Supabase from
            // hanging requests for the default ~60s before reporting failure.
            $dsn = "pgsql:host={$this->host};port={$this->port};dbname={$this->dbname};connect_timeout=5";
            $this->con = new PDO($dsn, $this->user, $this->password, [
            
                PDO::ATTR_ERRMODE            => PDO::ERRMODE_EXCEPTION,
                PDO::ATTR_EMULATE_PREPARES   => true,
                PDO::ATTR_DEFAULT_FETCH_MODE => PDO::FETCH_ASSOC,
            ]);
            $this->lastError = '';
            return $this->con;
        }catch(PDOException $e){
            $this->lastError = $this->friendlyError($e->getMessage());
            error_log('[dbconnect] ' . $e->getMessage());
            return null;
        }
    }

    private function friendlyError($message){
        $normalized = strtolower((string)$message);

        if (strpos($normalized, 'could not find driver') !== false) {
            return 'PHP PostgreSQL PDO driver is not enabled. Enable extension=pdo_pgsql and extension=pgsql in C:\\xampp\\php\\php.ini, then restart Apache.';
        }

        if (strpos($normalized, 'password authentication failed') !== false || strpos($normalized, 'authentication failed') !== false) {
            return 'Supabase rejected the database username or password. Check dbConnect\\dbconnect.php against the Supabase connection settings.';
        }

        foreach (['connection refused', 'connection timed out', 'could not connect to server', 'no route to host'] as $needle) {
            if (strpos($normalized, $needle) !== false) {
                return 'Could not reach Supabase. Check the internet connection, firewall, host, and port in dbConnect\\dbconnect.php.';
            }
        }

        // TCP succeeds but the Postgres handshake never completes — the
        // pooler is up but the database backend is not answering. By far
        // the most common cause is a Supabase project that was auto-paused
        // for inactivity on the free tier.
        if (strpos($normalized, 'timeout expired') !== false) {
            return 'Database did not respond. The Supabase project is likely paused — open https://supabase.com/dashboard and click "Restore project", then try again.';
        }

        if (strpos($normalized, 'unknown host') !== false || strpos($normalized, 'could not translate host name') !== false) {
            return 'DNS could not resolve the Supabase host. Check the internet connection, and verify the host name in dbConnect\\dbconnect.php.';
        }

        return 'Unable to connect to the PostgreSQL database. Check dbConnect\\dbconnect.php and C:\\xampp\\apache\\logs\\error.log for details.';
    }
}
