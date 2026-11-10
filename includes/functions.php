<?php
require_once __DIR__ . '/db.php';

function formatDate(string $date): string
{
    if ($date === '') {
        return 'N/A';
    }

    try {
        return (new DateTime($date))->format('M d, Y');
    } catch (Throwable $e) {
        return $date;
    }
}

function formatTime(string $time): string
{
    if ($time === '') {
        return 'N/A';
    }

    try {
        return (new DateTime('2000-01-01 ' . $time))->format('g:i A');
    } catch (Throwable $e) {
        return $time;
    }
}

function isAdminLoggedIn(): bool
{
    return isset($_SESSION['admin_logged_in']) && $_SESSION['admin_logged_in'] === true;
}

function requireAdminLogin(): void
{
    if (!isAdminLoggedIn()) {
        header('Location: /admin/login.php');
        exit;
    }
}

function renderPlayerList(array $players): string
{
    if (!$players) {
        return 'No additional players listed.';
    }

    return implode(', ', $players);
}

function buildCourtStatusLabel(int $currentTotal, int $maxPlayers): array
{
    if ($currentTotal >= $maxPlayers) {
        return ['label' => 'FULL', 'class' => 'status full'];
    }

    if ($currentTotal >= ($maxPlayers * 0.75)) {
        return ['label' => 'Almost Full', 'class' => 'status almost'];
    }

    return ['label' => 'Available', 'class' => 'status available'];
}
