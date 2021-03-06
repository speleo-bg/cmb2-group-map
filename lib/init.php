<?php
/**
 * CMB2_Group_Map
 *
 * @todo Make this work for other destination object types
 * @todo Document file/methods, etc
 */
class CMB2_Group_Map {

	/**
	 * Library version
	 */
	const VERSION = CMB2_GROUP_POST_MAP_VERSION;

	/**
	 * CMB2_Group_Map instance
	 *
	 * @var null
	 */
	protected static $single_instance = null;

	/**
	 * Allowed destination object types.
	 * (only post is fully supported right now)
	 *
	 * @var array
	 */
	protected $allowed_object_types = array( 'post', 'user', 'comment', 'term' );

	/**
	 * Array of group field config arrays
	 *
	 * @var array
	 */
	protected $group_fields = array();

	/**
	 * CMB2_Field object
	 *
	 * @var null
	 */
	protected static $current_field = null;

	/**
	 * Native post fields
	 *
	 * @var array
	 */
	public static $post_fields = array(
		'ID'                    => '',
		'post_author'           => '',
		'post_date'             => '',
		'post_date_gmt'         => '',
		'post_content'          => '',
		'post_content_filtered' => '',
		'post_title'            => '',
		'post_excerpt'          => '',
		'post_status'           => '',
		'post_type'             => '',
		'comment_status'        => '',
		'ping_status'           => '',
		'post_password'         => '',
		'post_name'             => '',
		'to_ping'               => '',
		'pinged'                => '',
		'post_modified'         => '',
		'post_modified_gmt'     => '',
		'post_parent'           => '',
		'menu_order'            => '',
		'post_mime_type'        => '',
		'guid'                  => '',
		'tax_input'             => '',
		'meta_input'            => '',
	);

	/**
	 * Native user fields
	 *
	 * @var array
	 */
	public static $user_fields = array(
		'ID'                   => '',
		'user_pass'            => '',
		'user_login'           => '',
		'user_nicename'        => '',
		'user_url'             => '',
		'user_email'           => '',
		'display_name'         => '',
		'nickname'             => '',
		'first_name'           => '',
		'last_name'            => '',
		'description'          => '',
		'rich_editing'         => '',
		'comment_shortcuts'    => '',
		'admin_color'          => '',
		'use_ssl'              => '',
		'user_registered'      => '',
		'show_admin_bar_front' => '',
		'role'                 => '',
	);

	/**
	 * Native comment fields
	 *
	 * @var array
	 */
	public static $comment_fields = array(
		'comment_agent'        => '',
		'comment_approved'     => '',
		'comment_author'       => '',
		'comment_author_email' => '',
		'comment_author_IP'    => '',
		'comment_author_url'   => '',
		'comment_content'      => '',
		'comment_date'         => '',
		'comment_date_gmt'     => '',
		'comment_karma'        => '',
		'comment_parent'       => '',
		'comment_post_ID'      => '',
		'comment_type'         => '',
		'comment_meta'         => '',
		'user_id'              => '',
	);

	/**
	 * Native term fields
	 *
	 * @var array
	 */
	public static $term_fields = array(
		'term'        => '',
		'taxonomy'    => '',
		'alias_of'    => '',
		'description' => '',
		'parent'      => '',
		'slug'        => '',
	);

	/**
	 * Library strings, filtered through cmb2_group_map_strings for translation.
	 *
	 * @var array
	 */
	public static $strings = array(
		'missing_required' => 'Missing required data.',
		'missing_nonce'    => 'Missing required validation nonce or failed nonce validation.',
		'delete_permanent' => 'This item will be detached from this post. Do you want to also delete it permanently?',
		'could_not_delete' => 'The item could not be deleted.',
		'item_id'          => '%s ID:',
	);

	/**
	 * Creates or returns an instance of this class.
	 * @since  0.1.0
	 * @return CMB2_Group_Map A single instance of this class.
	 */
	public static function get_instance() {
		if ( null === self::$single_instance ) {
			self::$single_instance = new self();
		}

		return self::$single_instance;
	}

