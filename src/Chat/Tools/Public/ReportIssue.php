<?php
/**
 * Visitor chat tool: report_issue.
 *
 * @package WPCortex
 */

namespace WPCortex\Chat\Tools\Public;

use WPCortex\Chat\IssueReportMailer;
use WPCortex\Chat\IssueReportStore;
use WPCortex\Chat\Tools\ToolContext;
use WPCortex\Settings;

defined( 'ABSPATH' ) || exit;

/**
 * Adds an issue report about the current page or another public page, or updates an
 * open report of the visitor's own conversation. Offered while public_chat_reports is on;
 * at most MAX_REPORTS reports per message.
 */
final class ReportIssue extends PublicTool {

	private const MAX_REPORTS = 3;

	/**
	 * Issue reports added or updated in this turn: report ID => whether it was updated.
	 *
	 * @var array<int, bool>
	 */
	private array $reports = array();

	/**
	 * Open issue reports of the visitor's stored conversation, loaded once per turn.
	 *
	 * @var array|null
	 */
	private ?array $open_reports = null;

	public function name(): string {
		return 'report_issue';
	}

	public function label(): string {
		return __( 'Report an issue', 'wp-cortex' );
	}

	/**
	 * Offered while issue reports are on.
	 *
	 * @param ToolContext $context Turn context.
	 */
	public function is_available( ToolContext $context ): bool {
		return (bool) Settings::get( 'public_chat_reports' );
	}

	/**
	 * What else decides whether the tool is offered.
	 */
	public function availability_note(): string {
		return __( 'Needs Visitor chat > Assistant actions > Issue reports.', 'wp-cortex' );
	}

	/**
	 * Description for the model.
	 *
	 * @param ToolContext $context Turn context.
	 */
	public function description( ToolContext $context ): string {
		return 'Reports a problem the visitor found on this website (for example a typo, a broken or missing image, a broken link, wrong or outdated information, a display problem or something that does not work) to the site team. Call it once per problem. When the visitor adds details, a correction or a screenshot about a problem already reported in this conversation, pass its report_id to update that report instead of adding a new one.';
	}

	/**
	 * Arguments.
	 *
	 * @param ToolContext $context Turn context.
	 * @return array<string, mixed>
	 */
	public function parameters( ToolContext $context ): ?array {
		return array(
			'type'       => 'object',
			'properties' => array(
				'report_id'   => array(
					'type'        => 'integer',
					'description' => 'Optional. ID of an already reported problem of this conversation (from the list in your instructions) to update with the new details; any image attached to the visitor\'s latest message is added to it. Omit it for a new problem.',
				),
				'category'    => array(
					'type'        => 'string',
					'description' => 'Kind of problem.',
					'enum'        => IssueReportStore::CATEGORIES,
				),
				'description' => array(
					'type'        => 'string',
					'description' => 'What is wrong and where on the page, in a few sentences, in the language of this website\'s content (it is read by the site team). Include the correction when the visitor gave one. When updating a report, this replaces its description: keep what is still true and add the new details.',
				),
				'excerpt'     => array(
					'type'        => 'string',
					'description' => 'Optional. The affected text exactly as it appears on the page (for example the misspelled sentence), or the name of the affected image, link or button.',
				),
				'post_id'     => array(
					'type'        => 'integer',
					'description' => 'Optional. ID of the page with the problem, from search_site results, when it is not the page the visitor is viewing. Omit it for the current page.',
				),
			),
			'required'   => array( 'category', 'description' ),
		);
	}

	/**
	 * System instruction lines, with the open reports of this conversation.
	 *
	 * @param ToolContext $context Turn context.
	 * @return string[]
	 */
	public function instructions( ToolContext $context ): array {
		$lines = array( 'If the visitor points out a problem on this website (for example a typo, a broken or missing image, a broken link, wrong or outdated information, a display problem or something that does not work), report it with report_issue so the site team can fix it. Unless the visitor says otherwise, the problem is on the page they are viewing. Ask one short question only when it is unclear what is wrong or on which page; otherwise report it right away, without asking for confirmation or contact details. Quote the affected text exactly when the visitor gives it. Report each problem once and never report something the visitor did not point out. When the visitor follows up on a problem already reported in this conversation (more details, a correction or a screenshot of it), update that report with report_id instead of reporting it again; report a new problem only when it is a different one.' );
		$open  = $this->open_reports( $this->visitor( $context ) );

		if ( $open ) {
			$lines[] = 'Problems already reported in this conversation (update them with report_id):';

			foreach ( $open as $report ) {
				$lines[] = sprintf(
					'- report_id %1$d (%2$s): %3$s%4$s',
					$report['id'],
					$report['category'],
					str_replace( array( "\r", "\n" ), ' ', mb_substr( $report['description'], 0, 300 ) ),
					'' !== $report['excerpt'] ? ' Quoted: «' . str_replace( array( "\r", "\n" ), ' ', mb_substr( $report['excerpt'], 0, 150 ) ) . '»' : ''
				);
			}
		}

		return $lines;
	}

