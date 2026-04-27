<?php

declare(strict_types=1);

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

// ── Hooks ────────────────────────────────────────────────────────────────────

add_action( 'add_meta_boxes',            'gq_ads_register_meta_boxes' );
add_action( 'admin_enqueue_scripts',     'gq_ads_admin_enqueue_styles' );

// Campaign / targeting fields (always saved, regardless of ACF).
add_action( 'save_post_gq_ad_group',     'gq_ads_save_group_meta' );
add_action( 'save_post_gq_ad_placement', 'gq_ads_save_placement_meta' );

// Native field save handlers — only registered when ACF is absent.
if ( ! function_exists( 'acf' ) ) {
	add_action( 'save_post_gq_ad_group', 'gq_ads_save_group_relations' );
	add_action( 'save_post_gq_ad',       'gq_ads_save_ad_settings' );
}

// Object cache flush on related post saves and deletions.
add_action( 'save_post_gq_ad_group',     'gq_ads_flush_group_cache' );
add_action( 'save_post_gq_ad_placement', 'gq_ads_flush_placement_cache' );
add_action( 'save_post_gq_ad',           'gq_ads_flush_ad_cache' );
add_action( 'before_delete_post',        'gq_ads_flush_any_cache' );

// ── Cache flush helpers ───────────────────────────────────────────────────────

function gq_ads_flush_group_cache( int $post_id ): void {
	$raw_placement = get_post_meta( $post_id, 'gq_ad_placement', true );
	$placement_id  = is_array( $raw_placement ) ? (int) ( $raw_placement[0] ?? 0 ) : (int) $raw_placement;
	if ( $placement_id > 0 ) {
		wp_cache_delete( 'groups_for_' . $placement_id, 'gq_ads' );
	}
	wp_cache_delete( 'ads_for_' . $post_id, 'gq_ads' );
}

function gq_ads_flush_placement_cache( int $post_id ): void {
	wp_cache_delete( 'groups_for_' . $post_id, 'gq_ads' );
}

function gq_ads_flush_ad_cache( int $post_id ): void {
	$raw_groups = get_post_meta( $post_id, 'gq_ad_group', true );
	$group_ids  = is_array( $raw_groups ) ? $raw_groups : [ $raw_groups ];
	foreach ( $group_ids as $gid ) {
		$gid = (int) $gid;
		if ( $gid > 0 ) {
			wp_cache_delete( 'ads_for_' . $gid, 'gq_ads' );
		}
	}
	delete_transient( 'gq_ads_adsense_publisher_id' );
}

function gq_ads_flush_any_cache( int $post_id ): void {
	if ( function_exists( 'wp_cache_flush_group' ) ) {
		wp_cache_flush_group( 'gq_ads' );
	}
	delete_transient( 'gq_ads_adsense_publisher_id' );
}

// ── Admin asset enqueue ───────────────────────────────────────────────────────

function gq_ads_admin_enqueue_styles( string $hook ): void {
	$screen = get_current_screen();
	if ( ! $screen ) {
		return;
	}

	$load_on = [ 'gq_ad_group', 'gq_ad_placement', 'gq_ad' ];

	if ( in_array( $screen->post_type, $load_on, true ) || $hook === 'gq_ad_page_gq-ads-stats' ) {
		wp_enqueue_style(
			'gq-ads-admin',
			GQ_ADS_PLUGIN_URL . 'assets/css/admin.css',
			[],
			GQ_ADS_VERSION
		);
	}

	// Media uploader for the ad image picker (native meta box only, no ACF).
	if ( $screen->post_type === 'gq_ad' && ! function_exists( 'acf' ) ) {
		wp_enqueue_media();
	}

	// Chart.js — stats page only, loaded in footer.
	if ( $hook === 'gq_ad_page_gq-ads-stats' ) {
		wp_enqueue_script(
			'gq-ads-chart',
			GQ_ADS_PLUGIN_URL . 'assets/js/chart.min.js',
			[],
			GQ_ADS_VERSION,
			true
		);
	}
}

// ── Meta box registration ─────────────────────────────────────────────────────

