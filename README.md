# Registration Applications

Private registration questions and a staff application queue for phpBB 3.3 and
4.0. This is an unreleased development version.

Staff can review answers and approve or deny accounts without general user
deletion access. Administrators choose whether denial retains an inactive
account or deletes it. Both actions require confirmation.

## Installation

1. Set **Account activation** to **By admin** and enable board email.
2. Copy this directory to `ext/phpbbmodders/registrationapplications/`.
3. Enable **Registration Applications** in ACP → Customise → Manage extensions.
4. Add questions under ACP → Extensions → Registration Applications.
5. Grant the global moderator permissions to view, approve, or deny applications.
   Approval and denial also require the view permission. Administrators receive
   all three permissions initially.

Authorized staff see **Applications (count)** in the navigation bar. Register a
test account with your questions filled in, then review it from that page.

See [the administration guide](docs/administration.md) for privacy, denial,
COPPA, account lifecycle, and upgrade behavior.

## Development

See [testing](docs/testing.md) for disposable-board regression commands and
[verification results](docs/verification.md) for the tested versions and limits.

## Contributing

Contributions are welcome!

- **Bug reports**: [Open an issue](https://github.com/phpbbmodders/registrationapplications/issues).
- **Everything else** (questions, feature requests, ideas, general discussion): [Use Discussions](https://github.com/phpbbmodders/registrationapplications/discussions).
- Pull requests are welcome for bug fixes or discussed features.

## Acknowledgments

- Code and documentation assisted by [Codex](https://openai.com/codex/).
- Inspired by the [Registration Applications request](https://www.phpbb.com/community/viewtopic.php?t=2674882).

## License

GNU General Public License version 2. See [license.txt](license.txt).
