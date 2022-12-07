## Braced Text Templates

<!-- markdown-toc start - Don't edit this section. Run M-x markdown-toc-refresh-toc -->
**Table of Contents**

- [Braced Text Templates](#braced-text-templates)
    - [Intro](#intro)
    - [Page Labels](#page-labels)
    - [File-Names](#file-names)

<!-- markdown-toc end -->

### Intro

A "braced text template" refers to piece of text with embedded placeholders like

```
TextTextText-{BASENAME}.pdf
```

For the purpose of page label and file-name templates the values of
the placeholders are determined by the names of the processed files
and the name of the directory or archive-file being worked on.

The substitutions allow some sort of filtering or padding. This is
detailed further below. The substitution backend can be found in the
traits-class
[`UtilTrait::replaceBracedPlaceholders()`](../lib/Toolkit/Traits/UtilTrait.php#L403).

### Page Labels

The substitutions are provided by [`PdfCombiner::makePageLabelFromTemplate()`](../lib/Service/PdfCombiner.php#L366).

TO BE CONTINUED.

### File-Names

The substitutions are provided by [`FileSystemWalker::getPdfFileName()`](../lib/Service/FileSystemWalker.php#L525).

TO BE CONTINUED.
