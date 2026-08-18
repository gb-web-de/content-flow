/*
 * Moving a record between tasks: split it into a task of its own, or move it
 * onto another open one.
 *
 * Both rest on one invariant - a record belongs to at most one open task - and
 * both are membership changes only. The workspace version hangs on the RECORD,
 * never on the task (see ARCHITECTURE.md, "What a task is about"), so nothing
 * an editor has typed is at stake here; the server re-points one row. Every
 * text below says so, because the one thing an editor will hesitate over is
 * exactly that question. Throwing changes away is a different button
 * (member-actions.js' discard), and it is red.
 *
 * Two layers on purpose, because the three surfaces are reachable differently:
 *
 *   - openSplitDialog()/openMoveDialog()/moveToTask() are called directly. The
 *     Visual Editor needs this: its bubbles live inside the rendered frontend
 *     iframe, one document below the backend chrome, where a delegated listener
 *     on the top document never sees a click.
 *   - registerMembershipActions() delegates from the top document for markup
 *     that only has to carry data attributes - the ticket modal's member list
 *     and the page module's element badge.
 */
import AjaxRequest from '@typo3/core/ajax/ajax-request.js';
import Notification from '@typo3/backend/notification.js';
import Modal from '@typo3/backend/modal.js';
import { SeverityEnum } from '@typo3/backend/enum/severity.js';
import { topDocument } from '@gb-web/content-flow/dom-scope.js';
import labels from '~labels/content_flow.messages';
import '@gb-web/content-flow/components/assignee-picker.js';

const NOTIFICATION_TITLE = 'Content Flow';

/*
 * Reloading is the right default for the board, the ticket and the page module:
 * a move changes which card owns what, and re-deriving that in the DOM would be
 * a second implementation of the server's answer. The Visual Editor passes its
 * own refresh instead - reloading there would throw away the whole editing
 * chrome for a marker that can simply be re-fetched.
 */
function reloadAfterChange() {
  window.location.reload();
}

/**
 * The assignee list the wizard's picker uses. Present in the backend chrome and
 * on the board; a module iframe that never got the inline setting falls back to
 * the chrome's copy rather than to an empty picker.
 */
function assignableUsers() {
  const own = TYPO3.settings?.ContentFlow?.assignableUsers;
  if (Array.isArray(own)) {
    return own;
  }
  try {
    const outer = window.top?.TYPO3?.settings?.ContentFlow?.assignableUsers;
    return Array.isArray(outer) ? outer : [];
  } catch {
    return [];
  }
}

/**
 * Delegated triggers for markup that carries `data-contentflow-split` or
 * `data-contentflow-move` plus `data-table` / `data-uid` / `data-title`.
 *
 * Bound to the TOP document for the same reason member-actions.js is: the
 * ticket arrives inside a Modal rendered into the backend chrome, while this
 * script runs in the module's content iframe.
 */
export function registerMembershipActions(options = {}) {
  const onDone = options.onDone ?? reloadAfterChange;

  const handler = (event) => {
    const target = event.target;
    if (typeof target?.closest !== 'function') {
      return;
    }

    const splitButton = target.closest('[data-contentflow-split]');
    const moveButton = splitButton === null ? target.closest('[data-contentflow-move]') : null;
    const button = splitButton ?? moveButton;
    if (button === null) {
      return;
    }

    event.preventDefault();
    // The page module's element box reacts to clicks of its own; a button
    // rendered into a content element's preview must not also select or open it.
    event.stopPropagation();

    const table = button.dataset.table;
    const uid = parseInt(button.dataset.uid, 10);
    const title = button.dataset.title || '';
    if (splitButton !== null) {
      openSplitDialog(table, uid, title, { onDone });
    } else {
      openMoveDialog(table, uid, title, { onDone });
    }
  };

  // Two documents, because the two surfaces live in different ones and an
  // iframe's events never reach the chrome around it: the ticket modal renders
  // into the top backend document, while the page module's element badge sits
  // in the module iframe this script itself runs in.
  document.addEventListener('click', handler);
  const top = topDocument();
  if (top !== document) {
    top.addEventListener('click', handler);
  }
}

/**
 * "Split from task": the record gets a task of its own, with the details an
 * editor would otherwise have to fix up afterwards - title, description and
 * assignee, the same three the post-save wizard asks for.
 *
 * @param table {string}
 * @param uid {number} the LIVE uid - a membership row holds no other kind
 * @param title {string} current record title, prefilled
 */
