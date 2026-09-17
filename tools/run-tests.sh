#!/bin/bash
# LES FATALES DOIVENT SE VOIR. Sans display_errors, le harnais les avale et
# la suite annonce « 0 wrong » sur un fichier qui n'a jamais fini.
cd "$(dirname "$0")/.."
pass=0; tot=0; fail=""
for t in tools/test-*.php; do
  out=$(php -d display_errors=1 -d error_reporting=E_ALL "$t" 2>&1); tot=$((tot+1))
  bad=$(echo "$out" | grep -cE "^  (WRONG|FAIL|NON)")
  fat=$(echo "$out" | grep -cE "Fatal error|Parse error|Uncaught")
  if [ "$bad" = "0" ] && [ "$fat" = "0" ]; then pass=$((pass+1));
  else fail="$fail $t"; echo "  ECHEC $t"; echo "$out" | grep -E "^  (WRONG|FAIL|NON)|Fatal error|Parse error|Uncaught" | head -4 | sed 's/^/      /'; fi
done
echo "  $pass/$tot fichiers de test"
[ -z "$fail" ] || exit 1
