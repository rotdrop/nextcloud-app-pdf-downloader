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
[`UtilTrait::replaceBracedPlaceholders()`](../php-toolkit/Traits/UtilTrait.php#L403).

It is possible to use localized placeholders if they have already been
provided by the translation teams. The translations can be found in the
subdirectory [`../l10n/`](../l10n/).

It is possible to do some post-processing on the substituted
values. This is in particular important when generating filenames in
order to get rid of path separators.

## Syntax

The general syntax of a replacement is
```txt
{[C[N]|]KEY[|M[D]][@FILTER]}
```
where anything in square brackets is optional. The particular parts
have the following meaning:

- 'C' is any character used for optional padding to the left.
- 'N' is the padding length. If ommitted, the value of 1 is assumed.
- '|' is a literal '|'
- 'KEY' is the replacement key
- '|' is a literal '|'
- 'M' is a number of "path" components to include from the right from the
  expansion of KEY with path-delimiter 'D' (default: "/"). E.g. `{KEY|2}` for
  the value `foo/bar/foobar` would result in `bar/foobar`.
- '@' is a literal '@'
- 'FILTER' can be either
  - a single character which is used to replace occurences of '/' in the
    replacement for KEY
  - A=[B] in which case occurences of A are replaced by B. If B is omitted
    occurences of A are replaced by the empty string.
  - the hash-algo passed to the PHP hash($algo, $data) in which case the replacement value
    is the hash w.r.t. FILTER of the replacement data

## Page Labels

THIS SECTION IS INCOMPLETE.

### Default Template

`{DIR_BASENAME} {0|DIR_PAGE_NUMBER}/{DIR_TOTAL_PAGES}`

### Supported Placeholders

- `PATH`
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

The substitutions are provided by [`FileSystemWalker::getPdfFileName()`](../lib/Service/FileSystemWalker.php#L664).

TO BE CONTINUED.
