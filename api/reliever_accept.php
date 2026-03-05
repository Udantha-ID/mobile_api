<?php
header("Content-Type: application/json; charset=UTF-8");
require_once __DIR__ . "/../assets/includes/db_connect.php";

ini_set("display_errors", 0);
error_reporting(E_ALL);
mysqli_report(MYSQLI_REPORT_ERROR | MYSQLI_REPORT_STRICT);

try {
  if ($_SERVER["REQUEST_METHOD"] !== "POST") {
    http_response_code(405);
    echo json_encode(["success" => false, "message" => "Method not allowed"]);
    exit;
  }

  $data = json_decode(file_get_contents("php://input"), true);

  $leaveRequestId = (int)($data["leaveRequestId"] ?? 0);
  $relieverId     = trim($data["relieverId"] ?? "");
  $comment        = trim($data["comment"] ?? "");

  if ($leaveRequestId <= 0 || $relieverId === "") {
    http_response_code(400);
    echo json_encode(["success" => false, "message" => "leaveRequestId and relieverId required"]);
    exit;
  }

  // Make sure this request belongs to this reliever and is still pending
  $chk = $conn->prepare("
    SELECT leave_request_id
    FROM leave_requests
    WHERE leave_request_id = ?
      AND oversee_member_id = ?
      AND status = 'PENDING'
    LIMIT 1
  ");
  $chk->bind_param("is", $leaveRequestId, $relieverId);
  $chk->execute();
  $found = $chk->get_result()->fetch_assoc();
  $chk->close();

  if (!$found) {
    http_response_code(403);
    echo json_encode(["success" => false, "message" => "Not allowed or request not pending"]);
    exit;
  }

  // Update status + save reliever comment
  $stmt = $conn->prepare("
    UPDATE leave_requests
    SET status = 'RELIEVER ACCEPTED',
        reliever_comment = ?,
        updated_at = NOW()
    WHERE leave_request_id = ?
  ");
  $stmt->bind_param("si", $comment, $leaveRequestId);
  $stmt->execute();
  $stmt->close();

  echo json_encode(["success" => true, "message" => "Accepted and forwarded to manager"]);
  exit;

} catch (Throwable $e) {
  http_response_code(500);
  echo json_encode(["success" => false, "message" => "EXCEPTION: " . $e->getMessage()]);
}
