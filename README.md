# Recursive PDF Downloader

<!-- markdown-toc start - Don't edit this section. Run M-x markdown-toc-refresh-toc -->
**Table of Contents**

- [Intro](#intro)
- [Compatibility](#compatibility)
- [Working Conversions](#working-conversions)
  - [Builtin Converters](#builtin-converters)
  - [Custom Converters](#custom-converters)
- [On-the-fly Extraction of Archive Files](#on-the-fly-extraction-of-archive-files)
  - [Security](#security)
  - [Implementation](#implementation)
- [User Preferences](#user-preferences)
  - [Page Label and File-Name Templates](#page-label-and-file-name-templates)
  - [Overlay Font Selection](#overlay-font-selection)
  - [Include and Exclude Patterns](#include-and-exclude-patterns)
  - [Archive Files](#archive-files)
  - [Conversion of Individual Files](#conversion-of-individual-files)
- [Performance](#performance)
- [Other Nextcloud PDF Converters](#other-nextcloud-pdf-converters)
- [Todo, some problems I am aware of](#todo-some-problems-i-am-aware-of)

<!-- markdown-toc end -->

## Intro

This is an app for the Nextcloud cloud software. It adds a new menu
entry to the actions menu of each folder, archive, or individual file in
the files view which lets you download, respectively, entire directories
trees, all files in archives, or other individual files, converted and
assembled as a single PDF file. Additionally, it adds a tab to the
details-view where version actions can be performed.

For the PDF generation the following steps are performed:

- walk through the given folder
- convert all found files to PDF
  - optionally transparently traverse archive files (zip etc.)
  - handle some special cases
  - try to convert the remaining files with `unoconv` or an
    admin-provided fallback-script
  - generate a PDF placeholder error page for each failed conversion
- then combine all found or generated PDF files in one document using
  `pdftk`
- add bookmarks to mark the start of each folder and each file
  - existing bookmarks are "shifted down" accordingly
  - the resulting bookmark structure resembles the folder structure
- optionally place a "Folder PAGE/MAX_PAGES" label to top of each page
- finally present the generated PDF as download or save it to the
  cloud filesystem.

The offers the choice between online and background PDF
generation. "Background" means that a job is scheduled which then runs
independent from the web-browser frontend. The user will be notified
after the job has completed.

## Compatibility

The app currently requires PHP >= 8.0. It should be usable with
Nextcloud v23 and probably also with v24.

## Working Conversions

### Builtin Converters

- PDF files ;) -- of course, just pass-through
- office files via LibreOffice
- .eml (rfc822) files, i.e. emails you saved to disk, via `mhonarc`,
  `wkthmltopdf`
- html files via `wkhtmltopdf`
- tiff files via `tiff2pdf`
- Postscript files via `ps2pdf`
- everything else is passed to `unoconv`
- if `unoconv` fails, a PDF placeholder error page is generated

### Custom Converters

Administrators may specify a shell-script or program for

- default conversion: try this script before any other converters, if
  it fails continue with the builtin converters
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
  usage on the server or if they feel that the builtin limit of 2^30
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

## User Preferences

### Page Label and File-Name Templates

The app allow to configure page labels and automatically generated
download and destination file-names based on a user configured
template. The details can be found in [Braced Text Templates](doc/Templates.md).

### Overlay Font Selection

- the fonts can be customized from the list of fonts shopped with `tcpdf`
- the backend generates font-samples for the chosen fonts and also
  provides a preview of the configured page labels with the chosen
  font.

### Include and Exclude Patterns

Files can be included and excluded by regular expressions and a
setting which controls whether the one or the other regular expression
has precedence in case both patterns match. Unfortunately, those
patterns cannot (yet) be controller from the "details" panel.

### Archive Files

If enabled by the administrators users can optionally disable
on-the-fly handling of archive files and also restrict the archive
size limit imposed by the admins further.

### Conversion of Individual Files

Optionally individual files (as opposed to directory trees and archives)
can directly be converted to PDF. The default is to enable this
feature. The drawback is that this adds an actions menu entry to each
filesystem node, even to PDF files themselves.

## Performance

- unfortunately, the app is not the fastest horse one could think of.
  In particular the `unvconv` (Libreoffice) converter tends to be
  somewhat slow. Conversion time increases linearly with the number of
  files to be converted, of course.
- it might be necessary to tweak your web-server to allow for larger
  execution times (several minutes) if you do not want to make use of
  the background PDF generation

## Other Nextcloud PDF Converters

There are at least two other apps which also are dedicated to PDF
conversion respectively allow for PDF conversion:

- [`nextcloud/workflow_pdf_converter`](https://github.com/nextcloud/workflow_pdf_converter)
  - this app is dedicated to automated PDF conversion based on workflow-rules
  - at the time of this writing conversion is done with Libreoffice
- [`newroco/emlviewer`](https://github.com/newroco/emlviewer)
  - as the names states this is a viewer module for `.eml`-files (emails)
  - the eml-view also provides a PDF-download button
  - at the time of this writing PDF conversion is done with MPDF

## Todo, some problems I am aware of

- please feel free to submit issues!
- ZIP-bomb detection might need improvement
- There is no test-suite. This is really an issue.
