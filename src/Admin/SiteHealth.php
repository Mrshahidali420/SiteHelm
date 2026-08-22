<?php
/**
 * SiteHelm's entry in Tools → Site Health.
 *
 * @package SiteHelm
 */

declare(strict_types=1);

namespace SiteHelm\Admin;

/**
 * Tells Site Health whether a client's credentials can reach WordPress.
 *
 * Site Health is where an operator looks when "something is wrong" and
 * they do not yet know it is SiteHelm. The one thing worth saying there is
 * the thing they cannot see from any client: whether this server hands the
 * Authorization header to WordPress at all. The verdict is the same probe
 * the Status screen runs.
 *
 * @package SiteHelm
 */
final class SiteHealth {

	/**
	 * The test's key in the Site Health list.
	 */
	public const TEST = 'sitehelm_authorization_header';

	/**
	 * The probe behind the verdict.
	 *
	 * @var ConnectionProbe
	 */
	private ConnectionProbe $probe;

	/**
	 * Constructs the test.
	 *
	 * @param ConnectionProbe|null $probe The probe; null for the default.
	 */
	public function __construct( ?ConnectionProbe $probe = null ) {
		$this->probe = $probe ?? new ConnectionProbe();
	}

	/**
	 * Register the test with Site Health.
	 */
	public function register(): void {
		add_filter( 'site_status_tests', [ $this, 'add_test' ] );
	}

	/**
	 * Add the direct test to Site Health's list.
	 *
	 * @param array<string, mixed> $tests Site Health's tests, keyed by kind.
	 *
	 * @return array<string, mixed>
	 */
	public function add_test( array $tests ): array {
		if ( ! isset( $tests['direct'] ) || ! is_array( $tests['direct'] ) ) {
			$tests['direct'] = [];
		}

		$tests['direct'][ self::TEST ] = [
			'label' => __( 'SiteHelm can receive client credentials', 'sitehelm' ),
			'test'  => [ $this, 'run' ],
		];

		return $tests;
	}

	/**
	 * Run the probe and phrase the result the way Site Health expects.
	 *
	 * @return array<string, mixed>
	 */
	public function run(): array {
		$state  = $this->probe->run();
		$result = [
			'label'       => __( 'Client credentials reach WordPress', 'sitehelm' ),
			'status'      => 'good',
			'badge'       => [
				'label' => __( 'SiteHelm', 'sitehelm' ),
				'color' => 'blue',
			],
			'description' => '<p>' . esc_html__( 'This server passes the Authorization header through to WordPress, so a client signing in with a SiteHelm credential will be recognised.', 'sitehelm' ) . '</p>',
			'actions'     => '',
			'test'        => self::TEST,
		];

		if ( ConnectionProbe::STRIPPED === $state ) {
			$result['label']          = __( 'Client credentials are being stripped before they reach WordPress', 'sitehelm' );
			$result['status']         = 'critical';
			$result['badge']['color'] = 'red';
			$result['description']    = '<p>' . esc_html__( 'This server drops the Authorization header before WordPress sees it, so every client will be told its SiteHelm credentials are wrong. On Apache, add these lines to the top of .htaccess, above the WordPress block; on other servers, ask your host to pass the header through to PHP.', 'sitehelm' ) . '</p>'
				. '<pre><code>' . esc_html( ConnectionProbe::HEADER_FIX ) . '</code></pre>';
		} elseif ( ConnectionProbe::UNREACHABLE === $state ) {
			$result['label']          = __( 'Whether client credentials reach WordPress could not be tested', 'sitehelm' );
			$result['status']         = 'recommended';
			$result['badge']['color'] = 'orange';
			$result['description']    = '<p>' . esc_html__( 'This site could not reach its own SiteHelm endpoint to test the header. That is common on local and firewalled hosts and does not by itself mean clients will fail.', 'sitehelm' ) . '</p>';
		} elseif ( ConnectionProbe::SKIPPED === $state ) {
			$result['label']          = __( 'Application passwords are disabled, so no client can sign in to SiteHelm', 'sitehelm' );
			$result['status']         = 'critical';
			$result['badge']['color'] = 'red';
			$result['description']    = '<p>' . esc_html__( 'SiteHelm credentials are WordPress application passwords. Something on this site has turned them off; until they are back on, no client can sign in.', 'sitehelm' ) . '</p>';
		}

		$result['actions'] = sprintf(
			'<p><a href="%s">%s</a></p>',
			esc_url( admin_url( 'admin.php?page=' . AdminMenu::PAGE_STATUS ) ),
			esc_html__( 'Open SiteHelm Status', 'sitehelm' )
		);

		return $result;
	}
}
