#!/usr/bin/env python3
"""Checks that every plugin string has a translation in languages/*.po, then builds .mo and .l10n.php.

Usage: python3 tools/build-translations.py
Arabic strings must have no diacritics and no punctuation except the full stop.
"""
import glob, re, struct, sys, os

ROOT = os.path.abspath(os.path.join(os.path.dirname(__file__), '..', 'sallanet-pay'))
PLURAL = 'nplurals=6; plural=(n==0 ? 0 : n==1 ? 1 : n==2 ? 2 : n%100>=3 && n%100<=10 ? 3 : n%100>=11 ? 4 : 5);'
EXTRA = ['Pay with Pi (sallanet Pay)', 'You will be taken to sallanet Pay to complete the payment securely.']


def source_strings():
    out = []
    for f in sorted(glob.glob(os.path.join(ROOT, '**', '*.php'), recursive=True)):
        if '/languages/' in f:
            continue
        for m in re.finditer(r"(?:__|esc_html__|esc_html_e|esc_attr__|_e)\(\s*'((?:[^'\\]|\\.)*)'\s*,\s*'sallanet-pay'", open(f).read()):
            t = m.group(1).replace("\\'", "'")
            if t not in out:
                out.append(t)
    return out + [t for t in EXTRA if t not in out]


def read_po(path):
    pairs, key = {}, None
    unq = lambda s: s[1:-1].replace('\\"', '"').replace('\\\\', '\\')
    for line in open(path, encoding='utf-8'):
        line = line.strip()
        if line.startswith('msgid '):
            key = unq(line[6:])
        elif line.startswith('msgstr ') and key:
            pairs[key] = unq(line[7:])
    return pairs


def write_all(lang, pairs, strings):
    base = os.path.join(ROOT, 'languages', 'sallanet-pay-' + lang)
    esc = lambda s: s.replace('\\', '\\\\').replace('"', '\\"')
    po = 'msgid ""\nmsgstr ""\n"Content-Type: text/plain; charset=UTF-8\\n"\n"Language: %s\\n"\n"Plural-Forms: %s\\n"\n\n' % (lang, PLURAL)
    for s in strings:
        po += 'msgid "%s"\nmsgstr "%s"\n\n' % (esc(s), esc(pairs[s]))
    open(base + '.po', 'w', encoding='utf-8').write(po)
    php = lambda s: "'" + s.replace('\\', '\\\\').replace("'", "\\'") + "'"
    l10n = "<?php\nreturn array('domain'=>'sallanet-pay','plural-forms'=>%s,'language'=>%s,'messages'=>array(\n" % (php(PLURAL), php(lang))
    l10n += ",\n".join('%s=>%s' % (php(s), php(pairs[s])) for s in strings) + "\n));\n"
    open(base + '.l10n.php', 'w', encoding='utf-8').write(l10n)
    hdr = 'Content-Type: text/plain; charset=UTF-8\nLanguage: %s\nPlural-Forms: %s\n' % (lang, PLURAL)
    items = sorted([('', hdr)] + [(s, pairs[s]) for s in strings])
    ids, strs, offs = b'', b'', []
    for k, v in items:
        kb, vb = k.encode(), v.encode()
        offs.append((len(ids), len(kb), len(strs), len(vb)))
        ids += kb + b'\0'
        strs += vb + b'\0'
    n = len(items)
    ko, vo = 28, 28 + n * 8
    ido, so = vo + n * 8, vo + n * 8 + len(ids)
    mo = struct.pack('Iiiiiii', 0x950412de, 0, n, ko, vo, 0, 0)
    mo += b''.join(struct.pack('ii', o[1], ido + o[0]) for o in offs)
    mo += b''.join(struct.pack('ii', o[3], so + o[2]) for o in offs)
    open(base + '.mo', 'wb').write(mo + ids + strs)


def main():
    strings = source_strings()
    ok = True
    for po in glob.glob(os.path.join(ROOT, 'languages', '*.po')):
        lang = re.search(r'^sallanet-pay-(.+)\.po$', os.path.basename(po)).group(1)
        pairs = read_po(po)
        for s in strings:
            t = pairs.get(s, '')
            if not t:
                print('MISSING [%s]: %s' % (lang, s)); ok = False; continue
            if sorted(re.findall(r'%\d?\$?[sd]', s)) != sorted(re.findall(r'%\d?\$?[sd]', t)):
                print('SLOTS [%s]: %s' % (lang, s)); ok = False
            plain = re.sub(r'%\d?\$?[sd]', '', t)
            if lang == 'ar' and re.search(r'[ً-ْ]', t):
                print('DIACRITICS: ' + t); ok = False
            if lang == 'ar' and re.search(r'[؀-ۿ]', plain) and re.search(r'[،,:;؟?!"«»()\[\]–—…#]', plain):
                print('PUNCTUATION: ' + t); ok = False
        if ok:
            write_all(lang, pairs, strings)
            print('built %s: %d strings' % (lang, len(strings)))
    sys.exit(0 if ok else 1)


main()