export function openSplitDialog(table, uid, title, options = {}) {
  const onDone = options.onDone ?? reloadAfterChange;
  const doc = topDocument();

  const content = doc.createElement('div');
  content.className = 'contentflow-membership-dialog';

  const intro = doc.createElement('p');
  intro.textContent = labels.get('membership.split.intro', [title]);
  content.appendChild(intro);

  const titleField = doc.createElement('input');
  titleField.type = 'text';
  titleField.className = 'form-control';
  titleField.value = title;
  content.appendChild(field(doc, labels.get('membership.split.field.title'), titleField));

  const descriptionField = doc.createElement('textarea');
  descriptionField.className = 'form-control';
  descriptionField.rows = 3;
  content.appendChild(field(doc, labels.get('membership.split.field.description'), descriptionField));

  const assigneePicker = doc.createElement('contentflow-assignee-picker');
  assigneePicker.users = assignableUsers();
  assigneePicker.value = 'me';
  assigneePicker.addEventListener('change', (event) => {
    assigneePicker.value = event.target.value;
  });
  content.appendChild(field(doc, labels.get('membership.split.field.assignment'), assigneePicker));

  Modal.advanced({
    title: labels.get('membership.split.title'),
    content,
    severity: SeverityEnum.notice,
    buttons: [
      {
        text: labels.get('membership.cancel'),
        btnClass: 'btn-default',
        name: 'cancel',
        trigger: (event, modal) => modal.hideModal(),
      },
      {
        text: labels.get('membership.split.submit'),
        btnClass: 'btn-primary',
        name: 'split',
        trigger: async (event, modal) => {
          modal.hideModal();
          await splitFromTask(table, uid, {
            title: titleField.value.trim(),
            description: descriptionField.value.trim(),
            assignee: assigneePicker.value,
            recordTitle: title,
            onDone,
          });
        },
      },
    ],
  });
}

/**
 * "Move to another task": pick one of the open tasks around this record.
 *
 * The candidates come from the server (moveTargetsAction), never from what the
 * client happens to have on screen - it is the same endpoint that knows which
 * tasks the editor may act on and which workspace they sit in.
 */
export async function openMoveDialog(table, uid, title, options = {}) {
  const onDone = options.onDone ?? reloadAfterChange;
  const doc = topDocument();

  const result = await request(
    () => new AjaxRequest(TYPO3.settings.ajaxUrls.contentflow_task_move_targets)
      .withQueryArguments({ table, uid })
      .get(),
  );
  if (result === null) {
    return;
  }
  if (result.success !== true) {
    Notification.error(NOTIFICATION_TITLE, result.message || labels.get('membership.error.targetsFailed'));
    return;
  }

  const content = doc.createElement('div');
  content.className = 'contentflow-membership-dialog';

  const intro = doc.createElement('p');
  intro.textContent = labels.get('membership.move.intro', [title]);
  content.appendChild(intro);

  const current = doc.createElement('p');
  current.className = 'contentflow-membership-current';
  current.textContent = labels.get('membership.move.current', [result.currentTaskTitle || '#' + result.currentTask]);
  content.appendChild(current);

  const tasks = Array.isArray(result.tasks) ? result.tasks : [];
  const buttons = [
    {
      text: labels.get('membership.cancel'),
      btnClass: 'btn-default',
      name: 'cancel',
      trigger: (event, modal) => modal.hideModal(),
    },
  ];

  if (tasks.length === 0) {
    // A regular answer, not a failure: this record simply has nowhere else to
    // go yet, and the useful next step is the other operation.
    const empty = doc.createElement('p');
    empty.className = 'contentflow-empty';
    empty.textContent = labels.get('membership.move.empty');
    content.appendChild(empty);
  } else {
    const list = doc.createElement('div');
    list.className = 'contentflow-membership-targets';
    // A radio group rather than one button per task: picking is separate from
    // confirming, so a mis-click does not move content on its own.
    tasks.forEach((task, index) => {
      const option = doc.createElement('label');
      option.className = 'contentflow-membership-target';

      const radio = doc.createElement('input');
      radio.type = 'radio';
      radio.name = 'contentflow-move-target';
      radio.value = String(task.uid);
      if (index === 0) {
        radio.checked = true;
      }
      option.appendChild(radio);

      const text = doc.createElement('span');
      text.textContent = task.title || '#' + task.uid;
      option.appendChild(text);

      if (task.stageLabel) {
        const stage = doc.createElement('span');
        stage.className = 'badge';
        stage.textContent = task.stageLabel;
        option.appendChild(stage);
      }

      list.appendChild(option);
    });
    content.appendChild(list);

    buttons.push({
      text: labels.get('membership.move.submit'),
      btnClass: 'btn-primary',
      name: 'move',
      trigger: async (event, modal) => {
        const selected = list.querySelector('input:checked');
        if (selected === null) {
          return;
        }
        const chosen = tasks.find((task) => String(task.uid) === selected.value);
        modal.hideModal();
        await moveToTask(table, uid, parseInt(selected.value, 10), {
          recordTitle: title,
          taskTitle: chosen?.title ?? '#' + selected.value,
          onDone,
        });
      },
    });
  }

  Modal.advanced({
    title: labels.get('membership.move.title'),
    content,
    severity: SeverityEnum.notice,
    buttons,
  });
}