function gq_ads_register_meta_boxes(): void {
	// Campaign & Targeting — always shown regardless of ACF.
	add_meta_box(
		'gq_group_campaign',
		__( 'Campaign & Targeting', 'gq-ads' ),
		'gq_ads_group_meta_box_html',
		'gq_ad_group',
		'normal',
		'high'
	);

	// Fallback Ad — always shown.
	add_meta_box(
		'gq_placement_fallback',
		__( 'Fallback Ad', 'gq-ads' ),
		'gq_ads_placement_meta_box_html',
		'gq_ad_placement',
		'normal',
		'default'
	);

	// When ACF is absent, provide native UI for the relationship/weight fields.
	if ( ! function_exists( 'acf' ) ) {
		add_meta_box(
			'gq_group_relations',
			__( 'Placement & Weight', 'gq-ads' ),
			'gq_ads_group_relations_meta_box_html',
			'gq_ad_group',
			'side',
			'high'
		);

		add_meta_box(
			'gq_ad_settings',
			__( 'Ad Settings', 'gq-ads' ),
			'gq_ads_ad_settings_meta_box_html',
			'gq_ad',
			'normal',
			'high'
		);
	}
}

// ── Campaign & Targeting meta box (gq_ad_group) ───────────────────────────────

function gq_ads_group_meta_box_html( WP_Post $post ): void {
	wp_nonce_field( 'gq_ads_group_meta_save', 'gq_ads_group_nonce' );

	$start_date = (string) get_post_meta( $post->ID, 'gq_group_start_date', true );
	$end_date   = (string) get_post_meta( $post->ID, 'gq_group_end_date', true );
	$exclusive  = get_post_meta( $post->ID, 'gq_group_exclusive', true );
	$saved_cats = array_map( 'intval', (array) get_post_meta( $post->ID, 'gq_group_categories', true ) );
	$all_cats   = get_categories( [ 'hide_empty' => false, 'orderby' => 'name' ] );
	?>
	<div class="gq-mb-row">
		<div class="gq-mb-col">
			<label for="gq_group_start_date"><?php esc_html_e( 'Campaign Start', 'gq-ads' ); ?></label>
			<input type="date" id="gq_group_start_date" name="gq_group_start_date"
			       value="<?php echo esc_attr( $start_date ); ?>">
			<p class="description"><?php esc_html_e( 'Leave blank for always-on.', 'gq-ads' ); ?></p>
		</div>
		<div class="gq-mb-col">
			<label for="gq_group_end_date"><?php esc_html_e( 'Campaign End', 'gq-ads' ); ?></label>
			<input type="date" id="gq_group_end_date" name="gq_group_end_date"
			       value="<?php echo esc_attr( $end_date ); ?>">
			<p class="description"><?php esc_html_e( 'Leave blank for always-on.', 'gq-ads' ); ?></p>
		</div>
	</div>

	<div class="gq-mb-exclusive">
		<label>
			<input type="checkbox" name="gq_group_exclusive" value="1" <?php checked( $exclusive, '1' ); ?>>
			<strong><?php esc_html_e( '100% Share of Voice', 'gq-ads' ); ?></strong>
			— <?php esc_html_e( 'This group receives all impressions, overriding normal rotation.', 'gq-ads' ); ?>
		</label>
		<div class="gq-mb-notice"><?php esc_html_e( 'Use for guaranteed/premium campaigns where one advertiser owns the placement entirely.', 'gq-ads' ); ?></div>
	</div>

	<p><strong><?php esc_html_e( 'Category Targeting', 'gq-ads' ); ?></strong></p>
	<p class="description" style="margin-bottom:6px;"><?php esc_html_e( 'Tick categories to restrict this group to matching posts/archives. Leave all unticked to show on every page (global).', 'gq-ads' ); ?></p>
	<div class="gq-cat-grid">
		<?php if ( empty( $all_cats ) ) : ?>
			<em><?php esc_html_e( 'No categories found.', 'gq-ads' ); ?></em>
		<?php else : ?>
			<?php foreach ( $all_cats as $cat ) : ?>
			<label>
				<input type="checkbox" name="gq_group_categories[]"
				       value="<?php echo esc_attr( $cat->term_id ); ?>"
				       <?php checked( in_array( $cat->term_id, $saved_cats, true ) ); ?>>
				<?php echo esc_html( $cat->name ); ?>
			</label>
			<?php endforeach; ?>
		<?php endif; ?>
	</div>
	<?php
}

// ── Fallback Ad meta box (gq_ad_placement) ────────────────────────────────────

