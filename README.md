# Ninja Lost

GitHub-first, reuse-first development. MB01 is in progress; **no live game, Discord bot or APK is delivered yet**.

The controlled intake is pinned to the community commit in `project/source-lock.json`. It imports server text under its upstream notice, excludes client binaries, game assets, credentials, SQL dumps and default seeders, and disables game mutation routes in the evaluation build.

`main` remains the accepted baseline; implementation is reviewed through pull requests. The intake job may create a NEW review branch, never push to main. Runtime tests run separately with read-only permissions and an isolated MySQL service.

## Checks

```sh
python3 -m unittest discover -s tests -v
python3 scripts/ci/check_repository.py
```

Application tests: `ops/runtime/setup-ci.sh` in isolated GitHub Actions only. No artifact upload, dependency cache, automatic Codespaces or hosting. Community APIs remain closed until later audits.

See `docs/MB01.md` for scope, limits and recovery. Bootstrap B00 is preserved in Git history.
