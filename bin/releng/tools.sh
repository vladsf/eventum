#!/bin/sh
#
# install tools necessary for CI
#

destdir="$1"

# copy tool from cache
# or download and put it to cache
get() {
	local tool="$1"

	make $tool
	cp -p $tool $destdir
}

get phpcompatinfo.phar
get phing.phar
