/*
 * Board scope: how far past the exact selected page the board looks.
 *
 * Unlike the assignee/status/workspace filters (client-side show/hide of already-
 * rendered cards, see filters.js), depth and "from workspace root" change what
 * ContentFlowController::buildBoard() queries server-side - so a change here
 * reloads the module with new `depth`/`wsroot` query params, the same way core's
 * own Page/List module depth selector works.
 */
export function registerScopeControls() {
  const depthSelect = document.querySelector('#cf-depth');
  const rootCheckbox = document.querySelector('#cf-wsroot');
  if (!depthSelect || !rootCheckbox) {
    return;
  }

  const settings = TYPO3.settings.ContentFlow || {};
  depthSelect.value = String(settings.depth || 0);
  rootCheckbox.checked = Boolean(settings.fromWorkspaceRoot);
  depthSelect.hidden = rootCheckbox.checked;

  const reloadWithScope = () => {
    const url = new URL(window.location.href);
    if (rootCheckbox.checked) {
      url.searchParams.set('wsroot', '1');
      url.searchParams.delete('depth');
    } else {
      url.searchParams.delete('wsroot');
      url.searchParams.set('depth', depthSelect.value);
    }
    window.location.href = url.toString();
  };

  rootCheckbox.addEventListener('change', () => {
    depthSelect.hidden = rootCheckbox.checked;
    reloadWithScope();
  });
  depthSelect.addEventListener('change', reloadWithScope);
}
