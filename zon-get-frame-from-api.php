<?php
/**
 * @package ZEIT ONLINE Framebuilder Client
 *
 * Plugin Name:       ZEIT ONLINE Framebuilder Client
 * Plugin URI:        https://github.com/ZeitOnline/zon-get-frame-from-api
 * Description:       Get and cache a preconfigured site frame from www.zeit.de/framebuilder and display it as header and footer in the blog themes
 * Version:           2.2.3
 * Author:            Nico Bruenjes, Moritz Stoltenburg, Arne Seemann
 * Author URI:        http://www.zeit.de
 * Text Domain:       zgffa
 * License:           GPL-3.0+
 * License URI:       http://www.gnu.org/licenses/gpl-3.0.txt
 * Domain Path:       /languages
 * GitHub Plugin URI: https://github.com/ZeitOnline/zon-get-frame-from-api
*/

! defined( 'ABSPATH' ) and exit;

class ZON_Get_Frame_From_API
{
	/**
	 * Static property to hold our singleton instance
	 *
	 * @var ZON_Get_Frame_From_API
	 */
	static $instance = false;

	/**
	 * URL of the framebuilder api
	 * (yet a static value, may needs to be mutable if we get sub site blogs i.e. campus)
	 *
	 * @var string
	 */
	static $framebuilder_url = 'http://www.zeit.de/framebuilder';

	/**
	 * identifier name for the plugin
	 *
	 * @var  string
	 */
	static $plugin_name = 'zon_get_frame_from_api';

	/**
	 * Time in seconds to cache content
	 *
	 * @var int
	 */
	static $cachetime = DAY_IN_SECONDS;

	/**
	 * Prefix for database identification
	 * adds some character to md5'd values to find them in the database
	 * max size would be 7 characters (cause the max name length for site transients is 40 characters)
	 *
	 * @var string
	 */
	const PREFIX = 'zgffa';

	/**
	 * Plugin settings name
	 *
	 * @var string
	 */
	const SETTINGS = 'zgffa_settings';

	/**
	 * Initialize and add some actions
	 *
	 * @return void
	 */
	private function __construct() {
		// load textdomain
		$plugin_rel_path = basename( dirname( __FILE__ ) ) . '/languages';
		load_plugin_textdomain( 'zgffa', false, $plugin_rel_path );

		// backend
		add_action( 'admin_init', array( &$this, 'init_settings' ) );
		$hook = is_multisite() ? 'network_admin_menu' : 'admin_menu';
		add_action( $hook, array( $this, 'add_admin_menu' ) );

		// frontend
		add_action( 'zon_theme_after_opening_head', array( $this, 'print_html_head' ) );
		add_action( 'zon_theme_after_opening_body', array( $this, 'print_upper_body' ) );
		add_action( 'zon_theme_before_closing_body', array( $this, 'print_footer' ) );

		// override option zon_bannerkennung
		add_filter( 'pre_option_zon_bannerkennung', array( $this, 'get_banner_channel' ) );

		// add links on plugin page
		add_filter('plugin_action_links', array( $this, 'add_action_links'), 10, 2 );
	}

	/**
	 * If an instance exists, this returns it.  If not, it creates one and
	 * retuns it.
	 *
	 * @return ZON_Get_Frame_From_API
	 */
	public static function getInstance() {
		if ( !self::$instance )
			self::$instance = new self;
		return self::$instance;
	}

	/**
	 * Activate the plugin
	 */
	public static function activate() {
		$cached = self::getInstance()->warm_up_frame_cache();
	}

	/**
	 * Deactivate the plugin
	 */
	public static function deactivate() {
		$deleted = self::getInstance()->delete_all_transients();
	}

	/**
	 * hook into WP's admin_init action hook
	 */
	public function init_settings() {
		// Set up the settings for this plugin
		register_setting( self::PREFIX . '_group', self::SETTINGS );

		add_settings_section(
			'zgffa_general_settings',
			__( 'Framebuilder API', 'zgffa' ),
			array( $this, 'zgffa_settings_section_text' ),
			self::$plugin_name
		);

		add_settings_field(
			'ttl',
			__( 'Cachingtime in seconds', 'zgffa' ),
			array( $this, 'zgffa_settings_ttl_render' ),
			self::$plugin_name,
			'zgffa_general_settings'
		);

		add_settings_field(
			'ssl',
			__( 'Use SSL/TLS frame', 'zgffa' ),
			array( $this, 'zgffa_settings_ssl_render' ),
			self::$plugin_name,
			'zgffa_general_settings'
		);

	}

	public function zgffa_settings_section_text() {
		echo make_clickable(__('Documentation', 'zgffa').': https://github.com/ZeitOnline/zeit.web/wiki/Rahmen-API---Framebuilder');
	}

