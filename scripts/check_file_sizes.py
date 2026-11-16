#!/usr/bin/env python3
"""Revisa tamaño de archivos sin exigir dividir código coherente."""
from __future__ import annotations

import argparse
from pathlib import Path

LIMITS = {
    "Controllers": 50,
    "Requests": 75,
    "Resources": 65,
    "Actions": 90,
    "Queries": 80,
    "Policies": 90,
    "Jobs": 80,
    "Listeners": 80,
    "Integrations": 110,
    "Models": 120,
}


def main() -> int:
    parser = argparse.ArgumentParser()
    parser.add_argument("--root", default=".")
    parser.add_argument("--fail", action="store_true")
    args = parser.parse_args()

    app = Path(args.root) / "app"
    if not app.is_dir():
        print("Sin carpeta app/: no hay clases PHP que revisar.")
        return 0

    problems = []
    for path in app.rglob("*.php"):
        kind = next((part for part in reversed(path.parts) if part in LIMITS), None)
        if kind is None:
            continue
        line_count = sum(1 for _ in path.open(encoding="utf-8", errors="replace"))
        if line_count > LIMITS[kind]:
            problems.append((path, line_count, LIMITS[kind]))

    for path, lines, limit in problems:
        print(f"REVISAR {path}: {lines} líneas (objetivo de revisión {limit})")
    print(f"Archivos a revisar: {len(problems)}. Excepciones razonables son válidas.")
    return 1 if args.fail and problems else 0


if __name__ == "__main__":
    raise SystemExit(main())
