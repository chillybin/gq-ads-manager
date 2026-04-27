<?php

declare(strict_types=1);
if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Add admin menu for stats.
 */
add_action( 'admin_menu', 'gq_ads_add_stats_menu' );

function gq_ads_add_stats_menu(): void {
	add_submenu_page(
		'edit.php?post_type=gq_ad',
		__( 'Ad Statistics', 'gq-ads' ),
		__( 'Statistics', 'gq-ads' ),
		'edit_posts', // Editors and above can view stats for ads they manage.
		'gq-ads-stats',
		'gq_ads_render_stats_page'
	);
}

/**
 * Render the stats admin page.
 */
function gq_ads_render_stats_page(): void {
	// Capability check: Only administrators can view stats.
	if ( ! current_user_can( 'edit_posts' ) ) {
		wp_die( esc_html__( 'You do not have sufficient permissions to access this page.', 'gq-ads' ) );
	}

	global $wpdb;

	// Table name is safe - wpdb->prefix is WordPress-controlled.
	$table = $wpdb->prefix . 'gq_ad_stats';

	// Handle CSV export with CSRF protection.
	if ( isset( $_GET['export'] ) && $_GET['export'] === 'csv' ) {
		check_admin_referer( 'gq_ads_export_csv' );
		gq_ads_export_csv();
		exit;
	}

	// CSRF protection: Verify nonce when filter parameters are present.
	if ( isset( $_GET['filter_placement'] ) || isset( $_GET['filter_group'] ) || isset( $_GET['filter_ad'] ) ) {
		check_admin_referer( 'gq_ads_filter_stats' );
	}

	// Filters.
	$days = isset( $_GET['days'] ) ? sanitize_text_field( $_GET['days'] ) : '30';
	$filter_placement = isset( $_GET['filter_placement'] ) ? (int) $_GET['filter_placement'] : 0;
	$filter_group = isset( $_GET['filter_group'] ) ? (int) $_GET['filter_group'] : 0;
	$filter_ad = isset( $_GET['filter_ad'] ) ? (int) $_GET['filter_ad'] : 0;
	$view_mode = isset( $_GET['view_mode'] ) ? sanitize_key( $_GET['view_mode'] ) : 'daily';

	// Handle custom date range with validation.
	if ( $days === 'custom' ) {
		$start_date = isset( $_GET['start_date'] ) ? sanitize_text_field( $_GET['start_date'] ) : current_time( 'Y-m-d' );
		$end_date = isset( $_GET['end_date'] ) ? sanitize_text_field( $_GET['end_date'] ) : current_time( 'Y-m-d' );

		// Validate date format (YYYY-MM-DD).
		if ( ! preg_match( '/^\d{4}-\d{2}-\d{2}$/', $start_date ) || ! strtotime( $start_date ) ) {
			$start_date = gmdate( 'Y-m-d', strtotime( current_time( 'Y-m-d' ) . ' -30 days' ) );
		}
		if ( ! preg_match( '/^\d{4}-\d{2}-\d{2}$/', $end_date ) || ! strtotime( $end_date ) ) {
			$end_date = current_time( 'Y-m-d' );
		}

		// Ensure start_date is not after end_date.
		if ( strtotime( $start_date ) > strtotime( $end_date ) ) {
			$temp = $start_date;
			$start_date = $end_date;
			$end_date = $temp;
		}

		// Boundary validation: Ensure dates are within reasonable limits.
		$min_date = '2020-01-01'; // Reasonable minimum (adjust to plugin launch date if needed).
		$max_date = current_time( 'Y-m-d' );

		if ( strtotime( $start_date ) < strtotime( $min_date ) ) {
			$start_date = $min_date;
		}
		if ( strtotime( $end_date ) > strtotime( $max_date ) ) {
			$end_date = $max_date;
		}
	} else {
		$start_date = gmdate( 'Y-m-d', strtotime( current_time( 'Y-m-d' ) . " -{$days} days" ) );
		$end_date = current_time( 'Y-m-d' );
	}

	// Build WHERE clause.
	$where = [ 's.stat_date >= %s', 's.stat_date <= %s' ];
	$where_values = [ $start_date, $end_date ];

	if ( $filter_placement > 0 ) {
		$where[] = 's.placement_id = %d';
		$where_values[] = $filter_placement;
	}

	if ( $filter_group > 0 ) {
		$where[] = 's.group_id = %d';
		$where_values[] = $filter_group;
	}

	if ( $filter_ad > 0 ) {
		$where[] = 's.ad_id = %d';
		$where_values[] = $filter_ad;
	}

	// Security: WHERE clause is built from parameterized conditions.
	// Each condition in $where array uses %s/%d placeholders.
	// Values are passed separately in $where_values and sanitized by wpdb->prepare().
	// This pattern is safe as long as $where array contains only literal strings with placeholders.
	$where_sql = implode( ' AND ', $where );

	// Get ALL ads with stats (including 0 impressions).
	// Table names are safe - wpdb->prefix is WordPress-controlled.
	$posts_table = $wpdb->prefix . 'posts';
	// phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared -- Table names are safe, wpdb->prefix is WordPress-controlled.
	$stats = $wpdb->get_results( $wpdb->prepare(
		"SELECT
			p.ID as ad_id,
			COALESCE(s.placement_id, 0) as placement_id,
			COALESCE(s.group_id, 0) as group_id,
			COALESCE(SUM(s.impressions), 0) as total_impressions,
			COALESCE(SUM(s.clicks), 0) as total_clicks,
			CASE
				WHEN SUM(s.impressions) > 0 THEN (SUM(s.clicks) / SUM(s.impressions)) * 100
				ELSE 0
			END as ctr,
			MIN(s.stat_date) as first_date,
			MAX(s.stat_date) as last_date
		FROM {$posts_table} p
		LEFT JOIN {$table} s ON p.ID = s.ad_id AND {$where_sql}
		WHERE p.post_type = 'gq_ad' AND p.post_status = 'publish'
		GROUP BY p.ID, s.placement_id, s.group_id
		ORDER BY total_impressions DESC",
		$where_values
	) ) ?? [];

	// Get daily/weekly/monthly totals for chart based on view mode.
	if ( $view_mode === 'weekly' ) {
		// phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared -- Table name is safe, wpdb->prefix is WordPress-controlled.
		$daily = $wpdb->get_results( $wpdb->prepare(
			"SELECT
				DATE_FORMAT(s.stat_date, '%Y-%u') as period,
				MIN(s.stat_date) as stat_date,
				SUM(s.impressions) as impressions
			FROM {$table} s
			WHERE {$where_sql}
			GROUP BY period
			ORDER BY stat_date ASC",
			$where_values
		) ) ?? [];
	} elseif ( $view_mode === 'monthly' ) {
		// phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared -- Table name is safe, wpdb->prefix is WordPress-controlled.
		$daily = $wpdb->get_results( $wpdb->prepare(
			"SELECT
				DATE_FORMAT(s.stat_date, '%Y-%m') as period,
				MIN(s.stat_date) as stat_date,
				SUM(s.impressions) as impressions
			FROM {$table} s
			WHERE {$where_sql}
			GROUP BY period
			ORDER BY stat_date ASC",
			$where_values
		) ) ?? [];
	} else {
		// Daily (default).
		// phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared -- Table name is safe, wpdb->prefix is WordPress-controlled.
		$daily = $wpdb->get_results( $wpdb->prepare(
			"SELECT s.stat_date, SUM(s.impressions) as impressions
			FROM {$table} s
			WHERE {$where_sql}
			GROUP BY s.stat_date
			ORDER BY s.stat_date ASC",
			$where_values
		) ) ?? [];
	}

	// Filter dropdowns only need ID + title — skip meta and term cache.
	$dropdown_args = [
		'post_status'            => 'publish',
		'posts_per_page'         => -1,
		'orderby'                => 'title',
		'order'                  => 'ASC',
		'no_found_rows'          => true,
		'update_post_meta_cache' => false,
		'update_post_term_cache' => false,
	];
	$placements = get_posts( array_merge( $dropdown_args, [ 'post_type' => 'gq_ad_placement' ] ) );
	$groups     = get_posts( array_merge( $dropdown_args, [ 'post_type' => 'gq_ad_group' ] ) );
	$ads        = get_posts( array_merge( $dropdown_args, [ 'post_type' => 'gq_ad' ] ) );

	?>
	<div class="wrap">
		<h1><?php esc_html_e( 'Ad Statistics', 'gq-ads' ); ?></h1>

		<div class="tablenav top" style="display: flex; gap: 10px; align-items: center; flex-wrap: wrap;">
			<form method="get" style="display: flex; gap: 10px; align-items: center; flex-wrap: wrap;">
				<input type="hidden" name="post_type" value="gq_ad">
				<input type="hidden" name="page" value="gq-ads-stats">
				<input type="hidden" name="_wpnonce" value="<?php echo esc_attr( wp_create_nonce( 'gq_ads_filter_stats' ) ); ?>">

				<select name="days" id="gq-ads-date-range">
					<option value="7" <?php selected( $days, '7' ); ?>>Last 7 days</option>
					<option value="30" <?php selected( $days, '30' ); ?>>Last 30 days</option>
					<option value="90" <?php selected( $days, '90' ); ?>>Last 90 days</option>
					<option value="365" <?php selected( $days, '365' ); ?>>Last year</option>
					<option value="custom" <?php selected( $days, 'custom' ); ?>>Custom Range</option>
				</select>

				<span id="gq-ads-custom-dates" style="display: <?php echo esc_attr( $days === 'custom' ? 'inline-flex' : 'none' ); ?>; gap: 5px; align-items: center;">
					<input type="date" name="start_date" value="<?php echo esc_attr( $start_date ); ?>" max="<?php echo esc_attr( current_time( 'Y-m-d' ) ); ?>">
					<span>to</span>
					<input type="date" name="end_date" value="<?php echo esc_attr( $end_date ); ?>" max="<?php echo esc_attr( current_time( 'Y-m-d' ) ); ?>">
				</span>

				<script>
				document.getElementById('gq-ads-date-range')?.addEventListener('change', (event) => {
					const display     = event.currentTarget.value === 'custom' ? 'inline-flex' : 'none';
					const customDates = document.getElementById('gq-ads-custom-dates');
					if (customDates) customDates.style.display = display;
				});
				</script>

				<select name="view_mode">
					<option value="daily" <?php selected( $view_mode, 'daily' ); ?>>Daily</option>
					<option value="weekly" <?php selected( $view_mode, 'weekly' ); ?>>Weekly</option>
					<option value="monthly" <?php selected( $view_mode, 'monthly' ); ?>>Monthly</option>
				</select>

				<select name="filter_placement">
					<option value="0">All Placements</option>
					<?php foreach ( $placements as $p ) : ?>
						<option value="<?php echo esc_attr( $p->ID ); ?>" <?php selected( $filter_placement, $p->ID ); ?>>
							<?php echo esc_html( $p->post_title ); ?>
						</option>
					<?php endforeach; ?>
				</select>

				<select name="filter_group">
					<option value="0">All Groups</option>
					<?php foreach ( $groups as $g ) : ?>
						<option value="<?php echo esc_attr( $g->ID ); ?>" <?php selected( $filter_group, $g->ID ); ?>>
							<?php echo esc_html( $g->post_title ); ?>
						</option>
					<?php endforeach; ?>
				</select>

				<select name="filter_ad">
					<option value="0">All Ads</option>
					<?php foreach ( $ads as $a ) : ?>
						<option value="<?php echo esc_attr( $a->ID ); ?>" <?php selected( $filter_ad, $a->ID ); ?>>
							<?php echo esc_html( $a->post_title ); ?>
						</option>
					<?php endforeach; ?>
				</select>

				<input type="submit" class="button button-primary" value="Apply Filters">

				<?php if ( $filter_placement || $filter_group || $filter_ad ) : ?>
					<a href="<?php echo esc_url( admin_url( 'edit.php?post_type=gq_ad&page=gq-ads-stats&days=' . $days ) ); ?>" class="button">Clear Filters</a>
				<?php endif; ?>
			</form>

			<?php

			// Build export URL with current filters.
			$export_args = [
				'post_type'        => 'gq_ad',
				'page'             => 'gq-ads-stats',
				'export'           => 'csv',
				'days'             => $days,
				'view_mode'        => $view_mode,
				'filter_placement' => $filter_placement,
				'filter_group'     => $filter_group,
				'filter_ad'        => $filter_ad,
			];
			if ( $days === 'custom' ) {
				$export_args['start_date'] = $start_date;
				$export_args['end_date'] = $end_date;
			}
			$export_url = wp_nonce_url( add_query_arg( $export_args, admin_url( 'edit.php' ) ), 'gq_ads_export_csv' );
			?>
			<a href="<?php echo esc_url( $export_url ); ?>" class="button" style="margin-left: auto;">
				<span class="dashicons dashicons-download" style="vertical-align: middle;"></span> Export CSV
			</a>
		</div>

		<?php

		// Show active filters summary.
		$active_filters = [];
		if ( $filter_placement ) {
			$active_filters[] = '<strong>Placement:</strong> ' . esc_html( get_the_title( $filter_placement ) );
		}
		if ( $filter_group ) {
			$active_filters[] = '<strong>Group:</strong> ' . esc_html( get_the_title( $filter_group ) );
		}
		if ( $filter_ad ) {
			$active_filters[] = '<strong>Ad:</strong> ' . esc_html( get_the_title( $filter_ad ) );
		}
		if ( ! empty( $active_filters ) ) :
		?>
		<div class="notice notice-info inline" style="margin: 15px 0;">
			<p><strong>Active Filters:</strong> <?php echo implode( ' | ', $active_filters ); ?></p>
		</div>
		<?php endif; ?>

		<?php

		// Active Campaigns panel — groups with campaign dates that are currently live.
		$today = current_time( 'Y-m-d' );
		$all_groups_for_campaigns = get_posts( [
			'post_type'              => 'gq_ad_group',
			'post_status'            => 'publish',
			'posts_per_page'         => -1,
			'no_found_rows'          => true,   // skip SQL_CALC_FOUND_ROWS
			'orderby'                => 'title',
			'order'                  => 'ASC',
			'update_post_meta_cache' => true,   // prime meta in one query
			'update_post_term_cache' => false,  // terms not needed here
		] );
		$active_campaigns = [];
		foreach ( $all_groups_for_campaigns as $cg ) {
			$start = get_post_meta( $cg->ID, 'gq_group_start_date', true );
			$end   = get_post_meta( $cg->ID, 'gq_group_end_date', true );
			if ( ! $start && ! $end ) continue;
			if ( $start && $today < $start ) continue;
			if ( $end   && $today > $end   ) continue;
			$active_campaigns[] = $cg;
		}
		if ( ! empty( $active_campaigns ) ) :
		?>
		<div style="background:#fff;border:1px solid #ccd0d4;padding:16px 20px;margin:20px 0;">
			<h3 style="margin-top:0;">Active Campaigns</h3>
			<table class="wp-list-table widefat fixed striped" style="margin:0;">
				<thead>
					<tr>
						<th>Group</th>
						<th>Campaign Window</th>
						<th>Targeting</th>
						<th>SOV</th>
						<th>Placement</th>
					</tr>
				</thead>
				<tbody>
				<?php foreach ( $active_campaigns as $cg ) :
					$cg_start     = get_post_meta( $cg->ID, 'gq_group_start_date', true );
					$cg_end       = get_post_meta( $cg->ID, 'gq_group_end_date', true );
					$cg_exclusive = get_post_meta( $cg->ID, 'gq_group_exclusive', true ) === '1';
					$cg_cats      = array_filter( array_map( 'intval', (array) get_post_meta( $cg->ID, 'gq_group_categories', true ) ) );
					$cg_window    = ( $cg_start ?: '∞' ) . ' → ' . ( $cg_end ?: '∞' );

					// Get placement name via the ACF relationship meta key.
					$placement_id_meta = get_post_meta( $cg->ID, 'gq_ad_placement', true );
					$placement_id_int  = is_array( $placement_id_meta ) ? (int) $placement_id_meta[0] : (int) $placement_id_meta;
					$placement_name    = $placement_id_int ? get_the_title( $placement_id_int ) : '—';

					$cat_names = [];
					foreach ( $cg_cats as $cat_id ) {
						$term = get_term( $cat_id, 'category' );
						if ( $term && ! is_wp_error( $term ) ) {
							$cat_names[] = $term->name;
						}
					}
				?>
					<tr>
						<td><a href="<?php echo esc_url( get_edit_post_link( $cg->ID ) ); ?>"><?php echo esc_html( $cg->post_title ); ?></a></td>
						<td><?php echo esc_html( $cg_window ); ?></td>
						<td><?php echo $cat_names ? esc_html( implode( ', ', $cat_names ) ) : '<em>' . esc_html__( 'Global', 'gq-ads' ) . '</em>'; ?></td>
						<td><?php echo $cg_exclusive ? '<span class="gq-sov-badge">100% SOV</span>' : '—'; ?></td>
						<td><?php echo esc_html( $placement_name ); ?></td>
					</tr>
				<?php endforeach; ?>
				</tbody>
			</table>
		</div>
		<?php endif; ?>

		<?php if ( ! empty( $daily ) ) : ?>
		<?php

		// Format chart labels based on view mode.
		$chart_title = ucfirst( $view_mode ) . ' Impressions';
		if ( $view_mode === 'weekly' ) {
			$labels = array_map( function( $d ) {
				return 'Week of ' . gmdate( 'M j', strtotime( $d->stat_date ) );
			}, $daily );
		} elseif ( $view_mode === 'monthly' ) {
			$labels = array_map( function( $d ) {
				return gmdate( 'M Y', strtotime( $d->stat_date ) );
			}, $daily );
		} else {
			$labels = array_map( function( $d ) {
				return gmdate( 'M j', strtotime( $d->stat_date ) );
			}, $daily );
		}

		// Attach chart init to the enqueued gq-ads-chart handle (runs in footer after canvas exists).
		wp_add_inline_script(
			'gq-ads-chart',
			sprintf(
				'(function(){var el=document.getElementById("gq-ads-chart-canvas");if(!el)return;new Chart(el.getContext("2d"),{type:"line",data:{labels:%s,datasets:[{label:"Impressions",data:%s,borderColor:"#2271b1",backgroundColor:"rgba(34,113,177,0.1)",tension:0.3,fill:true}]},options:{responsive:true,maintainAspectRatio:true,plugins:{legend:{display:false}},scales:{y:{beginAtZero:true,ticks:{callback:function(v){return v.toLocaleString();}}}}}});})();',
				wp_json_encode( $labels ),
				wp_json_encode( array_column( $daily, 'impressions' ) )
			)
		);
		?>
		<div class="gq-ads-chart" style="background: #fff; padding: 20px; margin: 20px 0; border: 1px solid #ccd0d4;">
			<h3 style="margin-top: 0;"><?php echo esc_html( $chart_title ); ?></h3>
			<canvas id="gq-ads-chart-canvas" style="max-height: 300px;"></canvas>
		</div>
		<?php endif; ?>

		<table class="wp-list-table widefat fixed striped">
			<thead>
				<tr>
					<th><?php esc_html_e( 'Ad', 'gq-ads' ); ?></th>
					<th><?php esc_html_e( 'Placement', 'gq-ads' ); ?></th>
					<th><?php esc_html_e( 'Group', 'gq-ads' ); ?></th>
					<th><?php esc_html_e( 'Impressions', 'gq-ads' ); ?></th>
					<th><?php esc_html_e( 'Clicks', 'gq-ads' ); ?></th>
					<th><?php esc_html_e( 'CTR', 'gq-ads' ); ?></th>
					<th><?php esc_html_e( 'Date Range', 'gq-ads' ); ?></th>
				</tr>
			</thead>
			<tbody>
				<?php if ( empty( $stats ) ) : ?>
					<tr>
						<td colspan="7"><?php esc_html_e( 'No stats recorded yet.', 'gq-ads' ); ?></td>
					</tr>
				<?php else :
					// Batch-load all post meta for groups shown in this table — prevents N+1 queries.
					$group_ids_in_stats = array_values( array_filter( array_unique( array_column( $stats, 'group_id' ) ) ) );
					if ( $group_ids_in_stats ) {
						update_meta_cache( 'post', $group_ids_in_stats );
					}
				?>
					<?php foreach ( $stats as $row ) :
						$ad_title        = get_the_title( $row->ad_id )        ?: sprintf( '(Deleted: %d)', $row->ad_id );
						$placement_title = get_the_title( $row->placement_id ) ?: sprintf( '(Deleted: %d)', $row->placement_id );
						$group_title     = $row->group_id ? ( get_the_title( $row->group_id ) ?: sprintf( '(Deleted: %d)', $row->group_id ) ) : '—';
					?>
					<tr>
						<td>
							<strong><?php echo esc_html( $ad_title ); ?></strong>
							<div class="row-actions">
								<a href="<?php echo esc_url( get_edit_post_link( $row->ad_id ) ); ?>">Edit</a>
							</div>
						</td>
						<td><?php echo esc_html( $placement_title ); ?></td>
						<td>
							<?php echo esc_html( $group_title ); ?>
							<?php if ( $row->group_id && get_post( $row->group_id ) ) :
								$g_exclusive = get_post_meta( $row->group_id, 'gq_group_exclusive', true ) === '1';
								$g_start     = get_post_meta( $row->group_id, 'gq_group_start_date', true );
								$g_end       = get_post_meta( $row->group_id, 'gq_group_end_date', true );
								$g_cats      = array_filter( array_map( 'intval', (array) get_post_meta( $row->group_id, 'gq_group_categories', true ) ) );
								$g_cat_names = [];
								foreach ( $g_cats as $cat_id ) {
									$term = get_term( $cat_id, 'category' );
									if ( $term && ! is_wp_error( $term ) ) {
										$g_cat_names[] = $term->name;
									}
								}
							?>
							<div class="gq-group-meta">
								<?php if ( $g_exclusive ) : ?>
									<span class="gq-sov-badge">100% SOV</span>
								<?php endif; ?>
								<?php if ( $g_start || $g_end ) : ?>
									<span><?php echo esc_html( ( $g_start ?: '∞' ) . ' → ' . ( $g_end ?: '∞' ) ); ?></span>
								<?php endif; ?>
								<?php if ( $g_cat_names ) : ?>
									<br><?php echo esc_html( implode( ', ', $g_cat_names ) ); ?>
								<?php endif; ?>
							</div>
							<?php endif; ?>
						</td>
						<td>
							<strong><?php echo number_format( $row->total_impressions ); ?></strong>
							<?php if ( (int) $row->total_impressions === 0 ) : ?>
								<span style="color: #d63638;"> — No impressions</span>
							<?php endif; ?>
						</td>
						<td>
							<strong><?php echo number_format( $row->total_clicks ); ?></strong>
						</td>
						<td>
							<?php

							$ctr = $row->ctr;
							$ctr_display = number_format( $ctr, 2 ) . '%';

							// Color coding: green if CTR > 1%, yellow if 0.5-1%, red if < 0.5%
							if ( $ctr >= 1.0 ) {
								$color = '#46b450'; // Green
							} elseif ( $ctr >= 0.5 ) {
								$color = '#f0b849'; // Yellow
							} else {
								$color = '#d63638'; // Red
							}
							?>
							<strong style="color: <?php echo esc_attr( $color ); ?>;">
								<?php echo esc_html( $ctr_display ); ?>
							</strong>
						</td>
						<td><?php echo $row->first_date ? esc_html( $row->first_date . ' — ' . $row->last_date ) : '—'; ?></td>
					</tr>
					<?php endforeach; ?>
				<?php endif; ?>
			</tbody>
		</table>

		<?php

		// Show totals.
		$total_impressions = array_sum( array_column( $stats, 'total_impressions' ) );
		$total_clicks = array_sum( array_column( $stats, 'total_clicks' ) );
		$overall_ctr = $total_impressions > 0 ? ( $total_clicks / $total_impressions ) * 100 : 0;
		?>
		<p style="margin-top: 15px;">
			<strong>Total Impressions:</strong> <?php echo number_format( $total_impressions ); ?> |
			<strong>Total Clicks:</strong> <?php echo number_format( $total_clicks ); ?> |
			<strong>Overall CTR:</strong> <?php echo number_format( $overall_ctr, 2 ); ?>%
		</p>
	</div>
	<?php

}

