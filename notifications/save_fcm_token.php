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
  $fcmToken   = trim($data["fcmToken"] ?? "");

  if ($employeeId === "" || $fcmToken === "") {
    http_response_code(400);
    echo json_encode(["success" => false, "message" => "employeeId and fcmToken required"]);
    exit;
  }

  $stmt = $conn->prepare("UPDATE employees SET fcm_token = ? WHERE employee_id = ?");
  $stmt->bind_param("ss", $fcmToken, $employeeId);
  $stmt->execute();

  echo json_encode(["success" => true, "message" => "Token saved"]);
  $stmt->close();
  $conn->close();

} catch (Throwable $e) {
  http_response_code(500);
  echo json_encode(["success" => false, "message" => "EXCEPTION: " . $e->getMessage()]);
    