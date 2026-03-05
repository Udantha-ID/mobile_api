<?php
header("Content-Type: application/json; charset=UTF-8");
require_once __DIR__ . "/../assets/includes/db_connect.php";

ini_set("display_errors", 0);
error_reporting(E_ALL);

register_shutdown_function(function () {
  $err = error_get_last();
  if ($err && in_array($err["type"], [E_ERROR, E_PARSE, E_CORE_ERROR, E_COMPILE_ERROR])) {
    http_response_code(500);
    echo json_encode([
      "success" => false,
      "message" => "FATAL: " . $err["message"],
      "file" => basename($err["file"]),
      "line" => $err["line"],
    ]);
  }
});

set_error_handler(function ($severity, $message, $file, $line) {
  http_response_code(500);
  echo json_encode([
    "success" => false,
    "message" => "PHP: $message",
    "file" => basename($file),
    "line" => $line,
  ]);
  exit;
});

mysqli_report(MYSQLI_REPORT_ERROR | MYSQLI_REPORT_STRICT);

try {
  if ($_SERVER["REQUEST_METHOD"] !== "POST") {
    http_response_code(405);
    echo json_encode(["success" => false, "message" => "Method not allowed"]);
    exit;
  }

  $raw = file_get_contents("php://input");
  $data = json_decode($raw, true);

  if (!is_array($data)) {
    http_response_code(400);
    echo json_encode(["success" => false, "message" => "Invalid JSON body"]);
    exit;
  }

  $employeeId = trim($data["employeeId"] ?? "");
  if ($employeeId === "") {
    http_response_code(400);
    echo json_encode(["success" => false, "message" => "employeeId required"]);
    exit;
  }

  /**
   * We will return:
   * - remaining for annual/sick/casual
   * - totals (entitlement) for annual/sick/casual
   *
   * Using leave_policies.name to map policy types.
   * Assumption: leave policy names are exactly:
   * "Annual Leave", "Sick Leave", "Casual Leave"
   */

  $sql = "
    SELECT
      p.name AS policy_name,
      COALESCE(y.leave_entitlement, 0) AS total_allowed,
      COALESCE(b.total_taken, 0) AS total_taken,
      COALESCE(b.remaining, COALESCE(y.leave_entitlement, 0) - COALESCE(b.total_taken, 0)) AS remaining
    FROM leave_policies p
    LEFT JOIN employee_yearly_leave_balance y
      ON y.leave_policy_id = p.leave_policy_id
     AND y.employee_id = ?
    LEFT JOIN employee_leave_balances b
      ON b.leave_policy_id = p.leave_policy_id
     AND b.employee_id = ?
    ORDER BY p.leave_policy_id
  ";

  $stmt = $conn->prepare($sql);
  $stmt->bind_param("ss", $employeeId, $employeeId);
  $stmt->execute();
  $res = $stmt->get_result();

  // defaults
  $out = [
    "annual_days" => "0.00",
    "medical_days" => "0.00",
    "casual_days" => "0.00",
    "annual_total" => "0.00",
    "medical_total" => "0.00",
    "casual_total" => "0.00",
  ];

  while ($row = $res->fetch_assoc()) {
    $name = $row["policy_name"];
    $remaining = number_format((float)$row["remaining"], 2, ".", "");
    $total = number_format((float)$row["total_allowed"], 2, ".", "");

    if ($name === "Annual Leave") {
      $out["annual_days"] = $remaining;
      $out["annual_total"] = $total;
    } elseif ($name === "Medical Leave") {
      $out["medical_days"] = $remaining;
      $out["medical_total"] = $total;
    } elseif ($name === "Casual Leave") {
      $out["casual_days"] = $remaining;
      $out["casual_total"] = $total;
    }
  }

  echo json_encode([
    "success" => true,
    "message" => "Leave balance loaded",
    "data" => $out
  ]);

  $stmt->close();
  $conn->close();
  exit;

} catch (Throwable $e) {
  http_response_code(500);
  echo json_encode([
    "success" => false,
    "message" => "EXCEPTION: " . $e->getMessage(),
    "file" => basename($e->getFile()),
    "line" => $e->getLine(),
  ]);
}
