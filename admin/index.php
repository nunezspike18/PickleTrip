<?php
session_start();
require_once __DIR__ . '/../includes/functions.php';
requireAdminLogin();

$pdo = getPdo();
$page = $_GET['page'] ?? 'dashboard';

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $action = $_POST['action'] ?? '';

    if ($action === 'cancel_booking') {
        $bookingId = (int) ($_POST['booking_id'] ?? 0);
        if ($bookingId > 0) {
            $stmt = $pdo->prepare("UPDATE bookings SET status = 'cancelled', updated_at = CURRENT_TIMESTAMP WHERE id = ?");
            $stmt->execute([$bookingId]);
        }
        header('Location: /admin/index.php?page=bookings');
        exit;
    }

    if ($action === 'add_court') {
        $name = sanitizeText($_POST['name'] ?? '');
        if ($name !== '') {
            $stmt = $pdo->prepare("INSERT INTO courts (name, max_players, status, is_active) VALUES (?, 12, 'available', 1)");
            $stmt->execute([$name]);
        }
        header('Location: /admin/index.php?page=courts');
        exit;
    }

    if ($action === 'toggle_court') {
        $courtId = (int) ($_POST['court_id'] ?? 0);
        $state = (int) ($_POST['is_active'] ?? 0);
        if ($courtId > 0) {
            $stmt = $pdo->prepare("UPDATE courts SET is_active = ?, status = CASE WHEN ? = 1 THEN 'available' ELSE 'inactive' END WHERE id = ?");
            $stmt->execute([$state, $state, $courtId]);
        }
        header('Location: /admin/index.php?page=courts');
        exit;
    }
}

$stats = getDashboardStats();
$allBookings = getBookingsForAdmin($_GET['search'] ?? '', $_GET['date'] ?? '', $_GET['type'] ?? '');
$courts = getCourtList();
$players = fetchAllPlayers();
$recent = getRecentBookings(5);
?>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8" />
    <meta name="viewport" content="width=device-width, initial-scale=1.0" />
    <title>Admin Dashboard | Pickle Trip</title>
    <link rel="stylesheet" href="/assets/css/style.css" />
