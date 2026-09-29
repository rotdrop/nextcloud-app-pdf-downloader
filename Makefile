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
APP_VERSION = $(shell $(XPATH) -q -e '/info/version/text()' $(APP_INFO))
APP_NAMESPACE = $(shell $(XPATH) -q -e '/info/namespace/text()' $(APP_INFO))
SCOPED_NAMESPACE_POSTFIX = $(shell $(XPATH) -q -e '/info/scopednamespace/text()' $(APP_INFO))
else
$(error The xpath binary could not be found)
endif

DEV_LIB_DIR = $(ABSSRCDIR)/dev-scripts/lib
BUILDDIR = ./build
ABSBUILDDIR = $(ABSSRCDIR)/build
BUILD_TOOLS_DIR = $(BUILDDIR)/tools
DOWNLOADS_DIR = ./downloads
CONFIG_DIR = ./config
TYPESCRIPT_CONVERTER = $(ABSSRCDIR)/dev-scripts/php-to-typescript.php
TS_TYPES_DIR = $(ABSBUILDDIR)/ts-types
TS_PHP_SOURCE_DIRS = lib

include $(DEV_LIB_DIR)/makefile/setup.mk

FONTS_LIST_FILE = $(CONFIG_DIR)/fonts-list.json

SILENT = @

# make these overridable from the command line
RSYNC = $(shell which rsync 2> /dev/null)
PHP = $(shell which php 2> /dev/null)
NPM = $(shell which npm 2> /dev/null)
BUNDLER_CONFIG = vite.config.ts
WGET = $(shell which wget 2> /dev/null)
OPENSSL = $(shell which openssl 2> /dev/null)
PHPUNIT = $(ABSSRCDIR)/vendor-bin/phpunit/vendor/bin/phpunit
PHP_SCOPER = $(ABSSRCDIR)/vendor-bin/php-scoper/vendor/bin/php-scoper

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
APPSTORE_PACKAGE_FILE := $(APPSTORE_BUILD_DIR)/$(APP_NAME)-$(APP_VERSION).tar
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
build: dev-setup npm-build
.PHONY: build

#@@ Build the development assets (include debugging information)
dev: dev-setup npm-dev
.PHONY: dev

#@private
dev-setup: app-toolkit composer build-fonts $(FONTS_LIST_FILE) ts-app-config
.PHONY: dev-setup

include $(DEV_LIB_DIR)/makefile/composer.mk

#@private
php-scoper-install: $(PHP_SCOPER)
.PHONY: php-scoper-install

$(PHP_SCOPER): composer.lock
	if ! [ -x "$@" ]; then $(COMPOSER) bin php-scoper install; else touch "$@"; fi

composer-scoped.lock: composer-scoped.json Makefile
	rm -f composer-scoped.lock

$(BUILDDIR)/vendor-scoped: composer-scoped.lock
	mkdir -p $(BUILDDIR)
	ln -fs ../vendor $(BUILDDIR)
	rm -rf $(BUILDDIR)/vendor-scoped
	ln -sf ../composer-patches $(BUILDDIR)
	env COMPOSER="$(ABSSRCDIR)/composer-scoped.json" $(COMPOSER) -d$(BUILDDIR) install $(COMPOSER_OPTIONS)
	env COMPOSER="$(ABSSRCDIR)/composer-scoped.json" $(COMPOSER) -d$(BUILDDIR) update $(COMPOSER_OPTIONS) --no-dev

$(BUILDDIR)/vendor-scoped/autoload.php: $(BUILDDIR)/vendor-scoped composer-scoped.json $(MAKEFILE_DEP)
	env COMPOSER="$(ABSSRCDIR)/composer-scoped.json" $(COMPOSER) -d$(BUILDDIR) dump-autoload

vendor-scoped: $(MAKEFILE_DEP) $(PHP_SCOPER) scoper.inc.php $(BUILDDIR)/vendor-scoped
	$(PHP_SCOPER) add-prefix -d$(BUILDDIR) --config=$(ABSSRCDIR)/scoper.inc.php --output-dir=$(ABSSRCDIR)/vendor-scoped --force
# scoper does not handle symlinks
#	cp -a $(BUILDDIR)/vendor-scoped/bin $(ABSSRCDIR)/vendor-scoped/
# scoper does not preserve executable bits
#	find $(ABSSRCDIR)/vendor-scoped -name bin -a -type d -exec chmod -R gu+x {} \;

