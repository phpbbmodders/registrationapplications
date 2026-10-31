# Development verification

Verified on 10/30/2026–10/31/2026 using marked disposable boards and synthetic
accounts. This is an unreleased development build, not a CDB-validated release.

| Board | Source commit | Database regressions |
| --- | --- | --- |
| phpBB 3.3.19 | `1b1ffdf89c033f584a1daf0c6995c13e59dcb81b` | 33 passed |
| phpBB 4.0.0-a3-dev | `2a2dad29a4d47d26d8f01f4bf4851c98f809c481` | 33 passed |

Each database below passed on **both** branches: 33 integration assertions,
ordinary-account HTTP links and decisions, disable/re-enable preservation,
purge and clean reinstall, actual SMTP receipt, and real SMTP refusal handling.

| Database/driver | Tested version |
| --- | --- |
| SQLite | Host PHP 8.4.27; SMTP checks in PHP 8.4.26 container |
| MariaDB / mysqli | 12.3.3-MariaDB-ubu2404 |
| PostgreSQL | 16.15 |
| Oracle | AI Database 26ai Free, release 23.26.3.0.0 |
| SQL Server / native | 2022 Developer, 16.0.4125.3 |
| SQL Server / ODBC | Same server; Microsoft ODBC Driver 17 |

Non-SQLite cases used PHP 8.4.26 with the extension source bind-mounted into
each runtime. SQLite boards used seed-forum extension mounts. Purge preserved
all user identities and activation states in all 12 cases.

## Oracle core prerequisites

The Oracle cases needed separate phpBB core fixes applied only to their
disposable boards. They are not included in the extension or its installable
ZIP. Unpatched Oracle compatibility is not claimed:

- 3.3: escape hyphens in numeric SQL rewrite regexes (10 regression cases).
- 4.0: normalize installer row casing and obtain inserted language IDs by ISO
  lookup (installer suite: 23 tests, 31 assertions).
- 4.0: remove a table's identity sequence during schema deletion, including
  uppercase identifiers and unmarked trigger-backed identity columns (two
  regression cases; real extension purge/reinstall also passed).

The initial 4.0 reinstall failure was reproduced and traced to the leftover
sequence. After the core fix, the complete lifecycle passed. These prerequisites
need upstream review before Oracle users should rely on this development build.

## HTTP and browser checks

Real HTTP checks with five ordinary, non-founder accounts passed on both
branches: generated navigation links, direct URLs, read-only access, action
button visibility, unauthorized POST refusals, and confirmed approval and denial
without administrator permissions. Retain was exercised on 3.3 and deletion
on 4.0. Guests and members without the view permission received HTTP 403.

Visible browser checks covered registration on both branches, Unicode/entity
answers, 4.0 minimum-length validation and answer preservation after errors,
the 3.3/4.0 ACP editors, saving a question and denial mode on 4.0, approval on
3.3, retained denial on 4.0, and the ordinary-reviewer navigation link on 4.0.
The decorative checkmark icon rendered on both branches at a narrow viewport.
Real HTTP approval with refused SMTP showed the delivery warning on both
branches while keeping the account activated.

PHP syntax checks and the Python test helpers' Flake8 and Ruff checks passed.
Composer metadata validation passed with its standard warning about declaring
an extension version explicitly.

## Email evidence and limits

Mailpit received actual SMTP messages on an isolated private Docker network.
Each branch/database case produced exactly one approval receipt, with the
expected recipient and activation content, no private answers in MIME, no
duplicate after replay, and no messages for either denial mode. Synthetic raw
receipts were saved separately from the installable package.

Forced TCP refusal returned failure, preserved activation and erased answers,
and increased the board's critical error log in every case. Staff receive a
specific warning rather than an unqualified saved-success message.

These checks establish delivery to the local SMTP catcher, not to an external
provider or recipient inbox. MySQL proper remains pending permission to
download its test image; MariaDB is tested separately. Other PHP runtimes,
third-party styles, and concurrent external account changes were not exercised.
phpBB 4.0 is an alpha development target and may change its APIs.
