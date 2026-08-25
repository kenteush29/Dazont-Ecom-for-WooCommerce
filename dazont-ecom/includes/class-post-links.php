<?php
defined( 'ABSPATH' ) || exit;

/**
 * Internal linking on articles and pages — the other direction of the mesh.
 *
 * The category side has been linked for a while: a category description
 * points at its branch, at its siblings, and at the articles that talk about
 * its subject. The articles pointed back at nothing, which is the half that
 * actually feeds a shop: an article ranks on a question, and the link inside
 * it is what turns a reader into a visitor of the category that sells the
 * answer.
 *
 * There is no second linking engine here. The pass is
 * DZE_Category_Content::weave() — the same prompt, the same anchor rule, the
 * same safety nets, the same usage accounting. This class only answers the
 * two questions that differ for an article: what it may link to, and how many
 * links a text that length should carry.
 *
 * It has no screen and no settings of its own: it is reached by the Writing
 * queue, which the Automation module feeds.
 */
final class DZE_Post_Links {

	/** The post types this works on. Products have their own pipeline. */
	public const TYPES = [ 'post', 'page' ];

	/** One link per this many words, within reason. */
	private const PER_WORDS = 150;

	private const MIN_LINKS = 2;
	private const MAX_LINKS = 10;

	/** A text shorter than this has nowhere to put a link that reads well. */
	private const MIN_WORDS = 150;

	/** How many posts the census looks at, newest edited first. */
	private const SCAN = 500;

	/**
	 * Every article and page, cheaply: its length and how many links it holds.
	 *
	 * One query, and the content is weighed here rather than carried around:
	 * what the ranking needs afterwards is two integers per post.
	 *
	 * @return array<int,array{title:string,type:string,words:int,out:int,target:int}>
	 */
	public static function census( bool $force = false ): array {
		$cached = $force ? false : get_transient( 'dze_pl_census' );
		if ( is_array( $cached ) ) {
			return $cached;
		}
		global $wpdb;
		$types = "'" . implode( "','", array_map( 'esc_sql', self::TYPES ) ) . "'";
		$rows  = (array) $wpdb->get_results(
			// phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared -- type list built from a constant.
			"SELECT ID, post_title, post_type, post_content FROM {$wpdb->posts}
			 WHERE post_status = 'publish' AND post_type IN ({$types})
			 ORDER BY post_modified DESC LIMIT " . (int) self::SCAN,
			ARRAY_A
		);
		$out  = [];
		$lang = class_exists( 'DZE_Category_Content' ) ? DZE_Category_Content::default_lang() : '';
		foreach ( $rows as $r ) {
			$id = (int) $r['ID'];
			// Translations belong to WPML: the shop is linked in its main language.
			if ( '' !== $lang && self::lang_of( $id, (string) $r['post_type'] ) !== $lang ) {
				continue;
			}
			$words = str_word_count( wp_strip_all_tags( (string) $r['post_content'] ) );
			$out[ $id ] = [
				'title'  => (string) $r['post_title'],
				'type'   => (string) $r['post_type'],
				'words'  => $words,
				'out'    => (int) preg_match_all( '/<a\s[^>]*href=/i', (string) $r['post_content'] ),
				'target' => self::target_links( $words ),
			];
		}
		set_transient( 'dze_pl_census', $out, 6 * HOUR_IN_SECONDS );
		return $out;
	}

	/** How many internal links a text that long should carry. */
	public static function target_links( int $words ): int {
		if ( $words < self::MIN_WORDS ) {
			return 0;
		}
		return (int) max( self::MIN_LINKS, min( self::MAX_LINKS, (int) round( $words / self::PER_WORDS ) ) );
	}

