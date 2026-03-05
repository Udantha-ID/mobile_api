<?php
header("Content-Type: application/json; charset=UTF-8");
require_once __DIR__ . "/../assets/includes/db_connect.php";

mysqli_report(MYSQLI_REPORT_ERROR | MYSQLI_REPORT_STRICT);

try {

  $data = json_decode(file_get_contents("php://input"), true);

  $email = $data["email"] ?? "";
  $recoveryKey = $data["recovery_key"] ?? "";
  $newPassword = $data["newPassword"] ?? "";

  if ($email === "" || $recoveryKey === "" || $newPassword === "") {
    echo json_encode(["success" => false, "message" => "Missing fields"]);
    exit;
  }

  // check recovery key
  $stmt = $conn->prepare("SELECT recovery_key FROM users WHERE email = ?");
  $stmt->bind_param("s", $email);
  $stmt->execute();
  $user = $stmt->get_result()->fetch_assoc();

  if (!$user) {
    echo json_encode(["success" => false, "message" => "User not found"]);
    exit;
  }

  if ($user["recovery_key"] !== $recoveryKey) {
    echo json_encode(["success" => false, "message" => "Invalid recovery key"]);
    exit;
  }

  // update CURRENT password column
  $hash = password_hash($newPassword, PASSWORD_BCRYPT);

  $stmt2 = $conn->prepare("
    UPDATE users 
    SET password = ?, updated_at = NOW()
    WHERE email = ?
  ");
  $stmt2->bind_param("ss", $hash, $email);
  $stmt2->execute();

  echo json_encode([
    "success" => true,
    "message" => "Password reset successful"
  ]);

} catch (Throwable $e) {
  echo json_encode(["success" => false, "message" => $e->getMessage()]);
}
