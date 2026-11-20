<?php
declare(strict_types=1);
if(session_status()===PHP_SESSION_NONE)session_start();
$baseUrl=getenv('APP_BASE_URL');
if($baseUrl===false)$baseUrl='/dibonke_bookshop';
defined('BASE_URL')||define('BASE_URL',rtrim($baseUrl,'/'));
defined('APP_NAME')||define('APP_NAME','Dibonke Bookshop');
defined('APP_TAGLINE')||define('APP_TAGLINE','Bookshop & General Retail Management System');
date_default_timezone_set(getenv('TZ')?:'Africa/Nairobi');
function e(mixed $value):string{return htmlspecialchars((string)$value,ENT_QUOTES|ENT_SUBSTITUTE,'UTF-8');}
function csrf_token():string{if(empty($_SESSION['csrf_token']))$_SESSION['csrf_token']=bin2hex(random_bytes(32));return $_SESSION['csrf_token'];}
function verify_csrf():void{$token=$_POST['csrf_token']??'';if(!is_string($token)||!hash_equals($_SESSION['csrf_token']??'',$token)){http_response_code(419);exit('Invalid or expired security token. Please go back and try again.');}}
