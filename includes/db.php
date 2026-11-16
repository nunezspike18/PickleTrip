<?php
require_once __DIR__ . '/config.php';

final class SupabaseException extends RuntimeException
{
    public $status;
    public $apiCode;

    public function __construct(string $message, int $status = 0, string $apiCode = '', ?Throwable $previous = null)
    {
        parent::__construct($message, 0, $previous);
        $this->status = $status;
        $this->apiCode = $apiCode;
    }
}

function supabaseRequest(string $method, string $resource, array $query = [], ?array $body = null, string $prefer = ''): array
{
    if (SUPABASE_URL === '' || SUPABASE_SERVICE_ROLE_KEY === '') {
        throw new RuntimeException('Supabase is not configured. Set PICKLE_SUPABASE_URL and PICKLE_SUPABASE_SERVICE_ROLE_KEY on the PHP server.');
    }

    $scheme = strtolower((string) parse_url(SUPABASE_URL, PHP_URL_SCHEME));
    $host = strtolower((string) parse_url(SUPABASE_URL, PHP_URL_HOST));
    $isLocalHttp = $scheme === 'http' && in_array($host, ['localhost', '127.0.0.1', '::1'], true);
    if (($scheme !== 'https' && !$isLocalHttp) || $host === '') {
        throw new RuntimeException('PICKLE_SUPABASE_URL must be an HTTPS URL (HTTP is allowed only for localhost).');
    }

    $url = SUPABASE_URL . '/rest/v1/' . ltrim($resource, '/');
    if ($query) {
        $url .= '?' . http_build_query($query, '', '&', PHP_QUERY_RFC3986);
    }

    $headers = [
        'apikey: ' . SUPABASE_SERVICE_ROLE_KEY,
        'Authorization: Bearer ' . SUPABASE_SERVICE_ROLE_KEY,
        'Accept: application/json',
    ];
    if ($body !== null) {
        $headers[] = 'Content-Type: application/json';
    }
    if ($prefer !== '') {
        $headers[] = 'Prefer: ' . $prefer;
    }

    $options = [
        'http' => [
            'method' => strtoupper($method),
            'header' => implode("\r\n", $headers),
            'content' => $body === null ? '' : json_encode($body, JSON_THROW_ON_ERROR),
            'timeout' => 15,
            'ignore_errors' => true,
            'follow_location' => 0,
            'max_redirects' => 0,
        ],
    ];

    set_error_handler(static function (int $severity, string $message): bool {
        throw new ErrorException($message, 0, $severity);
    });
    try {
        $responseBody = file_get_contents($url, false, stream_context_create($options));
    } catch (ErrorException $error) {
        throw new SupabaseException('Could not connect to Supabase. Check the URL, server network access, and PHP HTTPS stream support.', 0, '', $error);
    } finally {
        restore_error_handler();
    }

    $status = 0;
    foreach ($http_response_header ?? [] as $header) {
        if (preg_match('/^HTTP\/\S+\s+(\d{3})/', $header, $matches)) {
            $status = (int) $matches[1];
        }
    }

    if ($responseBody === false || $status === 0) {
        throw new SupabaseException('Supabase returned no HTTP response. Check the project URL and server network access.');
    }

    if ($status < 200 || $status >= 300) {
        $errorData = json_decode($responseBody, true);
        $message = is_array($errorData) ? (string) ($errorData['message'] ?? $errorData['error_description'] ?? 'Supabase request failed.') : 'Supabase request failed.';
        $apiCode = is_array($errorData) ? (string) ($errorData['code'] ?? '') : '';
        throw new SupabaseException($message, $status, $apiCode);
    }

    if ($responseBody === '') {
        return [];
    }

    $data = json_decode($responseBody, true, 512, JSON_THROW_ON_ERROR);
    if (!is_array($data)) {
        throw new SupabaseException('Supabase returned an unexpected response format.', $status);
    }
    return $data;
}

function generateReference(): string
{
    return 'PT-' . strtoupper(bin2hex(random_bytes(12)));
}

function sanitizeText($value): string
{
    return is_scalar($value) ? trim((string) $value) : '';
}

function parsePlayerNames(?array $players): array
{
    $names = [];
    foreach ($players ?? [] as $player) {
        if (!is_scalar($player)) {
            continue;
        }
        $name = sanitizeText((string) $player);
        if ($name !== '') {
            $names[] = $name;
        }
    }
    return array_values(array_unique($names));
}

function getCourtList(): array
{
    return supabaseRequest('GET', 'courts', [
        'select' => '*',
        'is_active' => 'eq.true',
        'order' => 'id.asc',
    ]);
}

