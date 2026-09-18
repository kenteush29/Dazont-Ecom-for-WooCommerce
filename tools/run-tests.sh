#!/bin/bash
# LES FATALES DOIVENT SE VOIR. Sans display_errors, le harnais les avale et
# la suite annonce « 0 wrong » sur un fichier qui n'a jamais fini.
cd "$(dirname "$0")/.."
pass=0; tot=0; fail=""

# LE VRAI LINT, DEPUIS LE SHELL. check-lint.php lit les fichiers avec
# token_get_all(), qui ne voit que les erreurs d'analyse : un tableau
# [ 'a', , 'b' ] passe sa porte et tue le plugin au chargement. exec() est
# coupe sur cet hebergeur, alors le seul « php -l » possible est ici.
# display_errors, sinon php -l dit « Errors parsing » sans dire quoi ni ou.
for p in dazont-ecom/*.php dazont-ecom/includes/*.php dazont-ecom/admin/*.php; do
  [ -e "$p" ] || continue
  out=$(php -d display_errors=1 -d error_reporting=E_ALL -l "$p" 2>&1)
  case "$out" in "No syntax errors"*) ;; *) echo "  ECHEC php -l $p"; echo "$out" | head -2 | sed 's/^/      /'; fail="$fail $p";; esac
done
[ -z "$fail" ] || { echo "  le plugin ne se charge pas, les tests ne diront rien d'utile"; exit 1; }
for t in tools/test-*.php; do
  out=$(php -d display_errors=1 -d error_reporting=E_ALL "$t" 2>&1); tot=$((tot+1))
  bad=$(echo "$out" | grep -cE "^  (WRONG|FAIL|NON)")
  fat=$(echo "$out" | grep -cE "Fatal error|Parse error|Uncaught")
  if [ "$bad" = "0" ] && [ "$fat" = "0" ]; then pass=$((pass+1));
  else fail="$fail $t"; echo "  ECHEC $t"; echo "$out" | grep -E "^  (WRONG|FAIL|NON)|Fatal error|Parse error|Uncaught" | head -4 | sed 's/^/      /'; fi
done
echo "  $pass/$tot fichiers de test"
# LES PORTES STRUCTURELLES, avec la suite : une porte quon lance a la main est
# une porte quon oublie le jour ou elle aurait servi.
for g in check-lint check-methods check-menus; do
  out=$(php "tools/$g.php" dazont-ecom 2>&1) || { echo "  ECHEC tools/$g.php"; echo "$out" | tail -6 | sed 's/^/      /'; fail="$fail $g"; }
done
[ -z "$fail" ] || exit 1
