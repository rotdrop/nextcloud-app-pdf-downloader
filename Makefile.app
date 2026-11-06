# This file is licensed under the Affero General Public License version 3 or
# later. See the COPYING file.
#
# pdf_downloader specific: rebuild the DejaVu fonts for TCPDF and ship them
# with the appstore archive. Included by the Makefile after the shared app.mk.

DEJAVU_ARCHIVE_BASE ?= dejavu-fonts-ttf
DEJAVU_ARCHIVE_FORMAT ?= tar.bz2
DEJAVU_VERSION ?= 2.37
DEJAVU_BASEURL ?= https://sourceforge.net/projects/dejavu/files/dejavu
DEJAVU_ARCHIVE ?= $(DEJAVU_ARCHIVE_BASE)-$(DEJAVU_VERSION).$(DEJAVU_ARCHIVE_FORMAT)
DEJAVU_DOWNLOAD_URL ?= $(DEJAVU_BASEURL)/$(DEJAVU_VERSION)/$(DEJAVU_ARCHIVE)

FONTS_SRC_DIR ?= $(BUILDDIR)/fonts
DEJAVU_SRC_DIR ?= $(FONTS_SRC_DIR)/$(DEJAVU_ARCHIVE_BASE)-$(DEJAVU_VERSION)/ttf
FONTS_DST_DIR ?= vendor/tecnickcom/tcpdf/fonts
TCPDF_ADDFONT ?= $(ABSSRCDIR)/$(SCOPED_VENDOR_DIR)/tecnickcom/tcpdf/tools/tcpdf_addfont.php

dev-setup: build-fonts $(FONTS_LIST_FILE)

# rebuild some fonts which seemingly are shipped in a broken or too
# old version by tcpdf
#@private
build-fonts: stamp.tcpdf-fonts
.PHONY: build-fonts

#@private
stamp.tcpdf-fonts: stamp.tcpdf-dejavu-fonts
	date > $@

#@private
build-fonts-dejavu: stamp.tcpdf-dejavu-fonts
.PHONY: build-fonts-dejavu

#@private
stamp.tcpdf-dejavu-fonts: $(SCOPED_VENDOR_DIR)/autoload.php composer.lock $(MAKEFILE_DEP) $(DEJAVU_SRC_DIR)
	rm -f $(FONTS_DST_DIR)/dejavu*.php
	rm -f $(FONTS_DST_DIR)/dejavu*.z
	cd $(DEJAVU_SRC_DIR); $(PHP) $(TCPDF_ADDFONT) -b -t TrueTypeUnicode -f 32 -i DejaVuSans.ttf,DejaVuSans-Bold.ttf,DejaVuSansCondensed.ttf,DejaVuSansCondensed-Bold.ttf,DejaVuSans-ExtraLight.ttf,DejaVuSerif.ttf,DejaVuSerif-Bold.ttf,DejaVuSerifCondensed.ttf,DejaVuSerifCondensed-Bold.ttf
	cd $(DEJAVU_SRC_DIR); $(PHP) $(TCPDF_ADDFONT) -b -t TrueTypeUnicode -f 33 -i DejaVuSansMono.ttf,DejaVuSansMono-Bold.ttf
	cd $(DEJAVU_SRC_DIR); $(PHP) $(TCPDF_ADDFONT) -b -t TrueTypeUnicode -f 96 -i DejaVuSans-BoldOblique.ttf,DejaVuSansCondensed-BoldOblique.ttf,DejaVuSansCondensed-Oblique.ttf,DejaVuSerifCondensed-BoldItalic.ttf,DejaVuSerifCondensed-Italic.ttf,DejaVuSerif-Italic.ttf,DejaVuSerif-BoldItalic.ttf,DejaVuSans-Oblique.ttf
	cd $(DEJAVU_SRC_DIR); $(PHP) $(TCPDF_ADDFONT) -b -t TrueTypeUnicode -f 97 -i DejaVuSansMono-BoldOblique.ttf,DejaVuSansMono-Oblique.ttf
	date > $@

#@private
$(DEJAVU_SRC_DIR): $(DOWNLOADS_DIR)/$(DEJAVU_ARCHIVE)
	mkdir -p $(FONTS_SRC_DIR)
	tar -C $(FONTS_SRC_DIR) -x -f $(ABSSRCDIR)/$(DOWNLOADS_DIR)/$(DEJAVU_ARCHIVE)
	touch $@

#@private
$(DOWNLOADS_DIR)/$(DEJAVU_ARCHIVE):
	mkdir -p $(DOWNLOADS_DIR)
	cd $(DOWNLOADS_DIR); $(WGET) $(DEJAVU_DOWNLOAD_URL)

#@private
$(FONTS_LIST_FILE): stamp.tcpdf-fonts $(wildcard $(FONTS_DST_DIR)/*.php)
	mkdir -p $(dir $@)
	( echo '{';\
FONTS_LIST="$$(ls -C1 $(FONTS_DST_DIR)/*.php)";\
FONTS_COUNT=$$(echo -n "$$FONTS_LIST"|wc -l);\
INDEX=0;\
for FONT_FILE in $$( echo -n "$$FONTS_LIST"); do\
  MD5=$$(md5sum "$$FONT_FILE"|awk '{ print $$1; }');\
  BASENAME=$$(basename $$FONT_FILE .php);\
  echo -n "  \"$$BASENAME\": \"$$MD5\"";\
  if [ "$$INDEX" -lt "$$FONTS_COUNT" ]; then\
    echo ',';\
  else\
    echo;\
  fi;\
  INDEX=$$(( $$INDEX + 1 ));\
done;\
echo '}'; ) > $(FONTS_LIST_FILE)

# The archive additionally ships the toolkit configuration and the fonts list.
#@private
appstore-copy-fonts:
	mkdir -p $(APPSTORE_SIGN_DIR)/$(APP_NAME)/config
	$(RSYNC) -a -L $(APPSTORE_BLACKLISTED:%=--exclude '%') $(SCOPED_VENDOR_DIR)/rotdrop/nextcloud-app-toolkit/config/ $(FONTS_LIST_FILE) $(APPSTORE_SIGN_DIR)/$(APP_NAME)/config/
.PHONY: appstore-copy-fonts
