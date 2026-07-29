<?php
/**
 * Keeps Knowledge Base articles in order inside their parent.
 *
 * Articles are ordered by menu_order per parent. This class owns the REST
 * reorder endpoint used by drag-and-drop, and the sibling renumbering the
 * article writer needs after a save moves an article between parents.
 *
 * @package    Decker
 * @subpackage Decker/includes
 */

defined( 'ABSPATH' ) || exit;

/**
 * Class Decker_Kb_Reorder
 */
class Decker_Kb_Reorder {

	/**
	 * REST callback to handle drag-and-drop reordering.
	 *
	 * Expects: moved_id, new_parent_id, new_order (array of IDs), old_parent_id, old_order (array of IDs).
	 *
	 * @param WP_REST_Request $request The request.
	 * @return WP_REST_Response
	 */
	public function reorder_articles( $request ) {
		$args = $this->parse_reorder_request( $request );

		if ( ! $args['moved_id'] ) {
			return Decker_Kb::error_response( __( 'Invalid moved ID.', 'decker' ) );
		}

		// Update parent for moved item.
		wp_update_post(
			array(
				'ID'          => $args['moved_id'],
				'post_parent' => $args['new_parent_id'],
			)
		);

		// Apply new order for target siblings.
		$this->apply_explicit_order( $args['new_parent_id'], $args['new_order'] );

		// Recalculate old siblings if provided.
		$this->reorder_previous_parent( $args['old_parent_id'], $args['old_order'] );

		return new WP_REST_Response( array( 'success' => true ), 200 );
	}

	/**
	 * Normalize the reorder request params into typed values.
	 *
	 * The old_parent_id value stays nullable: null means "not provided" (skip
	 * old-side recalculation), while 0 means the root parent.
	 *
	 * @param WP_REST_Request $request The request.
	 * @return array {
	 *     @type int      $moved_id      Moved post ID (0 default).
	 *     @type int      $new_parent_id Target parent ID (0 default).
	 *     @type int|null $old_parent_id Previous parent ID (null when absent).
	 *     @type int[]    $new_order     Ordered IDs for the target parent.
	 *     @type int[]    $old_order     Ordered IDs for the previous parent.
	 * }
	 */
	private function parse_reorder_request( $request ) {
		$params = $request->get_params();

		return array(
			'moved_id'      => isset( $params['moved_id'] ) ? intval( $params['moved_id'] ) : 0,
			'new_parent_id' => isset( $params['new_parent_id'] ) ? intval( $params['new_parent_id'] ) : 0,
			'old_parent_id' => isset( $params['old_parent_id'] ) ? intval( $params['old_parent_id'] ) : null,
			'new_order'     => isset( $params['new_order'] ) && is_array( $params['new_order'] ) ? array_map( 'intval', $params['new_order'] ) : array(),
			'old_order'     => isset( $params['old_order'] ) && is_array( $params['old_order'] ) ? array_map( 'intval', $params['old_order'] ) : array(),
		);
	}

	/**
	 * Recalculate the previous parent's siblings after a move.
	 *
	 * No-op when the old parent was not provided (null sentinel). Applies the
	 * explicit order when supplied, otherwise resequences the remaining children.
	 *
	 * @param int|null $old_parent_id Previous parent ID (null skips).
	 * @param array    $old_order     Ordered IDs for the previous parent.
	 */
	private function reorder_previous_parent( $old_parent_id, array $old_order ) {
		if ( null === $old_parent_id ) {
			return;
		}

		if ( $old_order ) {
			$this->apply_explicit_order( $old_parent_id, $old_order );
		} else {
			$this->recalculate_siblings( $old_parent_id );
		}
	}

	/**
	 * Recalculate sequential menu_order for all children of a parent.
	 *
	 * @param int $parent_id Parent post ID.
	 */
	public function recalculate_siblings( $parent_id ) {
		$children = get_children(
			array(
				'post_type'      => 'decker_kb',
				'post_parent'    => intval( $parent_id ),
				'orderby'        => 'menu_order',
				'order'          => 'ASC',
				'numberposts'    => -1,
			)
		);
		$index = 0;
		foreach ( $children as $child ) {
			wp_update_post(
				array(
					'ID'         => $child->ID,
					'menu_order' => $index++,
				)
			);
		}
	}

	/**
	 * Recalculate siblings placing the given post at desired position.
	 *
	 * @param int      $parent_id Parent ID.
	 * @param int      $post_id   Post ID to position.
	 * @param int|null $position  Desired index (0-based). If null, just recalc sequentially.
	 */
	public function recalculate_siblings_with_position( $parent_id, $post_id, $position ) {
		$children = get_children(
			array(
				'post_type'      => 'decker_kb',
				'post_parent'    => intval( $parent_id ),
				'orderby'        => 'menu_order',
				'order'          => 'ASC',
				'numberposts'    => -1,
			)
		);

		$ids = array();
		foreach ( $children as $child ) {
			if ( intval( $child->ID ) !== intval( $post_id ) ) {
				$ids[] = intval( $child->ID );
			}
		}
		if ( null === $position || $position < 0 ) {
			$ids[] = intval( $post_id );
		} else {
			$position = min( $position, count( $ids ) );
			array_splice( $ids, $position, 0, array( intval( $post_id ) ) );
		}

		$this->apply_explicit_order( $parent_id, $ids );
	}

	/**
	 * Apply explicit ordering to children list by IDs.
	 *
	 * @param int   $parent_id Parent post ID.
	 * @param array $ordered_ids Ordered IDs of children.
	 */
	private function apply_explicit_order( $parent_id, $ordered_ids ) {
		$index = 0;
		foreach ( $ordered_ids as $cid ) {
			wp_update_post(
				array(
					'ID'          => intval( $cid ),
					'post_parent' => intval( $parent_id ),
					'menu_order'  => $index++,
				)
			);
		}
	}
}
