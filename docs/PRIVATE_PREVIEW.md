# Ninja Lost — private browser preview

Scope: development only. This configuration does not start a Codespace, change account billing, create hosting, register a Discord bot or publish a public game server. Creating a Codespace consumes its own compute/storage allowance; check the account quota/budget first. Do not use production data.

## Launch when ready

1. Open xelfyn/ninja-lost at the reviewed branch/commit (main after this PR is merged). Select Code > Codespaces > create a codespace. Choose an appropriate standard small machine, not a paid upgrade automatically.
2. The devcontainer prepares PHP 8.4, Composer and an internal MySQL 8.4 service. Its setup installs locked dependencies and runs migrations without community seeders. Initial data catalogs are not populated.
3. In the Codespaces terminal run `php artisan ninja-lost:create-admin`. Enter a NEW username/email and your own password interactively. No default admin is created. Do not post the password in chat or commit it.
4. Run `bash ops/preview/start.sh`.
5. In Ports verify **8000 → Private**, then Open in Browser. Go to `/admin/login`. GitHub port authentication and application login are separate. Database port 3306 is not forwarded or published.
6. After login open `/admin/nsx-operations`. Try module search, Game Settings > Special Deals icon > Save Settings, then refresh. This is an evaluation panel, not a playable game.
7. Stop the application with Ctrl+C. Stop the Codespace when finished to stop compute. Stopped Codespaces still retain storage; do not delete one until useful changes are committed and any evaluation data worth keeping has been exported safely.

## Safety and persistence

`.env` and `.devcontainer/.runtime/` are generated and ignored by Git. Preview database credentials are random; environment setup refuses a foreign/modified .env rather than silently overriding it. APP_KEY is preserved across setup/start. Do not delete `.runtime` while retaining its MySQL volume: new passwords would not match that volume. No script automatically drops the database or resets existing data.

A local named volume retains data for normal container restart. Full Codespaces lifecycle/volume persistence must still be verified in the actual Codespace; it is not a backup strategy. The browser CI restart proof restarts the PHP process, not the MySQL container or Codespace.

The exact Codespaces hostname is validated before forcing HTTPS application links. No wildcard forwarded-host/proxy trust or disabled CSRF is introduced. Preview remains on the normal app authentication path. AMF/game APIs stay closed under the existing evaluation routes.

## Evidence limits

Python setup tests and PHP origin-policy tests are automated. Chromium tests cover actual form interactions and application-process restart on a GitHub runner. Desktop/mobile-sized viewport tests are not real-phone tests. Screenshots are captured temporarily on the runner and deliberately not uploaded to Actions storage or committed; without a retained image/manual inspection, visual design approval remains pending.

The Dockerfile uses official version-family images (PHP 8.4, Composer 2, MySQL 8.4), not immutable image digests; this preview is not a production release build. Codespace creation, container build and private forwarded URL must be verified on first launch. Do not call this setup live before that succeeds.

Rollback code with the previous Git revision. Preserve local credentials and database volume; restoring application source does not reverse arbitrary database writes.
