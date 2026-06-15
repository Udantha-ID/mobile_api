<?php
header("Content-Type: application/json; charset=UTF-8");
require_once __DIR__ . "/../assets/includes/db_connect.php";

try {
  if (($_SERVER["REQUEST_METHOD"] ?? "") !== "POST") {
    http_response_code(405);
    echo json_encode(["success" => false, "message" => "Method not allowed"]);
    exit;
  }

  $data = json_decode(file_get_contents("php://input"), true);
  $employeeId = trim($data["employeeId"] ?? "");
  $fcmToken   = trim($data["fcmToken"]   ?? "");

  if ($employeeId === "" || $fcmToken === "") {
    http_response_code(400);
    echo json_encode(["success" => false, "message" => "employeeId and fcmToken required"]);
    exit;
  }

  $stmt = $conn->prepare("UPDATE employees SET fcm_token = ? WHERE employee_id = ?");
  $stmt->bind_param("ss", $fcmToken, $employeeId);
  $stmt->execute();

  // affected_rows is 0 both when nothing changed AND when the token was already identical —
  // both are fine. Only a real SQL error (caught below) means failure.
  error_log("save_fcm_token: employee_id=$employeeId affected_rows=" . $stmt->affected_rows);

  $stmt->close();
  $conn->close();

  echo json_encode([
    "success"       => true,
    "message"       => "Token saved",
    "employee_id"   => $employeeId,
    "affected_rows" => $stmt->affected_rows ?? 0,
  ]);

} catch (Throwable $e) {
  http_response_code(500);
  echo json_encode(["success" => false, "message" => "EXCEPTION: " . $e->getMessage()]);
}