	public function zgffa_settings_ttl_render() {

		$settings = self::SETTINGS;
		$options = $this->get_options();
		$helptext = __('Time in seconds to after the frame fragments are revalidated from the framebuilder api. Should be normally one day (86.400 seconds)', 'zgffa');

		echo <<<HTML
			<input type="number" name="{$settings}[ttl]" value="$options[ttl]" min="60" step="60">
			<p class="description">{$helptext}.</p>

HTML;

}

	public function zgffa_settings_ssl_render() {
		$settings = self::SETTINGS;
		$options = $this->get_options();
		if ( !isset($options['ssl'] ) ) {
			$options['ssl'] = 0;
		}

		?>
			<input type="checkbox" name="<?php echo $settings; ?>[ssl]" value="1"<?php checked( 1 == $options['ssl'] ); ?> /> <?php
			_e('SSL/TLS Frame active', 'zgffa');

	}

	/**
	 * Adding the options page to the network menu
	 *
	 * @return void
	 */
	public function add_admin_menu () {
		if ( is_multisite() ) {
			add_submenu_page(
				'settings.php', // parent_slug
				__('ZEIT ONLINE Frame Pulling API', 'zgffa'), // page_title
				__('ZON Frame API', 'zgffa'), // menu_title
				'manage_options', // capability
				self::$plugin_name, // menu_slug
				array( $this, 'options_page' ) // function
			);
		} else {
			add_options_page(
				__('ZEIT ONLINE Frame Pulling API', 'zgffa'), // page_title
				__('ZON Frame API', 'zgffa'), // menu_title
				'manage_options', // capability
				self::$plugin_name, // menu_slug
				array( $this, 'options_page' ) // function
			);
		}
	}

	/**
	 * Render administration page
	 *
	 * @return void
	 */
	public function options_page() {

		if ( isset( $_POST[ 'submit' ] ) && isset( $_POST[self::SETTINGS] ) ) {
			$updated = $this->update_options( $_POST[self::SETTINGS] );

			if ($updated) {
				add_settings_error(
					'zgffa_general_settings',
					'settings_updated',
					__('Options saved, please reload frame.', 'zgffa'),
					'updated'
				);
			}
		}

		if ( isset( $_POST[ 'reload' ] ) ) {
			$deleted = $this->delete_all_transients();
			$cached = $this->warm_up_frame_cache();

			if ($deleted) {
				add_settings_error(
					'zgffa_general_settings',
					'settings_updated',
					__('Cache deleted.', 'zgffa'),
					'updated'
				);
			} else {
				add_settings_error(
					'zgffa_general_settings',
					'failed',
					__('Deleting of cache failed.', 'zgffa'),
					'error'
				);
			}

			if ($cached) {
				add_settings_error(
					'zgffa_general_settings',
					'settings_updated',
					__('Updated frame saved.', 'zgffa'),
					'updated'
				);
			} else {
				add_settings_error(
					'zgffa_general_settings',
					'failed',
					__('Could not save frame.', 'zgffa'),
					'error'
				);
			}
		}

		?>
		<div class="wrap">
			<h2>Einstellungen › <?php echo esc_html( get_admin_page_title() ); ?></h2>
			<?php settings_errors(); ?>
			<form method="POST">
				<?php
				settings_fields( self::PREFIX . '_group' );
				do_settings_sections( self::$plugin_name );
				?>
				<p class="submit">
				<?php submit_button(null, 'primary', 'submit', false); ?>
				<?php submit_button(__('Delete cache and reload frame from API', 'zgffa'), 'secondary', 'reload', false); ?>
				</p>
			</form>
		</div>
		<?php
	}

	/**
	 * Reads in the html head from zeit.de framebuilder,
	 * removes the title tag (which is later added by WP or WP SEO again)
	 * and prints it to the template
	 *
	 * @return void
	 */
	public function print_html_head() {
		$header = $this->load_frame_data( 'html_head' );
		if ( $header ) {
			// get everything between the <head> and </head>
			$header = $this->get_string_after_single_tag( $header, '<head>' );
			// quickfix for launch of blogs, remove after '_trsf' suffix is obsolete
			$header = str_replace( '$handle: \'blogs\'', '$handle: \'blogs_trsf\'', $header );
			// remove title and charset
			$header = preg_replace( '#<title>.*?</title>|<meta charset=.+?>#isu', '', $header );
			print "\n<!-- ZON get frame head Start -->\n" . trim( $header ) . "\n<!-- ZON get frame head End -->\n";
		}
	}

