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

  $leave_request_id = $_POST["leave_request_id"] ?? "";
  if ($leave_request_id === "") {
    http_response_code(400);
    echo json_encode(["success" => false, "message" => "leave_request_id required"]);
    exit;
  }

  if (!isset($_FILES["document"])) {
    http_response_code(400);
    echo json_encode(["success" => false, "message" => "document file required"]);
    exit;
  }

  $file = $_FILES["document"];

  if ($file["error"] !== UPLOAD_ERR_OK) {
    http_response_code(400);
    echo json_encode(["success" => false, "message" => "Upload error"]);
    exit;
  }

  $allowed = ["pdf","doc","docx","jpg","jpeg","png"];
  $originalName = $file["name"];
  $ext = strtolower(pathinfo($originalName, PATHINFO_EXTENSION));

  if (!in_array($ext, $allowed)) {
    http_response_code(400);
    echo json_encode(["success" => false, "message" => "File type not allowed"]);
    exit;
  }

  $mime = $file["type"] ?? "application/octet-stream";
  $size = (int)$file["size"];

  // folder: /api/uploads/leave_docs/
  $uploadDir = __DIR__ . "/../uploads/leave_docs/";
  if (!is_dir($uploadDir)) {
    mkdir($uploadDir, 0777, true);
  }

  $newName = "leave_" . $leave_request_id . "_" . time() . "." . $ext;
  $destPath = $uploadDir . $newName;

  if (!move_uploaded_file($file["tmp_name"], $destPath)) {
    http_response_code(500);
    echo json_encode(["success" => false, "message" => "Failed to save file"]);
    exit;
  }

  // path saved in DB (relative path)
  $relativePath = "uploads/leave_docs/" . $newName;

  // Insert into leave_request_documents table
  $stmt = $conn->prepare("
    INSERT INTO leave_request_documents
      (leave_request_id, file_name, file_path, mime_type, file_size_bytes, uploaded_at)
    VALUES
      (?, ?, ?, ?, ?, NOW())
  ");
  $stmt->bind_param("isssi", $leave_request_id, $originalName, $relativePath, $mime, $size);
  $stmt->execute();

  echo json_encode([
    "success" => true,
    "message" => "Uploaded",
    "file_path" => $relativePath,
    "file_name" => $originalName,
    "mime_type" => $mime,
    "file_size_bytes" => $size
  ]);
} catch (Throwable $e) {
  http_response_code(500);
  echo json_encode(["success" => false, "message" => $e->getMessage()]);
}