</head>
<body class="admin-shell">
    <div class="admin-layout">
        <aside class="admin-sidebar">
            <div class="brand">
                <span class="brand-mark">P</span>
                <span class="brand-copy">
                    <span>Pickle Trip</span>
                    <span>Admin</span>
                </span>
            </div>
            <nav class="admin-nav" aria-label="Sidebar navigation">
                <a href="/admin/index.php?page=dashboard" class="<?= $page === 'dashboard' ? 'active' : '' ?>">Dashboard Overview</a>
                <a href="/admin/index.php?page=bookings" class="<?= $page === 'bookings' ? 'active' : '' ?>">Manage Bookings</a>
                <a href="/admin/index.php?page=openplay" class="<?= $page === 'openplay' ? 'active' : '' ?>">Manage Open Play</a>
                <a href="/admin/index.php?page=rentals" class="<?= $page === 'rentals' ? 'active' : '' ?>">Manage Court Rentals</a>
                <a href="/admin/index.php?page=courts" class="<?= $page === 'courts' ? 'active' : '' ?>">Manage Courts</a>
                <a href="/admin/index.php?page=players" class="<?= $page === 'players' ? 'active' : '' ?>">Manage Players</a>
                <a href="/admin/index.php?page=reports" class="<?= $page === 'reports' ? 'active' : '' ?>">Reports</a>
                <a href="/admin/index.php?page=settings" class="<?= $page === 'settings' ? 'active' : '' ?>">Settings</a>
                <a href="/admin/logout.php">Logout</a>
            </nav>
        </aside>

        <main class="admin-main">
            <div class="admin-topbar">
                <h2>
                    <?php
                    $pageTitle = [
                        'dashboard' => 'Dashboard Overview',
                        'bookings' => 'Manage Bookings',
                        'openplay' => 'Manage Open Play',
                        'rentals' => 'Manage Court Rentals',
                        'courts' => 'Manage Courts',
                        'players' => 'Manage Players',
                        'reports' => 'Reports',
                        'settings' => 'Settings',
                    ];
                    echo htmlspecialchars($pageTitle[$page] ?? 'Dashboard Overview');
                    ?>
                </h2>
                <div class="status-pill available">Logged in as <?= htmlspecialchars($_SESSION['admin_full_name'] ?? 'Admin') ?></div>
            </div>

            <?php if ($page === 'dashboard' || $page === 'openplay' || $page === 'rentals'): ?>
                <div class="stats-grid">
                    <div class="card stat-card"><div class="stat-label">Total Bookings</div><div class="stat-value"><?= (int) $stats['totalBookings'] ?></div></div>
                    <div class="card stat-card"><div class="stat-label">Total Players</div><div class="stat-value"><?= (int) $stats['totalPlayers'] ?></div></div>
                    <div class="card stat-card"><div class="stat-label">Available Courts</div><div class="stat-value"><?= (int) $stats['availableCourts'] ?></div></div>
                    <div class="card stat-card"><div class="stat-label">Fully Booked Courts</div><div class="stat-value"><?= (int) $stats['fullyBookedCourts'] ?></div></div>
                </div>
            <?php endif; ?>

            <?php if ($page === 'dashboard'): ?>
                <div class="section" style="padding-top: 28px; padding-bottom: 0;">
                    <div class="grid-two">
                        <div class="card form-card">
                            <h3>Recent bookings</h3>
                            <div class="table-wrap">
                                <table>
                                    <thead>
                                        <tr>
                                            <th>Ref</th>
                                            <th>Type</th>
                                            <th>Court</th>
                                            <th>Host</th>
                                        </tr>
                                    </thead>
                                    <tbody>
                                        <?php foreach ($recent as $booking): ?>
                                            <tr>
                                                <td><?= htmlspecialchars($booking['booking_reference']) ?></td>
                                                <td><?= htmlspecialchars(ucwords(str_replace('_', ' ', $booking['booking_type']))) ?></td>
                                                <td><?= htmlspecialchars($booking['court_name'] ?? 'Court') ?></td>
                                                <td><?= htmlspecialchars($booking['host_name']) ?></td>
                                            </tr>
                                        <?php endforeach; ?>
                                    </tbody>
                                </table>
                            </div>
                        </div>
                        <div class="card form-card">
                            <h3>Court availability</h3>
                            <?php foreach ($courts as $court): ?>
                                <div class="summary-row">
                                    <span><?= htmlspecialchars($court['name']) ?></span>
                                    <strong><?= getCourtPlayers((int) $court['id'], date('Y-m-d')) ?>/<?= (int) $court['max_players'] ?></strong>
                                </div>
                            <?php endforeach; ?>
                        </div>
                    </div>
                </div>
            <?php endif; ?>

            <?php if ($page === 'bookings' || $page === 'openplay' || $page === 'rentals'): ?>
                <div class="card form-card" style="margin-top: 24px;">
                    <form method="get" style="display: flex; gap: 12px; flex-wrap: wrap; align-items: end;">
                        <input type="hidden" name="page" value="bookings" />
                        <div class="form-field">
                            <label>Search</label>
                            <input type="text" name="search" value="<?= htmlspecialchars($_GET['search'] ?? '') ?>" placeholder="Booking ID or name" />
                        </div>
                        <div class="form-field">
                            <label>Date</label>
                            <input type="date" name="date" value="<?= htmlspecialchars($_GET['date'] ?? '') ?>" />
                        </div>
                        <div class="form-field">
                            <label>Type</label>
                            <select name="type">
                                <option value="">All</option>
                                <option value="open_play" <?= ($_GET['type'] ?? '') === 'open_play' ? 'selected' : '' ?>>Open Play</option>
                                <option value="court_rental" <?= ($_GET['type'] ?? '') === 'court_rental' ? 'selected' : '' ?>>Court Rental</option>
                            </select>
                        </div>
                        <button type="submit" class="button-primary">Apply</button>
                    </form>
                </div>
                <div class="card form-card" style="margin-top: 24px;">
                    <div class="table-wrap">
                        <table>
                            <thead>
                                <tr>
                                    <th>ID</th>
                                    <th>Ref</th>
                                    <th>Type</th>
                                    <th>Host</th>
                                    <th>Date</th>
                                    <th>Time</th>
                                    <th>Court</th>
                                    <th>Players</th>
                                    <th>Status</th>
                                    <th>Actions</th>
                                </tr>
                            </thead>
                            <tbody>
                                <?php foreach ($allBookings as $booking): ?>
                                    <tr>
                                        <td><?= (int) $booking['id'] ?></td>
                                        <td><?= htmlspecialchars($booking['booking_reference']) ?></td>
                                        <td><?= htmlspecialchars(ucwords(str_replace('_', ' ', $booking['booking_type']))) ?></td>
                                        <td><?= htmlspecialchars($booking['host_name']) ?></td>
                                        <td><?= htmlspecialchars(formatDate($booking['booking_date'])) ?></td>
                                        <td><?= htmlspecialchars(formatTime($booking['start_time']) . ' - ' . formatTime($booking['end_time'])) ?></td>
                                        <td><?= htmlspecialchars($booking['court_name'] ?? 'Court') ?></td>
                                        <td><?= (int) $booking['players_count'] ?></td>
                                        <td><span class="status-pill <?= $booking['status'] === 'cancelled' ? 'full' : 'available' ?>"><?= htmlspecialchars($booking['status']) ?></span></td>
                                        <td>
                                            <div class="table-actions">
                                                <form method="post" style="display:inline;">
                                                    <input type="hidden" name="action" value="cancel_booking" />
                                                    <input type="hidden" name="booking_id" value="<?= (int) $booking['id'] ?>" />
                                                    <button type="submit" class="button-danger">Cancel</button>
                                                </form>
                                            </div>
                                        </td>
                                    </tr>
                                <?php endforeach; ?>
                            </tbody>
                        </table>
                    </div>
                </div>
            <?php endif; ?>

            <?php if ($page === 'courts'): ?>
                <div class="card form-card" style="margin-top: 24px;">
                    <h3>Add court</h3>
                    <form method="post">
                        <input type="hidden" name="action" value="add_court" />
                        <div class="form-grid" style="grid-template-columns: 1fr 180px; align-items: end;">
                            <div class="form-field">
                                <label for="court_name">Court name</label>
                                <input id="court_name" type="text" name="name" placeholder="Court 5" required />
                            </div>
                            <button type="submit" class="button-primary">Add court</button>
                        </div>
                    </form>
                </div>
                <div class="card form-card" style="margin-top: 24px;">
                    <div class="table-wrap">
                        <table>
                            <thead>
                                <tr>
                                    <th>ID</th>
                                    <th>Name</th>
                                    <th>Capacity</th>
                                    <th>Status</th>
                                    <th>Toggle</th>
                                </tr>
                            </thead>
                            <tbody>
                                <?php foreach ($courts as $court): ?>
                                    <tr>
                                        <td><?= (int) $court['id'] ?></td>
                                        <td><?= htmlspecialchars($court['name']) ?></td>
                                        <td><?= (int) $court['max_players'] ?></td>
                                        <td><span class="status-pill available"><?= htmlspecialchars($court['status']) ?></span></td>
                                        <td>
                                            <form method="post" style="display:inline;">
                                                <input type="hidden" name="action" value="toggle_court" />
                                                <input type="hidden" name="court_id" value="<?= (int) $court['id'] ?>" />
                                                <input type="hidden" name="is_active" value="<?= (int) ($court['is_active'] == 1 ? 0 : 1) ?>" />
                                                <button type="submit" class="small-button"><?= (int) $court['is_active'] === 1 ? 'Deactivate' : 'Activate' ?></button>
                                            </form>
                                        </td>
                                    </tr>
                                <?php endforeach; ?>
                            </tbody>
                        </table>
                    </div>
                </div>
            <?php endif; ?>

            <?php if ($page === 'players'): ?>
                <div class="card form-card" style="margin-top: 24px;">
                    <div class="table-wrap">
                        <table>
                            <thead>
                                <tr>
                                    <th>Booking</th>
                                    <th>Host</th>
                                    <th>Type</th>
                                    <th>Court</th>
                                    <th>Date</th>
                                    <th>Players</th>
                                </tr>
                            </thead>
                            <tbody>
                                <?php foreach ($players as $player): ?>
                                    <tr>
                                        <td><?= htmlspecialchars($player['booking_reference']) ?></td>
                                        <td><?= htmlspecialchars($player['host_name']) ?></td>
                                        <td><?= htmlspecialchars(ucwords(str_replace('_', ' ', $player['booking_type']))) ?></td>
                                        <td><?= htmlspecialchars($player['court_name'] ?? 'Court') ?></td>
                                        <td><?= htmlspecialchars(formatDate($player['booking_date'])) ?></td>
                                        <td><?= htmlspecialchars($player['player_names'] !== '' ? $player['player_names'] : 'Host only') ?></td>
                                    </tr>
                                <?php endforeach; ?>
                            </tbody>
                        </table>
                    </div>
                </div>
            <?php endif; ?>

            <?php if ($page === 'reports'): ?>
                <div class="card report-box" style="margin-top: 24px;">
                    <h3>Admin report snapshot</h3>
                    <div class="summary-row"><span>Total bookings</span><strong><?= (int) $stats['totalBookings'] ?></strong></div>
                    <div class="summary-row"><span>Open play reservations</span><strong><?= (int) $stats['openPlayReservations'] ?></strong></div>
                    <div class="summary-row"><span>Court rental reservations</span><strong><?= (int) $stats['courtRentalReservations'] ?></strong></div>
                    <div class="summary-row"><span>Today's bookings</span><strong><?= (int) $stats['todaysBookings'] ?></strong></div>
                    <div class="form-actions">
                        <button type="button" class="button-primary" onclick="window.print()">Print report</button>
                    </div>
                </div>
            <?php endif; ?>

            <?php if ($page === 'settings'): ?>
                <div class="card form-card" style="margin-top: 24px;">
                    <h3>Settings</h3>
                    <p class="muted-note">Security is enforced with PHP sessions and password hashing. The default admin login is username: <strong>admin</strong> and password: <strong>admin123</strong>.</p>
                </div>
            <?php endif; ?>
        </main>
    </div>
</body>
</html>