	/**
	 * Constructor.
	 *
	 * @since 0.1.0
	 */
	protected function __construct() {
		add_action( 'cmb2_after_init', array( $this, 'setup_mapped_group_fields' ) );
		add_action( 'cmb2_group_map_updated', array( $this, 'map_to_original_object' ), 10, 3 );
		add_action( 'cmb2_group_map_associated_object_deleted', array( $this, 'remove_from_original_object' ), 10, 3 );
		add_action( 'before_delete_post', array( $this, 'delete_associated_objects' ) );
		add_action( 'wp_ajax_cmb2_group_map_get_post_data', array( $this, 'get_ajax_input_data' ) );
		add_action( 'wp_ajax_cmb2_group_map_delete_item', array( $this, 'ajax_delete_item' ) );
	}

	/**
	 * Get all instances of group fields for mapping, and initiate them.
	 *
	 * @since  0.1.0
	 */
	public function setup_mapped_group_fields() {

		/**
		 * Library's strings made available for translation.
		 *
		 * function cmb2_group_map_strings_i18n( $strings ) {
		 * 	$strings['missing_required'] = __( 'Missing required data.', 'your-textdomain' );
		 *  	return $strings;
		 * }
		 * add_filter( 'cmb2_group_map_strings', 'cmb2_group_map_strings_i18n' );
		 *
		 * @param  array $strings Array of unmodified strings.
		 * @return array Array of modified strings
		 */
		self::$strings = apply_filters( 'cmb2_group_map_strings', self::$strings );

		foreach ( CMB2_Boxes::get_all() as $cmb ) {
			foreach ( (array) $cmb->prop( 'fields' ) as $field ) {
				if (
					'group' === $field['type']
					&& (
						isset( $field['post_type_map'] )
						&& post_type_exists( $field['post_type_map'] )
					)
					|| isset( $field['object_type_map'] )
				) {
					$this->setup_mapped_group_field( $cmb, $field );
				}
			}
		}
	}

	/**
	 * Initiate the mapping field group.
	 *
	 * @since 0.1.0
	 *
	 * @param CMB2  $cmb   CMB2 instance.
	 * @param array $field Group field config array.
	 */
	protected function setup_mapped_group_field( CMB2 $cmb, array $field ) {
		// Helpful reference back to the CMB object.
		$field['cmb_id'] = $cmb->cmb_id;

		$field = $this->set_object_type( $cmb, $field );

		$this->set_before_after_group_hooks( $cmb, $field );

		$field['original_object_types'] = $cmb->prop( 'object_types' );

		$cmb->update_field_property( $field['id'], 'original_object_types', $field['original_object_types'] );

		$cpt = get_post_type_object( $field['post_type_map'] );

		/*
		 * Add a hidden ID field to the group to store the referenced object id.
		 * To work around https://github.com/CMB2/CMB2/issues/1188 use type text
		 * and add the hidden type throu attributes, then hide the row with hidden class.
		 */
		$cmb->add_group_field( $field['id'], array(
			'id'   => self::object_id_key( $field['object_type_map'] ),
			'type' => 'text',
			'classes' => 'hidden',
			'attributes' => array(
				'type' => 'hidden',
			),
		) );

		$this->hook_cmb2_overrides( $field['id'] );

		// Store fields to object property for retrieval (if necessary)
		$this->group_fields[ $cmb->cmb_id ][ $field['id'] ] = $field;
	}

	/**
	 * Get/Set the object_type_map param.
	 *
	 * @since 0.1.0
	 *
	 * @param CMB2  $cmb   CMB2 instance.
	 * @param array $field Group field config array.
	 */
	protected function set_object_type( CMB2 $cmb, array $field ) {
		// Set object type
		if ( ! isset( $field['object_type_map'] ) || ! in_array( $field['object_type_map'], $this->allowed_object_types, 1 ) ) {
			$field['object_type_map'] = 'post';
		}

		$cmb->update_field_property( $field['id'], 'object_type_map', $field['object_type_map'] );

		if ( 'term' === $field['object_type_map'] && ( ! isset( $field['taxonomy'] ) || ! taxonomy_exists( $field['taxonomy'] ) ) ) {
			wp_die( 'Using "term" for the "object_type_map" parameter requires a "taxonomy" parameter to also be set.' );
		}

		return $field;
	}

