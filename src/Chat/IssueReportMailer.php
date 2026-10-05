<?php
/**
 * Emails new issue reports to the site team.
 *
 * @package WPCortex
 */

namespace WPCortex\Chat;

use WPCortex\Admin\Menu;
use WPCortex\Settings;

defined( 'ABSPATH' ) || exit;

/**
 * Sends a plain-text notification through wp_mail() to the addresses in the
 * public_chat_report_email setting when a visitor reports an issue.
 */
final class IssueReportMailer {

	public const MAX_RECIPIENTS = VisitorChatMailer::MAX_RECIPIENTS;

	/**
	 * Sends the notification, if any recipients are set. Failures are ignored: the
	 * report is already stored and listed in the admin.
	 *
	 * @param array $report Report from IssueReportStore::get().
	 */
	public function notify( array $report ): void {
		$to = array_filter( array_map( 'trim', explode( ',', (string) Settings::get( 'public_chat_report_email' ) ) ), 'is_email' );

		if ( ! $to ) {
			return;
		}

		$site    = wp_specialchars_decode( get_bloginfo( 'name' ), ENT_QUOTES );
		$subject = sprintf(
			/* translators: 1: site name, 2: report ID, 3: issue category. */
			__( '[%1$s] Issue report #%2$d: %3$s', 'wp-cortex' ),
			$site,
			$report['id'],
			$report['category_label']
		);

		wp_mail( array_values( $to ), $subject, $this->body( $report ), array( 'Content-Type: text/plain; charset=UTF-8' ) );
	}

	/**
	 * Plain-text email body.
	 *
	 * @param array $report Report.
	 */
	private function body( array $report ): string {
		$lines = array(
			__( 'A visitor reported an issue through the visitor chat.', 'wp-cortex' ),
			'',
			__( 'Issue:', 'wp-cortex' ) . ' ' . $report['category_label'],
			__( 'Description:', 'wp-cortex' ) . ' ' . $report['description'],
		);

		if ( '' !== $report['excerpt'] ) {
			$lines[] = __( 'Affected text or element:', 'wp-cortex' ) . ' ' . $report['excerpt'];
		}

		$page = $report['page'] ? $report['page']['url'] : '';
		$url  = '' !== $report['page_url'] ? $report['page_url'] : $page;

		if ( '' !== $url ) {
			$lines[] = __( 'Page:', 'wp-cortex' ) . ' ' . ( $report['page'] ? $report['page']['title'] . ' - ' : '' ) . $url;
		}

		// Built directly: get_edit_post_link() is empty for the visitor sending the report.
		if ( $report['page'] ) {
			$lines[] = __( 'Edit the page:', 'wp-cortex' ) . ' ' . admin_url( 'post.php?post=' . (int) $report['page']['id'] . '&action=edit' );
		}

		$lines[] = '';
		$lines[] = __( 'Open in the admin:', 'wp-cortex' ) . ' ' . admin_url( 'admin.php?page=' . Menu::SLUG_REPORTS );

		if ( $report['chat_id'] ) {
			$lines[] = __( 'Conversation:', 'wp-cortex' ) . ' ' . admin_url( 'admin.php?page=' . Menu::SLUG_VISITORS . '#chat=' . (int) $report['chat_id'] );
		}

		return implode( "\n", $lines ) . "\n";
	}
}
