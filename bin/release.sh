#!/bin/sh
set -e
set -x
app=eventum
dir=$app
podir=po

find_prog() {
	set +x
	local c prog=$1
	names="./$prog.phar $prog.phar $prog"
	prog=
	for c in $names; do
		prog=$(which $c) || continue
		prog=$(readlink -f "$prog")
		break
	done

	${prog:-false} --version >&2

	echo ${prog:-false}
}

# update timestamps from last commit
# see http://stackoverflow.com/a/5531813
update_timestamps() {
	set +x
	echo "Updating timestamps from last commit of each file, please wait..."
	git ls-files | while read file; do
		# skip files which were not exported
		test -f "$dir/$file" || continue
		rev=$(git rev-list -n 1 HEAD "$file")
		file_time=$(git show --pretty=format:%ai --abbrev-commit $rev | head -n 1)
		touch -d "$file_time" "$dir/$file"
	done
}

vcs_checkout() {
	rm -rf $dir
	install -d $dir
	dir=$(readlink -f $dir)

	# setup submodules
	git submodule init
	git submodule update

	git archive HEAD | tar -x -C $dir
	# include submodules
	# see http://stackoverflow.com/a/16843717
	dir=$dir git submodule foreach 'cd $toplevel/$path && git archive HEAD | tar -x -C $dir/$path/'

	update_timestamps
	po_checkout
}

