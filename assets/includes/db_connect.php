<?php
//$host = "localhost";
//$user = "explore";
//$pass = "k4p00Xy&9";
//$db = "explores_";

//$conn = new mysqli($host, $user, $pass, $db);

//if ($conn->connect_error) {
// die("DB Connection failed: " . $conn->connect_error);
//}

$host = "localhost";
$user = "root";
$pass = "";
$db = "explores_";

$conn = new mysqli($host, $user, $pass, $db);

if ($conn->connect_error) {
    die("DB Connection failed: " . $conn->connect_error);
}