	/**
	 * Set the before/after group callbacks and cache/store any existing callbacks,
	 * to keep from stomping them.
	 *
	 * @since 0.1.0
	 *
	 * @param CMB2  $cmb   CMB2 instance.
	 * @param array $field Group field config array.
	 */
	protected function set_before_after_group_hooks( CMB2 $cmb, array $field ) {
		// Let's be sure not to stomp out any existing before_group/after_group parameters.
		if ( isset( $field['before_group'] ) ) {
			// Store them to another field property
			$cmb->update_field_property( $field['id'], 'cmb2_group_map_before_group', $field['before_group'] );
		}
		if ( isset( $field['after_group'] ) ) {
			$cmb->update_field_property( $field['id'], 'cmb2_group_map_after_group', $field['after_group'] );
		}

		// Hook in our JS registration using after_group group field parameter.
		// This ensures the enqueueing/registering only occurs if the field is displayed.
		$cmb->update_field_property( $field['id'], 'after_group', array( $this, 'after_group' ) );
		$cmb->update_field_property( $field['id'], 'before_group', array( $this, 'before_group' ) );
	}

	/**
	 * Called before the mapping grouped field render begins.
	 *
	 * @since  0.1.0
	 *
	 * @param array      $args  Array of field arguments
	 * @param CMB2_Field $field Field object.
	 */
	public function before_group( $args, CMB2_Field $field ) {
		// Do not get terms from parent post object
		add_filter( 'get_the_terms', array( __CLASS__, 'override_term_get' ), 9 );

		// When the field starts rendering (now), store the current field object as property.
		self::$current_field = $field;

		// Check for stored 'before_group' parameter, and run that now.
		if ( $field->args( 'cmb2_group_map_before_group' ) ) {
			$field->peform_param_callback( 'cmb2_group_map_before_group' );
		}

		do_action( 'cmb2_group_map_before_group', $field );

		echo '<div class="cmb2-group-map-group" data-nonce="'. wp_create_nonce( $field->id(), $field->id() ) .'" data-groupID="'. $field->id() .'">';
	}

	/**
	 * Called after the mapping grouped field render begins.
	 *
	 * @since  0.1.0
	 *
	 * @param array      $args  Array of field arguments
	 * @param CMB2_Field $field Field object.
	 */
	public function after_group( $args, CMB2_Field $field ) {
		// Check for stored 'after_group' parameter, and run that now.
		if ( $field->args( 'cmb2_group_map_after_group' ) ) {
			$field->peform_param_callback( 'cmb2_group_map_after_group' );
		}

		echo '</div>';

		do_action( 'cmb2_group_map_after_group', $field );

		// The field is now done rendering, so reset the current field property.
		self::$current_field = null;

		// Register our JS with the 'cmb2_script_dependencies' filter.
		add_filter( 'cmb2_script_dependencies', array( $this, 'register_js' ) );
	}

	/**
	 * Override term-gettting when these field groups are rendering.
	 *
	 * @since  0.1.0
	 *
	 * @param  array  $terms Array of term values
	 *
	 * @return array         Array of term values (or empty array)
	 */
	public static function override_term_get( $terms ) {

		/*
		 * If we're rendering the map group
		 * AND Filter wasn't removed by CMB2_Group_Map_Get::override_term_get(),
		 * It means we should return an empty array
		 * (because there isn't an actual post, so it would pull from the host,
		 * which is not correct)
		 */
		if ( self::is_rendering() ) {
			$terms = array();
		}

		return $terms;
	}

	/**
	 * Hooked into cmb2_script_dependencies, adds the mapped field style/js dependencies.
	 *
	 * @since  0.1.0
	 *
	 * @param  array $dependencies Array of script dependencies
	 *
	 * @return array $dependencies Modified array of script dependencies
	 */
	public function register_js( $dependencies ) {
		$assets_url = $this->get_url_from_dir( CMB2_GROUP_POST_MAP_DIR ) . 'lib/assets/';

		if ( apply_filters( 'cmb2_group_map_delete_on_dettach', true ) ) {
			$dependencies['cmb2_group_map'] = 'cmb2_group_map';

			wp_register_script(
				'cmb2_group_map',
				$assets_url . 'js/cmb2-group-map.js',
				array( 'jquery', 'wp-backbone' ),
				self::VERSION,
				1
			);

			wp_localize_script( 'cmb2_group_map', 'CMB2Mapl10n', array(
				'ajaxurl' => admin_url( 'admin-ajax.php', 'relative' ),
				'strings' => self::$strings,
			) );
		}

		wp_enqueue_style(
			'cmb2_group_map',
			$assets_url . 'css/cmb2-group-map.css',
			array(),
			self::VERSION
		);

		return $dependencies;
	}

