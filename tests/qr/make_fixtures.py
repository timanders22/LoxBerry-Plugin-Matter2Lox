"""Generate deterministic QR PNGs using the already vendored qrcodegen wheel."""
import struct
import sys
import zlib
from pathlib import Path
sys.path.insert(0, str(Path(__file__).resolve().parents[2] / 'bin/vendor/qrcodegen-1.8.0-py3-none-any.whl'))
from qrcodegen import QrCode


def png(value):
    qr = QrCode.encode_text(value, QrCode.Ecc.MEDIUM)
    scale, border = 10, 4
    width = (qr.get_size() + border * 2) * scale
    rows = []
    for y in range(width):
        rows.append(b'\x00' + bytes(0 if qr.get_module(x // scale - border, y // scale - border) else 255
                                      for x in range(width)))
    def chunk(kind, body):
        return struct.pack('>I', len(body)) + kind + body + struct.pack('>I', zlib.crc32(kind + body))
    return (b'\x89PNG\r\n\x1a\n' + chunk(b'IHDR', struct.pack('>IIBBBBB', width, width, 8, 0, 0, 0, 0))
            + chunk(b'IDAT', zlib.compress(b''.join(rows))) + chunk(b'IEND', b''))

if __name__ == '__main__':
    sys.stdout.buffer.write(png(sys.argv[1]))
