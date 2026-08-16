# Content Flow — Entwickler-Dokumentation: Board, Visual Editor, Layout

Diese Doku beschreibt für drei Oberflächen der Extension — **Board** (Modul
`web_contentflow`), **Visual Editor** (`EXT:visual_editor`-Integration) und
**Layout** (TYPO3 Seite/Layout-Modul, `web_layout`) — wie PHP und JavaScript
zusammenspielen, welche Datei bei welchem Klick welche andere Datei aufruft,
und an welcher Stelle man Styling anpasst.

Sie ergänzt [ARCHITECTURE.md](ARCHITECTURE.md) (Konzept/Domänenmodell) und
[WORKSPACE-STAGES.md](WORKSPACE-STAGES.md) (Core-Workspace-Mechanik) um die
technische Ablaufsicht: Klick → JS-Funktion → AJAX-Route → PHP-Methode →
Antwort → DOM-Änderung.

**Konvention für Verweise:** `Datei.ext:Zeile` verweist auf die exakte Stelle
im Code zum Zeitpunkt dieser Doku. Bei Refactorings können sich Zeilennummern
verschieben — die Funktions-/Methodennamen bleiben der verlässlichere Anker.

---

## 0. Das gemeinsame Rückgrat: Warum drei Oberflächen dieselbe Wahrheit zeigen

Bevor es in die drei Bereiche geht, ein Punkt, der überall wiederkehrt:

Ein Redakteur kann einen Datensatz auf **drei Wegen** bearbeiten — im Visual
Editor, im Seite/Layout-Modul oder direkt im Datensatz-Formular. Alle drei
laufen serverseitig durch denselben DataHandler-Hook:

```
Classes/Hooks/TaskAutoCreationDataHandlerHook.php
  └─ processDatamap_afterDatabaseOperations()
       ├─ PendingPageClaimService::claimCreatedPage()   (nur bei status === 'new', table === 'pages')
       ├─ PendingSubjectClaimService::claimCreatedSubject() (bei neuen Nicht-Seiten-Records)
       └─ TaskAutoCreationService::captureEdit()         (bei jedem Save)
```

`captureEdit()` ist der Grund, warum ein Task "sich selbst öffnet": Er
erkennt eine neue Workspace-Version und hängt den Datensatz an einen
offenen Task (oder legt einen an). Das passiert **unabhängig davon**, ob
gerade das Board, der Visual Editor oder das Layout-Modul offen war — deshalb
zeigen alle drei Oberflächen danach denselben Task mit derselben Farbe
(`TaskColor::hueFor()`, [Classes/Service/TaskColor.php](Classes/Service/TaskColor.php)).

Der Ziel-Task kann vor dem Speichern im Visual Editor, Board, Layout-Banner
oder Datensatzformular gewählt werden. `ActiveTaskSession` speichert genau
eine persönliche Auswahl: ein Seiten-Kontext gilt für alle Records der Seite,
ein Record-Kontext nur für genau diesen Datensatz.

Zwei Event-Listener sorgen dafür, dass JS-Modul und CSS-Datei der Extension
**in jedem Backend-Rendering** verfügbar sind, unabhängig vom aktuellen Modul:

```
Classes/EventListener/LoadWizardModuleEventListener.php
  #[AsEventListener(event: AfterBackendPageRenderEvent::class)]
  → pageRenderer->loadJavaScriptModule('@gb-web/content-flow/wizard.js')
  → pageRenderer->addCssFile('EXT:content_flow/Resources/Public/Css/Styles.css')
```

`AfterBackendPageRenderEvent` feuert einmal pro Rendern der äußeren Backend-
Chrome (nicht pro Modul-Iframe-Inhalt). Das ist wichtig für das Verständnis
von Abschnitt 3 und 4: `wizard.js` und `Styles.css` liegen dadurch **immer**
im äußersten Backend-Dokument (`top.document`), auch wenn Board oder Seite-
Modul in einem Iframe laufen. TYPO3s `Modal.advanced()` rendert seine Dialoge
immer in dieses äußerste Dokument — deshalb sind Ticket-Modal, Checklisten-
Modal etc. immer korrekt gestylt, egal von welchem Iframe aus sie geöffnet
wurden.

---

## 1. Board (Backend-Modul `web_contentflow`)

### 1.1 Registrierung

```
Configuration/Backend/Modules.php
  'web_contentflow' => [
      'parent' => 'content',
      'position' => ['after' => 'records'],
      'navigationComponent' => '@typo3/backend/tree/page-tree-element',
      'controllerActions' => [ ContentFlowController::class => ['index'] ],
  ]
```

Ein Klick auf den Menüpunkt „Content Flow" im Content-Modulbereich zeigt den
TYPO3-PageTree links und ruft weiterhin über die Modulkennung
`web_contentflow` `GET /module/web/contentflow?id=<pageUid>` auf, was auf
[Classes/Controller/ContentFlowController.php](Classes/Controller/ContentFlowController.php):`indexAction()`
(Zeile 48) routet.

### 1.2 PHP-Seite: `ContentFlowController::indexAction()`

Ablauf beim Öffnen/Neuladen des Boards, Zeile für Zeile:

1. `moduleTemplateFactory->create()` — TYPO3s Standard-Modulrahmen (DocHeader,
   Breadcrumb, Shortcut-Button). Es gibt bewusst **kein eigenes Fluid-Layout**
   dafür — `Index.html` beginnt mit `<f:comment>` genau das begründend
   ([Index.html:6-16](Resources/Private/Templates/ContentFlow/Index.html)).
2. `$pageUid`, `$workspaceUid`, `$depth`, `$fromWorkspaceRoot` werden aus
   Query-Parametern gelesen (`id`, `depth`, `wsroot`).
3. `buildBoard()` (Zeile 198) — der eigentliche Kern:
   - `BoardColumnRegistry::getColumns()` baut die Spaltenliste (siehe 1.2.1).
   - `BoardScopeResolver` löst `$pageUid` + `$depth` (oder Workspace-Root) in
     eine Liste von `pageUids` auf.
   - **Eine** Query: `TaskRepository::findForBoard($pageUids)` holt alle
     offenen Tasks für diese Seiten (keine Query pro Spalte/Stage!).
   - Jeder Task wird angereichert (Icon, Zuweisungsname, Fälligkeits-Label/
     -Dringlichkeit, `canAct`, `foreignWorkspace`, `subjectPageTitle` …).
   - `belongsInColumn()` (Zeile 328) verteilt jeden Task in genau eine
     Spalte: `stageUid !== null` → Core-Stage-Vergleich, sonst → Content-
     Flow-`state`-Vergleich, foreign-Workspace-Tasks → Sentinel-Spalte
     `other-workspaces`.
4. Diverse `pageRenderer->addInlineSetting('ContentFlow', …)` — das sind die
   Werte, die im Browser unter `TYPO3.settings.ContentFlow.*` auftauchen und
   von `board.js` gelesen werden (`elementBrowserUrl`, `currentUserId`,
   `createTargetTables`, `assignableUsers`,
   `currentPageId`, `canPublish`, `depth`, `fromWorkspaceRoot`).
5. `pageRenderer->addCssFile(…Styles.css)` und
   `pageRenderer->loadJavaScriptModule('@gb-web/content-flow/board.js')` —
   **nur** für dieses Iframe-Dokument (wichtig für Abschnitt 1.7).
