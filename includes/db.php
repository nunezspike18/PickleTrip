<?php
require_once __DIR__ . '/config.php';

function getPdo(): PDO
{
    static $pdo = null;

    if ($pdo instanceof PDO) {
        return $pdo;
    }

    $dbType = strtoupper(DB_TYPE);

    try {
        if ($dbType === 'MYSQL' || $dbType === 'MARIADB') {
            $dsn = 'mysql:host=' . DB_HOST . ';dbname=' . DB_NAME . ';charset=utf8mb4';
            $pdo = new PDO($dsn, DB_USER, DB_PASS, [
                PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION,
                PDO::ATTR_DEFAULT_FETCH_MODE => PDO::FETCH_ASSOC,
            ]);
        } else {
            $pdo = new PDO('sqlite:' . DB_PATH, null, null, [
                PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION,
                PDO::ATTR_DEFAULT_FETCH_MODE => PDO::FETCH_ASSOC,
            ]);
        }
    } catch (Throwable $e) {
        $pdo = new PDO('sqlite:' . DB_PATH, null, null, [
            PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION,
            PDO::ATTR_DEFAULT_FETCH_MODE => PDO::FETCH_ASSOC,
        ]);
    }

    initDatabase($pdo);
    return $pdo;
}

function initDatabase(PDO $pdo): void
{
    $pdo->exec("
        CREATE TABLE IF NOT EXISTS courts (
            id INTEGER PRIMARY KEY AUTOINCREMENT,
            name TEXT NOT NULL,
            max_players INTEGER NOT NULL DEFAULT 12,
            status TEXT NOT NULL DEFAULT 'available',
            is_active INTEGER NOT NULL DEFAULT 1,
            created_at TEXT NOT NULL DEFAULT CURRENT_TIMESTAMP
        )
    ");

    $pdo->exec("
        CREATE TABLE IF NOT EXISTS bookings (
            id INTEGER PRIMARY KEY AUTOINCREMENT,
            booking_reference TEXT NOT NULL UNIQUE,
            booking_type TEXT NOT NULL,
            booking_date TEXT NOT NULL,
            start_time TEXT NOT NULL,
            end_time TEXT NOT NULL,
            court_id INTEGER NOT NULL,
            host_name TEXT NOT NULL,
            contact_number TEXT NOT NULL,
            email TEXT DEFAULT '',
            player_names TEXT DEFAULT '',
            players_count INTEGER NOT NULL DEFAULT 1,
            status TEXT NOT NULL DEFAULT 'confirmed',
            notes TEXT DEFAULT '',
            created_at TEXT NOT NULL DEFAULT CURRENT_TIMESTAMP,
            updated_at TEXT NOT NULL DEFAULT CURRENT_TIMESTAMP,
            FOREIGN KEY (court_id) REFERENCES courts(id)
        )
    ");

    $pdo->exec("
        CREATE TABLE IF NOT EXISTS booking_players (
            id INTEGER PRIMARY KEY AUTOINCREMENT,
            booking_id INTEGER NOT NULL,
            player_name TEXT NOT NULL,
            FOREIGN KEY (booking_id) REFERENCES bookings(id) ON DELETE CASCADE
        )
    ");

    $pdo->exec("
        CREATE TABLE IF NOT EXISTS admins (
            id INTEGER PRIMARY KEY AUTOINCREMENT,
            username TEXT NOT NULL UNIQUE,
            password_hash TEXT NOT NULL,
            full_name TEXT NOT NULL,
            is_active INTEGER NOT NULL DEFAULT 1,
            created_at TEXT NOT NULL DEFAULT CURRENT_TIMESTAMP
        )
    ");

    $courtCount = (int) $pdo->query('SELECT COUNT(*) FROM courts')->fetchColumn();
    if ($courtCount === 0) {
        $courtNames = ['Court 1', 'Court 2', 'Court 3', 'Court 4'];
        foreach ($courtNames as $courtName) {
            $stmt = $pdo->prepare('INSERT INTO courts (name, max_players, status, is_active) VALUES (?, 12, "available", 1)');
            $stmt->execute([$courtName]);
        }
    }

    $adminCount = (int) $pdo->query('SELECT COUNT(*) FROM admins')->fetchColumn();
    if ($adminCount === 0) {
        $stmt = $pdo->prepare('INSERT INTO admins (username, password_hash, full_name, is_active) VALUES (?, ?, ?, 1)');
        $stmt->execute(['admin', password_hash('admin123', PASSWORD_DEFAULT), 'Administrator']);
    }
}

function generateReference(): string
{
    $prefix = 'PT';
    $suffix = strtoupper(bin2hex(random_bytes(4)));
    return $prefix . '-' . $suffix;
}

function sanitizeText(?string $value): string
{
    return trim((string) ($value ?? ''));
}

function parsePlayerNames(?array $players): array
{
    $names = [];

    if (!$players) {
        return $names;
    }

    foreach ($players as $player) {
        $name = sanitizeText((string) $player);
        if ($name !== '') {
            $names[] = $name;
        }
    }

    return array_values(array_unique($names));
}

function getCourtList(): array
{
    $pdo = getPdo();
    $stmt = $pdo->query('SELECT * FROM courts WHERE is_active = 1 ORDER BY id ASC');
    return $stmt->fetchAll();
}

function getCourtById(int $courtId): ?array
{
    $pdo = getPdo();
    $stmt = $pdo->prepare('SELECT * FROM courts WHERE id = ? AND is_active = 1 LIMIT 1');
    $stmt->execute([$courtId]);
    $court = $stmt->fetch();
    return $court ?: null;
}

function getCourtAvailabilityToday(): array
{
    $pdo = getPdo();
    $today = date('Y-m-d');
    $courts = getCourtList();
    $overview = [];

    foreach ($courts as $court) {
        $courtId = (int) $court['id'];
        $countStatement = $pdo->prepare('SELECT COALESCE(SUM(players_count), 0) FROM bookings WHERE court_id = ? AND booking_date = ? AND status = "confirmed"');
        $countStatement->execute([$courtId, $today]);
        $currentTotal = (int) $countStatement->fetchColumn();

        $status = 'Available';
        if ($currentTotal >= (int) $court['max_players']) {
            $status = 'Full';
        } elseif ($currentTotal >= (int) $court['max_players'] * 0.75) {
            $status = 'Almost Full';
        }

        $overview[] = [
            'id' => $courtId,
            'name' => $court['name'],
            'capacityText' => $currentTotal . '/' . (int) $court['max_players'],
            'currentTotal' => $currentTotal,
            'status' => $status,
            'max_players' => (int) $court['max_players'],
        ];
    }

    return $overview;
}

function getDashboardStats(): array
{
    $pdo = getPdo();
    $today = date('Y-m-d');

    $totalBookings = (int) $pdo->query('SELECT COUNT(*) FROM bookings')->fetchColumn();
    $totalPlayers = (int) $pdo->query('SELECT COALESCE(SUM(players_count), 0) FROM bookings')->fetchColumn();
    $availableCourts = (int) $pdo->query('SELECT COUNT(*) FROM courts WHERE is_active = 1')->fetchColumn();

    $fullyBookedStatement = $pdo->prepare('SELECT COUNT(*) FROM courts WHERE is_active = 1 AND id IN (SELECT court_id FROM bookings WHERE booking_date = ? AND status = "confirmed" GROUP BY court_id HAVING SUM(players_count) >= 12)');
    $fullyBookedStatement->execute([$today]);
    $fullyBookedCourts = (int) $fullyBookedStatement->fetchColumn();

    $todaysBookings = (int) $pdo->query('SELECT COUNT(*) FROM bookings WHERE booking_date = ' . $pdo->quote($today))->fetchColumn();
    $openPlayBookings = (int) $pdo->query('SELECT COUNT(*) FROM bookings WHERE booking_type = "open_play"')->fetchColumn();
    $courtRentalBookings = (int) $pdo->query('SELECT COUNT(*) FROM bookings WHERE booking_type = "court_rental"')->fetchColumn();

    return [
        'totalBookings' => $totalBookings,
        'totalPlayers' => $totalPlayers,
        'availableCourts' => $availableCourts,
        'fullyBookedCourts' => $fullyBookedCourts,
        'todaysBookings' => $todaysBookings,
        'openPlayReservations' => $openPlayBookings,
        'courtRentalReservations' => $courtRentalBookings,
    ];
}

function getRecentBookings(int $limit = 6): array
{
    $pdo = getPdo();
    $stmt = $pdo->prepare('SELECT b.*, c.name AS court_name FROM bookings b LEFT JOIN courts c ON c.id = b.court_id ORDER BY b.created_at DESC LIMIT ?');
    $stmt->execute([$limit]);
    return $stmt->fetchAll();
}

function getBookingsForAdmin(string $search = '', string $date = '', string $type = ''): array
{
    $pdo = getPdo();
    $sql = 'SELECT b.*, c.name AS court_name FROM bookings b LEFT JOIN courts c ON c.id = b.court_id WHERE 1 = 1';
    $params = [];

    if ($search !== '') {
        $sql .= ' AND (CAST(b.id AS TEXT) LIKE ? OR b.host_name LIKE ? OR b.player_names LIKE ?)';
        $searchTerm = '%' . $search . '%';
        $params[] = $searchTerm;
        $params[] = $searchTerm;
        $params[] = $searchTerm;
    }

    if ($date !== '') {
        $sql .= ' AND b.booking_date = ?';
        $params[] = $date;
    }

    if ($type !== '') {
        $sql .= ' AND b.booking_type = ?';
        $params[] = $type;
    }

    $sql .= ' ORDER BY b.booking_date DESC, b.start_time DESC';

    $stmt = $pdo->prepare($sql);
    $stmt->execute($params);
    return $stmt->fetchAll();
}

function getCourtPlayers(int $courtId, string $bookingDate): int
{
    $pdo = getPdo();
    $stmt = $pdo->prepare('SELECT COALESCE(SUM(players_count), 0) FROM bookings WHERE court_id = ? AND booking_date = ? AND status = "confirmed"');
    $stmt->execute([$courtId, $bookingDate]);
    return (int) $stmt->fetchColumn();
}

function courtHasAvailability(int $courtId, string $date, string $startTime, string $endTime): array
{
    $pdo = getPdo();
    $statement = $pdo->prepare('SELECT * FROM bookings WHERE court_id = ? AND booking_date = ? AND status = "confirmed" AND start_time < ? AND end_time > ? LIMIT 1');
    $statement->execute([$courtId, $date, $endTime, $startTime]);
    $row = $statement->fetch();

    if ($row) {
        return ['available' => false, 'message' => 'This court is already booked for the selected date and time.'];
    }

    $playersTotal = getCourtPlayers($courtId, $date);
    if ($playersTotal >= 12) {
        return ['available' => false, 'message' => 'This court is already at full capacity for that schedule.'];
    }

    return ['available' => true, 'message' => 'Court is available.'];
}

function fetchAllPlayers(): array
{
    $pdo = getPdo();
    $stmt = $pdo->query('SELECT b.id, b.booking_reference, b.host_name, b.contact_number, b.email, b.booking_type, b.booking_date, b.court_id, c.name AS court_name, b.player_names FROM bookings b LEFT JOIN courts c ON c.id = b.court_id ORDER BY b.booking_date DESC, b.id DESC');
    return $stmt->fetchAll();
}

function getBookingById(int $bookingId): ?array
{
    $pdo = getPdo();
    $stmt = $pdo->prepare('SELECT b.*, c.name AS court_name FROM bookings b LEFT JOIN courts c ON c.id = b.court_id WHERE b.id = ? LIMIT 1');
    $stmt->execute([$bookingId]);
    return $stmt->fetch() ?: null;
}

function getBookingPlayers(int $bookingId): array
{
    $pdo = getPdo();
    $stmt = $pdo->prepare('SELECT player_name FROM booking_players WHERE booking_id = ? ORDER BY id ASC');
    $stmt->execute([$bookingId]);
    return array_map(static fn ($row) => $row['player_name'], $stmt->fetchAll());
}

function ensureBookingPlayers(int $bookingId, array $players): void
{
    $pdo = getPdo();
    $pdo->prepare('DELETE FROM booking_players WHERE booking_id = ?')->execute([$bookingId]);

    if (!$players) {
        return;
    }

    $insert = $pdo->prepare('INSERT INTO booking_players (booking_id, player_name) VALUES (?, ?)');
    foreach ($players as $player) {
        $insert->execute([$bookingId, $player]);
    }
}
