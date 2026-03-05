<?php
header("Content-Type: application/json");
require_once "../assets/includes/db_connect.php";

$sql = "SELECT id, username, password FROM employees";
$result = $conn->query($sql);

$users = [];

while ($row = $result->fetch_assoc()) {
    $users[] = $row;
}

echo json_encode([
    "status" => "success",
    "data" => $users
]);
