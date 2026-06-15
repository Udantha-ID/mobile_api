<?php
ob_start();
header("Content-Type: application/json; charset=UTF-8");

require_once __DIR__ . "/../assets/includes/db_connect.php";

ini_set("display_errors", 0);
error_reporting(E_ALL);
mysqli_report(MYSQLI_REPORT_ERROR | MYSQLI_REPORT_STRICT);

try {

    // ── Read JSON body ────────────────────────────────────────────────────
    $body = json_decode(file_get_contents("php://input"), true);

    if (!$body || !is_array($body)) {
        http_response_code(400);
        ob_clean();
        echo json_encode(["success" => false, "message" => "Invalid JSON body"]);
        exit;
    }

    // ── Required fields ───────────────────────────────────────────────────
    $employee_id    = isset($body["employee_id"])    ? intval($body["employee_id"])  : 0;
    $employee_name  = isset($body["employee_name"])  ? trim($body["employee_name"])  : "";
    $contact_no     = isset($body["contact_no"])     ? trim($body["contact_no"])     : "";
    $manager_id     = isset($body["manager_id"])     ? intval($body["manager_id"])   : 0;
    $gate_pass_date = isset($body["gate_pass_date"]) ? trim($body["gate_pass_date"]) : "";
    $out_time       = isset($body["out_time"])       ? trim($body["out_time"])       : "";
    $return_time    = isset($body["return_time"])    ? trim($body["return_time"])    : "";
    $reason         = isset($body["reason"])         ? trim($body["reason"])         : "";

    // ── Optional fields ───────────────────────────────────────────────────
    $vehicle_no = (isset($body["vehicle_no"]) && $body["vehicle_no"] !== "")
        ? trim($body["vehicle_no"]) : null;

    $companion_employee_ids = (
        isset($body["companion_employee_ids"]) &&
        is_array($body["companion_employee_ids"]) &&
        count($body["companion_employee_ids"]) > 0
    ) ? json_encode($body["companion_employee_ids"]) : null;

    $remark = (isset($body["remark"]) && $body["remark"] !== "")
        ? trim($body["remark"]) : null;

    // ── Validate ──────────────────────────────────────────────────────────
    $errors = [];
    if ($employee_id   <= 0)  $errors[] = "employee_id is required";
    if ($employee_name === "") $errors[] = "employee_name is required";
    if ($contact_no    === "") $errors[] = "contact_no is required";
    if ($manager_id    <= 0)  $errors[] = "manager_id is required";
    if ($gate_pass_date === "") $errors[] = "gate_pass_date is required";
    if ($out_time      === "") $errors[] = "out_time is required";
    if ($return_time   === "") $errors[] = "return_time is required";
    if ($reason        === "") $errors[] = "reason is required";

    if (!empty($errors)) {
        http_response_code(422);
        ob_clean();
        echo json_encode(["success" => false, "message" => implode(", ", $errors)]);
        exit;
    }

    // ── Insert ────────────────────────────────────────────────────────────
    $sql = "INSERT INTO gate_pass_requests (
                employee_id, employee_name, contact_no, manager_id,
                gate_pass_date, out_time, return_time, reason,
                vehicle_no, companion_employee_ids, remark, status
            ) VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, 'PENDING')";

    $stmt = $conn->prepare($sql);

    // Type string — exactly 12 chars, no spaces:
    // i  = employee_id      (int)
    // s  = employee_name    (string)
    // s  = contact_no       (string)
    // i  = manager_id       (int)
    // s  = gate_pass_date   (string)
    // s  = out_time         (string)
    // s  = return_time      (string)
    // s  = reason           (string)
    // s  = vehicle_no       (string|null)
    // s  = companion_ids    (string|null)
    // s  = remark           (string|null)
    $stmt->bind_param("ississsssss",
        $employee_id,
        $employee_name,
        $contact_no,
        $manager_id,
        $gate_pass_date,
        $out_time,
        $return_time,
        $reason,
        $vehicle_no,
        $companion_employee_ids,
        $remark
    );

    $stmt->execute();
    $new_id = $conn->insert_id;
    $stmt->close();

    ob_clean();
    echo json_encode([
        "success"      => true,
        "message"      => "Gate pass submitted successfully",
        "gate_pass_id" => $new_id
    ]);
    exit;

} catch (Throwable $e) {

    http_response_code(500);
    ob_clean();
    echo json_encode([
        "success" => false,
        "message" => $e->getMessage()
    ]);
    exit;
}