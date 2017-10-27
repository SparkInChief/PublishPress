<?php
/**
 * @package     PublishPress\Notifications
 * @author      PressShack <help@pressshack.com>
 * @copyright   Copyright (C) 2017 PressShack. All rights reserved.
 * @license     GPLv2 or later
 * @since       1.0.0
 */

namespace PublishPress\Notifications\Workflow\Step\Event_Content\Filter;

use PublishPress\Notifications\Workflow\Step\Event\Filter\Filter_Interface;

class Post_Type extends Base implements Filter_Interface {

	const META_KEY_POST_TYPE = '_psppno_posttype';

	/**
	 * Function to render and returnt the HTML markup for the
	 * Field in the form.
	 *
	 * @return string
	 */
	public function render() {
		echo $this->get_service( 'twig' )->render(
			'workflow_filter_multiple_select.twig',
			[
				'name'    => "publishpress_notif[{$this->step_name}_filters][post_type]",
				'id'      => "publishpress_notif_{$this->step_name}_filters_post_type",
				'options' => $this->get_options(),
				'labels'  => [
					'label' => esc_html__( 'Post Type', 'publishpress' ),
					'any'   => esc_html__( '- any type -', 'publishpress' ),
				]
			]
		);
	}

	/**
	 * Returns a list of post types in the options format
	 *
	 * @return array
	 */
	protected function get_options() {
		$post_types = $this->get_post_types();
		$options    = [];
		$metadata   = (array) $this->get_metadata( static::META_KEY_POST_TYPE );

		foreach ( $post_types as $slug => $label ) {
			$options[] = [
				'value'    => $slug,
				'label'    => $label,
				'selected' => in_array( $slug, $metadata ),
			];
		}

		return $options;
	}

	/**
	 * Function to save the metadata from the metabox
	 *
	 * @param int     $id
	 * @param WP_Post $post
	 */
	public function save_metabox_data( $id, $post ) {
		if ( ! isset( $_POST['publishpress_notif']["{$this->step_name}_filters"]['post_type'] ) ) {
			$values = [];
		} else {
			$values = $_POST['publishpress_notif']["{$this->step_name}_filters"]['post_type'];
		}

		$this->update_metadata_array( $id, static::META_KEY_POST_TYPE, $values, true );
	}

	/**
	 * Filters and returns the arguments for the query which locates
	 * workflows that should be executed.
	 *
	 * @param array $query_args
	 * @param array $action_args
	 * @return array
	 */
	public function get_run_workflow_query_args( $query_args, $action_args ) {

		// From
		$query_args['meta_query'][] = [
			[
				'key'     => static::META_KEY_POST_TYPE,
				'value'   => $action_args['post']->post_type,
				'type'    => 'CHAR',
				'compare' => '=',
			],
		];

		return parent::get_run_workflow_query_args( $query_args, $action_args );
	}
}