	/**
	 * Reads the upper body from zeit.de framebuilder,
	 * makes changes to the user login navigation and prints to template
	 * @param  boolean $is_wrapped is the request inside the wrapper app
	 *
	 * @return void
	 */
	public function print_upper_body( $is_wrapped ) {
		$body = $this->load_frame_data( 'upper_body' );
		if ( $body ) {
			$body = preg_replace( '|<body .*?>|uim', '<body>', $body );
			$body = $this->get_string_after_single_tag( $body, '<body>' );
			$url = 'http://'.$_SERVER['SERVER_NAME'].$_SERVER['REQUEST_URI'];
			$sso_user_data = FALSE;

			if ( $is_wrapped ) {
				$body = preg_replace( '|<header.+?</header>|is', '', $body);
			}

			print "\n<!-- ZON get frame body Start -->\n" . $body . "\n<!-- ZON get frame body End -->\n";
		}
	}

	/**
	 * Reads the footer from zeit.de framebuilder and prints it to the template
	 *
	 * @return void
	 */
	public function print_footer() {
		$footer = $this->load_frame_data( 'lower_body' );
		$footer = str_replace( array( '</body>', '</html>' ), '', $footer );
		print "\n<!-- ZON get frame footer Start -->\n" . $footer . "\n<!-- ZON get frame footer End -->\n";
	}

	/**
	 * Load the supplied slice of framebuilder data from the cache or from the web and caches it
	 * @param  string $slice   part of the frame to load. [ 'html_head' | 'upper_body' | 'lower_body' ]
	 *
	 * @return string          the frame fragment
	 */
	public function load_frame_data( $slice ) {
		$params = $this->url_params( $slice );
		$url = self::$framebuilder_url . "?" . http_build_query( $params );
		$md5 = md5( $url );
		if ( false !== ( $content = $this->get_correct_transient( self::PREFIX . '_' . $md5 ) ) ) {
			return $content;
		}
		$result = wp_remote_get( $url );
		if( is_wp_error( $result ) ) {
			return "";
		}
		return $this->set_frame_cache_and_return_content( self::PREFIX . '_' . $md5, $result['body'] );
	}

	/**
	 * cache frame fragment into transient and return it
	 * @param string $name      md5-string to identify cache
	 * @param string $content   frame fragment
	 *
	 * @return string           $content is rereturned
	 */
	public function set_frame_cache_and_return_content( $name, $content ) {
		$options = $this->get_options();
		$cachetime = $options['ttl'] ? : self::$cachetime;
		$this->set_correct_transient( $name, $content, $cachetime );
		return $content;
	}

	/**
	 * return the rest string after a specific string in this case a tag
	 * @param  string $str    string of text
	 * @param  string $needle text after which text should be returned
	 *
	 * @return string         text after needle
	 */
	public function get_string_after_single_tag( $str, $needle ) {
		$arr = explode( $needle, $str );
		if( !empty( $arr ) ) {
			return array_pop( $arr );
		}
		return "";
	}

	/**
	 * Pick the correct set of url params to load frame from api
	 * @param  string $slice part of the frame to load, default "html_head", also pos- "upper_body", "lower_body"
	 *
	 * @return array         array of url params
	 */
	public function url_params( $slice='html_head' ) {
		$params = array( 'page_slice' => $slice );
		$ressort = mb_strtolower( get_option( 'zon_ressort_main' ) ?: 'blogs' );
		$params['ressort'] = $ressort;
		$params['ivw'] = 1;
		$params['meetrics'] = 1;
		$options = $this->get_options();
		if( isset( $options['ssl'] ) ) {
			$params['useSSL'] = 'true';
		}

		if ( get_option( 'zon_ads_deactivated' ) !== '1' ) {
			$params['banner_channel'] = $this->get_banner_channel();
		}

		include_once( ABSPATH . 'wp-admin/includes/plugin.php' );
		if ( ! is_plugin_active( 'z_auth/z_auth.php' ) ) {
			$params['loginstatus_enforced'] = 1;
		}

		return $params;
	}

	/**
	 * Get banner channel for the current blog
	 *
	 * @return string         banner-channel as needed for Framebuilder API
	 */
	public function get_banner_channel() {
		$ressort = get_option( 'zon_ressort_main', 'blogs' );
		$subressort = get_option( 'zon_ressort_sub', '' );
		$name = get_bloginfo( 'name' );
		$parts = array(
			mb_strtolower( $ressort ),
			mb_strtolower( $subressort ),
			self::format_webtrekk( $name ),
			'blogs'
		);

		return implode( '/', $parts );
	}

	/**
	 * Returns a string that is webtrekk-safe.
	 * This code does the same as sanitizeString in clicktracking.js
	 * @param  string $string
	 *
	 * @return string         sanitized string
	 */
	public static function format_webtrekk( $string ) {
		$search = array('ä', 'ö', 'ü', 'á', 'à', 'é', 'è', 'ß');
		$replace = array('ae', 'oe', 'ue', 'a', 'a', 'e', 'e', 'ss');

		$string = str_replace( $search, $replace, mb_strtolower( $string ) );
		$string = preg_replace('/[^-a-zA-Z0-9]/', '_', $string);
		$string = preg_replace('/_+/', '_', $string);
		$string = preg_replace('/^_|_$/', '_', $string);

		return $string;
	}

