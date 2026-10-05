<?php
/**
 * Issue reports screen: problems visitors reported through the visitor chat.
 *
 * @package WPCortex
 */

namespace WPCortex\Admin;

use WPCortex\Chat\IssueReportStore;
use WPCortex\Settings;

defined( 'ABSPATH' ) || exit;

/**
 * Server-rendered shell; assets/js/issue-reports.js lists the reports, changes their
 * status, saves notes and deletes them.
 */
final class IssueReportsPage {

	/**
	 * Renders the page.
	 */
	public function render(): void {
		if ( ! current_user_can( 'manage_options' ) ) {
			return;
		}

		$settings_url = add_query_arg(
			array(
				'tab'     => 'visitors',
				'section' => 'actions',
			),
			admin_url( 'admin.php?page=' . Menu::SLUG_SETTINGS )
		);
		$off          = ! Settings::get( 'public_chat_enabled' ) || ! Settings::get( 'public_chat_reports' );
		?>
		<div class="wrap wp-cortex-wrap" id="wp-cortex-issue-reports">
			<h1 class="wp-heading-inline"><?php esc_html_e( 'Cortex Issue Reports', 'wp-cortex' ); ?></h1>
			<hr class="wp-header-end">

			<p class="wp-cortex-intro">
				<?php esc_html_e( 'Problems visitors pointed out in the visitor chat, such as typos, broken images or links and outdated information. Mark a report as resolved once it is fixed, or dismiss it.', 'wp-cortex' ); ?>
			</p>

			<?php if ( $off ) : ?>
				<div class="notice notice-warning inline"><p>
					<?php
					printf(
						/* translators: %s: link to the Settings screen. */
						esc_html__( 'Visitors cannot report issues right now: the visitor chat or issue reporting is turned off in %s.', 'wp-cortex' ),
						'<a href="' . esc_url( $settings_url ) . '">' . esc_html__( 'Cortex Settings', 'wp-cortex' ) . '</a>'
					);
					?>
				</p></div>
			<?php endif; ?>

			<div class="notice inline" id="wp-cortex-reports-notice" hidden><p></p></div>

			<ul class="subsubsub" id="wp-cortex-reports-filters"></ul>

			<form class="search-box" id="wp-cortex-reports-search" role="search">
				<label class="screen-reader-text" for="wp-cortex-reports-search-input"><?php esc_html_e( 'Search reports', 'wp-cortex' ); ?></label>
				<input type="search" id="wp-cortex-reports-search-input" maxlength="200">
				<button type="submit" class="button"><?php esc_html_e( 'Search reports', 'wp-cortex' ); ?></button>
			</form>

			<div class="tablenav top">
				<div class="alignleft actions bulkactions">
					<label class="screen-reader-text" for="wp-cortex-reports-bulk"><?php esc_html_e( 'Select bulk action', 'wp-cortex' ); ?></label>
					<select id="wp-cortex-reports-bulk">
						<option value=""><?php esc_html_e( 'Bulk actions', 'wp-cortex' ); ?></option>
						<option value="<?php echo esc_attr( IssueReportStore::STATUS_RESOLVED ); ?>"><?php esc_html_e( 'Mark as resolved', 'wp-cortex' ); ?></option>
						<option value="<?php echo esc_attr( IssueReportStore::STATUS_DISMISSED ); ?>"><?php esc_html_e( 'Dismiss', 'wp-cortex' ); ?></option>
						<option value="<?php echo esc_attr( IssueReportStore::STATUS_OPEN ); ?>"><?php esc_html_e( 'Reopen', 'wp-cortex' ); ?></option>
						<option value="delete"><?php esc_html_e( 'Delete', 'wp-cortex' ); ?></option>
					</select>
					<button type="button" class="button action" id="wp-cortex-reports-bulk-apply"><?php esc_html_e( 'Apply', 'wp-cortex' ); ?></button>
				</div>
				<div class="tablenav-pages" id="wp-cortex-reports-pages"></div>
				<br class="clear">
			</div>

			<p id="wp-cortex-reports-single" hidden><a href="#" id="wp-cortex-reports-show-all">&larr; <?php esc_html_e( 'Show all reports', 'wp-cortex' ); ?></a></p>

			<table class="wp-list-table widefat fixed striped wp-cortex-reports-table">
				<thead>
					<tr>
						<td class="manage-column column-cb check-column">
							<label class="screen-reader-text" for="wp-cortex-reports-select-all"><?php esc_html_e( 'Select all', 'wp-cortex' ); ?></label>
							<input type="checkbox" id="wp-cortex-reports-select-all">
						</td>
						<th scope="col" class="column-primary"><?php esc_html_e( 'Issue', 'wp-cortex' ); ?></th>
						<th scope="col" class="wp-cortex-col-page"><?php esc_html_e( 'Page', 'wp-cortex' ); ?></th>
						<th scope="col" class="wp-cortex-col-date"><?php esc_html_e( 'Reported', 'wp-cortex' ); ?></th>
						<th scope="col" class="wp-cortex-col-status"><?php esc_html_e( 'Status', 'wp-cortex' ); ?></th>
					</tr>
				</thead>
				<tbody id="wp-cortex-reports-list">
					<tr><td colspan="5"><?php esc_html_e( 'Loading…', 'wp-cortex' ); ?></td></tr>
				</tbody>
			</table>
		</div>
		<?php
	}
}
