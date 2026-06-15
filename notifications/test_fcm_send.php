<?php
/**
 * Standalone FCM send test.
 * Usage: http://localhost/mobile-api/notifications/test_fcm_send.php?token=<FCM_TOKEN>
 * DELETE this file before deploying to production.
 */
require_once __DIR__ . "/../assets/includes/db_connect.php";
require_once __DIR__ . "/fcm_helper.php";

header("Content-Type: application/json; charset=UTF-8");

$token = trim($_GET["token"] ?? "");

if ($token === "") {
  // Look up the most recently updated fcm_token from the DB for a quick smoke-test
  $row = $conn->query("SELECT employee_id, fcm_token FROM employees WHERE fcm_token IS NOT NULL ORDER BY last_updated_date DESC LIMIT 1")->fetch_assoc();
  if (!$row || empty($row["fcm_token"])) {
    echo json_encode(["error" => "No FCM token found in DB. Pass ?token=<token> or save a token first."]);
    exit;
  }
  $token = $row["fcm_token"];
  $note  = "Using token from employee_id=" . $row["employee_id"];
} else {
  $note = "Using token from query param";
}

$result = FcmHelper::send(
  $token,
  "FCM Test",
  "If you see this, push notifications are working!",
  ["type" => "test"]
);

echo json_encode([
  "note"   => $note,
  "token"  => substr($token, 0, 20) . "...",
  "result" => $result,
], JSON_PRETTY_PRINT);