function gq_ads_placement_meta_box_html( WP_Post $post ): void {
	wp_nonce_field( 'gq_ads_placement_meta_save', 'gq_ads_placement_nonce' );

	$fallback = (string) get_post_meta( $post->ID, 'gq_placement_google_fallback', true );
	?>
	<p class="description" style="margin-bottom:8px;">
		<?php esc_html_e( 'HTML or script to show when no active targeted group matches this placement. Typically a Google Ads unit or an in-house fallback creative.', 'gq-ads' ); ?>
	</p>
	<textarea name="gq_placement_google_fallback" rows="7"
	          style="width:100%;font-family:monospace;font-size:12px;"><?php echo esc_textarea( $fallback ); ?></textarea>
	<?php
}

// ── Placement & Weight meta box (gq_ad_group, native — no ACF) ───────────────

function gq_ads_group_relations_meta_box_html( WP_Post $post ): void {
	wp_nonce_field( 'gq_ads_group_relations_save', 'gq_ads_group_relations_nonce' );

	$placement_id = (int) get_post_meta( $post->ID, 'gq_ad_placement', true );
	$weight       = max( 1, (int) ( get_post_meta( $post->ID, 'gq_group_weight', true ) ?: 50 ) );

	$placements = get_posts( [
		'post_type'              => 'gq_ad_placement',
		'post_status'            => 'publish',
		'posts_per_page'         => -1,
		'no_found_rows'          => true,
		'update_post_meta_cache' => false,
		'update_post_term_cache' => false,
		'orderby'                => 'title',
		'order'                  => 'ASC',
	] );
	?>
	<p>
		<label for="gq_group_placement_select"><strong><?php esc_html_e( 'Placement', 'gq-ads' ); ?></strong></label><br>
		<select id="gq_group_placement_select" name="gq_group_placement_select" style="width:100%;margin-top:4px;">
			<option value="0"><?php esc_html_e( '— Select Placement —', 'gq-ads' ); ?></option>
			<?php foreach ( $placements as $p ) : ?>
			<option value="<?php echo esc_attr( $p->ID ); ?>" <?php selected( $placement_id, $p->ID ); ?>>
				<?php echo esc_html( $p->post_title ); ?>
			</option>
			<?php endforeach; ?>
		</select>
		<?php if ( empty( $placements ) ) : ?>
			<em class="description"><?php esc_html_e( 'No placements found. Create ad placements first.', 'gq-ads' ); ?></em>
		<?php endif; ?>
	</p>
	<p>
		<label for="gq_group_weight_input"><strong><?php esc_html_e( 'Weight', 'gq-ads' ); ?></strong></label><br>
		<input type="number" id="gq_group_weight_input" name="gq_group_weight_input"
		       value="<?php echo esc_attr( $weight ); ?>" min="1" max="100" style="width:70px;margin-top:4px;">
		<p class="description"><?php esc_html_e( 'Rotation weight within the placement (1–100, default 50).', 'gq-ads' ); ?></p>
	</p>
	<?php
}

// ── Ad Settings meta box (gq_ad, native — no ACF) ────────────────────────────

