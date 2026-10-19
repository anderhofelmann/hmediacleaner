#!/usr/bin/env bash
#
# Gera o ZIP de distribuição do HMediaCleaner.
#
#   ./bin/build-zip.sh
#
# O ZIP leva apenas a pasta hmediacleaner/, sem testes nem arquivos de
# desenvolvimento.
#
set -euo pipefail
RAIZ="$( cd "$( dirname "${BASH_SOURCE[0]}" )/.." && pwd )"
NOME="hmediacleaner"
cd "$RAIZ"
if [ ! -d "$NOME" ]; then
    echo "erro: pasta $NOME nao encontrada em $RAIZ" >&2
    exit 1
fi
VERSAO="$(grep -m1 -oP '^\s*\*\s*Version:\s*\K[0-9.]+' "$NOME/$NOME.php")"
SAIDA="$RAIZ/$NOME-$VERSAO.zip"
# Confere a sintaxe antes de empacotar.
while IFS= read -r arquivo; do
    php -l "$arquivo" >/dev/null || {
        echo "erro: erro de sintaxe em $arquivo" >&2
        exit 1
    }
done < <(find "$NOME" -name '*.php')
rm -f "$RAIZ/$NOME.zip" "$SAIDA"
zip -qr "$SAIDA" "$NOME" -x '*.DS_Store' '*/node_modules/*' '*/.git/*'
echo "ZIP gerado: $SAIDA"
echo "Versao: $VERSAO"
echo "sha256: $(sha256sum "$SAIDA" | cut -d' ' -f1)"