<?php
/*
 * Plugin name: Simple Multisite Crosspost – Metabox.io
 * Author: Misha Rudrastyh
 * Author URI: https://rudrastyh.com
 * Description: Provides better compatibility with metabox.io
 * Version: 1.0
 * Plugin URI: https://rudrastyh.com/support/metabox-io
 * Network: true
 */

class Rudr_SMC_Metaboxio {

	function __construct() {
		add_filter( 'rudr_pre_crosspost_meta', array( $this, 'process_fields' ), 10, 3 );
		add_filter( 'rudr_pre_crosspost_termmeta', array( $this, 'process_fields' ), 10, 3 );
		add_filter( 'rudr_pre_crosspost_content', array( $this, 'process_blocks' ), 10, 2 );

		// https://docs.metabox.io/extensions/mb-frontend-submission/
		add_action( 'rwmb_frontend_after_process', array( $this, 'frontend_submit' ), 10, 2 );
	}

	public function process_fields( $meta_value, $meta_key, $object_id ) {

		// if no ACF
		if( ! function_exists( 'rwmb_get_field_settings' ) ) {
			return $meta_value;
		}

		$new_blog_id = get_current_blog_id();
		restore_current_blog();

		// we can not just use acf_get_field( $meta_key ) because it won't work for nested repeater fields
		if( 'rudr_pre_crosspost_termmeta' == current_filter() ) {
			$object_type = array( 'object_type' => 'term' );
		} else {
			$object_type = array( 'object_type' => 'post' );
		}

		$field = rwmb_get_field_settings( $meta_key, $object_type, $object_id );

		switch_to_blog( $new_blog_id );

		// not an ACF field specifically
		if( ! $field ) {
			return $meta_value;
		}

		return $this->process_field_by_type( $meta_value, $field[ 'type' ] );

	}


	public function process_field_by_type( $meta_value, $field_type ) {

		switch( $field_type ) {
			case 'file_advanced':
			case 'file_upload':
			case 'image_advanced':
			case 'image_upload':
			case 'single_image':
			case 'video': {
				$meta_value = $this->process_attachment_field( $meta_value );
				break;
			}
			case 'post': {
				$meta_value = $this->process_relationships_field( $meta_value );
				break;
			}
		}

		return $meta_value;

	}


	private function process_attachment_field( $meta_value ) {

 		$meta_value = maybe_unserialize( $meta_value );
 		// let's make it array anyway for easier processing
 		$ids = is_array( $meta_value ) ? $meta_value : array( $meta_value );

 		$new_blog_id = get_current_blog_id();
 		restore_current_blog();
 		$attachments_data = array();
 		foreach( $ids as $id ) {
 			$attachments_data[] = Rudr_Simple_Multisite_Crosspost::prepare_attachment_data( $id );
 		}
 		switch_to_blog( $new_blog_id );
 		$attachment_ids = array();
 		foreach( $attachments_data as $attachment_data ) {
 			$upload = Rudr_Simple_Multisite_Crosspost::maybe_copy_image( $attachment_data );
 			if( isset( $upload[ 'id' ] ) && $upload[ 'id' ] ) {
 				$attachment_ids[] = $upload[ 'id' ];
 			}
 		}

 		return is_array( $meta_value ) ? maybe_serialize( $attachment_ids ) : ( $attachment_ids ? reset( $attachment_ids ) : 0 );

 	}


	private function process_relationships_field( $meta_value ) {

		$meta_value = maybe_unserialize( $meta_value );
		$ids = is_array( $meta_value ) ? $meta_value : array( $meta_value );
		$new_blog_id = get_current_blog_id();
		restore_current_blog();

		$crossposted_ids = array();
		$crossposted_skus = array(); // we will process it after switching to a new blog
		foreach( $ids as $id ) {
			$post_type = get_post_type( $id );
			if( 'product' === $post_type && 'sku' === Rudr_Simple_Multisite_Woo_Crosspost::connection_type() ) {
				$crossposted_skus[] = get_post_meta( $id, '_sku', true );
			} else {
				if( $new_id = Rudr_Simple_Multisite_Crosspost::is_crossposted( $id, $new_blog_id ) ) {
					$crossposted_ids[] = $new_id;
				}
			}
		}

		switch_to_blog( $new_blog_id );

		// do we have some crossposted SKUs here? let's check if there are some in a new blog
		if( $crossposted_skus ) {
			foreach( $crossposted_skus as $crossposted_sku ) {
				if( $new_id = Rudr_Simple_Multisite_Woo_Crosspost::maybe_is_crossposted_product__sku( array( 'sku' => $crossposted_sku ) ) ) {
					$crossposted_ids[] = $new_id;
				}
			}
		}

		return is_array( $meta_value ) ? maybe_serialize( $crossposted_ids ) : ( $crossposted_ids ? reset( $crossposted_ids ) : 0 );

	}