function gq_ads_ad_settings_meta_box_html( WP_Post $post ): void {
	wp_nonce_field( 'gq_ads_ad_settings_save', 'gq_ads_ad_settings_nonce' );

	// Resolve ad type through the enum — defaults to HTML/JS if meta is empty or unrecognised.
	$ad_type    = GQ_Ad_Type::fromMeta( get_post_meta( $post->ID, 'gq_ad_type', true ) ) ?? GQ_Ad_Type::HTML;
	$ad_code    = (string) get_post_meta( $post->ID, 'gq_ad_code', true );
	$image_id   = (int) get_post_meta( $post->ID, 'gq_ad_image', true );
	$target_url = (string) get_post_meta( $post->ID, 'gq_target_url', true );
	$weight     = max( 1, (int) ( get_post_meta( $post->ID, 'gq_ad_weight', true ) ?: 50 ) );
	$image_url  = $image_id > 0 ? ( wp_get_attachment_image_url( $image_id, 'thumbnail' ) ?: '' ) : '';

	$raw_groups      = get_post_meta( $post->ID, 'gq_ad_group', true );
	$assigned_groups = array_map( 'intval', is_array( $raw_groups ) ? $raw_groups : array_filter( [ (int) $raw_groups ] ) );

	$all_groups = get_posts( [
		'post_type'              => 'gq_ad_group',
		'post_status'            => 'publish',
		'posts_per_page'         => -1,
		'no_found_rows'          => true,
		'update_post_meta_cache' => false,
		'update_post_term_cache' => false,
		'orderby'                => 'title',
		'order'                  => 'ASC',
	] );

	$is_image = $ad_type === GQ_Ad_Type::IMAGE_LINK;
	?>
	<table class="form-table" role="presentation">
		<tr>
			<th><?php esc_html_e( 'Ad Type', 'gq-ads' ); ?></th>
			<td>
				<?php foreach ( GQ_Ad_Type::cases() as $case ) : ?>
				<label style="display:block;margin-bottom:4px;">
					<input type="radio" name="gq_ad_type" value="<?php echo esc_attr( $case->value ); ?>"
					       <?php checked( $ad_type->value, $case->value ); ?> id="gq-type-<?php echo esc_attr( $case->value ); ?>">
					<?php echo esc_html( $case->label() ); ?>
				</label>
				<?php endforeach; ?>
			</td>
		</tr>

		<tr id="gq-row-code" style="<?php echo esc_attr( $is_image ? 'display:none' : '' ); ?>">
			<th><label for="gq_ad_code"><?php esc_html_e( 'Ad Code', 'gq-ads' ); ?></label></th>
			<td>
				<textarea id="gq_ad_code" name="gq_ad_code" rows="7"
				          style="width:100%;font-family:monospace;font-size:12px;"><?php echo esc_textarea( $ad_code ); ?></textarea>
				<p class="description"><?php esc_html_e( 'Paste the full AdSense snippet or custom HTML/JS code.', 'gq-ads' ); ?></p>
			</td>
		</tr>

		<tr id="gq-row-image" style="<?php echo ! $is_image ? 'display:none' : ''; ?>">
			<th><?php esc_html_e( 'Ad Image', 'gq-ads' ); ?></th>
			<td>
				<input type="hidden" name="gq_ad_image" id="gq_ad_image_id"
				       value="<?php echo esc_attr( $image_id ?: '' ); ?>">
				<div id="gq-ad-image-preview" style="margin-bottom:8px;">
					<?php if ( $image_url ) : ?>
						<img src="<?php echo esc_url( $image_url ); ?>"
						     style="max-width:150px;height:auto;display:block;">
					<?php endif; ?>
				</div>
				<button type="button" class="button" id="gq-btn-image-select">
					<?php esc_html_e( 'Select Image', 'gq-ads' ); ?>
				</button>
				<button type="button" class="button" id="gq-btn-image-remove"
				        style="<?php echo esc_attr( ! $image_id ? 'display:none' : '' ); ?>">
					<?php esc_html_e( 'Remove', 'gq-ads' ); ?>
				</button>
			</td>
		</tr>

		<tr id="gq-row-url" style="<?php echo esc_attr( ! $is_image ? 'display:none' : '' ); ?>">
			<th><label for="gq_target_url"><?php esc_html_e( 'Click-through URL', 'gq-ads' ); ?></label></th>
			<td>
				<input type="url" id="gq_target_url" name="gq_target_url"
				       value="<?php echo esc_attr( $target_url ); ?>" class="large-text">
			</td>
		</tr>

		<tr>
			<th><label for="gq_ad_weight_input"><?php esc_html_e( 'Weight', 'gq-ads' ); ?></label></th>
			<td>
				<input type="number" id="gq_ad_weight_input" name="gq_ad_weight_input"
				       value="<?php echo esc_attr( $weight ); ?>" min="1" max="100" style="width:70px;">
				<p class="description"><?php esc_html_e( 'Rotation weight within the group (1–100, default 50).', 'gq-ads' ); ?></p>
			</td>
		</tr>

		<tr>
			<th><?php esc_html_e( 'Ad Groups', 'gq-ads' ); ?></th>
			<td>
				<?php if ( empty( $all_groups ) ) : ?>
					<em><?php esc_html_e( 'No groups found. Create ad groups first.', 'gq-ads' ); ?></em>
				<?php else : ?>
					<?php foreach ( $all_groups as $g ) : ?>
					<label style="display:block;margin-bottom:3px;">
						<input type="checkbox" name="gq_ad_group_ids[]"
						       value="<?php echo esc_attr( $g->ID ); ?>"
						       <?php checked( in_array( $g->ID, $assigned_groups, true ) ); ?>>
						<?php echo esc_html( $g->post_title ); ?>
					</label>
					<?php endforeach; ?>
				<?php endif; ?>
				<p class="description"><?php esc_html_e( 'Assign this ad to one or more groups.', 'gq-ads' ); ?></p>
			</td>
		</tr>
	</table>

	<script>
	(function () {
		const radios    = document.querySelectorAll('input[name="gq_ad_type"]');
		const rowCode   = document.getElementById('gq-row-code');
		const rowImage  = document.getElementById('gq-row-image');
		const rowUrl    = document.getElementById('gq-row-url');

		/** @param {HTMLElement|null} el @param {boolean} visible */
		const setVisible = (el, visible) => { if (el) el.style.display = visible ? '' : 'none'; };

		const toggleRows = (type) => {
			const isImage = type === 'image_link';
			setVisible(rowCode,  !isImage);
			setVisible(rowImage,  isImage);
			setVisible(rowUrl,    isImage);
		};

		radios.forEach((r) => r.addEventListener('change', (e) => toggleRows(e.target.value)));

		// Media library uploader.
		const imageIdInput = /** @type {HTMLInputElement|null}  */ (document.getElementById('gq_ad_image_id'));
		const imagePreview = document.getElementById('gq-ad-image-preview');
		const btnRemove    = document.getElementById('gq-btn-image-remove');

		document.getElementById('gq-btn-image-select')?.addEventListener('click', () => {
			const frame = wp.media({
				title:    <?php echo wp_json_encode( __( 'Select Ad Image', 'gq-ads' ) ); ?>,
				button:   { text: <?php echo wp_json_encode( __( 'Use this image', 'gq-ads' ) ); ?> },
				multiple: false,
				library:  { type: 'image' },
			});
			frame.on('select', () => {
				if (!imageIdInput || !imagePreview) return;

				const att = frame.state().get('selection').first().toJSON();
				imageIdInput.value = String(att.id);

				// Use createElement — avoids innerHTML with variable URL content.
				const img = document.createElement('img');
				img.src = att.sizes?.thumbnail?.url ?? att.url;
				img.style.cssText = 'max-width:150px;height:auto;display:block;';
				imagePreview.replaceChildren(img);

				setVisible(btnRemove, true);
			});
			frame.open();
		});

		btnRemove?.addEventListener('click', () => {
			if (imageIdInput) imageIdInput.value = '';
			if (imagePreview) imagePreview.replaceChildren();
			setVisible(btnRemove, false);
		});
	})();
	</script>
	<?php
}

