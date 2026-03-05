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
  if (!is_array($data)) {
    http_response_code(400);
    echo json_encode(["success" => false, "message" => "Invalid JSON body"]);
    exit;
  }

  $email       = trim($data["email"] ?? "");
  $newPassword = trim($data["newPassword"] ?? "");
  $recoveryKey = trim($data["recovery_key"] ?? "");

  // recovery_key REQUIRED
  if ($email === "" || $newPassword === "" || $recoveryKey === "") {
    http_response_code(400);
    echo json_encode(["success" => false, "message" => "email, newPassword, recovery_key required"]);
    exit;
  }

  if ($newPassword === "Test@123") {
    http_response_code(400);
    echo json_encode(["success" => false, "message" => "Cannot use default password"]);
    exit;
  }

  $hash = password_hash($newPassword, PASSWORD_BCRYPT);

  // Update password + recovery_key always
  $sql = "UPDATE users SET password = ?, recovery_key = ?, updated_at = NOW() WHERE email = ?";
  $stmt = $conn->prepare($sql);
  $stmt->bind_param("sss", $hash, $recoveryKey, $email);
  $stmt->execute();

  // Better check: verify user exists (affected_rows can be 0 if same values)
  if ($stmt->affected_rows === 0) {
    // Check if user exists
    $chk = $conn->prepare("SELECT id FROM users WHERE email = ? LIMIT 1");
    $chk->bind_param("s", $email);
    $chk->execute();
    $exists = $chk->get_result()->fetch_assoc();
    $chk->close();

    if (!$exists) {
      http_response_code(404);
      echo json_encode(["success" => false, "message" => "User not found"]);
      exit;
    }

    echo json_encode(["success" => true, "message" => "No change (same password/recovery_key as before)"]);
    exit;
  }

  echo json_encode(["success" => true, "message" => "Password and recovery_key updated successfully"]);
  $stmt->close();
  $conn->close();
  exit;

} catch (Throwable $e) {
  http_response_code(500);
  echo json_encode(["success" => false, "message" => "EXCEPTION: " . $e->getMessage()]);
}
