<?php
header("Content-Type: application/json; charset=UTF-8");
require_once __DIR__ . "/../assets/includes/db_connect.php";
require_once __DIR__ . "/../assets/includes/leave_balance_helper.php";

ini_set("display_errors", 0);
error_reporting(E_ALL);

function respond($ok, $msg, $extra = []) {
    echo json_encode(array_merge(["success" => $ok, "message" => $msg], $extra));
    exit;
}

try {
    if (($_SERVER["REQUEST_METHOD"] ?? "") !== "GET") {
        http_response_code(405);
        respond(false, "Method not allowed");
    }

    $employeeId    = (int)($_GET["employee_id"]    ?? 0);
    $leavePolicyId = (int)($_GET["leave_policy_id"] ?? 0);
    $days          = (float)($_GET["days"]          ?? 0);

    if ($employeeId <= 0 || $leavePolicyId <= 0 || $days <= 0) {
        http_response_code(400);
        respond(false, "Missing required params: employee_id, leave_policy_id, days");
    }

    if (!in_array($leavePolicyId, [1, 2, 3], true)) {
        respond(true, "OK", [
            "data" => [
                "entitlement" => 0.0,
                "taken"       => 0.0,
                "remaining"   => 0.0,
                "paidDays"    => $days,
                "noPayDays"   => 0.0,
                "hasNoPay"    => false,
            ]
        ]);
    }

    $bal = getLeaveRemaining($conn, $employeeId, $leavePolicyId);
    [$paidDays, $noPayDays] = splitPaidNoPay($days, $bal["remaining"]);

    respond(true, "OK", [
        "data" => [
            "entitlement" => $bal["entitlement"],
            "taken"       => $bal["taken"],
            "remaining"   => $bal["remaining"],
            "paidDays"    => $paidDays,
            "noPayDays"   => $noPayDays,
            "hasNoPay"    => $noPayDays > 0,
        ]
    ]);

} catch (Throwable $e) {
    http_response_code(500);
    respond(false, "EXCEPTION: " . $e->getMessage());
}
