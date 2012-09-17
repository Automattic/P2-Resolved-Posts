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

/**
 * @package P2_Resolved_Posts
 */
class P2_Resolved_Posts {

	static $instance;

	const taxonomy = 'p2_resolved';
	const audit_log_key = 'p2_resolved_log';

	var $states;

	/**
	 * Constructor. Saves instance and sets up initial hook.
	 */
	function __construct() {
		self::$instance = $this;

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

		load_plugin_textdomain( 'p2-resolve', false, dirname( plugin_basename( __FILE__ ) ) . '/languages/' );

		add_action( 'wp_head', array( $this, 'action_wp_head_css' ) );
		add_action( 'wp_head', array( $this, 'action_wp_head_ajax' ) );
		add_action( 'init', array( $this, 'action_init_handle_state_change' ) );
		add_action( 'p2_action_links', array( $this, 'p2_action_links' ), 100 );
		add_filter( 'post_class', array( $this, 'post_class' ), 10, 3 );
		add_action( 'widgets_init', array( $this, 'widgets_init' ) );
		add_filter( 'request', array( $this, 'request' ) );
		$this->register_taxonomy();

		$states = array(
				(object)array(
						'slug'          => 'normal',
						'name'          => __( 'Normal', 'p2-resolve' ),
						'link_text'     => __( 'Flag unresolved', 'p2-resolve' ),
						'next_action'   => __( 'Flag as unresolved', 'p2-resolve' ),
					),
				(object)array(
						'slug'          => 'unresolved',
						'name'          => __( 'Unresolved', 'p2-resolved' ),
						'link_text'     => __( 'Unresolved', 'p2-resolve' ),
						'next_action'   => __( 'Flag as Resolved', 'p2-resolve' ),
					),
				(object)array(
						'slug'          => 'resolved',
						'name'          => __( 'Resolved', 'p2-resolved' ),
						'link_text'     => __( 'Resolved', 'p2-resolve' ),
						'next_action'   => __( 'Remove resolved flag', 'p2-resolve' ),
					),
			);
		$this->states = apply_filters( 'p2_resolved_posts_states', $states );

		if ( ! term_exists( 'unresolved', self::taxonomy ) )
			wp_insert_term( 'unresolved', self::taxonomy );

		// Posts can be marked unresolved automatically by default
		// if the user wishes
		if ( apply_filters( 'p2_resolved_posts_mark_new_as_unresolved', false ) )
			add_action( 'publish_post', array( $this, 'mark_new_as_unresolved' ), 10, 2 );
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
	 * Get the slugs for all of the registered states
	 */
	function get_state_slugs() {
		return wp_list_pluck( $this->states, 'slug' );
	}

	/**
	 * Get the first state this post can be changed to
	 */
	function get_first_state() {
		return array_shift( array_values( $this->states ) );
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

	/**
	 * Sidebar widget for filtering posts
	 */
	function widgets_init() {
		register_widget( 'P2_Resolved_Posts_Widget' );
		register_widget( 'P2_Resolved_Posts_Show_Unresolved_Posts_Widget' );
	}

	/**
	 * Add our action links to the P2 post
	 */
	function p2_action_links() {

		$args = array(
			'action' => 'p2-resolve',
			'post-id' => get_the_ID(),
			'nonce' => wp_create_nonce( 'p2-resolve-' . get_the_id() ),
		);
		$link = add_query_arg( $args, get_site_url() );

		$css = array(
			'p2-resolve-link',
		);

		$existing_state = $this->get_current_state( get_the_ID() );
		if ( ! $existing_state )
			$existing_state = $this->get_first_state();
		$next_state = $this->get_next_state( $existing_state->slug );

		$css[] = 'state-' . $existing_state->slug;
		$link = add_query_arg( 'mark', $next_state->slug, $link );
		$next_action = $existing_state->next_action;
		$text = $existing_state->link_text;

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

		#main #postlist li.post {
			border-left: 8px solid #FFF;
			padding-left: 7px;
		}

		#main #postlist li.post.state-unresolved {
			border-left-color: #E6000A;
			-webkit-border-top-left-radius: 0;
			-moz-border-radius-topleft: 0;
			-o-border-radius-topleft: 0;
			-ms-border-radius-topleft: 0;
			border-top-left-radius: 0;
		}

		#main #postlist li.post.state-unresolved .actions a.p2-resolve-link {
			background-color: #E6000A;
		}

