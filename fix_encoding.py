import os

# Mojibake: these are the raw UTF-8 multi-byte sequences that got garbled
# when files were read as Windows-1252 and written back
fixes = {
    b'\xe2\x80\xb9': b'&lsaquo;',   # ‹ → &lsaquo;
    b'\xe2\x80\xba': b'&rsaquo;',   # › → &rsaquo;
    b'\xe2\x80\x94': b'&mdash;',    # — → &mdash;
    b'\xe2\x80\x93': b'&ndash;',    # – → &ndash;
    b'\xe2\x80\xa6': b'...',         # … → ...
    b'\xe2\x86\x90': b'&larr;',     # ← → &larr;
    b'\xe2\x86\x92': b'&rarr;',     # → → &rarr;
}

for root, dirs, files in os.walk('pages'):
    for f in files:
        if not f.endswith('.php'):
            continue
        path = os.path.join(root, f)
        with open(path, 'rb') as fh:
            data = fh.read()
        new = data
        for bad, good in fixes.items():
            new = new.replace(bad, good)
        if new != data:
            with open(path, 'wb') as fh:
                fh.write(new)
            print('Fixed: ' + path)

print('done')
