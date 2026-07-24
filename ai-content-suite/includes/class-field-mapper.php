<?php
defined( 'ABSPATH' ) || exit;

final class AICS_Field_Mapper {

	private const OPTION_MAP  = 'aics_field_map';
	private const OPTION_PAGE = 'aics-field-mapping';

	private static ?self $instance = null;

	public static function instance(): self {
		if ( null === self::$instance ) { self::$instance = new self(); }
		return self::$instance;
	}

	private function __construct() {
		add_action( 'admin_menu', [ $this, 'register_menu' ] );
		add_action( 'admin_post_aics_save_mapping', [ $this, 'handle_save' ] );
		add_action( 'wp_ajax_aics_get_acf_fields', [ $this, 'ajax_get_acf_fields' ] );
	}

	public function register_menu(): void {
		add_submenu_page(
			'aics-settings',
			__( 'Field Mapping', 'ai-content-suite' ),
			__( 'Field Mapping', 'ai-content-suite' ),
			'manage_woocommerce', self::OPTION_PAGE,
			[ $this, 'render_page' ]
		);
	}

	public function render_page(): void {
		$mapping      = $this->get_mapping();
		$slots        = $this->get_slots();
		$field_groups = $this->collect_all_fields();
		require AICS_DIR . 'admin/views/field-mapping.php';
	}

	public function handle_save(): void {
		check_admin_referer( 'aics_save_mapping' );
		if ( ! current_user_can( 'manage_woocommerce' ) ) {
			wp_die( esc_html__( 'Permission denied.', 'ai-content-suite' ) );
		}
		$posted  = $_POST['aics_mapping'] ?? [];
		$mapping = [];
		foreach ( $this->get_slots() as $slot => $label ) {
			if ( empty( $posted[ $slot ] ) ) { continue; }
			$mapping[ $slot ] = $this->parse_field_value( sanitize_text_field( wp_unslash( $posted[ $slot ] ) ) );
		}
		$custom = $_POST['aics_custom_meta'] ?? [];
		foreach ( $custom as $slot => $meta_key ) {
			$meta_key = sanitize_key( wp_unslash( $meta_key ) );
			if ( ! empty( $meta_key ) ) {
				$mapping[ $slot ] = [ 'type' => 'custom_meta', 'meta_key' => $meta_key ];
			}
		}
		update_option( self::OPTION_MAP, $mapping );
		wp_redirect( add_query_arg( [ 'page' => self::OPTION_PAGE, 'saved' => '1' ], admin_url( 'admin.php' ) ) );
		exit;
	}

	public function read( string $slot, int $post_id ): string {
		$dest = $this->get_mapping()[ $slot ] ?? null;
		if ( null === $dest ) { return ''; }
		return (string) $this->read_by_dest( $dest, $post_id );
	}

	public function write( string $slot, string $value, int $post_id ): bool {
		$dest = $this->get_mapping()[ $slot ] ?? null;
		if ( null === $dest ) { return false; }
		return $this->write_by_dest( $dest, $value, $post_id );
	}

	private function read_by_dest( array $dest, int $post_id ): string {
		switch ( $dest['type'] ) {
			case 'woo_native':
				$post = get_post( $post_id );
				if ( ! $post ) { return ''; }
				return match ( $dest['field'] ) {
					'post_title'   => $post->post_title,
					'post_content' => $post->post_content,
					'post_excerpt' => $post->post_excerpt,
					default        => '',
				};
			case 'acf':
				if ( function_exists( 'get_field' ) ) {
					$val = get_field( $dest['field_key'], $post_id );
					return is_string( $val ) ? $val : '';
				}
				return (string) get_post_meta( $post_id, $dest['field_name'], true );
			case 'seo_meta':
			case 'custom_meta':
				return (string) get_post_meta( $post_id, $dest['meta_key'], true );
		}
		return '';
	}

	private function write_by_dest( array $dest, string $value, int $post_id ): bool {
		switch ( $dest['type'] ) {
			case 'woo_native':
				$field = $dest['field']; $update = [ 'ID' => $post_id ];
				if ( $field === 'post_title' )       { $update['post_title']   = $value; }
				elseif ( $field === 'post_content' ) { $update['post_content'] = $value; }
				elseif ( $field === 'post_excerpt' ) { $update['post_excerpt'] = $value; }
				else { return false; }
				return ! is_wp_error( wp_update_post( $update ) );
			case 'acf':
				if ( function_exists( 'update_field' ) ) {
					return (bool) update_field( $dest['field_key'], $value, $post_id );
				}
				return (bool) update_post_meta( $post_id, $dest['field_name'], $value );
			case 'seo_meta':
			case 'custom_meta':
				return (bool) update_post_meta( $post_id, $dest['meta_key'], $value );
		}
		return false;
	}

