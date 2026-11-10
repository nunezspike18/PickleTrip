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

        const addButton = container.querySelector('[data-add-player]');
        if (!addButton) {
            return;
        }

        addButton.addEventListener('click', function () {
            const row = document.createElement('div');
            row.className = 'player-row';
            row.innerHTML = '<input type="text" name="other_players[]" placeholder="Player name" /> <button type="button" class="small-button" data-remove-player>Remove</button>';
            container.appendChild(row);

            row.querySelector('[data-remove-player]').addEventListener('click', function () {
                row.remove();
            });
        });
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
