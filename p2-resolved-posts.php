<?php
/* Plugin Name: P2 Resolved Posts
 * Description: Allows you to mark P2 posts for resolution and filter by state
 * Author: Daniel Bachhuber (and Andrew Nacin)
 * Author URI: http://danielbachhuber.com/
 * Contributors: Hugo Baeta (css), Joey Kudish, Allen Snook
 * Version: 0.3.3
 * Text Domain: p2-resolved-posts
 * Domain Path: /languages
 */

/**
 * Andrew Nacin wrote this plugin,
 * but Daniel Bachhuber mostly rewrote it because it didn't work at all
 * when he added it to WordPress.com
 * Original source: https://gist.github.com/1353754
 */

require_once( dirname( __FILE__ ) . '/php/class-show-unresolved-posts-widget.php' );
require_once( dirname( __FILE__ ) . '/php/class-p2-resolved-posts-widget.php' );

if ( defined('WP_CLI') && WP_CLI )
	require_once( dirname( __FILE__ ) . '/php/class-wp-cli.php' );

/**
 * @package P2_Resolved_Posts
 */
class P2_Resolved_Posts {

	const taxonomy = 'p2_resolved';
	const audit_log_key = 'p2_resolved_log';

	var $states = array();
	var $states_to_add = array();
	var $states_to_remove = array();

	/**
	 * @var P2 Resolved Posts The one true P2 Resolved Posts
	 */
	private static $instance;

	/**
	 * Main P2 Resolved Posts Instance
	 *
	 * Insures that only one instance of P2 Resolved Posts exists in memory at any one
	 * time. Also prevents needing to define globals all over the place.
	 *
	 * @since P2 Resolved Posts 0.3
	 * @staticvar array $instance
	 * @uses P2_Resolved_Posts::setup_actions() Setup the hooks and actions
	 * @see P2ResolvedPosts()
	 * @return The one true P2 Resolved POsts
	 */
	public static function instance() {
		if ( ! isset( self::$instance ) ) {
			self::$instance = new P2_Resolved_Posts;
			self::$instance->setup_actions();
			// Backwards compat for when we promoted use of the $p2_resolved_posts global
			global $p2_resolved_posts;
			$p2_resolved_posts = self::$instance;
		}
		return self::$instance;
	}

	private function __construct() {
		/** Do nothing **/
	}

	public function setup_actions() {
		add_action( 'after_setup_theme', array( $this, 'after_setup_theme' ) );
	}

	/**
	 * Sets up initial hooks, if the P2 theme is in play.
	 */
	function after_setup_theme() {

		if ( ! class_exists( 'P2' ) ) {
			// Don't run the plugin if P2 isn't active, but display an admin notice
			add_action( 'admin_notices', array( $this, 'action_admin_notices' ) );
			return;
		}

		add_action( 'init', array( $this, 'action_init' ), 9 );

		add_action( 'wp_head', array( $this, 'action_wp_head_css' ) );
		add_action( 'wp_head', array( $this, 'action_wp_head_ajax' ) );
		add_action( 'init', array( $this, 'action_init_handle_state_change' ) );
		add_action( 'p2_action_links', array( $this, 'p2_action_links' ), 100 );
		add_filter( 'post_class', array( $this, 'post_class' ), 10, 3 );
		add_action( 'widgets_init', array( $this, 'widgets_init' ) );
		add_filter( 'request', array( $this, 'request' ) );

	}

