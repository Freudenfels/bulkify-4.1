#!/usr/bin/env python3
"""SFTP-Deploy fuer bulkify-4.1 ohne apt/lftp (Ersatz seit 11.11.2026).

Warum: Seit 11.11.2026 nachmittags kann der GitHub-Runner die Ubuntu-Paketquellen
(security.ubuntu.com) nicht aufloesen. "apt-get install lftp" hing ~10 min und brach mit
exit 100 ab, damit fiel jeder Deploy aus. Dieses Skript braucht nur Python + paramiko (PyPI).

Verhalten wie bisher:
  prep   - liest .deploy_sha vom Server, bestimmt Modus (incremental/full) und schreibt
           changed_filtered.txt. Ergebnis "mode=..." nach $GITHUB_OUTPUT.
  upload - laedt die Dateien aus changed_filtered.txt hoch (legt Ordner an, loescht NICHTS).
  mark   - schreibt AFTER_SHA nach <SERVER_PATH>/.deploy_sha.

Ausgeschlossen bleiben: secrets.php, .git/, .github/, data/, *.sql, *.bak, *.log.
"""
import os
import re
import subprocess
import sys
import time

import paramiko

EXCLUDE = re.compile(r'^secrets\.php$|^\.git(hub)?/|^data/|\.sql$|\.bak$|\.log$')


def env(name, default=''):
    return (os.environ.get(name) or default).strip()


def remote_root():
    path = env('SFTP_PATH').rstrip('/')
    if not path:
        sys.exit('SERVER_PATH ist leer - Abbruch.')
    return path


def connect():
    host = env('SFTP_HOST')
    port = int(env('SFTP_PORT', '22') or 22)
    user = env('SFTP_USER')
    pw = os.environ.get('SFTP_PASS', '')
    last = None
    for attempt in range(1, 4):
        try:
            client = paramiko.SSHClient()
            client.set_missing_host_key_policy(paramiko.AutoAddPolicy())
            client.connect(host, port=port, username=user, password=pw, timeout=20,
                           banner_timeout=30, auth_timeout=30,
                           allow_agent=False, look_for_keys=False)
            sftp = client.open_sftp()
            sftp.get_channel().settimeout(60)
            return client, sftp
        except Exception as exc:  # noqa: BLE001
            last = exc
            print(f'SFTP-Verbindung Versuch {attempt}/3 fehlgeschlagen: {exc}')
            time.sleep(5 * attempt)
    sys.exit(f'SFTP-Verbindung nicht moeglich: {last}')


def git(*args):
    return subprocess.run(['git', *args], capture_output=True, text=True)


def set_output(key, value):
    out = os.environ.get('GITHUB_OUTPUT')
    if out:
        with open(out, 'a', encoding='utf-8') as fh:
            fh.write(f'{key}={value}\n')
    print(f'{key}={value}')


def cmd_prep():
    event = env('EVENT_NAME')
    after = env('AFTER_SHA')
    root = remote_root()

    base = ''
    try:
        client, sftp = connect()
        try:
            with sftp.open(f'{root}/.deploy_sha', 'r') as fh:
                raw = fh.read().decode('utf-8', 'ignore')
            base = ''.join(ch for ch in raw if ch in '0123456789abcdef')[:40]
        except IOError:
            base = ''
        finally:
            sftp.close()
            client.close()
    except SystemExit:
        raise
    print(f'Deployter Stand auf dem Server: {base or "<keiner>"}')

    mode = 'incremental'
    if event != 'push':
        mode = 'full'
    if len(base) != 40:
        mode = 'full'
    if mode == 'incremental' and git('cat-file', '-e', f'{base}^{{commit}}').returncode != 0:
        mode = 'full'
    if mode == 'incremental' and git('merge-base', '--is-ancestor', base, after).returncode != 0:
        mode = 'full'

    files = []
    if mode == 'incremental':
        res = git('diff', '--name-only', '--diff-filter=ACMR', base, after)
        if res.returncode != 0:
            mode = 'full'
        else:
            files = [f for f in res.stdout.splitlines() if f and not EXCLUDE.search(f)]

    if mode == 'incremental':
        with open('changed_filtered.txt', 'w', encoding='utf-8') as fh:
            fh.write('\n'.join(files) + ('\n' if files else ''))
        print(f'Modus: inkrementell ab {base}. Zu uebertragende Dateien: {len(files)}')
        print('----- Dateiliste -----')
        for f in files:
            print(f)
        print('----------------------')
    else:
        print('Modus: Vollupload (Fallback).')
    set_output('mode', mode)


def ensure_dir(sftp, path, known):
    if path in known or path in ('', '/'):
        return
    absolute = path.startswith('/')
    cur = ''
    for part in [p for p in path.split('/') if p]:
        if cur:
            cur = f'{cur}/{part}'
        else:
            cur = f'/{part}' if absolute else part
        if cur in known:
            continue
        try:
            sftp.stat(cur)
        except IOError:
            sftp.mkdir(cur)
        known.add(cur)


def cmd_upload():
    root = remote_root()
    if not os.path.isfile('changed_filtered.txt') or os.path.getsize('changed_filtered.txt') == 0:
        print('Keine relevanten Aenderungen - nichts hochzuladen.')
        return
    with open('changed_filtered.txt', encoding='utf-8') as fh:
        files = [line.rstrip('\n') for line in fh if line.strip()]
    todo = []
    for f in files:
        if not os.path.isfile(f):
            print(f'uebersprungen (lokal nicht vorhanden): {f}')
            continue
        todo.append(f)
    if not todo:
        print('Keine tatsaechlich vorhandenen Dateien zu uebertragen.')
        return

    client, sftp = connect()
    known = set()
    try:
        for i, f in enumerate(todo, 1):
            d = os.path.dirname(f)
            target_dir = root if not d else f'{root}/{d}'
            ensure_dir(sftp, target_dir, known)
            for attempt in range(1, 4):
                try:
                    sftp.put(f, f'{root}/{f}')
                    break
                except Exception as exc:  # noqa: BLE001
                    if attempt == 3:
                        sys.exit(f'Upload fehlgeschlagen: {f}: {exc}')
                    print(f'Upload {f} Versuch {attempt} fehlgeschlagen: {exc} - neuer Versuch')
                    sftp.close()
                    client.close()
                    time.sleep(3 * attempt)
                    client, sftp = connect()
            print(f'[{i}/{len(todo)}] {f}')
    finally:
        sftp.close()
        client.close()
    print(f'Inkrementeller Upload abgeschlossen ({len(todo)} Dateien).')


def cmd_mark():
    root = remote_root()
    after = env('AFTER_SHA')
    if len(after) != 40:
        sys.exit('AFTER_SHA fehlt - .deploy_sha wird nicht geschrieben.')
    client, sftp = connect()
    try:
        with sftp.open(f'{root}/.deploy_sha', 'w') as fh:
            fh.write(after + '\n')
    finally:
        sftp.close()
        client.close()
    print(f'Deployter Stand: {after}')


if __name__ == '__main__':
    cmds = {'prep': cmd_prep, 'upload': cmd_upload, 'mark': cmd_mark}
    if len(sys.argv) != 2 or sys.argv[1] not in cmds:
        sys.exit('Aufruf: sftp_deploy.py prep|upload|mark')
    cmds[sys.argv[1]]()