6. `renderResponse('ContentFlow/Index')` rendert
   [Index.html](Resources/Private/Templates/ContentFlow/Index.html) mit den
   zusammengebauten `columns`.

#### 1.2.1 `BoardColumnRegistry::getColumns()`

Baut die Spaltenreihenfolge `Backlog, Planned | <Core-Stages aus
sys_workspace_stage> | Done | Other workspaces`. Die mittleren Spalten
kommen **1:1** aus `WorkspaceStageRepository::findAllStagesByWorkspace()` —
ein Integrator legt neue Review-Stufen im Workspace-Datensatz an, das Board
zieht sie automatisch nach, ohne eigene Konfiguration
([Classes/Service/BoardColumnRegistry.php](Classes/Service/BoardColumnRegistry.php)).
Jede Stage-Spalte trägt zusätzlich `checklistItemsJson` (vorkodiertes JSON,
weil Fluid v14 keinen JSON-ViewHelper hat) für die Review-Checkliste.

### 1.3 Vom PHP zum DOM: `Index.html` → `data-contentflow-*`

`Index.html` rendert pro Spalte ein `<section class="contentflow-column"
data-contentflow-column="{column.key}" data-contentflow-state="{column.state}"
data-contentflow-stage="{column.stageUid}" data-contentflow-accepts-drop="…">`
und pro Karte ein `<li class="contentflow-card" data-contentflow-task="{card.uid}"
data-contentflow-state="…" data-contentflow-stage="…" data-contentflow-workspace="…"
data-contentflow-can-act="…">` usw.

Diese `data-*`-Attribute sind die **einzige** Schnittstelle zwischen PHP und
JS auf dem Board — es gibt keine zweite AJAX-Anfrage beim Laden, die
Kartendaten stecken bereits im gerenderten HTML. JS liest sie nur noch aus
(`card.dataset.contentflowTask` etc.) und macht daraus Verhalten.

### 1.4 JS-Einstieg: `board.js`

[Resources/Public/JavaScript/board.js](Resources/Public/JavaScript/board.js)
ist reine Verdrahtung (`class ContentFlowBoard`), Verhalten steckt in
Unterdateien:

```
board.js
 ├─ board/filters.js      registerFilters()        client-seitige Suche/Filter
 ├─ board/scope.js        registerScopeControls()  Tiefe/Workspace-Root (Reload)
 ├─ board/drag-drop.js    registerDragAndDrop()     Drag&Drop-Validierung
 ├─ board/checklist.js    registerChecklistManagement()/registerChecklistToggle()
 ├─ task/assign.js        registerAssignButtons()
 ├─ task/ticket.js        registerTicketButtons()
 ├─ task/create-wizard.js registerCreateButton()
 ├─ task/comment.js       registerCommentForm()
 ├─ task/publish.js       registerPublishButtons()
 └─ task/member-actions.js registerMemberActions()
```

`initialize()` ([board.js:56-80](Resources/Public/JavaScript/board.js))
registriert `create-wizard.js`, `ticket.js`, `assign.js` und die auf
`topDocument()` delegierten Handler (`comment.js`, `member-actions.js`,
`checklist.js`'s `registerChecklistToggle()`) **immer** — auch ohne Board-
Element im DOM, weil dieselben Buttons im Layout-Modul-Banner auftauchen
(Abschnitt 4). Erst danach folgt `this.board = document.querySelector(
'.contentflow-board')`; ist das `null` (z. B. im Layout-Modul), bricht
`initialize()` ab, bevor Drag&Drop/Filter/Scope/Publish/Checklist-Verwaltung
registriert werden — die gibt es nur, wenn ein echtes Board im DOM steht.

`topDocument()` ([Resources/Public/JavaScript/dom-scope.js](Resources/Public/JavaScript/dom-scope.js))
ist ein wiederkehrendes Muster: Das Board läuft im klassischen Backend-
Content-Iframe, aber `Modal.advanced()`/`Modal.types.ajax` rendern **immer**
in `window.top.document`. Ein auf `document` (= Iframe-Dokument) registrierter
Listener sieht Klicks in einem Modal nie. Jede Datei, die auf Inhalte in
einem Ticket-/Checklisten-Modal reagiert, delegiert deshalb auf
`topDocument()`.

### 1.5 Klick-Abläufe (Board)

#### a) Seite im Seitenbaum wählen → Board lädt

```
Klick auf Seite im Seitenbaum
 → GET /module/web/contentflow?id=<uid>
 → ContentFlowController::indexAction()
 → ContentFlowController::buildBoard()
 → Index.html rendert Spalten + Karten mit data-contentflow-*
 → board.js: new ContentFlowBoard() (Modul-Import) 
     → DocumentService.ready() → initialize()
     → registerCardEvents(), registerDragAndDrop(), registerFilters(),
       registerScopeControls(), registerPublishButtons(),
       registerChecklistManagement()
```

Kein AJAX-Request beim ersten Laden — alles kommt server-gerendert.

#### b) Karte in eine Content-Flow-Spalte ziehen (Backlog ↔ Planned)

```
dragstart auf .contentflow-card
 → drag-drop.js: draggedCard merken, updateDropTargetStyles()
     → board.canDropCardIntoColumn(card, column)  [board.js:120]
        prüft: acceptsDrop, canAct, targetStage vs. currentStage/State
 → drop auf .contentflow-column
 → board.handleCardDrop(taskUid, column, card)     [board.js:193]
     targetStageUid === null (Content-Flow-Spalte)
     → board.moveTaskToColumn(taskUid, targetState, 0, …)  [board.js:216]
        → POST TYPO3.settings.ajaxUrls.contentflow_task_move_stage
          { task, state, stageUid: 0 }
        → TaskAjaxController::moveStageAction()  [TaskAjaxController.php:402]
           targetState hat keine Version (Backlog/Planned)
           → TaskRepository::moveToColumn()
           → ActivityLogger::log(EVENT_STAGE_CHANGED)
           → JsonResponse { success: true }
     → Notification.success(), window.location.reload()
```

#### c) Karte in eine Review-/Core-Stage-Spalte ziehen

Gleicher Start wie b), aber `targetStageUid !== null`:

```
board.handleCardDrop() → board.openStageTransitionModal()   [board.js:301]
 → Workspaces.sendRemoteRequest('sendToSpecificStageWindow', …)  (Core-API)
 → Content Flow baut daraus einen schlanken TYPO3-Modal-Dialog:
    Kommentar, Benachrichtigungen als aufklappbarer Bereich, zusätzliche
    Empfänger und dieselben Core-Labels wie EXT:workspaces
 → ist der Task aktiv und verlässt Editing: der Dialog ergänzt die
   vorausgewählte Option „aktive Bearbeitung beenden" samt Lifecycle-Hinweis
 → Klick auf "OK" im Dialog
 → readStageTransitionForm(form) liest comment/recipients/additional/deactivateActiveTask
 → POST contentflow_task_execute_stage { task, stageUid, comment, recipients,
   additional, deactivateActiveTask }
 → TaskAjaxController::executeStageAction()  [TaskAjaxController.php:860]
    → StageTransitionService::transition() — echter Core-Stage-Wechsel
      (sys_history, Benachrichtigungen laufen durch Core)
    → erst nach Erfolg: ActiveTaskSession::forgetIfTask(), falls gewählt
 → modal.hideModal(), window.location.reload()
```

