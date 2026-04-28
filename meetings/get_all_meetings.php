<?php
ob_start();
header("Content-Type: application/json; charset=UTF-8");
header("Access-Control-Allow-Origin: *");
header("Access-Control-Allow-Headers: Content-Type");

require_once __DIR__ . "/../assets/includes/db_connect.php";

ini_set("display_errors", 0);
error_reporting(E_ALL);
mysqli_report(MYSQLI_REPORT_ERROR | MYSQLI_REPORT_STRICT);

try {

  if ($_SERVER["REQUEST_METHOD"] !== "GET") {
    http_response_code(405);
    ob_clean();
    echo json_encode([
      "success" => false,
      "message" => "Method not allowed"
    ]);
    exit;
  }

  $sql = "SELECT * FROM meetings WHERE deleted_at IS NULL ORDER BY meeting_date DESC, start_time DESC";
  $result = $conn->query($sql);

  $meetings = [];
  $nowTs = time();

  while ($row = $result->fetch_assoc()) {

    // Decode JSON safely
    $row["members_ids"] = !empty($row["members_ids"]) ? (json_decode($row["members_ids"], true) ?: []) : [];
    $row["response_status"] = !empty($row["response_status"]) ? (json_decode($row["response_status"], true) ?: []) : [];
    $row["attachments"] = !empty($row["attachments"]) ? (json_decode($row["attachments"], true) ?: []) : [];

    // Keep original DB type: meeting|event
    // Compute time-based status without touching "type"
    $startTs = strtotime($row["meeting_date"] . " " . $row["start_time"]);
    $endTs   = strtotime($row["meeting_date"] . " " . $row["end_time"]);

    $currentStatus = strtolower((string)($row["status"] ?? "scheduled"));

    // If already completed/cancelled in DB, keep it.
    if (!in_array($currentStatus, ["completed", "cancelled"], true)) {
      if ($nowTs < $startTs) {
        $row["status"] = "scheduled";
      } elseif ($nowTs >= $startTs && $nowTs <= $endTs) {
        $row["status"] = "ongoing";
      } else {
        $row["status"] = "completed";
      }
    }

    // Optional: add separate field for UI
    $row["live_status"] = $row["status"];

    $meetings[] = $row;
  }

  ob_clean();
  echo json_encode([
    "success" => true,
    "data" => $meetings
  ]);
  exit;

} catch (Throwable $e) {
  http_response_code(500);
  ob_clean();
  echo json_encode([
    "success" => false,
    "message" => $e->getMessage()
  ]);
  exit;
}