import DocumentService from '@typo3/core/document-service.js';
import AjaxRequest from '@typo3/core/ajax/ajax-request.js';
import Notification from '@typo3/backend/notification.js';
import Modal from '@typo3/backend/modal.js';
import { SeverityEnum } from '@typo3/backend/enum/severity.js';

class ContentFlowWizard {
  constructor() {
    DocumentService.ready().then(() => this.checkPendingWizard());
  }

  async checkPendingWizard() {
    if (!TYPO3.settings?.ajaxUrls?.contentflow_task_wizard_pending) {
      return;
    }

    try {
      const response = await new AjaxRequest(TYPO3.settings.ajaxUrls.contentflow_task_wizard_pending).get();
      const result = await response.resolve();

      if (result.success && result.pending) {
        this.openWizardModal(result.pending);
      }
    } catch (error) {
      // Silent catch if no session data or offline
    }
  }

  openWizardModal(pending) {
    const container = document.createElement('div');
    container.innerHTML = `
      <div style="padding: 16px;">
        <p style="margin-bottom: 14px;">An open task already exists for this page: <strong>${pending.pageTaskTitle}</strong>.</p>
        <p style="margin-bottom: 16px; font-weight: 500;">How would you like to route your content element edit?</p>

        <form id="cf-wizard-form">
          <div style="margin-bottom: 12px; padding: 10px; border: 1px solid #ddd; border-radius: 6px; background: #f9f9f9;">
            <label style="display: flex; align-items: center; gap: 8px; cursor: pointer; margin: 0; font-size: 13px;">
              <input type="radio" name="actionType" value="attach_to_page_task" checked>
              <span>Add edit to existing page task (<strong>${pending.pageTaskTitle}</strong>)</span>
            </label>
          </div>

          <div style="margin-bottom: 16px; padding: 10px; border: 1px solid #ddd; border-radius: 6px; background: #f9f9f9;">
            <label style="display: flex; align-items: center; gap: 8px; cursor: pointer; margin: 0; font-size: 13px;">
              <input type="radio" name="actionType" value="create_new_task">
              <span>Create a <strong>NEW separate task</strong> for this Content Element</span>
            </label>

            <div id="cf-wiz-new-fields" style="display: none; margin-top: 12px; padding-top: 12px; border-top: 1px dashed #ccc;">
              <div style="margin-bottom: 12px;">
                <label style="font-weight: 600; display: block; margin-bottom: 4px; font-size: 12px;">
                  Task Title <span style="color: red;">* (Pflichtfeld)</span>:
                </label>
                <input type="text" id="cf-wiz-title" class="form-control" placeholder="Enter task title...">
              </div>

              <div>
                <label style="font-weight: 600; display: block; margin-bottom: 6px; font-size: 12px;">Target Status / Stage:</label>
                <label style="display: block; font-size: 12px; margin-bottom: 4px;">
                  <input type="radio" name="stageChoice" value="review" checked>
                  <strong>Direkt zur Abnahme</strong> (Move directly to Review/Approval stage)
                </label>
                <label style="display: block; font-size: 12px;">
                  <input type="radio" name="stageChoice" value="in_progress">
                  <strong>In Arbeit / Edit noch nicht fertig</strong> (Keep in Progress, more edits coming)
                </label>
              </div>
            </div>
          </div>

          <div style="text-align: right; display: flex; gap: 8px; justify-content: flex-end;">
            <button type="button" class="btn btn-default" id="cf-wiz-cancel">Ignore / Keep default</button>
            <button type="submit" class="btn btn-primary">Save Choice</button>
          </div>
        </form>
      </div>
    `;

    const form = container.querySelector('#cf-wizard-form');
    const radios = container.querySelectorAll('input[name="actionType"]');
    const newFields = container.querySelector('#cf-wiz-new-fields');
    const titleInput = container.querySelector('#cf-wiz-title');

    radios.forEach(r => {
      r.addEventListener('change', () => {
        if (form.actionType.value === 'create_new_task') {
          newFields.style.display = 'block';
          titleInput.setAttribute('required', 'required');
        } else {
          newFields.style.display = 'none';
          titleInput.removeAttribute('required');
        }
      });
    });

    const modal = Modal.advanced({
      title: 'Post-Save Task Routing Wizard',
      content: container,
      size: Modal.sizes.medium,
      severity: SeverityEnum.notice,
    });

    container.querySelector('#cf-wiz-cancel').addEventListener('click', () => Modal.dismiss());

    form.addEventListener('submit', async (e) => {
      e.preventDefault();
      const actionType = form.actionType.value;
      const title = titleInput.value.trim();
      const stageChoice = form.stageChoice ? form.stageChoice.value : 'in_progress';

      if (actionType === 'create_new_task' && title === '') {
        Notification.error('Validation Error', 'Task title is mandatory.');
        return;
      }

      try {
        const response = await new AjaxRequest(TYPO3.settings.ajaxUrls.contentflow_task_wizard_submit)
          .post({
            actionType,
            table: pending.table,
            uid: pending.uid,
            pageTaskUid: pending.pageTaskUid,
            title,
            stageChoice,
          });
        const result = await response.resolve();
        Modal.dismiss();
        if (result.success) {
          Notification.success('Content Flow', 'Task routing completed.');
          window.location.reload();
        } else {
          Notification.error('Content Flow', result.message || 'Could not complete task routing.');
        }
      } catch (err) {
        Notification.error('Content Flow', 'Server error during task routing.');
      }
    });
  }
}

export default new ContentFlowWizard();
