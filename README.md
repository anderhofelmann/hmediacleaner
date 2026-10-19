# HMediaCleaner

Plugin de WordPress que encontra **arquivos da biblioteca de mídia sem uso e duplicados**, faz uma análise abrangente de todo o site e coloca o que sobra em **quarentena restaurável** antes de qualquer exclusão.

**Desenvolvido por Anderson Höfelmann.**

---

## O que ele faz

Antes de considerar uma imagem "sem uso", o plugin procura referências em:

- conteúdo de posts, páginas e produtos (HTML, blocos, galerias, shortcodes);
- foto principal e galeria de produtos e variações do WooCommerce;
- campos personalizados e construtores de página (Elementor e outros), inclusive JSON serializado;
- categorias e termos (miniaturas e descrições);
- metadados, campos ACF e configurações de tema;
- anexos fixados e imagens destacadas.

Só o que não tem referência nenhuma entra na lista. E nada é apagado sem passar pela quarentena.

## Quarentena

Cada arquivo movesido para a quarentena pode ser restaurado com um clique. A desinstalação do plugin também devolve **tudo** o que estiver em quarentena — a biblioteca de mídia não perde nada.

## Instalação

1. Baixe o [ZIP mais recente](https://github.com/anderhofelmann/hmediacleaner/releases)
2. **Plugins → Adicionar Novo → Enviar Plugin**
3. Instale, ative e acesse **Mídia → HMediaCleaner**

Requer WordPress 6.0+ e PHP 7.4+.

## Linha de comando

```bash
# Ver o que está configurado
wp hmediacleaner status

# Fazer a varredura
wp hmediacleaner scan

# Listar o que está em quarentena
wp hmediacleaner quarantine list

# Restaurar um arquivo
wp hmediacleaner quarantine restore <id>
```

## Atualizações

O plugin se atualiza sozinho a partir das releases do GitHub. Quando há versão nova, o painel mostra um aviso com botão para atualizar, e a instalação é feita pelo próprio WordPress.

Por segurança, o pacote só é aceito se:

- a URL for **HTTPS** (bloqueia espelho em texto puro);
- o **SHA-256** do arquivo bater com o hash publicado na release.

Se a rede falhar ou o limite de requisições do GitHub for atingido, nada acontece — o plugin segue funcionando e tenta de novo mais tarde.

## Arquitetura

```
hmediacleaner/          plugin distribuível (é isso que vira ZIP)
├── hmediacleaner.php   bootstrap
├── includes/
│   ├── class-hmc-scanner.php     varredura e análise de referências
│   ├── class-hmc-refs.php        coleta de referências
│   ├── class-hmc-quarantine.php  quarentena e restauração
│   ├── class-hmc-db.php          tabelas e migrações
│   ├── class-hmc-list-table.php  tabela no admin
│   ├── class-hmc-admin.php       telas e ações
│   ├── class-hmc-cli.php         comandos WP-CLI
│   └── class-hmc-updater.php     atualização via GitHub
├── assets/
└── uninstall.php

testes/                 testes (não vão para o ZIP)
bin/build-zip.sh        gera o ZIP de distribuição
```

## Desenvolvimento

```bash
# Testes do atualizador (não precisam de WordPress)
php testes/teste-atualizador.php

# Gerar o ZIP de distribuição
./bin/build-zip.sh
```

Os testes que exercitam a varredura precisam de um WordPress real:

```bash
wp eval-file testes/cenario-wordpress.php
```

A cada `push`, o GitHub Actions roda os testes em PHP 7.4 a 8.3.

Antes de publicar, **teste num ambiente de homologação**: o plugin mexe na biblioteca de mídia.

## Licença

GPL-2.0-or-later.