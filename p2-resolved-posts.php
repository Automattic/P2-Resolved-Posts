<?php
/* Plugin Name: P2 Resolved Posts
 * Description: Allows you to mark P2 posts for resolution and filter by state
 * Author: Daniel Bachhuber (and Andrew Nacin)
 * Author URI: http://danielbachhuber.com/
 * Contributors: Hugo Baeta (css)
 * Version: 0.2
 */

/**
 * Andrew Nacin wrote this plugin,
 * but Daniel Bachhuber mostly rewrote it because it didn't work at all
 * when he added it to WordPress.com
 * Original source: https://gist.github.com/1353754
 */

if ( defined('WP_CLI') && WP_CLI )
	require_once( dirname( __FILE__ ) . '/php/class-wp-cli.php' );

define( 'P2_RESOLVED_POSTS_VERSION', 0.2 );

/**
 * @package P2_Resolved_Posts
 */
class P2_Resolved_Posts {

	static $instance;

	const taxonomy = 'p2_resolved';
	const audit_log_key = 'p2_resolved_log';
	const resolved_keyword = '!resolved';
	const unresolved_keyword = '!unresolved';
	const normal_keyword = '!normal';

	/**
	 * Constructor. Saves instance and sets up initial hook.
	 */
	function __construct() {
		self::$instance = $this;

		load_plugin_textdomain( 'p2-resolve', false, dirname( plugin_basename( __FILE__ ) ) . '/languages/' );

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

		add_action( 'wp_enqueue_scripts', array( $this, 'enqueue' ) );
		add_action( 'init', array( $this, 'handle_state_change' ) );
		add_action( 'p2_action_links', array( $this, 'p2_action_links' ), 100 );
		add_filter( 'post_class', array( $this, 'post_class' ), 10, 3 );
		add_action( 'widgets_init', array( $this, 'widgets_init' ) );
		add_filter( 'request', array( $this, 'request' ) );
		add_action( 'comment_form', array( $this, 'comment_form' ) );
		add_action( 'comment_post', array( $this, 'comment_submit' ), 10, 2 );
		add_action( 'wp_insert_post', array( $this, 'post_submit' ), 1000, 2 );
		add_action( 'wp_ajax_p2_resolve', array( $this, 'handle_state_change' ) );
		add_action( 'wp_ajax_p2_resolve', array( $this, 'handle_state_change' ) );
		add_action( 'wp_ajax_p2_resolved_posts_get_status', array( $this, 'p2_action_links' ) );
		add_action( 'wp_ajax_no_priv_p2_resolved_posts_get_status', array( $this, 'p2_action_links' ) );

		$this->register_taxonomy();
		if ( ! term_exists( 'unresolved', self::taxonomy ) )
			wp_insert_term( 'unresolved', self::taxonomy );


		// Posts can be marked unresolved automatically by default if the user wishes
		// otherwise a checkbox is presented
		if ( apply_filters( 'p2_resolved_posts_mark_new_as_unresolved', false ) ) {
			add_action( 'publish_post', array( $this, 'mark_new_as_unresolved' ), 10, 2 );
		} else {
			add_action( 'p2_post_form', array( $this, 'post_form' ) );
		}

		// Comments can be closed automatically when a post is resolved
		// if the user wishes
		if ( apply_filters( 'p2_resolved_posts_open_close_comments', false ) )
			add_action( 'p2_resolved_posts_changed_state', array( $this, 'open_close_comments' ), 10, 3 );

	}

