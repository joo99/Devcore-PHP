<?php
/**
 * ===== Shared helpers for computing current_bookings and formatting slots (custom_slots) =====
 */

function countBookingsForSlot(PDO $db, int|string $slotId): int
{
    $stmt = $db->prepare('SELECT COUNT(id) AS cnt FROM bookings WHERE custom_slot_id = :slot_id');
    $stmt->execute(['slot_id' => $slotId]);
    $row = $stmt->fetch();
    return (int) ($row['cnt'] ?? 0);
}

/**
 * Re-fetches a full row by id.
 * Replacement for "RETURNING *", which MySQL/MariaDB do not support after INSERT/UPDATE.
 * Note: $table is always passed as a fixed literal from the code (never user input).
 */
function fetchRowById(PDO $db, string $table, int|string $id): ?array
{
    $allowed = ['departments', 'doctor_types', 'custom_slots', 'bookings', 'admins'];
    if (!in_array($table, $allowed, true)) {
        throw new InvalidArgumentException("جدول غير مسموح: $table");
    }
    $stmt = $db->prepare("SELECT * FROM $table WHERE id = :id LIMIT 1");
    $stmt->execute(['id' => $id]);
    $row = $stmt->fetch();
    return $row === false ? null : $row;
}

/**
 * Formats a slot in the same shape the frontend expects (as in the original code)
 */
function formatSlot(PDO $db, array $slot): array
{
    $currentBookings = countBookingsForSlot($db, $slot['id']);
    $from = timeShort($slot['from_time']);
    $to = timeShort($slot['to_time']);

    return array_merge($slot, [
        'current_bookings' => $currentBookings,
        'remaining' => $slot['capacity'] - $currentBookings,
        'available' => $currentBookings < $slot['capacity'],
        'time_range' => "$from - $to",
        'time_display' => "من $from إلى $to",
        'from_time_formatted' => $from,
        'to_time_formatted' => $to,
        'slot_display' => "$from - $to",
    ]);
}

function fetchCustomSlots(PDO $db, int|string $doctorTypeId): array
{
    $stmt = $db->prepare('SELECT * FROM custom_slots WHERE doctor_type_id = :id');
    $stmt->execute(['id' => $doctorTypeId]);
    return $stmt->fetchAll();
}
