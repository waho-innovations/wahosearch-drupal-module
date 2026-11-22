/**
 * @file
 * Tells WahoSearch which search result a visitor opened, for the reports.
 *
 * Only the page address, its place in the list and the reference of the
 * search are sent, to this website (/wahosearch/click), which passes them on.
 */
((Drupal, once) => {
  function opened(list, event) {
    const link = event.target.closest('a[data-wahosearch-position]');
    if (!link || (event.type === 'auxclick' && event.button !== 1) || !navigator.sendBeacon) {
      return;
    }
    navigator.sendBeacon(list.dataset.wahosearchClickUrl, JSON.stringify({
      ref: list.dataset.wahosearchClick,
      url: link.href,
      position: parseInt(link.dataset.wahosearchPosition, 10) || 0,
    }));
  }

  Drupal.behaviors.wahosearchClick = {
    attach(context) {
      once('wahosearch-click', '[data-wahosearch-click]', context).forEach((list) => {
        list.addEventListener('click', (event) => opened(list, event));
        list.addEventListener('auxclick', (event) => opened(list, event));
      });
    },
  };
})(Drupal, once);
