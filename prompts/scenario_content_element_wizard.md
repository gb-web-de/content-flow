# Content Flow — Scenario: Content Element Edit & Task Routing Wizard

## User Story / Scenario
User A edits a Content Element (e.g. `tt_content`) and clicks **Save**.

### Execution Rules & Workflow:

1. **Check Page Task State**:
   - The system checks if an open task already exists for the parent page (`pages:PID`).

2. **Second / Subsequent Save (Bypass)**:
   - If the Content Element is **already a member of an open task** (`findOpenTaskByMember` returns a task), the element has already been processed on its first save.
   - **Behavior**: Skip the wizard automatically. No popup/question is shown to the editor.

3. **First Save with Existing Page Task (Wizard Modal)**:
   - If the Content Element is **not yet a task member**, but an open task already exists for the page:
   - Present a Post-Save Task Routing Wizard dialog to User A asking:
     > "An open task already exists for this page (*Page Task Title*). Does this content element edit belong to the existing page task or should it get a new task?"

4. **Wizard Choices & Form Requirements**:
   - **Option 1**: Add edit to the existing page task.
   - **Option 2**: Create a **NEW separate task** for this Content Element.
     - **Title (Mandatory)**: `<input required>` — Task title must not be empty.
     - **Target Stage / Status Choice**:
       - *Choice A*: **"Direkt zur Abnahme"** (Move task directly to Review / Approval stage).
       - *Choice B*: **"In Arbeit / Edit noch nicht fertig"** (Keep task in Progress stage because more edits are coming).

5. **Technical Implementation**:
   - **Backend**: TYPO3 v14 PSR-14 Event Listener `TaskAutoCreationListener` listening to DataHandler events (`PostProcessDatabaseOperationsEvent`).
   - **Frontend**: Backend Wizard Modal (`wizard.js`) submitting choices to AJAX route `contentflow_task_wizard_submit`.
