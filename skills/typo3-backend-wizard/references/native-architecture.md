# Native TYPO3 Backend Wizard Architecture

## Core File Map

### PHP provider layer

- `typo3/sysext/backend/Classes/Wizard/WizardProviderInterface.php`
  The provider contract. Implement `getConfiguration()` and `handleSubmit()`. Tag providers with `#[AsTaggedItem(index: '...')]`.
- `typo3/sysext/backend/Classes/Wizard/WizardProviderRegistry.php`
  Resolves the provider by identifier from the `backend.wizard.provider` service locator.
- `typo3/sysext/backend/Classes/Wizard/PageWizardProvider.php`
  Native page-creation provider. Reads `doktype` and position data, asks the step builder for dynamic steps, and persists the new page via `DataHandler`.
- `typo3/sysext/backend/Classes/Wizard/PageWizardStepBuilder.php`
  Builds FormEngine-backed step DTOs from the page schema and wizard-step metadata.
- `typo3/sysext/backend/Classes/Wizard/DTO/*`
  Carries `Configuration`, `Step`, `Finisher`, and `SubmissionResult` objects between backend and frontend.

### Generic TypeScript wizard shell

- `Build/Sources/TypeScript/backend/wizard/wizard.ts`
  Shared Lit element that manages the step list, current step, summary, finisher, focus handling, keyboard behavior, and reset flow.
- `Build/Sources/TypeScript/backend/wizard/helper/dynamic-steps-loader.ts`
  Fetches step configuration from `wizard_config`, dynamically imports step modules, and instantiates them.
- `Build/Sources/TypeScript/backend/wizard/steps/confirm-step.ts`
  Summary/confirmation step appended by the shell.
- `Build/Sources/TypeScript/backend/wizard/steps/finisher-step.ts`
  Final success or error step appended by the shell.
- `Build/Sources/TypeScript/backend/wizard/steps/wizard-step-interface.ts`
  Base contract for step classes.
- `Build/Sources/TypeScript/backend/wizard/events/before-next-step-event.ts`
  Hook for intercepting navigation and asynchronously loading or reshaping steps.

### Page wizard overlay

- `Build/Sources/TypeScript/backend/page-wizard/page-wizard.ts`
  Page-specific host. Starts with fixed steps, loads backend-driven steps after doktype selection, and wires the page submission service.
- `Build/Sources/TypeScript/backend/page-wizard/page-wizard-configuration.ts`
  Optional prefill contract for doktype and position.
- `Build/Sources/TypeScript/backend/page-wizard/steps/position-step.ts`
  Fixed first step that stores target page and insert position.
- `Build/Sources/TypeScript/backend/page-wizard/steps/doktype-step.ts`
  Fixed second step that loads selectable doktypes, supports preselection, and auto-advances when appropriate.
- `Build/Sources/TypeScript/backend/page-wizard/steps/form-engine-step.ts`
  Generic renderer for backend-supplied FormEngine HTML steps.
- `Build/Sources/TypeScript/backend/page-wizard/finisher/page-wizard-submission-service.ts`
  Flattens stored field data, posts to `wizard_submit`, and refreshes the page tree.
- `Build/Sources/TypeScript/backend/page-wizard/new-page-wizard-button.ts`
  Entry-point button wiring for launching the page wizard.

## Native Request Flow

```text
Launch button
  -> page-wizard.ts
  -> fixed steps: position, doktype
  -> wizard-before-next-step after doktype
  -> loadDynamicSteps('page_wizard', context)
  -> AJAX wizard_config?mode=page_wizard&data=...
  -> PageWizardProvider::getConfiguration()
  -> PageWizardStepBuilder::getStepsForDokType()
  -> Step::create('@typo3/backend/page-wizard/steps/form-engine-step.js')
  -> dynamic TS step instances render in typo3-backend-wizard
  -> generic wizard appends confirm + finisher
  -> PageWizardSubmissionService posts wizard_submit?mode=page_wizard
  -> PageWizardProvider::handleSubmit()
  -> DataHandler persists record and returns redirect finisher
```

## Core Behaviors to Preserve

- Treat the provider identifier as a contract. `page_wizard` must match the DI tag index, the dynamic-step loader argument, and the submit/config `mode`.
- Return step modules from PHP, not class names. The frontend dynamically imports the module path in `Step::create(...)`.
- Default-export each step class. The loader expects `import(step.module).default`.
- Store state in the wizard data store. `beforeAdvance()` is the normal place to write step data.
- Let `wizard.ts` own confirm and finisher behavior. Custom wizards usually pass only their functional steps.

## Page Wizard Details Worth Reusing

- `PageWizardProvider` validates that `data[doktype]` exists before building steps.
- `PageWizardProvider` converts an "after" insert position into a negative `pid`, following `DataHandler` conventions for insertion after an existing record.
- `PageWizardStepBuilder` reads the page sub-schema for the selected doktype and iterates `getWizardSteps()`.
- `PageWizardStepBuilder` collects required fields that were not already covered by explicit wizard steps and adds one final required-fields step when needed.
- `PageWizardStepBuilder` compiles FormEngine data with `command => 'new'`, record type equal to the selected doktype, and `renderType => 'listOfFieldsContainer'`.
- The generated FormEngine step injects both HTML and JavaScript modules, including `@typo3/backend/form-engine.js`.
- `DoktypeStep` can preselect a configured doktype, auto-advance on a single allowed choice, and warn when a prefilled doktype is not allowed.
- `PageWizardSubmissionService` flattens `fields` from the store into the POST payload before calling `wizard_submit`.

## Implementation Checklist

1. Choose the wizard identifier and keep it stable everywhere.
2. Implement or update the provider class and tag it with `#[AsTaggedItem(index: '...')]`.
3. Return a `Configuration` with step DTOs whose module paths point to real frontend modules.
4. Build fixed steps in the host element only for universally present choices; fetch backend-driven steps once enough context exists.
5. Make each step class implement the right interfaces:
   `WizardStepInterface` always, plus `WizardStepValueInterface`, `WizardStepSummaryInterface`, or after-render hooks when needed.
6. Write to the store in `beforeAdvance()` and read from the store when a step must restore previous state.
7. Implement submission through a `SubmissionServiceInterface` so the generic finisher step can render the result.
8. Reuse FormEngine and DataHandler when the wizard edits TYPO3 records; avoid bypassing native permission and label handling.

## Debugging Checklist

- Provider cannot be found: verify the service tag and tag index.
- `wizard_config` succeeds but no UI appears: verify the module path and the default export of the step class.
- Dynamic steps never appear after a fixed step: verify the `wizard-before-next-step` hook and the loader argument.
- Confirm step is missing expected values: verify `beforeAdvance()` store writes and stable step keys.
- FormEngine fields render but behave incorrectly: verify injected JS modules and labels from the step builder.
- Submit fails or creates the record in the wrong place: verify payload flattening, `position` data, and the negative-`pid` convention for "after".
- Finisher shows success but the backend UI looks stale: verify the page-tree refresh event or redirect behavior.
