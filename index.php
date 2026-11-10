<?php
session_start();
require_once __DIR__ . '/includes/functions.php';

$successMessage = '';
$errorMessage = '';
$bookingResult = null;

function handleBookingSubmission(array $postData): array
{
    $bookingType = sanitizeText($postData['booking_type'] ?? '');
    $courtId = (int) ($postData['court_id'] ?? 0);
    $date = sanitizeText($postData['booking_date'] ?? '');
    $hostName = sanitizeText($postData['host_name'] ?? $postData['renter_name'] ?? '');
    $contactNumber = sanitizeText($postData['contact_number'] ?? '');
    $email = sanitizeText($postData['email'] ?? '');
    $playerInput = $postData['other_players'] ?? [];
    $players = parsePlayerNames(is_array($playerInput) ? $playerInput : [$playerInput]);

    $court = getCourtById($courtId);
    if (!$court) {
        return ['success' => false, 'message' => 'Please select a valid court.'];
    }

    if ($date === '' || $hostName === '' || $contactNumber === '') {
        return ['success' => false, 'message' => 'Please provide the booking date, host name, and contact details.'];
    }

    if ($bookingType === 'open_play') {
        $openPlaySlots = [
            '16:00-20:00' => ['16:00', '20:00'],
            '20:00-00:00' => ['20:00', '00:00'],
        ];
        $slot = sanitizeText($postData['slot_choice'] ?? '');
        if (!isset($openPlaySlots[$slot])) {
            return ['success' => false, 'message' => 'Please choose an open play time slot.'];
        }
        [$startTime, $endTime] = $openPlaySlots[$slot];
    } else {
        $startTime = sanitizeText($postData['start_time'] ?? '');
        $durationHours = (float) ($postData['rental_duration'] ?? 1);
        $endTime = date('H:i', strtotime('+' . $durationHours . ' hours', strtotime($startTime)));
    }

    if ($startTime === '' || $endTime === '') {
        return ['success' => false, 'message' => 'Please select a valid time.'];
    }

    $totalPlayers = 1 + count($players);
    $maxPlayers = (int) $court['max_players'];
    if ($totalPlayers > $maxPlayers) {
        return ['success' => false, 'message' => 'A booking can include a maximum of ' . $maxPlayers . ' players including the booking host.'];
    }

    $availability = courtHasAvailability(
        $courtId,
        $date,
        $startTime,
        $endTime,
        $bookingType,
        $totalPlayers,
        $maxPlayers
    );
    if (!$availability['available']) {
        return ['success' => false, 'message' => $availability['message']];
    }

    $reference = generateReference();
    $pdo = getPdo();
    $stmt = $pdo->prepare("INSERT INTO bookings (booking_reference, booking_type, booking_date, start_time, end_time, court_id, host_name, contact_number, email, player_names, players_count, status, notes, updated_at) VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, 'confirmed', ?, CURRENT_TIMESTAMP)");
    $playerList = implode(', ', $players);
    $result = $stmt->execute([$reference, $bookingType, $date, $startTime, $endTime, $courtId, $hostName, $contactNumber, $email, $playerList, $totalPlayers, 'Booking created from public website.']);

    if (!$result) {
        return ['success' => false, 'message' => 'The booking could not be saved. Please try again.'];
    }

    $bookingId = (int) $pdo->lastInsertId();
    ensureBookingPlayers($bookingId, $players);

    return [
        'success' => true,
        'message' => 'Booking confirmed successfully.',
        'reference' => $reference,
        'booking_type' => $bookingType,
        'booking_date' => $date,
        'start_time' => $startTime,
        'end_time' => $endTime,
        'court_name' => $court['name'],
        'host_name' => $hostName,
        'contact_number' => $contactNumber,
        'email' => $email,
        'players' => $players,
        'players_count' => $totalPlayers,
    ];
}

if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['booking_type'])) {
    $bookingResult = handleBookingSubmission($_POST);
    if ($bookingResult['success']) {
        $successMessage = $bookingResult['message'];
    } else {
        $errorMessage = $bookingResult['message'];
    }
}

