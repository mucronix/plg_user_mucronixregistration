/**
 * @package     Mucronix Registration
 * @subpackage  plg_user_mucronixregistration
 *
 * @copyright   (C) 2026 Mucronix
 * @license     GNU General Public License version 2 or later; see LICENSE.txt
 *
 * Country -> region lists. The server already builds the region list for the
 * country in the form; this script only reloads it when the country changes
 * and hides the region field, with its label, when there is nothing to choose.
 * Without the script the form still works: the server checks the region.
 */
((document, Joomla) => {
  'use strict';

  const init = () => {
    const country = document.getElementById('jform_mucronixregistration_country');
    const region = document.getElementById('jform_mucronixregistration_region');
    const options = Joomla && Joomla.getOptions ? Joomla.getOptions('plg_user_mucronixregistration', {}) : {};

    if (!country || !region || !options.url) {
      return;
    }

    const wrapper = region.closest('.control-group') || region.parentNode;
    const label = document.getElementById(`${region.id}-lbl`);
    const placeholder = region.querySelector('option[value=""]');
    const placeholderText = placeholder ? placeholder.textContent : '';
    let request = 0;

    // The same mark the server puts on the label of a required field
    // (layouts/joomla/form/renderlabel.php).
    const markLabel = (required) => {
      if (!label) {
        return;
      }

      const star = label.querySelector('.star');

      label.classList.toggle('required', required);

      if (required && !star) {
        const mark = document.createElement('span');
        mark.className = 'star';
        mark.setAttribute('aria-hidden', 'true');
        mark.textContent = ' *';
        label.appendChild(mark);
      } else if (!required && star) {
        star.remove();
      }
    };

    const show = (visible) => {
      const required = visible && Boolean(options.required);

      wrapper.hidden = !visible;

      // A hidden required field would block the form in the browser.
      region.required = required;
      region.classList.toggle('required', required);
      markLabel(required);
    };

    const fill = (list) => {
      region.replaceChildren();

      const first = document.createElement('option');
      first.value = '';
      first.textContent = placeholderText;
      region.appendChild(first);

      list.forEach((item) => {
        const option = document.createElement('option');
        option.value = item.code;
        option.textContent = item.name;
        region.appendChild(option);
      });

      show(list.length > 0);
    };

    const load = (code) => {
      const current = ++request;

      if (!code) {
        fill([]);
        return;
      }

      region.disabled = true;

      fetch(`${options.url}&country=${encodeURIComponent(code)}`, {
        headers: { 'X-Requested-With': 'XMLHttpRequest' },
      })
        .then((response) => {
          if (!response.ok) {
            throw new Error(`HTTP ${response.status}`);
          }

          return response.json();
        })
        .then((json) => {
          if (current !== request) {
            return;
          }

          // com_ajax wraps the result of each plugin into an array.
          const data = json && Array.isArray(json.data) ? json.data : [];
          const list = Array.isArray(data[0]) ? data[0] : [];

          fill(list);
        })
        .catch(() => {
          // The server still checks the region and asks for it if needed.
          if (current === request) {
            fill([]);
          }
        })
        .finally(() => {
          if (current === request) {
            region.disabled = false;
          }
        });
    };

    country.addEventListener('change', () => load(country.value));

    // Keep what the server built: it may contain a saved region that is no
    // longer offered, and it must stay selected.
    show(region.querySelectorAll('option:not([value=""])').length > 0);
  };

  if (document.readyState === 'loading') {
    document.addEventListener('DOMContentLoaded', init);
  } else {
    init();
  }
})(document, window.Joomla);