	/** WPML's language for one post, '' when WPML is not active. */
	private static function lang_of( int $post_id, string $type ): string {
		$details = apply_filters( 'wpml_element_language_details', null, [
			'element_id'   => $post_id,
			'element_type' => 'post_' . $type,
		] );
		$code = is_array( $details ) ? (string) ( $details['language_code'] ?? '' ) : '';
		return '' !== $code ? $code : (string) apply_filters( 'wpml_default_language', '' );
	}

	/**
	 * What this article may link to, closest first.
	 *
	 * Product categories come first on purpose: an article exists to send a
	 * reader somewhere he can buy. Then the neighbouring articles and pages,
	 * then whatever else the sitemap knows about — read from cache only,
	 * nobody waits on an HTTP call to build a list.
	 *
	 * @return array<int,array{label:string,url:string,kind:string,score:int}>
	 */
	public static function pool( int $post_id ): array {
		$post = get_post( $post_id );
		if ( ! $post || ! class_exists( 'DZE_Category_Content' ) ) {
			return [];
		}
		$needle = DZE_Category_Content::tokens(
			$post->post_title . ' ' . wp_trim_words( wp_strip_all_tags( (string) $post->post_content ), 200, '' )
		);
		$pool = [];
		$add  = static function ( string $label, string $url, string $kind, int $score ) use ( &$pool ): void {
			$key = untrailingslashit( $url );
			if ( '' === $url || isset( $pool[ $key ] ) ) {
				return;
			}
			$pool[ $key ] = [ 'label' => $label, 'url' => $url, 'kind' => $kind, 'score' => $score ];
		};

		// 1. The categories this article talks about. Shared wording decides:
		//    an article on camo patterns belongs next to the camo clothing
		//    category, not next to every category of a tactical shop.
		$cats = [];
		foreach ( self::categories() as $cat ) {
			$hits = count( array_intersect( $needle, DZE_Category_Content::tokens( $cat['name'] ) ) );
			if ( $hits > 0 ) {
				$cat['score'] = $hits;
				$cats[]       = $cat;
			}
		}
		usort( $cats, static fn( $a, $b ) => $b['score'] <=> $a['score'] );
		foreach ( array_slice( $cats, 0, 8 ) as $cat ) {
			$add( $cat['name'], $cat['url'], 'product category', (int) $cat['score'] );
		}

		// 2. The articles and pages next door.
		$near = [];
		foreach ( self::census() as $id => $row ) {
			if ( (int) $id === (int) $post_id ) {
				continue;
			}
			$hits = count( array_intersect( $needle, DZE_Category_Content::tokens( $row['title'] ) ) );
			if ( $hits > 1 ) {
				$near[] = [ 'id' => (int) $id, 'row' => $row, 'score' => $hits ];
			}
		}
		usort( $near, static fn( $a, $b ) => $b['score'] <=> $a['score'] );
		foreach ( array_slice( $near, 0, 6 ) as $n ) {
			$add(
				$n['row']['title'],
				(string) get_permalink( $n['id'] ),
				'post' === $n['row']['type'] ? 'blog post' : 'page',
				(int) $n['score']
			);
		}

		// 3. Anything else the sitemap knows about, best match first.
		$cached = DZE_Category_Content::sitemap_cached()['urls'] ?? [];
		if ( $cached ) {
			$ranked = [];
			foreach ( $cached as $page ) {
				$hits = count( array_intersect( $needle, DZE_Category_Content::tokens( (string) $page['url'] ) ) );
				if ( $hits > 0 ) {
					$page['score'] = $hits;
					$ranked[]      = $page;
				}
			}
			usort( $ranked, static fn( $a, $b ) => $b['score'] <=> $a['score'] );
			foreach ( array_slice( $ranked, 0, 6 ) as $page ) {
				$add( (string) $page['label'], (string) $page['url'], 'sitemap page', (int) $page['score'] );
			}
		}

		$pool = array_values( $pool );
		usort( $pool, static function ( $a, $b ) {
			// A category always outranks a page of the same closeness: that is
			// the direction this whole pass exists to feed.
			$rank = static fn( $x ) => 'product category' === $x['kind'] ? 1 : 0;
			return [ $rank( $b ), $b['score'] ] <=> [ $rank( $a ), $a['score'] ];
		} );
		return $pool;
	}