$overview = getCourtAvailabilityToday();
$recentBookings = getRecentBookings(10, true);
?>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8" />
    <meta name="viewport" content="width=device-width, initial-scale=1.0" />
    <title>Pickle Trip | Play. Book. Enjoy!</title>
    <meta name="description" content="Book open play sessions and rent pickleball courts with Pickle Trip." />
    <link rel="stylesheet" href="assets/css/style.css?v=4" />
</head>
<body>
    <header class="site-header">
        <div class="container navbar">
            <a href="#top" class="brand" aria-label="Pickle Trip home">
                <span class="brand-mark">P</span>
                <span class="brand-copy">
                    <span>Pickle Trip</span>
                    <span>Play. Book. Enjoy!</span>
                </span>
            </a>
            <nav class="nav-links" aria-label="Main navigation">
                <a href="#top" class="active">Home</a>
                <a href="#open-play">Open Play</a>
                <a href="#court-rental">Court Rental</a>
                <a href="#courts">Courts</a>
                <a href="#my-bookings">My Bookings</a>
                <a href="#about">About</a>
                <a href="#contact">Contact</a>
            </nav>
            <a href="#open-play" class="nav-cta">Book Now</a>
        </div>
    </header>

    <main id="top">
        <section class="hero">
            <div class="container hero-grid">
                <div class="hero-copy">
                    <span class="status-pill available" style="margin-bottom: 18px;">Now booking</span>
                    <h1>Play the best game in town.</h1>
                    <p>Reserve a court, join open play, and keep your pickleball schedule organized with Pickle Trip. Book instantly, track players, and enjoy stress-free sessions with a premium court booking experience.</p>
                    <div class="hero-actions">
                        <a href="#open-play" class="button-primary">Book Open Play</a>
                        <a href="#court-rental" class="button-secondary">Rent a Court</a>
                    </div>
                </div>
            </div>
        </section>

        <section class="section">
            <div class="container">
                <div class="section-head">
                    <h2>Why players love Pickle Trip</h2>
                </div>
                <div class="feature-grid">
                    <article class="card feature-card">
                        <div>
                            <div class="feature-icon">🏓</div>
                            <h3>Open Play Sessions</h3>
                            <p>Join pick-up sessions hosted by players just like you. Choose a time, select a court, and confirm your place in a lively community experience.</p>
                        </div>
                        <a href="#open-play" class="button-soft">Explore open play</a>
                    </article>
                    <article class="card feature-card">
                        <div>
                            <div class="feature-icon">🎾</div>
                            <h3>Court Rentals</h3>
                            <p>Reserve a private court for your crew, friends, or club practice. Choose your time and keep your schedule flexible.</p>
                        </div>
                        <a href="#court-rental" class="button-soft">Reserve a court</a>
                    </article>
                </div>
            </div>
        </section>

        <section class="section" id="open-play">
            <div class="container">
                <div class="section-head">
                    <div>
                        <span class="status-pill available">Open Play</span>
                        <h2>Book a session</h2>
                    </div>
                </div>
                <?php if ($successMessage !== ''): ?>
                    <div class="alert success"><?= htmlspecialchars($successMessage) ?></div>
                <?php endif; ?>
                <?php if ($errorMessage !== ''): ?>
                    <div class="alert error"><?= htmlspecialchars($errorMessage) ?></div>
                <?php endif; ?>
                <div class="booking-shell">
                    <div class="card form-card">
                        <h3>Open Play Booking</h3>
                        <form method="post" data-booking-form>
                            <input type="hidden" name="booking_type" value="open_play" />
                            <div class="form-grid">
                                <div class="form-field">
                                    <label for="op_date">Booking date</label>
                                    <input id="op_date" type="date" name="booking_date" required />
                                </div>
                                <div class="form-field">
                                    <label for="op_slot">Time slot</label>
                                    <select id="op_slot" name="slot_choice" required>
                                        <option value="">Select slot</option>
                                        <option value="16:00-20:00">4:00 PM - 8:00 PM</option>
                                        <option value="20:00-00:00">8:00 PM - 12:00 AM</option>
                                    </select>
                                </div>
                                <div class="form-field">
                                    <label for="op_court">Court</label>
                                    <select id="op_court" name="court_id" required>
                                        <option value="">Select court</option>
                                        <?php foreach ($overview as $court): ?>
                                            <option value="<?= (int) $court['id'] ?>"><?= htmlspecialchars($court['name']) ?> - <?= htmlspecialchars($court['capacityText']) ?></option>
                                        <?php endforeach; ?>
                                    </select>
                                    <div class="court-roster" id="open-play-court-roster" aria-live="polite" hidden>
                                        <strong data-roster-title></strong>
                                        <p data-roster-status></p>
                                        <ul data-roster-players></ul>
                                    </div>
                                </div>
                                <div class="form-field">
                                    <label for="op_host">Host name</label>
                                    <input id="op_host" type="text" name="host_name" placeholder="Full name" required />
                                </div>
                                <div class="form-field">
                                    <label for="op_phone">Contact number</label>
                                    <input id="op_phone" type="tel" name="contact_number" placeholder="+1 (555) 123-4567" required />
                                </div>
                                <div class="form-field">
                                    <label for="op_email">Email (optional)</label>
                                    <input id="op_email" type="email" name="email" placeholder="you@example.com" />
                                </div>
                                <div class="form-field full">
                                    <label>Additional players</label>
                                    <div id="open-play-player-list" class="player-stack">
                                        <div class="player-row">
                                            <input type="text" name="other_players[]" placeholder="Player name" />
                                            <button type="button" class="small-button" data-remove-player>Remove</button>
                                        </div>
                                    </div>
                                    <p class="player-count" data-player-count aria-live="polite">Players in this booking: 1 / 12</p>
                                    <button type="button" class="add-player" data-add-player>Add another player</button>
                                </div>
                            </div>
                            <div class="form-actions">
                                <button type="submit" class="button-primary">Confirm booking</button>
                            </div>
                        </form>
                    </div>
                    <aside class="card summary-card">
                        <h3>Booking checklist</h3>
                        <div class="booking-summary">
                            <div class="summary-row"><span>Booking type</span><strong>Open Play</strong></div>
                            <div class="summary-row"><span>Player limit</span><strong>12 players max</strong></div>
                            <div class="summary-row"><span>Host included</span><strong>Yes</strong></div>
                            <div class="summary-row"><span>Confirmation</span><strong>Instant</strong></div>
                        </div>
                        <div class="info-grid" style="margin-top: 18px;">
                            <div class="info-box">
                                <strong>Booking rules</strong>
                                <p class="muted-note">Every court can host a maximum of 12 players. Overlapping reservations are blocked automatically on the backend.</p>
                            </div>
                            <div class="info-box">
                                <strong>Season schedule</strong>
                                <p class="muted-note">Open play sessions run throughout the week with dedicated time slots for community matchups and relaxed play.</p>
                            </div>
                        </div>
                    </aside>
                </div>
            </div>
        </section>

        <section class="section" id="court-rental">
            <div class="container">
                <div class="section-head">
                    <div>
                        <span class="status-pill almost">Court Rental</span>
                        <h2>Rent a court</h2>
                    </div>
                </div>
                <div class="booking-shell">
                    <div class="card form-card">
                        <h3>Private Court Rental</h3>
                        <form method="post" data-booking-form>
                            <input type="hidden" name="booking_type" value="court_rental" />
                            <div class="form-grid">
                                <div class="form-field">
                                    <label for="r_date">Rental date</label>
                                    <input id="r_date" type="date" name="booking_date" required />
                                </div>
                                <div class="form-field">
                                    <label for="r_time">Start time</label>
                                    <input id="r_time" type="time" name="start_time" required />
                                </div>
                                <div class="form-field">
                                    <label for="r_duration">Duration</label>
                                    <select id="r_duration" name="rental_duration" required>
                                        <option value="1">1 hour</option>
                                        <option value="2">2 hours</option>
                                        <option value="3">3 hours</option>
                                        <option value="4">4 hours</option>
                                    </select>
                                </div>
                                <div class="form-field">
                                    <label for="r_court">Court</label>
                                    <select id="r_court" name="court_id" required>
                                        <option value="">Select court</option>
                                        <?php foreach ($overview as $court): ?>
                                            <option value="<?= (int) $court['id'] ?>"><?= htmlspecialchars($court['name']) ?> - <?= htmlspecialchars($court['capacityText']) ?></option>
                                        <?php endforeach; ?>
                                    </select>
                                </div>
                                <div class="form-field">
                                    <label for="r_name">Renter name</label>
                                    <input id="r_name" type="text" name="renter_name" placeholder="Full name" required />
                                </div>
                                <div class="form-field">
                                    <label for="r_phone">Contact number</label>
                                    <input id="r_phone" type="tel" name="contact_number" placeholder="+1 (555) 123-4567" required />
                                </div>
                                <div class="form-field">
                                    <label for="r_email">Email (optional)</label>
                                    <input id="r_email" type="email" name="email" placeholder="you@example.com" />
                                </div>
                                <div class="form-field full">
                                    <label>Other players</label>
                                    <div id="rental-player-list" class="player-stack">
                                        <div class="player-row">
                                            <input type="text" name="other_players[]" placeholder="Player name" />
                                            <button type="button" class="small-button" data-remove-player>Remove</button>
                                        </div>
                                    </div>
                                    <p class="player-count" data-player-count aria-live="polite">Players in this booking: 1 / 12</p>
                                    <button type="button" class="add-player" data-add-player>Add another player</button>
                                </div>
                            </div>
                            <div class="form-actions">
                                <button type="submit" class="button-primary">Reserve now</button>
                            </div>
                        </form>
                    </div>
                    <aside class="card summary-card">
                        <h3>Rental details</h3>
                        <div class="booking-summary">
                            <div class="summary-row"><span>Booking type</span><strong>Court Rental</strong></div>
                            <div class="summary-row"><span>Capacity</span><strong>Max 12 players</strong></div>
                            <div class="summary-row"><span>Availability</span><strong>Checked live</strong></div>
                            <div class="summary-row"><span>Cancellation</span><strong>Admin managed</strong></div>
                        </div>
                        <div class="info-grid" style="margin-top: 18px;">
                            <div class="info-box">
                                <strong>Private rentals</strong>
                                <p class="muted-note">Perfect for group matches, drills, or training sessions. Secure your preferred slot before it fills up.</p>
                            </div>
                        </div>
                    </aside>
                </div>
            </div>
        </section>

        <section class="section" id="courts">
            <div class="container">
                <div class="section-head">
                    <div>
                        <span class="status-pill available">Court status</span>
                        <h2>Available courts</h2>
                    </div>
                </div>
                <div class="court-grid">
                    <?php foreach ($overview as $court): ?>
                        <?php $statusData = buildCourtStatusLabel($court['currentTotal'], (int) $court['max_players']); ?>
                        <article class="card court-card">
                            <div class="status-pill <?= htmlspecialchars($statusData['class']) ?>"><?= htmlspecialchars($statusData['label']) ?></div>
                            <h3><?= htmlspecialchars($court['name']) ?></h3>
                            <p class="court-meta">Maximum capacity: <?= (int) $court['max_players'] ?> players</p>
                            <div class="court-capacity">
                                <span><?= htmlspecialchars($court['capacityText']) ?></span>
                                <strong>Players</strong>
                            </div>
                        </article>
                    <?php endforeach; ?>
                </div>
            </div>
        </section>

        <section class="section" id="my-bookings">
            <div class="container">
                <div class="section-head">
                    <h2>My bookings</h2>
                </div>
                <div class="card report-box">
                    <?php if ($recentBookings): ?>
                        <div class="table-wrap">
                            <table>
                                <thead>
                                    <tr>
                                        <th>Ref</th>
                                        <th>Type</th>
                                        <th>Host</th>
                                        <th>Court</th>
                                        <th>Date</th>
                                        <th>Time</th>
                                        <th>Players</th>
                                    </tr>
                                </thead>
                                <tbody>
                                    <?php foreach ($recentBookings as $booking): ?>
                                        <tr>
                                            <td><strong><?= htmlspecialchars($booking['booking_reference']) ?></strong></td>
                                            <td><?= htmlspecialchars(ucwords(str_replace('_', ' ', $booking['booking_type']))) ?></td>
                                            <td><?= htmlspecialchars($booking['host_name']) ?></td>
                                            <td><?= htmlspecialchars($booking['court_name'] ?? 'Court') ?></td>
                                            <td><?= htmlspecialchars(formatDate($booking['booking_date'])) ?></td>
                                            <td><?= htmlspecialchars(formatTime($booking['start_time']) . ' - ' . formatTime($booking['end_time'])) ?></td>
                                            <td>
                                                <strong><?= (int) $booking['players_count'] ?> total</strong>
                                                <div class="muted-note">
                                                    <?= htmlspecialchars(implode(', ', array_filter([$booking['host_name'], $booking['player_names']]))) ?>
                                                </div>
                                            </td>
                                        </tr>
                                    <?php endforeach; ?>
                                </tbody>
                            </table>
                        </div>
                    <?php else: ?>
                        <p class="muted-note">No recent bookings yet. Reserve your first pickleball session to get started.</p>
                    <?php endif; ?>
                </div>
            </div>
        </section>

        <?php if ($bookingResult && $bookingResult['success']): ?>
            <section class="section" id="booking-confirmation">
                <div class="container">
                    <div class="card summary-card">
                        <h3>Booking confirmation</h3>
                        <div class="booking-summary">
                            <div class="summary-row"><span>Booking reference</span><strong><?= htmlspecialchars($bookingResult['reference']) ?></strong></div>
                            <div class="summary-row"><span>Booking type</span><strong><?= htmlspecialchars(ucwords(str_replace('_', ' ', $bookingResult['booking_type']))) ?></strong></div>
                            <div class="summary-row"><span>Host name</span><strong><?= htmlspecialchars($bookingResult['host_name']) ?></strong></div>
                            <div class="summary-row"><span>Contact</span><strong><?= htmlspecialchars($bookingResult['contact_number']) ?></strong></div>
                            <div class="summary-row"><span>Date</span><strong><?= htmlspecialchars(formatDate($bookingResult['booking_date'])) ?></strong></div>
                            <div class="summary-row"><span>Time</span><strong><?= htmlspecialchars(formatTime($bookingResult['start_time']) . ' - ' . formatTime($bookingResult['end_time'])) ?></strong></div>
                            <div class="summary-row"><span>Court</span><strong><?= htmlspecialchars($bookingResult['court_name']) ?></strong></div>
                            <div class="summary-row"><span>Total players</span><strong><?= (int) $bookingResult['players_count'] ?></strong></div>
                            <div class="summary-row"><span>Player list</span><strong><?= htmlspecialchars(implode(', ', $bookingResult['players'] ?: ['Host only'])) ?></strong></div>
                        </div>
                        <div class="form-actions">
                            <button type="button" class="button-primary" data-print-booking>Print Booking</button>
                        </div>
                    </div>
                </div>
            </section>
        <?php endif; ?>

        <section class="section" id="about">
            <div class="container grid-two">
                <div class="card form-card">
                    <h3>About Pickle Trip</h3>
                    <p class="muted-note">Pickle Trip was built to help players find and book pickleball sessions quickly, comfortably, and without friction. Whether you're joining open play or reserving a private court, the experience is streamlined and designed for community play.</p>
                </div>
                <div class="card form-card">
                    <h3>Contact</h3>
                    <div class="contact-list">
                        <div class="contact-box"><strong>Phone</strong><div class="muted-note">+1 (415) 555-0132</div></div>
                        <div class="contact-box"><strong>Email</strong><div class="muted-note">hello@pickletrip.com</div></div>
                        <div class="contact-box"><strong>Address</strong><div class="muted-note">115 Court Lane, San Jose, CA</div></div>
                    </div>
                </div>
            </div>
        </section>
    </main>

    <footer class="site-footer" id="contact">
        <div class="container footer-inner">
            <span>© 2026 Pickle Trip</span>
            <span>Play. Book. Enjoy!</span>
            <a href="admin/login.php">Admin login</a>
        </div>
    </footer>

    <script src="assets/js/main.js?v=4"></script>
</body>
</html>
