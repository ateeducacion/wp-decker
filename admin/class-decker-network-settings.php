<?php
/**
 * Network settings for Decker in WordPress Multisite.
 *
 * @link       https://www3.gobiernodecanarias.org/medusa/ecoescuela/ate/
 *
 * @package    Decker
 * @subpackage Decker/admin
 */

// Exit if accessed directly.
defined( 'ABSPATH' ) || exit;

/**
 * Class Decker_Network_Settings
 *
 * Handles the network-level settings for Decker in a WordPress Multisite
 * environment, including the allowlist of sites permitted to activate the plugin.
 *
 * @since      1.0.0
 */
class Decker_Network_Settings {

	/**
	 * Network option name for the allowed sites list.
	 *
	 * @var string
	 */
	const OPTION_NAME = 'decker_network_allowed_sites';

	/**
	 * Constructor.
	 *
	 * Registers the network admin menu and settings save action.
	 */
	public function __construct() {
		add_action( 'network_admin_menu', array( $this, 'add_network_menu' ) );
		add_action( 'network_admin_edit_decker_network_settings', array( $this, 'save_network_settings' ) );
	}

	/**
	 * Add the Decker page to the Network Admin Settings menu.
	 */
	public function add_network_menu() {
		add_submenu_page(
			'settings.php',
			__( 'Decker Network Settings', 'decker' ),
			__( 'Decker', 'decker' ),
			'manage_network_options',
			'decker_network_settings',
			array( $this, 'render_network_settings_page' )
		);
	}

	/**
	 * Render the network settings page.
	 */
	public function render_network_settings_page() {
		if ( ! current_user_can( 'manage_network_options' ) ) {
			wp_die( esc_html__( 'You do not have sufficient permissions to access this page.', 'decker' ) );
		}

		$allowed_sites = get_site_option( self::OPTION_NAME, '' );
		?>
		<div class="wrap">
			<h1><?php esc_html_e( 'Decker Network Settings', 'decker' ); ?></h1>
			<?php if ( isset( $_GET['updated'] ) ) : // phpcs:ignore WordPress.Security.NonceVerification.Recommended ?>
				<div class="notice notice-success is-dismissible">
					<p><?php esc_html_e( 'Network settings saved.', 'decker' ); ?></p>
				</div>
			<?php endif; ?>
			<form method="post" action="edit.php?action=decker_network_settings">
				<?php wp_nonce_field( 'decker_network_settings_action', 'decker_network_settings_nonce' ); ?>
				<table class="form-table">
					<tr>
						<th scope="row">
							<label for="decker_allowed_sites">
								<?php esc_html_e( 'Allowed Site IDs', 'decker' ); ?>
							</label>
						</th>
						<td>
							<input
								type="text"
								id="decker_allowed_sites"
								name="decker_allowed_sites"
								value="<?php echo esc_attr( $allowed_sites ); ?>"
								class="regular-text"
								pattern="^[0-9]+(,[0-9]+)*$|^$"
								title="<?php esc_attr_e( 'Please enter comma-separated site IDs (numbers only)', 'decker' ); ?>"
							>
							<p class="description">
								<?php
								esc_html_e(
									'Enter comma-separated site IDs where Decker is permitted to be activated. Leave empty to allow activation on all sites.',
									'decker'
								);
								?>
							</p>
						</td>
					</tr>
				</table>
				<?php submit_button(); ?>
			</form>
		</div>
		<?php
	}

	/**
	 * Save the network settings and redirect back to the settings page.
	 */
	public function save_network_settings() {
		if ( ! current_user_can( 'manage_network_options' ) ) {
			wp_die( esc_html__( 'You do not have sufficient permissions to access this page.', 'decker' ) );
		}

		check_admin_referer( 'decker_network_settings_action', 'decker_network_settings_nonce' );

		$allowed_sites = isset( $_POST['decker_allowed_sites'] )
			? sanitize_text_field( wp_unslash( $_POST['decker_allowed_sites'] ) )
			: '';

		// Validate: keep only positive integer IDs.
		if ( ! empty( $allowed_sites ) ) {
			$ids       = array_filter( array_map( 'trim', explode( ',', $allowed_sites ) ) );
			$valid_ids = array();
			foreach ( $ids as $id ) {
				if ( is_numeric( $id ) && intval( $id ) > 0 ) {
					$valid_ids[] = intval( $id );
				}
			}
			$allowed_sites = implode( ',', $valid_ids );
		}

		update_site_option( self::OPTION_NAME, $allowed_sites );

		wp_redirect(
			add_query_arg(
				array(
					'page'    => 'decker_network_settings',
					'updated' => 'true',
				),
				network_admin_url( 'settings.php' )
			)
		);
		exit;
	}

	/**
	 * Get the list of allowed site IDs from the network option.
	 *
	 * Returns an empty array when no restriction is configured, meaning all
	 * sites are allowed.
	 *
	 * @return int[] Array of allowed site IDs. Empty array means no restriction.
	 */
	public static function get_allowed_sites() {
		$option = get_site_option( self::OPTION_NAME, '' );
		if ( empty( $option ) ) {
			return array();
		}
		return array_values(
			array_filter(
				array_map( 'intval', array_filter( array_map( 'trim', explode( ',', $option ) ) ) )
			)
		);
	}

	/**
	 * Check whether a given site is allowed to activate the plugin.
	 *
	 * When the allowlist is empty no restriction is enforced and every site
	 * is considered allowed (backward-compatible default).
	 *
	 * @param int $site_id The site ID to check.
	 * @return bool True if the site is allowed or no restriction is configured.
	 */
	public static function is_site_allowed( $site_id ) {
		$allowed_sites = self::get_allowed_sites();
		if ( empty( $allowed_sites ) ) {
			return true;
		}
		return in_array( (int) $site_id, $allowed_sites, true );
	}
}
