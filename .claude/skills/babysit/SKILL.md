---
name: babysit
description: "How to look after a Fort Vale pull request: what to check before pushing, how to handle CI, Codex and security findings, and when to ask the user. Read it when watching or fixing a PR, or before pushing to a PR branch."
---

# Looking after a Fort Vale pull request

## Before every push

- Run the affected tests: `php artisan test --compact <file or --filter>`. CI also runs the full suite on MySQL + Oracle, which only it has; don't try to reproduce those locally.
- Run `vendor/bin/pint --dirty --format agent` if any PHP changed.
- For changes in `public/js` (chat, `markdown.js`, `fortvale.js`), there are no PHP tests: load the page or the script in a real browser (Playwright) and check the behaviour, including hostile input for anything that renders AI replies.
- If a check can't run (missing PHP version, no browser), say so in your report rather than implying it passed.

## CI

- A red job is this PR's to fix until shown otherwise (red on `main` too). Find the cause; never skip or weaken a test to get green.

## Review comments

- Codex (`chatgpt-codex-connector`) findings: reproduce before acting. Fix what is real, reply on the thread with what you traced, then comment `@codex review` so it checks the new head.
- Security findings stay open until the user decides; reply on them, never resolve them.
- Small, clear asks: do them and resolve the thread. Larger asks (refactors, design, API or schema changes): propose to the user first.

## Before marking a draft ready

- Run the `code-reviewer` agent (it hands its report to `second-opinion-reviewer`) and fix what they confirm.

## Never

- Approve or merge: that is always the user's call.
