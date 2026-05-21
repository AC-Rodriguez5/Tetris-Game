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
            $dsn = "pgsql:host={$this->host};port={$this->port};dbname={$this->dbname}";
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

        return 'Unable to connect to the PostgreSQL database. Check dbConnect\\dbconnect.php and C:\\xampp\\apache\\logs\\error.log for details.';
    }
}