	/**
	 * Gets a URL from a provided directory.
	 *
	 * @since  0.1.0
	 *
	 * @param  string $dir Directory path to convert.
	 *
	 * @return string      Converted URL.
	 */
	public function get_url_from_dir( $dir ) {
		if ( 'WIN' === strtoupper( substr( PHP_OS, 0, 3 ) ) ) {
			// Windows
			$content_dir = str_replace( '/', DIRECTORY_SEPARATOR, WP_CONTENT_DIR );
			$content_url = str_replace( $content_dir, WP_CONTENT_URL, $dir );
			$url = str_replace( DIRECTORY_SEPARATOR, '/', $content_url );

		} else {
			if ( false !== strpos( $dir, WP_CONTENT_DIR ) ) {
				$url = str_replace(
					array( WP_CONTENT_DIR, WP_PLUGIN_DIR ),
					array( WP_CONTENT_URL, WP_PLUGIN_URL ),
					$dir
				);
			} else {
				// Check to see if it's in the root directory
				$to_trim = str_replace( ABSPATH, '', WP_CONTENT_DIR );
				$url = str_replace(
					array( ABSPATH ),
					array( str_replace( $to_trim, '', WP_CONTENT_URL ) ),
					$dir
				);
			}
		}

		return set_url_scheme( $url );
	}

	/**
	 * Get the primary object key for the object type.
	 *
	 * @since  0.1.0
	 *
	 * @param  string $object_type Object type
	 *
	 * @return string              Object ID key.
	 */
	public static function object_id_key( $object_type ) {
		switch ( $object_type ) {
			case 'comment':
				return 'comment_ID';

			case 'term':
				return 'term_id';

			case 'user':
			default:
				return 'ID';
		}
	}

	/**
	 * Hooks in the setting/getting overrides for this group field.
	 *
	 * @since 0.1.0
	 *
	 * @param string $field_id The group field ID.
	 */
	protected function hook_cmb2_overrides( $field_id ) {
		add_filter( "cmb2_override_{$field_id}_meta_save", array( $this, 'do_save' ), 10, 4 );
		add_filter( "cmb2_override_{$field_id}_meta_value", array( $this, 'do_get' ), 10, 4 );
	}

	/**
	 * The save override
	 *
	 * @since  0.1.0
	 *
	 * @param  [type]  $override    [description]
	 * @param  [type]  $a           [description]
	 * @param  [type]  $args        [description]
	 * @param  [type]  $field_group [description]
	 *
	 * @return bool                 Returns true to shortcircuit CMB2 setting.
	 */
	public function do_save( $override, $a, $args, $field_group ) {
		require_once CMB2_GROUP_POST_MAP_DIR . 'lib/set.php';
		$setter = new CMB2_Group_Map_Set( $field_group, $a['value'] );
		$setter->save();

		return true; // this shortcuts CMB2 save
	}

	/**
	 * The get override
	 *
	 * @since  0.1.0
	 *
	 * @param  [type] $nooverride [description]
	 * @param  [type] $object_id  [description]
	 * @param  [type] $a          [description]
	 * @param  [type] $field      [description]
	 *
	 * @return mixed              By not returning the passed-in value, we shortcircuit CMB2 getting.
	 */
	public function do_get( $nooverride, $object_id, $a, $field ) {
		remove_filter( "cmb2_override_{$a['field_id']}_meta_value", array( $this, 'do_get' ), 10, 4 );

		require_once CMB2_GROUP_POST_MAP_DIR . 'lib/get.php';
		$value = CMB2_Group_Map_Get::get_value( $field );

		return $value; // this shortcuts CMB2 get
	}

	/**
	 * Hooked into cmb2_group_map_updated, maps the updated/saved posts
	 * to the original parent post.
	 *
	 * @since 0.1.0
	 *
	 * @param array      $updated            Array of updated IDs
	 * @param int        $original_object_id Parent id
	 * @param CMB2_Field $field              Group field object.
	 */
	public function map_to_original_object( $updated, $original_object_id, CMB2_Field $field ) {
		$updated    = is_array( $updated ) ? $updated : array();
		$object_ids = array();

		foreach ( $updated as $object_id ) {
			if ( ! is_wp_error( $object_id ) ) {
				$object_ids[] = $object_id;
			}
		}

		if ( empty( $object_ids ) ) {
			self::delete_map_meta( $field );
		} else {
			self::update_map_meta( $field, $object_ids, true );
		}
	}

