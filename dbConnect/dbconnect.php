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
            $this->lastError = $e->getMessage();
            error_log('[dbconnect] ' . $e->getMessage());
            return null;
        }
    }
}