		#main #postlist li.post.state-resolved {
			border-left-color: #009632;
			-webkit-border-top-left-radius: 0;
			-moz-border-radius-topleft: 0;
			-o-border-radius-topleft: 0;
			-ms-border-radius-topleft: 0;
			border-top-left-radius: 0;
		}

		#main #postlist li.post.state-resolved .actions a.p2-resolve-link {
			background-color: #009632;
		}

		#main #postlist li.post.state-unresolved .actions a.p2-resolve-link,
		#main #postlist li.post.state-resolved .actions a.p2-resolve-link {
			color: #fff;
			padding: 1px 3px;
			-webkit-border-radius: 2px;
			-moz-border-radius: 2px;
			-o-border-radius: 2px;
			-ms-border-radius: 2px;
			border-radius: 2px;
		}

		#main #postlist li.post .actions a.p2-resolve-link:hover,
		#main #postlist li.post .actions a.p2-resolve-link.p2-resolve-ajax-action {
			background-color: #888;
		}

		#main #postlist li.post ul.p2-resolved-posts-audit-log,
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

		#main #postlist li.post ul.p2-resolved-posts-audit-log li {
			list-style-type: none;
			color: #777;
			opacity: 0.6;
			border-top: none;
			border-bottom: none;
			padding-top: 2px;
			padding-bottom: 2px;
		}

		#main #postlist li.post ul.p2-resolved-posts-audit-log li img {
			float: left;
			margin-right: 0;
			margin-top: 4px;
		}

		#main #postlist li.post ul.p2-resolved-posts-audit-log li span.audit-log-text {
			margin-left: 25px;
			display: block;
		}

		#main #postlist li.post ul.p2-resolved-posts-audit-log li span.date-time {
			font-size: 10px;
		}

		#main #postlist li.post ul.p2-resolved-posts-audit-log li:first-child {
			opacity: 1.0;
		}

		</style>
		<?
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
				// Mark the thread as unresolved
				jQuery(this).html('Saving...');
				jQuery(this).addClass('p2-resolve-ajax-action');
				jQuery.get( original_link.attr('href') + '&ajax', function(data){
					// The update was successful
					if ( data.indexOf('<') == 0 ) {
						// Depending on the action we took, update the DOM
						// Need to replace the text, href and the title attribute
						if ( original_link.attr('href').indexOf('mark=unresolved') != -1 ) {
							original_link.closest('.post').addClass('state-unresolved');
							original_link.addClass('state-unresolved');
							var new_url = original_link.attr('href').replace('mark=unresolved', 'mark=resolved');
							original_link.attr('href', new_url );
							original_link.html('<?php _e("Unresolved","p2-resolve"); ?>');
							original_link.attr('title','<?php _e("Flag as Resolved","p2-resolve"); ?>');
						} else if ( original_link.attr('href').indexOf('mark=resolved') != -1 ) {
							original_link.closest('.post').removeClass('state-unresolved').addClass('state-resolved');
							original_link.removeClass('state-unresolved').addClass('state-resolved');
							var new_url = original_link.attr('href').replace('mark=resolved', 'mark=normal');
							original_link.attr('href', new_url );
							original_link.html('<?php _e("Resolved","p2-resolve"); ?>');
							original_link.attr('title','<?php _e("Remove Resolved Flag","p2-resolve"); ?>');
						} else if ( original_link.attr('href').indexOf('mark=normal') != -1 ) {
							original_link.closest('.post').removeClass('state-resolved');
							original_link.removeClass('state-resolved');
							var new_url = original_link.attr('href').replace('mark=normal', 'mark=unresolved');
							original_link.attr('href', new_url );
							original_link.html('<?php _e("Flag Unresolved","p2-resolve"); ?>');
							original_link.attr('title','<?php _e("Flag as Unresolved","p2-resolve"); ?>');
						}
						// Update the audit log
						original_link.closest('.post').find('ul.p2-resolved-posts-audit-log').prepend( data );

					} else {
						// Display the error if it happened
						original_link.html(data);
						original_link.attr('style', 'color: #FF0000 !important;');
					}
					original_link.removeClass('p2-resolve-ajax-action');
					return false;
				});
				return false;
			});
		});
		</script>
		<?
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

		$error = false;
		$blog_id = get_current_blog_id();
		$blog_id = get_current_blog_id();
		$current_user = wp_get_current_user();

		$state = '';
		if ( in_array( $_GET['mark'], $this->get_state_slugs() ) )
			$state = sanitize_key( $_GET['mark'] );
		else
			$error = __( 'Bad state', 'p2-resolve' );

		$error = false;
		$post_id = intval( $_GET['post-id'] );

		$post = get_post( $post_id );
		if ( !$post )
			$error = __( 'Invalid post', 'p2-resolve' );

		if ( !wp_verify_nonce( $_GET['nonce'], 'p2-resolve-' . $post_id ) )
			$error = __( "Nonce error", 'p2-resolve' );

		// If there were no errors, set the post in that state
		if ( !$error ) {
			if ( $state == $this->get_first_state()->slug )
				$state = '';
			$message = $this->change_state( $post_id, $state );
		} else {
			$message = $error;
		}

		// Echo data if this was an AJAX request, otherwise go to the post
		if ( isset( $_GET['ajax'] ) ) {
			echo $message;
		} else {
			wp_safe_redirect( get_permalink( $post->ID ) );
		}
		die;

	}

	/**
	 * Change the state of a given post
	 */
	function change_state( $post_id, $state ) {
		if ( ! taxonomy_exists( self::taxonomy ) )
			$this->register_taxonomy();

		wp_set_object_terms( $post_id, (array)$state, self::taxonomy, false );
		$args = array(
				'new_state' => $state,
			);
		$args = $this->log_state_change( $post_id, $args );
		clean_object_term_cache( $post_id, get_post_type( $post_id ) );
		do_action( 'p2_resolved_posts_changed_state', $state, $post_id );

		return $this->single_audit_log_output( $args );
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

		// If there's a state currently set
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

		$new_state = $this->get_next_state( $this->get_first_state()->slug );
		$this->change_state( $post_id, $new_state->slug );
	}

}
$p2_resolved_posts = new P2_Resolved_Posts();


