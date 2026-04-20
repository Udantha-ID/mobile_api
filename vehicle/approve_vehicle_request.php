<?php
header("Content-Type: application/json; charset=UTF-8");
require_once __DIR__ . "/../assets/includes/db_connect.php";

ini_set("display_errors", 0);
error_reporting(E_ALL);

function respond($success, $message, $data = null) {
  echo json_encode([
    "success" => $success,
    "message" => $message,
    "data" => $data
  ]);
  exit;
}

function makeTripCode($employeeName) {
  $clean = strtoupper(preg_replace('/\s+/', '', (string)$employeeName));
  if (strlen($clean) < 4) $clean = str_pad($clean, 4, "X");
  $namePart = substr($clean, 0, 4);
  $numPart = strval(random_int(1000, 9999));
  return "#" . $namePart . $numPart;
}

if (($_SERVER["REQUEST_METHOD"] ?? "") !== "POST") {
  http_response_code(405);
  respond(false, "Method not allowed");
}

$raw = file_get_contents("php://input");
$body = json_decode($raw, true);
if (!is_array($body)) respond(false, "Invalid JSON");

$request_id = (int)($body["request_id"] ?? 0);
$hod_comment = trim($body["hod_comment"] ?? "");

if ($request_id <= 0) respond(false, "request_id required");

try {

  // ============================================================
  // 1. LOAD REQUEST + MANAGER
  // ============================================================
  $stmt = $conn->prepare("
    SELECT
      ts.id,
      ts.status,
      ts.type,
      ts.employee_id,
      ts.manager_id,
      e.full_name,
      e.preferred_name
    FROM transport_services ts
    JOIN employees e ON e.employee_id = ts.employee_id
    WHERE ts.id = ?
      AND ts.status IN ('PENDING', 'HOD_APPROVED')
      AND ts.deleted_at IS NULL
    LIMIT 1
  ");
  $stmt->bind_param("i", $request_id);
  $stmt->execute();
  $res = $stmt->get_result();
  $row = $res->fetch_assoc();
  $stmt->close();

  if (!$row) {
    respond(false, "Request not found or already processed");
  }

  $employeeName = trim($row["preferred_name"] ?: $row["full_name"]);
  if ($employeeName === "") $employeeName = "USER";

  $empId = (int)$row["employee_id"];
  $managerId = (int)$row["manager_id"];

  // ============================================================
  // 2. CHECK IF MANAGER IS GM
  // ============================================================
  $isGM = false;

  $jobStmt = $conn->prepare("
    SELECT jt.job_title_id
    FROM employee_job ej
    JOIN job_titles jt ON jt.job_title_id = ej.job_title_id
    WHERE ej.employee_id = ?
    LIMIT 1
  ");
  $jobStmt->bind_param("i", $managerId);
  $jobStmt->execute();
  $jobRes = $jobStmt->get_result();

  if ($jr = $jobRes->fetch_assoc()) {
    if ((int)$jr["job_title_id"] === 15) {
      $isGM = true;
    }
  }
  $jobStmt->close();

  // ============================================================
  // 3. PERSONAL FLOW
  // ============================================================
  if ($row["type"] === "personal") {

    // =========================
    // CASE 1: PENDING
    // =========================
    if ($row["status"] === "PENDING") {

      // 🔥 IF MANAGER IS GM → DIRECT APPROVAL
      if ($isGM) {

        $tripCode = makeTripCode($employeeName);

        $stmt2 = $conn->prepare("
          UPDATE transport_services
          SET status = 'APPROVED',
              trip_code = ?,
              updated_at = NOW()
          WHERE id = ?
            AND status = 'PENDING'
            AND deleted_at IS NULL
        ");
        $stmt2->bind_param("si", $tripCode, $request_id);
        $stmt2->execute();

        if ($stmt2->affected_rows <= 0) {
          $stmt2->close();
          respond(false, "Direct GM approval failed");
        }
        $stmt2->close();

        respond(true, "Approved by General Manager", [
          "trip_code" => $tripCode
        ]);
      }

      // 🔹 NORMAL HOD FLOW
      $stmt2 = $conn->prepare("
        UPDATE transport_services
        SET status = 'HOD_APPROVED',
            hod_comment = ?,
            updated_at = NOW()
        WHERE id = ?
          AND status = 'PENDING'
          AND deleted_at IS NULL
      ");
      $stmt2->bind_param("si", $hod_comment, $request_id);
      $stmt2->execute();

      if ($stmt2->affected_rows <= 0) {
        $stmt2->close();
        respond(false, "HOD approval failed");
      }
      $stmt2->close();

      respond(true, "Request forwarded to General Manager");
    }

    // =========================
    // CASE 2: GM FINAL APPROVAL
    // =========================
    else if ($row["status"] === "HOD_APPROVED") {

      $tripCode = makeTripCode($employeeName);

      $stmt2 = $conn->prepare("
        UPDATE transport_services
        SET status = 'APPROVED',
            trip_code = ?,
            updated_at = NOW()
        WHERE id = ?
          AND status = 'HOD_APPROVED'
          AND deleted_at IS NULL
      ");
      $stmt2->bind_param("si", $tripCode, $request_id);
      $stmt2->execute();

      if ($stmt2->affected_rows <= 0) {
        $stmt2->close();
        respond(false, "Final approval failed");
      }
      $stmt2->close();

      respond(true, "Request fully approved", [
        "trip_code" => $tripCode
      ]);
    }

    else {
      respond(false, "Invalid personal request status");
    }
  }

  // ============================================================
  // 4. NON-PERSONAL FLOW
  // ============================================================
  else {

    if ($row["status"] !== "PENDING") {
      respond(false, "Only PENDING requests can be approved");
    }

    $tripCode = makeTripCode($employeeName);

    $stmt2 = $conn->prepare("
      UPDATE transport_services
      SET status = 'APPROVED',
          trip_code = ?,
          updated_at = NOW()
      WHERE id = ?
        AND status = 'PENDING'
        AND deleted_at IS NULL
    ");
    $stmt2->bind_param("si", $tripCode, $request_id);
    $stmt2->execute();

    if ($stmt2->affected_rows <= 0) {
      $stmt2->close();
      respond(false, "Approval failed");
    }
    $stmt2->close();

    respond(true, "Approved", [
      "trip_code" => $tripCode
    ]);
  }

} catch (Throwable $e) {
  http_response_code(500);
  respond(false, "Server error: " . $e->getMessage());
}