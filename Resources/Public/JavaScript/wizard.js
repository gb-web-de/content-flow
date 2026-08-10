/*
 * Post-save task wizard trigger.
 *
 * Polls for a pending wizard payload (stored server-side by
 * TaskAutoCreationService::storePendingWizard() during the DataHandler save
 * that just happened) and, if one exists, opens TYPO3 core's native wizard
 * shell (@typo3/backend/wizard/wizard.js, see wizard/task-wizard.js) with it.
 * The step configuration and submission logic now live entirely in
 * Classes/Wizard/TaskWizardProvider.php and wizard/steps/*.js - this file's
 * only remaining job is the pending-check and opening the modal.
 *
 * Also boots the Visual Editor task select (task/visual-editor-task-select.js) -
 * see that file's own docblock for why that piece has to reach into
 * `#typo3-contentIframe` directly instead of loading inside it.
 */
import DocumentService from '@typo3/core/document-service.js'
import AjaxRequest from '@typo3/core/ajax/ajax-request.js'
import { html } from 'lit'
import Modal from '@typo3/backend/modal.js'
import { SeverityEnum } from '@typo3/backend/enum/severity.js'
import labels from '~labels/content_flow.messages'

import { WIZARD_MODAL_SIZE } from '@gb-web/content-flow/wizard/task-wizard.js'
import { observeVisualEditorTaskSelect } from '@gb-web/content-flow/task/visual-editor-task-select.js'

class ContentFlowWizard {
  constructor() {
    DocumentService.ready().then(() => {
      this.checkPendingWizard()
      observeVisualEditorTaskSelect()
    })

    // TYPO3's own Page/Layout module edits a content element in an iframe
    // modal (see core's <typo3-backend-contextual-record-edit-trigger>,
    // element/contextual-record-edit-trigger.js) rather than a full backend
    // page reload, so the DocumentService.ready() check above never re-fires
    // for that save - captureEdit() still runs server-side and queues a
    // pending wizard exactly as it does for any other edit, but nothing tells
    // this top-level chrome document to go check for it. Core's own trigger
    // listens for the same `typo3:editform:saved` postMessage at `top` to
    // refresh the page tree and content container; this is the same signal.
    window.addEventListener('message', (event) => {
      if (event.origin === window.location.origin && event.data?.actionName === 'typo3:editform:saved') {
        this.checkPendingWizard()
      }
    })
  }

  async checkPendingWizard() {
    if (!TYPO3.settings?.ajaxUrls?.contentflow_task_wizard_pending) {
      return
    }

    try {
      const response = await new AjaxRequest(TYPO3.settings.ajaxUrls.contentflow_task_wizard_pending).get()
      const result = await response.resolve()

      if (result.success !== true || !result.pending) {
        return
      }

      this.openWizard(result.pending)
    } catch (error) {
      // Silent catch if no session data or the backend is offline.
    }
  }

  openWizard(pending) {
    const titles = {
      route_member: labels.get('modal.routeEdit'),
      regression_comment: labels.get('modal.reopened'),
    }

    Modal.advanced({
      type: Modal.types.default,
      title: titles[pending.mode] || labels.get('modal.finishDetails'),
      content: html`<contentflow-task-wizard .pending=${pending}></contentflow-task-wizard>`,
      severity: SeverityEnum.notice,
      size: WIZARD_MODAL_SIZE,
      staticBackdrop: true,
      buttons: [],
    })
  }
}

export default new ContentFlowWizard()