	/**
	 * Adds or updates a report.
	 *
	 * @param array       $args    Arguments: report_id, category, description, excerpt, post_id.
	 * @param ToolContext $context Turn context.
	 * @return array<string, mixed>
	 */
	public function execute( array $args, ToolContext $context ) {
		$visitor = $this->visitor( $context );

		if ( count( $this->reports ) >= self::MAX_REPORTS ) {
			return array( 'error' => 'Too many reports in one message. Ask the visitor to send the remaining problems in a new message.' );
		}

		if ( '' === trim( (string) ( $args['description'] ?? '' ) ) ) {
			return array( 'error' => 'Describe the problem.' );
		}

		$images    = '' !== $visitor->image_name() ? array( $visitor->image_name() ) : array();
		$report_id = (int) ( $args['report_id'] ?? 0 );

		if ( $report_id > 0 ) {
			// Only open reports of the visitor's own conversation can be updated.
			$updated = in_array( $report_id, array_column( $this->open_reports( $visitor ), 'id' ), true )
				&& ( new IssueReportStore() )->amend(
					$report_id,
					$visitor->chat_id(),
					array(
						'category'    => (string) ( $args['category'] ?? '' ),
						'description' => (string) $args['description'],
						'excerpt'     => (string) ( $args['excerpt'] ?? '' ),
						'images'      => $images,
					)
				);

			if ( ! $updated ) {
				return array( 'error' => 'This report cannot be updated. Omit report_id to add a new report.' );
			}

			// A report added earlier in this turn stays "saved".
			if ( ! isset( $this->reports[ $report_id ] ) ) {
				$this->reports[ $report_id ] = true;
				$this->notice( $visitor, $report_id, true );
			}

			return array(
				'updated' => true,
				'note'    => 'In one short sentence, thank the visitor and tell them the details were added to their report. Do not promise when or whether it will be fixed.',
			);
		}

		$post_id  = (int) ( $args['post_id'] ?? 0 );
		$page_url = $visitor->page_url();

		if ( $post_id > 0 && $post_id !== $visitor->post_id() ) {
			$doc = $visitor->get_public_document( $post_id );

			if ( null === $doc ) {
				return array( 'error' => 'No published page with this ID. Omit post_id to report the page the visitor is viewing.' );
			}

			$page_url = (string) $doc['url'];
		} else {
			// The browser's post ID is kept only for pages in the public index.
			$post_id = null !== $visitor->current() ? $visitor->post_id() : 0;
		}

		$store = new IssueReportStore();
		$id    = $store->add(
			array(
				'chat_id'     => $visitor->chat_id(),
				'post_id'     => $post_id,
				'page_url'    => $page_url,
				'category'    => (string) ( $args['category'] ?? '' ),
				'description' => (string) $args['description'],
				'excerpt'     => (string) ( $args['excerpt'] ?? '' ),
				'images'      => $images,
			)
		);

		if ( ! $id ) {
			return array( 'error' => 'The report could not be saved.' );
		}

		$this->reports[ $id ] = false;
		$this->open_reports   = null;
		$this->notice( $visitor, $id, false );
		$report = $store->get( $id );

		if ( $report ) {
			( new IssueReportMailer() )->notify( $report );
		}

		return array(
			'reported'  => true,
			'report_id' => $id,
			'note'      => 'In one or two short sentences, thank the visitor and tell them the problem was passed on to the site team. Do not promise when or whether it will be fixed.',
		);
	}

	/**
	 * Adds the notice shown to the visitor for a report.
	 *
	 * @param PublicContext $visitor   Turn context.
	 * @param int           $report_id Report ID.
	 * @param bool          $updated   Whether an existing report was updated.
	 */
	private function notice( PublicContext $visitor, int $report_id, bool $updated ): void {
		$visitor->set_item(
			'report:' . $report_id,
			array(
				'role'      => 'notice',
				'text'      => $updated
					/* translators: %d: issue report ID. */
					? sprintf( __( 'Issue report #%d updated.', 'wp-cortex' ), $report_id )
					/* translators: %d: issue report ID. */
					: sprintf( __( 'Issue report #%d saved.', 'wp-cortex' ), $report_id ),
				'report_id' => $report_id,
			)
		);
	}

	/**
	 * Open issue reports of the visitor's stored conversation (none when the log is off).
	 *
	 * @param PublicContext $visitor Turn context.
	 * @return array<int, array{id: int, category: string, description: string, excerpt: string}>
	 */
	private function open_reports( PublicContext $visitor ): array {
		if ( null === $this->open_reports ) {
			$this->open_reports = $visitor->chat_id() > 0 ? ( new IssueReportStore() )->open_for_chat( $visitor->chat_id() ) : array();
		}

		return $this->open_reports;
	}
}
