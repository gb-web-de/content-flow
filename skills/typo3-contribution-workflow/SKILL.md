---
name: typo3-contribution-workflow
description: Guidelines and standards for contributing to TYPO3 Core and official documentation, including Gerrit workflows, Git commit message conventions, Forge issue tracking, and code review processes based on official TYPO3 docs.
---

# TYPO3 Contribution Workflow & Git Conventions

This skill provides comprehensive instructions for contributing to TYPO3 Core, official extensions, and documentation following the official TYPO3 Contribution Workflow Guide (`https://docs.typo3.org/m/typo3/guide-contributionworkflow/main/en-us/`).

---

## 1. Commit Message Conventions (CGL)

Every commit submitted to TYPO3 Core or official repositories MUST follow strict prefix and formatting rules.

### Prefix Types
- `[FEATURE]`: Introduces a new feature or functionality. Requires documentation update.
- `[BUGFIX]`: Fixes a bug or unexpected behavior. Include unit test to prevent regression.
- `[TASK]`: Internal refactoring, code cleanup, dependency updates, non-functional maintenance.
- `[DOCS]`: Updates or adds documentation files (`.rst`, `.md`).
- `[BREAKING]`: Contains a breaking change or deprecation removal. MUST include a breaking change migration note in commit body.

### Commit Message Structure
```text
[TYPE] Short summary of change in imperative mood (max 52 chars)

More detailed explanatory text if needed. Wrap text at 72 characters.
Explain WHY the change is made, WHAT was fixed or added, and HOW it works.

Resolves: #12345
Releases: main, 13.4
Change-Id: I0123456789abcdef0123456789abcdef01234567
```

### Mandatory Tags & Footer Rules
- **Imperative Mood**: Use "Add feature", "Fix crash", "Refactor listener" (not "Added" or "Fixes").
- **Resolves**: Reference the Forge issue ID (`Resolves: #101234`).
- **Releases**: Specify target branches (`Releases: main, 13.4`).
- **Change-Id**: Generated automatically by Git hook for Gerrit code review.

---

## 2. Issue Tracking on Forge

TYPO3 uses **Forge** (`https://forge.typo3.org/`) for official issue tracking.

- **Creating an Issue**: Provide exact steps to reproduce, TYPO3 version, PHP version, expected vs actual behavior.
- **Linking Commits**: Reference issue numbers using `Resolves: #XXXXX` or `Related: #XXXXX` in the commit footer.

---

## 3. Gerrit & Code Review Workflow

TYPO3 Core contributions use **Gerrit** (`https://review.typo3.org/`) for patch review.

### Step-by-Step Workflow
1. **Setup Git Review**:
   ```bash
   git clone https://review.typo3.org/TYPO3/Core/typo3.git
   cd typo3
   scp -p -P 29418 username@review.typo3.org:hooks/commit-msg .git/hooks/
   ```
2. **Create Topic Branch**:
   ```bash
   git checkout -b task-101234-improve-datahandler
   ```
3. **Commit & Push to Gerrit**:
   ```bash
   git commit -m "[BUGFIX] Correct workspace record resolving in DataHandler"
   git review
   ```
4. **Updating a Patch Set**:
   - Make edits to existing branch.
   - Amend commit without changing Change-Id:
     ```bash
     git commit --amend
     git review
     ```

---

## 4. Documentation Standards for Contributions

When introducing features or breaking changes:
- Include reStructuredText (`.rst`) snippet files under `Documentation/TYPO3/` or update `.md` docs.
- Provide PHP code examples conforming to PHP 8.2+ and TYPO3 v14 APIs.
