<?php
/**
 * The Contact Form 7 provider: forms as the plugin stores them.
 *
 * @package SiteHelm
 */

declare(strict_types=1);

namespace SiteHelm\Modules\Forms;

/**
 * Reads Contact Form 7's forms from the store the plugin itself uses.
 *
 * A FORM IS A POST. Contact Form 7 keeps each form as a `wpcf7_contact_form`
 * post whose title is the form's name and whose template lives in the `_form`
 * post meta as plain text carrying form tags — `[text* your-name]`,
 * `[email your-email]` and the rest. This provider addresses that stored
 * contract only: no Contact Form 7 class or function is ever called, so the
 * read works identically in production and under the test doubles.
 *
 * THE SHORTCODE IS PART OF THE ANSWER because embedding a form is the one
 * thing a caller does with it (REQ-0084). Since 5.8 the plugin's own copy box
 * shows a hash-based id when the form has one stored (`_hash` meta, first
 * seven characters); this provider emits the same spelling, falling back to
 * the numeric id for a form saved before hashes existed.
 *
 * ENTRIES ARE NULL, NOT EMPTY. Contact Form 7 stores no submissions — each
 * entry is sent by mail and forgotten — so entries() answers null and
 * entriesNote() says why in a sentence. An empty array would claim the store
 * exists and merely holds nothing, which is a different (and wrong) statement.
 *
 * @package SiteHelm
 */
final class Cf7Provider implements FormsProvider {

	/**
	 * The enforced version floor. Modern Contact Form 7 has kept the
	 * `wpcf7_contact_form` post type and per-property post meta unchanged
	 * throughout the 5.x line, which is what this provider addresses.
	 */
	public const MIN_VERSION = '5.0';

	/**
	 * The post type Contact Form 7 stores each form under.
	 */
	public const POST_TYPE = 'wpcf7_contact_form';

	/**
	 * Form-tag types that render controls but name no field a submission
	 * carries, so they are not fields.
	 */
	private const NON_FIELD_TYPES = [ 'submit', 'response', 'count', 'recaptcha' ];

	/**
	 * The stable provider name.
	 */
	public function name(): string {
		return 'contact-form-7';
	}

	/**
	 * Whether Contact Form 7 is loaded at or above the floor.
	 */
	public function available(): bool {
		$version = $this->version();

		return null !== $version && version_compare( $version, self::MIN_VERSION, '>=' );
	}

	/**
	 * The loaded plugin version, or null when the plugin is absent.
	 */
	public function version(): ?string {
		if ( ! defined( 'WPCF7_VERSION' ) ) {
			return null;
		}

		$version = constant( 'WPCF7_VERSION' );

		return is_string( $version ) && '' !== $version ? $version : null;
	}

	/**
	 * Every form the plugin holds, oldest first.
	 *
	 * @return array[] Rows of {id, title, shortcode}.
	 */
	public function forms(): array {
		$posts = get_posts(
			[
				'post_type'   => self::POST_TYPE,
				'post_status' => 'any',
				'numberposts' => -1,
				'orderby'     => 'ID',
				'order'       => 'ASC',
			]
		);

		if ( ! is_array( $posts ) ) {
			return [];
		}

		$rows = [];

		foreach ( $posts as $post ) {
			if ( ! is_object( $post ) || ! isset( $post->ID ) ) {
				continue;
			}

			$form_id = (int) $post->ID;
			$rows[]  = [
				'id'        => $form_id,
				'title'     => (string) ( $post->post_title ?? '' ),
				'shortcode' => $this->shortcode( $form_id, (string) ( $post->post_title ?? '' ) ),
			];
		}

		return $rows;
	}

	/**
	 * One form with its parsed fields, or null when nothing matches.
	 *
	 * @param int $form_id The form identifier.
	 *
	 * @return array{id: int, title: string, shortcode: string, fields: array[]}|null
	 */
	public function form( int $form_id ): ?array {
		$post = get_post( $form_id );

		if ( ! is_object( $post ) || self::POST_TYPE !== (string) ( $post->post_type ?? '' ) ) {
			return null;
		}

		$title    = (string) ( $post->post_title ?? '' );
		$template = get_post_meta( $form_id, '_form', true );

		return [
			'id'        => $form_id,
			'title'     => $title,
			'shortcode' => $this->shortcode( $form_id, $title ),
			'fields'    => $this->fields( is_string( $template ) ? $template : '' ),
		];
	}

	/**
	 * Contact Form 7 stores no entries, so there are none to list.
	 *
	 * @param int $form_id The form identifier.
	 * @param int $limit   Maximum entries to return.
	 *
	 * @return array[]|null Always null: the plugin keeps no entry store.
	 */
	public function entries( int $form_id, int $limit ): ?array {
		unset( $form_id, $limit );

		return null;
	}

	// phpcs:disable WordPress.NamingConventions.ValidFunctionName.MethodNameInvalid -- CamelCase required by the FormsProvider interface.
	/**
	 * Why entries() answers null, as a sentence a site owner can act on.
	 */
	public function entriesNote(): ?string {
		return 'Contact Form 7 does not store submissions on the site; each entry is delivered by email when it is sent.';
	}
	// phpcs:enable WordPress.NamingConventions.ValidFunctionName.MethodNameInvalid

	/**
	 * The embed shortcode, spelled the way the plugin's own copy box spells it.
	 *
	 * @param int    $form_id The form identifier.
	 * @param string $title   The form title.
	 */
	private function shortcode( int $form_id, string $title ): string {
		$hash = get_post_meta( $form_id, '_hash', true );
		$slug = is_string( $hash ) && strlen( $hash ) >= 7 ? substr( $hash, 0, 7 ) : (string) $form_id;

		return sprintf( '[contact-form-7 id="%s" title="%s"]', $slug, $title );
	}

	/**
	 * The fields a stored form template declares, in template order.
	 *
	 * A form tag is `[type* name …]`: the trailing asterisk on the type marks
	 * the field required, and the first bare token after the type is the field
	 * name. A tag whose next token opens with a quote names no field ­— that is
	 * how `[submit "Send"]` shapes itself out — and the known control-only
	 * types are skipped by name as well, so a quirk of spacing cannot let one
	 * in.
	 *
	 * @param string $template The stored `_form` template.
	 *
	 * @return array[] Rows of {name, type, required}.
	 */
	private function fields( string $template ): array {
		if ( '' === $template ) {
			return [];
		}

		$matched = preg_match_all(
			'/\[([a-zA-Z][a-zA-Z0-9_-]*)(\*)?[ \t]+([a-zA-Z0-9][a-zA-Z0-9_:.-]*)[^\]]*\]/',
			$template,
			$matches,
			PREG_SET_ORDER
		);

		if ( false === $matched || 0 === $matched ) {
			return [];
		}

		$fields = [];

		foreach ( $matches as $match ) {
			$type = strtolower( $match[1] );

			if ( in_array( $type, self::NON_FIELD_TYPES, true ) ) {
				continue;
			}

			$fields[] = [
				'name'     => $match[3],
				'type'     => $type,
				'required' => '*' === $match[2],
			];
		}

		return $fields;
	}
}
