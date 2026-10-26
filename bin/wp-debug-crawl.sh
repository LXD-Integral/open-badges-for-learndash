#!/usr/bin/env bash
# Development tool (never shipped): log in to a WordPress site that has the
# plugin active and WP_DEBUG_LOG on, load every plugin admin screen, the block
# editor, the given front-end pages, the block's REST render and the activity
# log CSV export, and fail on any non-200 response or missing admin session.
# PHP notices are then checked in wp-content/debug.log by the caller.
#
#   bin/wp-debug-crawl.sh BASE_URL USER PASS [front-end paths...]
#   CURL_EXTRA="..." adds curl options (e.g. --connect-to for local stacks).
set -uo pipefail
BASE=${1%/} USER=$2 PASS=$3
shift 3
JAR=$(mktemp)
read -r -a EXTRA <<<"${CURL_EXTRA:-}"
CURL=(curl -sk ${EXTRA[@]+"${EXTRA[@]}"} -b "$JAR" -c "$JAR" --max-time 60)
failed=0

"${CURL[@]}" -o /dev/null "$BASE/wp-login.php"
"${CURL[@]}" -o /dev/null -b "wordpress_test_cookie=WP%20Cookie%20check" \
	-d "log=$USER&pwd=$PASS&wp-submit=Log+In&testcookie=1&redirect_to=$BASE/wp-admin/" "$BASE/wp-login.php"

fetch() { # url label [admin]
	local out code
	out=$("${CURL[@]}" -L -w '\n__HTTP__%{http_code}' "$1")
	code=${out##*__HTTP__}
	if [[ "$code" != 200 ]]; then
		echo "FAIL $code $2"; failed=1; return
	fi
	if [[ "${3:-}" == admin ]] && ! grep -q 'id="wpadminbar"' <<<"$out"; then
		echo "FAIL $2 (no admin session)"; failed=1; return
	fi
	echo "ok   $2"
	LAST=$out
}

for page in obld-settings obld-badges obld-triggers obld-issue obld-activity-log; do
	fetch "$BASE/wp-admin/admin.php?page=$page" "admin $page" admin
done
fetch "$BASE/wp-admin/admin.php?page=obld-activity-log&outcome=error" "admin activity log, filtered" admin
fetch "$BASE/wp-admin/admin.php?page=obld-issue&user_search=admin" "admin issue badges, search" admin
fetch "$BASE/wp-admin/index.php" "admin dashboard (notices)" admin
fetch "$BASE/wp-admin/plugins.php" "admin plugins" admin
fetch "$BASE/wp-admin/export-personal-data.php" "admin export personal data" admin
fetch "$BASE/wp-admin/post-new.php?post_type=page" "admin block editor" admin

# CSV export (the link exists only when the log has entries).
fetch "$BASE/wp-admin/admin.php?page=obld-activity-log" "admin activity log, for export link" admin
export_url=$(grep -oE 'href="[^"]*action=obld_export_log[^"]*"' <<<"${LAST:-}" | head -1 | sed 's/href="//;s/"$//;s/&#038;/\&/g;s/&amp;/\&/g')
if [[ -n "$export_url" ]]; then
	headers=$("${CURL[@]}" -D - -o /dev/null "$export_url")
	if grep -qi '^content-type: text/csv' <<<"$headers"; then echo "ok   activity log CSV export"; else echo "FAIL activity log CSV export"; failed=1; fi
else
	echo "skip activity log CSV export (log is empty)"
fi

# The block's editor preview is rendered through the REST API.
nonce=$("${CURL[@]}" "$BASE/wp-admin/admin-ajax.php?action=rest-nonce")
out=$("${CURL[@]}" -H "X-WP-Nonce: $nonce" "$BASE/?rest_route=/wp/v2/block-renderer/obld/earned-badges&context=edit&attributes%5Bcolumns%5D=2")
if grep -q '"rendered"' <<<"$out"; then echo "ok   block REST render"; else echo "FAIL block REST render: ${out:0:200}"; failed=1; fi

for path in "$@"; do
	fetch "$BASE$path" "front $path (signed in)"
	code=$(curl -sk ${EXTRA[@]+"${EXTRA[@]}"} -L --max-time 60 -o /dev/null -w '%{http_code}' "$BASE$path")
	if [[ "$code" == 200 ]]; then echo "ok   front $path (anonymous)"; else echo "FAIL $code front $path (anonymous)"; failed=1; fi
done

rm -f "$JAR"
exit $failed