/**
 * class P2_Resolved_Posts_Widget
 * Add a widget to the sidebar for filtering posts by resolution
 */
class P2_Resolved_Posts_Widget extends WP_Widget {

	function __construct() {
		$widget_ops = array(
			'classname' => 'p2-resolved-posts',
			'description' => __( 'Allows querying of resolved and unresolved posts', 'p2-resolved-posts' )
		);
		parent::__construct( 'p2_resolved_posts', __( 'P2 Filter Posts' ), $widget_ops);
	}

	function widget( $args, $instance ) {
		extract( $args );
		$title = apply_filters( 'widget_title', $instance['title'], $instance, $this->id_base );

		echo $before_widget;
		if ( $title )
			echo $before_title . $title . $after_title;

?>
<ul>
<?php if ( is_home() && isset( $_GET['resolved'] ) && $_GET['resolved'] == 'unresolved' ) : ?>
	<li><strong>&bull; <?php _e( 'Show <strong>unresolved</strong> threads', 'p2-resolved-posts' ); ?></strong></li>
<?php else : ?>
	<li><a href="<?php echo esc_url( add_query_arg( 'resolved', 'unresolved', home_url() ) ); ?>"><?php _e( 'Show <strong>unresolved</strong> threads', 'p2-resolved-posts' ); ?></a></li>
<?php endif; ?>

<?php if ( is_tax( P2_Resolved_Posts::taxonomy, 'resolved' ) ) : ?>
	<li><strong>&bull; <?php _e( 'Show resolved threads', 'p2-resolved-posts' ); ?></strong></li>
<?php else : ?>
	<li><a href="<?php echo esc_url( add_query_arg( 'resolved', 'resolved', home_url() ) ); ?>"><?php _e( 'Show resolved threads', 'p2-resolved-posts' ); ?></a></li>
<?php endif; ?>

<?php if ( is_home() && ! isset( $_GET['resolved'] ) ) : ?>
	<li><strong>&bull; <?php _e( 'Show all threads', 'p2-resolved-posts' ); ?></strong></li>
<?php else : ?>
	<li><a href="<?php echo esc_url( home_url() ); ?>"><?php _e( 'Show all threads', 'p2-resolved-posts' ); ?></a></li>
<?php endif; ?>
</ul>

<?php

		echo $after_widget;
	}

