=== HMediaCleaner ===
Contributors: andersonhofelmann
Donate link: https://github.com/sponsors/anderhofelmann
Tags: media, cleaner, unused images, duplicates, quarantine, woocommerce
Requires at least: 6.0
Tested up to: 7.1
Requires PHP: 7.4
Stable tag: 0.4.0
License: GPLv2 or later

Encontra arquivos da biblioteca de mídia sem uso e duplicados, com análise abrangente, e os coloca em quarentena restaurável antes de qualquer exclusão.

== Descrição ==

O HMediaCleaner procura referências a cada arquivo da biblioteca de mídia em todo o site antes de
considerá-lo sem uso:

* conteúdo de posts, páginas e produtos (HTML, blocos, galerias, shortcodes);
* foto principal e galeria de produtos e variações do WooCommerce;
* campos personalizados e construtores de página (Elementor e outros), inclusive JSON e dados serializados;
* categorias e termos (miniaturas e descrições);
* configurações do site, do tema, widgets e plugins;
* dados de usuários;
* arquivos do tema, do tema pai e dos mu-plugins (imagens no código e no CSS);
* arquivos órfãos no disco, que nenhum anexo registra (relatório, sem apagar nada);
* o HTML final das principais páginas (cabeçalho, rodapé e o que os plugins inserem).

Reconhece IDs de anexo, URLs completas, URLs de miniaturas (-300x300), versões -scaled, WebP e
caminhos relativos. Na dúvida, considera o arquivo em uso. Também encontra arquivos idênticos
(mesmo conteúdo, comparado por hash) e indica qual é o original.

= Quarentena =

* Os arquivos (original, miniaturas, WebP e cópias de compressão) são movidos, não apagados, para
  uploads/hmc-quarantine/, e o anexo sai da biblioteca de mídia.
* Só entra o que a análise recente classificou como sem uso; no momento da ação, uma nova checagem
  confirma que não virou foto de produto, galeria, miniatura de categoria, logo ou ícone.
* O plugin vigia se os arquivos fazem falta: conta acessos a eles (com data e página de origem) e,
  a cada nova análise, verifica se algum passou a ser usado.
* Restauração com um clique, por item, por seleção ou em lote.
* Exclusão definitiva só depois do prazo configurado (padrão: 30 dias), sem acessos registrados e
  com confirmação.
* Log de todas as operações em uploads/hmc-quarantine/hmc-log.csv.
* Ao desinstalar, tudo o que estiver em quarentena é restaurado automaticamente.

= Situações =

* Em uso: há referência em algum lugar do site (o relatório mostra onde).
* Sem uso: nenhuma referência e nenhum item vinculado.
* Provavelmente sem uso: vinculada a um post/produto, mas não usada nele nem em outro lugar.
* Arquivo ausente: o registro existe, mas o arquivo não está no disco.
* Recente (protegida): enviada há menos dias que o configurado.

= WP-CLI =

* `wp hmediacleaner scan` – roda a análise completa no servidor.
* `wp hmediacleaner summary` – resumo da última análise.
* `wp hmediacleaner list --view=unused --format=csv` – lista de uma visão.
* `wp hmediacleaner why <id>` – situação de um anexo e onde ele é usado.
* `wp hmediacleaner quarantine [--view=<visão>]` – coloca os candidatos em quarentena.
* `wp hmediacleaner restore <id>... | --all` – restaura da quarentena.
* `wp hmediacleaner purge` – exclusão definitiva dos itens elegíveis.

== Changelog ==

= 0.4.0 =
* Segurança: os caminhos de arquivo vindos do banco (metadados do anexo e dados da quarentena)
  passam por validação estrita; um metadado adulterado com "../" não consegue mais fazer a
  quarentena mover ou restaurar arquivos fora da pasta de uploads. Links simbólicos nunca são seguidos
* Segurança: o atualizador só oferece releases do próprio repositório, por HTTPS e com SHA-256
  publicado (release sem hash é recusada) e confere o hash no arquivo que será de fato instalado
* Segurança: contagem de acessos à quarentena com limite de frequência (impede escrita repetida
  no banco por pedidos de visitantes); CSV (exportação e log) neutraliza fórmulas de planilha;
  desserialização sem instanciar classes; nonce das ações por item vinculado à operação;
  o endereço do site não é mais enviado ao GitHub; pasta da quarentena protegida também no IIS
* Novo: aba "Órfãos" e comando "wp hmediacleaner orphans" — arquivos que estão em uploads mas que
  nenhum anexo registra (miniaturas antigas, versões editadas, logs, arquivos soltos). Somente relatório
* Mais rápido: a leitura dos campos personalizados descarta no banco pedidos, cupons e campos sem
  mídia (em lojas com muitos pedidos, a maior parte da tabela); menos chamadas ao disco por anexo;
  comparação de duplicatas em duas fases (início do arquivo e só então o conteúdo inteiro)
* O link do plugin passou a apontar para o repositório (agora público)

= 0.3.0 =
* Atualização automática a partir das releases do GitHub: o painel avisa quando há versão nova
  e o WordPress instala pelo próprio mecanismo de atualização
* O pacote só é aceito em HTTPS e tem o SHA-256 conferido contra o hash publicado na release
* Desinstalação agora apaga também os transients do atualizador
* Cabeçalho do plugin com Plugin URI e Author URI

= 0.2.0 =

= 0.2.0 =
* Quarentena: mover arquivos sem uso para fora da biblioteca, restaurar e excluir definitivamente após um prazo.
* Monitoramento de falta: acessos a arquivos em quarentena e arquivos que voltaram a ser usados geram alerta.
* Análise passa a ler os arquivos do tema e dos mu-plugins e o HTML final das principais páginas.
* Novas configurações: validade da análise, tempo mínimo em quarentena e fontes adicionais.
* Desinstalação restaura tudo o que estiver em quarentena.

= 0.1.2 =
* Barra de progresso passa a acompanhar a etapa atual, com indicação "Etapa X de Y".
* Página do plugin ocupa a largura total.

= 0.1.1 =
* Nova aba "Sobre", com o funcionamento do plugin e os créditos do desenvolvedor.

= 0.1.0 =
* Primeira versão: análise e relatório (somente leitura).
