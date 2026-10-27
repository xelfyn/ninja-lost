# Browser CI provisioning

The application browser assertions remain in `ops/browser/check.cjs` and `ops/rewards/browser.cjs`. Both execute Playwright 1.56.1 with its matching Chromium headless shell, desktop 1440x1000 and mobile-sized 390x844. No test is skipped or marked successful on a dependency failure.

## Why OS package installation was removed from each run

Post-merge validation run 40539928420, runtime job 122315313400 on commit 79d42c4dec7c28447c9e5700fee7575939295550, passed migrations, 17 integration tests and strict autoload, then exited 124 during `playwright install --with-deps chromium`. The log shows slow Ubuntu apt package downloads; the actual admin browser stage had not started. This is a provisioning timeout, not a demonstrated application regression or an application-font-CDN failure. Do not attribute an unobserved exception to this run.

GitHub-hosted Ubuntu already contains the Chromium shared-library dependencies used by these flows. Instead of updating system packages and fetching optional font packs on every run, each runner downloads the pinned matching browser only (`install --only-shell chromium`) and executes `preflight.cjs`: launch Chromium, render a small page and interact with a control. If a future runner image lacks libraries, preflight fails the job; missing libraries are not ignored or silently substituted. Repair the image/dependency contract explicitly in that event. This setup is not a general workstation installer.

The browser and node dependency directories are beneath the newly created runner-temporary QA directory, removed at script exit. No Actions cache, artifact upload, browser binary commit or image publication is added. Browser/system font variation remains outside a pixel-perfect visual approval claim. The pinned Playwright version and existing application assertions are unchanged.

Reference: https://playwright.dev/docs/browsers#chromium-headless-shell — the default headless/no-channel path uses the headless shell, and `--only-shell` avoids the unused headed browser download.
