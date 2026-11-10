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
