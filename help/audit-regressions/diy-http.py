#!/usr/bin/env python3
"""Read-only v1/v2 DIY regression against the local runtime."""
import json
import sys
from urllib.request import urlopen

origin = sys.argv[1].rstrip('/') if len(sys.argv) > 1 else 'http://127.0.0.1:8011'


def get(path):
    with urlopen(origin + path, timeout=20) as response:
        result = json.load(response)
    if result.get('status') != 200:
        raise AssertionError(f'{path}: business status {result.get("status")}; {result.get("msg")}')
    return result['data']


for legacy, current in [
    ('/api/diy/get_diy', '/api/v2/diy/get_diy/default'),
    ('/api/diy/get_diy/0', '/api/v2/diy/get_diy/default?id=0'),
    ('/api/diy/get_diy/1', '/api/v2/diy/get_diy/default?id=1'),
    ('/api/diy/get_diy/2147483647', '/api/v2/diy/get_diy/default?id=2147483647'),
]:
    assert get(legacy) == get(current), f'DIY contract mismatch: {legacy}'
    print(f'PASS {legacy}: success and v2 data match')
