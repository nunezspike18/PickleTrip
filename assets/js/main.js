document.addEventListener('DOMContentLoaded', function () {
    const bookingToast = document.querySelector('[data-booking-toast]');
    if (bookingToast) {
        const dismissToast = function () {
            bookingToast.hidden = true;
        };
        const dismissButton = bookingToast.querySelector('[data-dismiss-toast]');
        if (dismissButton) {
            dismissButton.addEventListener('click', dismissToast);
        }
        window.setTimeout(dismissToast, 8000);
    }

    const tabPanels = Array.from(document.querySelectorAll('[data-tab-panel]'));
    const navigationLinks = Array.from(document.querySelectorAll('.nav-links a'));
    const mainContent = document.querySelector('.public-site > main');

    function tabForHash(hash) {
        const id = hash.replace(/^#/, '');
        const target = id ? document.getElementById(id) : null;
        const panel = target && (target.dataset.tabPanel ? target : target.closest('[data-tab-panel]'));
        return panel ? panel.dataset.tabPanel : '';
    }

    function activateTab(tabName, updateHistory, hash) {
        if (!tabPanels.some(function (panel) { return panel.dataset.tabPanel === tabName; })) {
            return;
        }

        tabPanels.forEach(function (panel) {
            panel.hidden = panel.dataset.tabPanel !== tabName;
        });
        const activeHash = hash === undefined ? window.location.hash : hash;
        const activeLink = navigationLinks.find(function (link) {
            return activeHash && link.hash === activeHash;
        }) || navigationLinks.find(function (link) {
            return tabForHash(link.hash) === tabName;
        });
        navigationLinks.forEach(function (link) {
            const isActive = link === activeLink;
            link.classList.toggle('active', isActive);
            if (isActive) {
                link.setAttribute('aria-current', 'page');
            } else {
                link.removeAttribute('aria-current');
            }
        });
        if (mainContent) {
            mainContent.scrollTop = 0;
        }

        if (updateHistory && hash && window.location.hash !== hash) {
            window.history.pushState(null, '', hash);
        }
    }

    document.querySelectorAll('a[href^="#"]').forEach(function (link) {
        link.addEventListener('click', function (event) {
            const tabName = tabForHash(link.hash);
            if (!tabName) {
                return;
            }
            event.preventDefault();
            activateTab(tabName, true, link.hash);
        });
    });

    const initialTab = document.body.dataset.initialTab || 'home';
    const startingTab = initialTab === 'home' ? (tabForHash(window.location.hash) || initialTab) : initialTab;
    activateTab(startingTab, false, initialTab === 'home' ? window.location.hash : '');
    window.addEventListener('popstate', function () {
        activateTab(tabForHash(window.location.hash) || document.body.dataset.initialTab || 'home', false);
    });

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
            const additionalPlayers = new Set(inputs.map(function (input) {
                return input.value.trim();
            }).filter(Boolean)).size;
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

    document.querySelectorAll('[data-price-preview]').forEach(function (preview) {
        const form = preview.closest('form');
        if (!form) {
            return;
        }

        function updatePrice() {
            let amount = 0;
            if (form.querySelector('[name="booking_type"][value="open_play"]')) {
                const extraPlayers = new Set(Array.from(form.querySelectorAll('[name="other_players[]"]'))
                    .map(function (input) { return input.value.trim(); })
                    .filter(Boolean)).size;
                amount = Number(preview.dataset.pricePerPlayer) * (extraPlayers + 1);
            } else {
                const duration = Number(form.querySelector('[name="rental_duration"]').value);
                amount = Number(preview.dataset.pricePerHour) * duration;
            }
            preview.textContent = 'Booking total: PHP ' + amount.toLocaleString('en-PH', {
                minimumFractionDigits: 2,
                maximumFractionDigits: 2
            });
        }

        form.addEventListener('input', updatePrice);
        form.addEventListener('change', updatePrice);
        updatePrice();
    });

    document.querySelectorAll('[data-copy-text]').forEach(function (button) {
        button.addEventListener('click', async function () {
            try {
                await navigator.clipboard.writeText(button.dataset.copyText);
                button.textContent = 'Copied';
            } catch (error) {
                button.textContent = 'Copy unavailable — select and copy the number above';
            }
        });
    });

    const openPlayForm = document.querySelector('form[data-booking-form] input[name="booking_type"][value="open_play"]')?.form;
    if (openPlayForm) {
        const dateInput = openPlayForm.querySelector('[name="booking_date"]');
        const slotInput = openPlayForm.querySelector('[name="slot_choice"]');
        const courtInput = openPlayForm.querySelector('[name="court_id"]');
        const playerList = openPlayForm.querySelector('#open-play-player-list');
        const rosterPanel = openPlayForm.querySelector('#open-play-court-roster');
        const submitButton = openPlayForm.querySelector('button[type="submit"]');
        const rosterTitle = rosterPanel.querySelector('[data-roster-title]');
        const rosterStatus = rosterPanel.querySelector('[data-roster-status]');
        let rosterController = null;
        let updateTimeout = null;

        function updateCourtRoster() {
            window.clearTimeout(updateTimeout);
            submitButton.disabled = Boolean(courtInput.value);
            updateTimeout = window.setTimeout(async function () {
                if (rosterController) {
                    rosterController.abort();
                    rosterController = null;
                }
                rosterPanel.hidden = !courtInput.value;
                rosterPanel.hidden = !courtInput.value;

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
                        submitButton.disabled = false;
                        return;
                    }

                    const selectedCourt = result.courts.find(function (court) {
                        return String(court.id) === courtInput.value;
                    });
                    if (!selectedCourt) {
                        throw new Error('The selected court is no longer available.');
                    }
                    submitButton.disabled = !selectedCourt.available;

                    const selectedSlot = slotInput.options[slotInput.selectedIndex].text;
                    const pendingPlayers = playerNames;
                    const pendingCount = 1 + pendingPlayers.length;
                    rosterTitle.textContent = selectedCourt.name + ' — ' + selectedCourt.players_booked + '/' + selectedCourt.max_players
                        + ' booked for ' + dateInput.value + ' (' + selectedSlot + ')';
                    if (!selectedCourt.available) {
                        rosterStatus.textContent = 'Your group cannot join this court: ' + selectedCourt.message;
                    } else {
                        const projectedCount = selectedCourt.players_booked + pendingCount;
                        const spotsAfterBooking = Math.max(0, selectedCourt.max_players - projectedCount);
                        rosterStatus.textContent = spotsAfterBooking
                            + ' spot' + (spotsAfterBooking === 1 ? '' : 's') + ' will remain after your booking is confirmed.';
                    }
                } catch (error) {
                    if (error.name !== 'AbortError') {
                        submitButton.disabled = Boolean(courtInput.value);
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
