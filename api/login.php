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
  if (($_SERVER["REQUEST_METHOD"] ?? "") !== "POST") {
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

  $email = trim($data["email"] ?? "");
  $password = (string)($data["password"] ?? ""); 

  if ($email === "" || $password === "") {
    http_response_code(400);
    echo json_encode(["success" => false, "message" => "Email and password required"]);
    exit;
  }
  $sql = "
    SELECT
      u.id AS user_id,
      u.email,
      u.password AS user_password,
      u.employee_id,

      e.employee_code,
      e.full_name,
      e.preferred_name,
      e.employment_status,
      e.date_of_birth,

      ej.department_id,
      d.name AS department_name,

      ej.job_title_id,
      jt.name AS job_title_name,

      ej.employment_type,
      ej.employment_level,
      ej.date_of_joining,
      ej.probation_end_date,
      ej.reporting_manager_id,

      -- WORK EMAIL
      cwe.contact_value AS work_email,

      -- PHONE
      cph.contact_value AS phone_number,

      -- manager details
      m.employee_code AS manager_code,
      m.full_name AS manager_full_name,
      m.preferred_name AS manager_preferred_name

    FROM users u
    JOIN employees e ON e.employee_id = u.employee_id

    LEFT JOIN employee_job ej 
      ON ej.employee_id = e.employee_id

    LEFT JOIN departments d 
      ON d.department_id = ej.department_id

    LEFT JOIN job_titles jt 
      ON jt.job_title_id = ej.job_title_id

    -- Work Email
    LEFT JOIN employee_contacts cwe
      ON cwe.employee_id = e.employee_id
      AND cwe.contact_type = 'Work Email'
      AND cwe.is_primary = 1

    -- Phone
    LEFT JOIN employee_contacts cph
      ON cph.employee_id = e.employee_id
      AND cph.contact_type IN ('Whatsapp Number','Mobile','MOBILE','Phone')
      AND cph.is_primary = 1

    -- Manager
    LEFT JOIN employees m 
      ON m.employee_id = ej.reporting_manager_id

    WHERE u.email = ?
    LIMIT 1
  ";

  $stmt = $conn->prepare($sql);
  $stmt->bind_param("s", $email);
  $stmt->execute();
  $res = $stmt->get_result();

  if ($res->num_rows === 0) {
    http_response_code(401);
    echo json_encode(["success" => false, "message" => "Invalid email or password"]);
    exit;
  }

  $row = $res->fetch_assoc();

  // employment_status in your DB shows "Active" (case may vary)
  $status = strtolower(trim((string)($row["employment_status"] ?? "")));
  if ($status !== "active") {
    http_response_code(403);
    echo json_encode(["success" => false, "message" => "Employee is not active"]);
    exit;
  }

  // Password check (supports hashed + temporary plaintext fallback)
  $stored = (string)($row["user_password"] ?? "");
  $ok = false;

  if ($stored !== "") {
    if (password_verify($password, $stored)) {
      $ok = true;
    } else if (hash_equals($stored, $password)) {
      $ok = true;

      // OPTIONAL: auto-upgrade plaintext to hashed
      /*
      $newHash = password_hash($password, PASSWORD_BCRYPT);
      $u2 = $conn->prepare("UPDATE users SET password = ? WHERE id = ?");
      $u2->bind_param("si", $newHash, $row["user_id"]);
      $u2->execute();
      $u2->close();
      */
    }
  }

  if (!$ok) {
    http_response_code(401);
    echo json_encode(["success" => false, "message" => "Invalid email or password"]);
    exit;
  }

  $displayName = trim((string)($row["preferred_name"] ?? ""));
  if ($displayName === "") $displayName = trim((string)($row["full_name"] ?? ""));

  $managerName = trim((string)($row["manager_preferred_name"] ?? ""));
  if ($managerName === "") $managerName = trim((string)($row["manager_full_name"] ?? ""));

  echo json_encode([
    "success" => true,
    "message" => "Login success",
    "user" => [
      "userId" => (int)$row["user_id"],
      "employeeId" => $row["employee_id"],
      "email" => $row["email"],

      "employeeCode" => $row["employee_code"],
      "fullName" => $row["full_name"] ?? "",
      "preferredName" => $row["preferred_name"] ?? "",
      "name" => $displayName,

      "dateOfBirth" => $row["date_of_birth"] ?? "",

      "departmentId" => $row["department_id"] ?? null,
      "department" => $row["department_name"] ?? "",

      "jobTitleId" => $row["job_title_id"] ?? null,
      "jobTitle" => $row["job_title_name"] ?? "",
      "employmentType" => $row["employment_type"] ?? null,
      "employmentLevel" => $row["employment_level"] ?? null,
      "dateOfJoining" => $row["date_of_joining"] ?? null,
      "probationEndDate" => $row["probation_end_date"] ?? null,
      "reportingManagerId" => $row["reporting_manager_id"] ?? null,
      "reportingManagerName" => $managerName,

      "workEmail" => $row["work_email"] ?? "",
      "phone" => $row["phone_number"] ?? "",
    ]
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