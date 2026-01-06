# Branch Protection Rules

Recommended branch protection for `main` to ensure CI gates merges:

- Require a pull request before merging (dismiss stale approvals, require 1+ approval).
- Require status checks to pass before merging:
  - `CI / php`
  - `CI / node`
- Require branches to be up to date before merging.
- Restrict direct pushes to `main` (enforce for admins if possible).
- Require signed commits if your release process mandates it.

Update the required checks list if job names change.
