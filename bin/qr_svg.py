#!/usr/bin/env python3
"""Matter to Loxone - einen Matter-Kopplungstext als QR-Bild (SVG) ausgeben.

Aufruf:   python3 -I -B qr_svg.py < datei_mit_dem_text

Der Text kommt NUR ueber die Standardeingabe, nie ueber die Kommandozeile:
ein Kopplungscode ist ein Zugang auf Zeit und gehoert nicht in die
Prozessliste (Regeln/05). Erlaubt ist genau ein Matter-Kopplungstext
(MT:..., Zeichen 0-9, A-Z, Punkt, Bindestrich). Ausgabe: ein SVG auf der
Standardausgabe - ein <rect> als weisser Grund, ein <path> je Modulzeile,
Ruhezone 4 Module, Fehlerkorrektur mindestens M (die Bibliothek hebt sie an,
wenn das ohne groesseres Symbol geht), Alphanumerik-Modus.

Rueckgabe 0 mit Bild, 1 ohne. Auf die Fehlerausgabe geht nichts - auch nicht
der Text, wenn er abgewiesen wird.

Nicht an ein Kopplungsfenster gebunden: die geplante Bridge ruft denselben
Helfer mit ihrem Kopplungstext (Entwurf 0.9.39, 2.1).

Bibliothek: qrcodegen 1.8.0 von Project Nayuki (MIT License,
https://www.nayuki.io/page/qr-code-generator-library), mitgeliefert als
Wheel unter bin/vendor/ und vor dem Laden gegen die Pruefsumme von PyPI
geprueft. Geladen wird nur aus diesem Wheel (zipimport), nicht aus einer
anderen Installation.
"""
import hashlib
import os
import re
import sys

WHEEL = "qrcodegen-1.8.0-py3-none-any.whl"
WHEEL_SHA256 = "06bf87021bc351f1c6e722ac67db8a059c4fe27bbfbca39ed7a9a2364abdd7a6"
MUSTER = re.compile(r"^MT:[0-9A-Z.\-]{5,60}$")
RUHEZONE = 4
PUNKT = 8          # Bildpunkte je Modul (Breite und Hoehe des Bildes)


def bibliothek():
    pfad = os.path.join(os.path.dirname(os.path.abspath(__file__)), "vendor", WHEEL)
    with open(pfad, "rb") as f:
        inhalt = f.read()
    if hashlib.sha256(inhalt).hexdigest() != WHEEL_SHA256:
        raise RuntimeError("Pruefsumme")
    sys.path.insert(0, pfad)
    import qrcodegen  # noqa: PLC0415 - erst nach der Pruefung
    return qrcodegen


def svg(text: str) -> str:
    q = bibliothek()
    code = q.QrCode.encode_segments(q.QrSegment.make_segments(text), q.QrCode.Ecc.MEDIUM)
    n = code.get_size()
    gesamt = n + 2 * RUHEZONE
    breite = gesamt * PUNKT
    teile = ['<svg xmlns="http://www.w3.org/2000/svg" viewBox="0 0 %d %d" width="%d" '
             'height="%d" shape-rendering="crispEdges">' % (gesamt, gesamt, breite, breite),
             '<rect width="%d" height="%d" fill="#ffffff"/>' % (gesamt, gesamt)]
    for y in range(n):
        d = []
        x = 0
        while x < n:
            if code.get_module(x, y):
                s = x
                while x < n and code.get_module(x, y):
                    x += 1
                d.append("M%d,%dh%dv1h-%dz" % (s + RUHEZONE, y + RUHEZONE, x - s, x - s))
            else:
                x += 1
        if d:
            teile.append('<path d="%s" fill="#000000"/>' % "".join(d))
    teile.append("</svg>")
    return "".join(teile)


def main() -> int:
    try:
        roh = sys.stdin.buffer.read(256)
        text = roh.decode("ascii").strip()
        if not MUSTER.match(text):
            return 1
        sys.stdout.write(svg(text) + "\n")
        sys.stdout.flush()
        return 0
    except Exception:  # noqa: BLE001 - kein Text auf die Fehlerausgabe
        return 1


if __name__ == "__main__":
    sys.exit(main())
