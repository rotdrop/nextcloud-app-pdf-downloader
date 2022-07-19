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
BUILD_TOOLS_DIRECTORY = $(BUILDDIR)/tools
DOWNLOADS_DIR = ./downloads

# make these overridable from the command line
PHP = $(shell which php 2> /dev/null)
NPM = $(shell which npm 2> /dev/null)
WGET = $(shell which wget 2> /dev/null)

COMPOSER_SYSTEM = $(shell which composer 2> /dev/null)
ifeq (, $(COMPOSER_SYSTEM))
COMPOSER = $(PHP) $(BUILD_TOOLS_DIRECTORY)/composer.phar
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


APPSTORE_BUILD_DIR = $(BUILDDIR)/artifacts/appstore
APPSTORE_PACKAGE_DIR = $(APPSTORE_BUILD_DIR)/$(APP_NAME)x
APPSTORE_SIGN_DIR = $(APPSTORE_BUILD_DIR)/sign

all: build lint test
.PHONY: all

build: dev-setup npm-build
.PHONY: build

dev: dev-setup npm-dev
.PHONY: dev

# Dev env management
dev-setup: composer build-fonts
.PHONY: dev-setup

composer.json: composer.json.in
	cp composer.json.in composer.json

stamp.composer-core-versions: composer.lock
	date > $@

composer.lock: DRY:=
composer.lock: composer.json composer.json.in
	rm -f composer.lock
	$(COMPOSER) install $(COMPOSER_OPTIONS)
	env DRY=$(DRY) dev-scripts/tweak-composer-json.sh || {\
 rm -f composer.lock;\
 $(COMPOSER) install $(COMPOSER_OPTIONS);\
}

.PHONY: comoser-download
composer-download:
	mkdir -p $(BUILD_TOOLS_DIRECTORY)
	curl -sS https://getcomposer.org/installer | php
	mv composer.phar $(BUILD_TOOLS_DIRECTORY)

# Installs and updates the composer dependencies. If composer is not installed
# a copy is fetched from the web
.PHONY: composer
composer: stamp.composer-core-versions
	$(COMPOSER) install $(COMPOSER_OPTIONS)

.PHONY: composer-suggest
composer-suggest:
	@echo -e "\n*** Regular Composer Suggestions ***\n"
	$(COMPOSER) suggest --all

CSS_FILES = $(shell find $(ABSSRCDIR)/style -name "*.css" -o -name "*.scss")
JS_FILES = $(shell find $(ABSSRCDIR)/src -name "*.js" -o -name "*.vue")

NPM_INIT_DEPS =\
 Makefile package-lock.json package.json webpack.config.js .eslintrc.js

WEBPACK_DEPS =\
 $(NPM_INIT_DEPS)\
 $(CSS_FILES) $(JS_FILES)

WEBPACK_TARGETS = $(ABSSRCDIR)/js/asset-meta.json

package-lock.json: package.json webpack.config.js Makefile
	{ [ -d package-lock.json ] && [ test -d node_modules ]; } || $(NPM) install
	$(NPM) update
	touch package-lock.json

BUILD_FLAVOUR_FILE = $(ABSSRCDIR)/build-flavour
PREV_BUILD_FLAVOUR = $(shell cat $(BUILD_FLAVOUR_FILE) 2> /dev/null || echo)

.PHONY: build-flavour-dev
build-flavour-dev:
ifneq ($(PREV_BUILD_FLAVOUR), dev)
	make clean
	echo dev > $(BUILD_FLAVOUR_FILE)
endif

.PHONY: build-flavour-build
build-flavour-build:
ifneq ($(PREV_BUILD_FLAVOUR), build)
	make clean
	echo build > $(BUILD_FLAVOUR_FILE)
endif

$(WEBPACK_TARGETS): $(WEBPACK_DEPS) $(BUILD_FLAVOUR_FILE)
	make webpack-clean
	$(NPM) run $(shell cat $(BUILD_FLAVOUR_FILE)) || rm -f $(WEBPACK_TARGETS)
	$(NPM) run lint

