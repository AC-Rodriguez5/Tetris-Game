<?php
class dbcon{
    private $host = "aws-1-ap-southeast-2.pooler.supabase.com";
    private $port = "6543";
    private $dbname = "postgres";
    private $user = "postgres.ealbozosgfjcqqmxjuzw";
    private $password = "TetrisProject1016"; // Replace with your actual password
    public $con;

    public function dbconnect(){
        try{
            $this->con = new PDO ('pgsql:host='.$this->host.';port='.$this->port.';dbname='.$this->dbname, $this->user,
             $this->password,[PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION]);
            return $this->con;
        }catch(PDOException $e){
            echo "Connection failed: " . $e->getMessage();
            return null;
        }
    }
}
