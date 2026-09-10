<?php
/**
 * Plugin Name: Ice & Field Elementor Slide Scheduler
 * Description: Adds optional start and end display times to individual Elementor Pro Slides.
 * Version: 1.0.0
 * Author: Ice & Field
 * Text Domain: ice-field-elementor-slide-scheduler
 * Requires at least: 6.2
 * Requires PHP: 7.4
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

define( 'IFESS_VERSION', '1.0.0' );

final class IFESS_Elementor_Slide_Scheduler {
	private const SLIDES_CONTROL = 'slides';
	private const START_CONTROL  = 'ifess_display_start';
	private const END_CONTROL    = 'ifess_display_end';

	public static function init(): void {
		add_action( 'plugins_loaded', array( __CLASS__, 'register' ) );
	}

	public static function register(): void {
		if ( ! did_action( 'elementor/loaded' ) ) {
			add_action( 'admin_notices', array( __CLASS__, 'missing_elementor_notice' ) );
			return;
		}

		add_action(
			'elementor/element/slides/section_slides/before_section_end',
			array( __CLASS__, 'add_schedule_controls' )
		);
		add_action( 'elementor/frontend/widget/before_render', array( __CLASS__, 'filter_scheduled_slides' ) );
	}

	/**
	 * Add scheduling fields to Elementor Pro's existing Slides repeater.
	 *
	 * @param \Elementor\Element_Base $element Widget being configured.
	 */
	public static function add_schedule_controls( $element ): void {
		if ( 'slides' !== $element->get_name() ) {
			return;
		}

		$control = $element->get_controls( self::SLIDES_CONTROL );
		if ( empty( $control['fields'] ) || ! is_array( $control['fields'] ) ) {
			return;
		}

		foreach ( $control['fields'] as $field ) {
			if ( isset( $field['name'] ) && self::START_CONTROL === $field['name'] ) {
				return;
			}
		}

		$control['fields'][] = array(
			'name'        => self::START_CONTROL,
			'label'       => esc_html__( 'Start Display On', 'ice-field-elementor-slide-scheduler' ),
			'type'        => \Elementor\Controls_Manager::DATE_TIME,
			'label_block' => true,
			'separator'   => 'before',
			'description' => esc_html__( 'Leave blank to display immediately. Uses the WordPress site timezone.', 'ice-field-elementor-slide-scheduler' ),
		);

		$control['fields'][] = array(
			'name'        => self::END_CONTROL,
			'label'       => esc_html__( 'End Display On', 'ice-field-elementor-slide-scheduler' ),
			'type'        => \Elementor\Controls_Manager::DATE_TIME,
			'label_block' => true,
			'description' => esc_html__( 'Leave blank to keep displaying indefinitely. Uses the WordPress site timezone.', 'ice-field-elementor-slide-scheduler' ),
		);

		$element->update_control( self::SLIDES_CONTROL, $control );
	}

	/**
	 * Remove slides outside their display window immediately before rendering.
	 * Scheduled and expired slides remain visible and editable in Elementor.
	 *
	 * @param \Elementor\Element_Base $widget Widget being rendered.
	 */
	public static function filter_scheduled_slides( $widget ): void {
		if ( 'slides' !== $widget->get_name() || self::is_elementor_editor() ) {
			return;
		}

		$slides = $widget->get_settings( self::SLIDES_CONTROL );
		if ( ! is_array( $slides ) || empty( $slides ) ) {
			return;
		}

		$now    = new DateTimeImmutable( 'now', wp_timezone() );
		$active = array_values(
			array_filter(
				$slides,
				static function ( $slide ) use ( $now ): bool {
					if ( ! is_array( $slide ) ) {
						return false;
					}

					$start = self::parse_datetime( $slide[ self::START_CONTROL ] ?? '' );
					$end   = self::parse_datetime( $slide[ self::END_CONTROL ] ?? '' );

					if ( $start && $now < $start ) {
						return false;
					}

					if ( $end && $now >= $end ) {
						return false;
					}

					return true;
				}
			)
		);

		$widget->set_settings( self::SLIDES_CONTROL, $active );
	}

	private static function parse_datetime( $value ): ?DateTimeImmutable {
		if ( ! is_string( $value ) || '' === trim( $value ) ) {
			return null;
		}

		$timezone = wp_timezone();
		$formats  = array( 'Y-m-d H:i', 'Y-m-d H:i:s', DateTimeInterface::ATOM );

		foreach ( $formats as $format ) {
			$date = DateTimeImmutable::createFromFormat( '!' . $format, trim( $value ), $timezone );
			if ( false !== $date ) {
				return $date;
			}
		}

		try {
			return new DateTimeImmutable( trim( $value ), $timezone );
		} catch ( Exception $exception ) {
			return null;
		}
	}

	private static function is_elementor_editor(): bool {
		return isset( \Elementor\Plugin::$instance->editor )
			&& \Elementor\Plugin::$instance->editor->is_edit_mode();
	}

	public static function missing_elementor_notice(): void {
		if ( ! current_user_can( 'activate_plugins' ) ) {
			return;
		}
		?>
		<div class="notice notice-warning"><p>
			<?php esc_html_e( 'Ice & Field Elementor Slide Scheduler requires Elementor and the Elementor Pro Slides widget.', 'ice-field-elementor-slide-scheduler' ); ?>
		</p></div>
		<?php
	}
}

IFESS_Elementor_Slide_Scheduler::init();