# checkout localizations from launchpad
po_checkout() {
	if [ -d $podir ]; then
	  cd $podir
	  bzr pull
	  cd -
	else
	  bzr branch lp:~glen666/eventum/po $podir
	fi
	rm -f $dir/localization/*.po
	cp -af $podir/localization/*.po $dir/localization
}

# setup $version and update APP_VERSION in init.php
update_version() {
	version=$(awk -F"'" '/APP_VERSION/{print $4}' init.php)

	version=$(git describe --tags)
	# not good tags, try trimming
	version=$(echo "$version" | sed -e 's,release-,,; s/-final$//; s/^v//; s/-pre[0-9]*//; ')

	sed -i -e "
		/define('APP_VERSION'/ {
			idefine('APP_VERSION', '$version');
		    d

		}" init.php
}

# setup composer deps
composer_install() {
	$composer install --prefer-dist --no-dev --ignore-platform-reqs
	$composer licenses --no-dev --no-ansi > deps
	# avoid composer warning in resulting doc file
	grep Warning: deps && exit 1
	cat deps >> docs/DEPENDENCIES.md && rm deps
}

# create phpcompatinfo report
phpcompatinfo_report() {
	$phpcompatinfo analyser:run --alias current --output docs/PhpCompatInfo.txt
}

# common cleanups:
# - remove closing php tag
# - strip trailing whitespace
# - use unix newlines
clean_scripts() {
	# here's shell oneliner to remove ?> from all files which have it on their last line:
	find -name '*.php' | xargs -r sed -i -e '${/^?>$/d}'
	# sometimes if you are hit by this problem, you need to kill last empty line first:
	find -name '*.php' | xargs -r sed -i -e '${/^$/d}'
	# and as well can remove trailing spaces/tabs:
	find -name '*.php' | xargs -r sed -i -e 's/[\t ]\+$//'
	# remove DOS EOL
	find -name '*.php' | xargs -r sed -i -e 's,\r$,,'
}

# remove bundled deps
cleanup_dist() {
	local dir

	# not ready yet
	rm lib/eventum/db/DbYii.php

	# cleanup vendors
	rm -r vendor/bin
	rm vendor/composer/*.json
	rm vendor/*/*/composer.json
	rm vendor/*/*/.gitattributes
	rm vendor/*/*/.gitignore
	rm vendor/*/*/LICENSE*
	rm vendor/*/*/COPYING
	rm vendor/*/*/ChangeLog*
	rm vendor/*/*/README*
	rm vendor/*/*/.travis.yml

	# php-gettext
	rm -r vendor/php-gettext/php-gettext/{tests,examples}
	rm -f vendor/php-gettext/php-gettext/[A-Z]*

	rm vendor/smarty-gettext/smarty-gettext/tsmarty2c.1

	# smarty: use -f, as dist and src packages differ
	# smarty src
	rm -rf vendor/smarty/smarty/{.svn,development,documentation,distribution/demo}
	rm -f vendor/smarty/smarty/distribution/{[A-Z]*,*.{txt,json}}
	# smarty dist
	rm -rf vendor/smarty/smarty/demo
	rm -f vendor/smarty/smarty/{[A-Z]*,*.txt}

	cd vendor
	clean_scripts
	cd ..

	# pear
	rm vendor/pear*/*/package.xml
	rm -r vendor/pear*/*/tests
	rm -r vendor/pear*/*/doc
	rm -r vendor/pear*/*/docs
	rm -r vendor/pear*/*/examples
	rm -r vendor/pear-pear.php.net/Console_Getopt
	rm -r vendor/pear-pear.php.net/Math_Stats/{data,contrib}
	rm vendor/pear-pear.php.net/XML_RPC/XML/RPC/Dump.php
	rm vendor/pear/pear-core-minimal/src/OS/Guess.php
	rm vendor/pear/net_smtp/phpdoc.sh

	mv vendor/pear/db/DB/{common,mysql*}.php vendor
	rm -r vendor/pear/db/DB/*.php
	mv vendor/*.php vendor/pear/db/DB

	# we need just LiberationSans-Regular.ttf
	mv vendor/fonts/liberation/{,.}LiberationSans-Regular.ttf
	rm vendor/fonts/liberation/*
	mv vendor/fonts/liberation/{.,}LiberationSans-Regular.ttf

	# need just phplot.php and maybe rgb.php
	rm -r vendor/phplot/phplot/{contrib,[A-Z]*}

	# component related deps, not needed runtime
	rm -r vendor/symfony/process
	rm -r vendor/kriswallsmith/assetic
	rm -r vendor/robloach/component-installer
	rm -r vendor/components
	rm -r vendor/malsup/form
	rm -r vendor/enyo/dropzone
	install -d vendor/kriswallsmith/assetic/src
	touch vendor/kriswallsmith/assetic/src/functions.php
	echo '<?php return array();' > vendor/composer/autoload_namespaces.php
	rmdir --ignore-fail-on-non-empty vendor/*/
	# cleanup components
	rm htdocs/components/*/*-built.js
	rm htdocs/components/*-built.js
	rm htdocs/components/jquery-ui/*.js
	rm htdocs/components/require.*
	mv htdocs/components/jquery-ui/themes/{base,.base}
	rm -r htdocs/components/jquery-ui/themes/*
	mv htdocs/components/jquery-ui/themes/{.base,base}
	rm -r htdocs/components/jquery-ui/ui/minified
	rm -r htdocs/components/jquery-ui/ui/i18n
	rm htdocs/components/dropzone/index.js

	# auto-fix pear packages
	make pear-fix php-cs-fixer=$phpcsfixer
	# run twice, to fix all occurrences
	make pear-fix php-cs-fixer=$phpcsfixer

	# eventum standalone cli
	make -C cli eventum.phar composer=$composer box=$box
	# eventum scm
	make -C scm phar box=$box

	rm composer.lock
}

cleanup_postdist() {
	rm -f composer.json bin/{dyncontent-chksum.pl,update-pear.sh}
	rm -f cli/{composer.json,box.json.dist,Makefile}
}

phplint() {
	echo "Running php lint on source files using $(php --version | head -n1)"

	find -name '*.php' | xargs -l1 php -n -l
}

# make tarball and md5 checksum
make_tarball() {
	rm -rf $app-$version
	mv $dir $app-$version
	tar --owner=root --group=root -czf $app-$version$rc.tar.gz $app-$version
	rm -rf $app-$version
	md5sum -b $app-$version$rc.tar.gz > $app-$version$rc.tar.gz.md5
	chmod a+r $app-$version$rc.tar.gz $app-$version$rc.tar.gz.md5
}

sign_tarball() {
	if [ -x /usr/bin/gpg ] && [ "$(gpg --list-keys | wc -l)" -gt 0 ]; then
		gpg --armor --sign --detach-sig $app-$version$rc.tar.gz
	else
		cat <<-EOF

		To create a digital signature, use the following command:
		% gpg --armor --sign --detach-sig $app-$version$rc.tar.gz

		This command will create $app-$version$rc.tar.gz.asc
		EOF
	fi
}

upload_tarball() {
	[ -x dropin ] || return 0

	./dropin $app-$version$rc.tar.gz $app-$version$rc.tar.gz.md5
}

prepare_source() {
	update_version
	composer_install
	phpcompatinfo_report

	# update to include checksums of js/css files
	./bin/dyncontent-chksum.pl

	cleanup_dist

	# setup locatlization
	make -C localization install clean

	# install dirs and fix permissions
	install -d logs templates_c locks htdocs/customer
	touch logs/{cli.log,errors.log,irc_bot.log,login_attempts.log}
	chmod -R a+rX .
	chmod -R a+rwX templates_c locks logs config

	# cleanup rest of the stuff, that was neccessary for release preparation process
	cleanup_postdist

	phplint
}

# download tools
make php-cs-fixer.phar phpcompatinfo.phar

composer=$(find_prog composer)
box=$(find_prog box)
phpcsfixer=$(find_prog php-cs-fixer)
phpcompatinfo=$(find_prog phpcompatinfo)

# checkout
vcs_checkout

# tidy up
cd $dir
	prepare_source
cd ..

make_tarball
sign_tarball
upload_tarball