function getCourtById(int $courtId): ?array
{
    $courts = supabaseRequest('GET', 'courts', [
        'select' => '*',
        'id' => 'eq.' . $courtId,
        'is_active' => 'eq.true',
        'limit' => '1',
    ]);
    return $courts[0] ?? null;
}

function getCourtAvailabilityToday(): array
{
    $today = date('Y-m-d');
    $courts = getCourtList();
    $bookings = supabaseRequest('GET', 'bookings', [
        'select' => 'court_id,players_count',
        'booking_date' => 'eq.' . $today,
        'status' => 'eq.confirmed',
    ]);
    $totals = [];
    foreach ($bookings as $booking) {
        $courtId = (int) $booking['court_id'];
        $totals[$courtId] = ($totals[$courtId] ?? 0) + (int) $booking['players_count'];
    }

    $overview = [];
    foreach ($courts as $court) {
        $courtId = (int) $court['id'];
        $currentTotal = $totals[$courtId] ?? 0;
        $maxPlayers = (int) $court['max_players'];
        $status = $currentTotal >= $maxPlayers ? 'Full' : ($currentTotal >= $maxPlayers * 0.75 ? 'Almost Full' : 'Available');
        $overview[] = [
            'id' => $courtId,
            'name' => $court['name'],
            'capacityText' => $currentTotal . '/' . $maxPlayers,
            'currentTotal' => $currentTotal,
            'status' => $status,
            'max_players' => $maxPlayers,
        ];
    }
    return $overview;
}

function getDashboardStats(): array
{
    $bookings = supabaseRequest('GET', 'bookings', ['select' => 'booking_date,booking_type,players_count,court_id,status']);
    $courts = getCourtList();
    $today = date('Y-m-d');
    $totalPlayers = 0;
    $todayBookings = 0;
    $openPlayBookings = 0;
    $courtRentalBookings = 0;
    $playersByCourt = [];

    foreach ($bookings as $booking) {
        $totalPlayers += (int) $booking['players_count'];
        if ($booking['booking_date'] === $today) {
            $todayBookings++;
        }
        if ($booking['booking_type'] === 'open_play') {
            $openPlayBookings++;
        } elseif ($booking['booking_type'] === 'court_rental') {
            $courtRentalBookings++;
        }
        if ($booking['status'] === 'confirmed' && $booking['booking_date'] === $today) {
            $courtId = (int) $booking['court_id'];
            $playersByCourt[$courtId] = ($playersByCourt[$courtId] ?? 0) + (int) $booking['players_count'];
        }
    }

    $fullyBookedCourts = 0;
    foreach ($courts as $court) {
        if (($playersByCourt[(int) $court['id']] ?? 0) >= (int) $court['max_players']) {
            $fullyBookedCourts++;
        }
    }

    return [
        'totalBookings' => count($bookings),
        'totalPlayers' => $totalPlayers,
        'availableCourts' => count($courts),
        'fullyBookedCourts' => $fullyBookedCourts,
        'todaysBookings' => $todayBookings,
        'openPlayReservations' => $openPlayBookings,
        'courtRentalReservations' => $courtRentalBookings,
    ];
}

function normalizeBooking(array $booking): array
{
    $court = $booking['courts'] ?? null;
    if (is_array($court) && isset($court[0])) {
        $court = $court[0];
    }
    $booking['court_name'] = is_array($court) ? ($court['name'] ?? null) : null;
    unset($booking['courts']);
    return $booking;
}

function getRecentBookings(int $limit = 6, bool $confirmedOnly = false): array
{
    $query = [
        'select' => '*,courts(name)',
        'order' => 'created_at.desc',
        'limit' => (string) max(0, $limit),
    ];
    if ($confirmedOnly) {
        $query['status'] = 'eq.confirmed';
    }
    return array_map('normalizeBooking', supabaseRequest('GET', 'bookings', $query));
}

function getBookingsForAdmin(string $search = '', string $date = '', string $type = ''): array
{
    $query = ['select' => '*,courts(name)', 'order' => 'booking_date.desc,start_time.desc'];
    if ($date !== '') {
        $query['booking_date'] = 'eq.' . $date;
    }
    if ($type !== '') {
        $query['booking_type'] = 'eq.' . $type;
    }
    $bookings = array_map('normalizeBooking', supabaseRequest('GET', 'bookings', $query));

    if ($search !== '') {
        $needle = function_exists('mb_strtolower') ? mb_strtolower($search) : strtolower($search);
        $bookings = array_values(array_filter($bookings, static function (array $booking) use ($needle): bool {
            $haystack = implode(' ', [
                (string) $booking['id'],
                (string) $booking['booking_reference'],
                (string) $booking['host_name'],
                (string) $booking['player_names'],
            ]);
            $haystack = function_exists('mb_strtolower') ? mb_strtolower($haystack) : strtolower($haystack);
            return strpos($haystack, $needle) !== false;
        }));
    }
    return $bookings;
}

