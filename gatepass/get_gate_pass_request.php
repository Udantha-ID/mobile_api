<?php
ob_start();
header("Content-Type: application/json; charset=UTF-8");

require_once __DIR__ . "/../assets/includes/db_connect.php";

ini_set("display_errors", 0);
error_reporting(E_ALL);
mysqli_report(MYSQLI_REPORT_ERROR | MYSQLI_REPORT_STRICT);

try {

    // ── Employee ID from query param ──────────────────────────────────────
    $employee_id = isset($_GET["employee_id"]) ? intval($_GET["employee_id"]) : 0;

    if ($employee_id <= 0) {
        http_response_code(422);
        ob_clean();
        echo json_encode(["success" => false, "message" => "employee_id is required"]);
        exit;
    }

    // ── Fetch gate pass requests ──────────────────────────────────────────
    $sql = "
        SELECT
            gp.id,
            gp.employee_id,
            gp.employee_name,
            gp.contact_no,
            gp.manager_id,
            COALESCE(NULLIF(TRIM(m.preferred_name), ''), TRIM(m.full_name)) AS manager_name,
            gp.gate_pass_date,
            gp.out_time,
            gp.return_time,
            gp.reason,
            gp.vehicle_no,
            gp.companion_employee_ids,
            gp.gate_pass_code,
            gp.status,
            gp.reject_reason,
            gp.manager_comment,
            gp.remark,
            gp.created_at,
            gp.updated_at
        FROM gate_pass_requests gp
        LEFT JOIN employees m ON m.employee_id = gp.manager_id
        WHERE gp.employee_id = ?
          AND gp.deleted_at IS NULL
        ORDER BY gp.created_at DESC
    ";

    $stmt = $conn->prepare($sql);
    $stmt->bind_param("i", $employee_id);
    $stmt->execute();

    $result = $stmt->get_result();
    $stmt->close();

    $requests = [];

    while ($row = $result->fetch_assoc()) {

        // Decode companion IDs JSON array
        $companion_ids = null;
        if (!empty($row["companion_employee_ids"])) {
            $companion_ids = json_decode($row["companion_employee_ids"], true);
        }

        // Fetch companion names if IDs exist
        $companions = [];
        if (!empty($companion_ids) && is_array($companion_ids)) {
            $placeholders = implode(",", array_fill(0, count($companion_ids), "?"));
            $types        = str_repeat("i", count($companion_ids));

            $cSql  = "
                SELECT
                    e.employee_id,
                    COALESCE(NULLIF(TRIM(e.preferred_name), ''), TRIM(e.full_name)) AS name,
                    jt.name AS job_title
                FROM employees e
                LEFT JOIN employee_job ej ON ej.employee_id = e.employee_id
                LEFT JOIN job_titles   jt ON jt.job_title_id = ej.job_title_id
                WHERE e.employee_id IN ($placeholders)
            ";

            $cStmt = $conn->prepare($cSql);
            $cStmt->bind_param($types, ...$companion_ids);
            $cStmt->execute();
            $cResult = $cStmt->get_result();
            $cStmt->close();

            while ($c = $cResult->fetch_assoc()) {
                $companions[] = [
                    "employee_id" => (int) $c["employee_id"],
                    "name"        => $c["name"],
                    // "job_title"   => $c["job_title"] ?? "Unknown",
                ];
            }
        }

        $requests[] = [
            "id"                     => (int) $row["id"],
            "employee_id"            => (int) $row["employee_id"],
            "employee_name"          => $row["employee_name"],
            "contact_no"             => $row["contact_no"],
            "manager_id"             => (int) $row["manager_id"],
            "manager_name"           => $row["manager_name"] ?? "Unknown",
            "gate_pass_date"         => $row["gate_pass_date"],
            "out_time"               => $row["out_time"],
            "return_time"            => $row["return_time"],
            "reason"                 => $row["reason"],
            "vehicle_no"             => $row["vehicle_no"],
            "companion_employee_ids" => $companion_ids,
            "companions"             => $companions,
            "gate_pass_code"         => $row["gate_pass_code"],
            "status"                 => $row["status"],
            "reject_reason"          => $row["reject_reason"],
            "manager_comment"        => $row["manager_comment"],
            "remark"                 => $row["remark"],
            "created_at"             => $row["created_at"],
            "updated_at"             => $row["updated_at"],
        ];
    }

    ob_clean();
    echo json_encode([
        "success"  => true,
        "message"  => "Gate pass requests loaded",
        "total"    => count($requests),
        "requests" => $requests,
    ]);
    exit;

} catch (Throwable $e) {

    http_response_code(500);
    ob_clean();
    echo json_encode([
        "success" => false,
        "message" => $e->getMessage(),
    ]);
    exit;
}