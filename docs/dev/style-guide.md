---
title: Documentation style guide
description: Shared writing, formatting, and structure rules across the Turbo documentation sites.
---

# Documentation style guide

These rules apply across all documentation sites in the Turbo suite so every product shares one voice, layout, and quality gate.

## Language and tone

- **Arabic pages:** Use Modern Standard Arabic (الفصحى الحديثة) only. Avoid colloquial Egyptian or regional dialect words; CI checks and rejects `مش`, `اللي`, `عشان`, `كده`, `مفيش`, `لسه`, `دلوقتي`, and `ازاي`.
- **English pages:** Use plain, short sentences. Prefer active voice and concrete nouns.
- **Second person, task first:** Address the reader directly and put the action before the explanation (for example: «افتح … ثم اضغط …»).
- **No marketing words and no emoji:** Do not use promotional adjectives or emoji characters in headings, prose, or cards.

## Terminology and UI strings

- **Shared glossary:** Keep canonical terms in `.workspace/GLOSSARY.md` and never invent synonyms for an existing term.
- **Exact UI labels:** Arabic UI words in documentation must match the application's Arabic strings verbatim, wrapped in guillemets (`«…»`) or bold when naming buttons and tabs.

## Formatting, numbers, and diagrams

- **File names:** Use English `kebab-case` for all Markdown files and folders (for example `first-run.md`, `order-types.md`).
- **Screenshots:** Store images under `docs/public/img/<section>/` using descriptive `kebab-case` file names.
- **Digits:** Use Western digits (`0`–`9`), never Eastern Arabic-Indic numerals (`٠`–`٩`).
- **Money:** Write currency amounts with Western digits followed by «ج.م» (for example: `120 ج.م`).
- **Diagrams:** Use Mermaid code blocks only for diagrams. Do not commit ASCII art or external diagram images.

## Page template

Every user-facing Arabic page follows this structure:

1. **Frontmatter:** Set `title` and `description`.
2. **Opening line:** State in one short sentence what the page lets the reader do and who it is for.
3. **Feature-gate callout (when applicable):** If a feature is hidden until enabled by support/vendor settings, open the page right after the intro with a callout naming the switch.
4. **Menu path:** Show where to open the screen with `<span class="path">القسم › الشاشة</span>`.
5. **Numbered steps:** Wrap task steps in `<div class="steps">` around a Markdown ordered list so each step renders as a numbered card.
6. **Callouts:** Use VitePress callouts `::: tip نصيحة` and `::: warning تنبيه` with clear titles.
7. **One topic per page:** Keep each page focused on a single workflow or screen.

Example skeleton:

```markdown
---
title: عنوان الصفحة
description: وصف مختصر يوضح الهدف من الصفحة.
---

# عنوان الصفحة

تتيح هذه الصفحة لـ[الدور] تنفيذ [المهمة].

::: warning تنبيه
تظهر هذه الميزة عند تفعيل خيار «اسم الميزة» من الدعم الفني.
:::

افتح <span class="path">الإدارة › الشاشة</span> ثم نفّذ الخطوات التالية:

<div class="steps">

1. افتح الشاشة واضغط **إضافة**.
2. أدخل البيانات المطلوبة ثم اضغط **حفظ**.

</div>

::: tip نصيحة
معلومة مساعدة مختصرة عند الحاجة.
:::
```

## Site layout and public/private split

Every site uses the same six-section layout in this order:

| Section | Folder | Audience and visibility |
|---|---|---|
| البداية | `docs/start/` | Public (default `/` locale, RTL) |
| دليل الاستخدام | `docs/guide/` | Public (default `/` locale, RTL) |
| الإدارة | `docs/admin/` | Public (default `/` locale, RTL) |
| التكامل | `docs/integrations/` | Public (default `/` locale, RTL) |
| Developer | `docs/dev/` | Private (`/dev/`, LTR) |
| Deployment and operations | `docs/dev/ops/` | Private (`/dev/ops/`, LTR) |

- All `docs/dev/**` pages are **private** and excluded from the public build (`DOCS_PUBLIC=1`).
- Public pages (`/start/`, `/guide/`, `/admin/`, `/integrations/`) must **never** link to `/dev/` pages; the public build validates dead links and will fail if any public page links to `/dev/`.
