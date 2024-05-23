# Changelog
All notable changes to this project will be documented in this file.

The format is based on [Keep a Changelog](http://keepachangelog.com/en/1.0.0/)
and this project adheres to [Semantic Versioning](http://semver.org/spec/v2.0.0.html).

## [1.2.3-rcX] unreleased

### Added

- Support Nextcloud 29

### Fixed

- mhonarc (.eml files): break long lines at column 80

## [1.2.2] - 2024-03-24

### Fixed

- A spurious error message in the logs. This did not affect the
  functionality of the app but puzzled its users.

## [1.2.1] - 2024-03-17

### Fixed

- restore PHP 8.1 compatibility

## [1.2.0] - 2024-03-17

### Added

- Support Nextcloud v28, in particular use the new event-bus as the
  old legacy file-list is no longer available.

### Changed

- Reduce the size of the JS assets needed to hook in to the files-app sidebar

- Update to recent @nextcloud/vue

- Drop support for Nextcloud <= v27. The differences in the files-API
  are just too big.

- Translations

### Fixed

- Background cleanup job used file creation which is in general not
  maintained by Nextcloud. Change to mtime.

## [1.1.4-rc1] - unreleased

### Fixed

- mainly cosmetical things like double slashes

### Added

- add full PATH to template substitutions

## [1.1.3] - 2023-08-02

### Fixed

- on-the-fly extraction of archives

### Added

- Support NC 26 and 27

## [1.1.2] - 2023-03-23

### Fixed

- spelling errors and translations
- remove the notion of "plain file" (we have only folders and plain
  files, no special files like sockets, pipes etc.)
- handling of a fixed font size for page labels
- unsupported command line arguments with old versions of img2pdf
- optionally support PDF-conversion of individual files (in addition
  to converting entire directory trees and archives content)

### Added

- wrap php app toolkit into the app namespace in order to avoid
  collisions with other apps using another version of the toolkit.

## [1.1.1] - 2023-01-17

### Fixed

- clean up and fix of theming support (in particular dark theme)

### Added

- optionally disable error pages
- include/exclude filename regular expressions

## [1.1.0] - 2022-12-27

### Fixed

- a bunch of spelling errors

### Added

- background jobs
- save to cloud
- styling of page labels
- font preview in the personal settings page
- templates for the page labels
- templates for the file-names of generated files
- template variable names are potentially localized
- support Nextcloud 25
- support dark themes for NC 25

## [1.0.11] - 2022-11-17

### Fixed

- the "fix" from release 10.

### Added

- internal restructuring of Vue and PHP source code, there is now a
  common base of code shared between the
  [Archive Explorer](https://github.com/rotdrop/nextcloud-app-files-archive)
  and this app.

## [1.0.10] - 2022-11-14

### Fixed

- erroneous usage of classes defined in [Archive Explorer](https://github.com/rotdrop/nextcloud-app-files-archive)

## [1.0.9] - 2022-11-13

### Added

- font preview in the personal settings

### Fixed

- sync archive handling with files_archive app and make sure all needed mime-type are there

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

- page labels: PDF page dimensions were parsed incorrectly and page
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

- Personal setting "page label generation" was not remembered across invocations of the settings-app

## [1.0.0] - 2022-07-20

### Added

- First release