vendor-scoped/autoload.php: vendor-scoped
	env COMPOSER="$(ABSSRCDIR)/composer-scoped.json" $(COMPOSER) dump-autoload
	cd vendor-scoped; sed -e 's/@FILE_IDENTIFIER_PREFIX@/OCA_$(APP_NAMESPACE)_$(SCOPED_NAMESPACE_POSTFIX)/g' $(ABSSRCDIR)/composer-patches/composer/scoped_autoload_real.patch|patch -p1

namespace-wrapper: php-scoper-install vendor-scoped/autoload.php
.PHONY: namespace-wrapper

APP_TOOLKIT_DIR = $(ABSSRCDIR)/php-toolkit
APP_TOOLKIT_DEST = $(ABSSRCDIR)/lib/Toolkit
APP_TOOLKIT_NS = PdfDownloader
APP_WRAPPER_NS = $(SCOPED_NAMESPACE_POSTFIX)

include $(APP_TOOLKIT_DIR)/tools/scopeme.mk
include $(DEV_LIB_DIR)/makefile/ts-app-config.mk
include $(DEV_LIB_DIR)/makefile/ts-notification-api.mk
include $(DEV_LIB_DIR)/makefile/ts-types-files.mk

L10N_FILES = $(wildcard l10n/*.js l10n/*.json)
JS_FILES = $(shell find $(ABSSRCDIR)/src -name "*.js" -o -name "*.vue" -o -name "*.ts")\
  $(shell find $(ABSSRCDIR)/repositories/nextcloud-vue-components -name "*.js" -o -name "*.vue" -o -name "*.ts")
IMG_FILES = $(shell find $(ABSSRCDIR)/img -name "*.svg")

NPM_INIT_DEPS =\
 Makefile package-lock.json package.json $(BUNDLER_CONFIG) .eslintrc.js

WEBPACK_DEPS =\
 $(NPM_INIT_DEPS)\
 $(JS_FILES)\
 $(IMG_FILES)\
 $(L10N_FILES)\
 $(TS_APP_CONFIG)\
 ts-types-files\
 $(TS_NOTIFICATION_API)

include $(DEV_LIB_DIR)/makefile/npm.mk

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
 vendor-scoped\
 config\
 contrib\
 CHANGELOG.md\
 COPYING\
 README.md

# .htaccess is blacklisted by the app-store installer, so we have to remove it
APPSTORE_BLACKLISTED = foobar .htaccess .git* .*keep *~

#@@ Prepare appstore archive
appstore: clean dev-setup npm-build
	$(COMPOSER) update --no-dev
	mkdir -p $(APPSTORE_SIGN_DIR)/$(APP_NAME)
	$(RSYNC) -a -L $(APPSTORE_BLACKLISTED:%=--exclude '%') $(APPSTORE_FILES) $(APPSTORE_SIGN_DIR)/$(APP_NAME)
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

#@@ Removes build files
clean: ## Tidy up local environment
	rm -rf $(BUILDDIR)
.PHONY: clean

#@@ Same as clean but also removes dependencies installed by composer, bower and npm
distclean: clean ## Clean even more, calls clean
	rm -rf vendor
	rm -rf vendor-bin/**/vendor
	find . -name node_modules -exec rm -rf {} \; 2> /dev/null || true
	rm -rf lib/Toolkit/*
.PHONY: distclean

#@@ Almost everything but downloads
mostlyclean: webpack-clean distclean
	rm -f composer*.lock
	rm -f composer.json
	rm -f vendor-bin/**/composer.lock
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
	$(PHPUNIT) -c phpunit.xml
.PHONY: unit-tests

#@@ Run the integration tests
integration-tests:
	$(PHPUNIT) -c phpunit.integration.xml
.PHONY: integration-tests

#@private
run-tide:
	$(EMACS) --batch --file $(SRCDIR)/src/vue-app.ts  -l $(DEV_LIB_DIR)/scripts/tide-project-errors.el|tee tide-errors.log
.PHONY: run-tide

#@@ Runs the Emacs Tide IDE in batch mode and diagnoses TypeScript errors.
tide: dev-setup ts-app-config ts-types-files run-tide
.PHONY: tide
