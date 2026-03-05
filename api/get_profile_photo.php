<?php
header("Content-Type: application/json; charset=UTF-8");
require_once __DIR__ . "/../assets/includes/db_connect.php";

mysqli_report(MYSQLI_REPORT_ERROR | MYSQLI_REPORT_STRICT);

try {
  if ($_SERVER["REQUEST_METHOD"] !== "GET") {
    http_response_code(405);
    echo json_encode(["success" => false, "message" => "Method not allowed"]);
    exit;
  }

  $employeeId = $_GET["employee_id"] ?? "";
  if ($employeeId === "") {
    http_response_code(400);
    echo json_encode(["success" => false, "message" => "employee_id required"]);
    exit;
  }

  // MUST match your Apache public folder
  //$baseUploadsUrl = "https://exploresuite.lk/storage/employees/";
  $baseUploadsUrl = "http://10.0.2.2/mobile-api/uploads/";

  $sql = "
    SELECT file_name, file_path, mime_type, file_size_bytes, uploaded_at
    FROM employee_documents
    WHERE employee_id = ?
      AND doc_type = 'Profile Photo'
    ORDER BY uploaded_at DESC
    LIMIT 1
  ";

  $stmt = $conn->prepare($sql);
  $stmt->bind_param("i", $employeeId);
  $stmt->execute();
  $res = $stmt->get_result();

  if ($res->num_rows === 0) {
    echo json_encode([
      "success" => true,
      "photo" => null
    ]);
    exit;
  }

  $row = $res->fetch_assoc();

  $path = $row["file_path"];
  $url  = $baseUploadsUrl . ltrim($path, "/");

  echo json_encode([
    "success" => true,
    "photo" => [
      "fileName" => $row["file_name"],
      "filePath" => $path,
      "fileUrl" => $url,
      "mimeType" => $row["mime_type"],
      "fileSizeBytes" => (int)$row["file_size_bytes"],
      "uploadedAt" => $row["uploaded_at"]
    ]
  ]);

  $stmt->close();
  $conn->close();

} catch (Throwable $e) {
  http_response_code(500);
  echo json_encode([
    "success" => false,
    "message" => $e->getMessage()
  ]);
}