function getCourtPlayers(int $courtId, string $bookingDate): int
{
    $bookings = supabaseRequest('GET', 'bookings', [
        'select' => 'players_count',
        'court_id' => 'eq.' . $courtId,
        'booking_date' => 'eq.' . $bookingDate,
        'status' => 'eq.confirmed',
    ]);
    return array_sum(array_map(static fn (array $booking): int => (int) $booking['players_count'], $bookings));
}

function courtHasAvailability(
    int $courtId,
    string $date,
    string $startTime,
    string $endTime,
    string $bookingType = '',
    int $playersToAdd = 1,
    int $maxPlayers = 12
): array {
    $bookings = supabaseRequest('GET', 'bookings', [
        'select' => 'booking_type,start_time,end_time,players_count',
        'court_id' => 'eq.' . $courtId,
        'booking_date' => 'eq.' . $date,
        'status' => 'eq.confirmed',
    ]);
    $startMinute = timeToMinutes($startTime);
    $endMinute = timeToMinutes($endTime);
    if ($endMinute <= $startMinute) {
        $endMinute += 24 * 60;
    }

    $openPlayPlayers = 0;
    foreach ($bookings as $booking) {
        $existingStart = timeToMinutes($booking['start_time']);
        $existingEnd = timeToMinutes($booking['end_time']);
        if ($existingEnd <= $existingStart) {
            $existingEnd += 24 * 60;
        }

        $sameOpenPlaySession = $bookingType === 'open_play'
            && $booking['booking_type'] === 'open_play'
            && $existingStart === $startMinute
            && $existingEnd === $endMinute;

        if ($sameOpenPlaySession) {
            $openPlayPlayers += (int) $booking['players_count'];
            continue;
        }

        if ($startMinute < $existingEnd && $existingStart < $endMinute) {
            return [
                'available' => false,
                'message' => 'This court is already booked for the selected date and time.',
                'players_booked' => $openPlayPlayers,
                'players_remaining' => max(0, $maxPlayers - $openPlayPlayers),
            ];
        }
    }

    if ($bookingType === 'open_play') {
        $playersRemaining = max(0, $maxPlayers - $openPlayPlayers);
        if ($playersToAdd > $playersRemaining) {
            return [
                'available' => false,
                'message' => $playersRemaining === 0
                    ? 'This open play session is full.'
                    : 'Only ' . $playersRemaining . ' spot' . ($playersRemaining === 1 ? '' : 's') . ' left in this open play session.',
                'players_booked' => $openPlayPlayers,
                'players_remaining' => $playersRemaining,
            ];
        }
        return [
            'available' => true,
            'message' => 'Court is available.',
            'players_booked' => $openPlayPlayers,
            'players_remaining' => $playersRemaining,
        ];
    }
    return ['available' => true, 'message' => 'Court is available.'];
}

function getOpenPlayRoster(int $courtId, string $date, string $startTime, string $endTime): array
{
    $bookings = supabaseRequest('GET', 'bookings', [
        'select' => 'id,host_name,player_names,players_count,start_time,end_time',
        'court_id' => 'eq.' . $courtId,
        'booking_date' => 'eq.' . $date,
        'booking_type' => 'eq.open_play',
        'status' => 'eq.confirmed',
        'order' => 'id.asc',
    ]);

    $matchingBookings = [];
    $targetStart = timeToMinutes($startTime);
    $targetEnd = timeToMinutes($endTime);
    if ($targetEnd <= $targetStart) {
        $targetEnd += 24 * 60;
    }
    foreach ($bookings as $booking) {
        $bookingStart = timeToMinutes($booking['start_time']);
        $bookingEnd = timeToMinutes($booking['end_time']);
        if ($bookingEnd <= $bookingStart) {
            $bookingEnd += 24 * 60;
        }
        if ($bookingStart === $targetStart && $bookingEnd === $targetEnd) {
            $matchingBookings[] = $booking;
        }
    }

    $playersByBooking = [];
    if ($matchingBookings) {
        $bookingIds = array_map(static fn (array $booking): string => (string) (int) $booking['id'], $matchingBookings);
        $playerRows = supabaseRequest('GET', 'booking_players', [
            'select' => 'booking_id,player_name',
            'booking_id' => 'in.(' . implode(',', $bookingIds) . ')',
            'order' => 'id.asc',
        ]);
        foreach ($playerRows as $player) {
            $playersByBooking[(int) $player['booking_id']][] = $player['player_name'];
        }
    }

    $players = [];
    $playersBooked = 0;
    foreach ($matchingBookings as $booking) {
        $bookingPlayers = [$booking['host_name']];
        $additionalPlayers = $playersByBooking[(int) $booking['id']] ?? parsePlayerNames(explode(',', (string) $booking['player_names']));
        array_push($bookingPlayers, ...$additionalPlayers);
        $bookingPlayerCount = max((int) $booking['players_count'], count($bookingPlayers));
        $playersBooked += $bookingPlayerCount;
        array_push($players, ...$bookingPlayers);
        for ($missingNames = count($bookingPlayers); $missingNames < $bookingPlayerCount; $missingNames++) {
            $players[] = 'Player name not provided';
        }
    }

    return [
        'players_booked' => $playersBooked,
        'players' => array_values(array_filter($players, static fn ($name): bool => trim((string) $name) !== '')),
    ];
}