	function form( $instance ) {
		$instance = wp_parse_args( (array) $instance, array( 'title' => '') );
		$title = $instance['title'];
?>
		<p><label for="<?php echo $this->get_field_id('title'); ?>"><?php _e('Title:'); ?> <input class="widefat" id="<?php echo $this->get_field_id('title'); ?>" name="<?php echo $this->get_field_name('title'); ?>" type="text" value="<?php echo esc_attr($title); ?>" /></label></p>
<?php
	}

	function update( $new_instance, $old_instance ) {
		$instance = $old_instance;
		$new_instance = wp_parse_args((array) $new_instance, array( 'title' => ''));
		$instance['title'] = sanitize_text_field( $new_instance['title'] );
		return $instance;
	}

}

/**
 * class P2_Resolved_Posts_Show_Unresolved_Posts_Widget
 * Show a listing of resolved or unresolved posts with optional filters
 *
 * @author danielbachhuber
 */
 class P2_Resolved_Posts_Show_Unresolved_Posts_Widget extends WP_Widget {

 	private $widget_args = array(
		'title' => '',
		'posts_per_page' => 5,
		'order' => 'DESC',
	 	);

 	function __construct() {

 		$widget_ops = array(
			'classname' => 'p2-resolved-posts-show-unresolved-posts',
			'description' => __( 'Display an (optionally filtered) list of unresolved posts', 'p2-resolved-posts' )
		);
		parent::__construct( 'p2_resolved_posts_show_unresolved_posts', __( 'P2 Unresolved Posts', 'p2-resolved-posts' ), $widget_ops );

		add_action( 'wp_head', array( $this, 'action_wp_head' ) );

 	}

 	/**
 	 * Styles and JS for the head
 	 */
 	function action_wp_head() {

 		?>
 		<style type="text/css">
 		#sidebar .p2-resolved-posts-show-unresolved-posts ul li img.avatar {
			float: left;
			padding-right: 8px;
			padding-top: 3px;
		}

		#sidebar .p2-resolved-posts-show-unresolved-posts ul li .inner {
			margin-left: 40px;
			font-size: 11px;
		}

		#sidebar .p2-resolved-posts-show-unresolved-posts .hidden {
			display: none;
		}

		#sidebar .p2-resolved-posts-show-unresolved-posts .p2-resolved-posts-show-unresolved-posts-pagination {
			padding-top: 0;
			margin-top: -3px;
			padding-bottom: 2px;
			margin-bottom: 0;
			font-size: 10px;
		}
		</style>
		<script type="text/javascript">
			jQuery(document).ready(function(){

				jQuery('.p2-resolved-posts-pagination-link').click(function(){
					var parent = jQuery(this).closest('.p2-resolved-posts-show-unresolved-posts');
					var first_post = parseInt( parent.find('.p2-resolved-posts-first-post').html() );
					var last_post = parseInt( parent.find('.p2-resolved-posts-last-post').html() );
					var total_posts = parseInt( parent.find('.p2-resolved-posts-total-posts').html() )
					var posts_per_page = parseInt( parent.find('.p2-resolved-posts-ppp').html() );
					if ( jQuery(this).hasClass('p2-resolved-posts-previous-posts') ) {
						// Don't paginate if we're at the first item
						if ( first_post <= 1 )
							return false;
						// Calculate the first and last post to display
						first_post = first_post - posts_per_page;
						last_post = first_post + ( posts_per_page - 1);

					} else if ( jQuery(this).hasClass('p2-resolved-posts-next-posts') ) {
						// Don't paginate if we're at the last item
						if ( last_post >= total_posts )
							return false;
						// Calculate the first and last post to display
						first_post = first_post + posts_per_page;
						last_post = last_post + posts_per_page;
						if ( last_post > total_posts )
							last_post = total_posts;

					}
					// Show posts based on our pagination counter
					parent.find( 'ul li' ).removeClass('active').addClass('hidden');
					parent.find( 'ul li' ).each(function( index, value ) {
						if ( ( index + 1 ) >= first_post && ( index + 1 ) <= last_post )
							jQuery(this).addClass('active').removeClass('hidden');

					});
					// Reset the pagination
					parent.find('.p2-resolved-posts-first-post').html( first_post );
					parent.find('.p2-resolved-posts-last-post').html( last_post );
					return false;
				});

			});
		</script>
 		<?php

 	}

 	/**
 	 * Form for the widget settings
 	 */
 	function form( $instance ) {
 		$instance = wp_parse_args( (array)$instance, $this->widget_args );
 		extract( $instance );

 		?>
		<p><label for="<?php echo $this->get_field_id('title'); ?>"><?php _e( 'Title', 'p2-resolved-posts' ); ?>: <input class="widefat" id="<?php echo $this->get_field_id('title'); ?>" name="<?php echo $this->get_field_name('title'); ?>" type="text" value="<?php echo esc_attr( $title ); ?>" /></label></p>
		<p><label for="<?php echo $this->get_field_id('posts_per_page'); ?>"><?php _e( 'Posts Per Page', 'p2-resolved-posts' ); ?>: <input class="widefat" id="<?php echo $this->get_field_id('posts_per_page'); ?>" name="<?php echo $this->get_field_name('posts_per_page'); ?>" type="text" value="<?php echo esc_attr( $posts_per_page ); ?>" maxlength="2" /></label></p>
		<p><label for="<?php echo $this->get_field_id('filter_tags'); ?>"><?php _e( 'Filter to these tags', 'p2-resolved-posts' ); ?>: <input class="widefat" id="<?php echo $this->get_field_id('filter_tags'); ?>" name="<?php echo $this->get_field_name('filter_tags'); ?>" type="text" value="<?php echo esc_attr( $filter_tags ); ?>" /></label><br />
			<span class="description"><?php _e( "Separate multiple tags with commas, and prefix with '-' to exclude.", 'p2-resolved-posts' ); ?></span>
		</p>
		<p><label for="<?php echo $this->get_field_id('order'); ?>"><?php _e( 'Show', 'p2-resolved-posts' ); ?>: <select id="<?php echo $this->get_field_id('order'); ?>" name="<?php echo $this->get_field_name('order'); ?>">
			<?php $order_options = array(
					'DESC' => __( 'Newest posts first', 'p2-resolved-posts' ),
					'ASC' => __( 'Oldest posts first', 'p2-resolved-posts' ),
				);
				foreach( $order_options as $key => $order_option ) {
					echo '<option value="' . esc_attr( $key ) . '"' . selected( $order, $key, false ) . '>' . esc_html( $order_option ) . '</option>';
				} ?>
		</select>
		</p>
<?php
 	}

 	/**
 	 * Validate any new widget form data
 	 */
 	function update( $new_instance, $old_instance ) {
 		$instance = $old_instance;
		$new_instance = wp_parse_args( (array)$new_instance, $this->widget_args );
		// Sanitize the values
		$instance['title'] = sanitize_text_field( $new_instance['title'] );
		$instance['posts_per_page'] = (int)$new_instance['posts_per_page'];
		if ( $instance['posts_per_page'] < 1 || $instance['posts_per_page'] > 99 )
			$instance['posts_per_page'] = 1;
		$multi_tags = (array)explode( ',', $new_instance['filter_tags'] );
		$multi_tags = array_map( 'sanitize_key', $multi_tags );
		// We only want to save tags that actually exist
		foreach( $multi_tags as $key => $multi_tag ) {
			if ( 0 === strpos( $multi_tag, '-') )
				$invert = '-';
			else
				$invert = '';
			if ( is_numeric( $multi_tag ) ) {
				if ( false === ( $tag = get_term_by( 'id', $multi_tag, 'post_tag' ) ) )
					unset( $multi_tags[$key] );
				if ( is_object( $tag ) )
					$multi_tags[$key] = $invert . $tag->term_id;
			} else {
				if ( false === ( $tag = get_term_by( 'slug', $multi_tag, 'post_tag' ) ) )
					unset( $multi_tags[$key] );
				if ( is_object( $tag ) )
					$multi_tags[$key] = $invert . $tag->slug;
			}
		}
		$instance['filter_tags'] = implode( ',', $multi_tags );
		if ( $new_instance['order'] != 'ASC' )
			$new_instance['order'] = 'DESC';
		$instance['order'] = $new_instance['order'];
		return $instance;
 	}

 	/**
 	 * Display the widget
 	 *
 	 * @todo The output should be cached and regenerated when a post is marked as resolved, etc.
 	 */
 	function widget( $args, $instance ) {
 		extract( $args );
 		extract( $instance );

 		echo $before_widget;
 		$link_args = array(
 				'resolved' => 'unresolved',
 				'tags' => $filter_tags,
 				'order' => $order,
	 		);
 		$more_link = add_query_arg( $link_args, get_site_url() );
 		if ( $title )
 			echo $before_title . $title . '&nbsp;<a title="' . esc_attr( __( 'See all matching unresolved posts', 'p2-resolved-posts' ) ) . '" href="' . esc_url( $more_link ) . '">&raquo;</a>' . $after_title;

 		if ( $posts_per_page > 0 ) {
 			$query_args = array(
 					'posts_per_page' =>  50, // Load a lot of posts so we can AJAX paginate through them
 					'tax_query' => array(
 							array(
 									'taxonomy' => P2_Resolved_Posts::taxonomy,
 									'field' => 'slug',
 									'terms' => 'unresolved',
	 							),
	 					),
	 				'order' => sanitize_key( $order ),
	 			);
	 		$filter_tags = (array)explode( ',', $filter_tags );
	 		foreach( (array)$filter_tags as $filter_tag ) {
	 			if ( !$filter_tag )
	 				continue;
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
	 			$query_args['tax_query'][] = $new_tax_query;
	 		}
 			$unresolved_posts = new WP_Query( $query_args );
 			if ( $unresolved_posts->have_posts() ) {
 				$current_post = 1;
 				if ( $unresolved_posts->found_posts < $posts_per_page )
 					$posts_per_page = $unresolved_posts->found_posts;
 				echo '<p class="p2-resolved-posts-show-unresolved-posts-pagination">';
 				if ( $unresolved_posts->found_posts > $posts_per_page )
		 			echo '<a href="#" class="p2-resolved-posts-previous-posts p2-resolved-posts-pagination-link" class="inactive">' . __( '&larr;', 'p2-resolved-posts' ) . '</a>&nbsp;&nbsp;';
		 		echo sprintf( __( 'Showing <span class="p2-resolved-posts-first-post">1</span>-<span class="p2-resolved-posts-last-post">%1$d</span> of <span class="p2-resolved-posts-total-posts">%2$d</span> unresolved posts'), esc_html( $posts_per_page ), esc_html( $unresolved_posts->found_posts ) );
		 		if ( $unresolved_posts->found_posts > $posts_per_page )
		 			echo '&nbsp;&nbsp;<a href="#" class="p2-resolved-posts-next-posts p2-resolved-posts-pagination-link">' . __( '&rarr;', 'p2-resolved-posts' ) . '</a>';
		 		echo '</p>';
		 		echo '<span class="hidden p2-resolved-posts-ppp">' . esc_html( $posts_per_page ) . '</span>';
 				echo '<ul>';
 				while( $unresolved_posts->have_posts() ) {
 					$unresolved_posts->the_post();
 					global $post;
					echo '<li';
					if ( $current_post > $posts_per_page )
						echo ' class="hidden"';
					else
						echo ' class="active"';
					echo '>';
					echo get_avatar( $post->post_author, 32 );
					echo '<div class="inner"><a href="' . get_permalink() . '" title="' . esc_attr( get_the_excerpt() ) . '">' . get_the_title() . '</a><br />';
					$post_timestamp = strtotime( $post->post_date );
					echo '<span>' . sprintf( __( '%s old', 'p2-resolved-posts' ), esc_html( human_time_diff( $post_timestamp ) ) ) . ', ';
					comments_number( __( 'no comments', 'p2-resolved-posts' ), __( 'one comment', 'p2-resolved-posts' ), __( '% comments', 'p2-resolved-posts' ) );
					echo '</span>';
					echo '</div></li>';
					$current_post++;
 				}
				wp_reset_postdata();
 				echo '</ul>';
 			} else {
 				echo '<p>' . __( 'Nice work! Everything in this list has been resolved.', 'p2-resolved-posts' ) . '</p>';
 			}
 		}

 		echo $after_widget;
 	}

 }