#### d) Ticket ohne Subject in "In Progress" ziehen

Sonderfall in `moveStageAction()`: `subject_uid === 0` bezeichnet ein reines
Plan-Ticket ohne echten Datensatz. Je nach `subject_table` wird Core zum
Erstellen einer Seite oder eines Records geöffnet:

```
Pending Page (`subject_table === 'pages'`):
TaskAjaxController::moveStageAction() → requestPageWizard($task)
 → JsonResponse { requiresPageWizard: true, positionData: … }
 → board.moveTaskToColumn() erkennt requiresPageWizard === true
 → board.openPageWizard(result, cardTitle)   [board.js:261]
    → topLevelModuleImport('@typo3/backend/page-wizard/page-wizard.js')
    → openPageWizardModal({})   — Core-Seitenassistent startet in Step 1
      "Position"; Content Flow übergibt bewusst keine `positionData`, damit
      keine Position vorselektiert wird und Core nicht direkt zu Step 2
      "Seitentyp" springt
    → Editor legt Seite an (oder bricht ab)
    → modal 'typo3-modal-hidden' → dropPageWizardClaimWhenClosed()
       → falls abgebrochen: POST contentflow_task_cancel_page_wizard
         → TaskAjaxController::cancelPageWizardAction()  [Zeile 1131]
       → falls Seite erstellt: DataHandler-Save der neuen Seite triggert
         TaskAutoCreationDataHandlerHook → PendingPageClaimService::
         claimCreatedPage() verknüpft Ticket mit der neuen Seite
         (siehe Abschnitt 0)

Pending Record (`subject_table !== 'pages'`):
TaskAjaxController::moveStageAction() → requestRecordTarget($task)
 → board.openRecordTargetModal() lädt contentflow_task_record_creation_targets
 → Editor wählt eine zulässige Zielseite
 → contentflow_task_start_record_creation speichert PendingSubjectHandoff
   und öffnet Core-FormEngine (record_edit, command=new)
 → DataHandler-Save triggert PendingSubjectClaimService::claimCreatedSubject()
   → Subject + Membership werden gesetzt, Task wechselt nach Editing
 → Return-Route löscht einen nicht verbrauchten Handoff und öffnet das Board
```

#### e) „+ New task" klicken

```
Klick auf [data-contentflow-action="create-task"] (ohne data-contentflow-page)
 → create-wizard.js: registerCreateButton() → openEntryChoiceWizard()  [Zeile 169]
    Auswahl-Dialog mit 3 Gruppen und 5 Optionen:
      Gruppe „Page"
      1. „Neue Seite" → openPendingPageWizard()
           → <contentflow-task-wizard .pending={mode:'create_pending_page', parentPid}>
      2. „Bestehende Seite" → openRecordPicker(['pages'])

      Gruppe „Content element"
      3. „Inhaltselement auswählen" → openRecordPicker(['tt_content'])

      Gruppe „Record"
      4. „Datensatz auswählen" → openRecordPicker(<alle erlaubten Tabellen
         außer pages und tt_content>)
           → Core Element-Browser (Modal.types.iframe, elementBrowserUrl)
           → Event 'typo3:element-browser:message' → openNewTaskWizard(table, uid, label)
      5. „Neuen Datensatz anlegen" → openPendingRecordWizard()
           → <contentflow-task-wizard .pending={mode:'create_pending_record', parentPid}>
           → Record-Typ + Task-Details werden gesammelt; der echte Record
             entsteht erst beim Wechsel des Tickets nach Editing (Abschnitt d).
             `pages` und `tt_content` sind hier ausgeschlossen: Pages laufen
             über den Page-Wizard, Content-Elemente über die eigene
             Content-Element-Auswahl.
 → <contentflow-task-wizard> ist Core-eigene Wizard-Shell
   (@typo3/backend/wizard/wizard.js), Schritte in wizard/steps/*.js,
   siehe Classes/Wizard/TaskWizardProvider.php für die Server-Logik.
 → Abschluss für `create_from_picker`, `create_pending_page` oder
   `create_pending_record` läuft über TaskWizardProvider::handleSubmit()
   → SubmissionResult { success:true, finisher.data.task: <uid> }
```

#### f) Ticket öffnen (Klick auf Kartentitel)

```
Klick auf [data-contentflow-open-ticket]
 → ticket.js: registerTicketButtons() → event.stopPropagation()
   (verhindert, dass die Karte gleichzeitig (de-)selektiert wird)
 → board.openTicket(taskUid, title)   [board.js:397]
    → Modal.advanced({ type: Modal.types.ajax,
                        content: ajaxUrls.contentflow_task_ticket + '&task=' + taskUid })
 → GET contentflow_task_ticket?task=<uid>
 → TaskAjaxController::ticketAction()   [Zeile 1489]
    → WorkspaceIntegrationService::getTaskDetails()  (Diffs, Mitglieder, Aktivität, Kommentare)
    → rendert Fluid-Template ContentFlow/Ticket.html serverseitig zu HTML
 → HtmlResponse wird 1:1 in das Modal (top.document) eingesetzt
```

Innerhalb des Ticket-Modals (`Resources/Private/Templates/ContentFlow/
Ticket.html`), alle Handler auf `topDocument()`:

- **Kommentar absenden** → `task/comment.js` → `POST contentflow_task_comment`
  → `TaskAjaxController::commentAction()` → `window.location.reload()`.
- **Mitglied „Vorschau"** (`.contentflow-member-preview`) → `task/member-actions.js`
  `previewMember()` → `POST contentflow_task_preview_member` →
  `TaskAjaxController::previewMemberAction()` → `window.open(result.url, …)`.
- **Mitglied „Diff"** (`.contentflow-member-diff`) → rein clientseitig,
  `jumpToDiff()` scrollt zu `.contentflow-diff[data-table][data-uid]`, kein
  Server-Roundtrip.
- **Mitglied „Verwerfen"** (`.contentflow-member-discard`) → `discardMember()`
  → `Modal.confirm()` → `POST contentflow_task_discard_member` →
  `TaskAjaxController::discardMemberAction()` → Reload.
- **Checkliste abhaken** → `board/checklist.js` `registerChecklistToggle()`
  → `POST contentflow_checklist_toggle` →
  `TaskAjaxController::checklistToggleAction()`.

#### g) „Assign me"

```
Klick auf .contentflow-action-assign
 → task/assign.js: registerAssignButtons()
 → POST contentflow_task_assign_me { task }
 → TaskAjaxController::assignMeAction()  [Zeile 496]
    → TaskRepository::assignTo()
    → ActivityLogger::log(EVENT_ASSIGNED)
 → window.location.reload()
```

#### h) „Publish"

