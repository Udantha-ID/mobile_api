<?php
header("Content-Type: application/json; charset=UTF-8");
require_once __DIR__ . "/../assets/includes/db_connect.php";

ini_set("display_errors", 0);
error_reporting(E_ALL);

function respond($success, $message) {
  echo json_encode(["success" => $success, "message" => $message]);
  exit;
}

function cancelRentalInExploreDrive($transportId) {
  $url    = "https://srilankaautorentals.com/api/rental-cancel";
  $secret = "123456789";

  $ch = curl_init($url);
  curl_setopt_array($ch, [
    CURLOPT_RETURNTRANSFER => true,
    CURLOPT_POST           => true,
    CURLOPT_HTTPHEADER     => [
      "Content-Type: application/json",
      "Accept: application/json",
      "X-SYNC-SECRET: " . $secret,
    ],
    CURLOPT_POSTFIELDS     => json_encode(["transport_id" => $transportId]),
    CURLOPT_TIMEOUT        => 20,
    CURLOPT_CONNECTTIMEOUT => 5,
  ]);

  $response = curl_exec($ch);
  $httpCode = curl_getinfo($ch, CURLINFO_HTTP_CODE);
  $error    = curl_error($ch);
  curl_close($ch);

  return [
    "success"  => $httpCode >= 200 && $httpCode < 300,
    "status"   => $httpCode,
    "error"    => $error,
    "raw"      => $response,
    "decoded"  => json_decode($response, true),
  ];
}

if (($_SERVER["REQUEST_METHOD"] ?? "") !== "POST") {
  respond(false, "Method not allowed");
}

$raw  = file_get_contents("php://input");
$body = json_decode($raw, true);
if (!is_array($body)) respond(false, "Invalid JSON");

$request_id = (int)($body["request_id"] ?? 0);
$comment    = trim($body["comment"]     ?? "");

if ($request_id <= 0) respond(false, "request_id required");
if ($comment   === "") respond(false, "comment required");

try {

  // ── 1. Get current request + employee info ────────────────────────────
  $check = $conn->prepare("
    SELECT ts.status, ts.employee_id,
           ts.assigned_start_at, ts.assigned_end_at
    FROM transport_services ts
    WHERE ts.id = ?
      AND ts.status IN ('PENDING', 'HOD_APPROVED')
      AND ts.deleted_at IS NULL
    LIMIT 1
  ");
  $check->bind_param("i", $request_id);
  $check->execute();
  $row = $check->get_result()->fetch_assoc();
  $check->close();

  if (!$row) respond(false, "Request not found or already processed");

  $currentStatus = $row["status"];
  $empId         = (int)$row["employee_id"];
  $fromDate      = date("Y-m-d", strtotime($row["assigned_start_at"]));
  $toDate        = date("Y-m-d", strtotime($row["assigned_end_at"]));

  // ── 2. Decide next status ─────────────────────────────────────────────
  if ($currentStatus === "PENDING") {
    $newStatus = "HOD_REJECTED";
  } else if ($currentStatus === "HOD_APPROVED") {
    $newStatus = "REJECTED";
  } else {
    respond(false, "Invalid state");
  }

  // ── 3. Update ─────────────────────────────────────────────────────────
  if ($newStatus === "HOD_REJECTED") {
    $stmt = $conn->prepare("
      UPDATE transport_services
      SET status = ?, hod_comment = ?, updated_at = NOW()
      WHERE id = ? AND deleted_at IS NULL
    ");
    $stmt->bind_param("ssi", $newStatus, $comment, $request_id);
  } else {
    $stmt = $conn->prepare("
      UPDATE transport_services
      SET status = ?, reject_reason = ?, updated_at = NOW()
      WHERE id = ? AND deleted_at IS NULL
    ");
    $stmt->bind_param("ssi", $newStatus, $comment, $request_id);
  }

  $stmt->execute();
  if ($stmt->affected_rows <= 0) { $stmt->close(); respond(false, "Reject failed"); }
  $stmt->close();

  // ── 4. Sync rental cancel ─────────────────────────────────────────────
  if (in_array($newStatus, ["REJECTED", "HOD_REJECTED"])) {
    $cancelResult = cancelRentalInExploreDrive($request_id);
    if (!$cancelResult["success"]) {
      respond(false, "Rejected, but rental cancel sync failed");
    }
  }

  // ── 5. Notify employee (non-blocking) ─────────────────────────────────
  try {
    require_once __DIR__ . "/../notifications/fcm_helper.php";

    $stToken = $conn->prepare(
      "SELECT fcm_token FROM employees WHERE employee_id = ? LIMIT 1"
    );
    $stToken->bind_param("i", $empId);
    $stToken->execute();
    $tokenRow = $stToken->get_result()->fetch_assoc();
    $stToken->close();

    if (!empty($tokenRow["fcm_token"])) {

      if ($newStatus === "HOD_REJECTED") {
        // HOD rejected — might still be reviewed by someone else
        FcmHelper::send(
          $tokenRow["fcm_token"],
          "Vehicle Request Rejected",
          "Your vehicle request ($fromDate – $toDate) was rejected by your manager.",
          [
            "type"    => "vehicle_rejected",
            "tripId"  => (string)$request_id,
            "by"      => "hod",
          ]
        );
      } else {
        // GM final rejection
        FcmHelper::send(
          $tokenRow["fcm_token"],
          "Vehicle Request Rejected",
          "Your vehicle request ($fromDate – $toDate) was rejected by the General Manager.",
          [
            "type"   => "vehicle_rejected",
            "tripId" => (string)$request_id,
            "by"     => "gm",
          ]
        );
      }
    }
  } catch (Throwable $notifyError) {
    error_log("FCM notify (vehicle_reject) failed: " . $notifyError->getMessage());
  }

  // ── 6. Response ───────────────────────────────────────────────────────
  if ($newStatus === "HOD_REJECTED") {
    respond(true, "Rejected by HOD");
  } else {
    respond(true, "Rejected by General Manager");
  }

} catch (Throwable $e) {
  http_response_code(500);
  respond(false, $e->getMessage());
}