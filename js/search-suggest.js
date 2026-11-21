/**
 * @file
 * Suggestions under the WahoSearch box while the visitor types.
 *
 * A combobox as described in the ARIA Authoring Practices: arrow keys move in
 * the list, Enter picks, Escape closes. Picking a search fills the box and
 * searches; picking a page title opens the page. The box works the same
 * without this script.
 */
((Drupal, once) => {
  const MIN = 2;
  let counter = 0;

  function setUp(form) {
    const url = form.dataset.wahosearchSuggest;
    const input = form.querySelector('input[name="q"]');
    const field = input && input.parentElement;
    if (!url || !field) {
      return;
    }
    counter += 1;
    const listId = `wahosearch-suggestions-${counter}`;
    const list = document.createElement('ul');
    list.id = listId;
    list.className = 'wahosearch-suggestions';
    list.setAttribute('role', 'listbox');
    list.setAttribute('aria-label', Drupal.t('Suggestions'));
    list.hidden = true;
    field.appendChild(list);

    input.setAttribute('role', 'combobox');
    input.setAttribute('aria-autocomplete', 'list');
    input.setAttribute('aria-controls', listId);
    input.setAttribute('aria-expanded', 'false');

    let items = [];
    let active = -1;
    let request = null;
    let lastText = '';

    function close() {
      list.hidden = true;
      list.replaceChildren();
      items = [];
      active = -1;
      input.setAttribute('aria-expanded', 'false');
      input.removeAttribute('aria-activedescendant');
    }

    function choose(index) {
      const item = items[index];
      if (!item) {
        return;
      }
      if (item.url) {
        window.location.href = item.url;
        return;
      }
      input.value = item.text;
      close();
      form.requestSubmit ? form.requestSubmit() : form.submit();
    }

    function highlight(index) {
      const options = list.querySelectorAll('[role="option"]');
      options.forEach((option, i) => {
        option.setAttribute('aria-selected', i === index ? 'true' : 'false');
      });
      active = index;
      if (index >= 0 && options[index]) {
        input.setAttribute('aria-activedescendant', options[index].id);
        options[index].scrollIntoView({ block: 'nearest' });
      }
      else {
        input.removeAttribute('aria-activedescendant');
      }
    }

    function show(suggestions) {
      close();
      items = suggestions;
      if (!items.length) {
        return;
      }
      items.forEach((item, i) => {
        const option = document.createElement('li');
        option.id = `${listId}-${i}`;
        option.className = 'wahosearch-suggestions__item';
        option.setAttribute('role', 'option');
        option.setAttribute('aria-selected', 'false');
        option.textContent = item.text;
        if (item.url) {
          option.classList.add('wahosearch-suggestions__item--page');
          const hint = document.createElement('span');
          hint.className = 'visually-hidden';
          hint.textContent = ` ${Drupal.t('(opens the page)')}`;
          option.appendChild(hint);
        }
        // Keeps the focus in the box, so the list stays open.
        option.addEventListener('mousedown', (event) => event.preventDefault());
        option.addEventListener('click', () => choose(i));
        list.appendChild(option);
      });
      list.hidden = false;
      input.setAttribute('aria-expanded', 'true');
      Drupal.announce(Drupal.formatPlural(items.length, '1 suggestion available.', '@count suggestions available.'));
    }

    const fetchSuggestions = Drupal.debounce(() => {
      const text = input.value.trim();
      if (text === lastText) {
        return;
      }
      lastText = text;
      if (request) {
        request.abort();
      }
      if (text.length < MIN) {
        close();
        return;
      }
      request = new AbortController();
      const address = `${url}${url.includes('?') ? '&' : '?'}q=${encodeURIComponent(text)}`;
      fetch(address, { headers: { Accept: 'application/json' }, signal: request.signal, credentials: 'same-origin' })
        .then((response) => (response.ok ? response.json() : { suggestions: [] }))
        .then((data) => {
          // Only the answer to the latest words is shown.
          if (input.value.trim() === text && document.activeElement === input) {
            show(Array.isArray(data.suggestions) ? data.suggestions.filter((item) => item && typeof item.text === 'string') : []);
          }
        })
        .catch(() => {});
    }, 200);

    input.addEventListener('input', fetchSuggestions);
    input.addEventListener('keydown', (event) => {
      if (list.hidden) {
        if (event.key === 'ArrowDown' && input.value.trim().length >= MIN) {
          lastText = '';
          fetchSuggestions();
        }
        return;
      }
      if (event.key === 'ArrowDown') {
        event.preventDefault();
        highlight(active + 1 < items.length ? active + 1 : 0);
      }
      else if (event.key === 'ArrowUp') {
        event.preventDefault();
        highlight(active > 0 ? active - 1 : items.length - 1);
      }
      else if (event.key === 'Enter' && active >= 0) {
        event.preventDefault();
        choose(active);
      }
      else if (event.key === 'Escape') {
        event.preventDefault();
        close();
      }
    });
    input.addEventListener('blur', close);
  }

  Drupal.behaviors.wahosearchSuggest = {
    attach(context) {
      once('wahosearch-suggest', 'form[data-wahosearch-suggest]', context).forEach(setUp);
    },
  };
})(Drupal, once);
