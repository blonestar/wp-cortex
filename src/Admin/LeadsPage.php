<?php
/**
 * Leads screen: where leads come from and the list of leads.
 *
 * @package WPCortex
 */

namespace WPCortex\Admin;

use WPCortex\Settings;

defined( 'ABSPATH' ) || exit;

/**
 * Server-rendered shell; assets/js/leads.js loads the report and the leads list, draws
 * the charts, changes lead statuses, rates leads with AI and exports CSV. Channels,
 * sources, campaigns and landing pages are shown only while attribution is on.
 */
final class LeadsPage {

	/**
	 * Lead status names.
	 *
	 * @return array<string, string>
	 */
	public static function status_labels(): array {
		return array(
			'new'       => __( 'New', 'wp-cortex' ),
			'contacted' => __( 'Contacted', 'wp-cortex' ),
			'qualified' => __( 'Qualified', 'wp-cortex' ),
			'won'       => __( 'Won', 'wp-cortex' ),
			'lost'      => __( 'Lost', 'wp-cortex' ),
			'spam'      => __( 'Spam', 'wp-cortex' ),
		);
	}

	/**
	 * Renders the page.
	 */
	public function render(): void {
		if ( ! current_user_can( 'manage_options' ) ) {
			return;
		}

		$attribution = (bool) Settings::get( 'leads_attribution' );
		$periods     = array(
			7   => __( '7 days', 'wp-cortex' ),
			30  => __( '30 days', 'wp-cortex' ),
			90  => __( '90 days', 'wp-cortex' ),
			365 => __( '12 months', 'wp-cortex' ),
			0   => __( 'All time', 'wp-cortex' ),
		);
		?>
		<div class="wrap wp-cortex-wrap wp-cortex-leads" id="wp-cortex-leads">
			<h1 class="wp-heading-inline"><?php esc_html_e( 'Cortex Leads', 'wp-cortex' ); ?></h1>
			<hr class="wp-header-end">

			<p class="wp-cortex-intro">
				<?php
				if ( $attribution ) {
					esc_html_e( 'Visitors who left their contact details in the visitor chat, with the channel, campaign and page that brought them. Rate leads with AI, track their status and export them for your CRM.', 'wp-cortex' );
				} else {
					esc_html_e( 'Visitors who left their contact details in the visitor chat. Rate leads with AI, track their status and export them for your CRM.', 'wp-cortex' );
				}
				?>
			</p>

			<?php if ( ! Settings::get( 'public_chat_enabled' ) || ! Settings::get( 'public_chat_log' ) ) : ?>
				<div class="notice notice-warning inline"><p><?php esc_html_e( 'The visitor chat or its conversation log is off, so no new leads are coming in.', 'wp-cortex' ); ?></p></div>
			<?php elseif ( ! $attribution ) : ?>
				<div class="notice notice-info inline"><p>
					<?php
					printf(
						/* translators: %s: link to the settings section. */
						esc_html__( 'Attribution is off, so leads are not credited to a channel, campaign or landing page. To see where leads come from, turn it on under %s.', 'wp-cortex' ),
						'<a href="' . esc_url( SettingsPage::section_url( 'leads', 'attribution' ) ) . '">' . esc_html__( 'Settings > Leads > Attribution', 'wp-cortex' ) . '</a>'
					);
					?>
				</p></div>
			<?php endif; ?>

			<div class="notice inline" id="wp-cortex-leads-notice" hidden><p></p></div>

			<div class="wp-cortex-leads-toolbar">
				<div class="wp-cortex-segmented" role="group" aria-label="<?php esc_attr_e( 'Period', 'wp-cortex' ); ?>" id="wp-cortex-leads-period">
					<?php foreach ( $periods as $days => $label ) : ?>
						<button type="button" class="button" data-days="<?php echo esc_attr( (string) $days ); ?>" aria-pressed="<?php echo 30 === $days ? 'true' : 'false'; ?>"><?php echo esc_html( $label ); ?></button>
					<?php endforeach; ?>
				</div>
				<?php if ( $attribution ) : ?>
					<label class="screen-reader-text" for="wp-cortex-leads-channel"><?php esc_html_e( 'Channel', 'wp-cortex' ); ?></label>
					<select id="wp-cortex-leads-channel"></select>
				<?php endif; ?>
				<span class="wp-cortex-leads-toolbar-end">
					<button type="button" class="button" id="wp-cortex-leads-rate-all"><?php esc_html_e( 'Rate unrated leads with AI', 'wp-cortex' ); ?></button>
					<button type="button" class="button button-primary" id="wp-cortex-leads-export"><?php esc_html_e( 'Export CSV', 'wp-cortex' ); ?></button>
				</span>
			</div>

			<div class="wp-cortex-kpis" id="wp-cortex-leads-kpis" aria-live="polite"></div>

			<div class="wp-cortex-leads-grid">
				<section class="wp-cortex-card wp-cortex-leads-wide">
					<h2><?php esc_html_e( 'Conversations and leads', 'wp-cortex' ); ?></h2>
					<div class="wp-cortex-chart" id="wp-cortex-leads-trend"></div>
				</section>
				<?php if ( $attribution ) : ?>
					<section class="wp-cortex-card">
						<h2><?php esc_html_e( 'Leads by channel', 'wp-cortex' ); ?></h2>
						<div id="wp-cortex-leads-channels"></div>
					</section>
				<?php endif; ?>
				<section class="wp-cortex-card<?php echo $attribution ? '' : ' wp-cortex-leads-wide'; ?>">
					<h2><?php esc_html_e( 'Lead quality', 'wp-cortex' ); ?></h2>
					<div id="wp-cortex-leads-quality"></div>
				</section>
				<?php if ( $attribution ) : ?>
					<section class="wp-cortex-card">
						<h2><?php esc_html_e( 'Top sources and campaigns', 'wp-cortex' ); ?></h2>
						<div id="wp-cortex-leads-campaigns"></div>
					</section>
					<section class="wp-cortex-card">
						<h2><?php esc_html_e( 'Top landing pages', 'wp-cortex' ); ?></h2>
						<div id="wp-cortex-leads-landing"></div>
					</section>
				<?php endif; ?>
			</div>

			<section class="wp-cortex-card wp-cortex-leads-list">
				<h2><?php esc_html_e( 'Leads', 'wp-cortex' ); ?></h2>
				<ul class="subsubsub" id="wp-cortex-leads-statuses"></ul>
				<form class="search-box" id="wp-cortex-leads-search" role="search">
					<label class="screen-reader-text" for="wp-cortex-leads-search-input"><?php esc_html_e( 'Search leads', 'wp-cortex' ); ?></label>
					<input type="search" id="wp-cortex-leads-search-input" maxlength="200">
					<button type="submit" class="button"><?php esc_html_e( 'Search leads', 'wp-cortex' ); ?></button>
				</form>
				<div class="tablenav top">
					<div class="alignleft actions">
						<label class="screen-reader-text" for="wp-cortex-leads-rating"><?php esc_html_e( 'Rating', 'wp-cortex' ); ?></label>
						<select id="wp-cortex-leads-rating">
							<option value=""><?php esc_html_e( 'All ratings', 'wp-cortex' ); ?></option>
							<option value="hot"><?php esc_html_e( 'Hot', 'wp-cortex' ); ?></option>
							<option value="warm"><?php esc_html_e( 'Warm', 'wp-cortex' ); ?></option>
							<option value="cold"><?php esc_html_e( 'Cold', 'wp-cortex' ); ?></option>
							<option value="unrated"><?php esc_html_e( 'Not rated', 'wp-cortex' ); ?></option>
						</select>
					</div>
					<div class="tablenav-pages" id="wp-cortex-leads-pages"></div>
					<br class="clear">
				</div>
				<table class="wp-list-table widefat fixed striped wp-cortex-leads-table">
					<thead><tr id="wp-cortex-leads-head"></tr></thead>
					<tbody id="wp-cortex-leads-rows">
						<tr><td colspan="7"><?php esc_html_e( 'Loading…', 'wp-cortex' ); ?></td></tr>
					</tbody>
				</table>
			</section>
			<p class="description">
				<?php
				esc_html_e( 'Figures cover the selected period and compare it with the period before. A conversation counts when it started, a lead when the visitor left an email address, phone number, postal address or website.', 'wp-cortex' );
				if ( $attribution ) {
					echo ' ' . esc_html__( 'The channel is that of the visit that led to the chat (last touch); each conversation also shows the first visit.', 'wp-cortex' );
				}
				?>
			</p>
		</div>
		<?php
	}
}
