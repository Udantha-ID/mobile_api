<?php

function getLeaveRemaining($conn, $employeeId, $leavePolicyId) {
    // 1. Primary source: employee_leave_balances (tracks actual remaining)
    $sqlBal = "
        SELECT total_taken, remaining
        FROM employee_leave_balances
        WHERE employee_id = ? AND leave_policy_id = ?
        LIMIT 1
    ";
    $st = $conn->prepare($sqlBal);
    $st->bind_param("ii", $employeeId, $leavePolicyId);
    $st->execute();
    $row = $st->get_result()->fetch_assoc();
    $st->close();

    if ($row) {
        $remaining   = (float)$row["remaining"];
        $taken       = (float)$row["total_taken"];
        $entitlement = $taken + $remaining;
        return ["entitlement" => $entitlement, "taken" => $taken, "remaining" => $remaining];
    }

    // 2. Fallback for employees with no balance row yet: use yearly entitlement
    //    minus approved paid_days in current year
    $sqlEnt = "
        SELECT leave_entitlement
        FROM employee_yearly_leave_balance
        WHERE employee_id = ? AND leave_policy_id = ?
        LIMIT 1
    ";
    $st = $conn->prepare($sqlEnt);
    $st->bind_param("ii", $employeeId, $leavePolicyId);
    $st->execute();
    $rowEnt = $st->get_result()->fetch_assoc();
    $st->close();
    $entitlement = $rowEnt ? (float)$rowEnt["leave_entitlement"] : 0.0;

    $sqlTaken = "
        SELECT COALESCE(SUM(paid_days), 0) AS paid_taken
        FROM leave_requests
        WHERE employee_id = ? AND leave_policy_id = ?
          AND status = 'APPROVED'
          AND YEAR(leave_start_date) = YEAR(CURDATE())
    ";
    $st = $conn->prepare($sqlTaken);
    $st->bind_param("ii", $employeeId, $leavePolicyId);
    $st->execute();
    $rowTaken = $st->get_result()->fetch_assoc();
    $st->close();
    $taken = (float)($rowTaken["paid_taken"] ?? 0);

    $remaining = max(0.0, $entitlement - $taken);

    return ["entitlement" => $entitlement, "taken" => $taken, "remaining" => $remaining];
}

function splitPaidNoPay($requestedDays, $remaining) {
    $paid  = round(min($requestedDays, $remaining), 2);
    $noPay = round($requestedDays - $paid, 2);
    return [$paid, $noPay];
}
