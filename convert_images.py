from pathlib import Path
import re
import json
from PIL import Image

root = Path(__file__).resolve().parent
image_dir = root / 'images'
thumb_dir = image_dir / 'thumbs'

for path in image_dir.glob('*'):
    if path.suffix.lower() not in {'.jpg', '.jpeg', '.png'}:
        continue
    webp_path = path.with_suffix('.webp')
    if webp_path.exists():
        continue
    with Image.open(path) as img:
        if img.mode in {'RGBA', 'LA', 'P'}:
            converted = img.convert('RGBA')
        else:
            converted = img.convert('RGB')
        converted.save(webp_path, 'WEBP', quality=75, lossless=False)

files_to_update = [
    root / 'index.html',
    root / 'css' / 'styles.css',
    root / 'js' / 'script.js',
    root / 'js' / 'trp-list.json',
]

for file_path in files_to_update:
    if not file_path.exists():
        continue
    text = file_path.read_text(encoding='utf-8')
    updated = re.sub(r'\.(jpg|jpeg|png)(?![A-Za-z0-9])', '.webp', text, flags=re.IGNORECASE)
    file_path.write_text(updated, encoding='utf-8')

print('Converted images and updated references.')

# Build low-quality thumbnails for gallery grid cards.
thumb_dir.mkdir(parents=True, exist_ok=True)
trp_json = root / 'js' / 'trp-list.json'

thumb_names = []
if trp_json.exists():
    try:
        thumb_names = json.loads(trp_json.read_text(encoding='utf-8'))
    except Exception:
        thumb_names = []

if not thumb_names:
    thumb_names = sorted(p.name for p in image_dir.glob('TRP-*.webp'))

for name in thumb_names:
    src = image_dir / name
    dst = thumb_dir / name
    if not src.exists():
        continue
    if dst.exists():
        continue

    with Image.open(src) as img:
        converted = img.convert('RGB')
        converted.thumbnail((420, 420), Image.Resampling.LANCZOS)
        converted.save(dst, 'WEBP', quality=28, method=6)

print('Generated gallery thumbnails in images/thumbs.')
