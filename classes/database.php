<?php
    class Database {
    private $host = "localhost";
    private $db_name = "dti_pricemonitoringsystem";
    private $username = "root";
    private $password = "";

    public $conn;

    public function getConnection() {
        $this->conn = null;
            $options = [
            PDO::ATTR_ERRMODE            => PDO::ERRMODE_EXCEPTION, 
            PDO::ATTR_DEFAULT_FETCH_MODE => PDO::FETCH_ASSOC,   
            PDO::ATTR_EMULATE_PREPARES   => false,          
        ];

        try {
            $this->conn = new PDO("mysql:host=" . $this->host . ";dbname=" . $this->db_name, $this->username, $this->password);
            $this->conn->exec("set names utf8");
            $this->conn->exec("SET time_zone = '+08:00'");
            $this->conn->exec("SET SESSION innodb_lock_wait_timeout = 10"); 
            $this->conn->exec("SET SESSION wait_timeout = 10");
        } catch (PDOException $e) {
            echo "Connection Error: " . $e->getMessage();
            error_log("Database connection failed: " . $e->getMessage());
            die("Sorry, there was a problem connecting to the database. Please try again later.");
        }
        
        return $this->conn; 
    }
    }
?> 
