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

## Arquivos órfãos

Além dos anexos sem uso, o plugin lista os **arquivos que estão em `uploads` mas que nenhum anexo registra**: miniaturas de tamanhos antigos, versões `-scaled` e editadas, cópias `.webp`, logs, arquivos enviados por FTP. Em lojas antigas costumam ocupar mais espaço que os anexos sem uso.

A procura vale para as pastas de data (`2026/10/`) e para a raiz de `uploads`; pastas de plugins ficam de fora. Arquivos que o site referencia são marcados como "em uso" e **nunca** são movidos. Aba **Órfãos** no painel e `wp hmediacleaner orphans` na linha de comando.

Nos sites em que o WordPress reduz as fotos grandes (versão `-scaled`), o **original** costuma ficar no disco sem constar nos metadados do anexo: o WordPress não o enxerga e não o apaga. O plugin os mostra como "Originais grandes", junto com as miniaturas deles, porque é aí que costuma estar o maior volume de espaço.

### Quarentena e exclusão de órfãos

Os órfãos seguem o mesmo caminho dos anexos: **quarentena → exclusão**, nunca apagar direto.

- **Mover:** os arquivos vão para `uploads/hmc-quarantine/orphans/`, com o mesmo caminho de origem. Por tipo, em lote, com confirmação (reforçada para originais grandes, cópias WebP e arquivos soltos).
- **Conferido de novo na hora de mover:** a análise precisa ser recente; o arquivo precisa existir, ser um arquivo comum (nunca link simbólico) dentro de `uploads`, e ter o mesmo tamanho e a mesma data da análise. Arquivos modificados há poucos dias (padrão: 7) são protegidos, e arquivos que a biblioteca passou a registrar são ignorados.
- **Vigia:** pedidos 404 ao endereço original do arquivo em quarentena são contados (com limite de frequência). Quem recebeu acesso **nunca** é excluído, e o painel alerta.
- **Restaurar:** com um clique, por tipo ou tudo. Nunca sobrescreve um arquivo que já exista no destino.
- **Excluir definitivamente:** só depois do prazo da quarentena (padrão: 30 dias) e só se não houve acessos, sempre com confirmação.
- **Linha de comando:** `wp hmediacleaner orphans-quarantine --kind=<tipo>`, `orphans-quarantined`, `orphans-restore`, `orphans-purge`.
- Tudo é registrado no log da quarentena, e a desinstalação do plugin devolve os órfãos aos lugares de origem.

## Quarentena

Cada arquivo movido para a quarentena pode ser restaurado com um clique. A desinstalação do plugin também devolve **tudo** o que estiver em quarentena — a biblioteca de mídia não perde nada.

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

# Arquivos órfãos: listar, mover, ver, restaurar, excluir (só os elegíveis)
wp hmediacleaner orphans
wp hmediacleaner orphans-quarantine --kind=junk
wp hmediacleaner orphans-quarantined
wp hmediacleaner orphans-restore [--kind=<tipo>]
wp hmediacleaner orphans-purge [--kind=<tipo>]
```

## Segurança

- Todo caminho de arquivo que vem do banco de dados (metadados do anexo, dados da quarentena) é validado antes de tocar no disco: `../`, caminho absoluto, byte nulo e links simbólicos são recusados, e o destino real precisa ficar dentro de `uploads`.
- Exportações em CSV neutralizam células que começam com `=`, `+`, `-` ou `@` (injeção de fórmula).
- A contagem de acessos à quarentena tem limite de frequência.
- **Nginx:** o `.htaccess` da pasta da quarentena só vale no Apache/LiteSpeed. No Nginx, acrescente ao servidor:

```nginx
location ^~ /wp-content/uploads/hmc-quarantine/ { deny all; return 404; }
```

## Atualizações

O plugin se atualiza sozinho a partir das releases do GitHub. Quando há versão nova, o painel mostra um aviso com botão para atualizar, e a instalação é feita pelo próprio WordPress.

Por segurança, o pacote só é oferecido se:

- vier de uma release **do próprio repositório**, por **HTTPS**;
- a release **publicar o SHA-256** (primeira linha do texto: `sha256: <64 hexadecimais>`); sem hash, a atualização é recusada.

O hash é conferido no arquivo que será de fato instalado, não só na hora de procurar atualizações.

O repositório precisa ser **público** para o WordPress consultar as releases.

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