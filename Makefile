# This file is licensed under the Affero General Public License version 3 or
# later. See the COPYING file.
#
# Thin wrapper around the shared Makefile of the build-system submodule,
# only the app-specific settings live here. The TCPDF font pipeline is in
# Makefile.app.

SRCDIR = .
ABSSRCDIR = $(CURDIR)
DEV_LIB_DIR = $(ABSSRCDIR)/dev-scripts/lib

FONTS_LIST_FILE = ./config/fonts-list.json

APP_BUILD_FLAVOUR_FIRST = 1
APP_NOTIFICATION_API = 1
APP_REQUIRED_TOOLS = WGET
APP_PHP_DIRS = lib appinfo templates
APP_MOSTLYCLEAN_FILES = stamp.tcpdf-fonts stamp.tcpdf-dejavu-fonts $(FONTS_LIST_FILE)
APPSTORE_FILES_EXTRA = contrib
APPSTORE_COPY_HOOK = appstore-copy-fonts
MAKEFILE_DEP = Makefile Makefile.app

include $(DEV_LIB_DIR)/makefile/app.mk

ifneq ($(strip $(MAKECMDGOALS)),release)
include $(ABSSRCDIR)/Makefile.app
endif
