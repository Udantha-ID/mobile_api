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

$platform_id = (int)($_GET["platform_id"] ?? 0);

if ($platform_id <= 0) {
    respond(false, "platform_id required");
}

try {
    $stmt = $conn->prepare("
        SELECT id, title, description
        FROM common_issues
        WHERE platform_id = ?
        ORDER BY id ASC
    ");
    $stmt->bind_param("i", $platform_id);
    $stmt->execute();
    $result = $stmt->get_result();
    $stmt->close();

    $issues = [];
    while ($row = $result->fetch_assoc()) {
        $issues[] = [
            "id"          => (int)$row["id"],
            "title"       => $row["title"],
            "description" => $row["description"],
        ];
    }

    respond(true, "Issues loaded", $issues);

} catch (Throwable $e) {
    http_response_code(500);
    respond(false, "Server error: " . $e->getMessage());
}
