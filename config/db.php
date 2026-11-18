<?php
declare(strict_types=1);

$host=getenv('DB_HOST')?:'localhost';
$port=(int)(getenv('DB_PORT')?:3306);
$dbname=getenv('DB_NAME')?:'dibonke_bookshop_db';
$username=getenv('DB_USER')?:'root';
$password=getenv('DB_PASSWORD')!==false?getenv('DB_PASSWORD'):'';

try{
    $conn=new PDO(
        "mysql:host={$host};port={$port};dbname={$dbname};charset=utf8mb4",
        $username,
        $password,
        [PDO::ATTR_ERRMODE=>PDO::ERRMODE_EXCEPTION,PDO::ATTR_DEFAULT_FETCH_MODE=>PDO::FETCH_ASSOC,PDO::ATTR_EMULATE_PREPARES=>false]
    );
}catch(PDOException $e){
    error_log('Dibonke Bookshop DB connection failed: '.$e->getMessage());
    die('Database connection failed. Please check the Dibonke Bookshop database configuration.');
}
