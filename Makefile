# This file is licensed under the Affero General Public License version 3 or
# later. See the COPYING file.
SRCDIR = .
ABSSRCDIR = $(CURDIR)
#
# try to parse the info.xml if we can, only then fall-back to the directory name
#
APP_INFO = $(SRCDIR)/appinfo/info.xml
XPATH = $(shell which xpath 2> /dev/null)
ifneq ($(XPATH),)
APP_NAME = $(shell $(XPATH) -q -e '/info/id/text()' $(APP_INFO))
else
$(warning The xpath binary could not be found, falling back to using the CWD as app-name)
APP_NAME = $(notdir $(CURDIR))
endif
BUILDDIR = ./build
ABSBUILDDIR = $(CURDIR)/build
BUILD_TOOLS_DIR = $(BUILDDIR)/tools
DOWNLOADS_DIR = ./downloads
CONFIG_DIR = ./config

FONTS_LIST_FILE = $(CONFIG_DIR)/fonts-list.json

SILENT = @

# make these overridable from the command line
RSYNC = $(shell which rsync 2> /dev/null)
PHP = $(shell which php 2> /dev/null)
NPM = $(shell which npm 2> /dev/null)
WGET = $(shell which wget 2> /dev/null)
OPENSSL = $(shell which openssl 2> /dev/null)

COMPOSER_SYSTEM = $(shell which composer 2> /dev/null)
ifeq (, $(COMPOSER_SYSTEM))
COMPOSER = $(PHP) $(BUILD_TOOLS_DIR)/composer.phar
else
COMPOSER = $(COMPOSER_SYSTEM)
endif
COMPOSER_OPTIONS = --prefer-dist

ifeq ($(PHP),)
$(error PHP binary is needed, but could not be found and was not specified on the command-line)
endif
ifeq ($(NPM),)
$(error NPM binary is needed, but could not be found and was not specified on the command-line)
endif
ifeq ($(COMPOSER),)
$(error COMPOSER binary is needed, but could not be found and was not specified on the command-line)
endif
ifeq ($(WGET),)
$(error WGET binary is needed, but could not be found and was not specified on the command-line)
endif

MAKE_HELP_DIR = $(SRCDIR)/dev-scripts/MakeHelp
include $(MAKE_HELP_DIR)/MakeHelp.mk

APPSTORE_BUILD_DIR = $(BUILDDIR)/artifacts/appstore
APPSTORE_COMPRESSION = z
APPSTORE_PACKAGE_FILE := $(APPSTORE_BUILD_DIR)/$(APP_NAME).tar
ifeq ($(APPSTORE_COMPRESSION),z)
  APPSTORE_PACKAGE_FILE := $(APPSTORE_PACKAGE_FILE).gz
else ifeq ($(APPSTORE_COMPRESSION),J)
  APPSTORE_PACKAGE_FILE := $(APPSTORE_PACKAGE_FILE).xz
endif
APPSTORE_SIGN_DIR = $(APPSTORE_BUILD_DIR)/sign
BUILD_CERT_DIR = $(BUILD_TOOLS_DIR)/certificates
CERT_DIR = $(HOME)/.nextcloud/certificates
OCC = $(CURDIR)/../../occ

#@@ The default rule.
all: help
.PHONY: all

#@@ Build the distribution assets (minified, without debugging info)
build: dev-setup npm-build lint test
.PHONY: build

#@@ Build the development assets (include debugging information)
dev: dev-setup npm-dev lint test
.PHONY: dev

#@private
dev-setup: composer build-fonts $(FONTS_LIST_FILE)
.PHONY: dev-setup

#@private
composer.json: composer.json.in
	cp composer.json.in composer.json

#@private
stamp.composer-core-versions: composer.lock
	date > $@

#@private
composer.lock: DRY:=
#@private
composer.lock: composer.json composer.json.in
	rm -f composer.lock
	$(COMPOSER) install $(COMPOSER_OPTIONS)
	env DRY=$(DRY) dev-scripts/tweak-composer-json.sh || {\
 rm -f composer.lock;\
 $(COMPOSER) install $(COMPOSER_OPTIONS);\
}

#@private
composer-download:
	mkdir -p $(BUILD_TOOLS_DIR)
	curl -sS https://getcomposer.org/installer | php
	mv composer.phar $(BUILD_TOOLS_DIR)
.PHONY: comoser-download

#@@ Installs and updates the composer dependencies. If composer is not installed
#@@ a copy is fetched from the web
composer: stamp.composer-core-versions
	$(COMPOSER) install $(COMPOSER_OPTIONS)
.PHONY: composer

#@@ Display the composer suggestions
composer-suggest:
	@echo -e "\n*** Regular Composer Suggestions ***\n"
	$(COMPOSER) suggest --all
.PHONY: composer-suggest

CSS_FILES = $(shell find $(ABSSRCDIR)/style -name "*.css" -o -name "*.scss")
JS_FILES = $(shell find $(ABSSRCDIR)/src -name "*.js" -o -name "*.vue")

