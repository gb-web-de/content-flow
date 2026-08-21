---
name: typo3-cgl-testing
description: Standards for TYPO3 Coding Guidelines (CGL), PHPStan, php-cs-fixer, PHPUnit Unit and Functional Testing, database fixtures, and automated testing framework based on official TYPO3 core documentation.
---

# TYPO3 Extension Testing & Coding Guidelines (CGL)

This skill provides guidelines for enforcing TYPO3 Coding Guidelines (CGL) and building automated testing suites using `typo3/testing-framework`, based on official TYPO3 docs (`https://docs.typo3.org/m/typo3/reference-coreapi/main/en-us/Testing/ExtensionTesting.html`).

---

## 1. TYPO3 Coding Guidelines (CGL)

TYPO3 code strictly adheres to **PSR-12 / PER Coding Style Standard** with TYPO3-specific enhancements.

### Core PHP Rules
- **Strict Types**: Every PHP file MUST start with `declare(strict_types=1);` immediately after `<?php`.
- **PHP 8.2+ Syntax**: Use constructor property promotion, readonly properties, match expressions, and typed properties.
- **Naming Conventions**:
  - Class names: `PascalCase` (`TaskRepository`, `TaskAutoCreationListener`).
  - Method & Property names: `camelCase` (`findOpenBySubject`, `stageUid`).
  - Constants: `UPPER_CASE_WITH_UNDERSCORES` (`EVENT_STAGE_CHANGED`).
  - Interfaces: End with `Interface` (`ViewFactoryInterface`).
- **File Structure**:
  ```php
  <?php

  declare(strict_types=1);

  namespace Vendor\ExtensionName\SubNamespace;

  use TYPO3\CMS\Core\Attribute\AsEventListener;

  #[AsEventListener(identifier: 'vendor/ext-identifier')]
  final class MyEventListener
  {
      public function __construct(
          private readonly DependencyService $dependencyService,
      ) {
      }
  }
  ```

### Code Quality Tooling
- **PHP CS Fixer**: Configured via `.php-cs-fixer.dist.php` extending `TYPO3\CodingStandards\CsFixerConfig`.
  ```bash
  vendor/bin/php-cs-fixer fix --config=.php-cs-fixer.dist.php
  ```
- **PHPStan**: Static analysis configured with `saschaegerer/phpstan-typo3`.
  ```bash
  vendor/bin/phpstan analyse -c phpstan.neon
  ```

---

## 2. Extension Testing Framework (`typo3/testing-framework`)

TYPO3 extensions use `typo3/testing-framework` for reliable unit and functional testing.

### Test Directory Layout
```text
Build/
Tests/
├── Unit/
│   └── Domain/Model/TaskTest.php
└── Functional/
    └── Domain/Repository/TaskRepositoryTest.php
```

---

## 3. Writing Unit Tests

Unit tests test isolated logic without booting the full TYPO3 framework or database.

- **Base Class**: `TYPO3\TestingFramework\Core\Unit\UnitTestCase` or `PHPUnit\Framework\TestCase`.

```php
<?php

declare(strict_types=1);

namespace Vendor\ExtensionName\Tests\Unit\Domain\Model;

use TYPO3\TestingFramework\Core\Unit\UnitTestCase;

final class TaskTest extends UnitTestCase
{
    public function testTaskStateCanBeUpdated(): void
    {
        $task = new Task('Test Task');
        $task->setState('in_progress');
        self::assertSame('in_progress', $task->getState());
    }
}
```

---

## 4. Writing Functional Tests

Functional tests boot an isolated TYPO3 backend instance with a clean SQLite/MySQL database.

- **Base Class**: `TYPO3\TestingFramework\Core\Functional\FunctionalTestCase`.
- **Core Extensions**: Declare required core extensions in `$coreExtensionsToLoad`.
- **Test Extensions**: Declare custom extension path in `$testExtensionsToLoad`.

```php
<?php

declare(strict_types=1);

namespace Vendor\ExtensionName\Tests\Functional\Repository;

use TYPO3\TestingFramework\Core\Functional\FunctionalTestCase;

final class TaskRepositoryTest extends FunctionalTestCase
{
    protected array $coreExtensionsToLoad = ['workspaces', 'dashboard'];
    protected array $testExtensionsToLoad = ['typo3conf/ext/editorial_flow'];

    protected function setUp(): void
    {
        parent::setUp();
        $this->importCSVDataSet(__DIR__ . '/../Fixtures/Database/tasks.csv');
    }

    public function testFindOpenBySubjectReturnsMatchingTask(): void
    {
        $repository = $this->get(TaskRepository::class);
        $task = $repository->findOpenBySubject('pages', 12);
        self::assertNotNull($task);
        self::assertSame('Page 12 Revision', $task['title']);
    }
}
```

---

## 5. Automated CI Integration

Run tests in GitHub Actions or CI pipeline:

```bash
# Execute Unit Tests
vendor/bin/phpunit -c Build/phpunit/UnitTests.xml

# Execute Functional Tests
vendor/bin/phpunit -c Build/phpunit/FunctionalTests.xml
```
