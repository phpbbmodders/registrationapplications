# Administration

The extension requires administrator activation and enabled board email. It
does not change these settings. If they change while the extension is enabled,
new registrations fail validation until the configuration is corrected.

## Questions and answers

Questions are plain text, required or optional, and ordered by display order
then ID. Each can allow up to 10,000 Unicode code points. Optional answers may
be blank; a nonblank answer must meet both limits. Question labels and answers
use phpBB request escaping and render as plain text, with no BBCode or HTML.

There are no questions immediately after installation. Add questions before
opening registration. Disabling a question affects future registrations only.
Existing applications retain a snapshot of the question and answer. Questions
are edited or disabled rather than deleted.

Answers are visible only to staff with the global view permission. They do not
appear in profiles, member lists, or public posts. The queue shows 20 pending
applications per page. Its navigation count is queried on each authorized
staff page load. It does not create separate extension notifications; phpBB’s
normal administrator activation notifications remain available.

Answers are erased when the application is approved or denied, including when
phpBB’s normal activation helper approves an account elsewhere. User deletion
through phpBB removes the application record. Database backups may retain old
copies. Pending answers have no automatic expiry; review or remove stale
applicants using phpBB’s administration tools.

## Decisions

View, approve, and deny are independent global permissions. Viewing is required
for either action. Permissions and account eligibility are checked again during
each decision, after confirmation. A conditional claim prevents two extension
reviewers from deciding the same application twice.

Only extension-created pending applications belonging to inactive registration
accounts can be decided. Founders, active accounts, manually deactivated users,
the guest account, and the current staff account are excluded. Approval uses
`user_active_flip()` and sends phpBB’s standard activation email using the
appropriate 3.3 or 4.0 messenger API. The account decision is committed before
email delivery; a delivery failure does not undo activation.
If the mail transport reports failure, staff see a warning that the account was
activated but the email could not be sent. Check the board email settings and
error log; do not approve the account again. A successful send means the mail
transport accepted the message, not that a remote inbox received it.

Denial defaults to **retain**: the account remains inactive, its reason becomes
manual deactivation, and activation/password-reset keys are cleared. The name
and email remain reserved. Administrators can activate it separately later.

**Delete** removes only the pending applicant via phpBB’s user deletion helper.
Any posts are retained. Neither mode bans the applicant; a deleted applicant
can register again. There is no rejection email. If an administrator changes
denial behavior while a confirmation is open, the decision is refused until
staff refresh and confirm the new behavior.

COPPA accounts remain subject to phpBB’s own consent requirements. Staff must
obtain the required consent before approving them; the application queue does
not replace that process.

Accounts registered before the extension was enabled are not imported.
Disabling the extension preserves data and returns registration to core
behavior. Purging extension data removes questions and applications, but never
deletes or activates user accounts. Retained rejected accounts stay inactive.
