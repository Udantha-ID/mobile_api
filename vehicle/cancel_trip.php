<?php
header("Content-Type: application/json; charset=UTF-8");
require_once __DIR__ . "/../assets/includes/db_connect.php";

ini_set("display_errors", 0);
error_reporting(E_ALL);

function respond($success, $message, $data = null) {
  echo json_encode(["success"=>$success, "message"=>$message, "data"=>$data]);
  exit;
}

if (($_SERVER["REQUEST_METHOD"] ?? "") !== "POST") {
  http_response_code(405);
  respond(false, "Method not allowed");
}

$raw = file_get_contents("php://input");
$body = json_decode($raw, true);
if (!is_array($body)) respond(false, "Invalid JSON");

$id = (int)($body["id"] ?? 0);
if ($id <= 0) respond(false, "id required");

try {
  $stmt = $conn->prepare("
    DELETE FROM transport_services
    WHERE id = ?
      AND status IN ('PENDING','Pending','pending')
  ");
  $stmt->bind_param("i", $id);
  $stmt->execute();

  if ($stmt->affected_rows <= 0) {
    $stmt->close();
    respond(false, "Not deleted. It may not be Pending, or id not found.");
  }

  $stmt->close();
  respond(true, "Deleted successfully", ["id" => $id]);

} catch (Throwable $e) {
  http_response_code(500);
  respond(false, "Server error: " . $e->getMessage());
}