Nur sichtbar/aktiv, wenn `TYPO3.settings.ContentFlow.canPublish === true`
(serverseitig via `WorkspacePublishGate::isGranted()` gesetzt,
[ContentFlowController.php:162-166](Classes/Controller/ContentFlowController.php)).
Ist die Berechtigung nicht gegeben, bleibt der Button sichtbar aber
`disabled` — nie einfach versteckt (siehe `publish.js:18-24`, folgt der im
Code mehrfach zitierten Regel „immer Icon + Label, nie nur Farbe/Silence").

```
Klick auf .contentflow-action-publish
 → task/publish.js: Modal.confirm(„Publish …?“)
 → POST contentflow_task_publish { task }
 → TaskAjaxController::publishTaskAction()  [Zeile 964]
    → askCoreToPublish() — echter Core-Publish-Vorgang
    → schließt den Task, falls nichts mehr offen ist
 → window.location.reload()
```

#### i) Checkliste verwalten (Zahnrad-Icon an der Spalte)

Nur gerendert, wenn `column.canManageChecklist` true ist
(`BoardColumnRegistry::canManageChecklist()` — Workspace-Owner oder Admin).

```
Klick auf .contentflow-column-checklist-manage
 → board/checklist.js: openManageModal(column)
    liest column.dataset.contentflowChecklistItems (vorkodiertes JSON)
    baut Modal.advanced() mit Liste + „Entfernen"-Buttons + Add-Formular
 → „Entfernen" → POST contentflow_checklist_remove { workspaceUid, itemUid }
    → TaskAjaxController::checklistRemoveAction()
 → Add-Formular submit → POST contentflow_checklist_add { workspaceUid, stageUid, title }
    → TaskAjaxController::checklistAddAction()
 → Modal schließen → window.location.reload() (callback der Modal.advanced())
```

#### j) Filter (client-seitig, kein Server-Request)

```
Eingabe in #cf-search-input / Änderung an #cf-filter-assignee/-status/-workspace
 → board/filters.js: filterCards()
    blendet .contentflow-card per style.display ein/aus, aktualisiert
    Spalten-Badges, ruft board.announce() für Screenreader
```

#### k) Scope: Tiefe / „From workspace root" (server-seitig, Reload)

```
Änderung an #cf-depth oder #cf-wsroot
 → board/scope.js: reloadWithScope()
    setzt URL-Parameter depth=… bzw. wsroot=1 und lädt die Seite neu
    → wieder bei ContentFlowController::indexAction() (Abschnitt 1.2)
```

### 1.6 AJAX-Routen des Boards

Registriert in
[Configuration/Backend/AjaxRoutes.php](Configuration/Backend/AjaxRoutes.php),
alle unter `/contentflow/...`, alle auf `TaskAjaxController`:

| Route | PHP-Methode | Aufgerufen von |
|---|---|---|
| `contentflow_task_create` | `createAction()` | create-wizard.js, VE-Select „+ Create" |
| `contentflow_task_create_pending_page` | `createPendingPageAction()` | create-wizard.js |
| `contentflow_task_cancel_page_wizard` | `cancelPageWizardAction()` | board.js (Seitenassistent abgebrochen) |
| `contentflow_task_record_creation_targets` | `recordCreationTargetsAction()` | board.js (Zielseite für Pending Record) |
| `contentflow_task_start_record_creation` | `startRecordCreationAction()` | board.js (Core-FormEngine öffnen) |
| `contentflow_task_attach` | `attachAction()` | „Select to task" (Element-Browser-Fluss) |
| `contentflow_task_detach` | `detachAction()` | Split-from-task-Aktion im Ticket |
| `contentflow_task_preview_member` | `previewMemberAction()` | task/member-actions.js |
| `contentflow_task_discard_member` | `discardMemberAction()` | task/member-actions.js |
| `contentflow_task_move_stage` | `moveStageAction()` | board/drag-drop.js → board.js |
| `contentflow_task_assign_me` | `assignMeAction()` | task/assign.js |
| `contentflow_task_details` | `detailsAction()` | (Inspector-Detaildaten) |
| `contentflow_task_list_open_for_page` | `listOpenTasksForPageAction()` | **VE**: task-select.js `reloadTasks()` |
| `contentflow_task_set_active_for_page` | `setActiveTaskForPageAction()` | **VE**: task-select.js `onChange()` |
| `contentflow_task_active_context` | `activeTaskContextAction()` | globales DocHeader-Control |
| `contentflow_task_set_active_context` | `setActiveTaskForContextAction()` | Board/Layout/Record-Formular |
| `contentflow_task_list_member_markers` | `listMemberTaskMarkersForPageAction()` | **VE**: task-select.js `reloadMarkers()` |
| `contentflow_task_comment` | `commentAction()` | task/comment.js |
| `contentflow_task_ticket` | `ticketAction()` | board.js/visual-editor-markers.js `openTicket()` |
| `contentflow_task_execute_stage` | `executeStageAction()` | board.js Stage-Dialog |
| `contentflow_task_publish` | `publishTaskAction()` | task/publish.js |
| `contentflow_task_wizard_pending` | `getPendingWizardAction()` | wizard.js `checkPendingWizard()` |
| `contentflow_checklist_toggle` | `checklistToggleAction()` | board/checklist.js |
| `contentflow_checklist_add` | `checklistAddAction()` | board/checklist.js |
| `contentflow_checklist_remove` | `checklistRemoveAction()` | board/checklist.js |

### 1.7 Styling anpassen (Board)

**Wo CSS geladen wird:**

- Für das Board-Iframe selbst: `ContentFlowController::indexAction()` →
  `pageRenderer->addCssFile('EXT:content_flow/Resources/Public/Css/Styles.css')`
  ([ContentFlowController.php:97](Classes/Controller/ContentFlowController.php)).
- Für alle Modals (Ticket, Checkliste), egal aus welchem Modul geöffnet:
  `LoadWizardModuleEventListener` lädt dieselbe Datei zusätzlich in die
  äußere Backend-Chrome (siehe Abschnitt 0). **Das ist derselbe Dateipfad**,
  nicht zwei verschiedene Stylesheets.

Es gibt nur **eine** CSS-Datei:
[Resources/Public/Css/Styles.css](Resources/Public/Css/Styles.css) (kein
Preprozessor, kein Build-Schritt — direkt editieren und Browser-Cache/TYPO3-
Cache leeren reicht).

**Design-Tokens** (Zeile 4-25): Farben/Maße als CSS-Custom-Properties in
`:root`, die meisten mit Fallback auf TYPO3s eigene `--typo3-*`-Tokens:

```css
--contentflow-gap: 16px;              /* Abstand zwischen Spalten/Karten */
--contentflow-col-width: 310px;       /* Spaltenbreite */
--contentflow-card-bg: var(--typo3-surface-base, #fff);
--contentflow-accent: var(--typo3-component-primary-color, #0078d4);
```

Willst du z. B. die Spaltenbreite ändern: `--contentflow-col-width` in
`Styles.css:6` anpassen — wirkt sofort auf `.contentflow-column`
([Styles.css:166](Resources/Public/Css/Styles.css)), keine JS-Änderung
nötig.

**Fälligkeitsfarben** (`--contentflow-due-soon`, `--contentflow-overdue`)
werden **nicht** in `Styles.css` gesetzt, sondern zur Laufzeit aus der
Extension-Konfiguration injiziert:

```
ContentFlowController::dueDateColorCss()  [Zeile 428]
 → liest $GLOBALS['TYPO3_CONF_VARS']['EXTCONF']['content_flow']['dueDateThresholds']
 → pageRenderer->addCssInlineBlock('content-flow-due-date-colors', …, csp: true)
 → erzeugt :root { --contentflow-due-soon: …; --contentflow-overdue: …; }
```

Diese Werte also **nicht** in `Styles.css`, sondern in der
`ext_localconf.php`/EXTCONF des Integrators ändern.

**Statusfarben je Spalte/Badge** (`.badge-backlog`, `.badge-planned`, …,
[Styles.css:689-694](Resources/Public/Css/Styles.css)) sind an
`GbWeb\ContentFlow\Domain\Model\TaskState`-Werte gekoppelt (Klassenname =
`badge-` + State-Value) und nutzen selbst wieder TYPO3-Design-Tokens
(`--typo3-state-*`).

**Task-Farbe (Board-unabhängig von VE/Layout)**: Die „Hue" eines Tasks (für
Punkte/Badges in Banner, Marker, Legende) kommt **nicht** aus CSS, sondern
aus `TaskColor::hueFor()` (goldener Winkel × Task-UID) — dieselbe Formel
existiert nochmal in JS (`task-markers.js: hueForTaskUid()`), weil der
Client manchmal nur die UID kennt. Beide Formeln müssen identisch bleiben,
sonst zeigt derselbe Task auf zwei Oberflächen unterschiedliche Farben.