	public function collect_all_fields(): array {
		$groups = [];
		$groups[ __( 'WooCommerce native', 'ai-content-suite' ) ] = [
			'woo|post_title'   => __( 'Product title (post_title)', 'ai-content-suite' ),
			'woo|post_excerpt' => __( 'Short description (post_excerpt)', 'ai-content-suite' ),
			'woo|post_content' => __( 'Long description (post_content)', 'ai-content-suite' ),
		];
		if ( function_exists( 'acf_get_field_groups' ) ) {
			$acf_fields   = [];
			$field_groups = acf_get_field_groups( [ 'post_type' => 'product' ] );
			foreach ( $field_groups as $group ) {
				$fields = acf_get_fields( $group['key'] );
				if ( ! $fields ) { continue; }
				foreach ( $fields as $field ) {
					if ( in_array( $field['type'], [ 'text', 'textarea', 'wysiwyg', 'email', 'url' ], true ) ) {
						$v = 'acf|' . $field['key'] . '|' . $field['name'];
						$acf_fields[$v] = '[ACF] ' . $group['title'] . ' > ' . $field['label'];
					}
				}
			}
			if ( $acf_fields ) {
				$groups[ __( 'ACF fields', 'ai-content-suite' ) ] = $acf_fields;
			}
		}
		$seo = $this->detect_seo_fields();
		if ( $seo ) { $groups[ __( 'SEO meta', 'ai-content-suite' ) ] = $seo; }
		return $groups;
	}

	private function detect_seo_fields(): array {
		if ( defined( 'RANK_MATH_VERSION' ) || class_exists( 'RankMath' ) ) {
			return [
				'seo|rank_math_title'         => '[RankMath] SEO Title',
				'seo|rank_math_description'   => '[RankMath] SEO Description',
				'seo|rank_math_focus_keyword' => '[RankMath] Focus Keyword',
			];
		}
		if ( defined( 'WPSEO_VERSION' ) || class_exists( 'WPSEO_Options' ) ) {
			return [
				'seo|_yoast_wpseo_title'    => '[Yoast] SEO Title',
				'seo|_yoast_wpseo_metadesc' => '[Yoast] Meta Description',
			];
		}
		return [];
	}

	public function get_mapping(): array {
		return (array) get_option( self::OPTION_MAP, [] );
	}

	public function get_slots(): array {
		return [
			'source_supplier_data'   => __( 'Source: Supplier data', 'ai-content-suite' ),
			'source_product_title'   => __( 'Source: Product title (input)', 'ai-content-suite' ),
			'dest_seo_title'         => __( 'Destination: SEO title', 'ai-content-suite' ),
			'dest_short_description' => __( 'Destination: Short description', 'ai-content-suite' ),
			'dest_long_description'  => __( 'Destination: Long description', 'ai-content-suite' ),
			'dest_custom_1'          => __( 'Destination: Custom field 1', 'ai-content-suite' ),
			'dest_custom_2'          => __( 'Destination: Custom field 2', 'ai-content-suite' ),
		];
	}

	private function parse_field_value( string $raw ): array {
		$parts = explode( '|', $raw, 3 ); $prefix = $parts[0] ?? '';
		if ( $prefix === 'woo' ) { return [ 'type' => 'woo_native', 'field' => $parts[1] ?? '' ]; }
		if ( $prefix === 'acf' ) { return [ 'type' => 'acf', 'field_key' => $parts[1] ?? '', 'field_name' => $parts[2] ?? '' ]; }
		if ( $prefix === 'seo' ) { return [ 'type' => 'seo_meta', 'meta_key' => $parts[1] ?? '' ]; }
		return [ 'type' => 'custom_meta', 'meta_key' => $raw ];
	}

	public function ajax_get_acf_fields(): void {
		check_ajax_referer( 'aics_admin', 'nonce' );
		if ( ! current_user_can( 'manage_woocommerce' ) ) { wp_send_json_error( 'Forbidden', 403 ); }
		wp_send_json_success( $this->collect_all_fields() );
	}
}