NPM_INIT_DEPS =\
 Makefile package-lock.json package.json webpack.config.js .eslintrc.js

WEBPACK_DEPS =\
 $(NPM_INIT_DEPS)\
 $(CSS_FILES) $(JS_FILES)

WEBPACK_TARGETS = $(ABSSRCDIR)/js/asset-meta.json

#@private
package-lock.json: package.json webpack.config.js Makefile
	{ [ -d package-lock.json ] && [ test -d node_modules ]; } || $(NPM) install
	$(NPM) update
	touch package-lock.json

BUILD_FLAVOUR_FILE = $(ABSSRCDIR)/build-flavour
PREV_BUILD_FLAVOUR = $(shell cat $(BUILD_FLAVOUR_FILE) 2> /dev/null || echo)

#@private
build-flavour-dev:
ifneq ($(PREV_BUILD_FLAVOUR), dev)
	make clean
	echo dev > $(BUILD_FLAVOUR_FILE)
endif
.PHONY: build-flavour-dev

#@private
build-flavour-build:
ifneq ($(PREV_BUILD_FLAVOUR), build)
	make clean
	echo build > $(BUILD_FLAVOUR_FILE)
endif
.PHONY: build-flavour-build

#@private
$(WEBPACK_TARGETS): $(WEBPACK_DEPS) $(BUILD_FLAVOUR_FILE)
	make webpack-clean
	$(NPM) run $(shell cat $(BUILD_FLAVOUR_FILE)) || rm -f $(WEBPACK_TARGETS)
	$(NPM) run lint

#@private
npm-dev: build-flavour-dev $(WEBPACK_TARGETS)
.PHONY: npm-dev

#@private
npm-build: build-flavour-build $(WEBPACK_TARGETS)
.PHONY: npm-build

#@@ Linting
lint:
	$(NPM) run lint
.PHONY: lint

#@@ Lint and fix (be careful!)
lint-fix:
	$(NPM) run lint:fix
.PHONY: lint-fix

#@@ Style linting
stylelint:
	$(NPM) run stylelint
.PHONY: stylelint

#@@ Style linting and apply fixes (be carful!)
stylelint-fix:
	$(NPM) run stylelint:fix
.PHONY: stylelint-

#@@ Run phpcs on the PHP code
phpcs: composer
	vendor/bin/phpcs -s --report=emacs --standard=$(SRCDIR)/.phpcs.xml lib/ appinfo/ templates/

#@@ Run phpmd on the PHP code
phpmd: composer
	vendor/bin/phpmd lib/,appinfo/,templates/ text $(SRCDIR)/.phpmd.xml

###############################################################################
#
# START FONT STUFF

# rebuild some fonts which seemingly are shipped in a broken or too
# old version by tcpdf
#@private
build-fonts: stamp.tcpdf-fonts
.PHONY: build-fonts

stamp.tcpdf-fonts: stamp.tcpdf-dejavu-fonts
	date > $@

#@private
build-fonts-dejavu: stamp.tcpdf-dejavu-fonts
.PHONY: build-fonts-dejavu

DEJAVU_ARCHIVE_BASE = dejavu-fonts-ttf
DEJAVU_ARCHIVE_FORMAT = tar.bz2
DEJAVU_VERSION = 2.37
DEJAVU_BASEURL = http://sourceforge.net/projects/dejavu/files/dejavu/
DEJAVU_ARCHIVE = $(DEJAVU_ARCHIVE_BASE)-$(DEJAVU_VERSION).$(DEJAVU_ARCHIVE_FORMAT)
DEJAVU_DOWNLOAD_URL = $(DEJAVU_BASEURL)/$(DEJAVU_VERSION)/$(DEJAVU_ARCHIVE)

FONTS_SRC_DIR = $(BUILDDIR)/fonts
DEJAVU_SRC_DIR = $(FONTS_SRC_DIR)/$(DEJAVU_ARCHIVE_BASE)-$(DEJAVU_VERSION)/ttf
FONTS_DST_DIR = vendor/tecnickcom/tcpdf/fonts
TCPDF_ADDFONT = $(ABSSRCDIR)/vendor/tecnickcom/tcpdf/tools/tcpdf_addfont.php

#@private
stamp.tcpdf-dejavu-fonts: composer.lock Makefile $(DEJAVU_SRC_DIR)
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

# generate static MD5-checksums for the distributed fonts in order to
# invalidate cached font-sample respectively keep them as long as
# possible.

