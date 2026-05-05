<?php

function reclaimEnsureClaimStatusSchema(PDO $db): void
{
    static $ensured = false;

    if ($ensured) {
        return;
    }

    $column = $db->query("SHOW COLUMNS FROM claim_requests LIKE 'status'")->fetch(PDO::FETCH_ASSOC);
    if (!$column) {
        return;
    }

    $type = strtolower((string) ($column['Type'] ?? ''));
    $requiredValues = ["'pending'", "'approved'", "'rejected'", "'completed'", "'cancelled'"];
    $missingValue = false;

    foreach ($requiredValues as $value) {
        if (strpos($type, $value) === false) {
            $missingValue = true;
            break;
        }
    }

    if ($missingValue) {
        $db->exec("
            ALTER TABLE claim_requests
            MODIFY status ENUM('pending','approved','rejected','completed','cancelled') NULL DEFAULT 'pending'
        ");
    }

    $db->exec("
        UPDATE claim_requests c
        LEFT JOIN items i ON c.item_id = i.item_id
        SET c.status = CASE
            WHEN COALESCE(c.status, '') <> '' THEN c.status
            WHEN c.verified_date IS NOT NULL THEN 'completed'
            ELSE 'cancelled'
        END
        WHERE COALESCE(c.status, '') = ''
    ");

    $ensured = true;
}
