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
        const courtInput = openPlayForm.querySelector('[name="court_id"]');
        const playerList = openPlayForm.querySelector('#open-play-player-list');
        const rosterPanel = openPlayForm.querySelector('#open-play-court-roster');
        const rosterTitle = rosterPanel.querySelector('[data-roster-title]');
        const rosterStatus = rosterPanel.querySelector('[data-roster-status]');
        const rosterPlayers = rosterPanel.querySelector('[data-roster-players]');
        let rosterController = null;
        let updateTimeout = null;

        function updateCourtRoster() {
            window.clearTimeout(updateTimeout);
            updateTimeout = window.setTimeout(async function () {
                if (rosterController) {
                    rosterController.abort();
                    rosterController = null;
                }

                rosterPanel.hidden = !courtInput.value;
                rosterPlayers.replaceChildren();

                if (!dateInput.value || !slotInput.value) {
                    Array.from(courtInput.options).forEach(function (option) {
                        if (option.value) {
                            option.textContent = option.dataset.courtName + ' — choose date and time';
                        }
                    });
                    if (courtInput.value) {
                        rosterTitle.textContent = courtInput.options[courtInput.selectedIndex].dataset.courtName;
                        rosterStatus.textContent = 'Choose a booking date and time slot to see who has joined this court.';
                    }
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

                rosterController = new AbortController();
                if (courtInput.value) {
                    rosterStatus.textContent = 'Loading players for this court...';
                }

                try {
                    const response = await fetch('availability.php?' + params.toString(), {
                        headers: { 'Accept': 'application/json' },
                        cache: 'no-store',
                        signal: rosterController.signal
                    });
                    const result = await response.json();
                    if (!response.ok) {
                        throw new Error(result.error || 'Could not load players for this court.');
                    }

                    result.courts.forEach(function (court) {
                        const option = courtInput.querySelector('option[value="' + court.id + '"]');
                        if (option) {
                            const pendingSuffix = playersCount > 1 ? ' (+' + playersCount + ' in your booking)' : '';
                            option.textContent = court.name + ' — ' + court.players_booked + '/' + court.max_players + ' booked' + pendingSuffix;
                        }
                    });

                    if (!courtInput.value) {
                        return;
                    }

                    const selectedCourt = result.courts.find(function (court) {
                        return String(court.id) === courtInput.value;
                    });
                    if (!selectedCourt) {
                        throw new Error('The selected court is no longer available.');
                    }

                    const selectedSlot = slotInput.options[slotInput.selectedIndex].text;
                    const pendingHost = (openPlayForm.querySelector('[name="host_name"]')?.value || '').trim();
                    const pendingPlayers = playerNames;
                    const pendingCount = pendingHost || pendingPlayers.length
                        ? 1 + playerNames.length
                        : 0;
                    rosterTitle.textContent = selectedCourt.name + ' — ' + selectedCourt.players_booked + '/' + selectedCourt.max_players
                        + ' booked for ' + dateInput.value + ' (' + selectedSlot + ')';
                    rosterStatus.textContent = selectedCourt.players.length
                        ? selectedCourt.players_remaining + ' spot' + (selectedCourt.players_remaining === 1 ? '' : 's') + ' remaining. ' + selectedCourt.message
                        : 'No players booked for this court and time yet. ' + selectedCourt.message;

                    selectedCourt.players.forEach(function (name) {
                        const item = document.createElement('li');
                        item.textContent = name;
                        rosterPlayers.appendChild(item);
                    });
                    if (pendingCount > 0) {
                        const pendingHeading = document.createElement('li');
                        pendingHeading.textContent = 'Your booking (not yet confirmed):';
                        rosterPlayers.appendChild(pendingHeading);
                        const hostItem = document.createElement('li');
                        hostItem.textContent = pendingHost || 'Booking host';
                        rosterPlayers.appendChild(hostItem);
                        pendingPlayers.forEach(function (name) {
                            const item = document.createElement('li');
                            item.textContent = name;
                            rosterPlayers.appendChild(item);
                        });
                        if (!selectedCourt.available) {
                            rosterStatus.textContent = 'Your group cannot join this court: ' + selectedCourt.message;
                        } else {
                            const projectedCount = selectedCourt.players_booked + pendingCount;
                            const spotsAfterBooking = Math.max(0, selectedCourt.max_players - projectedCount);
                            rosterStatus.textContent = spotsAfterBooking
                                + ' spot' + (spotsAfterBooking === 1 ? '' : 's') + ' will remain after your booking is confirmed.';
                        }
                    } else if (!selectedCourt.players.length) {
                        const item = document.createElement('li');
                        item.textContent = 'Be the first to join!';
                        rosterPlayers.appendChild(item);
                    }
                } catch (error) {
                    if (error.name !== 'AbortError') {
                        if (courtInput.value) {
                            rosterStatus.textContent = 'Players could not be loaded. Please try again.';
                        }
                    }
                }
            }, 200);
        }

        [dateInput, slotInput, courtInput].forEach(function (input) {
            input.addEventListener('change', updateCourtRoster);
        });
        openPlayForm.querySelector('[name="host_name"]').addEventListener('input', updateCourtRoster);
        openPlayForm.querySelector('[data-add-player]').addEventListener('click', function () {
            window.setTimeout(updateCourtRoster, 0);
        });
        playerList.addEventListener('input', updateCourtRoster);
        playerList.addEventListener('click', function (event) {
            if (event.target.closest('[data-remove-player]')) {
                window.setTimeout(updateCourtRoster, 0);
            }
        });
        updateCourtRoster();
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
