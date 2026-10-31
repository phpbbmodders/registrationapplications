#!/usr/bin/env python3
"""Verify SMTP receipts in a dedicated Mailpit instance using fixture results.

Uses Mailpit's documented v1 list, message, and raw-source endpoints. Reads
every mailbox page. Does not delete messages, release mail, or configure relay.
"""
import argparse
import ipaddress
import json
import sys
from pathlib import Path
from urllib.parse import urlparse

import requests


def main():
    """Assert one activation receipt, no denial mail, and no answer leakage."""
    parser = argparse.ArgumentParser(description=__doc__)
    parser.add_argument('-e', '--expectations', type=Path, required=True)
    parser.add_argument('-m', '--mailpit', required=True)
    parser.add_argument('-o', '--output', type=Path,
                        help='directory for the synthetic raw receipt')
    if len(sys.argv) == 1:
        parser.print_help()
        return 0
    args = parser.parse_args()
    endpoint = urlparse(args.mailpit)
    if endpoint.hostname != 'localhost':
        try:
            address = ipaddress.ip_address(endpoint.hostname)
        except ValueError:
            parser.error('use a local test catcher IP or localhost')
        if not address.is_private:
            parser.error('only a private/local test catcher is allowed')
    if endpoint.scheme != 'http':
        parser.error('expected an isolated local HTTP test catcher')
    base = args.mailpit.rstrip('/')
    expected = json.loads(args.expectations.read_text())
    failure = expected.get('mode') == 'failure'
    approved = None if failure else expected['accounts']['approved']
    rejected = ({expected['email']} if failure else {
        expected['accounts'][kind]['email']
        for kind in ('retained', 'deleted')})
    messages = []
    start = 0
    while True:
        response = requests.get(base + '/api/v1/messages',
                                params={'start': start, 'limit': 100},
                                timeout=20)
        response.raise_for_status()
        page = response.json()
        batch = page['messages']
        messages.extend(batch)
        start += len(batch)
        if start >= page['total']:
            break
        if not batch:
            raise AssertionError('incomplete mailbox pagination')
    matches = []
    for message in messages:
        recipients = {recipient['Address'] for recipient in message['To']}
        assert not (recipients & rejected), 'denial unexpectedly sent email'
        if approved and approved['email'] in recipients:
            matches.append(message)
    if failure:
        print('PASS: refused SMTP transaction produced no delivered message')
        return 0
    assert len(matches) == 1, 'expected exactly one delivered approval email'
    message_id = matches[0]['ID']
    response = requests.get(base + '/api/v1/message/' + message_id, timeout=20)
    response.raise_for_status()
    receipt = response.json()
    text = receipt['Text']
    assert approved['username'] in text, 'activation body identifies applicant'
    assert 'activat' in text.lower(), 'activation body explains approval'
    assert expected['private_marker'] not in text, 'private answers leaked'
    assert receipt['Subject'], 'activation email has no subject'
    raw = requests.get(base + '/api/v1/message/' + message_id + '/raw',
                       timeout=20)
    raw.raise_for_status()
    assert expected['private_marker'] not in raw.text, (
        'raw MIME leaked answers')
    if args.output:
        args.output.mkdir(parents=True, exist_ok=True)
        (args.output / (expected['token'] + '.eml')).write_bytes(raw.content)
    print('PASS: one activation email received over SMTP for ' +
          approved['email'])
    print('PASS: correct activation content; private answers absent')
    print('PASS: no duplicate after replay; neither denial sent email')
    print('Receipt ID: ' + message_id)
    return 0


if __name__ == '__main__':
    raise SystemExit(main())
