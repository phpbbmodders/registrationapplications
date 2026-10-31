# Development tests

Use separate disposable phpBB 3.3 and 4.0 boards. The tests create synthetic
accounts and change application settings. Never run them against a live board.

Prepare boards with [seed-forum](https://github.com/phpbbmodders/seed-forum),
bind-mount this checkout, and verify `mounted=True` with its `--status` command.
Configure administrator activation and email before enabling the extension.
Create a `.ra-disposable-test` marker in each disposable board root.

For tests that do not verify delivery, suppress outbound email. For PHP's development server and CLI tests,
use `-d sendmail_path=/bin/true` and turn SMTP delivery off in the disposable
board. Do not send messages to real email addresses.

For actual delivery checks, use an isolated Mailpit SMTP catcher on a private
Docker network with no external relay. Set SMTP delivery on, host
`ra-regapps-mail`, port `1025`, no authentication, and email package size `0`.
Use only synthetic addresses under `example.invalid`. Run:

```bash
php tests/mail_delivery.php /path/to/disposable/board
python3 tests/verify_mail.py --expectations /path/to/disposable/board/store/ra-mail-expectations.json --mailpit http://PRIVATE_CATCHER_IP:8025/
php tests/mail_failure.php /path/to/disposable/board
python3 tests/verify_mail.py --expectations /path/to/disposable/board/store/ra-mail-failure.json --mailpit http://PRIVATE_CATCHER_IP:8025/
```

The delivery fixture checks approval, both denial modes, and replay refusal.
The receiver requires exactly one approval email, no denial email, correct
activation content, and no private answers in MIME. The failure fixture forces
an actual TCP refusal, then checks committed activation, answer cleanup, a
false return, and a phpBB critical error. Restore original mail settings after
testing. Run the HTTP test with `--expect-mail-failure` and an unused SMTP port
to verify the staff warning; restore the correct port afterward.

On a fresh board with this extension enabled and no questions configured:

```bash
php -d sendmail_path=/bin/true tests/integration.php /path/to/disposable/board
php tests/seed_review_users.php /path/to/disposable/board
python3 tests/http_access.py --base http://localhost:8193/
python3 tests/lifecycle.py --board /path/to/disposable/board
```

The integration test uses phpBB's actual database driver, account helpers,
events, messenger API, and extension services. It covers question snapshots,
Unicode/length validation, optional and disabled questions, both denial modes,
stale decisions, protected accounts, permission enforcement, cleanup after
core activation/deletion, and queue pagination.

The HTTP test requires `requests`, as does the shared phpbb-test-board harness.
It follows generated links and form actions with cookies and CSRF tokens. Five
ordinary `USER_NORMAL` accounts cover no access, view-only access, approval,
denial, and full review. They belong only to the Registered users group and
have no administrator permissions. Their public test password is
`RaFixture2026!`; these accounts must never exist on a public board.

The HTTP test checks guest/member refusals, navigation and button visibility,
forged unauthorized POSTs, and confirmed approval/denial by ordinary staff.
Keep several pending applications available when running it. To exercise both
HTTP denial modes, select retain on one board and delete on the other, then
run the tests on each.

The lifecycle test supports the seed-forum SQLite layout. It confirms that
disable/re-enable preserves data, purge removes extension tables/settings/ACLs,
and purge never changes or deletes an account. It leaves a clean enabled
extension, ready to rerun the integration suite.

For other database drivers, `lifecycle_snapshot.php BOARD_ROOT save` captures
accounts and extension rows. After disable and re-enable, use `preserved`.
After disable and purge, use `purged`. After enable, use `clean`. Run the
appropriate phpBB CLI extension command and cache purge between each step.
This checks real migrations without resetting the database.

Browser checks should also cover the registration form, validation feedback,
ACP question creation/editing, the denial selector, confirmation cancellation,
and the decorative checkmark icon at narrow and wide viewports. Registration
tests on a local fixture may replace the fixture's agreement text with an
explicitly nonbinding test notice; that fixture change is not distributed as
part of this extension.
