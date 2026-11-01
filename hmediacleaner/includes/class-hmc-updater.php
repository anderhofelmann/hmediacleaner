<?php
/**
 * Atualização automática a partir das releases do GitHub.
 *
 * O WordPress só sabe instalar ZIPs. Esta classe consulta a API do GitHub,
 * compara com a versão instalada e, havendo versão nova, coloca o endereço do
 * arquivo na tela "Atualizar plugins", sem depender de biblioteca externa.
 *
 * Proteções aplicadas na instalação:
 *  - o pacote precisa ser HTTPS (bloqueia espelho em texto puro);
 *  - o SHA-256 do arquivo é conferido contra o hash publicado na release;
 *  - uma única consulta à API a cada 6 horas, porque o GitHub limita a 60
 *    requisições por hora sem autenticação.
 *
 * Se a rede falhar ou o limite for atingido, nada acontece: o plugin
 * continua funcionando e tenta de novo mais tarde.
 */

defined( 'ABSPATH' ) || exit;

/**
 * Classe do atualizador.
 */
class HMC_Updater {

	/**
	 * Repositório no GitHub, no formato "usuario/repositorio".
	 */
	const REPO = 'anderhofelmann/hmediacleaner';

	/**
	 * Caminho do plugin dentro do ZIP.
	 */
	const PLUGIN_FILE = 'hmediacleaner/hmediacleaner.php';

	/**
	 * Nome do arquivo que o WordPress vai baixar.
	 */
	const ASSET_NAME = 'hmediacleaner.zip';

	/**
	 * Chave do cache do resultado da consulta.
	 */
	const CACHE_KEY = 'hmc_update_check';

	/**
	 * Chave do cache que marca a consulta já feita neste request.
	 */
	const CACHE_BRANCH = 'hmc_update_check_branch';

	/**
	 * Tempo do cache: 6 horas, para respeitar o limite da API.
	 */
	const CACHE_TTL = 21600;

	/**
	 * Registra os hooks.
	 */
	public static function init() {
		add_filter( 'pre_set_site_transient_update_plugins', array( __CLASS__, 'check_update' ) );
		add_filter( 'plugins_api', array( __CLASS__, 'plugin_info' ), 10, 3 );
		add_filter( 'upgrader_pre_download', array( __CLASS__, 'pre_download' ), 10, 4 );
		add_action( 'admin_notices', array( __CLASS__, 'render_notice' ) );
	}

	/**
	 * Verifica se existe versão nova e alimenta o transiente do WordPress.
	 *
	 * @param object $transient Objeto update_plugins do WordPress.
	 * @return object
	 */
	public static function check_update( $transient ) {
		// Só age em telas do painel, onde o transiente é processado.
		if ( ! is_object( $transient ) || ! isset( $transient->response ) ) {
			return $transient;
		}

		$release = self::get_release_info();

		if ( ! $release ) {
			return $transient;
		}

		$atual = self::current_version();
		$nova  = isset( $release['version'] ) ? $release['version'] : '';

		if ( '' === $nova || version_compare( $atual, $nova, '>=' ) ) {
			return $transient;
		}

		// Só oferece a atualização se o pacote vem do próprio repositório, por HTTPS, e a release
		// publica o hash. O hash é conferido contra o arquivo na hora da instalação (pre_download).
		if ( ! self::package_is_trusted( $release ) ) {
			return $transient;
		}

		$transient->response[ self::PLUGIN_FILE ] = self::build_update_object( $release );

		return $transient;
	}

	/**
	 * O pacote vem de uma release do repositório oficial, por HTTPS, e há SHA-256 publicado?
	 *
	 * Sem hash a atualização NÃO é oferecida: instalar código sem poder conferi-lo é o risco que
	 * este atualizador existe para evitar.
	 *
	 * @param array $release Dados da release.
	 * @return bool
	 */
	public static function package_is_trusted( $release ) {
		if ( ! is_array( $release ) || empty( $release['package'] ) || empty( $release['sha256'] ) ) {
			return false;
		}
		$prefixo = 'https://github.com/' . self::REPO . '/releases/download/';
		return 0 === strpos( (string) $release['package'], $prefixo )
			&& false === strpos( (string) $release['package'], '..' )
			&& (bool) preg_match( '/^[a-f0-9]{64}$/', (string) $release['sha256'] );
	}