.PHONY: npm-dev
npm-dev: build-flavour-dev $(WEBPACK_TARGETS)

.PHONY: npm-build
npm-build: build-flavour-build $(WEBPACK_TARGETS)

# Linting
lint:
	$(NPM) run lint

lint-fix:
	$(NPM) run lint:fix

# Style linting
stylelint:
	$(NPM) run stylelint

stylelint-fix:
	$(NPM) run stylelint:fix

# rebuild some fonts which seemingly are shipped in a broken or too
# old version by tcpdf
build-fonts: build-fonts-dejavu
.PHONY: build-fonts

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

stamp.tcpdf-dejavu-fonts: composer.lock Makefile $(DEJAVU_SRC_DIR)
	rm -f $(FONTS_DST_DIR)/dejavu*.php
	rm -f $(FONTS_DST_DIR)/dejavu*.z
	cd $(DEJAVU_SRC_DIR); $(PHP) $(TCPDF_ADDFONT) -b -t TrueTypeUnicode -f 32 -i DejaVuSans.ttf,DejaVuSans-Bold.ttf,DejaVuSansCondensed.ttf,DejaVuSansCondensed-Bold.ttf,DejaVuSans-ExtraLight.ttf,DejaVuSerif.ttf,DejaVuSerif-Bold.ttf,DejaVuSerifCondensed.ttf,DejaVuSerifCondensed-Bold.ttf
	cd $(DEJAVU_SRC_DIR); $(PHP) $(TCPDF_ADDFONT) -b -t TrueTypeUnicode -f 33 -i DejaVuSansMono.ttf,DejaVuSansMono-Bold.ttf
	cd $(DEJAVU_SRC_DIR); $(PHP) $(TCPDF_ADDFONT) -b -t TrueTypeUnicode -f 96 -i DejaVuSans-BoldOblique.ttf,DejaVuSansCondensed-BoldOblique.ttf,DejaVuSansCondensed-Oblique.ttf,DejaVuSerifCondensed-BoldItalic.ttf,DejaVuSerifCondensed-Italic.ttf,DejaVuSerif-Italic.ttf,DejaVuSerif-BoldItalic.ttf,DejaVuSans-Oblique.ttf
	cd $(DEJAVU_SRC_DIR); $(PHP) $(TCPDF_ADDFONT) -b -t TrueTypeUnicode -f 97 -i DejaVuSansMono-BoldOblique.ttf,DejaVuSansMono-Oblique.ttf
	date > $@

$(DEJAVU_SRC_DIR): $(DOWNLOADS_DIR)/$(DEJAVU_ARCHIVE)
	mkdir -p $(FONTS_SRC_DIR)
	tar -C $(FONTS_SRC_DIR) -x -f $(ABSSRCDIR)/$(DOWNLOADS_DIR)/$(DEJAVU_ARCHIVE)
	touch $@

$(DOWNLOADS_DIR)/$(DEJAVU_ARCHIVE):
	mkdir -p $(DOWNLOADS_DIR)
	cd $(DOWNLOADS_DIR); $(WGET) $(DEJAVU_DOWNLOAD_URL)

#@@ prepare appstore archive
appstore: COMPOSER_OPTIONS := $(COMPOSER_OPTIONS) --no-dev
appstore: clean dev-setup npm-build
	mkdir -p $(APPSTORE_SIGN_DIR)/$(APP_NAME)

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

#@@ Really delete everything but the bare source files
realclean: mostlyclean downloadsclean
.PHONY: realclean

#@@ Remove non-npm non-composer downloads
downloadsclean:
	rm -rf $(DOWNLOADS_DIR)

clean-dev:
	rm -rf node_modules

# Tests
test:
	./vendor/phpunit/phpunit/phpunit -c phpunit.xml
	./vendor/phpunit/phpunit/phpunit -c phpunit.integration.xml
