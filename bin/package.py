#!/usr/bin/env python3
"""Create a clean GitHub-ready archive using an explicit source allowlist."""
from pathlib import Path
import stat
import zipfile

ROOT = Path(__file__).resolve().parents[1]
FILES = ['README.md', 'DEPLOIEMENT.md', 'Dockerfile', 'compose.yaml',
         '.gitignore', '.dockerignore', '.gitattributes', '.env.example',
         'ldap.example.json', 'start.sh', 'Lancer-le-dashboard.command',
         'config/.gitkeep', 'data/.gitkeep']
DIRECTORIES = ['src', 'public', 'docker', 'tests', 'bin', '.github']

def sources():
    for name in FILES:
        p = ROOT / name
        if not p.is_file():
            raise RuntimeError(f'Missing source: {name}')
        yield p
    for directory in DIRECTORIES:
        for p in sorted((ROOT / directory).rglob('*')):
            if p.relative_to(ROOT).parts[:2] == ('public', 'maquettes'):
                continue
            if not p.is_file() or p.is_symlink() or '__pycache__' in p.parts:
                continue
            if p.name == '.DS_Store' or p.suffix in ['.log', '.pyc', '.zip']:
                continue
            yield p

if __name__ == '__main__':
    out = ROOT / 'dist' / 'dashboard-ecrans-github.zip'
    out.parent.mkdir(exist_ok=True)
    with zipfile.ZipFile(out, 'w', zipfile.ZIP_DEFLATED) as archive:
        for p in sources():
            name = p.relative_to(ROOT).as_posix()
            info = zipfile.ZipInfo('dashboard-ecrans/' + name)
            info.compress_type = zipfile.ZIP_DEFLATED
            mode = 0o755 if p.suffix in ['.sh', '.command'] else 0o644
            info.external_attr = (stat.S_IFREG | mode) << 16
            archive.writestr(info, p.read_bytes())
    print(out)
