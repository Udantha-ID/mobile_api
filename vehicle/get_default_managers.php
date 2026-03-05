<?php
header("Content-Type: application/json; charset=UTF-8");
require_once __DIR__ . "/../assets/includes/db_connect.php";

ini_set("display_errors", 0);
error_reporting(E_ALL);

function respond($success, $message, $data = null) {
  echo json_encode(["success"=>$success, "message"=>$message, "data"=>$data]);
  exit;
}

$employee_id = trim($_GET["employee_id"] ?? "");
if ($employee_id === "" || !ctype_digit($employee_id)) {
  respond(false, "employee_id is required and must be numeric");
}
$employee_id = (int)$employee_id;

try {
  // 1) employee's reporting manager id
  $stmt = $conn->prepare("SELECT reporting_manager_id FROM employee_job WHERE employee_id = ? LIMIT 1");
  $stmt->bind_param("i", $employee_id);
  $stmt->execute();
  $res = $stmt->get_result();
  if ($res->num_rows === 0) respond(false, "employee_job row not found");
  $row = $res->fetch_assoc();
  $stmt->close();

  $reportingManagerId = $row["reporting_manager_id"] ? (int)$row["reporting_manager_id"] : null;

  // 2) Get job_title_ids for MD + GM
  $stmt2 = $conn->prepare("
    SELECT job_title_id, name
    FROM job_titles
    WHERE name IN ('Managing Director', 'General Manager')
  ");
  $stmt2->execute();
  $res2 = $stmt2->get_result();

  $titleIds = [];
  while ($r = $res2->fetch_assoc()) {
    $titleIds[] = (int)$r["job_title_id"];
  }
  $stmt2->close();

  // If not found, still continue
  $managers = [];

  // helper: add manager by employee_id (avoid duplicates)
  $added = [];

  $addManagerById = function($id) use (&$conn, &$managers, &$added) {
    if ($id === null) return;
    if (isset($added[$id])) return;

    $q = $conn->prepare("SELECT employee_id, preferred_name, full_name FROM employees WHERE employee_id = ? LIMIT 1");
    $q->bind_param("i", $id);
    $q->execute();
    $rr = $q->get_result();
    if ($rr->num_rows > 0) {
      $e = $rr->fetch_assoc();
      $name = trim($e["preferred_name"] ?? "");
      if ($name === "") $name = trim($e["full_name"] ?? "");
      $managers[] = ["id" => (int)$e["employee_id"], "name" => $name];
      $added[$id] = true;
    }
    $q->close();
  };

  // add reporting manager first (top)
  $addManagerById($reportingManagerId);

  // 3) Add all employees who are MD/GM (from employee_job.job_title_id)
  if (count($titleIds) > 0) {
    // create placeholders ?,?
    $placeholders = implode(",", array_fill(0, count($titleIds), "?"));
    $types = str_repeat("i", count($titleIds));

    $sql = "
      SELECT DISTINCT e.employee_id, e.preferred_name, e.full_name
      FROM employee_job ej
      JOIN employees e ON e.employee_id = ej.employee_id
      WHERE ej.job_title_id IN ($placeholders)
      ORDER BY e.employee_id ASC
    ";

    $stmt3 = $conn->prepare($sql);
    $stmt3->bind_param($types, ...$titleIds);
    $stmt3->execute();
    $res3 = $stmt3->get_result();

    while ($e = $res3->fetch_assoc()) {
      $id = (int)$e["employee_id"];
      if (isset($added[$id])) continue;
      $name = trim($e["preferred_name"] ?? "");
      if ($name === "") $name = trim($e["full_name"] ?? "");
      $managers[] = ["id" => $id, "name" => $name];
      $added[$id] = true;
    }
    $stmt3->close();
  }

  respond(true, "OK", [
    "reporting_manager_id" => $reportingManagerId,
    "managers" => $managers
  ]);

} catch (Throwable $e) {
  http_response_code(500);
  respond(false, "Server error: " . $e->getMessage());
}