# =============================================================================
# Module parameters
#
# The only file of the Make setup and of tests/Release/ that holds values of one
# module. Everything else is copied from the template of magicsunday/coding-standard
# by bin/sync-webtrees-module.php and is the same in every module.
#
# The Makefile reads this file before every other fragment, and the release tests
# read it at run time.
#
# Make, the sync script and the release tests read the file the same way. A line
# is an assignment with = or := (optionally indented with spaces). The last one of
# a name wins. ?= sets a name only when no line before it did. A value carries no
# comment after it, and += is not used.
# =============================================================================

# The package name of composer.json without the vendor. The release archive, the
# POT header and the issue URL of the translations carry it, and the name of the
# JavaScript bundle is derived from it by dropping the webtrees- prefix.
MODULE_NAME := webtrees-statistics

# The segment of the PHP namespace between MagicSunday\Webtrees\ and the Test\ or
# the src/ part, as autoload and autoload-dev of composer.json spell it. The sync
# script writes it into the namespace line of the files under tests/Release/.
MODULE_NS := Statistic

# The prefix injected into the bundled module-base classes at dist time. It keeps
# two modules at different versions from registering the same shared namespace.
# Must be unique per module.
SCOPE_NS := StatisticsVendor

# The webtrees constraint that release-prepare and release-bump write to composer.json.
# It derives from CORE_RELEASES, so it needs the deferred form, written with = and not with :=.
WEBTREES_CONSTRAINT = ~$(firstword $(CORE_RELEASES))

# The file of the chart library build that marks a finished build. release-bootstrap
# runs npm ci when it is missing from node_modules/@magicsunday/webtrees-chart-lib/dist/.
CHART_LIB_MARKER := webtrees-chart-lib-widgets.es.js

# The module bundles no shared module-base views, so the variable is defined empty.
# The archive then carries the module-base source only.
SHARED_VIEWS :=

# The webtrees core releases whose catalogues define the texts that core owns. A text
# that every one of them carries is left out of the POT, see Make/lang.mk and
# dev/core-owned.pot. The list holds every release of the minor line composer.json
# requires, the first one is the floor of that constraint, and a release core ships
# later is added to it.
CORE_RELEASES := 2.2.2 2.2.3 2.2.4 2.2.5 2.2.6