	/**
	 * Query all transients from the database and hand them to delete_transient
	 * use to immediatly delete all cached frames on request or as garbage collection
	 *
	 * @return bool
	 */
	public function delete_all_transients() {
		global $wpdb;
		$return_check = true;
		$table = is_multisite() ? $wpdb->sitemeta : $wpdb->options;
		$needle = is_multisite() ? 'meta_key' : 'option_name';
		$name_chunk = is_multisite() ? '_site_transient_' : '_transient_';
		$query = "
			SELECT `$needle`
			FROM `$table`
			WHERE `$needle`
			LIKE '%transient_" . self::PREFIX . "%'";
		$results = $wpdb->get_results( $query );
		foreach( $results as $result ) {
			$transient = str_replace( $name_chunk, '', $result->$needle );
			if ( ! $this->delete_correct_transient( $transient ) ) {
				$return_check = false;
			}
		}
		return $return_check;
	}

	/**
	 * Iterate through the blogs and update all frames
	 *
	 * @return bool
	 */
	public function warm_up_frame_cache() {
		global $wpdb;
		if( is_multisite() ) {
			$return_check = true;
			$old_blog = $wpdb->blogid;
			$blogids = $wpdb->get_col( "SELECT `blog_id` FROM {$wpdb->blogs}" );
			foreach ( $blogids as $blog_id) {
				switch_to_blog( $blog_id);
				$result = $this->load_all_slices();
				if ( $result === false ) $return_check = false;
			}
			switch_to_blog($old_blog);
			return $return_check;
		}
		return $this->load_all_slices();
	}

	/**
	 * Load all frame slices for one blog into transient
	 *
	 * @return bool
	 */
	public function load_all_slices() {
		$return_check = true;
		$slices = array( 'html_head', 'upper_body', 'lower_body' );
		foreach( $slices as $slice ) {
			$result = $this->load_frame_data( $slice );
			if ( $result === "" ) $return_check = false;
		}
		return $return_check;
	}

	/**
	 * Covers get_option for use with multisite wordpress
	 *
	 * @return mixed    The value set for the option.
	 */
	public function get_options() {
		$default = array( 'ttl' => self::$cachetime );

		if ( is_multisite() ) {
			return get_site_option( self::SETTINGS, $default );
		}

		return get_option( self::SETTINGS, $default );
	}

	/**
	 * Covers update_option for use with multisite wordpress
	 *
	 * @return bool    False if value was not updated and true if value was updated.
	 */
	public function update_options( $options ) {
		if ( is_multisite() ) {
			return update_site_option( self::SETTINGS, $options );
		}

		return update_option( self::SETTINGS, $options );
	}

	/**
	 * Use site transient if multisite environment
	 * @param string $transient  name of the transient
	 * @param mixed  $value      content to set as transient
	 * @param int    $expiration time in seconds for maximum cache time
	 *
	 * @return bool
	 */
	public function set_correct_transient( $transient, $value, $expiration ) {
		if ( is_multisite() ) {
			return set_site_transient( $transient, $value, $expiration );
		} else {
			return set_transient( $transient, $value, $expiration );
		}
	}

	/**
	 * Use site transient if multisite environment
	 * @param  string $transient name of the transient
	 *
	 * @return mixed             content stored in the transient or false if no adequate transient found
	 */
	public function get_correct_transient( $transient ) {
		if ( is_multisite() ) {
			return get_site_transient( $transient );
		} else {
			return get_transient( $transient );
		}
	}

	/**
	 * Use site transient if multisite environment
	 * @param  string $transient name of the transient to delete
	 *
	 * @return bool
	 */
	public function delete_correct_transient( $transient ) {
		if ( is_multisite() ) {
			return delete_site_transient( $transient );
		} else {
			return delete_transient( $transient );
		}
	}

	/**
	 * Add actions links on plugin page
	 * @since 2.3.3
	 * @param array $links
	 * @param string $file
	 * @return array $links
	 */
	public function add_action_links($links, $file) {
		if( basename( dirname( $file ) ) == self::$plugin_name ) {
			$url = esc_url( sprintf( 'options-general.php?page=%s', self::$plugin_name ) );
			links[] = '<a href="'. $url .'">'. __( 'Settings', 'zgffa' ) .'</a>';
		}
		return $links;
	}

}

register_activation_hook(__FILE__, array('ZON_Get_Frame_From_API', 'activate'));
register_deactivation_hook(__FILE__, array('ZON_Get_Frame_From_API', 'deactivate'));

// Instantiate our class
$ZON_Get_Frame_From_API = ZON_Get_Frame_From_API::getInstance();
