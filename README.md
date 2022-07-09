# Recursive PDF Downloader

## Intro
This is an app for the Nextcloud cloud software. It adds a new menu
entry to the actions menu in the files view which lets you download
entire directory trees as a single PDF file.

That is:

- it walks through the given folder
- converts all found files to PDF
  - handles some special cases
  - tries to convert the remaining files with unoconv
  - generates a PDF placeholder error page for each failed conversion 
- it then combines all found or generated PDF files in one document using pdftk
- add bookmarks to mark the start of each folder and each file
  - existing bookmarks are "shifted down" accordingly
  - the resulting bookmark structure resembles the folder structure
- optionally places a "Folder PAGE/MAX_PAGES" label to top of each page
- finally presents the generated PDF as download

## Working conversions
What works:
- PDF files ;)
- office files via Libreoffice
- .eml (rfc822) files, i.e. emails you saved to disk via mhonarc, wkthmltopdf
- html files via wkhtmltopdf
- tiff files via tiff2pdf
- Postscript files via ps2pdf
- everything else is passed to unoconv
- if unoconv fails, a PDF placeholder error page is generated

## Not working, but somehow on the "ideas" list
- on the fly decompression of archives (.zip etc.)
- user (well administrator) provided fallback/catch-all conversion script

