<?php
require_once __DIR__ . '/includes/functions.php';

header('Content-Type: application/json; charset=utf-8');
header('Cache-Control: no-store, max-age=0');

$date = $_GET['booking_date'] ?? '';
$slotChoice = $_GET['slot_choice'] ?? '';
$courtId = filter_var($_GET['court_id'] ?? null, FILTER_VALIDATE_INT);
$playersCount = filter_var($_GET['players_count'] ?? null, FILTER_VALIDATE_INT);
$slots = [
    '16:00-20:00' => ['16:00', '20:00'],
    '20:00-00:00' => ['20:00', '00:00'],
];

$dateValue = is_string($date) ? DateTime::createFromFormat('!Y-m-d', $date) : false;
$dateErrors = DateTime::getLastErrors();
$validDate = $dateValue !== false
    && $dateValue->format('Y-m-d') === $date
    && ($dateErrors === false || ($dateErrors['warning_count'] === 0 && $dateErrors['error_count'] === 0));

if (!$validDate || !is_string($slotChoice) || !isset($slots[$slotChoice]) || $courtId === false || $courtId < 1 || $playersCount === false || $playersCount < 1 || $playersCount > 12) {
    http_response_code(400);
    echo json_encode(['error' => 'Choose a valid court, date, time slot, and player count.']);
    exit;
}

[$startTime, $endTime] = $slots[$slotChoice];
$court = getCourtById($courtId);
if (!$court) {
    http_response_code(404);
    echo json_encode(['error' => 'The selected court is not available.']);
    exit;
}

$courtAvailability = courtHasAvailability(
    $courtId,
    $date,
    $startTime,
    $endTime,
    'open_play',
    $playersCount,
    (int) $court['max_players']
);
$roster = getOpenPlayRoster($courtId, $date, $startTime, $endTime);

echo json_encode([
    'court_name' => $court['name'],
    'available' => $courtAvailability['available'],
    'message' => $courtAvailability['message'],
    'players_booked' => $roster['players_booked'],
    'players_remaining' => $courtAvailability['players_remaining'] ?? (int) $court['max_players'],
    'max_players' => (int) $court['max_players'],
    'players' => $roster['players'],
], JSON_THROW_ON_ERROR);