	/**
	 * Load textdomain, register the taxonomy, etc.
	 */
	function action_init() {

		load_plugin_textdomain( 'p2-resolved-posts', false, dirname( plugin_basename( __FILE__ ) ) . '/languages/' );

		$this->register_taxonomy();

		$this->states = array(
				(object)array(
						'slug'          => 'normal',
						'name'          => __( 'Normal', 'p2-resolved-posts' ),
						'link_text'     => __( 'Flag unresolved', 'p2-resolved-posts' ),
						'next_action'   => __( 'Flag as unresolved', 'p2-resolved-posts' ),
					),
				(object)array(
						'slug'          => 'unresolved',
						'name'          => __( 'Unresolved', 'p2-resolved-posts' ),
						'link_text'     => __( 'Unresolved', 'p2-resolved-posts' ),
						'next_action'   => __( 'Flag as Resolved', 'p2-resolved-posts' ),
					),
				(object)array(
						'slug'          => 'resolved',
						'name'          => __( 'Resolved', 'p2-resolved-posts' ),
						'link_text'     => __( 'Resolved', 'p2-resolved-posts' ),
						'next_action'   => __( 'Remove resolved flag', 'p2-resolved-posts' ),
					),
			);
		// Add any states the user wants added using the custom helper function
		foreach( $this->states_to_add as $new_state ) {
			// Link text defaults to the state name if empty
			if ( ! $new_state->link_text )
				$new_state->link_text = $new_state->name;

			// Put the state in the proper position
			if ( 'first' == $new_state->position ) {
				// Default next_action text if missing
				if ( ! $new_state->next_action )
					$new_state->next_action = sprintf( __( 'Flag as %s', 'p2-resolved-posts' ), $this->get_next_state( $this->get_first_state()->slug )->name );
				array_unshift( $this->states, $new_state );
			} else if ( 'last' == $new_state->position ) {
				// Default next_action text if missing
				if ( ! $new_state->next_action )
					$new_state->next_action = sprintf( __( 'Flag as %s', 'p2-resolved-posts' ), $this->get_next_state( $this->get_last_state()->slug )->name );
				array_push( $this->states, $new_state );
			} else if ( ! empty( $new_state->position['after'] ) || ! empty( $new_state->position['before'] ) ) {
				if ( ! empty( $new_state->position['after'] ) ) {
					$insert = 'after';
					$match = $new_state->position['after'];
				} else {
					$insert = 'before';
					$match = $new_state->position['before'];
				}
				foreach( $this->states as $key => $state ) {
					if ( $match != $state->slug )
						continue;

					$index = ( 'after' == $insert ) ? $key + 1 : $key;
					$first_half = array_slice( $this->states, 0, $index );
					$second_half = array_slice( $this->states, - ( count( $this->states ) - $index ) );
					$this->states = $first_half;
					if ( ! $new_state->next_action && count( $second_half ) )
						$new_state->next_action = sprintf( __( 'Flag as %s', 'p2-resolved-posts' ), array_shift( array_values( $second_half ) )->name );
					else if ( ! $new_state->next_action && count( $first_half ) )
						$new_state->next_action = sprintf( __( 'Flag as %s', 'p2-resolved-posts' ), array_shift( array_values( $first_half ) )->name );
					array_push( $this->states, $new_state );
					$this->states = array_merge( $this->states, $second_half );
				}
			}

			// Reindex to ensure we're maintaining the appropriate index
			$this->states = array_values( $this->states );
		}

		// Remove any states the user wanted to remove
		foreach( $this->states as $key => $state ) {
			if ( in_array( $state->slug, $this->states_to_remove ) ) {
				unset( $this->states[$key] );
			}
		}
		// Reindex if we need to
		$this->states = array_values( $this->states );
		$this->states = apply_filters( 'p2_resolved_posts_states', $this->states );

		if ( ! term_exists( $this->get_first_state()->slug, self::taxonomy ) )
			wp_insert_term( $this->get_first_state()->slug, self::taxonomy );

		// Posts can be marked unresolved automatically by default
		// if the user wishes
		if ( apply_filters( 'p2_resolved_posts_mark_new_as_unresolved', false ) )
			add_action( 'transition_post_status', array( $this, 'mark_new_as_unresolved' ), 10, 3 );
	}

	/**
	 * Display an admin notice if the plugin is active but P2 isn't enabled
	 */
	function action_admin_notices() {
		$message = sprintf( __( "P2 Resolved Posts is enabled. You'll also need to activate the <a href='%s' target='_blank'>P2 theme</a> to start using the plugin.", 'p2-resolved-posts' ), 'http://p2theme.com/' );
		echo '<div class="error"><p>' . $message . '</p></div>';
	}

