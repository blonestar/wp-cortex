<?php
/**
 * Lead report of the Leads screen.
 *
 * @package WPCortex
 */

namespace WPCortex\Leads;

use DateTimeImmutable;
use DateTimeZone;
use WPCortex\Chat\VisitorChatStore;

defined( 'ABSPATH' ) || exit;

/**
 * Aggregates visitor conversations and leads for a period: the key figures compared with
 * the previous period of the same length, the trend over time and the breakdowns by
 * channel, source and campaign, landing page, rating and intent.
 *
 * A conversation counts in the period it started, a lead in the period it left contact
 * details; the conversion rate is leads per conversation of the same period (at most 100%).
 */
final class LeadReport {

	/**
	 * Periods offered on the screen, in days (0 for all time).
	 */
	public const PERIODS = array( 7, 30, 90, 365, 0 );

	/**
	 * Rows in the source and landing page tables.
	 */
	private const TOP = 10;

	/**
	 * Builds the report.
	 *
	 * @param int    $days    Period in days, 0 for all time.
	 * @param string $channel Only this channel ("unknown" for conversations without attribution), empty for all.
	 * @return array<string, mixed>
	 */
	public function build( int $days, string $channel ): array {
		$now   = time();
		$from  = $days > 0 ? $now - $days * DAY_IN_SECONDS : 0;
		$prev  = $days > 0 ? $from - $days * DAY_IN_SECONDS : 0;
		$since = $days > 0 ? gmdate( 'Y-m-d H:i:s', $prev ) : '';
		$rows  = ( new VisitorChatStore() )->report_rows( $since );

		if ( '' !== $channel ) {
			$wanted = 'unknown' === $channel ? '' : $channel;
			$rows   = array_values( array_filter( $rows, static fn( $row ) => $row['channel'] === $wanted ) );
		}

		if ( ! $days ) {
			foreach ( $rows as $row ) {
				$from = min( $from ? $from : $now, self::time( $row['created_at'] ) );
			}
		}

		$current  = $this->slice( $rows, $from, $now );
		$previous = $days > 0 ? $this->slice( $rows, $prev, $from ) : null;

		return array(
			'from'      => gmdate( 'Y-m-d H:i:s', $from ? $from : $now ),
			'to'        => gmdate( 'Y-m-d H:i:s', $now ),
			'kpis'      => $this->kpis( $current, $previous ),
			'trend'     => $this->trend( $current, $from ? $from : $now, $now, $days ),
			'channels'  => $this->channels( $current ),
			'ratings'   => $this->count_leads( $current['leads'], 'lead_rating', array_merge( VisitorChatStore::LEAD_RATINGS, array( '' ) ) ),
			'intents'   => $this->count_leads( $current['leads'], 'lead_intent', array_merge( LeadQualifier::INTENTS, array( '' ) ) ),
			'statuses'  => $this->count_leads( $current['leads'], 'lead_status', VisitorChatStore::LEAD_STATUSES ),
			'campaigns' => $this->top( $current, static fn( $row ) => '' !== $row['source'] ? $row['source'] . "\0" . $row['medium'] . "\0" . $row['campaign'] : '' ),
			'landing'   => $this->top( $current, static fn( $row ) => $row['landing_path'] ),
		);
	}

	/**
	 * Conversations started and leads created within a time range.
	 *
	 * @param array $rows Report rows.
	 * @param int   $from Start (timestamp, included).
	 * @param int   $to   End (timestamp, excluded).
	 * @return array{chats: array, leads: array}
	 */
	private function slice( array $rows, int $from, int $to ): array {
		$in = static function ( string $date ) use ( $from, $to ): bool {
			$time = self::time( $date );

			return $time && $time >= $from && $time < $to + 1;
		};

		return array(
			'chats' => array_values( array_filter( $rows, static fn( $row ) => $in( (string) $row['created_at'] ) ) ),
			'leads' => array_values( array_filter( $rows, static fn( $row ) => $in( (string) $row['lead_at'] ) ) ),
		);
	}

