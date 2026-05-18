#!/usr/bin/make
# SHELL = bash
PACKAGE_NAME = grasp
INSTALL_DIR = grasp
PATH_PROJECT = $(DESTDIR)/opt/$(INSTALL_DIR)
PATH_PUBLIC = $(PATH_PROJECT)/public
DEVELOPER_EMAIL = "karel.wintersky@yandex.ru"
DEVELOPER_NAME = "Karel Wintersky"

.PHONY: help install update build setup_env sync_db dchv dchr clear_smarty_cache clear_nginx_cache clear_redis_cache

help:
	@perl -e '$(HELP_ACTION)' $(MAKEFILE_LIST)

install: 	##@system Install package. Don't run it manually!!!
	@echo Installing...
	install -d $(PATH_PROJECT)
	cp -r app $(PATH_PROJECT)
	cp -r public $(PATH_PROJECT)
	cp cron.php $(PATH_PROJECT)
	cp _setup.php $(PATH_PROJECT)
	cp composer.json $(PATH_PROJECT)
	cp README.md $(PATH_PROJECT)
	git rev-parse --short HEAD > $(PATH_PROJECT)/_version
	git log --oneline --format=%B -n 1 HEAD | head -n 1 >> $(PATH_PROJECT)/_version
	git log --oneline --format="%at" -n 1 HEAD | xargs -I{} date -d @{} +%Y-%m-%d >> $(PATH_PROJECT)/_version
	set -e && cd $(PATH_PROJECT)/ && composer install
	install -d $(PATH_PROJECT)/logs

cron:		##@cron Run cron task
	@php ./cron.php --verbose

update:		##@build Update project from GIT
	@echo Updating project from GIT
	git pull --no-rebase

build:		##@build Build project to DEB Package
	@echo Building project to DEB-package
	@dh_clean
#	@dh_clean ./public/
#	@./node_modules/.bin/gulp build --production
	export COMPOSER_HOME=/tmp/ && dpkg-buildpackage -rfakeroot --no-sign -uc -us --compression-level=9 --diff-ignore=node_modules --tar-ignore=node_modules
	@dh_clean

#build_dev:		##@build Build DEV DEB-package
#	@echo Building project
#	dh_clean
#	@./node_modules/.bin/gulp build
#	dpkg-buildpackage -rfakeroot -uc -us --compression-level=9 --diff-ignore=node_modules
#	dh_clean

#compile:		##@work Compile dev version
#	@echo Compiling
#	@dh_clean ./public/
#	@./node_modules/.bin/gulp build

#compile_prod:		##@work Compile public version
#	@echo Compiling
#	@dh_clean ./public/
#	@./node_modules/.bin/gulp build --production

#watch:	compile	##@watch Gulp-Watch project
#	./node_modules/.bin/gulp watch


setup_env:	##@localhost Setup environment at localhost
	@echo Setting up local environment
	@mkdir -p $(PATH_PROJECT)/cache
	@mkdir -p $(PATH_PROJECT)/logs
	@mkdir -p $(PATH_PROJECT)/logs/raw_input

dchr:		##@development Publish release
	@dch --controlmaint --release --distribution unstable

dchv:		##@development Append release
	@export DEBEMAIL="$(DEVELOPER_EMAIL)" && \
	export DEBFULLNAME="$(DEVELOPER_NAME)" && \
	echo "$(YELLOW)------------------ Previous version header: ------------------$(GREEN)" && \
	head -n 3 debian/changelog && \
	echo "$(YELLOW)--------------------------------------------------------------$(RESET)" && \
	read -p "Next version: " VERSION && \
	dch --controlmaint -v $$VERSION

dchn:		##@development Initial create changelog file
	@export DEBEMAIL="$(DEVELOPER_EMAIL)" && \
	export DEBFULLNAME="$(DEVELOPER_NAME)" && \
	dch --create --package $(PACKAGE_NAME)

# ------------------------------------------------
# Add the following 'help' target to your makefile, add help text after each target name starting with '\#\#'
# A category can be added with @category
GREEN  := $(shell tput -Txterm setaf 2)
YELLOW := $(shell tput -Txterm setaf 3)
WHITE  := $(shell tput -Txterm setaf 7)
RESET  := $(shell tput -Txterm sgr0)
HELP_ACTION = \
	%help; while(<>) { push @{$$help{$$2 // 'options'}}, [$$1, $$3] if /^([a-zA-Z\-_]+)\s*:.*\#\#(?:@([a-zA-Z\-]+))?\s(.*)$$/ }; \
	print "usage: make [target]\n\n"; for (sort keys %help) { print "${WHITE}$$_:${RESET}\n"; \
	for (@{$$help{$$_}}) { $$sep = " " x (32 - length $$_->[0]); print "  ${YELLOW}$$_->[0]${RESET}$$sep${GREEN}$$_->[1]${RESET}\n"; }; \
	print "\n"; }

# -eof-