	/**
	 * Register the taxonomy we're using for tracking threads
	 */
	function register_taxonomy() {
		register_taxonomy( self::taxonomy, 'post', array(
			'public' => true,
			'query_var' => 'resolved',
			'rewrite' => false,
			'show_ui' => false,
		) );
	}

	/**
	 * Get the slugs for all of the registered states
	 */
	function get_state_slugs() {
		return wp_list_pluck( $this->states, 'slug' );
	}

	/**
	 * Given a slug, get the state
	 */
	function get_state( $slug ) {
		return array_shift( wp_filter_object_list( $this->states, array( 'slug' => $slug ) ) );
	}

	/**
	 * Get the first state this post can be changed to
	 */
	function get_first_state() {

		$states = array_values( $this->states );

		return array_shift( $states );

	}

	/**
	 * Given a state, get the next one
	 */
	function get_next_state( $slug ) {
		$position = array_search( $slug, $this->get_state_slugs() );
		$total = count( $this->get_state_slugs() );
		// We're at the end, return the first
		if ( $position == ( $total - 1) )
			return $this->get_first_state();
		$next = $position + 1;
		return $this->states[$next];
	}

	/**
	 * Get the last state this post can be in
	 */
	function get_last_state() {
		return array_pop( array_values( $this->states ) );
	}

	/**
	 * Get the current state for a post
	 */
	function get_current_state( $post_id ) {
		$state = wp_get_object_terms( $post_id, self::taxonomy );
		if ( empty( $state ) || is_wp_error( $state ) )
			return false;
		return array_shift( wp_filter_object_list( $this->states, array( 'slug' => $state[0]->slug ) ) );
	}

	/**
	 * Add a state to the set of available states
	 *
	 * @since 0.3
	 *
	 * @param string $slug Unique identifier for the state (e.g. "inprogress")
	 * @param string $name State's name (e.g. "In-Progress" )
	 * @param string|array $position Where the state should appear in the list of states (e.g. 'last' or array( 'after' => 'unresolved' ))
	 */
	public function add_state( $slug, $name, $position = 'last', $link_text = false, $next_action = false ) {
		if ( did_action( 'init' ) )
			_doing_it_wrong( 'P2ResolvedPosts()->add_state()', __( "Adding a state needs to happen before 'init'", 'p2-resolved-posts' ) );

		$this->states_to_add[] = (object)array(
				'slug'        => $slug,
				'name'        => $name,
				'position'    => $position,
				'link_text'   => $link_text,
				'next_action' => $next_action,
			);
	}

	/**
	 * Remove a state from the set of available states
	 *
	 * @since 0.3
	 *
	 * @param string $slug Slug for the state you'd like to remove
	 */
	public function remove_state( $slug ) {
		if ( did_action( 'init' ) )
			_doing_it_wrong( 'P2ResolvedPosts()->remove_state()', __( "Removing a state needs to happen before 'init'", 'p2-resolved-posts' ) );

		$this->states_to_remove[] = $slug;
	}

