# Changelog
All notable changes to this project will be documented in this file.

The format is based on [Keep a Changelog](http://keepachangelog.com/en/1.0.0/)
and this project adheres to [Semantic Versioning](http://semver.org/spec/v2.0.0.html).

## [1.0.8] - 2022-11-11

### Added

- Transifex translations integration

## [1.0.7] - 2022-11-10

### Fixed

- handling of default values in app-settings page
- add `--rotation-ifvalid` option to `img2pdf` converter in order to
  ignore broken rotation settings in JPEG files. **This requires img2pdf >= v0.4.4**

### Added

- Add pandoc to convert markdown to html. This means in particular the
  the "rich workspace" "Readme.md" files are formatted. This needs
  "pandoc" to be installed.
- Optionally group files first instead of starting with the
  folders. This may be beneficial if a directory contains a Readme.md
  as this causes the description file to show up in front of any
  sub-folders.
- Post-process mhonarc converter and replace any image urls with
  data-uris. This make attachments with "content-disposition: inline"
  work as expected. Any non-inline attachments are still detached.

## [1.0.6] - 2022-09-17

### Fixed

- page-labels: PDF page dimensions were parsed incorrectly and page
  rotation was not taken into account

### Added

- use img2pdf instead of unoconv for image/jpeg as LibreOffice/unoconv
  still ignores the EXIF rotation settings. Also, img2pdf should be
  much faster.

## [1.0.5] - 2022-09-05

### Fixed

- fix settings logic

### Added

- screenshots

## [1.0.4] - 2022-09-05

### Fixed

- fix duplicate bookmarks for folders which contain both, plain files
  and sub-directories.

### Added

- optional on-the-fly extraction of archive files by means of
  [wapmorgan/unified-archive](https://github.com/wapmorgan/UnifiedArchive)

- admin-customizable custom conversion scripts (default and fallback), including
  the possibility to disable the builtin converters.

- display the found converter executables on the admin settings page.

## [1.0.3] - 2022-09-02

### Fixed

- fix infinite loop with unoconv conversion
- fix generation of error page when the conversion fails.

## [1.0.2] - 2022-08-22

### Fixed

- Remove blacklisted files (i.e. .htaccess) from app-store distribution

## [1.0.1] - 2022-07-20

### Fixed

- Personal setting "page-label generation" was not remembered across invocations of the settings-app

## [1.0.0] - 2022-07-20

### Added

- First release