// ── Save handlers ─────────────────────────────────────────────────────────────

function gq_ads_save_group_meta( int $post_id ): void {
	if ( ! isset( $_POST['gq_ads_group_nonce'] )
	     || ! wp_verify_nonce( sanitize_text_field( wp_unslash( $_POST['gq_ads_group_nonce'] ) ), 'gq_ads_group_meta_save' ) ) {
		return;
	}
	if ( defined( 'DOING_AUTOSAVE' ) && DOING_AUTOSAVE ) {
		return;
	}
	if ( ! current_user_can( 'edit_post', $post_id ) ) {
		return;
	}

	$start = sanitize_text_field( wp_unslash( $_POST['gq_group_start_date'] ?? '' ) );
	if ( $start && preg_match( '/^\d{4}-\d{2}-\d{2}$/', $start ) && strtotime( $start ) ) {
		update_post_meta( $post_id, 'gq_group_start_date', $start );
	} else {
		delete_post_meta( $post_id, 'gq_group_start_date' );
	}

	$end = sanitize_text_field( wp_unslash( $_POST['gq_group_end_date'] ?? '' ) );
	if ( $end && preg_match( '/^\d{4}-\d{2}-\d{2}$/', $end ) && strtotime( $end ) ) {
		update_post_meta( $post_id, 'gq_group_end_date', $end );
	} else {
		delete_post_meta( $post_id, 'gq_group_end_date' );
	}

	update_post_meta( $post_id, 'gq_group_exclusive', isset( $_POST['gq_group_exclusive'] ) ? '1' : '0' );

	$cats = [];
	if ( ! empty( $_POST['gq_group_categories'] ) && is_array( $_POST['gq_group_categories'] ) ) {
		$cats = array_values( array_filter( array_map( 'intval', wp_unslash( $_POST['gq_group_categories'] ) ) ) );
	}
	update_post_meta( $post_id, 'gq_group_categories', $cats );
}

