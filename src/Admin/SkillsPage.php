<?php
/**
 * Skills screen: saved chat procedures.
 *
 * @package WPCortex
 */

namespace WPCortex\Admin;

use WPCortex\Chat\SkillStore;

defined( 'ABSPATH' ) || exit;

/**
 * Server-rendered shell; assets/js/skills.js lists, edits and deletes the skills.
 */
final class SkillsPage {

	/**
	 * Renders the page.
	 */
	public function render(): void {
		if ( ! current_user_can( 'manage_options' ) ) {
			return;
		}
		?>
		<div class="wrap wp-cortex-wrap" id="wp-cortex-skills">
			<h1 class="wp-heading-inline"><?php esc_html_e( 'Cortex Skills', 'wp-cortex' ); ?></h1>
			<button type="button" class="page-title-action" id="wp-cortex-skill-add"><?php esc_html_e( 'Add skill', 'wp-cortex' ); ?></button>
			<hr class="wp-header-end">

			<p class="wp-cortex-intro">
				<?php esc_html_e( 'Skills are procedures the chat assistant follows when a request matches them, for example how to reach a settings tab. The assistant can propose a skill after a task; it is only saved when you confirm it in the chat. Inactive skills are not offered to the assistant.', 'wp-cortex' ); ?>
			</p>

			<div class="notice inline" id="wp-cortex-skills-notice" hidden><p></p></div>

			<form class="wp-cortex-card wp-cortex-skill-form" id="wp-cortex-skill-form" hidden>
				<h2 id="wp-cortex-skill-form-title"><?php esc_html_e( 'Add skill', 'wp-cortex' ); ?></h2>
				<input type="hidden" name="id" value="0">
				<div class="wp-cortex-field">
					<label class="wp-cortex-field-label" for="wp-cortex-skill-name"><?php esc_html_e( 'Name', 'wp-cortex' ); ?></label>
					<div class="wp-cortex-field-control">
						<input type="text" class="regular-text code" id="wp-cortex-skill-name" name="name" maxlength="<?php echo esc_attr( (string) SkillStore::MAX_NAME ); ?>" required>
						<p class="description"><?php esc_html_e( 'Lowercase words joined by hyphens, for example open-chat-settings.', 'wp-cortex' ); ?></p>
					</div>
				</div>
				<div class="wp-cortex-field">
					<label class="wp-cortex-field-label" for="wp-cortex-skill-description"><?php esc_html_e( 'When to use', 'wp-cortex' ); ?></label>
					<div class="wp-cortex-field-control">
						<input type="text" class="large-text" id="wp-cortex-skill-description" name="description" maxlength="<?php echo esc_attr( (string) SkillStore::MAX_DESCRIPTION ); ?>" required>
						<p class="description"><?php esc_html_e( 'One sentence the assistant uses to decide whether the skill matches a request.', 'wp-cortex' ); ?></p>
					</div>
				</div>
				<div class="wp-cortex-field">
					<label class="wp-cortex-field-label" for="wp-cortex-skill-instructions"><?php esc_html_e( 'Instructions', 'wp-cortex' ); ?></label>
					<div class="wp-cortex-field-control">
						<textarea class="large-text code" rows="8" id="wp-cortex-skill-instructions" name="instructions" maxlength="<?php echo esc_attr( (string) SkillStore::MAX_INSTRUCTIONS ); ?>" required></textarea>
						<p class="description"><?php esc_html_e( 'Numbered steps naming the tools and their arguments, for example: 1. Call open_admin_page with page "admin.php?page=wp-cortex" and tab "Chat".', 'wp-cortex' ); ?></p>
					</div>
				</div>
				<div class="wp-cortex-field">
					<span class="wp-cortex-field-label"><?php esc_html_e( 'Status', 'wp-cortex' ); ?></span>
					<div class="wp-cortex-field-control">
						<label><input type="checkbox" name="active" value="1" checked> <?php esc_html_e( 'Active', 'wp-cortex' ); ?></label>
					</div>
				</div>
				<p class="wp-cortex-actions">
					<button type="submit" class="button button-primary"><?php esc_html_e( 'Save skill', 'wp-cortex' ); ?></button>
					<button type="button" class="button" id="wp-cortex-skill-cancel"><?php esc_html_e( 'Cancel', 'wp-cortex' ); ?></button>
				</p>
			</form>

			<table class="wp-list-table widefat fixed striped wp-cortex-skills-table">
				<thead>
					<tr>
						<th scope="col" class="column-primary"><?php esc_html_e( 'Skill', 'wp-cortex' ); ?></th>
						<th scope="col" class="wp-cortex-col-source"><?php esc_html_e( 'Source', 'wp-cortex' ); ?></th>
						<th scope="col" class="wp-cortex-col-uses"><?php esc_html_e( 'Uses', 'wp-cortex' ); ?></th>
						<th scope="col" class="wp-cortex-col-date"><?php esc_html_e( 'Last used', 'wp-cortex' ); ?></th>
						<th scope="col" class="wp-cortex-col-status"><?php esc_html_e( 'Status', 'wp-cortex' ); ?></th>
					</tr>
				</thead>
				<tbody id="wp-cortex-skills-list">
					<tr><td colspan="5"><?php esc_html_e( 'Loading…', 'wp-cortex' ); ?></td></tr>
				</tbody>
			</table>
		</div>
		<?php
	}
}
