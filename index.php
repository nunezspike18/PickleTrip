<?php
session_start();
require_once __DIR__ . '/includes/functions.php';

$successMessage = '';
$errorMessage = '';
$bookingResult = null;
$paymentMessage = '';
$submittedBookingType = '';
$csrf = csrfToken();

function handleBookingSubmission(array $postData): array
{
    $bookingType = sanitizeText($postData['booking_type'] ?? '');
    $date = sanitizeText($postData['booking_date'] ?? '');
    $hostName = sanitizeText($postData['host_name'] ?? $postData['renter_name'] ?? '');
    $contactNumber = sanitizeText($postData['contact_number'] ?? '');
    $email = sanitizeText($postData['email'] ?? '');
    $playerInput = $postData['other_players'] ?? [];
    $players = parsePlayerNames(is_array($playerInput) ? $playerInput : [$playerInput]);
    $validDate = DateTime::createFromFormat('!Y-m-d', $date);
    $dateErrors = DateTime::getLastErrors();

    if (!in_array($bookingType, ['open_play', 'court_rental'], true)) {
        return ['success' => false, 'message' => 'Choose a valid booking type.'];
    }

    if ($validDate === false || $validDate->format('Y-m-d') !== $date
        || ($dateErrors !== false && ($dateErrors['warning_count'] > 0 || $dateErrors['error_count'] > 0))
        || $date < date('Y-m-d')) {
        return ['success' => false, 'message' => 'Choose a valid booking date that is today or later.'];
    }

    if ($hostName === '') {
        return ['success' => false, 'message' => 'Please provide the booking host name.'];
    }

    if ($bookingType === 'court_rental' && ($contactNumber === '' || strlen($contactNumber) > 50)) {
        return ['success' => false, 'message' => 'Please provide a valid contact number for the court rental.'];
    }

    if ($bookingType === 'court_rental' && $email !== '' && !filter_var($email, FILTER_VALIDATE_EMAIL)) {
        return ['success' => false, 'message' => 'Please provide a valid email address.'];
    }

    $courtId = filter_var($postData['court_id'] ?? null, FILTER_VALIDATE_INT);
    if ($courtId === false || $courtId < 1) {
        return ['success' => false, 'message' => 'Please select a valid court.'];
    }
    $durationHours = 0;
    $paymentReference = '';

    if ($bookingType === 'open_play') {
        $paymentReference = sanitizeText($postData['payment_reference'] ?? '');
        if (!preg_match('/^[A-Za-z0-9-]{5,40}$/', $paymentReference)) {
            return ['success' => false, 'message' => 'Pay the open-play fee first, then enter a valid GCash transaction number.'];
        }

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
        $durationHours = filter_var($postData['rental_duration'] ?? null, FILTER_VALIDATE_INT);
        if ($durationHours === false || $durationHours < 1 || $durationHours > 4) {
            return ['success' => false, 'message' => 'Choose a rental duration from 1 to 4 hours.'];
        }
        $parsedStartTime = DateTime::createFromFormat('!H:i', $startTime);
        if ($parsedStartTime === false || $parsedStartTime->format('H:i') !== $startTime) {
            return ['success' => false, 'message' => 'Please select a valid start time.'];
        }
        if (timeToMinutes($startTime) + ($durationHours * 60) > 24 * 60) {
            return ['success' => false, 'message' => 'Court rentals must finish by midnight.'];
        }
        $endTime = date('H:i', strtotime('+' . $durationHours . ' hours', strtotime($startTime)));
    }

    $court = getCourtById($courtId);
    if (!$court) {
        return ['success' => false, 'message' => 'Please select a valid court.'];
    }

    $totalPlayers = 1 + count($players);
    $maxPlayers = (int) $court['max_players'];
    if ($totalPlayers > $maxPlayers) {
        return ['success' => false, 'message' => 'A booking can include a maximum of ' . $maxPlayers . ' players including the booking host.'];
    }

    $paymentAmount = $bookingType === 'open_play'
        ? OPEN_PLAY_PRICE * $totalPlayers
        : COURT_RENTAL_HOURLY_PRICE * $durationHours;
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
    $playerList = implode(', ', $players);
    try {
        createBooking([
            'booking_reference' => $reference,
            'booking_type' => $bookingType,
            'booking_date' => $date,
            'start_time' => $startTime,
            'end_time' => $endTime,
            'court_id' => $courtId,
            'host_name' => $hostName,
            'contact_number' => $contactNumber,
            'email' => $email,
            'player_names' => $playerList,
            'players_count' => $totalPlayers,
            'payment_amount' => $paymentAmount,
            'payment_reference' => $bookingType === 'open_play' ? $paymentReference : null,
        ], $players);
    } catch (SupabaseException $error) {
        if ($error->apiCode === 'P0001') {
            return ['success' => false, 'message' => $error->getMessage()];
        }
        if ($error->apiCode === '23505' && $bookingType === 'open_play') {
            return ['success' => false, 'message' => 'That GCash transaction number has already been used. Check the number and try again.'];
        }
        throw $error;
    }

    return [
        'success' => true,
        'message' => $bookingType === 'open_play'
            ? 'Open-play booking received. Your GCash payment is pending manual verification.'
            : 'Booking confirmed. Please pay by GCash and submit your transaction reference for manual verification.',
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
        'payment_amount' => $paymentAmount,
        'payment_status' => 'pending',
        'payment_reference' => $paymentReference,
    ];
}

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    if (!isValidCsrfToken($_POST['csrf_token'] ?? null)) {
        http_response_code(403);
        $errorMessage = 'Your form session expired. Refresh the page and try again.';
    } elseif (($_POST['action'] ?? '') === 'submit_payment_reference') {
        $reference = sanitizeText($_POST['booking_reference'] ?? '');
        $paymentReference = sanitizeText($_POST['payment_reference'] ?? '');
        if (!preg_match('/^[A-Za-z0-9-]{5,40}$/', $reference)
            || !preg_match('/^[A-Za-z0-9-]{5,40}$/', $paymentReference)) {
            $errorMessage = 'Enter a valid booking reference and GCash transaction number.';
        } else {
            try {
                if (!updatePaymentReference($reference, $paymentReference)) {
                    $errorMessage = 'The booking was not found or its payment reference was already submitted.';
                } else {
                    $paymentMessage = 'GCash transaction reference submitted. Your payment is pending admin verification.';
                }
            } catch (SupabaseException $error) {
                if ($error->apiCode === '23505') {
                    $errorMessage = 'That GCash transaction number has already been submitted.';
                } else {
                    throw $error;
                }
            }
        }
        $bookingResult = getBookingByReference($reference);
        if ($bookingResult) {
            $bookingResult['players'] = getBookingPlayers((int) $bookingResult['id']);
            $bookingResult['reference'] = $bookingResult['booking_reference'];
            $bookingResult['success'] = true;
        } elseif ($errorMessage === '') {
            $errorMessage = 'The booking could not be found.';
        }
    } else {
        $submittedBookingType = sanitizeText($_POST['booking_type'] ?? '');
        $bookingResult = handleBookingSubmission($_POST);
        if ($bookingResult['success']) {
            $successMessage = $bookingResult['message'];
        } else {
            $errorMessage = $bookingResult['message'];
        }
    }
}