	/**
	 * Display an admin notice if the plugin is active but P2 isn't enabled
	 */
	function action_admin_notices() {
		$message = sprintf( __( "P2 Resolved Posts is enabled. You'll also need to activate the <a href='%s' target='_blank'>P2 theme</a> to start using the plugin.", 'p2-resolve' ), 'http://p2theme.com/' );
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
	 * Parse the request if it's a request for our unresolved posts
	 */
	function request( $qvs ) {
		if ( ! isset( $qvs['resolved'] ) )
			return $qvs;

		if ( ! in_array( $qvs['resolved'], array( 'resolved', 'unresolved' ) ) ) {
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
		if ( isset( $_GET['tags'] ) || isset( $_GET['post_tag'] ) ) {
				$filter_tags = ( isset( $_GET['tags'] ) ) ? $_GET['tags'] : $_GET['post_tag'];
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
	 	if ( isset( $_GET['order'] ) && in_array( strtolower( $_GET['order'] ), array( 'asc', 'desc' ) ) )
	 		$qvs['order'] = sanitize_key( $_GET['order'] );

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

	function is_ajax_request() {
		return ( defined( 'DOING_AJAX' ) && DOING_AJAX && ! empty( $_REQUEST['action'] ) && false !== strpos( $_REQUEST['action'], 'p2_resolve' ) );
	}

	/**
	 * Sidebar widget for filtering posts
	 */
	function widgets_init() {
		include_once( dirname( __FILE__ ) . '/php/resolved-posts-links-widget.php' );
		include_once( dirname( __FILE__ ) . '/php/unresolved-posts-widget.php' );
		register_widget( 'P2_Resolved_Posts_Widget' );
		register_widget( 'P2_Resolved_Posts_Show_Unresolved_Posts_Widget' );
	}

	/**
	 * Add our action links to the P2 post
	 * also respond to ajax request to update the links
	 */
	function p2_action_links() {

		global $post;

		if ( $this->is_ajax_request() && !empty( $_REQUEST['post_id'] ) ) {
			$post_id = $_REQUEST['post_id'];
		} elseif ( is_object( $post ) && !empty( $post->ID ) ) {
			$post_id = $post->ID;
		} else {
			return;
		}

		$args = array(
			'action' => 'p2_resolve',
			'post_id' => $post_id,
			'nonce' => wp_create_nonce( 'p2-resolve-' . $post_id ),
		);
		$link = add_query_arg( $args, get_site_url() );

		$css = array(
			'p2-resolve-link',
		);

		if ( has_term( 'unresolved', self::taxonomy, $post_id ) ) {
			$state = 'unresolved';
			$link = add_query_arg( 'mark', 'resolved', $link );
			$title = __( 'Flag as Resolved', 'p2-resolve' );
			$text = __( 'Unresolved', 'p2-resolve' );
		} else if ( has_term( 'resolved', self::taxonomy, $post_id ) ) {
			$state = 'resolved';
			$link = add_query_arg( 'mark', 'normal', $link );
			$title = __( 'Remove Resolved Flag', 'p2-resolve' );
			$text = __( 'Resolved', 'p2-resolve' );
		} else {
			$state = '';
			$link = add_query_arg( 'mark', 'unresolved', $link );
			$title = __( 'Flag as Unresolved', 'p2-resolve' );
			$text = __( 'Flag Unresolved', 'p2-resolve' );
		}

		if ( !empty( $state ) )
			$css[] = 'state-' . $state;

		$action_links = ( $this->is_ajax_request() ) ? '' : ' | ';
		$action_links .= '<span class="p2-resolve-wrap"><a title="' . esc_attr( $title ) . '" href="' . esc_url( $link ) . '" class="' . esc_attr( implode( ' ', $css ) ) . '">' . esc_html( $text ) . '</a>';

		$audit_logs = get_post_meta( $post_id, self::audit_log_key );

		if ( !empty( $audit_logs ) ) {
			$audit_logs = array_reverse( $audit_logs, true );

			$action_links .= '<ul class="p2-resolved-posts-audit-log">';
			foreach( $audit_logs as $audit_log ) {
				$action_links .= $this->single_audit_log_output( $audit_log );
			}
			$action_links .= '</ul>';
		}

		$action_links .= '</span>';

		if ( $this->is_ajax_request() ) {
			header( 'Content-Type: application/json' );
			die( json_encode( array( 'action_links' => $action_links, 'state' => $state ) ) );
		} else {
			echo $action_links;
		}
	}

	function post_form() {

		if ( apply_filters( 'p2_resolved_posts_disable_mark_as_unresolved_checkbox', false ) )
			return;

		$mark_as_unresolved_checkbox = '<p class="p2_resolved_posts_mark_as_unresolved_checkbox">';
		$mark_as_unresolved_checkbox .= '<input type="checkbox" name="p2_resolved_posts_mark_as_unresolved_checkbox" id="p2_resolved_posts_mark_as_unresolved_checkbox" value="unresolved"/> ';
		$mark_as_unresolved_checkbox .= '<label class="p2_resolved_posts_mark_as_unresolved_label" id="p2_resolved_posts_mark_as_unresolved_label" for="p2_resolved_posts_mark_as_unresolved_checkbox">' . __( 'Mark as Unresolved', 'p2-resolve' ) . '</label>';
		$mark_as_unresolved_checkbox .= '</p>';
		echo apply_filters( 'p2_resolved_posts_mark_as_unresolved_checkbox', $mark_as_unresolved_checkbox );


	}

	/**
	 * add checkbox to comment replies
	 */
	function comment_form( $comment_field ) {
		global $post;

		if ( !has_term( 'unresolved', self::taxonomy, $post->ID ) || apply_filters( 'p2_resolved_posts_disable_mark_as_resolved_comment_checkbox', false ) )
			return;

		$mark_as_resolved_checkbox = '<p class="p2_resolved_posts_mark_as_resolved_checkbox">';
		$mark_as_resolved_checkbox .= '<input type="checkbox" name="p2_resolved_posts_mark_as_resolved" id="p2_resolved_posts_mark_as_resolved" value="resolved"/> ';
		$mark_as_resolved_checkbox .= '<label class="p2_resolved_posts_mark_as_resolved_label" id="p2_resolved_posts_mark_as_resolved_label" for="p2_resolved_posts_mark_as_resolved">' . __( 'Mark as Resolved', 'p2-resolve' ) . '</label>';
		$mark_as_resolved_checkbox .= '</p>';
		echo apply_filters( 'p2_resolved_posts_mark_as_resolved_comment_checkbox', $mark_as_resolved_checkbox );

	}

	/**
	 * process a comment after it's inserted and adjust the
	 * resolved state as needed
	 */
	function comment_submit( $comment_id, $approved ) {
		if ( 'spam' == $approved )
			return;

		$comment = get_comment( $comment_id );

		if ( ! $this->string_contains_state_keywords( $comment->comment_content ) )
			return;

		$comment->comment_content = $this->process_string_post_resolved_state( $comment->comment_content, $comment->comment_post_ID );
		wp_update_comment( (array) $comment );
	}

	/**
	 * process a post after it's inserted and adjust the
	 * resolved state as needed
	 */
	function post_submit( $post_id, $post ) {

		if ( ! $this->string_contains_state_keywords( $post->post_content ) )
			return;

		$post->post_content = $this->process_string_post_resolved_state( $post->post_content, $post_id );
		wp_update_post( $post );
	}

	/**
	 * detect which keywords a string contains and change the
	 * post's state accordingly, then strip the keyword
	 */
	function process_string_post_resolved_state( $string, $post_id ) {
		if ( $this->string_contains_resolved_keyword( $string ) )
			$this->change_state( $post_id, 'resolved', true );

		if ( $this->string_contains_unresolved_keyword( $string ) )
			$this->change_state( $post_id, 'unresolved', true );

		if ( $this->string_contains_normal_keyword( $string ) )
			$this->change_state( $post_id, 'normal', true );

		clean_object_term_cache( $post_id, get_post_type( $post_id ) );
		return $this->erase_keywords_from_string( $string );
	}

	/**
	 * detect if a string contains any of the keywords
	 */
	function string_contains_state_keywords( $string ) {
		return $this->string_contains_helper( $string, array( self::resolved_keyword, self::unresolved_keyword, self::normal_keyword ) );
	}

	/**
	 * detect if a string contains the resolved keyword
	 */
	function string_contains_resolved_keyword( $string ) {
		return $this->string_contains_helper( $string, self::resolved_keyword );
	}

	/**
	 * detect if a string contains the unresolved keyword
	 */
	function string_contains_unresolved_keyword( $string ) {
		return $this->string_contains_helper( $string, self::unresolved_keyword );
	}

	/**
	 * detect if a string contains the normal keyword
	 */
	function string_contains_normal_keyword( $string ) {
		return $this->string_contains_helper( $string, self::normal_keyword );
	}

	/**
	 * helper function to detect if a string contains contains the specified keywords
	 */
	function string_contains_helper( $string, $keywords ) {

		if ( !is_array( $keywords ) )
			$keywords = array( $keywords );

		foreach ( $keywords as $keyword ) {
			if ( false !== strpos( $string, $keyword ) )
				return true;
		}

		return false;
	}

	/**
	 * helper function to erase keywords from a string
	 */
	function erase_keywords_from_string( $string, $keywords = null ) {

		if ( empty( $keywords ) )
			$keywords = array( self::resolved_keyword, self::unresolved_keyword, self::normal_keyword );

		if ( !is_array( $keywords ) )
			$keywords = array( $keywords );

		foreach ( $keywords as $keyword )
			$string = str_replace( $keyword, '', $string );

		return trim( $string );

	}


	function enqueue() {
		wp_enqueue_script( 'p2-resolved-posts', plugins_url( 'js/p2-resolved-posts.js', __FILE__ ), array( 'jquery' ), P2_RESOLVED_POSTS_VERSION );

		$ajax_polling_url = add_query_arg( array( 'action' => 'p2_resolved_posts_get_status' ), wp_nonce_url( admin_url( 'admin-ajax.php' ), 'p2_resolved_posts_get_status' ) );
		wp_localize_script( 'p2-resolved-posts', 'p2rp', array( 'ajaxPollingUrl' => $ajax_polling_url, 'ajaxurl' => admin_url( 'admin-ajax.php' ) ) );

		wp_enqueue_style( 'p2-resolved-posts', plugins_url( 'css/p2-resolved-posts.css', __FILE__ ), array(), P2_RESOLVED_POSTS_VERSION );
	}


	/**
	 * Extra CSS to style the post when there are open threads
	 */
	function post_class( $classes, $class, $post_id ) {
		if ( has_term( 'resolved', self::taxonomy, $post_id ) )
			$classes[] = 'state-resolved';

		if ( has_term( 'unresolved', self::taxonomy, $post_id ) )
			$classes[] = 'state-unresolved';
		return $classes;
	}

	/**
	 * Handle changing the state between 'unresolved', 'resolved' and normal
	 */
	function handle_state_change() {

		// bail if ajax and init
		if ( 'init' == current_filter() && $this->is_ajax_request() )
			return;

		// Bail if the action isn't ours
		if ( !isset( $_GET['post_id'], $_GET['action'], $_GET['nonce'], $_GET['mark'] ) || $_GET['action'] != 'p2_resolve' )
			return;

		$error = false;
		$current_user = wp_get_current_user();

		$states = array(
			'resolved',
			'unresolved',
			'normal',
		);
		$state = '';
		if ( in_array( $_GET['mark'], $states ) )
			$state = sanitize_key( $_GET['mark'] );
		else
			$error = __( 'Bad state', 'p2-resolve' );

		$error = false;
		$post_id = intval( $_GET['post_id'] );

		$post = get_post( $post_id );
		if ( !$post )
			$error = __( 'Invalid post', 'p2-resolve' );

		if ( !wp_verify_nonce( $_GET['nonce'], 'p2-resolve-' . $post_id ) )
			$error = __( "Nonce error", 'p2-resolve' );

		if ( empty( $error ) ) {
			if ( $state == 'normal' )
				$state = '';
			$this->change_state( $post_id, $state );
			$response = $this->p2_action_links();
			clean_object_term_cache( $post->ID, $post->post_type );
		} else {
			$response['state'] = 'error';
			$response['action_links'] = $error;
		}

		if ( $this->is_ajax_request() ) {
			header( 'Content-Type: application/json' );
			die( json_encode( array( 'action_links' => $action_links, 'state' => $state ) ) );
		} else {
			wp_safe_redirect( get_permalink( $post->ID ) );
		}
		die;

	}

	/**
	 * Change the state of a given post
	 */
	function change_state( $post_id, $state, $inserting_post = false ) {
		if ( ! taxonomy_exists( self::taxonomy ) )
			$this->register_taxonomy();

		wp_set_object_terms( $post_id, (array)$state, self::taxonomy, false );
		$args = array(
				'new_state' => $state,
			);
		$args = $this->log_state_change( $post_id, $args );
		do_action( 'p2_resolved_posts_changed_state', $state, $post_id, $inserting_post );

		return $this->single_audit_log_output( $args );
		if ( ! $inserting_post ) {
			return $this->single_audit_log_output( $args );
		}
	}

	/**
	 * Log when a post has changed resolution state.
	 * Don't blow away existing logs though.
	 *
	 * @since 0.2
	 */
	function log_state_change( $post_id, $args = array() ) {

		$defaults = array(
				'user_login' => wp_get_current_user()->user_login,
				'new_state' => '',
				'timestamp' => time(),
			);
		$args = array_merge( $defaults, $args );
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
		$date_time = sprintf( __( '<span class="date-time">%1$s on %2$s</span>', 'p2-resolve' ), esc_html( $time ), esc_html( $date ) );

		$user = get_user_by( 'login', $args['user_login'] );
		// Accomodate for removed users
		if ( $user ) {
			$avatar = get_avatar( $user->ID, 16 );
			$display_name = $user->display_name;
		} else {
			$avatar = '';
			$display_name = __( 'Someone', 'p2-resolve' );
		}

		// If there's a 'resolved' or 'unresolved' state currently set
		if ( $args['new_state'] )
			$text = sprintf( __( '%1$s marked this %2$s<br />%3$s', 'p2-resolve' ), esc_html( $display_name ), esc_html( $args['new_state'] ), $date_time );
		else
			$text = sprintf( __( '%1$s removed resolution<br />%2$s', 'p2-resolve' ), esc_html( $display_name ), $date_time );

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
	function mark_new_as_unresolved( $post_id, $post ) {

		// Allow certain types of posts to not be marked as unresolved
		if ( !apply_filters( 'p2_resolved_posts_maybe_mark_new_as_unresolved', true, $post ) )
			return;

		wp_set_post_terms( $post_id, array( 'unresolved' ), self::taxonomy );
		$args = array(
					'new_state' => 'unresolved',
				);
		$this->log_state_change( $post_id, $args );
	}

	/**
	 * Automatically close comments on resolved posts
	 * To enable, include the following in your theme's functions.php:
	 * - add_filter( 'p2_resolved_posts_open_close_comments', '__return_true' );
	 *
	 * @since 0.3
	 */
	function open_close_comments( $state, $post_id, $inserting_post ) {

		if ( $inserting_post )
			return;

		$the_post = get_post( $post_id );
		if ( 'resolved' == $state )
			$the_post->comment_status = 'closed';
		else
			$the_post->comment_status = 'open';
		wp_update_post( $the_post );

	}

}

$p2_resolved_posts = new P2_Resolved_Posts();