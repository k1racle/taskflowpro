#!/usr/bin/env python3
"""
Unify inline styles across HTML components by replacing common
static style="..." patterns with CSS utility classes.
"""

import re
import sys
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
    'color: #dc2626; border-color: rgba(220, 38, 38, 0.2)': 'crm-text-error',  # special
    'border:1px solid var(--lg-border); background:rgba(255,255,255,.02)': 'crm-border-subtle',
    'border-bottom:1px solid var(--lg-border)': 'crm-border-bottom',
    'border:1px solid var(--lg-border)': 'crm-border',
    'border-color: var(--lg-border)': 'crm-border',  # keep inline border-style, just add class? tricky
    'font-family: ui-monospace, monospace;': 'crm-mono',
    'font-family: ui-monospace, SFMono-Regular, Menlo, Monaco, Consolas, \'Liberation Mono\', \'Courier New\', monospace;': 'crm-mono',
    'opacity:.95': 'crm-opacity-muted',
    'min-width:600px': 'crm-min-w-table',
    'background: var(--lg-primary)': 'crm-bg-primary',
    'background: var(--crm-accent);': 'crm-bg-accent',
    'color: var(--lg-text-primary); direction: ltr; text-align: left;': 'crm-text-primary',  # keep direction inline
}

# Some replacements need to preserve remaining inline styles
PARTIAL_REPLACEMENTS = {
    'color: var(--lg-text-primary); direction: ltr; text-align: left;': ('crm-text-primary', 'direction: ltr; text-align: left;'),
}


def process_file(path: Path) -> tuple[int, int]:
    """Returns (replacements_count, lines_changed)"""
    content = path.read_text(encoding='utf-8')
    original = content
    total_replacements = 0

    # Pattern to match style="..." inside a tag (not :style)
    style_pattern = re.compile(r'(?<![:\w])style="([^"]*)"')

    def replace_tag(matchobj):
        nonlocal total_replacements
        tag_start = content[:matchobj.start()]
        tag_end = content[matchobj.end():]
        style_val = matchobj.group(1).strip()

        # Skip if this looks dynamic (contains Alpine/JS expressions)
        if any(ch in style_val for ch in ['+', '?', '&&', '||', '===', '!==', '<', '>', '(', ')', '{', '}', 'color-mix']):
            return matchobj.group(0)

        # Exact match
        replacement = STYLE_TO_CLASS.get(style_val)
        if replacement:
            total_replacements += 1
            # Add class to existing class attr or create new one
            tag_text = tag_start + matchobj.group(0) + tag_end
            # We operate on the whole content, so we reconstruct below
            return f'__STYLE_REMOVED__CLASS_{replacement}__'

        # Partial match
        partial = PARTIAL_REPLACEMENTS.get(style_val)
        if partial:
            total_replacements += 1
            return f'__STYLE_REMOVED__CLASS_{partial[0]}__STYLE_{partial[1]}__'

        return matchobj.group(0)

    # We need to process each tag individually, but regex on whole file is tricky.
    # Instead, find all tags with static style and replace.
    # Simpler approach: iterate known patterns and replace exact occurrences.

    # Actually, let's do it per exact style string to be safe.
    for style_val, cls in STYLE_TO_CLASS.items():
        needle = f'style="{style_val}"'
        if needle not in content:
            continue
        # Split by needle to add class
        parts = content.split(needle)
        new_parts = []
        for i, part in enumerate(parts):
            new_parts.append(part)
            if i < len(parts) - 1:
                # Add class to the tag that precedes this needle
                # Find the opening '<tag' before needle
                tag_start = part.rfind('<')
                if tag_start == -1:
                    new_parts.append(needle)  # keep original
                    continue
                tag_content = part[tag_start:]
                if 'class="' in tag_content:
                    # Append to existing class
                    idx = tag_content.rfind('class="')
                    before = part[:tag_start] + tag_content[:idx + 7]
                    after = tag_content[idx + 7:]
                    new_parts.append(before + cls + ' ' + after + f' ')
                else:
                    # Insert class attribute before style
                    new_parts.append(f' class="{cls}" ')
                total_replacements += 1
        content = ''.join(new_parts)

    # Handle partials
    for style_val, (cls, remain_style) in PARTIAL_REPLACEMENTS.items():
        needle = f'style="{style_val}"'
        if needle not in content:
            continue
        parts = content.split(needle)
        new_parts = []
        for i, part in enumerate(parts):
            new_parts.append(part)
            if i < len(parts) - 1:
                tag_start = part.rfind('<')
                if tag_start == -1:
                    new_parts.append(needle)
                    continue
                tag_content = part[tag_start:]
                if 'class="' in tag_content:
                    idx = tag_content.rfind('class="')
                    before = part[:tag_start] + tag_content[:idx + 7]
                    after = tag_content[idx + 7:]
                    new_parts.append(before + cls + ' ' + after + f' style="{remain_style}" ')
                else:
                    new_parts.append(f' class="{cls}" style="{remain_style}" ')
                total_replacements += 1
        content = ''.join(new_parts)

    if content != original:
        path.write_text(content, encoding='utf-8')

    return total_replacements, 0


def main():
    files = sorted(COMPONENTS_DIR.glob('*.html'))
    grand_total = 0
    for f in files:
        count, _ = process_file(f)
        if count:
            print(f'{f.name}: {count} replacements')
            grand_total += count
    print(f'\nTotal replacements across all files: {grand_total}')


if __name__ == '__main__':
    main()