	/**
	 * Parse the request if it's a request for our unresolved posts
	 */
	function request( $qvs ) {
		if ( ! isset( $qvs['resolved'] ) )
			return $qvs;

		if ( ! in_array( $qvs['resolved'], $this->get_state_slugs() ) ) {
			unset( $qvs['resolved'] );
			return $qvs;
		}

		// Just to be safe
		$qvs['resolved'] = sanitize_key( $qvs['resolved'] );

		add_action( 'parse_query', array( $this, 'parse_query' ) );
		add_filter( 'template_include', array( $this, 'force_home_template' ) );

		if ( ! isset( $qvs['tax_query'] ) )
			$qvs['tax_query'] = array();

		// Don't pay attention to sticky posts
		$qvs['ignore_sticky_posts'] = 1;

		// Filter the query to just the type of posts we're looking for
		$qvs['tax_query'][] = array(
			'taxonomy' => self::taxonomy,
			'terms' => array( $qvs['resolved'] ),
			'field' => 'slug',
			'operator' => 'IN',
		);
		if ( !empty( $_GET['tags'] ) || !empty( $_GET['post_tag'] ) ) {
			$filter_tags = ( !empty( $_GET['tags'] ) ) ? $_GET['tags'] : $_GET['post_tag'];
			$filter_tags = (array)explode( ',', $filter_tags );
	 		foreach( (array)$filter_tags as $filter_tag ) {
	 			$filter_tag = sanitize_key( $filter_tag );
	 			$new_tax_query = array(
						'taxonomy' => 'post_tag',
					);
	 			if ( 0 === strpos( $filter_tag, '-') )
					$new_tax_query['operator'] = 'NOT IN';
				$filter_tag = trim( $filter_tag, '-' );
				if ( is_numeric( $filter_tag ) )
					$new_tax_query['field'] = 'ID';
				else
					$new_tax_query['field'] = 'slug';
				$new_tax_query['terms'] = $filter_tag;
	 			$qvs['tax_query'][] = $new_tax_query;
	 		}
	 	}

		return $qvs;
	}

	/**
	 * Nacin thought this was important
	 */
	function parse_query( $query ) {
		$query->is_home = true; // Force home detection.
	}

	/**
	 * Nacin thought this was important
	 */
	function force_home_template() {
		return get_home_template();
	}

	/**
	 * Sidebar widget for filtering posts
	 */
	function widgets_init() {
		register_widget( 'P2_Resolved_Posts_Widget' );
		register_widget( 'P2_Resolved_Posts_Show_Unresolved_Posts_Widget' );
	}

	function get_next_action_link( $next_state, $post_id = null ) {

		if ( is_null( $post_id ) )
			$post_id = get_the_ID();

		$args = array(
				'action'        => 'p2-resolve',
				'post-id'       => $post_id,
				'nonce'         => wp_create_nonce( 'p2-resolve-' . $post_id ),
				'mark'          => $next_state,
			);
		$link = add_query_arg( $args, get_site_url() );
		return $link;
	}

	/**
	 * Add our action links to the P2 post
	 */
	function p2_action_links() {

		$css = array(
			'p2-resolve-link',
		);

		$existing_state = $this->get_current_state( get_the_ID() );
		if ( ! $existing_state )
			$existing_state = $this->get_first_state();
		$next_state = $this->get_next_state( $existing_state->slug );

		$css[] = 'state-' . $existing_state->slug;
		$link = $this->get_next_action_link( $next_state->slug );
		$next_action = $existing_state->next_action;
		$text = $existing_state->link_text;

		if ( ! is_user_logged_in() ) {
			$css[] = 'p2-nopriv';
			$link = '#';
			$next_action = __( 'Please log in to change post states.', 'p2-resolved-posts' );
		}

		$output = ' | <span class="p2-resolve-wrap"><a title="' . esc_attr( $next_action ) . '" href="' . esc_url( $link ) . '" class="' . esc_attr( implode( ' ', $css ) ) . '">' . esc_html( $text ) . '</a>';

		// Hide our audit log output here too
		$audit_logs = get_post_meta( get_the_id(), self::audit_log_key );
		$audit_logs = array_reverse( $audit_logs, true );

		$output .= '<ul class="p2-resolved-posts-audit-log">';
		foreach( $audit_logs as $audit_log ) {
			$output .= $this->single_audit_log_output( $audit_log );
		}
		$output .= '</ul></span>';

		echo $output;
	}

	/**
	 * Give our resolve and unresolved items a bit of CSS
	 */
	function action_wp_head_css() {
		?>
		<style type="text/css">

		#main #postlist li.hentry {
			border-left: 8px solid #FFF;
			padding-left: 7px;
		}

