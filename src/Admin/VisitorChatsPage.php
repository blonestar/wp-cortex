<?php
/**
 * Visitor chats screen: stored visitor conversations and contact details.
 *
 * @package WPCortex
 */

namespace WPCortex\Admin;

use WPCortex\Chat\VisitorChatMailer;
use WPCortex\Chat\VisitorChatStore;
use WPCortex\Settings;

defined( 'ABSPATH' ) || exit;

/**
 * Server-rendered shell; assets/js/visitor-chats.js lists, opens, annotates and
 * deletes the conversations.
 */
final class VisitorChatsPage {

	/**
	 * Renders the page.
	 */
	public function render(): void {
		if ( ! current_user_can( 'manage_options' ) ) {
			return;
		}

		$settings_url = admin_url( 'admin.php?page=' . Menu::SLUG_SETTINGS );
		$off          = ! Settings::get( 'public_chat_enabled' ) || ! Settings::get( 'public_chat_log' );
		?>
		<div class="wrap wp-cortex-wrap" id="wp-cortex-visitor-chats">
			<h1 class="wp-heading-inline"><?php esc_html_e( 'Cortex Visitor Chats', 'wp-cortex' ); ?></h1>
			<hr class="wp-header-end">

			<p class="wp-cortex-intro">
				<?php esc_html_e( 'Conversations visitors had with the visitor chat on the front end, with the contact details they chose to leave. Open a conversation to read it, add a note for your team or delete it.', 'wp-cortex' ); ?>
			</p>

			<?php if ( $off ) : ?>
				<div class="notice notice-warning inline"><p>
					<?php
					printf(
						/* translators: %s: link to the Settings screen. */
						esc_html__( 'New conversations are not being saved: the visitor chat or its conversation log is turned off in %s.', 'wp-cortex' ),
						'<a href="' . esc_url( $settings_url ) . '">' . esc_html__( 'Cortex Settings', 'wp-cortex' ) . '</a>'
					);
					?>
				</p></div>
			<?php endif; ?>

			<div class="notice inline" id="wp-cortex-vchats-notice" hidden><p></p></div>

			<div id="wp-cortex-vchats-list-view">
				<ul class="subsubsub" id="wp-cortex-vchats-filters"></ul>

				<form class="search-box" id="wp-cortex-vchats-search" role="search">
					<label class="screen-reader-text" for="wp-cortex-vchats-search-input"><?php esc_html_e( 'Search conversations', 'wp-cortex' ); ?></label>
					<input type="search" id="wp-cortex-vchats-search-input" maxlength="200">
					<button type="submit" class="button"><?php esc_html_e( 'Search conversations', 'wp-cortex' ); ?></button>
				</form>

				<div class="tablenav top">
					<div class="alignleft actions bulkactions">
						<label class="screen-reader-text" for="wp-cortex-vchats-bulk"><?php esc_html_e( 'Select bulk action', 'wp-cortex' ); ?></label>
						<select id="wp-cortex-vchats-bulk">
							<option value=""><?php esc_html_e( 'Bulk actions', 'wp-cortex' ); ?></option>
							<option value="read"><?php esc_html_e( 'Mark as read', 'wp-cortex' ); ?></option>
							<option value="unread"><?php esc_html_e( 'Mark as unread', 'wp-cortex' ); ?></option>
							<option value="delete"><?php esc_html_e( 'Delete', 'wp-cortex' ); ?></option>
						</select>
						<button type="button" class="button action" id="wp-cortex-vchats-bulk-apply"><?php esc_html_e( 'Apply', 'wp-cortex' ); ?></button>
					</div>
					<div class="tablenav-pages" id="wp-cortex-vchats-pages"></div>
					<br class="clear">
				</div>

				<table class="wp-list-table widefat fixed striped wp-cortex-vchats-table">
					<thead>
						<tr>
							<td class="manage-column column-cb check-column">
								<label class="screen-reader-text" for="wp-cortex-vchats-select-all"><?php esc_html_e( 'Select all', 'wp-cortex' ); ?></label>
								<input type="checkbox" id="wp-cortex-vchats-select-all">
							</td>
							<th scope="col" class="wp-cortex-col-live"><span class="screen-reader-text"><?php esc_html_e( 'Activity', 'wp-cortex' ); ?></span></th>
							<th scope="col" class="column-primary"><?php esc_html_e( 'Conversation', 'wp-cortex' ); ?></th>
							<th scope="col" class="wp-cortex-col-contact"><?php esc_html_e( 'Contact', 'wp-cortex' ); ?></th>
							<th scope="col" class="wp-cortex-col-uses"><?php esc_html_e( 'Messages', 'wp-cortex' ); ?></th>
							<th scope="col" class="wp-cortex-col-date"><?php esc_html_e( 'Last activity', 'wp-cortex' ); ?></th>
							<th scope="col" class="wp-cortex-col-status"><?php esc_html_e( 'Status', 'wp-cortex' ); ?></th>
						</tr>
					</thead>
					<tbody id="wp-cortex-vchats-list">
						<tr><td colspan="7"><?php esc_html_e( 'Loading…', 'wp-cortex' ); ?></td></tr>
					</tbody>
				</table>
			</div>

			<div id="wp-cortex-vchat-detail" hidden>
				<p><a href="#" id="wp-cortex-vchat-back">&larr; <?php esc_html_e( 'Back to visitor chats', 'wp-cortex' ); ?></a></p>
				<h2 class="wp-cortex-vchat-title" id="wp-cortex-vchat-title"></h2>
				<p class="wp-cortex-vchat-meta" id="wp-cortex-vchat-meta"></p>
				<p class="wp-cortex-vchat-activity" id="wp-cortex-vchat-activity" aria-live="polite"></p>
				<div class="wp-cortex-vchat-layout">
					<div class="wp-cortex-vchat-main">
						<div class="wp-cortex-card wp-cortex-vchat-summary">
							<h2>
								<?php esc_html_e( 'Summary', 'wp-cortex' ); ?>
								<button type="button" class="button" id="wp-cortex-vchat-summarize"></button>
							</h2>
							<p class="wp-cortex-vchat-summary-status" id="wp-cortex-vchat-summary-status"></p>
							<div class="wp-cortex-vchat-summary-text" id="wp-cortex-vchat-summary-text"></div>
							<div id="wp-cortex-vchat-pages"></div>
						</div>
						<div class="wp-cortex-card">
							<h2><?php esc_html_e( 'Conversation', 'wp-cortex' ); ?></h2>
							<div class="wp-cortex-vchat-transcript" id="wp-cortex-vchat-transcript"></div>
						</div>
					</div>
					<div class="wp-cortex-vchat-side">
						<div class="wp-cortex-card">
							<h2><?php esc_html_e( 'Contact details', 'wp-cortex' ); ?></h2>
							<div id="wp-cortex-vchat-contact"></div>
						</div>
						<?php if ( Settings::leads_enabled() ) : ?>
							<div class="wp-cortex-card wp-cortex-vchat-lead">
								<h2><?php esc_html_e( 'Lead', 'wp-cortex' ); ?></h2>
								<div id="wp-cortex-vchat-lead"></div>
							</div>
							<div class="wp-cortex-card wp-cortex-vchat-attribution">
								<h2><?php esc_html_e( 'How the visitor found the site', 'wp-cortex' ); ?></h2>
								<div id="wp-cortex-vchat-attribution"></div>
							</div>
						<?php endif; ?>
						<form class="wp-cortex-card" id="wp-cortex-vchat-forward-form">
							<h2><label for="wp-cortex-vchat-forward-to"><?php esc_html_e( 'Forward by email', 'wp-cortex' ); ?></label></h2>
							<p class="wp-cortex-vchat-forward-hint is-stale" id="wp-cortex-vchat-forward-warning" hidden></p>
							<input type="text" class="large-text" id="wp-cortex-vchat-forward-to" maxlength="2000" placeholder="<?php esc_attr_e( 'name@example.com, other@example.com', 'wp-cortex' ); ?>" required>
							<p class="description">
								<?php
								printf(
									/* translators: %d: maximum number of recipients. */
									esc_html__( 'Up to %d addresses. Sent with the contact details, note, full conversation and, if available, the summary through the site\'s mail setup (for example an SMTP plugin); replies go to the visitor\'s email when known.', 'wp-cortex' ),
									(int) VisitorChatMailer::MAX_RECIPIENTS
								);
								?>
							</p>
							<label class="screen-reader-text" for="wp-cortex-vchat-forward-message"><?php esc_html_e( 'Message', 'wp-cortex' ); ?></label>
							<textarea class="large-text" rows="3" id="wp-cortex-vchat-forward-message" maxlength="<?php echo esc_attr( (string) VisitorChatMailer::MAX_MESSAGE ); ?>" placeholder="<?php esc_attr_e( 'Optional message, for example: Please call this client back.', 'wp-cortex' ); ?>"></textarea>
							<p><label><input type="checkbox" id="wp-cortex-vchat-forward-summarize" checked> <?php esc_html_e( 'Include an up-to-date summary', 'wp-cortex' ); ?> <span class="wp-cortex-muted"><?php esc_html_e( '(a new summary is generated if needed)', 'wp-cortex' ); ?></span></label></p>
							<p class="wp-cortex-vchat-forward-hint" id="wp-cortex-vchat-forward-hint" hidden></p>
							<p class="wp-cortex-actions"><button type="submit" class="button button-primary" id="wp-cortex-vchat-forward-send"><?php esc_html_e( 'Send', 'wp-cortex' ); ?></button></p>
							<p class="description" id="wp-cortex-vchat-forwarded"></p>
						</form>
						<form class="wp-cortex-card" id="wp-cortex-vchat-note-form">
							<h2><label for="wp-cortex-vchat-note"><?php esc_html_e( 'Note', 'wp-cortex' ); ?></label></h2>
							<textarea class="large-text" rows="5" id="wp-cortex-vchat-note" maxlength="<?php echo esc_attr( (string) VisitorChatStore::MAX_NOTE ); ?>" placeholder="<?php esc_attr_e( 'For example: forwarded to the sales team.', 'wp-cortex' ); ?>"></textarea>
							<p class="wp-cortex-actions"><button type="submit" class="button button-primary"><?php esc_html_e( 'Save note', 'wp-cortex' ); ?></button></p>
						</form>
						<div class="wp-cortex-card">
							<h2><?php esc_html_e( 'Actions', 'wp-cortex' ); ?></h2>
							<p class="wp-cortex-actions">
								<button type="button" class="button" id="wp-cortex-vchat-toggle-read"></button>
								<button type="button" class="button wp-cortex-button-delete" id="wp-cortex-vchat-delete"><?php esc_html_e( 'Delete conversation', 'wp-cortex' ); ?></button>
							</p>
						</div>
					</div>
				</div>
			</div>
		</div>
		<?php
	}
}