#@private
$(FONTS_LIST_FILE): $(wildcard $(FONTS_DEST_DIR)/*.php)
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

#echo '  "__comment": "END OF FONTS LIST"';

# END FONT STUFF
#
###############################################################################

# what has to be copied to the appstore archive
APPSTORE_FILES =\
 appinfo\
 css\
 js\
 img\
 l10n\
 templates\
 lib\
 vendor\
 config\
 contrib\
 CHANGELOG.md\
 COPYING\
 README.md

# .htaccess is blacklisted by the app-store installer, so we have to remove it
APPSTORE_BLACKLISTED = foobar .htaccess

#@private
appstore: COMPOSER_OPTIONS := $(COMPOSER_OPTIONS) --no-dev
#@@ Prepare appstore archive
appstore: clean dev-setup npm-build
	mkdir -p $(APPSTORE_SIGN_DIR)/$(APP_NAME)
	$(RSYNC) -a $(APPSTORE_BLACKLISTED:%=--exclude %) $(APPSTORE_FILES) $(APPSTORE_SIGN_DIR)/$(APP_NAME)
	mkdir -p $(BUILD_CERT_DIR)
	$(SILENT)if [ -n "$$APP_PRIVATE_KEY" ]; then\
  echo "$$APP_PRIVATE_KEY" > $(BUILD_CERT_DIR)/$(APP_NAME).key;\
elif [ -f "$(CERT_DIR)/$(APP_NAME).key" ]; then\
  cp $(CERT_DIR)/$(APP_NAME).key $(BUILD_CERT_DIR)/$(APP_NAME).key;\
fi
	$(SILENT)if [ -f $(BUILD_CERT_DIR)/$(APP_NAME).key ] && [ ! -f $(BUILD_CERT_DIR)/$(APP_NAME).crt ]; then\
  curl -L -o $(BUILD_CERT_DIR)/$(APP_NAME).crt\
 "https://github.com/nextcloud/app-certificate-requests/raw/master/$(APP_NAME)/$(APP_NAME).crt";\
  $(OPENSSL) x509 -in $(BUILD_CERT_DIR)/$(APP_NAME).crt -noout -text > /dev/null 2>&1 || rm -f $(BUILD_CERT_DIR)/$(APP_NAME).crt;\
fi
	$(SILENT)if [ -f $(BUILD_CERT_DIR)/$(APP_NAME).key ] && [ -f $(BUILD_CERT_DIR)/$(APP_NAME).crt ]; then\
  echo "Signing app files ...";\
  $(PHP) $(OCC) integrity:sign-app\
 --privateKey=$(ABSSRCDIR)/$(BUILD_CERT_DIR)/$(APP_NAME).key\
 --certificate=$(ABSSRCDIR)/$(BUILD_CERT_DIR)/$(APP_NAME).crt\
 --path=$(ABSSRCDIR)/$(APPSTORE_SIGN_DIR)/$(APP_NAME);\
  echo "... signing app files done";\
else\
  echo 'Cannot sign app-files, certificate "$(BUILD_CERT_DIR)/$(APP_NAME).crt" or private key "$(BUILD_CERT_DIR)/$(APP_NAME).key" not available.' 1>&2;\
fi
	tar -c$(APPSTORE_COMPRESSION)f $(APPSTORE_PACKAGE_FILE) -C $(APPSTORE_SIGN_DIR) $(APP_NAME)
	$(SILENT)if [ -f $(BUILD_CERT_DIR)/$(APP_NAME).key ] && [ -f $(BUILD_CERT_DIR)/$(APP_NAME).crt ]; then\
  echo "Signing package ...";\
  $(OPENSSL) dgst -sha512 -sign $(CERT_DIR)/$(APP_NAME).key $(APPSTORE_PACKAGE_FILE) | openssl base64; \
else\
  echo 'Cannot sign app-store package, certificate "$(BUILD_CERT_DIR)/$(APP_NAME).crt" or private key "$(BUILD_CERT_DIR)/$(APP_NAME).key" not available.' 1>&2;\
fi

.PHONY: appstore

#@@ Removes WebPack builds
webpack-clean:
	rm -rf ./js/*
	rm -rf ./css/*
.PHONY: webpack-clean

#@@ Removes build files
clean: ## Tidy up local environment
	rm -rf $(BUILDDIR)
.PHONY: clean

#@@ Same as clean but also removes dependencies installed by composer, bower and npm
distclean: clean ## Clean even more, calls clean
	rm -rf vendor*
	rm -rf node_modules
.PHONY: distclean

#@@ Almost everything but downloads
mostlyclean: webpack-clean distclean
	rm -f composer*.lock
	rm -f composer.json
	rm -f stamp.composer-core-versions
	rm -f package-lock.json
	rm -f *.html
	rm -f stats.json
	rm -f $(FONTS_LIST_FILE)

#@@ Really delete everything but the bare source files
realclean: mostlyclean downloadsclean
.PHONY: realclean

#@@ Remove non-npm non-composer downloads
downloadsclean:
	rm -rf $(DOWNLOADS_DIR)
.PHONY: downloadsclean

#@@ Run the test-suite
test: unit-tests integration-tests
.PHONY: test

#@@ Run the unit tests
unit-tests:
	./vendor/phpunit/phpunit/phpunit -c phpunit.xml
.PHONY: unit-tests

#@@ Run the integration tests
integration-tests:
	./vendor/phpunit/phpunit/phpunit -c phpunit.integration.xml
.PHONY: integration-tests
