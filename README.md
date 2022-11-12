# Recursive PDF Downloader

<!-- markdown-toc start - Don't edit this section. Run M-x markdown-toc-refresh-toc -->
**Table of Contents**

- [Recursive PDF Downloader](#recursive-pdf-downloader)
    - [Intro](#intro)
    - [Compatibility](#compatibility)
    - [Working Conversions](#working-conversions)
        - [Builtin Converters](#builtin-converters)
        - [Custom Converters](#custom-converters)
    - [On-the-fly Extraction of Archive Files](#on-the-fly-extraction-of-archive-files)
        - [Security](#security)
        - [Implementation](#implementation)
    - [Performance](#performance)
    - [Todo, some problems I am aware of](#todo-some-problems-i-am-aware-of)

<!-- markdown-toc end -->

## Intro
This is an app for the Nextcloud cloud software. It adds a new menu
entry to the actions menu in the files view which lets you download
entire directory trees as a single PDF file.

That is:

- it walks through the given folder
- converts all found files to PDF
  - optionally transparently traverses archive files (zip etc.)
  - handles some special cases
  - tries to convert the remaining files with unoconv or an
    admin-provided fallback-script
  - generates a PDF placeholder error page for each failed conversion
- it then combines all found or generated PDF files in one document using pdftk
- add bookmarks to mark the start of each folder and each file
  - existing bookmarks are "shifted down" accordingly
  - the resulting bookmark structure resembles the folder structure
- optionally places a "Folder PAGE/MAX_PAGES" label to top of each page
- finally presents the generated PDF as download

## Compatibility
The app currently requires PHP >= 8.0. It should be usable with
Nextcloud v23 and probably also with v24.

## Working Conversions

### Builtin Converters

- PDF files ;) -- of course, just pass-through
- office files via Libreoffice
- .eml (rfc822) files, i.e. emails you saved to disk via mhonarc, wkthmltopdf
- html files via wkhtmltopdf
- tiff files via tiff2pdf
- Postscript files via ps2pdf
- everything else is passed to unoconv
- if unoconv fails, a PDF placeholder error page is generated

### Custom Converters
Administrators may specify a shell-script or program for

- default conversion: try this script before any other converters, if
  it fails continue with the builtin convertes
- fallback conversion: if all other converters fail, try the given
  script as fallback, if that fails also generate an error page.

  If no fallback-converter is configured then `unoconv` is used as fallback.

## On-the-fly Extraction of Archive Files
If enabled by an admin users can choose to enable on-the-fly
extraction of archive files.

### Security

- in order to somehow reduce the danger of
  [zip-bombs](https://en.wikipedia.org/wiki/Zip_bomb) there is a
  hard-coded upper limit of the decompressed archive size
- administrators can lower this limit in order to reduce resource
  usage on the server or if they feel that the builin limit of 2^30
  bytes is too high.
- users may decrease this limit further on a per-user basis
- administrators may be disabled by administrators altogether
- if enable users may decide by themselves whether to enable this
  feature or not

### Implementation
This package relies on
[`wapmorgan/unified-archive`](https://github.com/wapmorgan/UnifiedArchive)
as archive handling backend. Please see there for a list of supported
archive formats and how to support further archive formats.

## Performance
- unfortunately, the app is not the fastest horse one could think
  of. In particular the unvconv (Libreoffice) converter tends to be
  somewhat slow. Conversion time increases linearly with the number of
  files to be converted, of course.
- it might be necessary to tweak your web-server to allow for larger
  execution times (several minutes).

## Todo, some problems I am aware of
- please feel free to submit issues!
- perhaps execution time problems could be leveraged by implementing a
  sort-of streaming context, or by accumulating the results in the
  javascript font-end. The JS frontend would then receive the results
  for each individual file conversion.
- ZIP-bomb detection might need improvement
- There is no test-suite. This is really an issue.