	/**
	 * Confere o SHA-256 do arquivo que o WordPress vai INSTALAR.
	 *
	 * O WordPress baixaria o pacote de novo na hora da instalação; conferir o hash só ao procurar
	 * atualizações não garante que o arquivo instalado seja o conferido. Aqui o pacote é baixado
	 * uma vez, o hash é verificado e o próprio arquivo verificado é entregue ao instalador.
	 *
	 * @param false|string|WP_Error $reply      Resposta anterior do filtro.
	 * @param string                $package    Endereço do pacote.
	 * @param WP_Upgrader           $upgrader   Instância do atualizador.
	 * @param array                 $hook_extra Dados extras (inclui o plugin).
	 * @return false|string|WP_Error
	 */
	public static function pre_download( $reply, $package, $upgrader = null, $hook_extra = array() ) {
		$plugin = is_array( $hook_extra ) && isset( $hook_extra['plugin'] ) ? $hook_extra['plugin'] : '';
		if ( self::PLUGIN_FILE !== $plugin ) {
			return $reply; // Não é o nosso plugin: o WordPress segue normalmente.
		}

		$release = self::get_release_info();
		if ( ! self::package_is_trusted( $release ) || $release['package'] !== $package ) {
			return new WP_Error( 'hmc_pacote_nao_confiavel', __( 'HMediaCleaner: o pacote de atualização não é de uma release confiável do repositório; nada foi instalado.', 'hmediacleaner' ) );
		}

		$arquivo = download_url( $package, 60 );
		if ( is_wp_error( $arquivo ) ) {
			return $arquivo;
		}

		$hash = hash_file( 'sha256', $arquivo );
		if ( ! is_string( $hash ) || ! hash_equals( $release['sha256'], $hash ) ) {
			@unlink( $arquivo ); // phpcs:ignore WordPress.PHP.NoSilencedErrors
			return new WP_Error( 'hmc_hash_diferente', __( 'HMediaCleaner: o SHA-256 do pacote não confere com o publicado na release; nada foi instalado.', 'hmediacleaner' ) );
		}

		return $arquivo;
	}

	/**
	 * Monta o objeto de atualização no formato esperado pelo WordPress.
	 *
	 * @param array $release
	 * @return object
	 */
	private static function build_update_object( $release ) {
		$atual = self::current_version();

		$obj              = new stdClass();
		$obj->id          = 'hmediacleaner';
		$obj->slug        = 'hmediacleaner';
		$obj->plugin      = self::PLUGIN_FILE;
		$obj->new_version = $release['version'];
		$obj->package     = $release['package'];
		$obj->url         = $release['url'];
		$obj->icons       = array();
		$obj->requires    = '6.0';
		$obj->requires_php = '7.4';

		$obj->banners       = array();
		$obj->banners['low']  = $release['url'];
		$obj->banners['high'] = $release['url'];

		$obj->sections = array(
			'description' => 'Encontra arquivos da biblioteca de mídia sem uso e duplicados e os coloca em quarentena antes de qualquer exclusão.',
			'changelog'   => isset( $release['changelog'] ) ? $release['changelog'] : '',
		);

		$obj->upgrade_notice = sprintf(
			/* translators: 1: versão nova, 2: versão atual */
			__( 'HMediaCleaner %1$s está disponível (você usa a %2$s).', 'hmediacleaner' ),
			$release['version'],
			$atual
		);

		return $obj;
	}

	/**
	 * Detalhes do plugin para a tela de informações.
	 *
	 * @param false|object|array $resultado
	 * @param string             $action
	 * @param object             $args
	 * @return false|object|array
	 */
	public static function plugin_info( $resultado, $action, $args ) {
		if ( 'plugin_information' !== $action ) {
			return $resultado;
		}

		if ( ! isset( $args->slug ) || 'hmediacleaner' !== $args->slug ) {
			return $resultado;
		}

		$release = self::get_release_info();

		if ( ! $release ) {
			return $resultado;
		}

		$obj                 = new stdClass();
		$obj->name           = 'HMediaCleaner';
		$obj->slug           = 'hmediacleaner';
		$obj->version        = $release['version'];
		$obj->author         = 'Anderson Höfelmann';
		$obj->author_profile = 'https://github.com/anderhofelmann';
		$obj->homepage       = 'https://github.com/' . self::REPO;
		$obj->requires       = '6.0';
		$obj->requires_php   = '7.4';
		$obj->tested         = '6.4';
		$obj->download_link  = $release['package'];
		$obj->sections       = array(
			'description' => 'Encontra arquivos da biblioteca de mídia sem uso e duplicados e os coloca em quarentena antes de qualquer exclusão.',
			'changelog'   => isset( $release['changelog'] ) ? $release['changelog'] : '',
		);

		return $obj;
	}

	/**
	 * Consulta a API do GitHub, com cache.
	 *
	 * @return array|null
	 */
	public static function get_release_info() {
		// No máximo uma consulta por request.
		if ( get_transient( self::CACHE_BRANCH ) ) {
			return get_transient( self::CACHE_KEY );
		}
		set_transient( self::CACHE_BRANCH, 1, HOUR_IN_SECONDS );

		$cache = get_transient( self::CACHE_KEY );

		if ( is_array( $cache ) ) {
			return $cache;
		}

		$release = self::fetch_latest_release();

		// Guarda mesmo sem resultado, para não repetir a chamada.
		set_transient( self::CACHE_KEY, $release, self::CACHE_TTL );

		return $release;
	}