/**
 * Move one record onto an existing task. Also the Visual Editor's one-click
 * "move to the active task", which needs no dialog because the destination is
 * the one the editor already declared.
 */
export async function moveToTask(table, uid, taskUid, options = {}) {
  const onDone = options.onDone ?? reloadAfterChange;
  const recordTitle = options.recordTitle ?? '';
  const taskTitle = options.taskTitle ?? '#' + taskUid;

  const result = await post(TYPO3.settings.ajaxUrls.contentflow_task_attach, {
    task: taskUid,
    records: [{ table, uid }],
  });
  if (result === null) {
    return;
  }

  // attachAction() reports per record, so a single-record move that came back
  // "successful" with an empty `moved` list did not happen.
  const refused = Array.isArray(result.refused) ? result.refused : [];
  if (result.success !== true || refused.length > 0) {
    Notification.error(
      NOTIFICATION_TITLE,
      refused[0]?.message || result.message || labels.get('membership.error.failed'),
    );
    return;
  }

  Notification.success(NOTIFICATION_TITLE, labels.get('membership.move.success', [recordTitle, taskTitle]));
  onDone();
}

/**
 * Pull one record out into a task of its own.
 */
export async function splitFromTask(table, uid, options = {}) {
  const onDone = options.onDone ?? reloadAfterChange;
  const recordTitle = options.recordTitle ?? options.title ?? '';

  const result = await post(TYPO3.settings.ajaxUrls.contentflow_task_detach, {
    table,
    uid,
    title: options.title ?? '',
    description: options.description ?? '',
    assignee: options.assignee ?? 'me',
  });
  if (result === null) {
    return;
  }

  if (result.success !== true) {
    Notification.error(NOTIFICATION_TITLE, result.message || labels.get('membership.error.failed'));
    return;
  }

  Notification.success(NOTIFICATION_TITLE, labels.get('membership.split.success', [recordTitle]));
  onDone();
}

/**
 * One labelled form row, so the three fields above stay readable.
 */
function field(doc, labelText, control) {
  const group = doc.createElement('div');
  group.className = 'form-group';

  const label = doc.createElement('label');
  label.className = 'form-label';
  label.textContent = labelText;
  group.appendChild(label);
  group.appendChild(control);

  return group;
}

async function post(url, payload) {
  return request(() => new AjaxRequest(url).post(payload));
}

/**
 * One request, with the server's own rejection kept intact.
 *
 * AjaxRequest THROWS on any non-2xx answer - and every rejection this
 * controller raises is a 400 carrying the code and message an editor is meant
 * to read (see TaskAjaxController::error()). A bare `catch` would replace
 * "this task belongs to another workspace" with "could not reach the server",
 * which is both wrong and unactionable. What is thrown is an AjaxResponse, so
 * the body is still there to be resolved.
 *
 * @returns {object|null} the decoded answer, or null once a genuine transport
 *          failure has been reported - callers only handle answers they got.
 */
async function request(send) {
  try {
    const response = await send();
    return await response.resolve();
  } catch (error) {
    if (typeof error?.resolve === 'function') {
      try {
        const body = await error.resolve();
        if (body !== null && typeof body === 'object') {
          return body;
        }
      } catch {
        // Not a JSON body after all - fall through to the transport message.
      }
    }
    Notification.error(NOTIFICATION_TITLE, labels.get('membership.error.server'));
    return null;
  }
}
