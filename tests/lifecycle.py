#!/usr/bin/env python3
"""Verify disable, re-enable, and purge on a marked disposable SQLite board."""
import argparse
import sqlite3
import subprocess
import sys
from pathlib import Path


def main():
    """Keep accounts unchanged while exercising the extension's migrations."""
    parser = argparse.ArgumentParser(description=__doc__)
    parser.add_argument('-b', '--board', type=Path, required=True)
    if len(sys.argv) == 1:
        parser.print_help()
        return 0
    root = parser.parse_args().board.resolve()
    if not (root / '.ra-disposable-test').is_file():
        parser.error('refusing an unmarked board')
    database = root / 'store/sqlite.db'
    if not database.is_file():
        parser.error('expected the seed-forum SQLite database')
    extension = 'phpbbmodders/registrationapplications'

    def cli(command):
        subprocess.run(['php', str(root / 'bin/phpbbcli.php'), command,
                        extension, '--no-interaction'], check=True)
        subprocess.run(['php', str(root / 'bin/phpbbcli.php'), 'cache:purge'],
                       check=True)

    def rows(sql):
        with sqlite3.connect(database) as connection:
            return connection.execute(sql).fetchall()

    accounts = rows('SELECT user_id, user_type, user_inactive_reason '
                    'FROM phpbb_users ORDER BY user_id')
    applications = rows('SELECT * FROM phpbb_ra_applications ORDER BY user_id')
    questions = rows('SELECT * FROM phpbb_ra_questions ORDER BY question_id')
    cli('extension:disable')
    assert rows('SELECT * FROM phpbb_ra_applications ORDER BY user_id') == (
        applications)
    assert rows('SELECT * FROM phpbb_ra_questions ORDER BY question_id') == (
        questions)
    print('PASS: disable preserves applications and questions')
    cli('extension:enable')
    assert rows('SELECT * FROM phpbb_ra_applications ORDER BY user_id') == (
        applications)
    print('PASS: re-enable preserves applications')
    cli('extension:disable')
    cli('extension:purge')
    assert not rows("SELECT name FROM sqlite_master WHERE type = 'table' "
                    "AND name IN ('phpbb_ra_applications', "
                    "'phpbb_ra_questions')")
    assert not rows("SELECT config_name FROM phpbb_config "
                    "WHERE config_name = 'ra_denial_mode'")
    assert not rows("SELECT auth_option FROM phpbb_acl_options "
                    "WHERE auth_option IN ('m_ra_view', 'm_ra_approve', "
                    "'m_ra_deny')")
    assert rows('SELECT user_id, user_type, user_inactive_reason '
                'FROM phpbb_users ORDER BY user_id') == accounts
    print('PASS: purge removes extension data and ACLs, '
          'preserving every account')
    cli('extension:enable')
    assert rows('SELECT COUNT(*) FROM phpbb_ra_applications') == [(0,)]
    assert rows('SELECT COUNT(*) FROM phpbb_ra_questions') == [(0,)]
    assert rows("SELECT config_value FROM phpbb_config "
                "WHERE config_name = 'ra_denial_mode'") == [('retain',)]
    print('PASS: clean reinstall restores the safe default and empty tables')
    return 0


if __name__ == '__main__':
    raise SystemExit(main())