**Wichtige Klassenbereiche** in `Styles.css` (Zeilennummern siehe
`grep -n "^\."`-Ausgabe oben, hier nur der Überblick):

| Bereich | Klassen-Präfix | Zeilen (ca.) |
|---|---|---|
| Toolbar/Filter/Scope | `.contentflow-toolbar`, `.contentflow-filters`, `.contentflow-scope` | 27-118 |
| Board-Grid & Spalten | `.contentflow-board`, `.contentflow-column*` | 157-201 |
| Karten | `.contentflow-card*` | 203-376 |
| Drag&Drop-Zustände | `.is-dragged`, `.is-drop-target-valid/-invalid` | 293-312 |
| Seiten-Banner (Layout-Modul) | `.contentflow-page-banner*` | 391-498 |
| Element-Badge (Layout-Modul) | `.contentflow-element-badge*` | 504-521 |
| Ticket-Ansicht | `.contentflow-ticket*`, `.contentflow-diff*`, `.contentflow-timeline*` | 619-925 |

Drag&Drop-Rückmeldung ändern (z. B. andere Farbe für „gültiges Ziel"): nicht
in `drag-drop.js` — dort wird nur die Klasse `is-drop-target-valid`
getoggelt, die Farbe selbst steht in `Styles.css:299-312`.

---

## 2. Visual Editor (VE) Integration

### 2.1 Die drei beteiligten Dokumente

Zentral für das Verständnis — und laut Code-Kommentar der Grund, warum die
Marker anfangs gar nicht funktionierten:

```
1. Backend-Chrome (top.document)
     lädt wizard.js (via LoadWizardModuleEventListener, s. Abschnitt 0)
2. #typo3-contentIframe
     enthält EXT:visual_editor's eigenes Modul (Lit-Toolbar:
     ve-auto-save-toggle, ve-backend-save-button, …)
     → hier wird das Task-Select eingehängt
3. iframe.visual-editor-iframe (eines PRO SPRACHE, innerhalb von 2.)
     enthält die gerenderte FRONTEND-Seite
     → nur hier existieren <ve-content-element>, nur hier werden Marker gesetzt
```

`EXT:visual_editor` bietet **keine** serverseitige Extension-Point-API für
Drittanbieter — die Integration hängt sich rein clientseitig über
`MutationObserver` und DOM-Suche in diese drei Dokumente ein.

### 2.2 Ladepfad

```
LoadWizardModuleEventListener (jedes Backend-Rendering)
 → loadJavaScriptModule('@gb-web/content-flow/wizard.js')
 → wizard.js: DocumentService.ready()
    → checkPendingWizard()
    → observeVisualEditorTaskSelect()   [visual-editor-task-select.js:560]
       kein #typo3-contentIframe vorhanden?
         → MutationObserver auf document.body, wartet bis es erscheint
       vorhanden?
         → attach(iframe) sofort
    attach(iframe):
       iframe.addEventListener('load', () => tryMount(iframe))
       tryMount(iframe) sofort einmal versuchen (Iframe evtl. schon geladen)
    tryMount(iframe):
       isVisualEditorDocument(doc)  // prüft auf <ve-auto-save-toggle>
       → nein: abbrechen (kein VE gerade offen)
       → ja: new VisualEditorTaskSelect(doc, pageUid).mount()
```

`#typo3-contentIframe` bleibt über Modulwechsel hinweg bestehen (TYPO3 nutzt
es wieder), daher genügt **ein** `load`-Listener für alle künftigen
Navigationen in und aus dem Visual Editor heraus.

### 2.3 Toolbar-Select einhängen

```
VisualEditorTaskSelect.mount()   [visual-editor-task-select.js:174]
 → insertToolbar()   [Zeile 189]
    sucht <ve-auto-save-toggle>, hängt einen eigenen Slot
    (.contentflow-ve-toolbar-slot, margin-left:auto → rechtsbündig)
    NACH dem umgebenden .btn-toolbar ein (nicht in dessen .btn-group!)
    injiziert TOOLBAR_STYLES als <style id="contentflow-ve-toolbar">
    baut <select class="contentflow-ve-task-select"> + Legende
       (<span class="contentflow-ve-legend">, an TaskMarkers.mountLegend() übergeben)
 → watchToolbar()   [Zeile 268]
    MutationObserver: EXT:visual_editor ersetzt bei jedem Sprachwechsel/jeder
    Navigation den kompletten Toolbar-Container (updateModuleState() in
    Backend/page-changed.js). Verschwindet unser <select> aus dem DOM
    (this.select.isConnected === false), wird insertToolbar() erneut
    aufgerufen — das ist das einzige Signal, es gibt kein eigenes Event dafür.
 → reloadTasks()   [Zeile 287] → GET contentflow_task_list_open_for_page?pageUid=…
 → reloadMarkers() [Zeile 501] → GET contentflow_task_list_member_markers?pageUid=…
```

### 2.4 Marker: `visual-editor-markers.js` + `task-markers.js`

`TaskMarkers` (visual-editor-markers.js) verwaltet:

- Eine `<style id="contentflow-ve-markers">`, injiziert **in jedes
  `iframe.visual-editor-iframe`-Dokument** (nicht in `Styles.css`! — dieses
  Dokument ist die gerenderte Frontend-Seite, die `Styles.css` nie erreicht,
  siehe Kommentarblock `visual-editor-markers.js:40-56`). `EXT:visual_editor`
  lockert dafür im Edit-Modus CSP `style-src` auf `unsafe-inline`.
- Pro `<ve-content-element>`: eine farbige `outline` (Klasse
  `contentflow-task-claimed`) + eine kleine runde „Bubble" oben rechts
  (`.contentflow-task-bubble`) als Button, der bei Klick
  `openTicket(taskUid)` aufruft — derselbe Modal-Call wie in `board.js`.
- `observeMutations()` — ein `MutationObserver` pro Frame-Dokument, weil
  `EXT:visual_editor` Content-Elemente bei Save/Move/Sprachsynchronisation
  neu rendert und dabei die Marker mit wegwirft. Läuft **debounced** über
  `requestAnimationFrame`, damit Tippen im Editor nicht bei jedem
  eingefügten Zeichen einen kompletten Re-Scan auslöst.

**Identitätsproblem** (warum `task-markers.js` als reine Funktionsbibliothek
existiert, ohne DOM-Zugriff, separat testbar): Eine `tx_contentflow_task_member`-
Zeile hält die **LIVE**-UID eines Datensatzes. Der Visual Editor rendert die
Seite jedoch Workspace-overlaid und schreibt auf jedes `ve-content-element`
`uid = localizedUid ?: versionedUid ?: uid` — `versionedUid` ist dabei die
**Versions**-UID. Genau der interessante Fall (ein gerade bearbeiteter Task)
würde bei einem reinen UID-Vergleich nie matchen. Deshalb sendet der Server
(`TaskAjaxController::memberIdentifiers()`, Zeile 845) für jedes Mitglied
**beide** Schreibweisen, und `claimFor()`/`claimsByIdentifier()`
(task-markers.js) matchen gegen jede davon.

Die Legende (`renderLegend()`, visual-editor-markers.js:316) zeigt jeden
Task, der die Seite berührt, als Farbpunkt + Titel neben dem Select; Hover
über einen Legenden-Eintrag ruft `highlight(taskUid)` auf, was alle Marker
auf der Seite neu zeichnet (`markElements()`) und den betroffenen Task per
`HIGHLIGHT_CLASS` hervorhebt.

### 2.5 Klick-Abläufe (Visual Editor)

#### a) Visual Editor öffnen

```
Navigation ins VE-Modul
 → #typo3-contentIframe lädt EXT:visual_editor
 → 'load'-Event → tryMount() → VisualEditorTaskSelect.mount()
 → Select + Legende erscheinen im Toolbar-Slot
 → reloadTasks() befüllt das <select> (inkl. bereits aktivem Task, falls
   ActiveTaskSession noch einen für diese Seite kennt)
 → reloadMarkers() zeichnet vorhandene Bubbles auf bereits geclaimte Elemente
```

#### b) Einen Task im Dropdown auswählen

```
change-Event auf <select>
 → onChange()   [Zeile 341]
    Wert === CREATE_VALUE → createTask() (siehe c)
    Wert === NONE_VALUE → taskUid = 0 (Deklaration zurücknehmen)
    sonst → taskUid = parseInt(value)
 → POST contentflow_task_set_active_for_page { pageUid, taskUid }
 → TaskAjaxController::setActiveTaskForPageAction()  [Zeile 649]
    taskUid === 0 → ActiveTaskSession::forget()  → JsonResponse (nichts weiter)
    sonst:
      task noch ohne Workspace-Version (Backlog/Planned)?
        → TaskRepository::attachWorkspace(taskUid, workspaceUid, STAGE_EDIT_ID)
          (Backlog/Planned → Editing, genau der Übergang, den ein erster
          erfasster Edit sonst reaktiv auslösen würde — hier nur vorgezogen)
      task in Review/Ready?
        → StageTransitionService::transition(…, STAGE_EDIT_ID, …)
          (Rückstufung nach Editing MIT automatischem Kommentar
          „ve.comment.reopened")
      → ActiveTaskSession::rememberForContext('pages', pageUid, taskUid)
 → Antwort: { success, taskUid, state, stageLabel, transitioned, comment, commentUid }
 → onChange() aktualisiert Options-Text „Titel (Stage)"
 → falls transitioned: Notification.success(„taskActive")
 → falls comment vorhanden: offerCommentEdit() öffnet Popover zum Bearbeiten
   des Auto-Kommentars → Speichern-Button
     → POST ajaxUrls.wizard_submit?mode=contentflow_task_wizard
       { mode:'regression_comment', taskUid, commentUid, content }
       (generische Core-Route, siehe Classes/Wizard/TaskWizardProvider.php)
 → reloadMarkers() — Marker/Legende sofort aktualisiert, damit die eigenen
   Elemente ab jetzt als „aktiv" (Ring statt Punkt) erscheinen
```

**Wichtig für die Erwartungshaltung:** Die Auswahl im VE-Select ist eine
**Vor-Deklaration** ("Edits auf dieser Seite gehen an diesen Task"), keine
Reaktion auf einen Save. Grund: `EXT:visual_editor` autosaved bei jedem paar
Tastenanschlägen (`ve-auto-save-toggle.js`), ein Modal nach jedem Autosave
wäre unbenutzbar.

#### c) „+ Create new task" im Dropdown

```
onChange() erkennt CREATE_VALUE → createTask()   [Zeile 388]
 → POST contentflow_task_create { table:'pages', uid: pageUid, title:'' }
 → TaskAjaxController::createAction()  (Titel leer → deriveTitle() nimmt
   den Seitentitel als Default)
 → reloadTasks() → neuen Task im Select vorauswählen
 → onChange() erneut aufrufen (setzt ihn aktiv, siehe b)
```

#### d) Auf eine Marker-Bubble oder einen Legenden-Punkt klicken

```
Klick auf .contentflow-task-bubble  ODER  .contentflow-ve-legend-swatch
 → TaskMarkers.openTicket(taskUid)   [visual-editor-markers.js:379]
 → Modal.advanced({ type: Modal.types.ajax, content: ajaxUrls.contentflow_task_ticket + '&task=' + taskUid })
```
Läuft in der **Backend-Chrome** (top-Dokument), weil `TaskMarkers` selbst
dort instanziiert wird (`moduleDoc` = `#typo3-contentIframe`-Dokument, das
bereits im obersten Backend-Kontext liegt) — deshalb landet das Modal exakt
dort, wo `Styles.css` bereits geladen ist (Abschnitt 0), ohne Postmessage-
Bridge.

#### e) Speichern im Visual Editor (Autosave/manuell)

Kein direkter JS→PHP-Aufruf dieser Extension. Der Save läuft komplett durch
`EXT:visual_editor`. Serverseitig greift derselbe DataHandler-Hook wie
überall (`TaskAutoCreationDataHandlerHook::captureEdit()`, Abschnitt 0), der
den bearbeiteten Datensatz — falls per `ActiveTaskSession` ein Task aktiv
deklariert ist — genau an **diesen** Task hängt statt (wie ohne Deklaration)
automatisch einen neuen zu suchen/anzulegen. Die Marker aktualisieren sich
**nicht live** nach jedem Autosave; sie werden neu geladen, sobald die
Toolbar neu gerendert wird (`watchToolbar()`, siehe 2.3) oder bei einer
Task-Auswahl (`onChange()` → `reloadMarkers()`).

### 2.6 AJAX-Routen des Visual Editor

Bereits in der Tabelle in Abschnitt 1.6 enthalten (VE-spezifische Zeilen
sind dort markiert): `contentflow_task_list_open_for_page`,
`contentflow_task_set_active_for_page`, `contentflow_task_list_member_markers`,
sowie `contentflow_task_create` (VE „+ Create") und `contentflow_task_ticket`
(Bubble-/Legenden-Klick). Zusätzlich die generische Core-Route
`wizard_submit?mode=contentflow_task_wizard` für den Regressions-Kommentar
(nicht in `AjaxRoutes.php`, sondern über `Classes/Wizard/
TaskWizardProvider.php` an Core angebunden).

### 2.7 Styling anpassen (Visual Editor)

Zwei getrennte, **inline injizierte** Style-Blöcke — bewusst **nicht** in
`Styles.css`, weil die beiden Zieldokumente `Styles.css` nie laden:

| Style-Block | Konstante | Ziel-Dokument | Datei/Zeile |
|---|---|---|---|
| Toolbar-Select + Legende | `TOOLBAR_STYLES` | `#typo3-contentIframe`-Dokument | [visual-editor-task-select.js:53-138](Resources/Public/JavaScript/task/visual-editor-task-select.js) |
| Content-Element-Marker (Outline, Bubble) | `MARKER_STYLES` | jedes `iframe.visual-editor-iframe`-Dokument | [visual-editor-markers.js:57-125](Resources/Public/JavaScript/task/visual-editor-markers.js) |

Um z. B. die Bubble-Größe oder Position zu ändern: `.contentflow-task-bubble`
in `MARKER_STYLES` (visual-editor-markers.js, `top`/`right`/`width`/`height`)
bearbeiten — **nicht** in `Styles.css` suchen, dort existiert diese Klasse
nicht.

Die Farbe selbst kommt in beiden Blöcken über die CSS-Custom-Property
`--contentflow-task-hue` (pro Element per `element.style.setProperty(...)`
gesetzt, siehe `markElements()`), berechnet aus `hueForTaskUid()`
(`task-markers.js:20`) — dieselbe Formel wie `TaskColor::hueFor()` in PHP
(Abschnitt 1.7). Eine Änderung der Formel muss **in beiden Sprachen
gleichzeitig** erfolgen, sonst weichen Board/Layout-Farbe und VE-Farbe für
denselben Task auseinander.

Labels im Toolbar/Marker (`labels.get('ve.marker.unassigned')` etc.) kommen
aus `~labels/content_flow.messages` — das ist die XLF-Datei unter
`Resources/Private/Language/locallang.xlf` mit Schlüsseln unter `ve.*`.

---

## 3. Layout-Integration (Seite/Layout-Modul, `web_layout`)

„Layout" meint hier **nicht** ein Fluid-`Layouts/`-Verzeichnis, sondern
TYPO3s eigenes Seite/Layout-Backend-Modul (`web_layout`) — die dritte
Bearbeitungsoberfläche neben Visual Editor und Records (Datensatz-Formular),
wie auch im Code kommentiert (`ActiveTaskSession.php:18`: „Visual Editor,
Layout, Records").

Zwei unabhängige Event-Listener hängen sich dort ein:

```
Classes/EventListener/PageModuleEventListener.php
  → ModifyPageLayoutContentEvent → Banner oben im Modul (Abschnitt 3.1)

Classes/EventListener/ContentElementTaskBadgeListener.php
  → AfterPageContentPreviewRenderedEvent → Badge an jedem Content-Element
    (Abschnitt 3.2)
```

### 3.1 `PageModuleEventListener` → Banner

```
Seite im Layout-Modul öffnen
 → Core feuert ModifyPageLayoutContentEvent
 → PageModuleEventListener::__invoke()   [Zeile 50]
    $pageUid aus Request-Query 'id'
    → TaskRepository::findAllOpenForPage($pageUid)
      (JEDE offene Aufgabe, die diese Seite berührt — nicht nur die, deren
      Subjekt sie ist: eine Seite trägt oft die eigene Seiten-Aufgabe PLUS
      jede Aufgabe, die ein Content-Element auf ihr beansprucht hat)
    → ActiveTaskSession::resolve() — welcher Task ist gerade „aktiv" (aus dem VE-Select)
    → jeder Task bekommt: hue (TaskColor::hueFor), isActive, assigneeName
    → pageRenderer->addCssFile(Styles.css), loadJavaScriptModule(board.js)
      (board.js NOCHMAL laden — für die Buttons im Banner selbst, siehe unten)
    → inlineSettings: elementBrowserUrl, createTargetTables, currentPageId
    → ViewFactory rendert PageModule/Banner.html
    → $event->addHeaderContent($view->render(...))
      → Core setzt das HTML oben im Modul-Content-Bereich ein
```

`board.js` wird hier **erneut** per `loadJavaScriptModule()` angestoßen
(zusätzlich zu `wizard.js` aus `LoadWizardModuleEventListener`) — das ist
kein Duplikat-Bug: `initialize()` in `board.js` registriert
`registerCreateButton`/`registerTicketButtons`/`registerAssignButtons` immer,
unabhängig davon, ob `.contentflow-board` existiert (siehe Abschnitt 1.4) —
genau diese drei braucht das Banner.

### 3.2 `ContentElementTaskBadgeListener` → Element-Badge

```
Core rendert die Vorschau eines Content-Elements im Seite-Modul
 → AfterPageContentPreviewRenderedEvent
 → ContentElementTaskBadgeListener::__invoke()   [Zeile 46]
    claimsFor($pageUid) — einmal pro Request pro Seite berechnet (nicht pro
    Element!), sonst würde jede Vorschau eine eigene DB-Abfrage auslösen
    → schaut nach, ob {table}:{uid} des Elements zu einem offenen Task gehört
    → falls ja: renderBadge() setzt ein <div class="contentflow-element-badge">
      VOR den bestehenden Vorschau-Inhalt ($event->setPreviewContent())
```

Dieser Listener implementiert **keine** eigene Preview-Renderer-Logik — er
stellt nur eine Zeile *vor* das, was das Element (oder eine andere
Extension) ohnehin schon gerendert hat. Deshalb funktioniert er für jeden
Content-Element-Typ ohne CType-spezifischen Code.

### 3.3 Klick-Abläufe (Layout-Modul)

#### a) Seite öffnen → Banner erscheint

Kein Klick nötig — läuft beim Rendern (3.1). Zeigt entweder die Liste
offener Tasks (mit farbigem Punkt, Titel, Stage-Badge, „you are working on
this"-Badge falls aktiv, Zuweisungsname oder „Assign to me"-Button) oder,
falls keiner existiert, „No open task for this page" + „Plan task for this
page"-Button.

#### b) „Plan task for this page" (nur wenn noch kein Task existiert)

```
Klick auf [data-contentflow-action="create-task"][data-contentflow-page]
 → create-wizard.js: registerCreateButton()
    bannerPageId = button.dataset.contentflowPage  (> 0, da im Banner immer gesetzt)
    → openNewTaskWizard('pages', bannerPageId, pageTitle)   [Abschnitt 1.5e, Variante mit Vorauswahl]
    → <contentflow-task-wizard .pending={mode:'create_from_picker', table:'pages', uid, recordTitle}>
    → Wizard-Abschluss → POST contentflow_task_create → createAction()
```

Das ist derselbe `registerCreateButton()`-Code wie im Board — der einzige
Unterschied ist, dass hier `data-contentflow-page` bereits gesetzt ist und
deshalb der Auswahl-Dialog (4 Optionen) übersprungen wird.

#### c) „Open in Board" (wenn mindestens ein Task existiert)

Reiner Link (`<a href="{boardUrl}">`), kein JS — `boardUrl` wurde
serverseitig mit `UriBuilder::buildUriFromRoute('web_contentflow', ['id' =>
pageUid])` gebaut. Führt direkt zu Abschnitt 1.

#### d) „Assign to me" im Banner

```
Klick auf .contentflow-action-assign (im Banner, gleiche Klasse wie auf der Karte)
 → task/assign.js: registerAssignButtons()   (bereits registriert, s. 3.1)
 → POST contentflow_task_assign_me → assignMeAction()  [identisch zu Abschnitt 1.5g]
```

#### e) Auf einen Task-Titel im Banner klicken

```
Klick auf [data-contentflow-open-ticket] (im Banner)
 → ticket.js: registerTicketButtons()  → board.openTicket()  [identisch zu Abschnitt 1.5f]
```

#### f) Ein Content-Element im Layout-Modul bearbeiten

```
Klick auf ein Content-Element → Core öffnet Bearbeitungsformular
 (Modal-Iframe via <typo3-backend-contextual-record-edit-trigger>, kein
 vollständiger Backend-Reload)
 → Speichern → DataHandler → TaskAutoCreationDataHandlerHook::captureEdit()
   (Abschnitt 0) — hängt das Element an den aktiven/neuen Task
 → Core sendet postMessage 'typo3:editform:saved' an top
 → wizard.js hat genau darauf einen eigenen Listener (Zeile 42-46), WEIL
   DocumentService.ready() für diesen Save nicht erneut feuert (kein
   vollständiger Seiten-Reload) → checkPendingWizard() wird manuell erneut
   aufgerufen
 → GET contentflow_task_wizard_pending → getPendingWizardAction()
   liefert ggf. den Post-Save-Wizard (z. B. „Route member" oder
   „Reopened"-Kommentar-Schritt) → wizard.js: openWizard(pending)
```

Nach dem Schließen des Bearbeitungs-Modals rendert Core den Content-
Container neu, wodurch `ContentElementTaskBadgeListener` (3.2) erneut
läuft und das Badge am (jetzt evtl. neu geclaimten) Element zeigt.

### 3.4 Styling anpassen (Layout-Modul)

Beide Bereiche nutzen `Styles.css` (wird durch `PageModuleEventListener`
selbst UND durch `LoadWizardModuleEventListener` geladen — hier gibt es kein
Iframe-Problem wie beim VE, weil das Layout-Modul im normalen Backend-
Content-Iframe läuft, das dieselbe `Styles.css` zieht):

| Bereich | Klassen | Datei/Zeilen |
|---|---|---|
| Banner-Rahmen/Layout | `.contentflow-page-banner`, `-info`, `-actions` | [Styles.css:391-419](Resources/Public/Css/Styles.css) |
| Task-Zähler-Badge | `.contentflow-page-banner-count` | Styles.css:427-438 |
| Task-Zeile im Banner | `.contentflow-page-banner-task`, `--active` | Styles.css:449-471 |
| Farbpunkt | `.contentflow-task-dot` (gemeinsam mit VE-Legende) | Styles.css:457-471 |
| „aktiv"-Badge in Worten | `.contentflow-badge-active` | Styles.css:490-494 |
| Element-Badge im Seiteninhalt | `.contentflow-element-badge`, `--active` | Styles.css:504-521 |

Markup-Änderungen am Banner selbst (z. B. zusätzliche Info anzeigen) gehören
in [Resources/Private/Templates/PageModule/Banner.html](Resources/Private/Templates/PageModule/Banner.html)
— dort werden die von `PageModuleEventListener` übergebenen Variablen
(`tasks`, `pageUid`, `pageTitle`, `boardUrl`, `activeTaskUid`) per Fluid
ausgegeben. Neue Daten müssen zuerst in `PageModuleEventListener::__invoke()`
per `$view->assignMultiple()` ergänzt werden, sonst stehen sie im Template
nicht zur Verfügung.

Das Element-Badge-Markup wird dagegen **nicht** in Fluid gerendert, sondern
als PHP-String in `ContentElementTaskBadgeListener::renderBadge()`
zusammengesetzt (Zeile 69) — Änderungen daran direkt in dieser Methode,
inklusive manuellem `htmlspecialchars()` für Titel/Tooltip (kein Fluid-
Escaping vorhanden, da kein Template beteiligt ist).

---

## 4. Übergreifendes Cheatsheet

### 4.1 „Wo lädt was, wann?" — Kurzreferenz

| Oberfläche | PHP-Einstieg | JS-Modul geladen von | CSS geladen von |
|---|---|---|---|
| Board | `ContentFlowController::indexAction()` | `board.js` (direkt) | `ContentFlowController` + `LoadWizardModuleEventListener` |
| Visual Editor | (kein eigener Controller — reine Backend-Chrome) | `wizard.js` → `visual-editor-task-select.js` (dynamischer Import) | injizierte `<style>`-Blöcke, **nicht** `Styles.css` |
| Layout-Modul | `PageModuleEventListener::__invoke()` | `board.js` (erneut, für Banner-Buttons) + `wizard.js` | `PageModuleEventListener` + `LoadWizardModuleEventListener` |
| Record-/sonstiges Modul | `ActiveTaskButtonBarEventListener::__invoke()` | `active-task-control.js` | `ActiveTaskButtonBarEventListener` |
| Jede Backend-Seite (global) | `LoadWizardModuleEventListener::__invoke()` | `wizard.js` | `Styles.css` |

### 4.2 Vorgehen, um zu einem Klick den Code zu finden

1. Im Browser DevTools das angeklickte Element inspizieren → nach
   `data-contentflow-*`-Attribut oder Klassenname suchen
   (z. B. `data-contentflow-action="create-task"`).
2. `grep -rn "data-contentflow-action" Resources/Public/JavaScript` bzw. den
   konkreten Attributnamen — führt zur registrierenden JS-Datei.
3. In dieser Datei den `AjaxRequest(...)`-Aufruf suchen → Name unter
   `TYPO3.settings.ajaxUrls.contentflow_*`.
4. Diesen Routennamen in
   [Configuration/Backend/AjaxRoutes.php](Configuration/Backend/AjaxRoutes.php)
   nachschlagen → Ziel-Methode in `TaskAjaxController`.
5. In der Methode nachsehen, welche Repository-/Service-Aufrufe folgen.

Umgekehrt (PHP → UI): `grep -rn "ajaxUrls.contentflow_<route>"
Resources/Public/JavaScript` findet alle Aufrufer einer Route.

### 4.3 Drei konkrete Styling-Rezepte

**„Ich will die Kartenfarbe bei Fälligkeit anpassen."**
→ Farbwerte in `ext_localconf.php`/EXTCONF (`dueDateThresholds.warningColor`/
`overdueColor`), **nicht** in `Styles.css` — die Datei liest die Werte nur
über `var(--contentflow-due-soon, …)`. Siehe Abschnitt 1.7.

**„Ich will die Bubble-Größe im Visual Editor ändern."**
→ `MARKER_STYLES`-Konstante in
[visual-editor-markers.js:57](Resources/Public/JavaScript/task/visual-editor-markers.js),
**nicht** `Styles.css` — dieses Dokument lädt die Datei nie. Siehe
Abschnitt 2.7.

**„Ich will die Spaltenbreite/den Kartenabstand im Board ändern."**
→ `--contentflow-col-width` bzw. `--contentflow-gap` in
[Styles.css:4-25](Resources/Public/Css/Styles.css). Siehe Abschnitt 1.7.