$overview = getCourtAvailabilityToday();
$initialTab = 'home';
$bookingTab = $bookingResult['booking_type'] ?? $submittedBookingType;
if (in_array($bookingTab, ['open_play', 'court_rental'], true)) {
    $initialTab = $bookingTab;
}
?>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8" />
    <meta name="viewport" content="width=device-width, initial-scale=1.0" />
    <title>Pickle Trip | Play. Book. Enjoy!</title>
    <meta name="description" content="Book open play sessions and rent pickleball courts with Pickle Trip." />
    <link rel="stylesheet" href="assets/css/style.css?v=8" />
</head>
<body class="public-site" data-initial-tab="<?= htmlspecialchars($initialTab) ?>">
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
                <a href="#about">About</a>
                <a href="#contact">Contact</a>
            </nav>
            <a href="#open-play" class="nav-cta">Book Now</a>
        </div>
    </header>

    <main>
        <?php if ($successMessage !== ''): ?>
            <div class="container alert success" role="status"><?= htmlspecialchars($successMessage) ?></div>
        <?php endif; ?>
        <?php if ($paymentMessage !== ''): ?>
            <div class="container alert success" role="status"><?= htmlspecialchars($paymentMessage) ?></div>
        <?php endif; ?>
        <?php if ($errorMessage !== ''): ?>
            <div class="container alert error" role="alert"><?= htmlspecialchars($errorMessage) ?></div>
        <?php endif; ?>
        <section class="hero" id="top" data-tab-panel="home" <?= $initialTab !== 'home' ? 'hidden' : '' ?>>
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

        <section class="section" data-tab-panel="home" <?= $initialTab !== 'home' ? 'hidden' : '' ?>>
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

        <section class="section" id="open-play" data-tab-panel="open_play" <?= $initialTab !== 'open_play' ? 'hidden' : '' ?>>
            <div class="container">
                <div class="section-head">
                    <div>
                        <span class="status-pill available">Open Play</span>
                        <h2>Book a session</h2>
                    </div>
                </div>
                <details class="booking-details" <?= $submittedBookingType === 'open_play' || ($bookingResult && ($bookingResult['booking_type'] ?? '') === 'open_play') ? 'open' : '' ?>>
                    <summary>Open play booking <span>Choose your court, players, and time</span></summary>
                    <div class="booking-shell">
                    <div class="card form-card">
                        <h3>Open Play Booking</h3>
                        <form method="post" data-booking-form>
                            <input type="hidden" name="csrf_token" value="<?= htmlspecialchars($csrf) ?>" />
                            <input type="hidden" name="booking_type" value="open_play" />
                            <div class="booking-price" data-price-preview data-price-per-player="<?= (int) OPEN_PLAY_PRICE ?>">
                                PHP <?= number_format(OPEN_PLAY_PRICE) ?> per player
                            </div>
                            <div class="form-grid">
                                <div class="form-field">
                                    <label for="op_date">Booking date</label>
                                    <input id="op_date" type="date" name="booking_date" value="<?= htmlspecialchars(date('Y-m-d')) ?>" required />
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
                                        <option value="">Select court and time</option>
                                        <?php foreach (getCourtList() as $court): ?>
                                            <option value="<?= (int) $court['id'] ?>" data-court-name="<?= htmlspecialchars($court['name']) ?>"><?= htmlspecialchars($court['name']) ?></option>
                                        <?php endforeach; ?>
                                    </select>
                                    <div class="court-roster" id="open-play-court-roster" aria-live="polite" hidden>
                                        <strong data-roster-title></strong>
                                        <p data-roster-status></p>
                                    </div>
                                </div>
                                <div class="form-field">
                                    <label for="op_host">Host name</label>
                                    <input id="op_host" type="text" name="host_name" placeholder="Full name" required />
                                </div>
                                <div class="form-field full">
                                    <label>Additional players</label>
                                    <div id="open-play-player-list" class="player-stack"></div>
                                    <p class="player-count" data-player-count aria-live="polite">Players in this booking: 1 / 12</p>
                                    <button type="button" class="add-player" data-add-player>Add another player</button>
                                </div>
                            </div>
                            <div class="payment-instructions">
                                <h4>Pay before booking open play</h4>
                                <figure class="payment-qr">
                                    <img src="assets/images/gcash-qr.jpg" alt="GCash InstaPay QR code for Pickle Trip payments" width="410" height="410" />
                                    <figcaption>After choosing all players, wait for the court roster to show enough spots, then pay the exact total shown above. Use the host name and session date in the payment message.</figcaption>
                                </figure>
                                <?php if (GCASH_NUMBER !== ''): ?>
                                    <div class="summary-row">
                                        <span>GCash number</span>
                                        <strong><?= htmlspecialchars(GCASH_NUMBER) ?></strong>
                                    </div>
                                <?php endif; ?>
                                <p>After paying, enter the GCash transaction number below. Availability is checked again when you submit, so a spot may be taken while you pay. The venue verifies payments manually.</p>
                                <div class="form-field">
                                    <label for="op_payment_reference">GCash transaction number</label>
                                    <input id="op_payment_reference" type="text" name="payment_reference" minlength="5" maxlength="40" pattern="[A-Za-z0-9-]{5,40}" autocomplete="off" required />
                                </div>
                            </div>
                            <div class="form-actions">
                                <button type="submit" class="button-primary">I paid — submit open-play booking</button>
                            </div>
                        </form>
                    </div>
                    <aside class="card summary-card">
                        <h3>Booking checklist</h3>
                        <div class="booking-summary">
                            <div class="summary-row"><span>Booking type</span><strong>Open Play</strong></div>
                            <div class="summary-row"><span>Player limit</span><strong>12 players max</strong></div>
                            <div class="summary-row"><span>Host included</span><strong>Yes</strong></div>
                            <div class="summary-row"><span>Payment</span><strong>Pay first by GCash</strong></div>
                            <div class="summary-row"><span>Verification</span><strong>Manual by venue</strong></div>
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
                </details>
            </div>
        </section>

        <section class="section" id="court-rental" data-tab-panel="court_rental" <?= $initialTab !== 'court_rental' ? 'hidden' : '' ?>>
            <div class="container">
                <div class="section-head">
                    <div>
                        <span class="status-pill almost">Court Rental</span>
                        <h2>Rent a court</h2>
                    </div>
                </div>
                <details class="booking-details" <?= $submittedBookingType === 'court_rental' || ($bookingResult && ($bookingResult['booking_type'] ?? '') === 'court_rental') ? 'open' : '' ?>>
                    <summary>Court rental booking <span>Choose a date, duration, and court</span></summary>
                    <div class="booking-shell">
                    <div class="card form-card">
                        <h3>Private Court Rental</h3>
                        <form method="post" data-booking-form>
                            <input type="hidden" name="csrf_token" value="<?= htmlspecialchars($csrf) ?>" />
                            <input type="hidden" name="booking_type" value="court_rental" />
                            <div class="booking-price" data-price-preview data-price-per-hour="<?= (int) COURT_RENTAL_HOURLY_PRICE ?>">
                                PHP <?= number_format(COURT_RENTAL_HOURLY_PRICE) ?> per court per hour
                            </div>
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
                                    <input id="r_phone" type="tel" name="contact_number" placeholder="09xx xxx xxxx" required />
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
                                <button type="submit" class="button-primary">Book and view payment details</button>
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
                </details>
            </div>
        </section>

        <section class="section" id="courts" data-tab-panel="courts" <?= $initialTab !== 'courts' ? 'hidden' : '' ?>>
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

        <?php if ($bookingResult && $bookingResult['success']): ?>
            <section class="section" id="booking-confirmation" data-tab-panel="<?= htmlspecialchars($bookingResult['booking_type']) ?>" <?= $initialTab !== $bookingResult['booking_type'] ? 'hidden' : '' ?>>
                <div class="container">
                    <div class="card summary-card">
                        <h3>Booking confirmation</h3>
                        <div class="booking-summary">
                            <div class="summary-row"><span>Booking reference</span><strong><?= htmlspecialchars($bookingResult['reference']) ?></strong></div>
                            <div class="summary-row"><span>Booking type</span><strong><?= htmlspecialchars(ucwords(str_replace('_', ' ', $bookingResult['booking_type']))) ?></strong></div>
                            <div class="summary-row"><span>Host name</span><strong><?= htmlspecialchars($bookingResult['host_name']) ?></strong></div>
                            <?php if (!empty($bookingResult['contact_number'])): ?>
                                <div class="summary-row"><span>Contact</span><strong><?= htmlspecialchars($bookingResult['contact_number']) ?></strong></div>
                            <?php endif; ?>
                            <div class="summary-row"><span>Date</span><strong><?= htmlspecialchars(formatDate($bookingResult['booking_date'])) ?></strong></div>
                            <div class="summary-row"><span>Time</span><strong><?= htmlspecialchars(formatTime($bookingResult['start_time']) . ' - ' . formatTime($bookingResult['end_time'])) ?></strong></div>
                            <div class="summary-row"><span>Court</span><strong><?= htmlspecialchars($bookingResult['court_name']) ?></strong></div>
                            <div class="summary-row"><span>Total players</span><strong><?= (int) $bookingResult['players_count'] ?></strong></div>
                            <div class="summary-row"><span>Player list</span><strong><?= htmlspecialchars(implode(', ', $bookingResult['players'] ?: ['Host only'])) ?></strong></div>
                            <div class="summary-row"><span>Booking fee</span><strong><?= htmlspecialchars(formatMoney($bookingResult['payment_amount'])) ?></strong></div>
                            <div class="summary-row"><span>Payment status</span><strong><?= htmlspecialchars(ucfirst($bookingResult['payment_status'] ?? 'pending')) ?></strong></div>
                        </div>
                        <div class="payment-instructions">
                            <h4><?= $bookingResult['booking_type'] === 'open_play' ? 'Open-play payment' : 'Pay with GCash' ?></h4>
                            <figure class="payment-qr">
                                <img src="assets/images/gcash-qr.jpg" alt="GCash InstaPay QR code for Pickle Trip payments" width="410" height="410" />
                                <figcaption>Scan the QR code with GCash and enter the exact booking fee shown above.</figcaption>
                            </figure>
                            <?php if ($bookingResult['booking_type'] === 'open_play' && !empty($bookingResult['payment_reference'])): ?>
                                <p>Your open-play booking was submitted after payment. Keep booking reference <strong><?= htmlspecialchars($bookingResult['reference']) ?></strong>; payment is pending manual verification.</p>
                            <?php elseif ($bookingResult['booking_type'] === 'open_play'): ?>
                                <p>Pay the exact fee first, then enter your GCash transaction number below. Keep booking reference <strong><?= htmlspecialchars($bookingResult['reference']) ?></strong>.</p>
                            <?php else: ?>
                                <p>Include booking reference <strong><?= htmlspecialchars($bookingResult['reference']) ?></strong> in your payment message. Bookings are reserved immediately; payments are manually verified.</p>
                            <?php endif; ?>
                            <?php if (GCASH_NUMBER !== ''): ?>
                                <div class="summary-row">
                                    <span>GCash number</span>
                                    <strong><?= htmlspecialchars(GCASH_NUMBER) ?></strong>
                                </div>
                                <button type="button" class="small-button" data-copy-text="<?= htmlspecialchars(GCASH_NUMBER) ?>">Copy GCash number</button>
                            <?php endif; ?>
                            <?php if (($bookingResult['payment_status'] ?? 'pending') === 'paid'): ?>
                                <div class="alert success">Payment verified by the venue.</div>
                            <?php elseif (!empty($bookingResult['payment_reference'])): ?>
                                <p class="muted-note">GCash transaction number submitted: <strong><?= htmlspecialchars($bookingResult['payment_reference']) ?></strong>. Waiting for admin verification.</p>
                            <?php else: ?>
                                <form method="post" class="payment-reference-form">
                                    <input type="hidden" name="csrf_token" value="<?= htmlspecialchars($csrf) ?>" />
                                    <input type="hidden" name="action" value="submit_payment_reference" />
                                    <input type="hidden" name="booking_reference" value="<?= htmlspecialchars($bookingResult['reference']) ?>" />
                                    <div class="form-field">
                                        <label for="payment_reference">GCash transaction number</label>
                                        <input id="payment_reference" type="text" name="payment_reference" minlength="5" maxlength="40" pattern="[A-Za-z0-9-]{5,40}" required />
                                    </div>
                                    <button type="submit" class="button-primary">Submit payment reference</button>
                                </form>
                            <?php endif; ?>
                        </div>
                        <div class="form-actions">
                            <button type="button" class="button-primary" data-print-booking>Print Booking</button>
                        </div>
                    </div>
                </div>
            </section>
        <?php endif; ?>

        <section class="section" id="about" data-tab-panel="about" <?= $initialTab !== 'about' ? 'hidden' : '' ?>>
            <div class="container grid-two">
                <div class="card form-card">
                    <h3>About Pickle Trip</h3>
                    <p class="muted-note">Pickle Trip was built to help players find and book pickleball sessions quickly, comfortably, and without friction. Whether you're joining open play or reserving a private court, the experience is streamlined and designed for community play.</p>
                </div>
                <div class="card form-card" id="contact">
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

    <footer class="site-footer">
        <div class="container footer-inner">
            <span>© 2026 Pickle Trip</span>
            <span>Play. Book. Enjoy!</span>
            <a href="admin/login.php">Admin login</a>
        </div>
    </footer>

    <script src="assets/js/main.js?v=8"></script>
</body>
</html>
