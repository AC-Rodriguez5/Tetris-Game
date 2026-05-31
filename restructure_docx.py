"""
Reorganize ITEC106_Cosmic_Tetris_Documentation.docx so that the out-of-template
sections 7 (BLITZ MODE), 8 (ADDITIONAL FEATURES), 9 (ADDITIONAL REVISIONS) and
10 (ADDITIONAL ERRORS) are folded into the 6-section template structure:

  3. TECHNICAL ARCHITECTURE       <- gains 3.4-3.9 (from old 7.1, 7.2, 7.3, 7.7, 7.8, 7.9)
  4. DEVELOPMENT AND SYSTEM LOGIC <- gains 4.4-4.13 (from old 7.4-7.6, 8.1-8.7)
  5. IMPROVEMENT / REVISION       <- gains Revisions 6-14 (from old Section 9)
  6. ERRORS ENCOUNTERED ...       <- gains Errors 6-12 (from old Section 10)

Body element layout is verified to be the same as the indices used here.
"""
import shutil
from copy import deepcopy
from docx import Document

SRC = r"C:\xampp\htdocs\Tetris\ITEC106_Cosmic_Tetris_Documentation.docx"
BAK = r"C:\xampp\htdocs\Tetris\ITEC106_Cosmic_Tetris_Documentation.docx.bak"

W = "{http://schemas.openxmlformats.org/wordprocessingml/2006/main}"

shutil.copy(SRC, BAK)

doc = Document(SRC)
body = doc.element.body
elements = list(body)  # snapshot before any modification

assert len(elements) == 576, f"unexpected body length {len(elements)}"

# ---------------------------------------------------------------------------
# 1. Renumber heading text in-place (operates on the original elements which
#    will be reordered in step 3).
# ---------------------------------------------------------------------------
heading_renames = {
    391: ("7.1", "3.4"),
    403: ("7.2", "3.5"),
    409: ("7.3", "3.6"),
    421: ("7.4", "4.4"),
    429: ("7.5", "4.5"),
    438: ("7.6", "4.6"),
    447: ("7.7", "3.7"),
    456: ("7.8", "3.8"),
    461: ("7.9", "3.9"),
    467: ("8.1", "4.7"),
    474: ("8.2", "4.8"),
    477: ("8.3", "4.9"),
    484: ("8.4", "4.10"),
    487: ("8.5", "4.11"),
    495: ("8.6", "4.12"),
    501: ("8.7", "4.13"),
}

for idx, (old, new) in heading_renames.items():
    el = elements[idx]
    replaced = False
    for t in el.iter(W + "t"):
        if t.text and t.text.startswith(old):
            t.text = new + t.text[len(old):]
            replaced = True
            break
    if not replaced:
        # Fallback: any <w:t> that contains old prefix
        for t in el.iter(W + "t"):
            if t.text and old in t.text:
                t.text = t.text.replace(old, new, 1)
                replaced = True
                break
    assert replaced, f"failed to rename heading at body index {idx}"

# ---------------------------------------------------------------------------
# 2. Build new TOC subsection entries by deep-copying existing templates.
#    - numId 7 is the list used under TECHNICAL ARCHITECTURE
#    - numId 6 is the list used under DEVELOPMENT AND SYSTEM LOGIC
# ---------------------------------------------------------------------------
tpl_section3 = elements[44]   # "State Management"   (numId 7)
tpl_section4 = elements[48]   # "Edge Case Handling" (numId 6)


def make_toc_entry(template, text):
    new_el = deepcopy(template)
    # Replace text of first <w:t>; subsequent <w:t> nodes in the template are empty
    set_first = False
    for t in new_el.iter(W + "t"):
        if not set_first:
            t.text = text
            set_first = True
    return new_el


toc_section3 = [
    make_toc_entry(tpl_section3, "Blitz Match Lifecycle and Room Phases"),
    make_toc_entry(tpl_section3, "Matchmaking Modes"),
    make_toc_entry(tpl_section3, "Real-Time Synchronization Loop"),
    make_toc_entry(tpl_section3, "Blitz Database Schema"),
    make_toc_entry(tpl_section3, "Blitz API Endpoint Reference"),
    make_toc_entry(tpl_section3, "Blitz Leaderboard Page"),
]

