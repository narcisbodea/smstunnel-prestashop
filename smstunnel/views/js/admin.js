/**
 * SMSTunnel: SMS notifications for PrestaShop.
 *
 * @author    Narcis Bodea <contact@narboweb.ro>
 * @copyright 2026 NARBOWEB SRL
 * @license   https://opensource.org/licenses/MIT MIT License
 */
document.addEventListener('DOMContentLoaded', function () {
  // The browser must not fill the back office password into the API key field.
  var key = document.getElementById('SMSTUNNEL_API_KEY');
  if (key) {
    key.setAttribute('autocomplete', 'new-password');
    key.setAttribute('placeholder', 'sk_live_...');
  }

  // Show the text of a status only while its box is ticked.
  var boxes = document.querySelectorAll('input[type="checkbox"][name^="SMSTUNNEL_STATES_"]');
  Array.prototype.forEach.call(boxes, function (box) {
    var id = box.name.substring('SMSTUNNEL_STATES_'.length);
    var groups = document.querySelectorAll('.smstunnel-state-' + id);
    var sync = function () {
      Array.prototype.forEach.call(groups, function (group) {
        group.style.display = box.checked ? '' : 'none';
      });
    };
    box.addEventListener('change', sync);
    sync();
  });
});
