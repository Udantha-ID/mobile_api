<?php
header("Content-Type: application/json; charset=UTF-8");
require_once __DIR__ . "/../assets/includes/db_connect.php";

ini_set("display_errors", 0);
error_reporting(E_ALL);

function respond($success, $message, $data = null) {
    echo json_encode(["success" => $success, "message" => $message, "data" => $data]);
    exit;
}

if (($_SERVER["REQUEST_METHOD"] ?? "") !== "GET") {
    http_response_code(405);
    respond(false, "Method not allowed");
}

try {
    $stmt = $conn->prepare("
        SELECT id, name, description
        FROM platforms
        ORDER BY id ASC
    ");
    $stmt->execute();
    $result = $stmt->get_result();
    $stmt->close();

    $platforms = [];
    while ($row = $result->fetch_assoc()) {
        $platforms[] = [
            "id"          => (int)$row["id"],
            "name"        => $row["name"],
            "description" => $row["description"],
        ];
    }

    respond(true, "Platforms loaded", $platforms);

} catch (Throwable $e) {
    http_response_code(500);
    respond(false, "Server error: " . $e->getMessage());
}
