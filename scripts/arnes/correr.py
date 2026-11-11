"""
Lanza los procesos del arnés.

    php artisan serve --port=8099                 (en otra terminal)
    python scripts/arnes/correr.py                (los 33)
    python scripts/arnes/correr.py p_tendero      (solo unos)

Sale 0 si los 33 procesos pasan y no hay hallazgos; 1 en cuanto hay uno de los
dos. Ver `LEEME.md`.
"""

import os
import sys

sys.path.insert(0, os.path.dirname(os.path.abspath(__file__)))

import arnes  # noqa: E402

TODOS = ['p_comprador', 'p_tendero', 'p_resto']

modulos = sys.argv[1:] or TODOS

procesos = []
for m in modulos:
    procesos += __import__(m).PROCESOS

arnes.correr_todo(procesos)