		#main #postlist li.hentry.state-unresolved {
			border-left-color: #E6000A;
			-webkit-border-top-left-radius: 0;
			-moz-border-radius-topleft: 0;
			-o-border-radius-topleft: 0;
			-ms-border-radius-topleft: 0;
			border-top-left-radius: 0;
		}

		#main #postlist li.hentry.state-unresolved .actions a.p2-resolve-link {
			background-color: #E6000A;
		}

		#main #postlist li.hentry.state-resolved {
			border-left-color: #009632;
			-webkit-border-top-left-radius: 0;
			-moz-border-radius-topleft: 0;
			-o-border-radius-topleft: 0;
			-ms-border-radius-topleft: 0;
			border-top-left-radius: 0;
		}

		#main #postlist li.hentry.state-resolved .actions a.p2-resolve-link {
			background-color: #009632;
		}

		#main #postlist li.hentry.state-unresolved .actions a.p2-resolve-link,
		#main #postlist li.hentry.state-resolved .actions a.p2-resolve-link {
			color: #fff;
			padding: 1px 3px;
			-webkit-border-radius: 2px;
			-moz-border-radius: 2px;
			-o-border-radius: 2px;
			-ms-border-radius: 2px;
			border-radius: 2px;
		}

		#main #postlist li.hentry .actions a.p2-resolve-link:hover,
		#main #postlist li.hentry .actions a.p2-resolve-link.p2-resolve-ajax-action {
			background-color: #888;
		}

		#main #postlist li.hentry ul.p2-resolved-posts-audit-log,
		#main .controls ul.p2-resolved-posts-audit-log {
			background-color: #FFFFFF;
			padding-top: 3px;
			padding-bottom: 3px;
			padding-left: 10px;
			padding-right: 10px;
			position: absolute;
			right: 0;
			width: 225px;
			z-index: 1000;
			border: 1px solid #CCCCCC;
			display: none;
		}

		#main #postlist li.hentry ul.p2-resolved-posts-audit-log li {
			list-style-type: none;
			color: #777;
			opacity: 0.6;
			border-top: none;
			border-bottom: none;
			padding-top: 2px;
			padding-bottom: 2px;
		}

		#main #postlist li.hentry ul.p2-resolved-posts-audit-log li img {
			float: left;
			margin-right: 0;
			margin-top: 4px;
		}

		#main #postlist li.hentry ul.p2-resolved-posts-audit-log li span.audit-log-text {
			margin-left: 25px;
			display: block;
		}

		#main #postlist li.post ul.p2-resolved-posts-audit-log li span.date-time {
			font-size: 10px;
		}

		#main #postlist li.hentry ul.p2-resolved-posts-audit-log li:first-child {
			opacity: 1.0;
		}

		</style>
		<?php
	}

	/**
	 * Javascript to make this whole operation run like AJAX
	 */
	function action_wp_head_ajax() {
		?>
		<script type="text/javascript">

		jQuery(document).ready(function(){

			var p2_resolved_hover_in = null;
			var p2_resolved_hover_out = null;

			// Display the most recent audit log for each post
			jQuery('#main #postlist li.post .p2-resolved-posts-audit-log').each( function() {
				jQuery('li', this).last().show();
			});

			jQuery('.actions .p2-resolve-wrap').hover(
				function(){
					clearTimeout( p2_resolved_hover_out );
					var audit_log = jQuery(this).find('.p2-resolved-posts-audit-log');
					if ( audit_log.find('li').length ) {
						p2_resolved_hover_in = setTimeout( function() {
							audit_log.fadeIn();
						}, 1250 );
					}
				},
				function(){
					clearTimeout( p2_resolved_hover_in );
					var audit_log = jQuery(this).find('.p2-resolved-posts-audit-log');
					p2_resolved_hover_out = setTimeout( function() {
							audit_log.fadeOut();
						}, 500 );
				}
				);

			jQuery('.actions .p2-resolve-link').click(function(){
				var original_link = jQuery(this);
				// Check for nopriv
				if ( jQuery( this ).hasClass( 'p2-nopriv' ) ) {
					alert( '<?php echo esc_js( __( 'Please log in to change post states.', 'p2-resolved-posts' ) ); ?>' );
					return false;
				}

				// Mark the thread as unresolved
				jQuery(this).html('Saving...');
				jQuery(this).addClass('p2-resolve-ajax-action');
				jQuery.get( original_link.attr('href') + '&ajax', function(data){

					// Reset our classes
					var post_classes = original_link.closest('.post').attr('class').replace( /state-[a-zA-Z0-9]+/, '' );
					original_link.closest('.post').attr('class', post_classes);
					var link_classes = original_link.attr('class').replace( /state-[a-zA-Z0-9]+/, '' );
					original_link.attr('class', link_classes);

					if ( 'ok' == data.status ) {
						original_link.attr( 'href', data.href );
						original_link.attr( 'title', data.next_action );
						original_link.html( data.link_text );
						original_link.addClass('state-'+data.new_state);
						original_link.closest('.post').addClass('state-'+data.new_state);
						original_link.closest('.post').find('ul.p2-resolved-posts-audit-log').prepend( data.audit_log_entry );
					} else if ( 'error' == data.status ) {
						original_link.html(data.message);
						original_link.attr('style', 'color: #FF0000 !important;');
					}
					original_link.removeClass('p2-resolve-ajax-action');
					return false;
				});
				return false;
			});
		});
		</script>
		<?php
	}

	/**
	 * Extra CSS to style the post when there are open threads
	 */
	function post_class( $classes, $class, $post_id ) {

		$existing_state = $this->get_current_state( $post_id );
		if ( $existing_state )
			$classes[] = 'state-' . $existing_state->slug;

		return $classes;
	}

	/**
	 * Handle changing the state between 'unresolved', 'resolved' and normal
	 */
	function action_init_handle_state_change() {

		// Bail if the action isn't ours
		if ( !isset( $_GET['post-id'], $_GET['action'], $_GET['nonce'], $_GET['mark'] ) || $_GET['action'] != 'p2-resolve' )
			return;

		$post_id = intval( $_GET['post-id'] );

		// Only allowed logged in users to change the state
		if ( ! is_user_logged_in() )
			$this->do_response( 'error', array( 'message' => __( "Only logged in users may change resolved status", 'p2-resolved-posts' ) ) );

		// Check that the user is who they say they are
		if ( ! wp_verify_nonce( $_GET['nonce'], 'p2-resolve-' . $post_id ) )
			$this->do_response( 'error', array( 'message' => __( "Doin' something fishy, huh?", 'p2-resolved-posts' ) ) );

		// Check that it's a valid state
		if ( in_array( $_GET['mark'], $this->get_state_slugs() ) )
			$state = sanitize_key( $_GET['mark'] );
		else
			$this->do_response( 'error', array( 'message' => __( 'Bad state', 'p2-resolved-posts' ) ) );

		// Check that the post is valid
		$post = get_post( $post_id );
		if ( !$post )
			$this->do_response( 'error', array( 'message' => __( 'Invalid post id', 'p2-resolved-posts' ) ) );

		$status = 'ok';
		$data = array(
				'post_id'         => $post_id,
				'new_state'       => $state,
			);
		$changed_state = $this->change_state( $post_id, $state );
		$data = array_merge( $changed_state, $data );
		$this->do_response( $status, $data );
	}

	/**
	 * Do a JSON response
	 */
	function do_response( $status, $data ) {
		if ( isset( $_GET['ajax'] ) ) {
			header( 'Content-type: application/json' );
			$response = array_merge( array( 'status' => $status ), $data );
			echo json_encode( $response );
		} else {
			if ( 'ok' == $status )
				wp_safe_redirect( get_permalink( $data['post_id'] ) );
			else
				wp_die( $data['message'] );
		}
		exit;
	}

	/**
	 * Change the state of a given post
	 */
	function change_state( $post_id, $state ) {
		if ( ! taxonomy_exists( self::taxonomy ) )
			$this->register_taxonomy();

		$args = array(
			'new_state' => $state,
		);

		// check if the current state is already the state we're trying to set and if so, don't change it and don't record it
		$existing_state = wp_get_object_terms( $post_id, self::taxonomy, array( 'fields' => 'slugs' ) );
		if ( in_array( $state, $existing_state ) ) {
			do_action( 'p2_resolved_posts_change_state_already_has_state', $state, $post_id );
			$args = $this->log_state_change( $post_id, $args, false ); // we need to do this so that the response we send remains proper
		} else {
			wp_set_object_terms( $post_id, (array)$state, self::taxonomy, false );
			$args = $this->log_state_change( $post_id, $args );
			clean_object_term_cache( $post_id, get_post_type( $post_id ) );
			do_action( 'p2_resolved_posts_changed_state', $state, $post_id );
		}

		$state_obj = $this->get_state( $state );
		$args['next_action'] = $state_obj->next_action;
		$args['link_text'] = $state_obj->link_text;
		$args['href'] = $this->get_next_action_link( $this->get_next_state( $state )->slug, $post_id );
		$args['audit_log_entry'] = $this->single_audit_log_output( $args );
		return $args;
	}

	/**
	 * Log when a post has changed resolution state.
	 * Don't blow away existing logs though.
	 *
	 * @since 0.2
	 */
	function log_state_change( $post_id, $args = array(), $record_meta = true ) {

		$defaults = array(
				'user_login' => wp_get_current_user()->user_login,
				'new_state' => '',
				'timestamp' => time(),
			);
		$args = array_merge( $defaults, $args );

		if ( $record_meta )
			add_post_meta( $post_id, self::audit_log_key, $args );

		return $args;
	}
	
	/**
	 * Produce the HTML for a single audit log
	 *
	 * @since 0.2
	 */
	function single_audit_log_output( $args ) {

		$date = get_date_from_gmt( date( 'Y-m-d H:i:s', $args['timestamp'] ), get_option( 'date_format' ) );
		$time = get_date_from_gmt( date( 'Y-m-d H:i:s', $args['timestamp'] ), get_option( 'time_format' ) );
		$date_time = sprintf( __( '<span class="date-time">%1$s on %2$s</span>', 'p2-resolved-posts' ), esc_html( $time ), esc_html( $date ) );

		$user = get_user_by( 'login', $args['user_login'] );
		// Accomodate for removed users
		if ( $user ) {
			$avatar = get_avatar( $user->ID, 16 );
			$display_name = $user->display_name;
		} else {
			$avatar = '';
			$display_name = __( 'Someone', 'p2-resolved-posts' );
		}

		// If there's a state currently set
		if ( $args['new_state'] )
			$text = sprintf( __( '%1$s marked this %2$s<br />%3$s', 'p2-resolved-posts' ), esc_html( $display_name ), esc_html( $args['new_state'] ), $date_time );
		else
			$text = sprintf( __( '%1$s removed resolution<br />%2$s', 'p2-resolved-posts' ), esc_html( $display_name ), $date_time );

		$html = '<li>' . $avatar . '<span class="audit-log-text">' . $text . '</span></li>';
		return $html;
	}

	/**
	 * Automatically mark a newly published post as unresolved
	 * To enable, include the following in your theme's functions.php:
	 * - add_filter( 'p2_resolved_posts_mark_new_as_unresolved', '__return_true' );
	 *
	 * @since 0.2
	 */
	function mark_new_as_unresolved( $new_status, $old_status, $post ) {

		if ( 'publish' != $new_status || 'publish' == $old_status )
			return;

		// Allow certain types of posts to not be marked as unresolved
		if ( ! apply_filters( 'p2_resolved_posts_maybe_mark_new_as_unresolved', true, $post ) )
			return;

		$new_state = $this->get_next_state( $this->get_first_state()->slug );
		$this->change_state( $post->ID, $new_state->slug );
	}

}

function P2ResolvedPosts() {
	return P2_Resolved_Posts::instance();
}
add_action( 'plugins_loaded', 'P2ResolvedPosts' );
