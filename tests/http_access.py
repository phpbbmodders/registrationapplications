#!/usr/bin/env python3
"""Test generated queue links using ordinary accounts on a disposable board.

Seed with seed_review_users.php first. Requires requests (also used by the
shared phpbb-test-board harness). No-argument invocation prints usage.
"""
import argparse
import ipaddress
import sys
import time
from html.parser import HTMLParser
from urllib.parse import urljoin, urlparse

import requests


class Page(HTMLParser):
    """Collect links, form actions, and hidden fields from phpBB HTML."""

    def __init__(self, text):
        super().__init__()
        self.links = []
        self.forms = []
        self.text = []
        self.link = None
        self.form = None
        self.feed(text)

    def handle_starttag(self, tag, attrs):
        attrs = dict(attrs)
        if tag == 'a':
            self.link = [attrs.get('href', ''), '']
        if tag == 'form':
            self.form = {'action': attrs.get('action', ''), 'hidden': {}}
            self.forms.append(self.form)
        if (tag == 'input' and self.form is not None
                and attrs.get('type') == 'password'):
            self.form['password_name'] = attrs['name']
        if (tag == 'input' and self.form is not None
                and attrs.get('type') == 'hidden' and 'name' in attrs):
            self.form['hidden'][attrs['name']] = attrs.get('value', '')

    def handle_endtag(self, tag):
        if tag == 'a' and self.link is not None:
            self.links.append(tuple(self.link))
            self.link = None
        if tag == 'form':
            self.form = None

    def handle_data(self, data):
        self.text.append(data)
        if self.link is not None:
            self.link[1] += data


def check(condition, label):
    """Fail immediately with the relevant access assertion."""
    if not condition:
        raise AssertionError(label)
    print(f'PASS: {label}')


def login(base, username):
    """Sign in using the synthetic fixture account, retaining its cookies."""
    session = requests.Session()
    response = session.get(urljoin(base, 'ucp.php?mode=login'), timeout=20)
    form = next(form for form in Page(response.text).forms
                if 'password_name' in form)
    data = dict(form['hidden'], username=username, login='Login')
    data[form['password_name']] = 'RaFixture2026!'
    time.sleep(1.1)
    response = session.post(urljoin(response.url, form['action']),
                            data=data, timeout=20)
    login_text = response.text
    # 3.3 can use an HTML refresh after login; follow with a normal page load.
    response = session.get(urljoin(base, 'index.php'), timeout=20)
    if 'mode=logout' not in response.text:
        visible = ' '.join(' '.join(Page(login_text).text).split())
        print('Login response:', visible[:2000], file=sys.stderr)
    check('mode=logout' in response.text,
          f'{username}: ordinary account login')
    return session, response


def decision(base, queue_url, username, action, mail_failure=False):
    """Follow generated form actions through confirmation as ordinary staff."""
    session, _ = login(base, username)
    response = session.get(queue_url, timeout=20)
    form = next(form for form in Page(response.text).forms
                if 'applicant' in form['hidden'])
    applicant = form['hidden']['applicant']
    time.sleep(1.1)
    confirmation = session.post(urljoin(response.url, form['action']),
                                data=dict(form['hidden'], decision=action),
                                timeout=20)
    confirm_form = next(form for form in Page(confirmation.text).forms
                        if 'confirm_uid' in form['hidden'])
    check(confirmation.status_code == 200,
          f'{username}: {action} requires confirmation')
    time.sleep(1.1)
    result = session.post(
        urljoin(confirmation.url, confirm_form['action']),
        data=dict(confirm_form['hidden'], confirm='Yes'), timeout=20
    )
    expected = ('The account was activated, but its activation email could '
                'not be sent.' if mail_failure and action == 'approve'
                else 'The application decision was saved.')
    check(expected in result.text,
          f'{username}: confirmed {action} succeeds without admin privileges')
    remaining = [form['hidden'].get('applicant')
                 for form in Page(result.text).forms]
    check(applicant not in remaining,
          f'{username}: decided applicant removed from queue')


def main():
    """Check guest/member/viewer/approve/deny/full-review links and ACLs."""
    parser = argparse.ArgumentParser(description=__doc__)
    parser.add_argument('-b', '--base', required=True,
                        help='loopback disposable board URL, ending in /')
    parser.add_argument('-p', '--allow-private-test-host', action='store_true',
                        help='allow an isolated Docker/private fixture IP')
    parser.add_argument('-f', '--expect-mail-failure', action='store_true',
                        help='expect the committed-approval mail warning')
    if len(sys.argv) == 1:
        parser.print_help()
        return 0
    args = parser.parse_args()
    base = args.base.rstrip('/') + '/'
    endpoint = urlparse(base)
    if not base.startswith(('http://localhost:', 'http://127.0.0.1:')):
        try:
            private = ipaddress.ip_address(endpoint.hostname).is_private
        except ValueError:
            private = False
        if not args.allow_private_test_host or not private:
            parser.error('use loopback or explicitly allow a private test IP')

    reviewer, index = login(base, 'ra_reviewer')
    links = [url for url, text in Page(index.text).links
             if text.strip().startswith('Applications (')]
    check(bool(links), 'reviewer: navigation link generated')
    queue_url = urljoin(index.url, links[0])
    queue = reviewer.get(queue_url, timeout=20)
    check(queue.status_code == 200, 'reviewer: generated link opens queue')
    check('name="decision" value="approve"' in queue.text,
          'reviewer: approve button present')
    check('name="decision" value="deny"' in queue.text,
          'reviewer: deny button present')
    check(not any('/adm/' in urljoin(queue.url, href)
                  for href, _ in Page(queue.text).links),
          'reviewer: no administrative link')
    check(requests.get(queue_url, timeout=20).status_code == 403,
          'guest: direct queue URL refused')

    for username, view, approve, deny in (
        ('ra_member', False, False, False),
        ('ra_viewer', True, False, False),
        ('ra_approver', True, True, False),
        ('ra_denier', True, False, True),
    ):
        session, index = login(base, username)
        has_link = any(text.strip().startswith('Applications (')
                       for _, text in Page(index.text).links)
        check(has_link == view, f'{username}: correct link visibility')
        response = session.get(queue_url, timeout=20)
        check(response.status_code == (200 if view else 403),
              f'{username}: correct direct URL access')
        if not view:
            check('private &amp; answer' not in response.text,
                  f'{username}: private answers absent')
            continue
        check(('name="decision" value="approve"' in response.text) == approve,
              f'{username}: correct approve button visibility')
        check(('name="decision" value="deny"' in response.text) == deny,
              f'{username}: correct deny button visibility')
        forms = Page(response.text).forms
        fields = next((form['hidden'] for form in forms
                       if 'applicant' in form['hidden']), None)
        if fields:
            for action, allowed in (('approve', approve), ('deny', deny)):
                if allowed:
                    continue
                time.sleep(1.1)
                forged = session.post(queue_url,
                                      data=dict(fields, decision=action),
                                      timeout=20)
                check(forged.status_code == 403,
                      f'{username}: forged {action} POST refused')
    decision(base, queue_url, 'ra_approver', 'approve',
             args.expect_mail_failure)
    decision(base, queue_url, 'ra_denier', 'deny')
    print('All ordinary-account link/access checks passed.')
    return 0


if __name__ == '__main__':
    raise SystemExit(main())