function gq_ads_save_group_relations( int $post_id ): void {
	if ( ! isset( $_POST['gq_ads_group_relations_nonce'] )
	     || ! wp_verify_nonce( sanitize_text_field( wp_unslash( $_POST['gq_ads_group_relations_nonce'] ) ), 'gq_ads_group_relations_save' ) ) {
		return;
	}
	if ( defined( 'DOING_AUTOSAVE' ) && DOING_AUTOSAVE ) {
		return;
	}
	if ( ! current_user_can( 'edit_post', $post_id ) ) {
		return;
	}

	$placement_id = (int) ( $_POST['gq_group_placement_select'] ?? 0 );
	if ( $placement_id > 0 ) {
		update_post_meta( $post_id, 'gq_ad_placement', $placement_id );
	} else {
		delete_post_meta( $post_id, 'gq_ad_placement' );
	}

	$weight = max( 1, min( 100, (int) ( $_POST['gq_group_weight_input'] ?? 50 ) ) );
	update_post_meta( $post_id, 'gq_group_weight', $weight );
}

function gq_ads_save_placement_meta( int $post_id ): void {
	if ( ! isset( $_POST['gq_ads_placement_nonce'] )
	     || ! wp_verify_nonce( sanitize_text_field( wp_unslash( $_POST['gq_ads_placement_nonce'] ) ), 'gq_ads_placement_meta_save' ) ) {
		return;
	}
	if ( defined( 'DOING_AUTOSAVE' ) && DOING_AUTOSAVE ) {
		return;
	}
	if ( ! current_user_can( 'edit_post', $post_id ) ) {
		return;
	}

	$raw      = isset( $_POST['gq_placement_google_fallback'] ) ? wp_unslash( $_POST['gq_placement_google_fallback'] ) : '';
	$fallback = current_user_can( 'unfiltered_html' ) ? $raw : wp_kses_post( $raw );
	update_post_meta( $post_id, 'gq_placement_google_fallback', $fallback );
}

function gq_ads_save_ad_settings( int $post_id ): void {
	if ( ! isset( $_POST['gq_ads_ad_settings_nonce'] )
	     || ! wp_verify_nonce( sanitize_text_field( wp_unslash( $_POST['gq_ads_ad_settings_nonce'] ) ), 'gq_ads_ad_settings_save' ) ) {
		return;
	}
	if ( defined( 'DOING_AUTOSAVE' ) && DOING_AUTOSAVE ) {
		return;
	}
	if ( ! current_user_can( 'edit_post', $post_id ) ) {
		return;
	}

	// Ad type — validate through enum; fallback to HTML/JS.
	$ad_type = GQ_Ad_Type::tryFrom( sanitize_key( $_POST['gq_ad_type'] ?? '' ) ) ?? GQ_Ad_Type::HTML;
	update_post_meta( $post_id, 'gq_ad_type', $ad_type->value );

	// Ad code — admins with unfiltered_html can save script tags.
	$code = isset( $_POST['gq_ad_code'] ) ? wp_unslash( $_POST['gq_ad_code'] ) : '';
	if ( ! current_user_can( 'unfiltered_html' ) ) {
		$code = wp_kses_post( $code );
	}
	update_post_meta( $post_id, 'gq_ad_code', $code );

	// Image attachment ID.
	$image_id = (int) ( $_POST['gq_ad_image'] ?? 0 );
	if ( $image_id > 0 ) {
		update_post_meta( $post_id, 'gq_ad_image', $image_id );
	} else {
		delete_post_meta( $post_id, 'gq_ad_image' );
	}

	// Target URL — note meta key is gq_target_url (not gq_ad_target_url).
	$url = esc_url_raw( wp_unslash( $_POST['gq_target_url'] ?? '' ) );
	if ( $url ) {
		update_post_meta( $post_id, 'gq_target_url', $url );
	} else {
		delete_post_meta( $post_id, 'gq_target_url' );
	}

	// Weight.
	$weight = max( 1, min( 100, (int) ( $_POST['gq_ad_weight_input'] ?? 50 ) ) );
	update_post_meta( $post_id, 'gq_ad_weight', $weight );

	// Group assignment — stored as PHP-serialised array so LIKE ':ID;' meta query matches.
	$group_ids = [];
	if ( ! empty( $_POST['gq_ad_group_ids'] ) && is_array( $_POST['gq_ad_group_ids'] ) ) {
		$group_ids = array_values( array_filter( array_map( 'intval', wp_unslash( $_POST['gq_ad_group_ids'] ) ) ) );
	}
	update_post_meta( $post_id, 'gq_ad_group', $group_ids );
}
