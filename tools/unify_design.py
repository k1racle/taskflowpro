#!/usr/bin/env python3
"""
Unify inline styles across HTML components by replacing common
static style="..." patterns with CSS utility classes.

Safe approach: process tag-by-tag using regex.
"""

import re
from pathlib import Path

COMPONENTS_DIR = Path(__file__).parent.parent / "assets" / "components"

# Map exact style values -> utility class to ADD (style is removed)
STYLE_TO_CLASS = {
    'color: var(--lg-text-primary)': 'crm-text-primary',
    'color: var(--lg-text-secondary)': 'crm-text-secondary',
    'color: var(--lg-text-tertiary)': 'crm-text-tertiary',
    'color: var(--lg-primary)': 'crm-text-accent',
    'color: var(--crm-text-muted)': 'crm-text-muted',
    'color: var(--crm-text)': 'crm-text-primary',
    'color: var(--lg-success)': 'crm-text-success',
    'color: var(--lg-error)': 'crm-text-error',
    'color: var(--lg-warning)': 'crm-text-warning',
    'color: var(--lg-info)': 'crm-text-info',
    'color: #3B82F6': 'crm-text-info',
    'color: #10B981': 'crm-text-success',
    'color: #EF4444': 'crm-text-error',
    'border:1px solid var(--lg-border); background:rgba(255,255,255,.02)': 'crm-border-subtle',
    'border-bottom:1px solid var(--lg-border)': 'crm-border-bottom',
    'border:1px solid var(--lg-border)': 'crm-border',
    'font-family: ui-monospace, monospace;': 'crm-mono',
    "font-family: ui-monospace, SFMono-Regular, Menlo, Monaco, Consolas, 'Liberation Mono', 'Courier New', monospace;": 'crm-mono',
    'opacity:.95': 'crm-opacity-muted',
    'min-width:600px': 'crm-min-w-table',
    'background: var(--lg-primary)': 'crm-bg-primary',
    'background: var(--crm-accent);': 'crm-bg-accent',
    'border:1px solid rgba(245,158,11,.25); background:rgba(120,53,15,.10)': 'crm-border-warn',
    'border:1px solid rgba(245,158,11,.18); background:rgba(120,53,15,.08)': 'crm-border-warn-light',
    'border:1px solid rgba(34,197,94,.18); background:rgba(20,83,45,.08)': 'crm-border-success',
    'background: color-mix(in oklab, #EF4444 20%, var(--lg-glass-bg))': 'crm-bg-error-soft',
    'border:1px solid var(--lg-border); background: color-mix(in oklab, #000 35%, var(--lg-glass-bg));': 'crm-bg-dark-soft',
}


def find_tag_boundaries(text: str, pos: int) -> tuple[int, int]:
    """Find the start (<) and end (>) of the HTML tag containing position pos."""
    start = text.rfind('<', 0, pos)
    if start == -1:
        return -1, -1
    end = text.find('>', pos)
    if end == -1:
        return -1, -1
    # Make sure there isn't another '>' between start and pos that would close a previous tag
    prev_close = text.find('>', start, pos)
    if prev_close != -1:
        # We're inside text content, not inside a tag
        return -1, -1
    return start, end + 1


def add_class_to_tag(tag_text: str, new_class: str) -> str:
    """Add a class to an HTML tag string. Returns modified tag."""
    class_match = re.search(r'class="([^"]*)"', tag_text)
    if class_match:
        old_classes = class_match.group(1)
        if new_class in old_classes.split():
            return tag_text
        new_classes = old_classes.rstrip() + ' ' + new_class
        return tag_text[:class_match.start()] + f'class="{new_classes}"' + tag_text[class_match.end():]
    else:
        # Insert class right after tag name
        # Find position after tag name: <tagname ...
        m = re.match(r'(<[^\s>]+)', tag_text)
        if m:
            insert_pos = m.end()
            return tag_text[:insert_pos] + f' class="{new_class}"' + tag_text[insert_pos:]
    return tag_text


def remove_style_from_tag(tag_text: str, style_val: str) -> str:
    """Remove exact style="value" from tag."""
    needle = f'style="{style_val}"'
    idx = tag_text.find(needle)
    if idx == -1:
        return tag_text
    return tag_text[:idx] + tag_text[idx + len(needle):]


def process_file(path: Path) -> int:
    content = path.read_text(encoding='utf-8')
    original = content
    total_replacements = 0

    # Process each style mapping
    for style_val, cls in STYLE_TO_CLASS.items():
        offset = 0
        while True:
            idx = content.find(f'style="{style_val}"', offset)
            if idx == -1:
                break

            # Skip if preceded by ':' (dynamic :style)
            if idx > 0 and content[idx - 1] == ':':
                offset = idx + 1
                continue

            tag_start, tag_end = find_tag_boundaries(content, idx)
            if tag_start == -1:
                offset = idx + 1
                continue

            old_tag = content[tag_start:tag_end]
            new_tag = remove_style_from_tag(old_tag, style_val)
            new_tag = add_class_to_tag(new_tag, cls)

            # Clean up extra spaces
            new_tag = re.sub(r'\s{2,}', ' ', new_tag)
            new_tag = new_tag.replace(' >', '>')

            content = content[:tag_start] + new_tag + content[tag_end:]
            total_replacements += 1
            offset = tag_start + len(new_tag)

    if content != original:
        path.write_text(content, encoding='utf-8')

    return total_replacements


def main():
    files = sorted(COMPONENTS_DIR.glob('*.html'))
    grand_total = 0
    for f in files:
        try:
            count = process_file(f)
            if count:
                print(f'{f.name}: {count} replacements')
                grand_total += count
        except Exception as e:
            print(f'{f.name}: ERROR {e}')
    print(f'\nTotal replacements across all files: {grand_total}')


if __name__ == '__main__':
    main()
