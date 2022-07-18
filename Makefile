# This file is licensed under the Affero General Public License version 3 or
# later. See the COPYING file.
app_name=$(notdir $(CURDIR))
SRCDIR=.
ABSSRCDIR=$(CURDIR)
BUILDDIR=./build
ABSBUILDDIR=$(CURDIR)/build
build_tools_directory=$(BUILDDIR)/tools
COMPOSER_SYSTEM=$(shell which composer 2> /dev/null)
ifeq (, $(COMPOSER_SYSTEM))
COMPOSER_TOOL=php $(build_tools_directory)/composer.phar
else
COMPOSER_TOOL=$(COMPOSER_SYSTEM)
endif
COMPOSER_OPTIONS=--prefer-dist
PHP = $(shell which php 2> /dev/null)

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
	$(COMPOSER_TOOL) install $(COMPOSER_OPTIONS)
	env DRY=$(DRY) dev-scripts/tweak-composer-json.sh || {\
 rm -f composer.lock;\
 $(COMPOSER_TOOL) install $(COMPOSER_OPTIONS);\
}

.PHONY: comoser-download
composer-download:
	mkdir -p $(build_tools_directory)
	curl -sS https://getcomposer.org/installer | php
	mv composer.phar $(build_tools_directory)

# Installs and updates the composer dependencies. If composer is not installed
# a copy is fetched from the web
.PHONY: composer
composer: stamp.composer-core-versions
	$(COMPOSER_TOOL) install $(COMPOSER_OPTIONS)



.PHONY: composer-suggest
composer-suggest:
	@echo -e "\n*** Regular Composer Suggestions ***\n"
	$(COMPOSER_TOOL) suggest --all

CSS_FILES = $(shell find $(ABSSRCDIR)/style -name "*.css" -o -name "*.scss")
JS_FILES = $(shell find $(ABSSRCDIR)/src -name "*.js" -o -name "*.vue")

NPM_INIT_DEPS =\
 Makefile package-lock.json package.json webpack.config.js .eslintrc.js

WEBPACK_DEPS =\
 $(NPM_INIT_DEPS)\
 $(CSS_FILES) $(JS_FILES)

WEBPACK_TARGETS = $(ABSSRCDIR)/js/asset-meta.json

package-lock.json: package.json webpack.config.js Makefile
	{ [ -d package-lock.json ] && [ test -d node_modules ]; } || npm install
	npm update
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
	npm run $(shell cat $(BUILD_FLAVOUR_FILE)) || rm -f $(WEBPACK_TARGETS)
	npm run lint

.PHONY: npm-dev
npm-dev: build-flavour-dev $(WEBPACK_TARGETS)

.PHONY: npm-build
npm-build: build-flavour-build $(WEBPACK_TARGETS)

# Linting
lint:
	npm run lint

lint-fix:
	npm run lint:fix

# Style linting
stylelint:
	npm run stylelint

stylelint-fix:
	npm run stylelint:fix

# rebuild some fonts which seemingly are shipped in a broken or too
# old version by tcpdf
build-fonts: build-fonts-dejavu
.PHONY: build-fonts

build-fonts-dejavu: stamp.tcpdf-dejavu-fonts
.PHONY: build-fonts-dejavu

FONTS_SRC_DIR = fonts
FONTS_DST_DIR = vendor/tecnickcom/tcpdf/fonts
TCPDF_ADDFONT = $(ABSSRCDIR)/vendor/tecnickcom/tcpdf/tools/tcpdf_addfont.php

stamp.tcpdf-dejavu-fonts: composer.lock Makefile
	rm -f $(FONTS_DST_DIR)/dejavu*.php
	rm -f $(FONTS_DST_DIR)/dejavu*.z
	cd $(FONTS_SRC_DIR); $(PHP) $(TCPDF_ADDFONT) -b -t TrueTypeUnicode -f 32 -i DejaVuSans.ttf,DejaVuSans-Bold.ttf,DejaVuSansCondensed.ttf,DejaVuSansCondensed-Bold.ttf,DejaVuSans-ExtraLight.ttf,DejaVuSerif.ttf,DejaVuSerif-Bold.ttf,DejaVuSerifCondensed.ttf,DejaVuSerifCondensed-Bold.ttf
	cd $(FONTS_SRC_DIR); $(PHP) $(TCPDF_ADDFONT) -b -t TrueTypeUnicode -f 33 -i DejaVuSansMono.ttf,DejaVuSansMono-Bold.ttf
	cd $(FONTS_SRC_DIR); $(PHP) $(TCPDF_ADDFONT) -b -t TrueTypeUnicode -f 96 -i DejaVuSans-BoldOblique.ttf,DejaVuSansCondensed-BoldOblique.ttf,DejaVuSansCondensed-Oblique.ttf,DejaVuSerifCondensed-BoldItalic.ttf,DejaVuSerifCondensed-Italic.ttf,DejaVuSerif-Italic.ttf,DejaVuSerif-BoldItalic.ttf,DejaVuSans-Oblique.ttf
	cd $(FONTS_SRC_DIR); $(PHP) $(TCPDF_ADDFONT) -b -t TrueTypeUnicode -f 97 -i DejaVuSansMono-BoldOblique.ttf,DejaVuSansMono-Oblique.ttf
	date > $@

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

#@@ Really delete everything but the bare source files
realclean: webpack-clean distclean
	rm -f composer*.lock
	rm -f composer.json
	rm -f stamp.composer-core-versions
	rm -f package-lock.json
	rm -f *.html
	rm -f stats.json
.PHONY: realclean

clean-dev:
	rm -rf node_modules

# Tests
test:
	./vendor/phpunit/phpunit/phpunit -c phpunit.xml
	./vendor/phpunit/phpunit/phpunit -c phpunit.integration.xml
