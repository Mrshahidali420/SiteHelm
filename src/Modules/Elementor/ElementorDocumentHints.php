<?php
/**
 * The conditional authoring hints one Elementor document read carries.
 *
 * @package SiteHelm
 */

declare(strict_types=1);

namespace SiteHelm\Modules\Elementor;

/**
 * The advice that is only sent when this page is actually in the state it
 * warns about.
 *
 * THE COMPLEMENT OF `ServerInstructions`, NOT A SECOND COPY OF IT. The server
 * instructions state the general rules once, at initialize, and by the time a
 * client is mid-build they are the oldest thing in its context. These arrive
 * attached to the read a client clones or rebuilds a page from, which is the
 * moment the advice is actionable, and they cannot be scrolled past because
 * they are part of the answer.
 *
 * EVERY HINT IS DETECTED, NEVER GUESSED. Each one below is emitted from a fact
 * this plugin has just read out of the database — the rendered layout, the
 * agreement of the two layout rows, the stored settings of the page's own
 * top-level containers. Advice that could not be checked belongs in
 * `ServerInstructions`, where it is paid for once, rather than here, where a
 * client would learn to ignore the member.
 *
 * THE VOCABULARY LIVES HERE AND NOWHERE ELSE, on `ElementorPageSettings`'s
 * rule. A code spelled in the emitter and again in the schema enum is two
 * spellings that drift, and a client filtering on the first would silently stop
 * matching. The message text is a constant for the same reason: the same advice
 * given two ways reads as two different problems.
 *
 * THE MEMBER IS ALWAYS PRESENT AND MAY BE EMPTY. A member that appears only
 * when there is something to say is one a client cannot tell from a member it
 * failed to parse, and an absent list and an empty list would then mean the
 * same thing by accident rather than by declaration.
 *
 * TERSE ON PURPOSE. These ride every document read, so their length is a cost
 * paid on every page a client looks at rather than once per session.
 *
 * @package SiteHelm
 */
final class ElementorDocumentHints {

	/**
	 * The response member carrying the hints.
	 */
	public const FIELD_HINTS = 'hints';

	/**
	 * The hint member a client matches on.
	 */
	public const FIELD_CODE = 'code';

	/**
	 * The hint member a client shows.
	 */
	public const FIELD_MESSAGE = 'message';

	/**
	 * The page renders with the theme's own chrome because nothing set a layout.
	 */
	public const CODE_LAYOUT_NOT_SET = 'layout-not-set';

	/**
	 * The two layout rows name different layouts.
	 */
	public const CODE_LAYOUT_DESYNCED = 'layout-desynced';

	/**
	 * A top-level container will inherit the kit's padding rather than run edge
	 * to edge.
	 */
	public const CODE_CONTAINER_KIT_PADDING = 'container-kit-padding';

	/**
	 * What `CODE_LAYOUT_NOT_SET` says.
	 */
	public const MESSAGE_LAYOUT_NOT_SET = 'This page\'s layout is "default", so WordPress renders it with the theme\'s header, footer and page title however its sections are styled. Call elementor-page-settings-set with a layout of canvas, headerFooter or theme to change that.';

	/**
	 * What `CODE_LAYOUT_DESYNCED` says.
	 */
	public const MESSAGE_LAYOUT_DESYNCED = 'Elementor\'s page-settings row and WordPress\'s page-template row name different layouts for this page. WordPress renders the one reported in pageSettings.layoutSync.inEffect; calling elementor-page-settings-set with a layout writes both rows.';

	/**
	 * What `CODE_CONTAINER_KIT_PADDING` says, given the count.
	 *
	 * ONE HINT CARRYING A COUNT, NEVER ONE HINT PER ELEMENT. A page built out of
	 * a dozen unpadded containers would otherwise push everything else in the
	 * member out of a client's attention with twelve copies of one sentence.
	 */
	public const MESSAGE_CONTAINER_KIT_PADDING = 'Top-level containers whose stored settings declare no padding: %d. Each inherits Elementor\'s kit default of 10px on all four sides and will not render full-bleed; set padding to 0 on any container meant to run edge to edge, or add such a container with elementor-element-add\'s preset "full-bleed", which stores that and full content width together.';

	/**
	 * Every code this member may carry, in the order they are emitted.
	 *
	 * @var string[]
	 */
	public const CODE_ORDER = [
		self::CODE_LAYOUT_NOT_SET,
		self::CODE_LAYOUT_DESYNCED,
		self::CODE_CONTAINER_KIT_PADDING,
	];

	/**
	 * The stored settings key a container's padding is held under.
	 *
	 * The name the element registry declares, which is why it carries no device
	 * suffix: Elementor stores `padding_tablet` beside it, and a container that
	 * sets only the tablet value still renders inset on a desktop.
	 */
	public const PADDING_KEY = 'padding';