	public function process_blocks( $content, $new_blog_id ) {

		// no blocks, especially no acf ones
		if( ! has_blocks( $content ) ) {
			return $content;
		}

		$blocks = parse_blocks( $content );

		// let's do the shit
		foreach( $blocks as &$block ) {
			$block = $this->process_block( $block, $new_blog_id );
		}

		$processed_content = '';
		foreach( $blocks as $processed_block ) {
			if( $processed_rendered_block = $this->render_block( $processed_block ) ) {
				$processed_content .= "{$processed_rendered_block}\n\n";
			}
		}

		return $processed_content;
	}

	public function process_block( $block, $new_blog_id ) {

		// first – process inner blocks
		if( $block[ 'innerBlocks' ] ) {
			foreach( $block[ 'innerBlocks' ] as &$innerBlock ) {
				$innerBlock = $this->process_block( $innerBlock, $new_blog_id );
			}
		}

		// second – once the block itself non metabox.io, we do nothing
		if( empty( $block[ 'blockName' ] ) || 0 !== strpos( $block[ 'blockName' ], 'meta-box/' ) ) {
			return $block;
		}

		$metabox_block = mb_get_block( $block[ 'blockName' ] );
		$field_types = wp_list_pluck( $metabox_block->render_callback[0]->meta_box[ 'fields' ], 'type', 'id' );

		// skip the block if it has empty data
		if( empty( $block[ 'attrs' ][ 'data' ] ) || ! $block[ 'attrs' ][ 'data' ] ) {
			return $block;
		}

		// now we are going to work with fields!
		$fields = array();
		foreach( $block[ 'attrs' ][ 'data' ] as $key => &$value ) {
			switch_to_blog( $new_blog_id );
			$value = $this->process_field_by_type( $value, $field_types[ $key ] );
			restore_current_blog();
		}

		return $block;

	}


	public function render_block( $processed_block ) {

		if( empty( $processed_block[ 'blockName' ] ) ){
			return false;
		}

		$processed_rendered_block = '';
		// block name
		$processed_rendered_block .= "<!-- wp:{$processed_block[ 'blockName' ]}";
		// data
		if( $processed_block[ 'attrs' ] ) {
			$processed_rendered_block .= ' ' . wp_unslash( wp_json_encode( $processed_block[ 'attrs' ] ) );
		}

		if( ! $processed_block[ 'innerHTML' ] && ! $processed_block[ 'innerBlocks' ] ) {
			$processed_rendered_block .= " /-->";
		} else {
			// ok now we have either html or innerblocks or both
			// but we are going to use innerContent to populate that
			$innerBlockIndex = 0;
			$processed_rendered_block .= " -->";
			foreach( $processed_block[ 'innerContent' ] as $piece ) {
				if( isset( $piece ) && $piece ) {
					$processed_rendered_block .= $piece; // innerHTML
				} else {
					if( $processed_inner_block = $this->render_block( $processed_block[ 'innerBlocks' ][$innerBlockIndex] ) ) {
						$processed_rendered_block .= $processed_inner_block;
					}
					$innerBlockIndex++;
				}
			}
			$processed_rendered_block .= "<!-- /wp:{$processed_block[ 'blockName' ]} -->";
		}

		return $processed_rendered_block;

	}


	public function frontend_submit( $config, $post_id ) {

		if( $post = get_post( $post_id ) ) {

			$crosspost_instance = new Rudr_Simple_Multisite_Crosspost();
			$blogs = $crosspost_instance->get_blogs();
      $blog_ids = array_keys( $blogs );
			$crosspost_instance->crosspost( $post, $blog_ids );

		}

	}

}


new Rudr_SMC_Metaboxio;
