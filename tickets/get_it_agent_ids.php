<?php
header("Content-Type: application/json; charset=UTF-8");

ini_set("display_errors", 0);
error_reporting(E_ALL);

try {
    $ids = require_once __DIR__ . "/../assets/config/it_agent_ids.php";

    if (!is_array($ids)) {
        throw new Exception("Invalid IT agent IDs config");
    }

    // Return as strings so Flutter string comparison works directly
    $stringIds = array_map('strval', $ids);

    echo json_encode([
        "success"   => true,
        "agent_ids" => $stringIds,
    ]);

} catch (Throwable $e) {
    http_response_code(500);
    echo json_encode([
        "success" => false,
        "message" => "Failed to load IT agent IDs",
    ]);
}
