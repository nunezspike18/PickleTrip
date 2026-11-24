import React, { useEffect, useState } from 'react';
import { createRoot } from 'react-dom/client';

function getUniquePlayers(form) {
  if (!form) return 0;
  const names = Array.from(form.querySelectorAll('[name="other_players[]"]'))
    .map((input) => input.value.trim())
    .filter((name, index, names) => name && names.indexOf(name) === index);
  return names.length + 1;
}

function BookingAssistant() {
  const [state, setState] = useState({
    type: 'Open Play',
    date: '',
    court: '',
    players: 1,
    total: 0,
  });

  useEffect(() => {
    const update = () => {
      const activeForm = Array.from(document.querySelectorAll('form[data-booking-form]'))
        .find((form) => {
          const section = form.closest('[data-tab-panel]');
          const details = form.closest('details');
          return section && !section.hidden && (!details || details.open);
        });

      if (!activeForm) {
        setState((current) => ({ ...current, type: 'Choose a booking type', court: '', total: 0 }));
        return;
      }

      const typeInput = activeForm.querySelector('[name="booking_type"]');
      const isOpenPlay = typeInput?.value === 'open_play';
      const courtInput = activeForm.querySelector('[name="court_id"]');
      const courtName = courtInput?.selectedOptions[0]?.textContent?.trim() || '';
      const dateInput = activeForm.querySelector('[name="booking_date"]');
      const players = getUniquePlayers(activeForm);
      let total = 0;

      if (isOpenPlay) {
        const price = Number(activeForm.querySelector('[data-price-preview]')?.dataset.pricePerPlayer || 0);
        total = price * players;
      } else {
        const duration = Number(activeForm.querySelector('[name="rental_duration"]')?.value || 0);
        const price = Number(activeForm.querySelector('[data-price-preview]')?.dataset.pricePerHour || 0);
        total = price * duration;
      }

      setState({
        type: isOpenPlay ? 'Open Play' : 'Court Rental',
        date: dateInput?.value || '',
        court: courtName.startsWith('Select') ? '' : courtName,
        players: isOpenPlay ? players : 1,
        total,
      });
    };

    document.querySelectorAll('form[data-booking-form]').forEach((form) => {
      form.addEventListener('input', update);
      form.addEventListener('change', update);
    });
    document.querySelectorAll('[data-tab-panel], details').forEach((element) => {
      element.addEventListener('toggle', update);
    });
    document.querySelectorAll('.nav-links a, .hero-actions a').forEach((link) => {
      link.addEventListener('click', () => window.setTimeout(update, 0));
    });

    update();
    return () => {
      document.querySelectorAll('form[data-booking-form]').forEach((form) => {
        form.removeEventListener('input', update);
        form.removeEventListener('change', update);
      });
    };
  }, []);

  return (
    <div className="react-assistant card" aria-live="polite">
      <div>
        <span className="react-assistant-label">React booking assistant</span>
        <strong>{state.type}</strong>
      </div>
      <div className="react-assistant-meta">
        <span>{state.date || 'Choose date'}</span>
        <span>{state.court || 'Choose court'}</span>
        {state.type === 'Open Play' && <span>{state.players} player{state.players === 1 ? '' : 's'}</span>}
      </div>
      {state.total > 0 && (
        <strong className="react-assistant-total">
          Total: PHP {state.total.toLocaleString('en-PH', {
            minimumFractionDigits: 2,
            maximumFractionDigits: 2,
          })}
        </strong>
      )}
    </div>
  );
}

const mount = document.getElementById('react-public-assistant');
if (mount) {
  createRoot(mount).render(<BookingAssistant />);
}