	/**
	 * Key figures of the period and of the previous one.
	 *
	 * @param array      $current  Current slice.
	 * @param array|null $previous Previous slice, null without comparison.
	 * @return array<string, array{value: float|int|null, previous: float|int|null}>
	 */
	private function kpis( array $current, ?array $previous ): array {
		$figures = static function ( array $slice ): array {
			$chats = count( $slice['chats'] );
			$leads = count( $slice['leads'] );
			$days  = array();

			foreach ( $slice['leads'] as $row ) {
				$first = self::time( (string) $row['first_seen'] );

				if ( $first ) {
					$days[] = max( 0, self::time( (string) $row['lead_at'] ) - $first ) / DAY_IN_SECONDS;
				}
			}

			return array(
				'leads'         => $leads,
				'conversations' => $chats,
				'rate'          => $chats ? round( 100 * min( $leads, $chats ) / $chats, 1 ) : null,
				'hot'           => count( array_filter( $slice['leads'], static fn( $row ) => 'hot' === $row['lead_rating'] ) ),
				'days_to_lead'  => $days ? round( array_sum( $days ) / count( $days ), 1 ) : null,
			);
		};

		$now    = $figures( $current );
		$before = $previous ? $figures( $previous ) : array();
		$kpis   = array();

		foreach ( $now as $key => $value ) {
			$kpis[ $key ] = array(
				'value'    => $value,
				'previous' => $before[ $key ] ?? null,
			);
		}

		return $kpis;
	}

	/**
	 * Conversations and leads per day, week or month (site time zone).
	 *
	 * @param array $slice Current slice.
	 * @param int   $from  Start timestamp.
	 * @param int   $to    End timestamp.
	 * @param int   $days  Period in days, 0 for all time.
	 * @return array{unit: string, points: array}
	 */
	private function trend( array $slice, int $from, int $to, int $days ): array {
		$span = $to - $from;
		$unit = $span <= 92 * DAY_IN_SECONDS ? 'day' : ( $span <= 400 * DAY_IN_SECONDS ? 'week' : 'month' );
		$tz   = wp_timezone();

		$points = array();
		$cursor = self::bucket_start( ( new DateTimeImmutable( '@' . $from ) )->setTimezone( $tz ), $unit );
		$end    = ( new DateTimeImmutable( '@' . $to ) )->setTimezone( $tz );

		while ( $cursor <= $end && count( $points ) < 500 ) {
			$points[ $cursor->format( 'Y-m-d' ) ] = array(
				'date'          => $cursor->format( 'Y-m-d' ),
				'conversations' => 0,
				'leads'         => 0,
			);
			$cursor = $cursor->modify( '+1 ' . $unit );
		}

		foreach ( array( 'chats' => 'conversations', 'leads' => 'leads' ) as $list => $key ) {
			$column = 'chats' === $list ? 'created_at' : 'lead_at';

			foreach ( $slice[ $list ] as $row ) {
				$date   = new DateTimeImmutable( (string) $row[ $column ], new DateTimeZone( 'UTC' ) );
				$bucket = self::bucket_start( $date->setTimezone( $tz ), $unit )->format( 'Y-m-d' );

				if ( isset( $points[ $bucket ] ) ) {
					++$points[ $bucket ][ $key ];
				}
			}
		}

		return array(
			'unit'   => $unit,
			'points' => array_values( $points ),
		);
	}

	/**
	 * Start of the day, week (per the site's "Week starts on" setting) or month of a date.
	 *
	 * @param DateTimeImmutable $date Date in the site time zone.
	 * @param string            $unit day, week or month.
	 */
	private static function bucket_start( DateTimeImmutable $date, string $unit ): DateTimeImmutable {
		$date = $date->setTime( 0, 0 );

		if ( 'month' === $unit ) {
			return $date->modify( 'first day of this month' );
		}

		if ( 'week' === $unit ) {
			$offset = ( (int) $date->format( 'w' ) - (int) get_option( 'start_of_week', 1 ) + 7 ) % 7;

			return $date->modify( '-' . $offset . ' days' );
		}

		return $date;
	}