	/**
	 * Hooked into cmb2_group_map_associated_object_deleted, updates the mapped objects
	 * on the original parent post.
	 *
	 * @since 0.1.0
	 *
	 * @param array      $deleted_id         ID of the deleted associated object.
	 * @param int        $original_object_id Parent id
	 * @param CMB2_Field $field              Group field object.
	 */
	public function remove_from_original_object( $deleted_id, $original_object_id, CMB2_Field $field ) {

		if ( is_wp_error( $deleted_id ) ) {
			return;
		}

		self::remove_from_map_meta( $field, $deleted_id );
	}

	/**
	 * When deleting a post, we need to delete any mapped posts, if they exist.
	 * If the group field has a 'sync_delete' param that is set to false,
	 * this deletion sync will be disabled for that mapping.
	 *
	 * This can also be disabled with the 'cmb2_group_map_sync_delete' filter.
	 *
	 * @since  0.1.0
	 *
	 * @param  int $post_id ID of the post being deleted.
	 */
	public function delete_associated_objects( $post_id ) {
		$post_type = get_post_type();

		foreach ( $this->group_fields as $cmb_id => $fields ) {
			foreach ( $fields as $field_id => $field ) {

				// Only sync for 'post' object type.
				if ( 'post' !== $field['object_type_map'] ) {
					continue;
				}

				$types = $field['original_object_types'];
				$types = is_array( $types ) ? $types : array( $types );

				// Not the field we're looking for.
				if ( ! in_array( $post_type, $types, 1 ) ) {
					continue;
				}

				// If $field['sync_delete'] is false, then do not sync deletion.
				if ( isset( $field['sync_delete'] ) && ! $field['sync_delete'] ) {
					continue;
				}

				// If 'cmb2_group_map_sync_delete' filter value is false, then do not sync deletion.
				if ( ! apply_filters( 'cmb2_group_map_sync_delete', true, $post_id, $field ) ) {
					continue;
				}

				$object_ids = get_post_meta( $post_id, $field['id'], 1 );

				// If no connected posts to delete.
				if ( ! is_array( $object_ids ) || empty( $object_ids ) ) {
					continue;
				}

				// Ok, delete them.
				foreach ( $object_ids as $id ) {
					wp_delete_post( $id, 1 );
				}
			}
		}
	}

	/**
	 * Ajax handler for getting group data.
	 *
	 * @since  0.1.0
	 */
	public function get_ajax_input_data() {
		require_once CMB2_GROUP_POST_MAP_DIR . 'lib/ajax.php';

		try {
			$ajax_handler = new CMB2_Group_Map_Ajax( $_POST, $this->group_fields );
			$ajax_handler->send_input_data();
		} catch ( Exception $e ) {
			wp_send_json_error( $e->getMessage() );
		}
	}

	/**
	 * Ajax handler for deleting associated posts.
	 *
	 * @since  0.1.0
	 */
	public function ajax_delete_item() {
		require_once CMB2_GROUP_POST_MAP_DIR . 'lib/ajax.php';

		try {
			$ajax_handler = new CMB2_Group_Map_Ajax( $_POST, $this->group_fields );
			$ajax_handler->delete();
		} catch ( Exception $e ) {
			wp_send_json_error( $e->getMessage() );
		}
	}

	/**
	 * Get the object ids value for a mapped group field.
	 *
	 * @since  0.1.0
	 *
	 * @param  array|CMB2_Field $group_field Either CMB2_Field group object, or array.
	 *
	 * @return mixed                         Result of get.
	 */
	public static function get_map_meta( $group_field ) {
		$args = self::parse_group_field_args( $group_field );

		if ( ! $args ) {
			return false;
		}

		$object_ids = get_metadata( $args['object_type'], $args['object_id'], $args['id'], $args['single'] );

		return is_array( $object_ids ) ? $object_ids : array();
	}

	/**
	 * Updates the object ids value for a mapped group field.
	 *
	 * @since  0.1.0
	 *
	 * @param  array|CMB2_Field $group_field Either CMB2_Field group object, or array.
	 *
	 * @return mixed                         Result of update.
	 */
	public static function update_map_meta( $group_field, array $value, $replace = false ) {
		$args = self::parse_group_field_args( $group_field );

		if ( ! $args ) {
			return false;
		}

		if ( ! $replace ) {
			$existing = self::get_map_meta( $args );

			if ( ! is_array( $existing ) ) {
				return false;
			}

			$value = array_merge( $value, $existing );
			$value = array_unique( $value );
		}

		return update_metadata( $args['object_type'], $args['object_id'], $args['id'], $value );
	}