	/**
	 * Busca a release mais recente na API do GitHub.
	 *
	 * @return array|null
	 */
	private static function fetch_latest_release() {
		$url = sprintf( 'https://api.github.com/repos/%s/releases/latest', self::REPO );

		$resposta = wp_remote_get(
			$url,
			array(
				'timeout'    => 8,
				// Sem o endereço do site: o GitHub não precisa saber onde o plugin está instalado.
				'user-agent' => 'HMediaCleaner/' . self::current_version(),
				'headers'    => array( 'Accept' => 'application/vnd.github+json' ),
			)
		);

		// Sem rede ou limite atingido: devolve null e tenta depois.
		if ( is_wp_error( $resposta ) || 200 !== (int) wp_remote_retrieve_response_code( $resposta ) ) {
			return null;
		}

		$corpo = json_decode( wp_remote_retrieve_body( $resposta ), true );

		if ( ! is_array( $corpo ) || empty( $corpo['tag_name'] ) ) {
			return null;
		}

		$pacote = '';
		foreach ( (array) $corpo['assets'] as $asset ) {
			// Aceita "hmediacleaner.zip" e também "hmediacleaner-0.4.0.zip" (o nome que o GitHub Actions gera).
			if ( isset( $asset['name'], $asset['browser_download_url'] ) && preg_match( '/^hmediacleaner(?:-[0-9][0-9a-z.\-]*)?\.zip$/i', (string) $asset['name'] ) ) {
				$pacote = $asset['browser_download_url'];
				break;
			}
		}

		// Sem ZIP anexado, não há o que instalar.
		if ( '' === $pacote ) {
			return null;
		}

		return array(
			'version'   => self::normalize_version( $corpo['tag_name'] ),
			'package'   => $pacote,
			'url'       => isset( $corpo['html_url'] ) ? $corpo['html_url'] : '',
			'changelog' => isset( $corpo['body'] ) ? wp_kses_post( $corpo['body'] ) : '',
			'sha256'    => self::extract_sha256( isset( $corpo['body'] ) ? $corpo['body'] : '' ),
		);
	}

	/**
	 * Extrai o SHA-256 publicado no corpo da release.
	 *
	 * O autor publica no topo da release:
	 *     sha256: 3f7a...
	 *
	 * @param string $corpo
	 * @return string Hexadecimal de 64 caracteres, ou string vazia.
	 */
	public static function extract_sha256( $corpo ) {
		if ( ! is_string( $corpo ) || '' === $corpo ) {
			return '';
		}

		if ( preg_match( '/sha-?256\s*[:=]\s*`?([a-f0-9]{64})`?/i', $corpo, $m ) ) {
			return strtolower( $m[1] );
		}

		return '';
	}

	/**
	 * Normaliza "v1.2.3" para "1.2.3".
	 *
	 * @param string $tag
	 * @return string
	 */
	public static function normalize_version( $tag ) {
		$tag = trim( (string) $tag );
		$tag = trim( $tag, '/' );
		return ltrim( $tag, 'vV' );
	}

	/**
	 * Versão instalada, lida do próprio arquivo.
	 *
	 * @return string
	 */
	public static function current_version() {
		return defined( 'HMC_VERSION' ) ? HMC_VERSION : '0.0.0';
	}

	/**
	 * Avisa no painel quando há versão nova.
	 */
	public static function render_notice() {
		if ( ! current_user_can( 'update_plugins' ) ) {
			return;
		}

		$screen = function_exists( 'get_current_screen' ) ? get_current_screen() : null;

		// Só nas telas de plugins, para não poluir o resto do painel.
		if ( ! $screen || false === strpos( (string) $screen->id, 'plugins' ) ) {
			return;
		}

		$release = self::get_release_info();

		if ( ! $release || version_compare( self::current_version(), $release['version'], '>=' ) ) {
			return;
		}

		$url = add_query_arg(
			array(
				'action' => 'upgrade-plugin',
				'plugin' => self::PLUGIN_FILE,
			),
			admin_url( 'update.php' )
		);

		printf(
			'<div class="notice notice-warning is-dismissible"><p><strong>%s</strong> %s <a href="%s" class="button button-primary">%s</a></p></div>',
			esc_html__( 'HMediaCleaner:', 'hmediacleaner' ),
			sprintf(
				/* translators: %s: versão disponível */
				esc_html__( 'a versão %s está disponível.', 'hmediacleaner' ),
				esc_html( $release['version'] )
			),
			esc_url( $url ),
			esc_html__( 'Atualizar agora', 'hmediacleaner' )
		);
	}
}