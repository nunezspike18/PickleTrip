document.addEventListener('DOMContentLoaded', function () {
    const minDate = new Date();
    minDate.setHours(0, 0, 0, 0);

    document.querySelectorAll('input[type="date"]').forEach(function (input) {
        input.min = minDate.toISOString().split('T')[0];
    });

    function attachPlayerAdder(containerId) {
        const container = document.getElementById(containerId);
        if (!container) {
            return;
        }

        const form = container.closest('form');
        const addButton = form ? form.querySelector('[data-add-player]') : null;
        const countLabel = form ? form.querySelector('[data-player-count]') : null;
        if (!form || !addButton || !countLabel) {
            return;
        }

        function updatePlayerCount() {
            const inputs = Array.from(container.querySelectorAll('input[name="other_players[]"]'));
            const additionalPlayers = inputs.filter(function (input) {
                return input.value.trim() !== '';
            }).length;
            const totalPlayers = additionalPlayers + 1;
            countLabel.textContent = 'Players in this booking: ' + totalPlayers + ' / 12';
            addButton.disabled = totalPlayers >= 12 || inputs.length >= 11;
        }

        addButton.addEventListener('click', function () {
            if (addButton.disabled) {
                return;
            }

            const row = document.createElement('div');
            row.className = 'player-row';
            const input = document.createElement('input');
            input.type = 'text';
            input.name = 'other_players[]';
            input.placeholder = 'Player name';
            input.setAttribute('aria-label', 'Additional player name');

            const removeButton = document.createElement('button');
            removeButton.type = 'button';
            removeButton.className = 'small-button';
            removeButton.dataset.removePlayer = '';
            removeButton.textContent = 'Remove';

            row.append(input, removeButton);
            container.appendChild(row);
            input.addEventListener('input', updatePlayerCount);
            input.focus();
            updatePlayerCount();
        });

        container.addEventListener('click', function (event) {
            const removeButton = event.target.closest('[data-remove-player]');
            if (removeButton) {
                removeButton.closest('.player-row').remove();
                updatePlayerCount();
            }
        });

        container.querySelectorAll('input[name="other_players[]"]').forEach(function (input) {
            input.addEventListener('input', updatePlayerCount);
        });
        updatePlayerCount();
    }

    attachPlayerAdder('open-play-player-list');
    attachPlayerAdder('rental-player-list');

    const openPlayForm = document.querySelector('form[data-booking-form] input[name="booking_type"][value="open_play"]')?.form;
    if (openPlayForm) {
        const dateInput = openPlayForm.querySelector('[name="booking_date"]');
        const slotInput = openPlayForm.querySelector('[name="slot_choice"]');
        const playerList = openPlayForm.querySelector('#open-play-player-list');
        const context = document.querySelector('[data-availability-context]');
        let availabilityController = null;
        let updateTimeout = null;

        function updateCourtAvailability() {
            window.clearTimeout(updateTimeout);
            updateTimeout = window.setTimeout(async function () {
                if (!dateInput.value || !slotInput.value) {
                    if (context) {
                        context.textContent = 'Choose a booking date and time slot to check availability for your player list.';
                    }
                    document.querySelectorAll('[data-availability-court]').forEach(function (row) {
                        const status = row.querySelector('[data-court-status]');
                        const detail = row.querySelector('[data-court-detail]');
                        if (status) {
                            status.textContent = 'Choose a slot';
                            status.className = 'court-availability-status';
                        }
                        if (detail) {
                            detail.textContent = 'Select a date and time';
                        }
                    });
                    return;
                }

                const playerNames = Array.from(playerList.querySelectorAll('input[name="other_players[]"]'))
                    .map(function (input) { return input.value.trim(); })
                    .filter(function (name, index, names) { return name !== '' && names.indexOf(name) === index; });
                const playersCount = playerNames.length + 1;
                const params = new URLSearchParams({
                    booking_date: dateInput.value,
                    slot_choice: slotInput.value,
                    players_count: String(playersCount)
                });

                if (availabilityController) {
                    availabilityController.abort();
                }
                availabilityController = new AbortController();
                if (context) {
                    context.textContent = 'Checking courts for ' + playersCount + (playersCount === 1 ? ' player...' : ' players...');
                }

                try {
                    const response = await fetch('availability.php?' + params.toString(), {
                        headers: { 'Accept': 'application/json' },
                        cache: 'no-store',
                        signal: availabilityController.signal
                    });
                    const result = await response.json();
                    if (!response.ok) {
                        throw new Error(result.error || 'Could not check court availability.');
                    }

                    result.courts.forEach(function (court) {
                        const row = document.querySelector('[data-availability-court="' + court.id + '"]');
                        if (!row) {
                            return;
                        }
                        const status = row.querySelector('[data-court-status]');
                        const detail = row.querySelector('[data-court-detail]');
                        if (status) {
                            status.textContent = court.available ? 'Available' : 'Unavailable';
                            status.className = 'court-availability-status ' + (court.available ? 'is-available' : 'is-unavailable');
                        }
                        if (detail) {
                            detail.textContent = court.available
                                ? court.players_remaining + ' spot' + (court.players_remaining === 1 ? '' : 's') + ' left'
                                : court.message;
                        }
                    });

                    const availableCount = result.courts.filter(function (court) { return court.available; }).length;
                    if (context) {
                        context.textContent = availableCount
                            ? availableCount + ' court' + (availableCount === 1 ? '' : 's') + ' available for ' + playersCount + (playersCount === 1 ? ' player.' : ' players.')
                            : 'No courts available for ' + playersCount + (playersCount === 1 ? ' player.' : ' players.') + ' at this time.';
                    }
                } catch (error) {
                    if (error.name !== 'AbortError' && context) {
                        context.textContent = 'Availability could not be checked. Please try again.';
                    }
                }
            }, 200);
        }

        [dateInput, slotInput].forEach(function (input) {
            input.addEventListener('change', updateCourtAvailability);
        });
        openPlayForm.querySelector('[data-add-player]').addEventListener('click', function () {
            window.setTimeout(updateCourtAvailability, 0);
        });
        playerList.addEventListener('input', updateCourtAvailability);
        playerList.addEventListener('click', function (event) {
            if (event.target.closest('[data-remove-player]')) {
                window.setTimeout(updateCourtAvailability, 0);
            }
        });
        updateCourtAvailability();
    }

    document.querySelectorAll('[data-print-booking]').forEach(function (button) {
        button.addEventListener('click', function () {
            window.print();
        });
    });

    const forms = document.querySelectorAll('form[data-booking-form]');
    forms.forEach(function (form) {
        form.addEventListener('submit', function () {
            const hostName = form.querySelector('[name="host_name"]') || form.querySelector('[name="renter_name"]');
            const contact = form.querySelector('[name="contact_number"]');
            const court = form.querySelector('[name="court_id"]');
            const date = form.querySelector('[name="booking_date"]');
            const startTime = form.querySelector('[name="start_time"]');

            if (!hostName || !contact || !court || !date || !startTime) {
                return;
            }

            if (hostName.value.trim() === '' || contact.value.trim() === '') {
                return;
            }
        });
    });
});
