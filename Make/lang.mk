# =============================================================================
# TARGETS
# =============================================================================

#### Language & Translations

.PHONY: lang lang-extract lang-merge lang-compile

# Locales the module ships translations for. Add a new entry here +
# `make lang` once to seed the locale's messages.po from the POT.
# Selection follows the dev.webtrees.net installation share — the
# top 10 most-used locales plus en-US as the editor-template source
# (en-US's msgstr usually mirrors the msgid; Poedit consumers use it
# as the canonical English reference when filling other languages).
LOCALES := cs da de en-US fr it nb nl pl ru zh-Hans

POT_FILE  := resources/lang/messages.pot
PO_FILES  := $(foreach loc,$(LOCALES),resources/lang/$(loc)/messages.po)
MO_FILES  := $(PO_FILES:.po=.mo)

# Full pipeline: extract POT → merge into existing PO files (or seed
# missing ones from the POT) → compile every PO to a MO.
lang: .logo lang-extract lang-merge lang-compile ## Extract POT, merge PO, compile MO (full i18n pipeline).
	@echo "  ✔ Translations up to date for: $(LOCALES)"

lang-extract: $(POT_FILE) ## Extract translatable strings from src/ + resources/ into the POT.

# xgettext walks every PHP / PHTML source for the I18N::translate
# family. The keyword list mirrors webtrees core's helpers — adding
# a new context-aware variant means extending this list.
$(POT_FILE): $(shell find src resources/views -type f \( -name '*.php' -o -name '*.phtml' \) 2>/dev/null)
	@$(COMPOSE_RUN) sh -c 'apk add --no-cache gettext >/dev/null 2>&1; \
		mkdir -p resources/lang; \
		xgettext \
			--language=PHP \
			--from-code=UTF-8 \
			--keyword=translate \
			--keyword=translateContext:1c,2 \
			--keyword=plural:1,2 \
			--add-comments=I18N \
			--package-name="webtrees-statistics" \
			--copyright-holder="Rico Sonntag" \
			--msgid-bugs-address="https://github.com/magicsunday/webtrees-statistics/issues" \
			--sort-output \
			--output=$(POT_FILE) \
			$$(find src resources/views -type f \( -name "*.php" -o -name "*.phtml" \) | sort) && \
		echo "  ✔ Extracted $(POT_FILE) ($$(grep -c ^msgid $(POT_FILE)) strings)"'

lang-merge: $(POT_FILE) ## Update each locale's PO from the latest POT (seeds missing locales).
	@$(COMPOSE_RUN) sh -c 'apk add --no-cache gettext >/dev/null 2>&1; \
		for loc in $(LOCALES); do \
			po="resources/lang/$$loc/messages.po"; \
			if [ ! -f "$$po" ]; then \
				mkdir -p "resources/lang/$$loc"; \
				msginit --no-translator --locale="$$loc" --input=$(POT_FILE) --output-file="$$po" >/dev/null 2>&1 && \
					echo "  + Seeded $$po from POT"; \
			else \
				msgmerge --quiet --update --backup=none "$$po" $(POT_FILE) && \
					echo "  ↻ Merged $$po"; \
			fi; \
		done'

lang-compile: ## Compile every messages.po to its sibling messages.mo.
	@$(COMPOSE_RUN) sh -c 'apk add --no-cache gettext >/dev/null 2>&1; \
		for po in resources/lang/*/messages.po; do \
			dir=$$(dirname "$$po"); \
			msgfmt -o "$$dir/messages.mo" "$$po" && \
				echo "  ✔ Compiled $$po"; \
		done'
