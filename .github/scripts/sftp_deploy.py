#!/usr/bin/env python3
"""SFTP-Deploy fuer bulkify-4.1 ohne apt/lftp (Ersatz seit 11.11.2026).

Warum: Seit 11.11.2026 nachmittags kann der GitHub-Runner die Ubuntu-Paketquellen
(security.ubuntu.com) nicht aufloesen. "apt-get install lftp" hing ~10 min und brach mit
exit 100 ab, damit fiel jeder Deploy aus. Dieses Skript braucht nur Python + paramiko (PyPI).

Verhalten wie bisher:
  prep   - liest .deploy_sha vom Server, bestimmt Modus (incremental/full/skip) und schreibt
           changed_filtered.txt. Ergebnis "mode=..." nach $GITHUB_OUTPUT.
  upload - laedt die Dateien aus changed_filtered.txt hoch (legt Ordner an, loescht NICHTS).
  mark   - schreibt AFTER_SHA nach <SERVER_PATH>/.deploy_sha (nie einen aelteren Stand).

Schutz gegen Zurueckdrehen (12.11.2026): Nach der GitHub-Stoerung vom 11.11. hingen alte Laeufe
(#1813-#1815) in der Warteschlange. Startet so ein Lauf, nachdem live schon neuer ist, darf er nichts
hochladen und .deploy_sha nicht ueberschreiben:
  - prep: AFTER_SHA gleich oder Vorfahre des Server-Stands -> mode=skip.
  - prep: Vollupload nur fuer den aktuellen main HEAD, sonst mode=skip.
  - mark: liest .deploy_sha direkt vor dem Schreiben erneut und schreibt nie einen aelteren Stand.

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


def is_commit(sha):
    return len(sha) == 40 and git('cat-file', '-e', f'{sha}^{{commit}}').returncode == 0


def is_ancestor(older, newer):
    # True, wenn older == newer oder older ein Vorfahre von newer ist.
    return git('merge-base', '--is-ancestor', older, newer).returncode == 0


def main_head():
    # Aktueller main HEAD - frisch vom Remote (ls-remote), Rueckfall auf den beim Checkout geholten Stand.
    res = git('ls-remote', 'origin', 'refs/heads/main')
    if res.returncode == 0 and res.stdout.strip():
        sha = res.stdout.split()[0].strip()
        if len(sha) == 40:
            return sha
    res = git('rev-parse', '--verify', '-q', 'refs/remotes/origin/main')
    sha = res.stdout.strip() if res.returncode == 0 else ''
    return sha if len(sha) == 40 else ''


def read_server_sha(sftp, root):
    try:
        with sftp.open(f'{root}/.deploy_sha', 'r') as fh:
            raw = fh.read().decode('utf-8', 'ignore')
    except IOError:
        return ''
    return ''.join(ch for ch in raw if ch in '0123456789abcdef')[:40]


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

    client, sftp = connect()
    try:
        base = read_server_sha(sftp, root)
    finally:
        sftp.close()
        client.close()
    print(f'Deployter Stand auf dem Server: {base or "<keiner>"}')
    print(f'Stand dieses Laufs: {after}')

    # Schutz 1: Server ist schon auf diesem oder einem neueren Stand -> nichts tun.
    # Ausnahme (13.11.2026, Fable-3 T4.6/D3): „Run workflow“ (workflow_dispatch) auf main HEAD, wenn der Server GENAU diesen Stand
    # hat -> Reparatur-Vollupload (z. B. Dateien auf dem Server beschädigt). Ist der Server neuer, bleibt es beim skip.
    # Hinweis (D4): Zurückdrehen per Force-Push auf einen älteren Commit wird übersprungen – zurück nur per Revert-Commit.
    if len(after) == 40 and is_commit(base) and is_ancestor(after, base):
        reparatur = event == 'workflow_dispatch' and base == after and after == main_head()
        if not reparatur:
            print(f'Server ist schon neuer ({base}) - nichts zu tun' if base != after
                  else f'Server hat diesen Stand schon ({base}) - nichts zu tun')
            set_output('mode', 'skip')
            return
        print(f'Manueller Lauf auf main HEAD, Server hat diesen Stand ({base}) - Reparatur-Vollupload.')

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
        # Schutz 2: Vollupload nur fuer den aktuellen main HEAD (sonst koennte ein alter Lauf alten Code komplett hochladen).
        head = main_head()
        if not head or after != head:
            print(f'Vollupload nur fuer main HEAD ({head or "unbekannt"}) - dieser Lauf ({after}) ist es nicht, uebersprungen.')
            set_output('mode', 'skip')
            return
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
        # Schutz 3: direkt vor dem Schreiben erneut lesen - nie einen aelteren Stand schreiben (auch nicht bei einem Wettlauf).
        cur = read_server_sha(sftp, root)
        if cur and cur != after:
            if not is_commit(cur):
                git('fetch', '-q', 'origin', 'main')
            if is_commit(cur) and is_ancestor(after, cur):
                print(f'Server ist schon neuer ({cur}) - .deploy_sha bleibt.')
                return
            if not (is_commit(cur) and is_ancestor(cur, after)) and after != main_head():
                print(f'Server-Stand {cur} ist kein Vorfahre von {after} und dieser Lauf ist nicht main HEAD - .deploy_sha bleibt.')
                return
        with sftp.open(f'{root}/.deploy_sha', 'w') as fh:
            fh.write(after + '\n')
    finally:
        sftp.close()
        client.close()
    print(f'Deployter Stand: {after}')


def cmd_upload_all():
    # Testumgebung (Paket 12.11.2026): Vollupload ohne fremde Action - alle Dateien aus git, gleiche Ausschluesse.
    res = git('ls-files')
    files = [f for f in res.stdout.splitlines() if f and not EXCLUDE.search(f)]
    with open('changed_filtered.txt', 'w', encoding='utf-8') as fh:
        fh.write('\n'.join(files) + ('\n' if files else ''))
    print(f'Vollupload: {len(files)} Dateien')
    cmd_upload()


def cmd_put(local, remote_rel):
    # Einzelne Datei hochladen (Testumgebung: robots.txt nur dort).
    root = remote_root()
    client, sftp = connect()
    try:
        d = os.path.dirname(remote_rel)
        ensure_dir(sftp, f'{root}/{d}' if d else root, set())
        sftp.put(local, f'{root}/{remote_rel}')
    finally:
        sftp.close()
        client.close()
    print(f'hochgeladen: {remote_rel}')


def cmd_pfadcheck():
    # Testumgebung (13.11.2026): Zielpfad mit realpath (SFTP normalize) gegen live und beta pruefen. Gleich, Unter- oder
    # Elternordner = Abbruch. TEST_PATH/LIVE_PATH/BETA_PATH kommen aus den Secrets (BETA_PATH optional).
    test_p = env('TEST_PATH').rstrip('/')
    andere = [(n, env(k).rstrip('/')) for n, k in (('SERVER_PATH (live)', 'LIVE_PATH'), ('beta', 'BETA_PATH')) if env(k)]
    if not test_p:
        sys.exit('TEST_PATH leer - Abbruch.')
    client, sftp = connect()
    try:
        def real(p):
            try:
                return sftp.normalize(p).rstrip('/') or '/'
            except IOError:
                return p   # existiert (noch) nicht: dann der Text selbst
        t = real(test_p)
        for name, p in andere:
            o = real(p)
            if t == o or t.startswith(o + '/') or o.startswith(t + '/'):
                sys.exit(f'Abbruch: Testziel {t} ist gleich, Unter- oder Elternordner von {name} ({o}).')
        print(f'Testziel {t} ist getrennt von ' + ', '.join(f'{n}' for n, _ in andere))
    finally:
        sftp.close()
        client.close()


if __name__ == '__main__':
    cmds = {'prep': cmd_prep, 'upload': cmd_upload, 'mark': cmd_mark, 'upload_all': cmd_upload_all, 'pfadcheck': cmd_pfadcheck}
    if len(sys.argv) == 4 and sys.argv[1] == 'put':
        cmd_put(sys.argv[2], sys.argv[3])
        sys.exit(0)
    if len(sys.argv) != 2 or sys.argv[1] not in cmds:
        sys.exit('Aufruf: sftp_deploy.py prep|upload|mark|upload_all|pfadcheck  oder  put <lokal> <ziel-relativ>')
    cmds[sys.argv[1]]()