toc_section4 = [
    make_toc_entry(tpl_section4, "Garbage Line Attack System"),
    make_toc_entry(tpl_section4, "Match-Ending Conditions"),
    make_toc_entry(tpl_section4, "Rematch System"),
    make_toc_entry(tpl_section4, "Ghost Piece (Landing Preview)"),
    make_toc_entry(tpl_section4, "Hard Drop (Slam)"),
    make_toc_entry(tpl_section4, "Touch Controls (Mobile)"),
    make_toc_entry(tpl_section4, "CSRF Protection for Score Submissions"),
    make_toc_entry(tpl_section4, "Database Resilience and Friendly Error Messages"),
    make_toc_entry(tpl_section4, "Disconnect Handling and Beacon Cleanup"),
    make_toc_entry(tpl_section4, "SPA-Style Page Architecture for Blitz Rooms"),
]

# ---------------------------------------------------------------------------
# 3. Assemble the new body order.
# ---------------------------------------------------------------------------
new_elements = []

# Title page + TOC up through "State Management" (body 0..44)
new_elements.extend(elements[0:45])
# New TOC entries 3.4-3.9
new_elements.extend(toc_section3)
# TOC: DEVELOPMENT... through "Edge Case Handling" (body 45..48)
new_elements.extend(elements[45:49])
# New TOC entries 4.4-4.13
new_elements.extend(toc_section4)
# TOC: IMPROVEMENT / REVISION + ERRORS ENCOUNTERED (body 49..50)
new_elements.extend(elements[49:51])
# (Skip body 51..55: the four out-of-template TOC entries.)
# Blank-page paragraphs after TOC + Section 1 + Section 2 (body 56..142)
new_elements.extend(elements[56:143])

# Section 3 (TECHNICAL ARCHITECTURE) + existing 3.1-3.3 (body 143..195)
new_elements.extend(elements[143:196])
# New 3.4 (from old 7.1)
new_elements.extend(elements[391:403])
# New 3.5 (from old 7.2)
new_elements.extend(elements[403:409])
# New 3.6 (from old 7.3)
new_elements.extend(elements[409:421])
# New 3.7 (from old 7.7)
new_elements.extend(elements[447:456])
# New 3.8 (from old 7.8)
new_elements.extend(elements[456:461])
# New 3.9 (from old 7.9)
new_elements.extend(elements[461:464])

# Section 4 (DEVELOPMENT AND SYSTEM LOGIC) + existing 4.1-4.3 (body 196..340)
new_elements.extend(elements[196:341])
# New 4.4 (from old 7.4)
new_elements.extend(elements[421:429])
# New 4.5 (from old 7.5)
new_elements.extend(elements[429:438])
# New 4.6 (from old 7.6)
new_elements.extend(elements[438:447])
# New 4.7 (from old 8.1)
new_elements.extend(elements[467:474])
# New 4.8 (from old 8.2)
new_elements.extend(elements[474:477])
# New 4.9 (from old 8.3)
new_elements.extend(elements[477:484])
# New 4.10 (from old 8.4)
new_elements.extend(elements[484:487])
# New 4.11 (from old 8.5)
new_elements.extend(elements[487:495])
# New 4.12 (from old 8.6)
new_elements.extend(elements[495:501])
# New 4.13 (from old 8.7)
new_elements.extend(elements[501:504])

# Section 5 (IMPROVEMENT / REVISION) + Revisions 1-5 (body 341..358)
new_elements.extend(elements[341:359])
# Append Revisions 6-14 from old Section 9 body 507..535 (skip 504-506 heading/intro/blank)
new_elements.extend(elements[507:536])

# Section 6 (ERRORS ENCOUNTERED) + Errors 1-5 (body 359..387)
new_elements.extend(elements[359:388])
# Append Errors 6-12 from old Section 10 body 539..573 (skip 536-538 heading/intro/blank)
new_elements.extend(elements[539:574])

# Trailing blank paragraph + section properties
new_elements.extend(elements[574:576])

# ---------------------------------------------------------------------------
# 4. Detach and re-attach in the new order.
# ---------------------------------------------------------------------------
for e in elements:
    body.remove(e)

for e in new_elements:
    body.append(e)

doc.save(SRC)

print(f"Done. Backup written to {BAK}")
print(f"New body element count: {len(new_elements)} (was 576)")