	/**
	 * Conversations, leads and conversion rate per channel, most leads first.
	 *
	 * @param array $slice Current slice.
	 * @return array<int, array<string, mixed>>
	 */
	private function channels( array $slice ): array {
		$rows = array();

		foreach ( array( 'chats' => 'conversations', 'leads' => 'leads' ) as $list => $key ) {
			foreach ( $slice[ $list ] as $row ) {
				$channel = '' !== (string) $row['channel'] ? (string) $row['channel'] : 'unknown';

				$rows[ $channel ] = $rows[ $channel ] ?? array(
					'channel'       => $channel,
					'conversations' => 0,
					'leads'         => 0,
				);
				++$rows[ $channel ][ $key ];
			}
		}

		return $this->rank( $rows );
	}

	/**
	 * Top values of a dimension (source / medium / campaign, landing page) by leads.
	 *
	 * @param array    $slice Current slice.
	 * @param callable $key   Row => dimension value, empty to skip the row.
	 * @return array<int, array<string, mixed>>
	 */
	private function top( array $slice, callable $key ): array {
		$rows = array();

		foreach ( array( 'chats' => 'conversations', 'leads' => 'leads' ) as $list => $count ) {
			foreach ( $slice[ $list ] as $row ) {
				$value = (string) $key( $row );

				if ( '' === $value ) {
					continue;
				}

				$rows[ $value ] = $rows[ $value ] ?? array(
					'key'           => $value,
					'conversations' => 0,
					'leads'         => 0,
				);
				++$rows[ $value ][ $count ];
			}
		}

		$top = array_slice( $this->rank( $rows ), 0, self::TOP );

		foreach ( $top as &$row ) {
			$parts = explode( "\0", $row['key'] );

			if ( 3 === count( $parts ) ) {
				$row['source']   = $parts[0];
				$row['medium']   = $parts[1];
				$row['campaign'] = $parts[2];
			}

			unset( $row['key'] );

			if ( 1 === count( $parts ) ) {
				$row['path'] = $parts[0];
			}
		}
		unset( $row );

		return $top;
	}

	/**
	 * Adds the conversion rate and sorts by leads, then conversations.
	 *
	 * @param array $rows Rows with conversations and leads.
	 * @return array<int, array<string, mixed>>
	 */
	private function rank( array $rows ): array {
		foreach ( $rows as &$row ) {
			$row['rate'] = $row['conversations'] ? round( 100 * min( $row['leads'], $row['conversations'] ) / $row['conversations'], 1 ) : null;
		}
		unset( $row );

		usort( $rows, static fn( $a, $b ) => array( $b['leads'], $b['conversations'] ) <=> array( $a['leads'], $a['conversations'] ) );

		return array_values( $rows );
	}

	/**
	 * Number of leads per value of a column, in the given order.
	 *
	 * @param array    $leads  Lead rows.
	 * @param string   $column Column.
	 * @param string[] $values Values to count ("" for none).
	 * @return array<string, int>
	 */
	private function count_leads( array $leads, string $column, array $values ): array {
		$counts = array_fill_keys( $values, 0 );

		foreach ( $leads as $row ) {
			$value = (string) $row[ $column ];
			$value = isset( $counts[ $value ] ) ? $value : $values[0];

			++$counts[ $value ];
		}

		return $counts;
	}

	/**
	 * Timestamp of a UTC MySQL date, 0 when empty.
	 *
	 * @param string $date Date.
	 */
	private static function time( string $date ): int {
		return '' !== $date && '0000-00-00 00:00:00' !== $date ? (int) strtotime( $date . ' UTC' ) : 0;
	}
}
