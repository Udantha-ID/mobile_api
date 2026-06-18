<?php
header("Content-Type: application/json; charset=UTF-8");
require_once __DIR__ . "/../assets/includes/db_connect.php";

ini_set("display_errors", 0);
error_reporting(E_ALL);

function respond($success, $message, $data = null) {
  echo json_encode(["success"=>$success, "message"=>$message, "data"=>$data]);
  exit;
}

function writeLog($title, $data = null) {
  $file = __DIR__ . "/transport_delete_log.txt";

  $text = "[" . date("Y-m-d H:i:s") . "] " . $title . PHP_EOL;

  if ($data !== null) {
    $text .= print_r($data, true) . PHP_EOL;
  }

  $text .= str_repeat("-", 60) . PHP_EOL;

  file_put_contents($file, $text, FILE_APPEND);
}

function cancelRentalInExploreDrive($transportId) {
  $url = "https://srilankaautorentals.com/api/rental-cancel";
  $secret = "123456789";

  $payload = [
    "transport_id" => $transportId
  ];

  writeLog("Entered cancelRentalInExploreDrive", [
    "url" => $url,
    "payload" => $payload
  ]);

  $ch = curl_init($url);

  if ($ch === false) {
    writeLog("curl_init failed");
    return [
      "success" => false,
      "status" => 0,
      "error" => "curl_init failed",
      "raw" => null,
      "decoded" => null,
    ];
  }

  curl_setopt_array($ch, [
    CURLOPT_RETURNTRANSFER => true,
    CURLOPT_POST => true,
    CURLOPT_HTTPHEADER => [
      "Content-Type: application/json",
      "Accept: application/json",
      "X-SYNC-SECRET: " . $secret
    ],
    CURLOPT_POSTFIELDS => json_encode($payload),
    CURLOPT_TIMEOUT => 20,
    CURLOPT_CONNECTTIMEOUT => 5,
    CURLOPT_SSL_VERIFYPEER => false,
    CURLOPT_SSL_VERIFYHOST => false,
  ]);

  $response = curl_exec($ch);
  $httpCode = curl_getinfo($ch, CURLINFO_HTTP_CODE);
  $error = curl_error($ch);

  writeLog("Raw curl result", [
    "response" => $response,
    "httpCode" => $httpCode,
    "error" => $error
  ]);

  curl_close($ch);

  $result = [
    "success" => $httpCode >= 200 && $httpCode < 300,
    "status" => $httpCode,
    "error" => $error,
    "raw" => $response,
    "decoded" => json_decode($response, true),
  ];

  writeLog("Cancel response", $result);

  return $result;
}

if (($_SERVER["REQUEST_METHOD"] ?? "") !== "POST") {
  http_response_code(405);
  respond(false, "Method not allowed");
}

$raw = file_get_contents("php://input");
$body = json_decode($raw, true);

writeLog("Incoming request", [
  "raw" => $raw,
  "decoded" => $body
]);

if (!is_array($body)) respond(false, "Invalid JSON");

$id = (int)($body["id"] ?? 0);
if ($id <= 0) respond(false, "id required");

try {

  writeLog("Attempting delete", ["id" => $id]);
  $stmt = $conn->prepare("
    DELETE FROM transport_services
    WHERE id = ?
      AND status IN ('PENDING','Pending','pending', 'HOD_REJECTED')
  ");
  $stmt->bind_param("i", $id);
  $stmt->execute();

  if ($stmt->affected_rows <= 0) {
     writeLog("Delete failed", ["id" => $id]);
    $stmt->close();
    respond(false, "Not deleted. It may not be Pending, or id not found.");
  }

  $stmt->close();

  
  writeLog("Delete success", ["id" => $id]);

  $cancelResult = cancelRentalInExploreDrive($id);

  if (!$cancelResult["success"]) {
    writeLog("Cancel sync failed", [
      "id" => $id,
      "result" => $cancelResult
    ]);

    respond(false, "Deleted, but rental cancel sync failed", [
      "id" => $id,
      "rental" => $cancelResult
    ]);
  }

  writeLog("Final success", [
    "id" => $id,
    "cancel_result" => $cancelResult
  ]);

  respond(true, "Deleted successfully", [
    "id" => $id,
    "rental" => $cancelResult
  ]);

  respond(true, "Deleted successfully", ["id" => $id]);

} catch (Throwable $e) {

  writeLog("Fatal error", [
    "message" => $e->getMessage(),
    "file" => $e->getFile(),
    "line" => $e->getLine()
  ]);
  http_response_code(500);
  respond(false, "Server error: " . $e->getMessage());
}