	/**
	 * Not instantiable: the class is a named vocabulary and one pure emitter.
	 */
	private function __construct() {
	}

	// phpcs:disable WordPress.NamingConventions.ValidFunctionName.MethodNameInvalid -- The module vocabulary is camelCase across every class.

	/**
	 * The hints one document's own state earns, in `CODE_ORDER`.
	 *
	 * PURE, AND FED THE READS THE OPERATION HAS ALREADY MADE rather than making
	 * its own. A second read of the settings row could answer differently from
	 * the one the response reports, and a hint that disagreed with the member it
	 * refers to is worse than no hint.
	 *
	 * @param array<string, mixed> $page_settings The `ElementorPageSettings::report()` value.
	 * @param array[]              $tree          The raw stored element list.
	 *
	 * @return array<int, array<string, string>> The hints; empty when there is nothing to report.
	 */
	public static function forDocument( array $page_settings, array $tree ): array {
		$hints = [];
		$sync  = $page_settings[ ElementorPageSettings::FIELD_LAYOUT_SYNC ] ?? [];
		$sync  = is_array( $sync ) ? $sync : [];

		if ( ElementorPageSettings::LAYOUT_DEFAULT === ( $sync[ ElementorPageSettings::SYNC_IN_EFFECT ] ?? null ) ) {
			$hints[] = self::hint( self::CODE_LAYOUT_NOT_SET, self::MESSAGE_LAYOUT_NOT_SET );
		}

		if ( false === ( $sync[ ElementorPageSettings::SYNC_AGREE ] ?? true ) ) {
			$hints[] = self::hint( self::CODE_LAYOUT_DESYNCED, self::MESSAGE_LAYOUT_DESYNCED );
		}

		$unpadded = self::unpaddedContainers( $tree );

		if ( 0 < $unpadded ) {
			$hints[] = self::hint(
				self::CODE_CONTAINER_KIT_PADDING,
				sprintf( self::MESSAGE_CONTAINER_KIT_PADDING, $unpadded )
			);
		}

		return $hints;
	}

	/**
	 * How many top-level containers declare no padding of their own.
	 *
	 * TOP LEVEL ONLY. The kit default applies to a container at every depth, but
	 * only a top-level one is a candidate for running edge to edge — a nested
	 * container is inset by its parent whatever it declares — so counting the
	 * whole tree would fire this hint on every page ever built.
	 *
	 * A NODE WITH NO SETTINGS MEMBER AT ALL COUNTS. That is exactly the shape a
	 * container added with no settings has, and it is the one that renders
	 * inset.
	 *
	 * @param array[] $tree The raw stored element list.
	 *
	 * @return int The count.
	 */
	private static function unpaddedContainers( array $tree ): int {
		$count = 0;

		foreach ( $tree as $node ) {
			if ( ! is_array( $node ) || ElementorElementAddInput::EL_TYPE_CONTAINER !== ( $node[ ElementorSettingsMerge::NODE_EL_TYPE ] ?? null ) ) {
				continue;
			}

			$settings = $node[ ElementorPropCoercion::NODE_SETTINGS ] ?? null;

			if ( ! is_array( $settings ) || ! array_key_exists( self::PADDING_KEY, $settings ) ) {
				++$count;
			}
		}

		return $count;
	}
	// phpcs:enable WordPress.NamingConventions.ValidFunctionName.MethodNameInvalid

	/**
	 * One hint object.
	 *
	 * @param string $code    The code.
	 * @param string $message The message.
	 *
	 * @return array<string, string> The hint.
	 */
	private static function hint( string $code, string $message ): array {
		return [
			self::FIELD_CODE    => $code,
			self::FIELD_MESSAGE => $message,
		];
	}

	/**
	 * The declared schema for the member.
	 *
	 * @return array<string, mixed> The JSON Schema fragment.
	 */
	public static function schema(): array {
		return [
			'type'        => 'array',
			'description' => 'Authoring warnings this particular page has earned, each detected from what is actually stored rather than offered as general advice. Always present, and an empty list when this page has nothing to warn about.',
			'items'       => [
				'type'                 => 'object',
				'properties'           => [
					self::FIELD_CODE    => [
						'type'        => 'string',
						'enum'        => self::CODE_ORDER,
						'description' => 'Which condition was detected.',
					],
					self::FIELD_MESSAGE => [
						'type'        => 'string',
						'description' => 'What the condition means for this page and which operation changes it.',
					],
				],
				'required'             => [ self::FIELD_CODE, self::FIELD_MESSAGE ],
				'additionalProperties' => false,
			],
		];
	}
}
