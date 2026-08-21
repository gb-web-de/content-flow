---
name: typo3-core-api
description: Comprehensive reference for TYPO3 Core API, v14 architecture, PSR-14 event listeners, DataHandler operations, Workspace integration, Module registration, and Backend JavaScript modules based on official TYPO3 docs.
---

# TYPO3 Core API Reference & Architecture (v14 Standards)

This skill provides a reference for building extensions using TYPO3 Core APIs, v14 standards, and backend architecture, based on official TYPO3 Core API documentation (`https://docs.typo3.org/m/typo3/reference-coreapi/main/en-us/`).

---

## 1. Extension Directory & Configuration Architecture

Every TYPO3 v14 extension follows strict directory structure and configuration rules:

```text
my_extension/
├── Classes/
│   ├── Controller/          # Backend & Frontend Controllers
│   ├── Domain/
│   │   ├── Model/           # Domain Models & State Enums
│   │   └── Repository/      # Repositories & Query Builders
│   ├── EventListener/       # PSR-14 Event Listeners
│   └── Service/             # Business Logic & Workspaces Services
├── Configuration/
│   ├── Backend/
│   │   ├── AjaxRoutes.php   # Backend AJAX Route definitions
│   │   └── Modules.php      # Backend Module registration
│   ├── TCA/
│   │   └── Overrides/       # TCA Table Schema overrides
│   ├── JavaScriptModules.php # ES Module Import Maps
│   └── Services.yaml        # Dependency Injection definitions
├── Resources/
│   ├── Private/
│   │   ├── Templates/       # Fluid View Templates
│   │   ├── Partials/        # Fluid Component Partials
│   │   └── Layouts/         # Fluid Layout wrappers
│   └── Public/
│       ├── Css/             # Extension Stylesheets
│       └── JavaScript/      # ES6 JavaScript Modules
├── composer.json
└── ext_tables.sql           # Database schema tables
```

---

## 2. Event System: PSR-14 Event Listeners

In TYPO3 v14, PSR-14 Event Listeners replace legacy hooks across the system.

### Listener Registration via PHP Attributes
Register listeners using `#[AsEventListener]` in `Classes/EventListener/`. No `ext_localconf.php` entry required!

```php
<?php

declare(strict_types=1);

namespace Vendor\ExtensionName\EventListener;

use TYPO3\CMS\Backend\Controller\Event\ModifyPageLayoutContentEvent;
use TYPO3\CMS\Core\Attribute\AsEventListener;

#[AsEventListener(identifier: 'vendor/my-extension/page-header')]
final class PageHeaderEventListener
{
    public function __invoke(ModifyPageLayoutContentEvent $event): void
    {
        $pageUid = (int)($event->getRequest()->getQueryParams()['id'] ?? 0);
        if ($pageUid < 1) {
            return;
        }
        $event->addHeaderContent('<div class="my-custom-banner">Active Page ID: ' . $pageUid . '</div>');
    }
}
```

### Key TYPO3 v14 Events
- **DataHandler Operations**: `TYPO3\CMS\Core\DataHandling\Event\PostProcessDatabaseOperationsEvent`
- **Page Layout Header**: `TYPO3\CMS\Backend\Controller\Event\ModifyPageLayoutContentEvent`
- **Dashboard Widgets**: Registered via `Configuration/Services.yaml` under `tags: [{ name: 'dashboard.widget' }]`.

---

## 3. DataHandler & Database QueryBuilder

### DataHandler Operations
Always execute record mutations through `DataHandler` to respect permissions, history logging, and workspace versioning:

```php
use TYPO3\CMS\Core\DataHandling\DataHandler;
use TYPO3\CMS\Core\Utility\GeneralUtility;

$dataHandler = GeneralUtility::makeInstance(DataHandler::class);
$cmd = [
    'tt_content' => [
        123 => [
            'version' => [
                'action' => 'setStage',
                'stageId' => $targetStageUid,
            ],
        ],
    ],
];
$dataHandler->start([], $cmd);
$dataHandler->process_cmdmap();
```

### Database QueryBuilder (`ConnectionPool`)
Use `ConnectionPool` for read operations or custom table queries:

```php
use TYPO3\CMS\Core\Database\ConnectionPool;
use TYPO3\CMS\Core\Utility\GeneralUtility;

$queryBuilder = GeneralUtility::makeInstance(ConnectionPool::class)
    ->getQueryBuilderForTable('tx_myextension_task');

$records = $queryBuilder
    ->select('*')
    ->from('tx_myextension_task')
    ->where($queryBuilder->expr()->eq('closed', $queryBuilder->createNamedParameter(0, \PDO::PARAM_INT)))
    ->executeQuery()
    ->fetchAllAssociative();
```

---

## 4. Backend Controllers & Module Registration

### Module Registration (`Configuration/Backend/Modules.php`)
```php
<?php

return [
    'web_editorialflow' => [
        'parent' => 'web',
        'position' => ['after' => 'web_layout'],
        'access' => 'user',
        'iconIdentifier' => 'module-editorialflow',
        'labels' => 'LLL:EXT:editorial_flow/Resources/Private/Language/locallang_mod.xlf',
        'extensionName' => 'EditorialFlow',
        'controllerActions' => [
            \Vendor\EditorialFlow\Controller\EditorialFlowController::class => ['index'],
        ],
    ],
];
```

### Backend AJAX Routes (`Configuration/Backend/AjaxRoutes.php`)
```php
<?php

use Vendor\EditorialFlow\Controller\TaskAjaxController;

return [
    'editorialflow_task_details' => [
        'path' => '/editorialflow/task/details',
        'target' => TaskAjaxController::class . '::detailsAction',
    ],
];
```

---

## 5. JavaScript Modules & Asset Loading

### Module Map (`Configuration/JavaScriptModules.php`)
```php
<?php

return [
    'dependencies' => ['backend'],
    'imports' => [
        '@vendor/my-extension/' => 'EXT:my_extension/Resources/Public/JavaScript/',
    ],
];
```

### Loading Assets in Controller / Listener
```php
$this->pageRenderer->addCssFile('EXT:my_extension/Resources/Public/Css/Styles.css');
$this->pageRenderer->loadJavaScriptModule('@vendor/my-extension/board.js');
$this->pageRenderer->addInlineSetting('MyExtension', 'myConfig', ['key' => 'value']);
```

### Client-Side ES Module (`board.js`)
```javascript
import DocumentService from '@typo3/core/document-service.js';
import AjaxRequest from '@typo3/core/ajax/ajax-request.js';
import Notification from '@typo3/backend/notification.js';

DocumentService.ready().then(() => {
  console.log('TYPO3 Backend UI ready');
});
```
