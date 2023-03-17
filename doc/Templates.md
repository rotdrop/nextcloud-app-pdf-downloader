# Braced Text Templates

<!-- markdown-toc start - Don't edit this section. Run M-x markdown-toc-refresh-toc -->
**Table of Contents**

- [Intro](#intro)
- [Page Labels](#page-labels)
  - [Default Template](#default-template)
  - [Supported Placeholders](#supported-placeholders)
- [File-Names](#file-names)

<!-- markdown-toc end -->

## Intro

A "braced text template" refers to a piece of text with embedded
placeholders like:

```txt
TextTextText-{BASENAME}.pdf
```

For page label and file name templates, the values of the placeholders
are determined by the names of the processed files and the name of the
directory or archive file being worked on.

The substitutions allow some sort of filtering or padding, detailed
further below. The substitution backend can be found in the traits class
[`UtilTrait::replaceBracedPlaceholders()`](../lib/Toolkit/Traits/UtilTrait.php#L403).

It is possible to use localized placeholders if they have already been
provided by the translation teams. The translations can be found in the
subdirectory [`../l10n/`](../l10n/).

However, there is a known [`issue (#20)`](https://github.com/rotdrop/nextcloud-app-pdf-downloader/issues/20#issue-1490531098)
that will render a template using localized variable names unusable if
the user changes the frontend language. Of course, it is planned to fix
this issue.

## Page Labels

THIS SECTION IS INCOMPLETE.

### Default Template

`{DIR_BASENAME} {0|DIR_PAGE_NUMBER}/{DIR_TOTAL_PAGES}`

### Supported Placeholders

- `BASENAME`
- `FILENAME`
- `EXTENSION`
- `DIRNAME`
- `DIR_BASEAME`
- `DIR_PAGE_NUMBER`
- `DIR_TOTAL_PAGES`
- `FILE_PAGE_NUMBER`
- `FILE_TOTAL_PAGES`

## File-Names

The substitutions are provided by [`FileSystemWalker::getPdfFileName()`](../lib/Service/FileSystemWalker.php#L525).

TO BE CONTINUED.