function timeToMinutes(string $time): int
{
    $parts = explode(':', $time);
    return ((int) ($parts[0] ?? 0) * 60) + (int) ($parts[1] ?? 0);
}

function fetchAllPlayers(): array
{
    $bookings = supabaseRequest('GET', 'bookings', [
        'select' => 'id,booking_reference,host_name,contact_number,email,booking_type,booking_date,court_id,player_names,courts(name)',
        'order' => 'booking_date.desc,id.desc',
    ]);
    return array_map('normalizeBooking', $bookings);
}

function getBookingById(int $bookingId): ?array
{
    $bookings = supabaseRequest('GET', 'bookings', [
        'select' => '*,courts(name)',
        'id' => 'eq.' . $bookingId,
        'limit' => '1',
    ]);
    return isset($bookings[0]) ? normalizeBooking($bookings[0]) : null;
}

function getBookingByReference(string $reference): ?array
{
    $bookings = supabaseRequest('GET', 'bookings', [
        'select' => '*,courts(name)',
        'booking_reference' => 'eq.' . $reference,
        'status' => 'eq.confirmed',
        'limit' => '1',
    ]);
    return isset($bookings[0]) ? normalizeBooking($bookings[0]) : null;
}

function getBookingPlayers(int $bookingId): array
{
    $players = supabaseRequest('GET', 'booking_players', [
        'select' => 'player_name',
        'booking_id' => 'eq.' . $bookingId,
        'order' => 'id.asc',
    ]);
    return array_map(static fn (array $player): string => $player['player_name'], $players);
}

function findAdminByUsername(string $username): ?array
{
    $admins = supabaseRequest('GET', 'admins', [
        'select' => 'id,username,password_hash,full_name',
        'username' => 'eq.' . $username,
        'is_active' => 'eq.true',
        'limit' => '1',
    ]);
    return $admins[0] ?? null;
}

function createBooking(array $booking, array $players): array
{
    return supabaseRequest('POST', 'rpc/create_booking', [], [
        'p_booking' => $booking,
        'p_players' => array_values($players),
    ]);
}

function updatePaymentReference(string $bookingReference, string $paymentReference): bool
{
    $updated = supabaseRequest('PATCH', 'bookings', [
        'booking_reference' => 'eq.' . $bookingReference,
        'payment_status' => 'eq.pending',
        'status' => 'eq.confirmed',
        'select' => 'id',
    ], ['payment_reference' => $paymentReference], 'return=representation');
    return count($updated) > 0;
}

function updateBookingPaymentStatus(int $bookingId, string $paymentStatus): bool
{
    if (!in_array($paymentStatus, ['pending', 'paid'], true)) {
        throw new InvalidArgumentException('Invalid payment status.');
    }
    $updated = supabaseRequest('PATCH', 'bookings', [
        'id' => 'eq.' . $bookingId,
        'status' => 'eq.confirmed',
        'select' => 'id',
    ], ['payment_status' => $paymentStatus, 'updated_at' => date(DATE_ATOM)], 'return=representation');
    return count($updated) > 0;
}

function cancelBooking(int $bookingId): bool
{
    $updated = supabaseRequest('PATCH', 'bookings', [
        'id' => 'eq.' . $bookingId,
        'status' => 'eq.confirmed',
        'select' => 'id',
    ], ['status' => 'cancelled', 'updated_at' => date(DATE_ATOM)], 'return=representation');
    return count($updated) > 0;
}

function addCourt(string $name): void
{
    supabaseRequest('POST', 'courts', [], [
        'name' => $name,
        'max_players' => 12,
        'status' => 'available',
        'is_active' => true,
    ], 'return=minimal');
}

function setCourtActive(int $courtId, bool $isActive): bool
{
    $updated = supabaseRequest('PATCH', 'courts', [
        'id' => 'eq.' . $courtId,
        'select' => 'id',
    ], [
        'is_active' => $isActive,
        'status' => $isActive ? 'available' : 'inactive',
    ], 'return=representation');
    return count($updated) > 0;
}
