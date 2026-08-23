# Contributing to TypeDock

Thank you for contributing to TypeDock!

TypeDock is a modern, security-conscious open-source PHP CMS designed for clean boundaries, predictable architecture, and shared-hosting compatibility. To keep the codebase robust, auditable, and maintainable, we follow the guidelines outlined below.

---

## 1. Branching Strategy & PR Lifecycle

All changes—including contributions from core developers, external contributors, and AI assistants—flow through Pull Requests. Direct commits to `main` are restricted.

### Branch Naming Conventions
Use descriptive prefixes for topic branches:

| Prefix | Purpose | Example |
|:---|:---|:---|
| `feat/` | New feature or architectural enhancement | `feat/scheduled-posts`, `feat/favicon-management` |
| `fix/` | Bug fix or boundary hardening | `fix/non-ascii-slugs`, `fix/redirect-matching` |
| `deps/` | Dependency upgrade or security patch | `deps/nanoid-advisory`, `deps/composer-group` |
| `docs/` | Documentation update | `docs/theme-guide-update` |
| `chore/` | Release preparation, CI, or toolchain update | `chore/bump-version-rc7` |

### Pull Request Lifecycle
1. **One Topic per Branch**:
   - Keep pull requests focused on a single change or cohesive feature. Avoid bundling unrelated fixes or features together.
2. **Open as Draft First**:
   - Open your PR early as a **Draft**. Outline the problem, the architectural rationale, and the test plan in the PR description.
3. **Local Pre-flight Checks**:
   - Ensure all automated checks pass locally before requesting review (see [Development & Pre-flight Checklist](#3-development--pre-flight-checklist)).
4. **Pass All CI Checks**:
   - All GitHub Actions workflows (multi-driver PHPUnit for SQLite/MySQL/PostgreSQL, Dependency Audit, Package Build, CodeQL) must pass completely.
5. **Review & Merge**:
   - Mark the PR as **Ready for review**. Once approved, changes are merged into `main` (Squash Merge for atomic feature branches, or standard Merge Commit where preserving commit history is desired).

---

## 2. Commit Conventions

TypeDock follows [Conventional Commits](https://www.conventionalcommits.org/) to maintain clean, searchable git history and simplify release changelog generation.

### Commit Format
```text
<type>(<scope>): <short summary>

- <detail 1>
- <detail 2>
```

### Commit Types
- `feat`: New feature or user-facing capability (e.g., `feat(content): add zero-cron query-time gate for scheduled posts`)
- `fix`: Bug fix (e.g., `fix(router): allow non-ascii characters in term slugs`)
- `sec`: Security boundary hardening (e.g., `sec(plugin): enforce admin authorization on iframe routes`)
- `refactor`: Code restructuring without behavioral changes
- `test`: Adding or updating test suites
- `docs`: Documentation changes
- `chore`: Tooling, version bumps, or maintenance tasks

---

## 3. Development & Pre-flight Checklist

Before committing or pushing changes, perform the following verification steps:

### ① Automated Tests (PHPUnit)
Run the full test suite across SQLite, unit tests, and integration tests:
```bash
./vendor/bin/phpunit
```

### ② Admin Assets Build (Tailwind CSS & Tiptap Editor Bundle)
When modifying CSS in `resources/admin/admin.css` or JavaScript in `admin/src/editor/`:
```bash
npm run build
```
> [!IMPORTANT]
> TypeDock distributes compiled assets in `public/admin/assets/css/admin.css` and `public/admin/dist/editor.bundle.js` so that end users and plugin developers do not require Node.js. Always commit the updated build artifacts alongside your source edits.

### ③ Syntax & Lint Checks
Verify all PHP files have valid syntax:
```bash
find src admin plugins tests -name '*.php' -exec php -l {} +
```

### ④ Dependency & Security Audits
Ensure dependencies contain no reported vulnerabilities:
```bash
composer audit
npm audit
```

### ⑤ Self-Review `git diff`
Always inspect your `git diff` prior to staging:
- Check that no unrelated files or unintended deletions occurred (e.g., table cells in Latte templates).
- Ensure comments, licenses, and documentation standards are preserved.

---

## 4. Handling Bots & Dependency Updates

### Dependabot PRs
- TypeDock groups dependency updates (npm minor/patch, composer minor/patch, GitHub Actions).
- Merge order preference:
  1. `actions/*` (CI workflow updates)
  2. `composer/*` (PHP dependencies)
  3. `npm/*` (JavaScript toolchain)
- If lockfile conflicts occur after merging one dependency PR, resolve them locally (`composer update` / `npm update`), verify tests, and push the updated branch.

### Security Vulnerabilities (Advisories)
- Address high/critical security advisories immediately via targeted dependency patches (e.g., `npm update <package>` or `composer update <package>`).
- If an npm dependency upgrade affects compiled bundles, re-run `npm run build` and commit the updated distribution assets.

---

## 5. Release & Tagging Process

Releases in TypeDock follow a strict verification and cryptographic signing workflow:

1. **Version Bump Synchronization**:
   Update the version string across all tracked locations:
   - `src/helpers.php` (`TYPEDOCK_VERSION`)
   - `config/app.php`
   - `package.json` & `package-lock.json`
   - `build/make-package-manifest.php`
   - `public/install.php`
   - `SECURITY.md` (verification examples)
2. **Commit & Push to `main`**:
   Commit as `chore(release): bump version to 1.0.0-rcX` and push to `main`.
3. **Verify Green CI on `main`**:
   Ensure all GitHub Actions runs (CI, CodeQL, Dependency Audit) are 100% green on `main`.
4. **Create & Push Annotated Tag**:
   ```bash
   git tag -a v1.0.0-rcX -m "TypeDock 1.0.0-rcX"
   git push origin v1.0.0-rcX
   ```
5. **Approve Release Workflow**:
   The release workflow triggers in GitHub Actions, builds `typedock-shared-<version>.zip`, performs Sigstore keyless signing, and publishes the release after approval in the `release` environment.

---

## 6. AI & Pair-Programming Guidelines

When developing with AI assistants (e.g., Antigravity, Claude, Copilot):
- **Explicit Review Gates**: Structural modifications, database schema migrations, and version tagging require human maintainer confirmation before execution.
- **Traceability**: Changes should include clear commit messages and references to architectural documents in `docs-internal/` or public documentation in `docs/`.