	/**
	 * Deletes the object ids value for a mapped group field.
	 *
	 * @since  0.1.0
	 *
	 * @param  array|CMB2_Field $group_field Either CMB2_Field group object, or array.
	 *
	 * @return mixed                         Result of delete.
	 */
	public static function delete_map_meta( $group_field ) {
		$args = self::parse_group_field_args( $group_field );

		if ( ! $args ) {
			return false;
		}

		return delete_metadata( $args['object_type'], $args['object_id'], $args['id'] );
	}

	/**
	 * Removes object id(s) from value for a mapped group field.
	 *
	 * @since  0.1.0
	 *
	 * @param  array|CMB2_Field $group_field Either CMB2_Field group object, or array.
	 *
	 * @return mixed                         Result of removal.
	 */
	public static function remove_from_map_meta( $group_field, $remove ) {
		$args = self::parse_group_field_args( $group_field );

		if ( ! $args ) {
			return false;
		}

		$existing = self::get_map_meta( $args );

		// Nothing to remove.
		if ( ! $existing || empty( $existing ) ) {
			return false;
		}

		$remove = is_array( $remove ) ? $remove : array( $remove );
		$removed = array();

		foreach ( $remove as $id ) {
			if ( in_array( $id, $existing ) ) {
				// Search
				$pos = array_search( $id, $existing );
				// Remove from array
				unset( $existing[ $pos ] );

				$removed[] = $id;
			}
		}

		// Nothing was removed, so don't proceed.
		if ( empty( $removed ) ) {
			return $removed;
		}

		// Ok, resave the meta value w/ the removals complete.
		if ( empty( $existing ) ) {
			// if it's now empty, just delete it.
			$result = self::delete_map_meta( $args );
		} else {
			$result = self::update_map_meta( $args, $existing, true );
		}

		// If update was complete, send back the array of the leftover IDs.
		return $result ? $existing : false;
	}

	/**
	 * Gets the object_type, object_id, and field id (meta key) parameters for
	 * get/update/delete_metadata function calls.
	 *
	 * Can be provided a CMB2_Field object or an array.
	 *
	 * @since  0.1.0
	 *
	 * @param  array|CMB2_Field $group_field Either CMB2_Field group object, or array.
	 *
	 * @return array|false                   Array of args, or false if 'id' was not found.
	 */
	protected static function parse_group_field_args( $group_field ) {
		if ( is_a( $group_field, 'CMB2_Field' ) ) {
			$args['object_type'] = $group_field->args( 'original_object_type' )
				? $group_field->args( 'original_object_type' )
				: $group_field->object_type;
			$args['object_id']   = $group_field->object_id;
			$args['id']          = $group_field->id();
			$args['single']      = true;

		} else {

			$args = wp_parse_args( $group_field, array(
				'object_type' => 'post',
				'object_id'   => get_the_id(),
				'id'          => '',
				'single'      => true,
			) );

		}

		if ( ! $args['id'] ) {
			return false;
		}

		return $args;
	}

	/**
	 * Helper method to determine if Lib is in the middle of
	 * rendering a group mapping field.
	 *
	 * @since  0.1.0
	 *
	 * @return boolean True if rendering.
	 */
	public static function is_rendering() {
		return (bool) self::$current_field;
	}

	/**
	 * Return current field.
	 *
	 * @since  0.1.0
	 *
	 * @return CMB2_Field|null If in the middle of rendering, will be the current group field object.
	 */
	public static function get_current_field() {
		return self::$current_field;
	}

	/**
	 * Magic getter for our object.
	 *
	 * @since  0.1.0
	 *
	 * @param string $field
	 * @throws Exception Throws an exception if the field is invalid.
	 *
	 * @return mixed
	 */
	public function __get( $field ) {
		switch ( $field ) {
			case 'group_fields':
				return $this->{$field};
			default:
				throw new Exception( 'Invalid ' . __CLASS__ . ' property: ' . $field );
		}
	}

}
CMB2_Group_Map::get_instance();