/**
 * Export stats to CSV.
 */
function gq_ads_export_csv(): void {
	global $wpdb;

	// Get filters from request.
	$days = isset( $_GET['days'] ) ? sanitize_text_field( $_GET['days'] ) : '30';
	$filter_placement = isset( $_GET['filter_placement'] ) ? (int) $_GET['filter_placement'] : 0;
	$filter_group = isset( $_GET['filter_group'] ) ? (int) $_GET['filter_group'] : 0;
	$filter_ad = isset( $_GET['filter_ad'] ) ? (int) $_GET['filter_ad'] : 0;
	$view_mode = isset( $_GET['view_mode'] ) ? sanitize_key( $_GET['view_mode'] ) : 'daily';

	// Table name is safe - wpdb->prefix is WordPress-controlled.
	$table = $wpdb->prefix . 'gq_ad_stats';

	// Handle custom date range with validation.
	if ( $days === 'custom' ) {
		$start_date = isset( $_GET['start_date'] ) ? sanitize_text_field( $_GET['start_date'] ) : current_time( 'Y-m-d' );
		$end_date = isset( $_GET['end_date'] ) ? sanitize_text_field( $_GET['end_date'] ) : current_time( 'Y-m-d' );

		// Validate date format (YYYY-MM-DD).
		if ( ! preg_match( '/^\d{4}-\d{2}-\d{2}$/', $start_date ) || ! strtotime( $start_date ) ) {
			$start_date = gmdate( 'Y-m-d', strtotime( current_time( 'Y-m-d' ) . ' -30 days' ) );
		}
		if ( ! preg_match( '/^\d{4}-\d{2}-\d{2}$/', $end_date ) || ! strtotime( $end_date ) ) {
			$end_date = current_time( 'Y-m-d' );
		}

		// Ensure start_date is not after end_date.
		if ( strtotime( $start_date ) > strtotime( $end_date ) ) {
			$temp = $start_date;
			$start_date = $end_date;
			$end_date = $temp;
		}

		// Boundary validation: Ensure dates are within reasonable limits.
		$min_date = '2020-01-01'; // Reasonable minimum (adjust to plugin launch date if needed).
		$max_date = current_time( 'Y-m-d' );

		if ( strtotime( $start_date ) < strtotime( $min_date ) ) {
			$start_date = $min_date;
		}
		if ( strtotime( $end_date ) > strtotime( $max_date ) ) {
			$end_date = $max_date;
		}
	} else {
		$start_date = gmdate( 'Y-m-d', strtotime( current_time( 'Y-m-d' ) . " -{$days} days" ) );
		$end_date = current_time( 'Y-m-d' );
	}

	// Build WHERE clause.
	$where = [ 's.stat_date >= %s', 's.stat_date <= %s' ];
	$where_values = [ $start_date, $end_date ];

	if ( $filter_placement > 0 ) {
		$where[] = 's.placement_id = %d';
		$where_values[] = $filter_placement;
	}

	if ( $filter_group > 0 ) {
		$where[] = 's.group_id = %d';
		$where_values[] = $filter_group;
	}

	if ( $filter_ad > 0 ) {
		$where[] = 's.ad_id = %d';
		$where_values[] = $filter_ad;
	}

	$where_sql = implode( ' AND ', $where );

	// Get stats.
	// phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared -- Table name is safe, wpdb->prefix is WordPress-controlled.
	$stats = $wpdb->get_results( $wpdb->prepare(
		"SELECT
			s.ad_id,
			s.placement_id,
			s.group_id,
			SUM(s.impressions) as total_impressions,
			SUM(s.clicks) as total_clicks,
			CASE
				WHEN SUM(s.impressions) > 0 THEN (SUM(s.clicks) / SUM(s.impressions)) * 100
				ELSE 0
			END as ctr,
			MIN(s.stat_date) as first_date,
			MAX(s.stat_date) as last_date
		FROM {$table} s
		WHERE {$where_sql}
		GROUP BY s.ad_id, s.placement_id, s.group_id
		ORDER BY total_impressions DESC",
		$where_values
	) ) ?? [];

	// Set headers for CSV download.
	$filename = sanitize_file_name( 'gq-ads-stats-' . current_time( 'Y-m-d' ) . '.csv' );
	header( 'Content-Type: text/csv; charset=utf-8' );
	header( 'Content-Disposition: attachment; filename="' . $filename . '"' );
	header( 'Pragma: no-cache' );
	header( 'Expires: 0' );

	// Open output stream.
	$output = fopen( 'php://output', 'w' );

	// CSV headers.
	fputcsv( $output, [ 'Ad', 'Placement', 'Group', 'Impressions', 'Clicks', 'CTR', 'Date Range' ] );

	// CSV rows.
	foreach ( $stats as $row ) {
		$ad_title = get_the_title( $row->ad_id ) ?: "(Deleted: {$row->ad_id})";
		$placement_title = get_the_title( $row->placement_id ) ?: "(Deleted: {$row->placement_id})";
		$group_title = $row->group_id ? ( get_the_title( $row->group_id ) ?: "(Deleted: {$row->group_id})" ) : '-';
		$ctr = number_format( $row->ctr, 2 ) . '%';

		fputcsv( $output, [
			$ad_title,
			$placement_title,
			$group_title,
			$row->total_impressions,
			$row->total_clicks,
			$ctr,
			$row->first_date . ' — ' . $row->last_date,
		] );
	}

	fclose( $output );
}
