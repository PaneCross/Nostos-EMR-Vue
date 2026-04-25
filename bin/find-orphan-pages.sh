#!/usr/bin/env bash
# Phase P12 — find Vue files in resources/js/Pages that are not referenced by:
#   1. Inertia::render('X/Y')          — direct route render
#   2. <Component> tag in any other Vue file (composed as a child)
#   3. import statement in any other Vue/TS file (composed via setup)
set -uo pipefail
cd "$(dirname "$0")/.."

PAGES_DIR="resources/js/Pages"
TMP_ALL=$(mktemp); TMP_REF=$(mktemp); trap "rm -f $TMP_ALL $TMP_REF" EXIT

# All Vue files under Pages/, with their relative dotted name (e.g. ItAdmin/Audit)
find "$PAGES_DIR" -name '*.vue' | sed -E "s#^${PAGES_DIR}/##; s#\.vue\$##" | sort > "$TMP_ALL"

# All Inertia render targets in PHP
grep -rohE "Inertia::render\\(['\"]([^'\"]+)['\"]" routes/ app/ \
  | sed -E "s/.*Inertia::render\\(['\"]([^'\"]+)['\"].*/\\1/" \
  | sort -u > "$TMP_REF"

# All component imports in Vue/TS source
grep -rohE "from '@/Pages/[^']+'" resources/ \
  | sed -E "s#from '@/Pages/([^']+)'#\\1#" \
  | sed -E "s#\.vue\$##" \
  | sort -u >> "$TMP_REF"

# All component imports via relative ../../Pages
grep -rohE "from '\.\./[^']*Pages/[^']+'" resources/ 2>/dev/null \
  | sed -E "s#from '\\.\\./[^']*Pages/([^']+)'#\\1#" \
  | sed -E "s#\.vue\$##" \
  | sort -u >> "$TMP_REF"

# Reference any basename that appears in a `from '...PageName.vue'` import
# (covers relative paths like ./Tabs/AdlTab.vue + ./Depts/PrimaryCareDashboard.vue
# that don't go through the @/Pages/ alias).
grep -rohE "from '[^']+\\.vue'" resources/js/ 2>/dev/null \
  | sed -E "s#from '[^']*/([^/']+)\\.vue'#\\1#" \
  | sort -u >> "$TMP_REF"

# Reference any basename that appears in `<ComponentName ` or `<ComponentName/>` form
COMPONENT_BASENAMES=$(awk -F/ '{print $NF}' "$TMP_ALL" | sort -u)
for name in $COMPONENT_BASENAMES; do
  if grep -rqE "<${name}[ />]" resources/js/ 2>/dev/null; then
    echo "$name" >> "$TMP_REF"
  fi
done

sort -u "$TMP_REF" -o "$TMP_REF"

# Find pages whose full dotted path AND basename do not appear in references.
echo "Possible orphan pages:"
while IFS= read -r page; do
  base=$(basename "$page")
  if grep -qFx "$page" "$TMP_REF"; then continue; fi
  if grep -qFx "$base" "$TMP_REF"; then continue; fi
  echo "  $page"
done < "$TMP_ALL"
