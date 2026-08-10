---
name: typo3-backend-wizard
description: Implement, debug, or extend native TYPO3 backend wizards, especially the page creation wizard, `WizardProviderInterface` providers, PHP step builders, Lit-based `@typo3/backend/wizard` and `@typo3/backend/page-wizard` modules, dynamic wizard steps, and wizard submit/finisher flows. Use when working on backend wizard providers, `page-wizard.ts`, `wizard.ts`, step modules, AJAX `wizard_config` or `wizard_submit` flows, or when replacing a custom backend dialog with TYPO3's native wizard stack.
---

# TYPO3 Backend Wizard

Implement wizard changes by following TYPO3's native provider, step, and finisher architecture instead of introducing bespoke backend modals or ad-hoc AJAX flows.

## Quick Start

- Read `references/native-architecture.md` before substantial changes.
- Keep the provider identifier consistent across PHP registration, frontend `loadDynamicSteps(...)`, and AJAX `mode` parameters.
- Reuse FormEngine and DataHandler for record forms and persistence whenever possible.
- Let `@typo3/backend/wizard/wizard.ts` own navigation, summary, focus handling, and finisher behavior.

## Choose the Layer

- Change the PHP provider when the issue is provider registration, request parsing, dynamic step configuration, submit handling, redirects, or DataHandler persistence.
- Change the PHP step builder when the issue is generated form steps, required-field grouping, labels, or FormEngine module initialization.
- Change the wizard host element when the issue is fixed step ordering, prefilled wizard configuration, or when dynamic steps should be requested.
- Change the generic wizard shell only when the issue is truly shared behavior such as navigation, summary, finisher, focus, reset, or auto-advance handling.

## Build the Native Flow

1. Register a provider with `#[AsTaggedItem(index: '...')]` on a class implementing `WizardProviderInterface`.
2. Return `Configuration::create([...])` from `getConfiguration()` and `SubmissionResult` from `handleSubmit()`.
3. Default-export each dynamic TypeScript step class and keep its module path identical to the PHP `Step::create(...)` path.
4. Persist step state through the wizard store and `beforeAdvance()`, not through ad-hoc globals.
5. Implement submission in a `SubmissionServiceInterface` and let the generic finisher step present the result.

## Preserve Native Contracts

- Use one provider identifier end to end: DI tag index, `loadDynamicSteps('identifier', ...)`, and submit/config AJAX `mode`.
- Prefer dynamic steps for backend-driven forms; let PHP decide the step list and field grouping.
- Keep step `key` values stable because summary, navigation, and store writes depend on them.
- Use core DTOs and step interfaces instead of inventing alternative payload shapes.
- Allow the generic shell to append confirm and finisher steps unless there is a strong reason to skip summary.

## Debug in This Order

- Verify provider lookup and AJAX mode before debugging frontend rendering.
- Verify dynamic import paths and default exports before changing the shell.
- Verify `beforeAdvance()` store writes before debugging confirm or submit payloads.
- Verify FormEngine module initialization before patching custom field behavior.
- Verify submission payload shape and backend `handleSubmit()` expectations before changing finishers.

## Host the Wizard in a Modal

- Pass an explicit `size: { width, height }` to every `Modal.advanced()` that hosts a wizard, as `openPageWizardModal()` does. This is load-bearing, not cosmetic.
- Never let the modal size itself to the wizard's content: `backend.css` sets `.modal-body typo3-backend-wizard { position: absolute; inset: 0 }`, so the wizard contributes no height and the modal collapses to a sliver.
- Expect a collapsed modal to look like a header, a progress bar and nothing else - the step markup is in the DOM and even keeps a bounding box, it is only clipped.
- Assert containment inside `.wizard-content`, not `toBeVisible()`, when a test has to guard this - clipped controls still pass a visibility check.

## Localize Step Labels

- Read editor-facing strings in step modules from a translation domain, `import labels from '~labels/<extension_key>.messages'`, rather than writing them into the JavaScript.
- Note that core derives the domain from the label file's own path, so `Resources/Private/Language/locallang.xlf` is `<extension_key>.messages` with nothing to register.
- Remember that `LabelProvider.get()` throws on an unknown key, which rejects the dynamic step import and empties the wizard - cover the keys the modules ask for.

## Common Pitfalls

- Dynamic steps never load because the provider identifier, AJAX `mode`, and frontend loader string do not match.
- The wizard renders blank because the PHP step module path does not match a default-exporting TypeScript step class.
- The wizard shows its chrome but no form because its modal was opened without an explicit size.
- Values disappear on confirm because a step never writes to the store in `beforeAdvance()`.
- Summary or finisher appears twice because a custom wizard duplicates behavior already appended by `wizard.ts`.
- Backend permissions, labels, or record handling drift because custom forms bypass FormEngine or DataHandler.

## Reference

Read `references/native-architecture.md` for the native file map, request flow, implementation checklist, and focused debugging notes.
