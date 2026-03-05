<?php
header("Content-Type: application/json; charset=UTF-8");
require_once __DIR__ . "/../assets/includes/db_connect.php";

ini_set("display_errors", 0);
error_reporting(E_ALL);

function respond($success, $message) {
  echo json_encode(["success"=>$success, "message"=>$message]);
  exit;
}

if (($_SERVER["REQUEST_METHOD"] ?? "") !== "POST") respond(false, "Method not allowed");

$raw = file_get_contents("php://input");
$body = json_decode($raw, true);
if (!is_array($body)) respond(false, "Invalid JSON");

$request_id = (int)($body["request_id"] ?? 0);
$comment = trim($body["comment"] ?? "");
if ($request_id <= 0) respond(false, "request_id required");
if ($comment === "") respond(false, "comment required");

try {
  $stmt = $conn->prepare("
    UPDATE transport_services
    SET status = 'REJECTED', reject_reason = ?, updated_at = NOW()
    WHERE id = ?
      AND status = 'PENDING'
      AND deleted_at IS NULL
  ");
  $stmt->bind_param("si", $comment, $request_id);
  $stmt->execute();

  if ($stmt->affected_rows <= 0) {
    $stmt->close();
    respond(false, "Cannot reject. Only PENDING requests can be rejected.");
  }

  $stmt->close();
  respond(true, "Rejected");
} catch (Throwable $e) {
  http_response_code(500);
  respond(false, $e->getMessage());
}