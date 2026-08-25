<?php
header("Content-Type: application/json; charset=UTF-8");

ini_set("display_errors", 0);
error_reporting(E_ALL);

$employee_id = intval($_GET["employee_id"] ?? 0);
if (!$employee_id) {
    echo json_encode(["success" => false, "message" => "employee_id required"]);
    exit;
}

$access = require __DIR__ . "/assets/config/module_access.php";

$data = [];
foreach ($access as $module => $ids) {
    $data[$module] = in_array($employee_id, $ids, true);
}

echo json_encode(["success" => true, "data" => $data]);
