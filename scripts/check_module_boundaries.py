#!/usr/bin/env python3
"""Detector parcial de imports PHP entre módulos feature-first."""
from __future__ import annotations

import argparse
import re
from pathlib import Path

IMPORT = re.compile(r"^\s*use\s+(?:function\s+|const\s+)?App\\Modules\\([A-Za-z][A-Za-z0-9_]*)\\([^;]+);", re.M)
PUBLIC_FOLDERS = ("Contracts\\", "Events\\")


def main() -> int:
    parser = argparse.ArgumentParser()
    parser.add_argument("--root", default=".")
    parser.add_argument("--fail", action="store_true")
    args = parser.parse_args()

    folder = Path(args.root) / "app" / "Modules"
    if not folder.is_dir():
        print("Sin app/Modules/: no aplica revisión de fronteras.")
        return 0

    warnings = []
    for owner in sorted(p for p in folder.iterdir() if p.is_dir()):
        for path in owner.rglob("*.php"):
            source = path.read_text(encoding="utf-8", errors="replace")
            for match in IMPORT.finditer(source):
                target, location = match.groups()
                if target != owner.name and not location.startswith(PUBLIC_FOLDERS):
                    warnings.append((path, owner.name, target, location))

    for path, owner, target, location in warnings:
        print(f"REVISAR {path}: {owner} -> {target}\\{location.strip()} (acoplamiento interno)")
    print(f"Imports a revisar: {len(warnings)}. Análisis estático parcial, no exhaustivo.")
    return 1 if args.fail and warnings else 0


if __name__ == "__main__":
    raise SystemExit(main())