	/**
	 * Product categories with their address, read once.
	 *
	 * @return array<int,array{name:string,url:string}>
	 */
	private static function categories(): array {
		$cached = get_transient( 'dze_pl_cats' );
		if ( is_array( $cached ) ) {
			return $cached;
		}
		$out   = [];
		$lang  = class_exists( 'DZE_Category_Content' ) ? DZE_Category_Content::default_lang() : '';
		$terms = get_terms( [
			'taxonomy'   => 'product_cat',
			'hide_empty' => true,
			'exclude'    => [ (int) get_option( 'default_product_cat' ) ],
		] );
		if ( ! is_wp_error( $terms ) ) {
			foreach ( $terms as $t ) {
				if ( '' !== $lang && class_exists( 'DZE_Category_Content' )
					&& DZE_Category_Content::lang_code( (int) $t->term_id ) !== $lang ) {
					continue;
				}
				$url = get_term_link( $t );
				if ( ! is_wp_error( $url ) ) {
					$out[] = [ 'name' => (string) $t->name, 'url' => (string) $url ];
				}
			}
		}
		set_transient( 'dze_pl_cats', $out, 6 * HOUR_IN_SECONDS );
		return $out;
	}

	/** How many links this article is short of, 0 when it has its share. */
	public static function shortfall( int $post_id ): int {
		$row = self::census()[ $post_id ] ?? null;
		if ( ! $row ) {
			$post = get_post( $post_id );
			if ( ! $post ) {
				return 0;
			}
			$words = str_word_count( wp_strip_all_tags( (string) $post->post_content ) );
			$row   = [
				'words'  => $words,
				'out'    => (int) preg_match_all( '/<a\s[^>]*href=/i', (string) $post->post_content ),
				'target' => self::target_links( $words ),
			];
		}
		return max( 0, (int) $row['target'] - (int) $row['out'] );
	}

	/**
	 * The linking pass on one article, returning the text with links added.
	 * Nothing is saved here — the queue decides what becomes of the result.
	 *
	 * @return string
	 */
	public static function add_links( int $post_id ): string {
		$post = get_post( $post_id );
		if ( ! $post ) {
			throw new RuntimeException( __( 'Article not found.', 'dazont-ecom' ) );
		}
		if ( ! class_exists( 'DZE_Category_Content' ) ) {
			throw new RuntimeException( __( 'The Category descriptions module holds the linking pass — switch it on.', 'dazont-ecom' ) );
		}
		$html  = (string) $post->post_content;
		$words = str_word_count( wp_strip_all_tags( $html ) );
		if ( $words < self::MIN_WORDS ) {
			throw new RuntimeException( __( 'This text is too short to carry an internal link that reads well.', 'dazont-ecom' ) );
		}
		$done  = DZE_Category_Content::linked_urls( $html );
		$known = [];
		foreach ( $done as $u ) {
			$known[ untrailingslashit( $u ) ] = true;
		}
		$links = [];
		foreach ( self::pool( $post_id ) as $l ) {
			if ( ! isset( $known[ untrailingslashit( $l['url'] ) ] ) ) {
				$links[] = $l;
			}
		}
		$room = self::target_links( $words ) - count( $done );
		if ( $room < 1 ) {
			throw new RuntimeException( __( 'This article already carries its share of internal links.', 'dazont-ecom' ) );
		}
		$res = DZE_Category_Content::weave(
			(string) $post->post_title,
			$html,
			DZE_Category_Content::lang_name( self::lang_of( $post_id, (string) $post->post_type ) ),
			$links,
			$room,
			[ 'label' => 'post' === $post->post_type ? 'ARTICLE' : 'PAGE' ]
		);
		return (string) $res['html'];
	}
}
