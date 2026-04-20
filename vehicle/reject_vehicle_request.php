<?php
header("Content-Type: application/json; charset=UTF-8");
require_once __DIR__ . "/../assets/includes/db_connect.php";

ini_set("display_errors", 0);
error_reporting(E_ALL);

function respond($success, $message) {
  echo json_encode(["success"=>$success, "message"=>$message]);
  exit;
}

if (($_SERVER["REQUEST_METHOD"] ?? "") !== "POST") {
  respond(false, "Method not allowed");
}

$raw = file_get_contents("php://input");
$body = json_decode($raw, true);
if (!is_array($body)) respond(false, "Invalid JSON");

$request_id = (int)($body["request_id"] ?? 0);
$comment = trim($body["comment"] ?? "");

if ($request_id <= 0) respond(false, "request_id required");
if ($comment === "") respond(false, "comment required");

try {

  // ============================================================
  // 1. GET CURRENT STATUS
  // ============================================================
  $check = $conn->prepare("
    SELECT status
    FROM transport_services
    WHERE id = ?
      AND status IN ('PENDING', 'HOD_APPROVED')
      AND deleted_at IS NULL
    LIMIT 1
  ");
  $check->bind_param("i", $request_id);
  $check->execute();
  $res = $check->get_result();
  $row = $res->fetch_assoc();
  $check->close();

  if (!$row) {
    respond(false, "Request not found or already processed");
  }

  $currentStatus = $row["status"];

  // ============================================================
  // 2. DECIDE NEXT STATUS
  // ============================================================
  if ($currentStatus === "PENDING") {
    $newStatus = "HOD_REJECTED";
  } else if ($currentStatus === "HOD_APPROVED") {
    $newStatus = "REJECTED";
  } else {
    respond(false, "Invalid state");
  }

  // ============================================================
  // 3. UPDATE
  // ============================================================
if ($newStatus === "HOD_REJECTED") {

  // HOD reject → save in hod_comment
  $stmt = $conn->prepare("
    UPDATE transport_services
    SET status = ?,
        hod_comment = ?,
        updated_at = NOW()
    WHERE id = ?
      AND deleted_at IS NULL
  ");
  $stmt->bind_param("ssi", $newStatus, $comment, $request_id);

} else {

  // GM reject → save in reject_reason
  $stmt = $conn->prepare("
    UPDATE transport_services
    SET status = ?,
        reject_reason = ?,
        updated_at = NOW()
    WHERE id = ?
      AND deleted_at IS NULL
  ");
  $stmt->bind_param("ssi", $newStatus, $comment, $request_id);
}

$stmt->execute();

if ($stmt->affected_rows <= 0) {
  $stmt->close();
  respond(false, "Reject failed");
}

$stmt->close();

  // ============================================================
  // 4. RESPONSE
  // ============================================================
  if ($newStatus === "HOD_REJECTED") {
    respond(true, "Rejected by HOD");
  } else {
    respond(true, "Rejected by General Manager");
  }

} catch (Throwable $e) {
  http_response_code(500);
  respond(false, $e->getMessage());
}