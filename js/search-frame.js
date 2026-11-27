/**
 * @file
 * Grows the hosted search frame to the height of its results.
 */
((Drupal, once) => {
  Drupal.behaviors.wahosearchFrame = {
    attach(context) {
      once('wahosearch-frame', 'iframe[data-wahosearch-origin]', context).forEach((frame) => {
        window.addEventListener('message', (event) => {
          if (event.origin !== frame.dataset.wahosearchOrigin || event.source !== frame.contentWindow) {
            return;
          }
          const data = event.data || {};
          const height = Number(data.height);
          if (data.wahosearch === 'height' && height > 0 && height < 20000) {
            frame.style.height = `${Math.ceil(height)}px`;
          }
        });
      });
    },
  };
})(Drupal, once);
